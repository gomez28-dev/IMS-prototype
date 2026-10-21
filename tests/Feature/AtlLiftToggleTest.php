<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\AtlAllocation;
use App\Models\Client;
use App\Models\Delivery;
use App\Models\Order;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderDelivery;
use App\Models\Supplier;
use App\Models\StockIn;
use App\Models\StorageTank;
use Tests\TestCase;
use App\Models\Warehouse;

/**
 * Lift and unlift, per ATL record, on the Sales Order details page.
 *
 * The lift already existed - one button at the top of the page, bound to the
 * FIRST active unlifted ATL - but a second ATL could not be lifted from that
 * page, and once a lift was recorded there was no way to reverse it at all.
 * Now every active unlifted row carries its own lift action, every lifted row
 * carries Mark Unlifted, and unlifting reverses everything the lift wrote:
 * the ATL row, the tanker stock-in, the PO flow state, the order status, and
 * the Module 1 delivery mirror that M1's Delivered/Remaining figures read.
 */
class AtlLiftToggleTest extends TestCase
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
        $this->purchasing->refresh();

        Client::first() ?? Client::create(['name' => 'Apex Logistics Corp']);
    }

    /**
     * A Fuel Trade order with the given number of ATLs against one PO, all
     * approved, dispatched and waiting to be lifted.
     *
     * @return array{0: Order, 1: PurchaseOrder, 2: list<PurchaseOrderDelivery>}
     */
    protected function fuelTradeOrder(int $deliveries = 1): array
    {
        $order = Order::create([
            'account' => 'Apex Logistics Corp',
            'location' => 'Valenzuela',
            'so_number' => 'FT-'.rand(1000, 9999),
            'date' => now(),
            'status' => 'Active',
            'terms' => 'COD',
            'order_category' => Order::CATEGORY_CLIENT_ORDER,
            'fulfillment_type' => Order::FULFILLMENT_FUEL_TRADE,
            'qty_ordered' => 5000,
            'clearing_status' => 'Approved',
            'created_by' => $this->purchasing->id,
        ]);

        $supplier = Supplier::create([
            'company_name' => 'Petron Bataan Refinery',
            'location' => 'Limay',
        ]);

        $po = PurchaseOrder::create([
            'po_number' => 'PO-FT-'.rand(1000, 9999),
            'po_type' => PurchaseOrder::TYPE_FUEL_TRADE,
            'supplier_id' => $supplier->id,
            'supplier_name' => $supplier->company_name,
            'qty_ordered' => 5000,
            'total_amount' => 250000.00,
            'request_status' => 'FOR_DELIVERY',
            'status' => 'For Delivery',
            'linked_order_id' => $order->id,
            'prepared_by' => $this->purchasing->id,
        ]);

        $order->update(['linked_purchase_order_id' => $po->id]);

        $rows = [];
        for ($i = 0; $i < $deliveries; $i++) {
            $delivery = PurchaseOrderDelivery::create([
                'purchase_order_id' => $po->id,
                'order_id' => $order->id,
                'so_number' => $order->so_number,
                'delivery_channel' => 'SUPPLIER_CLIENT_PICKUP',
                'order_type' => 'PICK_UP',
                'atl_type' => 'DITC_ATL',
                'atl_number' => 'ATL-'.rand(100000, 999999),
                'atl_category' => PurchaseOrderDelivery::CATEGORY_FUEL_TRADE,
                'product' => 'Diesel',
                'qty_to_receive' => 5000,
                'receiving_date' => now()->addDay(),
                'status' => 'Active',
                'lift_status' => PurchaseOrderDelivery::LIFT_UNLIFTED,
                'approval_status' => 'APPROVED',
                'prepared_by' => $this->purchasing->id,
            ]);

            // The volume the ATL draws from the PO - the same shape the store
            // flow writes, and what drawnByProduct()/liftedByProduct() read.
            AtlAllocation::create([
                'purchase_order_delivery_id' => $delivery->id,
                'purchase_order_id' => $po->id,
                'product' => 'Diesel',
                'quantity' => 5000,
            ]);

            $rows[] = $delivery;
        }

        return [$order->fresh(), $po->fresh(), $rows];
    }

    protected function show(Order $order): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->purchasing)
            ->get(route('stock-orders.sales-orders.show', $order->id));
    }

    // ---- Every record carries its own action --------------------------------

    public function test_every_active_unlifted_row_carries_its_own_lift_modal(): void
    {
        [$order, $po, $rows] = $this->fuelTradeOrder(2);

        $html = $this->show($order)->assertOk()->getContent();

        // Two ATLs waiting to be lifted, and each one gets its own modal
        // rather than the page offering just the first. Each render carries
        // the string twice (button target + modal id), so count modal
        // definitions only.
        $this->assertSame(2, substr_count($html, 'id="confirmLiftModal'), 'Each liftable row needs its own modal.');

        foreach ($rows as $row) {
            $this->assertStringContainsString(
                route('stock-orders.complete-fuel-trade', $row->id),
                $html,
                'Row '.$row->id.' must post to its own lift action.'
            );
        }
    }

    public function test_a_lifted_row_offers_an_unlift_action(): void
    {
        [$order, $po, $rows] = $this->fuelTradeOrder(1);
        $rows[0]->update(['lift_status' => PurchaseOrderDelivery::LIFT_LIFTED]);

        $html = $this->show($order)->assertOk()->getContent();

        $this->assertStringContainsString('Mark Unlifted', $html);
        $this->assertStringContainsString('unliftModal', $html);
        $this->assertStringContainsString(
            route('stock-orders.unlift-atl', $rows[0]->id),
            $html,
            'The reversal posts to the unlift route.'
        );

        // And a lifted row must no longer offer lifting itself.
        $this->assertStringNotContainsString(
            route('stock-orders.complete-fuel-trade', $rows[0]->id),
            $html
        );
    }

    // ---- The reversal --------------------------------------------------------

    public function test_unlifting_reverses_what_the_lift_wrote(): void
    {
        [$order, $po, $rows] = $this->fuelTradeOrder(1);
        $delivery = $rows[0];

        $this->actingAs($this->purchasing)
            ->post(route('stock-orders.complete-fuel-trade', $delivery->id))
            ->assertSessionHas('success');

        $delivery->refresh();
        $this->assertSame(PurchaseOrderDelivery::LIFT_LIFTED, $delivery->lift_status);
        $this->assertSame(1, Delivery::where('order_id', $order->id)->count(), 'Lift wrote the M1 mirror.');
        $this->assertEquals(5000, $order->fresh()->total_qty_out);

        $this->actingAs($this->purchasing)
            ->post(route('stock-orders.unlift-atl', $delivery->id))
            ->assertSessionHas('success');

        $delivery->refresh();
        $order->refresh();
        $po->refresh();

        $this->assertSame(PurchaseOrderDelivery::LIFT_UNLIFTED, $delivery->lift_status);
        $this->assertNull($delivery->lifted_at);
        $this->assertNull($delivery->lifted_by);
        $this->assertSame('Active', $delivery->status, 'An approved, unlifted ATL is back in flight.');

        // The PO is back to in-flight, where a dispatch and a second lift can
        // happen - not the pre-approval CONFIRMED state.
        $this->assertSame('FOR_DELIVERY', $po->request_status);
        $this->assertSame('For Delivery', $po->status);

        // The order only falls back because no other lifted ATL holds volume.
        $this->assertSame('Active', $order->status);

        // M1's balance figures read the mirror, so the mirror has to be gone.
        $this->assertSame(0, Delivery::where('order_id', $order->id)->count(), 'The M1 mirror row is removed.');
        $this->assertEquals(0, $order->total_qty_out, 'M1 no longer counts the reversed lift as delivered.');
        $this->assertEquals(5000, $order->remaining_balance, 'M1 remaining goes back to the full order.');

        // And the ATL volume is a live draw again, so the shared filter makes
        // it agree with the form-side remaining figure.
        $this->assertSame(['Diesel' => 5000], $order->drawnByProduct(), 'The unlifted ATL holds volume again.');
    }

    public function test_unlifting_keeps_the_order_fulfilled_when_another_atl_is_still_lifted(): void
    {
        [$order, $po, $rows] = $this->fuelTradeOrder(2);

        $this->actingAs($this->purchasing)
            ->post(route('stock-orders.complete-fuel-trade', $rows[0]->id));
        $this->actingAs($this->purchasing)
            ->post(route('stock-orders.complete-fuel-trade', $rows[1]->id));

        // Reversing one of two lifted ATLs must not un-fulfil the order.
        $this->actingAs($this->purchasing)
            ->post(route('stock-orders.unlift-atl', $rows[0]->id))
            ->assertSessionHas('success');

        $this->assertSame('Fulfilled', $order->fresh()->status);
        $this->assertSame(1, Delivery::where('order_id', $order->id)->count());
        $this->assertEquals(5000, $order->fresh()->total_qty_out);
    }

    public function test_unlifting_keeps_the_po_completed_when_another_lifted_atl_draws_from_it(): void
    {
        [$order, $po, $rows] = $this->fuelTradeOrder(2);

        $this->actingAs($this->purchasing)
            ->post(route('stock-orders.complete-fuel-trade', $rows[0]->id));
        $this->actingAs($this->purchasing)
            ->post(route('stock-orders.complete-fuel-trade', $rows[1]->id));

        $this->actingAs($this->purchasing)
            ->post(route('stock-orders.unlift-atl', $rows[0]->id))
            ->assertSessionHas('success');

        $po->refresh();
        $this->assertSame('COMPLETED', $po->request_status, 'The second lifted ATL keeps the PO completed.');
        $this->assertSame('Fulfilled', $po->status);
    }

    // ---- The guards ----------------------------------------------------------

    /**
     * Stock recorded against the delivery goes with the reverse. A FUEL TRADE
     * client rack lift writes no StockIn of its own, so the row here stands
     * for whatever Module 2 recorded against this ATL - fuel that, with the
     * lift reversed, was never delivered to the truck at all.
     */
    public function test_unlifting_also_removes_the_stock_the_delivery_stood_for(): void
    {
        [$order, $po, $rows] = $this->fuelTradeOrder(1);

        $tank = StorageTank::create([
            'warehouse_id' => Warehouse::create(['name' => 'Lift Depot '.rand(1000, 9999)])->id,
            'name' => 'Tanker T-'.rand(100, 999),
            'category' => 'tanker',
            'product_type' => 'D',
            'max_capacity' => 50000,
            'is_active' => true,
        ]);

        $delivery = $rows[0];
        StockIn::create([
            'storage_tank_id' => $tank->id,
            'admin_id' => $this->purchasing->id,
            'purchase_order_delivery_id' => $delivery->id,
            'quantity' => 5000,
            'date' => now(),
        ]);

        $this->actingAs($this->purchasing)
            ->post(route('stock-orders.complete-fuel-trade', $delivery->id))
            ->assertSessionHas('success');
        $this->assertSame(1, StockIn::where('purchase_order_delivery_id', $delivery->id)->count());

        $this->actingAs($this->purchasing)
            ->post(route('stock-orders.unlift-atl', $delivery->id))
            ->assertSessionHas('success');

        $this->assertSame(
            0,
            StockIn::where('purchase_order_delivery_id', $delivery->id)->count(),
            'Stock recorded against this ATL goes with the reversal.'
        );
    }

    public function test_unlift_requires_the_same_grant_as_lift(): void
    {
        [$order, $po, $rows] = $this->fuelTradeOrder(1);
        $rows[0]->update(['lift_status' => PurchaseOrderDelivery::LIFT_LIFTED]);

        $sales = Admin::create([
            'username' => 'sales_'.uniqid(),
            'password' => bcrypt('secret'),
            'name' => 'Sales User',
            'role' => 'sales',
        ]);

        $this->actingAs($sales)
            ->post(route('stock-orders.unlift-atl', $rows[0]->id))
            ->assertForbidden();
    }

    public function test_unlift_refuses_an_atl_that_was_never_lifted(): void
    {
        [$order, $po, $rows] = $this->fuelTradeOrder(1);

        $this->actingAs($this->purchasing)
            ->post(route('stock-orders.unlift-atl', $rows[0]->id))
            ->assertSessionHas('error');
    }
}
