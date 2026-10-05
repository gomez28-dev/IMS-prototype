<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderDelivery;
use App\Models\StockIn;
use App\Models\StorageTank;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class StockReceivedHandoffTest extends TestCase
{
    use DatabaseTransactions;

    protected Admin $opsManager;
    protected Warehouse $warehouse;
    protected StorageTank $tank;

    protected function setUp(): void
    {
        parent::setUp();

        $this->opsManager = Admin::create([
            'username' => 'ops_' . uniqid(),
            'password' => bcrypt('secret'),
            'name' => 'Depot Manager',
            'role' => 'ops_mgr',
            'is_active' => true,
        ]);

        $this->warehouse = Warehouse::create([
            'name' => 'San Simon Depot Test',
            'location' => 'San Simon, Pampanga',
        ]);

        $this->tank = StorageTank::create([
            'warehouse_id' => $this->warehouse->id,
            'name' => 'Tank Diesel 1',
            'fuel_type' => 'Diesel',
            'max_capacity' => 60000,
            'is_active' => true,
        ]);
    }

    public function test_depot_manager_can_request_replenishment(): void
    {
        $response = $this->actingAs($this->opsManager)
            ->post(route('wetstock.stock-requests.store'), [
                'warehouse_id' => $this->warehouse->id,
                'po_type' => 'STANDARD_REPLENISHMENT',
                'product' => 'Diesel',
                'qty_ordered' => 20000,
                'date_needed' => now()->addDays(3)->format('Y-m-d'),
                'remarks' => 'Critical low stock replenishment needed for weekend.',
            ]);

        $response->assertRedirect();
        
        $this->assertDatabaseHas('purchase_orders', [
            'warehouse_id' => $this->warehouse->id,
            'po_type' => 'STANDARD_REPLENISHMENT',
            'qty_ordered' => 20000,
            'request_status' => 'REQUESTED',
            'status' => 'Pending',
            'requested_by' => $this->opsManager->id,
        ]);
    }

    public function test_depot_manager_can_receive_depot_delivery_into_storage_tank(): void
    {
        $po = PurchaseOrder::create([
            'po_number' => 'PO-DEPOT-TEST-001',
            'po_type' => 'STANDARD_REPLENISHMENT',
            'supplier_name' => 'Petron Bataan',
            'warehouse_id' => $this->warehouse->id,
            'qty_ordered' => 10000,
            'request_status' => 'CONFIRMED',
            'status' => 'Pending',
            'requested_by' => $this->opsManager->id,
            'requested_products' => [['product' => 'Diesel', 'quantity' => 10000]],
        ]);

        $delivery = PurchaseOrderDelivery::create([
            'purchase_order_id' => $po->id,
            'delivery_channel' => 'SUPPLIER_STOCKS_DELIVERY',
            'order_type' => 'DELIVERY',
            'product' => 'Diesel',
            'qty_to_receive' => 10000,
            'status' => 'Active',
            'dr_number' => 'DR-PETRON-9988',
        ]);

        $response = $this->actingAs($this->opsManager)
            ->post(route('wetstock.deliveries.receive-stock', $delivery->id), [
                'storage_tank_id' => $this->tank->id,
                'date' => now()->format('Y-m-d'),
            ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $delivery->refresh();
        $this->assertEquals('Completed', $delivery->status);

        $po->refresh();
        $this->assertEquals('Fulfilled', $po->status);

        $this->assertDatabaseHas('stock_ins', [
            'storage_tank_id' => $this->tank->id,
            'quantity' => 10000,
            'purchase_order_delivery_id' => $delivery->id,
        ]);

        $this->assertEquals(10000, $this->tank->stock_available);
    }

    public function test_fuel_trade_delivery_cannot_be_received_into_depot_tanks(): void
    {
        $po = PurchaseOrder::create([
            'po_number' => 'PO-FT-TEST-002',
            'po_type' => 'FUEL_TRADE',
            'supplier_name' => 'Shell Tabangao',
            'warehouse_id' => null,
            'qty_ordered' => 15000,
            'request_status' => 'CONFIRMED',
            'status' => 'Pending',
            'requested_by' => $this->opsManager->id,
            'requested_products' => [['product' => 'Diesel', 'quantity' => 15000]],
        ]);

        $delivery = PurchaseOrderDelivery::create([
            'purchase_order_id' => $po->id,
            'delivery_channel' => 'SUPPLIER_CLIENT_PICKUP',
            'order_type' => 'PICK_UP',
            'product' => 'Diesel',
            'qty_to_receive' => 15000,
            'status' => 'Active',
        ]);

        $response = $this->actingAs($this->opsManager)
            ->post(route('wetstock.deliveries.receive-stock', $delivery->id), [
                'storage_tank_id' => $this->tank->id,
                'date' => now()->format('Y-m-d'),
            ]);

        $response->assertSessionHas('danger');
        $this->assertDatabaseMissing('stock_ins', [
            'purchase_order_delivery_id' => $delivery->id,
        ]);
    }
}
