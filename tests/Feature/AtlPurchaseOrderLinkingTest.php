<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\AtlAllocation;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderDelivery;
use App\Models\PurchaseOrderItem;
use App\Models\Supplier;
use Tests\TestCase;

/**
 * Two defects on the ATL side, both from the same assumption: that an ATL
 * belongs to one Sales Order and one Purchase Order.
 *
 * The Pen action passed an Order id where the route wants a PurchaseOrder id, so
 * it opened an unrelated PO's form. And createAtl never passed the pool of
 * allocatable POs, so the Allocate table was hidden behind an empty check and a
 * user arriving from a PO could not see any balance to draw from.
 */
class AtlPurchaseOrderLinkingTest extends TestCase
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

    protected function order(): Order
    {
        static $counter = 0;

        $order = Order::create([
            'so_number' => 'SO-LINK-'.(++$counter).'-'.rand(100, 999),
            'account' => 'Venture Commercial & Industrial Corp.',
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

    protected function po(?Order $order = null, int $qty = 100000, string $number = null): PurchaseOrder
    {
        $supplier = Supplier::create([
            'company_name' => 'Linking Refinery '.rand(1000, 9999),
            'location' => 'Limay Terminal',
        ]);

        $po = PurchaseOrder::create([
            'po_number' => $number ?: 'PO-LINK-'.rand(1000, 9999),
            'po_type' => PurchaseOrder::TYPE_FUEL_TRADE,
            'linked_order_id' => $order?->id,
            'supplier_id' => $supplier->id,
            'supplier_name' => $supplier->company_name,
            'po_date' => now()->toDateString(),
            'qty_ordered' => $qty,
            'request_status' => 'CONFIRMED',
        ]);

        PurchaseOrderItem::create([
            'purchase_order_id' => $po->id,
            'product' => 'Diesel',
            'quantity_ordered' => $qty,
        ]);

        return $po;
    }

    protected function atl(Order $order, PurchaseOrder $po, int $qty = 20000): PurchaseOrderDelivery
    {
        $atl = PurchaseOrderDelivery::create([
            'purchase_order_id' => $po->id,
            'order_id' => $order->id,
            'delivery_channel' => 'SUPPLIER_CLIENT_PICKUP',
            'order_type' => 'PICK_UP',
            'atl_type' => 'DITC_ATL',
            'atl_number' => 'ATL-LINK-'.rand(1000, 9999),
            'product' => 'Diesel',
            'qty_to_receive' => $qty,
            'receiving_date' => now()->addDay(),
            'status' => 'Active',
            'lift_status' => PurchaseOrderDelivery::LIFT_UNLIFTED,
        ]);

        AtlAllocation::create([
            'purchase_order_delivery_id' => $atl->id,
            'purchase_order_id' => $po->id,
            'product' => 'Diesel',
            'quantity' => $qty,
        ]);

        return $atl;
    }

    // ---- The Pen action ----------------------------------------------------

    public function test_the_pen_action_opens_the_atls_own_purchase_order(): void
    {
        $order = $this->order();
        $po = $this->po($order);
        $this->atl($order, $po);

        $html = $this->actingAs($this->purchasing)
            ->get(route('stock-orders.sales-orders.show', $order->id))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString(
            route('stock-orders.add-atl', $po->id),
            $html,
            'Pen must target the ATL\'s Purchase Order.'
        );
    }

    public function test_the_pen_action_never_passes_an_order_id(): void
    {
        // Give the order and its PO deliberately different ids so a swapped id
        // cannot pass by coincidence.
        $order = $this->order();
        $po = $this->po($order, 100000, 'PO-DISTINCT-'.rand(1000, 9999));
        $this->atl($order, $po);

        $this->assertNotEquals($order->id, $po->id, 'Fixture must have distinct ids.');

        $html = $this->actingAs($this->purchasing)
            ->get(route('stock-orders.sales-orders.show', $order->id))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString(
            route('stock-orders.add-atl', $order->id),
            $html,
            'add-atl expects a Purchase Order id; an Order id opens the wrong form.'
        );
    }

    // ---- The allocation pool -----------------------------------------------

    public function test_the_pool_offers_a_po_with_volume_left(): void
    {
        $po = $this->po(null, 100000);

        $numbers = PurchaseOrder::availableForAllocation()->pluck('po_number');

        $this->assertContains($po->po_number, $numbers);
    }

    public function test_the_pool_excludes_a_fully_drawn_po(): void
    {
        $order = $this->order();
        $po = $this->po(null, 20000);
        $this->atl($order, $po, 20000);

        $numbers = PurchaseOrder::availableForAllocation()->pluck('po_number');

        $this->assertNotContains(
            $po->po_number,
            $numbers,
            'A PO with nothing left to draw must not be offered.'
        );
    }

    public function test_the_pool_is_where_the_atl_form_gets_its_dropdown(): void
    {
        $order = $this->order();
        $po = $this->po($order, 100000);

        $html = $this->actingAs($this->purchasing)
            ->get(route('stock-orders.create-fuel-trade-po', $order->id))
            ->assertOk()
            ->getContent();

        // This is the supervisor's PO dropdown: pick a PO with remaining volume,
        // then the page states how much of the product is still available.
        $this->assertStringContainsString('drawdowns[0][purchase_order_id]', $html);
        $this->assertStringContainsString($po->po_number, $html);
        $this->assertStringContainsString('Split Across Another PO', $html);
    }

    public function test_adding_an_atl_to_a_specific_po_offers_no_other_po(): void
    {
        $po = $this->po(null, 100000);

        // This action raises another ATL against the PO named in the route, so
        // the form must not offer a dropdown of other POs to draw from.
        $html = $this->actingAs($this->purchasing)
            ->get(route('stock-orders.add-atl', $po->id))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('drawdowns[0][purchase_order_id]', $html);
        $this->assertStringNotContainsString('Split Across Another PO', $html);
    }
}
