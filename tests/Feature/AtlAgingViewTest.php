<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderDelivery;
use App\Models\PurchaseOrderItem;
use App\Models\Supplier;
use Tests\TestCase;

/**
 * Approved ATLs past their pick-up date that were never lifted hold their
 * volume against the order. The aging view is where that stuck volume is read,
 * so it must list exactly those records and narrow by client, PO and date.
 */
class AtlAgingViewTest extends TestCase
{
    protected Admin $purchasing;

    protected function setUp(): void
    {
        parent::setUp();

        $this->purchasing = Admin::create([
            'username' => 'purchasing_'.uniqid(),
            'password' => bcrypt('secret'),
            'name' => 'Rica Esaga',
            'role' => 'purchasing',
        ]);
    }

    protected function order(string $account): Order
    {
        $order = Order::create([
            'so_number' => 'SO-AGE-'.rand(1000, 9999),
            'account' => $account,
            'date' => now()->toDateString(),
            'location' => 'Valenzuela',
            'status' => 'Active',
            'terms' => '30 Days',
            'qty_ordered' => 50000,
            'clearing_status' => 'Approved',
            'fulfillment_type' => Order::FULFILLMENT_FUEL_TRADE,
            'order_category' => Order::CATEGORY_CLIENT_ORDER,
        ]);

        OrderItem::create([
            'order_id' => $order->id,
            'product_type' => 'D',
            'qty' => 50000,
        ]);

        return $order;
    }

    protected function po(): PurchaseOrder
    {
        $supplier = Supplier::create([
            'company_name' => 'Aging Refinery '.rand(1000, 9999),
            'location' => 'Limay Terminal',
        ]);

        $po = PurchaseOrder::create([
            'po_number' => 'PO-AGE-'.rand(1000, 9999),
            'po_type' => PurchaseOrder::TYPE_STANDARD_REPLENISHMENT,
            'supplier_id' => $supplier->id,
            'supplier_name' => $supplier->company_name,
            'po_date' => now()->toDateString(),
            'qty_ordered' => 100000,
            'request_status' => 'CONFIRMED',
        ]);

        PurchaseOrderItem::create([
            'purchase_order_id' => $po->id,
            'product' => 'Diesel',
            'quantity_ordered' => 100000,
        ]);

        return $po;
    }

    protected function atl(PurchaseOrder $po, array $overrides = []): PurchaseOrderDelivery
    {
        return PurchaseOrderDelivery::create(array_merge([
            'purchase_order_id' => $po->id,
            'delivery_channel' => 'SUPPLIER_DOYEN_PICKUP',
            'order_type' => 'PICK_UP',
            'atl_type' => 'DITC_ATL',
            'atl_number' => 'ATL-AGE-'.rand(100000, 999999),
            'product' => 'Diesel',
            'qty_to_receive' => 20000,
            'receiving_date' => now()->subDays(10),
            'driver_name' => 'Juan Dela Cruz',
            'status' => 'Pending',
            'approval_status' => PurchaseOrderDelivery::APPROVAL_APPROVED,
            'atl_source' => PurchaseOrderDelivery::SOURCE_DOYEN_ISSUED,
            'lift_status' => PurchaseOrderDelivery::LIFT_UNLIFTED,
        ], $overrides));
    }

    protected function agingHtml(array $query = []): string
    {
        return $this->actingAs($this->purchasing)
            ->get(route('stock-orders.atl-aging', $query))
            ->assertOk()
            ->getContent();
    }

    public function test_a_past_due_approved_unlifted_atl_is_listed_with_its_volume(): void
    {
        $atl = $this->atl($this->po());

        $html = $this->agingHtml();

        $this->assertStringContainsString($atl->atl_number, $html);
        $this->assertStringContainsString('20,000 L', $html, 'The stuck volume is summed on the page.');
        $this->assertStringContainsString('10 days', $html, 'How far past its pick-up date the ATL is.');
        $this->assertStringContainsString('Juan Dela Cruz', $html);
    }

    public function test_lifted_received_draft_future_and_cancelled_atls_are_not_stuck(): void
    {
        $po = $this->po();

        $stuck = $this->atl($po);
        $lifted = $this->atl($po, [
            'lift_status' => PurchaseOrderDelivery::LIFT_LIFTED,
            'lifted_at' => now()->subDays(2),
        ]);
        $received = $this->atl($po, ['received_at' => now()->subDay()]);
        $draft = $this->atl($po, ['approval_status' => PurchaseOrderDelivery::APPROVAL_FOR_APPROVAL]);
        $future = $this->atl($po, ['receiving_date' => now()->addDays(3)]);
        $cancelledLift = $this->atl($po, ['lift_status' => PurchaseOrderDelivery::LIFT_CANCELLED]);

        $html = $this->agingHtml();

        $this->assertStringContainsString($stuck->atl_number, $html);

        foreach ([$lifted, $received, $draft, $future, $cancelledLift] as $atl) {
            $this->assertStringNotContainsString(
                $atl->atl_number,
                $html,
                'Only approved, past-due, still-unlifted ATLs are stuck volume.'
            );
        }
    }

    public function test_the_list_narrows_by_client_po_and_date_range(): void
    {
        $alphaOrder = $this->order('Alpha Petroleum');
        $betaOrder = $this->order('Beta Fuels');

        $firstPo = $this->po();
        $secondPo = $this->po();

        $alpha = $this->atl($firstPo, [
            'order_id' => $alphaOrder->id,
            'so_number' => $alphaOrder->so_number,
            'receiving_date' => now()->subDays(20),
        ]);
        $beta = $this->atl($secondPo, [
            'order_id' => $betaOrder->id,
            'so_number' => $betaOrder->so_number,
            'receiving_date' => now()->subDays(5),
        ]);

        $byClient = $this->agingHtml(['client' => 'Alpha Petroleum']);
        $this->assertStringContainsString($alpha->atl_number, $byClient);
        $this->assertStringNotContainsString($beta->atl_number, $byClient);

        $byPo = $this->agingHtml(['po' => $secondPo->id]);
        $this->assertStringContainsString($beta->atl_number, $byPo);
        $this->assertStringNotContainsString($alpha->atl_number, $byPo);

        // A window inside the last week: the 20-day-old ATL falls outside it.
        $byRange = $this->agingHtml([
            'from' => now()->subDays(7)->format('Y-m-d'),
            'to' => now()->subDay()->format('Y-m-d'),
        ]);
        $this->assertStringContainsString($beta->atl_number, $byRange);
        $this->assertStringNotContainsString($alpha->atl_number, $byRange);
    }

    public function test_the_client_picker_offers_only_clients_with_stuck_atls(): void
    {
        $stuckOrder = $this->order('Alpha Petroleum');
        $this->atl($this->po(), [
            'order_id' => $stuckOrder->id,
            'so_number' => $stuckOrder->so_number,
        ]);

        // Has an order but nothing stuck: no reason to appear in the picker.
        $this->order('Gamma Oils');

        $html = $this->agingHtml();

        $this->assertStringContainsString('Alpha Petroleum', $html);
        $this->assertStringNotContainsString('Gamma Oils', $html);
    }
}
