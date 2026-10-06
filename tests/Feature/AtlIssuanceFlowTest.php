<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Order;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderDelivery;
use Tests\TestCase;

class AtlIssuanceFlowTest extends TestCase
{
    protected Admin $purchasing;

    protected function setUp(): void
    {
        parent::setUp();

        $this->purchasing = Admin::create([
            'username' => 'purchasing_' . uniqid(),
            'password' => bcrypt('secret'),
            'name' => 'Rica Esaga',
            'role' => 'purchasing',
        ]);
    }

    public function test_draft_can_be_saved_before_accounting_clears_the_order(): void
    {
        $order = $this->fuelTradeOrder('SO-DRAFT', 'Pending');
        $po = $this->supplierPo('PO-DRAFT', 50000);

        $response = $this->actingAs($this->purchasing)->post(
            route('stock-orders.store-fuel-trade-po', $order->id),
            $this->payload($po, ['submit_for_approval' => 0])
        );

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $atl = PurchaseOrderDelivery::latest('id')->firstOrFail();
        $this->assertEquals('DRAFT', $atl->approval_status);
        $this->assertEquals('UNLIFTED', $atl->lift_status);
        $this->assertEquals('DOYEN_ISSUED', $atl->atl_source);
        // A draft must not sit in the VP approval queue.
        $this->assertEquals(0, PurchaseOrderDelivery::where('approval_status', 'FOR_APPROVAL')->count());
    }

    public function test_submit_for_approval_is_blocked_until_clearance_is_approved(): void
    {
        $order = $this->fuelTradeOrder('SO-GATE', 'Pending');
        $po = $this->supplierPo('PO-GATE', 50000);

        $response = $this->actingAs($this->purchasing)->post(
            route('stock-orders.store-fuel-trade-po', $order->id),
            $this->payload($po, ['submit_for_approval' => 1])
        );

        $response->assertSessionHasErrors();
        $this->assertNull(PurchaseOrderDelivery::latest('id')->first());
    }

    public function test_cleared_order_can_be_submitted_for_approval(): void
    {
        $order = $this->fuelTradeOrder('GO', 'Approved');
        $po = $this->supplierPo('PO-SUBMIT', 50000);

        $response = $this->actingAs($this->purchasing)->post(
            route('stock-orders.store-fuel-trade-po', $order->id),
            $this->payload($po, ['submit_for_approval' => 1])
        );

        $response->assertSessionHas('success');

        $atl = PurchaseOrderDelivery::latest('id')->firstOrFail();
        $this->assertEquals('FOR_APPROVAL', $atl->approval_status);
        $this->assertNotNull($atl->issued_at);
        $this->assertEquals($order->id, $atl->order_id);
    }

    public function test_client_provided_atl_is_recorded_without_approval(): void
    {
        $order = $this->fuelTradeOrder('SO-CLIENT', 'Approved');
        $po = $this->supplierPo('PO-CLIENT', 50000);

        $response = $this->actingAs($this->purchasing)->post(
            route('stock-orders.store-fuel-trade-po', $order->id),
            $this->payload($po, [
                'atl_source' => 'CLIENT_PROVIDED',
                'client_atl_number' => 'ATL-CLI-9',
                'submit_for_approval' => 0,
            ])
        );

        $response->assertSessionHas('success');

        $atl = PurchaseOrderDelivery::latest('id')->firstOrFail();
        $this->assertEquals('CLIENT_PROVIDED', $atl->atl_source);
        $this->assertEquals('ATL-CLI-9', $atl->client_atl_number);
        // Client ATLs skip VP approval entirely, even if submit was pressed.
        $this->assertNotEquals('FOR_APPROVAL', $atl->approval_status);
        $this->assertTrue($atl->isClientProvided());
    }

    public function test_client_provided_atl_requires_its_atl_number(): void
    {
        $order = $this->fuelTradeOrder('SO-CLIENT2', 'Approved');
        $po = $this->supplierPo('PO-CLIENT2', 50000);

        $this->actingAs($this->purchasing)->post(
            route('stock-orders.store-fuel-trade-po', $order->id),
            $this->payload($po, [
                'atl_source' => 'CLIENT_PROVIDED',
                'client_atl_number' => '',
                'submit_for_approval' => 0,
            ])
        )->assertSessionHasErrors(['client_atl_number']);
    }

    public function test_atl_records_the_sales_order_it_serves(): void
    {
        $order = $this->fuelTradeOrder('SO-LINK', 'Approved');
        $po = $this->supplierPo('PO-LINK', 50000);

        $this->actingAs($this->purchasing)->post(
            route('stock-orders.store-fuel-trade-po', $order->id),
            $this->payload($po, ['submit_for_approval' => 0])
        )->assertSessionHas('success');

        $atl = PurchaseOrderDelivery::latest('id')->firstOrFail();
        $this->assertEquals($order->id, $atl->order_id);
        $this->assertEquals($order->so_number, $atl->so_number);
        $this->assertEquals('FUEL_TRADE', $atl->atl_category);
    }

    private function payload(PurchaseOrder $po, array $overrides = []): array
    {
        return array_merge([
            'receiving_date' => now()->addDay()->format('Y-m-d'),
            'driver_name' => 'Juan Dela Cruz',
            'plate_number' => 'NBD-1234',
            'location' => 'Limay Terminal',
            'atl_type' => 'DITC_ATL',
            'atl_number' => 'ATL-NEW-1',
            'drawdowns' => [
                ['purchase_order_id' => $po->id, 'product' => 'Diesel', 'quantity' => 5000],
            ],
        ], $overrides);
    }

    private function fuelTradeOrder(string $soNumber, string $clearance): Order
    {
        return Order::create([
            'account' => 'Apex Logistics',
            'location' => 'Valenzuela',
            'so_number' => $soNumber . '-' . uniqid(),
            'date' => now(),
            'qty_ordered' => 5000,
            'price' => 50.00,
            'status' => 'Active',
            'clearing_status' => $clearance,
            'fulfillment_type' => 'FUEL_TRADE',
            'order_category' => 'CLIENT_ORDER',
        ]);
    }

    private function supplierPo(string $poNumber, int $qty): PurchaseOrder
    {
        $po = PurchaseOrder::create([
            'po_number' => $poNumber,
            'po_type' => 'STANDARD_REPLENISHMENT',
            'supplier_name' => 'Petron Bataan Refinery',
            'qty_ordered' => $qty,
            'request_status' => 'CONFIRMED',
            'status' => 'Pending',
        ]);

        \App\Models\PurchaseOrderItem::create([
            'purchase_order_id' => $po->id,
            'product' => 'Diesel',
            'quantity_ordered' => $qty,
        ]);

        return $po;
    }
}
