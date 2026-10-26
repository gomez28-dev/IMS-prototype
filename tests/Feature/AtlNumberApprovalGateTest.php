<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Order;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderDelivery;
use App\Models\PurchaseOrderItem;
use Tests\TestCase;

/**
 * The ATL number gate.
 *
 * Rica can issue an ATL before DITC numbers it - the number legitimately
 * arrives later - so creation stays optional on it. But approval is the
 * moment the record becomes official: a DITC-issued ATL may not be approved
 * while its number is still blank, or the official PDF would print nothing
 * where the ATL number belongs. The number is recorded on the ATL as soon as
 * DITC issues it, and approval lifts the moment it is there.
 *
 * NONE-type ATLs never carry a number, and client-provided ones already
 * demand their number at creation, so the gate is specific to DITC_ATL.
 */
class AtlNumberApprovalGateTest extends TestCase
{
    protected Admin $purchasing;

    protected Admin $vp;

    protected function setUp(): void
    {
        parent::setUp();

        $this->purchasing = Admin::create([
            'username' => 'purchasing_'.uniqid(),
            'password' => bcrypt('secret'),
            'name' => 'Rica Esaga',
            'role' => 'purchasing',
        ]);

        $this->vp = Admin::create([
            'username' => 'vp_'.uniqid(),
            'password' => bcrypt('secret'),
            'name' => 'VP Operations',
            'role' => 'vp',
        ]);
    }

    protected function poolPo(): PurchaseOrder
    {
        $po = PurchaseOrder::create([
            'po_number' => 'PO-GATE-'.rand(1000, 9999),
            'po_type' => PurchaseOrder::TYPE_STANDARD_REPLENISHMENT,
            'supplier_name' => 'Petron Bataan',
            'qty_ordered' => 50000,
            'request_status' => 'CONFIRMED',
            'status' => 'Pending',
        ]);
        PurchaseOrderItem::create([
            'purchase_order_id' => $po->id,
            'product' => 'Diesel',
            'quantity_ordered' => 50000,
        ]);

        return $po;
    }

    protected function salesOrder(): Order
    {
        return Order::create([
            'account' => 'Apex Logistics Corp',
            'location' => 'Valenzuela',
            'so_number' => 'SO-GATE-'.rand(100000, 999999),
            'date' => now(),
            'qty_ordered' => 10000,
            'price' => 52.00,
            'status' => 'Active',
            'clearing_status' => 'Approved',
            'fulfillment_type' => Order::FULFILLMENT_FUEL_TRADE,
            'order_category' => Order::CATEGORY_CLIENT_ORDER,
        ]);
    }

    protected function issue(Order $order, array $overrides = [])
    {
        return $this->actingAs($this->purchasing)
            ->post(route('stock-orders.store-fuel-trade-po', $order->id), array_merge([
                'receiving_date' => now()->addDays(2)->format('Y-m-d'),
                'atl_type' => 'DITC_ATL',
                'drawdowns' => [
                    ['purchase_order_id' => $this->poolPo()->id, 'product' => 'Diesel', 'quantity' => 10000],
                ],
            ], $overrides));
    }

    // ---- The gate ---------------------------------------------------------

    public function test_approval_refuses_a_ditc_atl_that_has_no_number_yet(): void
    {
        $order = $this->salesOrder();
        // Issued without a number: legal at creation, the number may arrive later.
        $this->issue($order)->assertRedirect();

        $atl = PurchaseOrderDelivery::where('order_id', $order->id)->firstOrFail();
        $this->assertSame(PurchaseOrderDelivery::APPROVAL_FOR_APPROVAL, $atl->approval_status);

        $this->actingAs($this->vp)
            ->post(route('stock-orders.atl-approve', $atl->id))
            ->assertSessionHas('error');

        $this->assertNotSame(
            PurchaseOrderDelivery::APPROVAL_APPROVED,
            $atl->fresh()->approval_status,
            'A blank-number DITC ATL must stay unapproved.'
        );
    }

    public function test_an_atl_whose_number_was_recorded_approves(): void
    {
        $order = $this->salesOrder();
        // The real issuance: purchasing records the number DITC gave.
        $this->issue($order, ['atl_number' => 'ATL-GATE-'.rand(100000, 999999)])->assertRedirect();

        $atl = PurchaseOrderDelivery::where('order_id', $order->id)->firstOrFail();

        $this->actingAs($this->vp)
            ->post(route('stock-orders.atl-approve', $atl->id))
            ->assertSessionHasNoErrors();

        $this->assertSame(
            PurchaseOrderDelivery::APPROVAL_APPROVED,
            $atl->fresh()->approval_status
        );
    }

    public function test_a_none_atl_approves_without_a_number(): void
    {
        $order = $this->salesOrder();
        $this->issue($order, ['atl_type' => 'NONE'])->assertRedirect();

        $atl = PurchaseOrderDelivery::latest('id')->firstOrFail();
        $this->assertSame('NONE', $atl->atl_type);

        $this->actingAs($this->vp)
            ->post(route('stock-orders.atl-approve', $atl->id))
            ->assertSessionHasNoErrors();

        $this->assertSame(
            PurchaseOrderDelivery::APPROVAL_APPROVED,
            $atl->fresh()->approval_status
        );
    }
}
