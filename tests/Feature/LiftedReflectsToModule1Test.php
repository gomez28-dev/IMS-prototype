<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Delivery;
use App\Models\Order;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderDelivery;
use Tests\TestCase;

class LiftedReflectsToModule1Test extends TestCase
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

    public function test_lifting_a_fuel_trade_atl_reduces_the_module1_remaining_balance(): void
    {
        [$order, $delivery] = $this->fuelTradeSetup('FT-LIFT-1', 5000);

        // Before the lift, the order still owes its full volume.
        $this->assertEquals(5000, $order->fresh()->remaining_balance);
        $this->assertEquals('Pending', $order->fresh()->computed_status);

        $this->actingAs($this->purchasing)
            ->post(route('stock-orders.complete-fuel-trade', $delivery->id))
            ->assertSessionHas('success');

        $order->refresh();

        // Module 1 must actually show the volume as delivered, not just a
        // status string. remaining_balance is what every M1 screen reads.
        $this->assertEquals(0, $order->remaining_balance, 'M1 remaining balance should drop to zero after the lift');
        $this->assertEquals('Fulfilled', $order->computed_status);
        $this->assertEquals(5000, $order->total_qty_out);
    }

    public function test_module1_delivery_row_records_the_lift(): void
    {
        [$order, $delivery] = $this->fuelTradeSetup('FT-LIFT-2', 3000);

        $this->actingAs($this->purchasing)
            ->post(route('stock-orders.complete-fuel-trade', $delivery->id));

        // Module 1's Deliveries page lists Delivery rows, so the lift has to
        // be visible there as a fulfilled delivery.
        $m1Delivery = Delivery::where('order_id', $order->id)->first();

        $this->assertNotNull($m1Delivery, 'Expected a Module 1 delivery record for the lifted fuel');
        $this->assertEquals('FULFILLED', $m1Delivery->status);
        $this->assertEquals(3000, (int) $m1Delivery->qty_out);
    }

    public function test_lifting_does_not_double_count_the_volume_in_module_one(): void
    {
        [$order, $delivery] = $this->fuelTradeSetup('FT-LIFT-3', 4000);

        $this->actingAs($this->purchasing)
            ->post(route('stock-orders.complete-fuel-trade', $delivery->id));

        $order->refresh();

        // The volume must be counted once, not twice (once from the ATL and
        // once from a Module 1 delivery row).
        $this->assertEquals(4000, $order->total_qty_out);
        $this->assertEquals(0, $order->remaining_balance);
        $this->assertEquals(
            1,
            Delivery::where('order_id', $order->id)->count(),
            'Only one Module 1 delivery row should exist for this lift'
        );
    }

    public function test_lifted_atl_is_recorded_on_the_atl_row(): void
    {
        [$order, $delivery] = $this->fuelTradeSetup('FT-LIFT-4', 2500);

        $this->actingAs($this->purchasing)
            ->post(route('stock-orders.complete-fuel-trade', $delivery->id));

        $delivery->refresh();

        $this->assertEquals(PurchaseOrderDelivery::LIFT_LIFTED, $delivery->lift_status);
        $this->assertNotNull($delivery->lifted_at);
        $this->assertEquals($this->purchasing->id, $delivery->lifted_by);
    }

    public function test_buying_back_from_a_client_reflects_to_module_one(): void
    {
        $order = Order::create([
            'account' => 'Apex Logistics',
            'location' => 'Valenzuela',
            'so_number' => 'FT-BB-1-' . uniqid(),
            'date' => now(),
            'qty_ordered' => 1000,
            'price' => 50.00,
            'status' => 'Active',
            'clearing_status' => 'Approved',
            'fulfillment_type' => 'DEPOT_PICKUP',
            'order_category' => 'BUY_BACK',
        ]);

        $po = PurchaseOrder::create([
            'po_number' => 'PO-BB-' . rand(1000, 9999),
            'po_type' => 'BUY_BACK',
            'supplier_name' => 'Apex Logistics',
            'qty_ordered' => 1000,
            'request_status' => 'CONFIRMED',
            'linked_order_id' => $order->id,
        ]);

        $delivery = PurchaseOrderDelivery::create([
            'purchase_order_id' => $po->id,
            'order_id' => $order->id,
            'so_number' => $order->so_number,
            'delivery_channel' => 'BUY_BACK_CLIENT_PICKUP',
            'order_type' => 'PICK_UP',
            'atl_type' => 'DITC_ATL',
            'atl_number' => 'ATL-BB-1',
            'atl_category' => PurchaseOrderDelivery::CATEGORY_BUY_BACK,
            'lift_status' => PurchaseOrderDelivery::LIFT_UNLIFTED,
            'product' => 'Diesel',
            'qty_to_receive' => 1000,
            'receiving_date' => now()->addDay(),
            'status' => 'Active',
        ]);

        $order->update(['linked_purchase_order_id' => $po->id]);

        $this->actingAs($this->purchasing)
            ->post(route('stock-orders.complete-fuel-trade', $delivery->id))
            ->assertSessionHas('success');

        $order->refresh();
        $this->assertEquals(1000, $order->total_qty_out);
    }

    /**
     * @return array{0: Order, 1: PurchaseOrderDelivery}
     */
    private function fuelTradeSetup(string $soPrefix, int $qty): array
    {
        $order = Order::create([
            'account' => 'Apex Logistics',
            'location' => 'Valenzuela',
            'so_number' => $soPrefix . '-' . uniqid(),
            'date' => now(),
            'qty_ordered' => $qty,
            'price' => 50.00,
            'status' => 'Active',
            'clearing_status' => 'Approved',
            'fulfillment_type' => 'FUEL_TRADE',
            'order_category' => 'CLIENT_ORDER',
        ]);

        $po = PurchaseOrder::create([
            'po_number' => 'PO-' . rand(1000, 9999),
            'po_type' => 'FUEL_TRADE',
            'supplier_name' => 'Petron Bataan',
            'qty_ordered' => $qty,
            'request_status' => 'CONFIRMED',
            'linked_order_id' => $order->id,
        ]);

        $delivery = PurchaseOrderDelivery::create([
            'purchase_order_id' => $po->id,
            'order_id' => $order->id,
            'so_number' => $order->so_number,
            'delivery_channel' => 'SUPPLIER_CLIENT_PICKUP',
            'order_type' => 'PICK_UP',
            'atl_type' => 'DITC_ATL',
            'atl_number' => 'ATL-' . rand(1000, 9999),
            'atl_category' => PurchaseOrderDelivery::CATEGORY_FUEL_TRADE,
            'lift_status' => PurchaseOrderDelivery::LIFT_UNLIFTED,
            'product' => 'Diesel',
            'qty_to_receive' => $qty,
            'receiving_date' => now()->addDay(),
            'status' => 'Active',
        ]);

        $order->update(['linked_purchase_order_id' => $po->id]);

        return [$order, $delivery];
    }
}
