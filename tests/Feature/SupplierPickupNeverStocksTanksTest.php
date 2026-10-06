<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Order;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderDelivery;
use App\Models\StockIn;
use App\Models\StorageTank;
use App\Models\Warehouse;
use Tests\TestCase;

class SupplierPickupNeverStocksTanksTest extends TestCase
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

    public function test_fuel_trade_pickup_does_not_stock_a_mobile_tanker(): void
    {
        [$order, $delivery] = $this->makePickup('FT-NOSTOCK', 'FUEL_TRADE', 'CLIENT_ORDER');
        $tank = $this->mobileTanker();

        // Even when a tanker is selected, a Fuel Trade lift goes to the client
        // at the supplier, so nothing may be stocked into a tank.
        $this->actingAs($this->purchasing)
            ->post(route('stock-orders.complete-fuel-trade', $delivery->id), [
                'storage_tank_id' => $tank->id,
            ])
            ->assertSessionHas('success');

        $this->assertEquals(0, StockIn::where('storage_tank_id', $tank->id)->count());
        $this->assertEquals(0, (float) $tank->fresh()->stock_available);

        // The lift itself must still have happened.
        $delivery->refresh();
        $this->assertEquals(PurchaseOrderDelivery::LIFT_LIFTED, $delivery->lift_status);
        $this->assertEquals(0, $order->fresh()->remaining_balance);
    }

    public function test_buy_back_pickup_does_not_stock_a_mobile_tanker(): void
    {
        [$order, $delivery] = $this->makePickup('FT-BBNOSTOCK', 'BUY_BACK', 'BUY_BACK');
        $tank = $this->mobileTanker();

        $this->actingAs($this->purchasing)
            ->post(route('stock-orders.complete-fuel-trade', $delivery->id), [
                'storage_tank_id' => $tank->id,
            ])
            ->assertSessionHas('success');

        $this->assertEquals(0, StockIn::where('storage_tank_id', $tank->id)->count());
        $this->assertEquals(0, (float) $tank->fresh()->stock_available);
    }

    private function makePickup(string $soPrefix, string $category, string $orderCategory): array
    {
        $order = Order::create([
            'account' => 'Apex Logistics',
            'location' => 'Valenzuela',
            'so_number' => $soPrefix . '-' . uniqid(),
            'date' => now(),
            'qty_ordered' => 1000,
            'price' => 50.00,
            'status' => 'Active',
            'clearing_status' => 'Approved',
            'fulfillment_type' => $category === 'BUY_BACK' ? 'DEPOT_PICKUP' : 'FUEL_TRADE',
            'order_category' => $orderCategory,
        ]);

        $po = PurchaseOrder::create([
            'po_number' => 'PO-' . rand(1000, 9999),
            'po_type' => $category,
            'supplier_name' => 'Petron Bataan',
            'qty_ordered' => 1000,
            'request_status' => 'CONFIRMED',
            'linked_order_id' => $order->id,
        ]);

        $delivery = PurchaseOrderDelivery::create([
            'purchase_order_id' => $po->id,
            'order_id' => $order->id,
            'so_number' => $order->so_number,
            // Doyen pickup, which historically auto-stocked a tanker.
            'delivery_channel' => $category === 'BUY_BACK' ? 'BUY_BACK_DOYEN_PICKUP' : 'SUPPLIER_DOYEN_PICKUP',
            'order_type' => 'PICK_UP',
            'atl_type' => 'DITC_ATL',
            'atl_number' => 'ATL-' . rand(1000, 9999),
            'atl_category' => $category === 'BUY_BACK'
                ? PurchaseOrderDelivery::CATEGORY_BUY_BACK
                : PurchaseOrderDelivery::CATEGORY_FUEL_TRADE,
            'lift_status' => PurchaseOrderDelivery::LIFT_UNLIFTED,
            'product' => 'Diesel',
            'qty_to_receive' => 1000,
            'receiving_date' => now()->addDay(),
            'status' => 'Active',
        ]);

        $order->update(['linked_purchase_order_id' => $po->id]);

        return [$order, $delivery];
    }

    private function mobileTanker(): StorageTank
    {
        $warehouse = Warehouse::firstOrCreate(['name' => 'Valenzuela Depot']);

        return StorageTank::create([
            'warehouse_id' => $warehouse->id,
            'name' => 'Tanker ' . rand(1000, 9999),
            'category' => 'tanker',
            'max_capacity' => 10000,
            'is_active' => true,
        ]);
    }
}
