<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Client;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderDelivery;
use App\Models\StorageTank;
use App\Models\Warehouse;
use Tests\TestCase;

class PoBuyBackTest extends TestCase
{
    protected Admin $purchasingUser;
    protected Admin $vpUser;
    protected Admin $opsUser;
    protected Client $client;
    protected Warehouse $warehouse;
    protected StorageTank $tank;

    protected function setUp(): void
    {
        parent::setUp();

        $this->purchasingUser = Admin::create([
            'username' => 'purchasing_' . uniqid(),
            'password' => bcrypt('secret'),
            'name' => 'Rica Esaga',
            'role' => 'purchasing',
        ]);

        $this->vpUser = Admin::create([
            'username' => 'vp_' . uniqid(),
            'password' => bcrypt('secret'),
            'name' => 'Bernice Nikki Lee',
            'role' => 'vp',
        ]);

        $this->opsUser = Admin::create([
            'username' => 'ops_' . uniqid(),
            'password' => bcrypt('secret'),
            'name' => 'Ops Receiver',
            'role' => 'ops_wh',
        ]);

        $this->client = Client::first() ?? Client::create(['name' => 'Metro Transport Services']);

        $this->warehouse = Warehouse::create([
            'name' => 'San Simon Depot ' . uniqid(),
            'location' => 'San Simon',
        ]);

        $this->tank = StorageTank::create([
            'warehouse_id' => $this->warehouse->id,
            'name' => 'Tank BB-1',
            'category' => 'depot',
            'max_capacity' => 50000,
            'is_active' => true,
        ]);
    }

    public function test_purchasing_can_create_and_approve_po_buy_back(): void
    {
        $poNumber = 'PO-BB-' . rand(1000, 9999);
        $po = PurchaseOrder::create([
            'po_number' => $poNumber,
            'po_type' => 'BUY_BACK',
            'client_id' => $this->client->id,
            'supplier_name' => $this->client->name,
            'warehouse_id' => $this->warehouse->id,
            'qty_ordered' => 12000,
            'request_status' => 'RECEIVED',
            'requested_by' => $this->purchasingUser->id,
            'requested_products' => [
                ['product' => 'Diesel', 'quantity' => 12000]
            ],
        ]);

        $delivery = PurchaseOrderDelivery::create([
            'purchase_order_id' => $po->id,
            'delivery_channel' => 'BUY_BACK_DOYEN_PICKUP',
            'order_type' => 'PICK_UP',
            'atl_type' => 'DITC_ATL',
            'atl_number' => 'ATL-BB-01',
            'product' => 'Diesel',
            'qty_to_receive' => 12000,
            'driver_name' => 'Doyen Driver Mario',
            'plate_number' => 'NBK-9090',
            'status' => 'Pending',
        ]);

        // VP Approves
        $response = $this->actingAs($this->vpUser)->post(route('stock-orders.approve', $po->id));
        $response->assertSessionHas('success');

        $po->refresh();
        $this->assertEquals('CONFIRMED', $po->request_status);
        $this->assertEquals($this->vpUser->id, $po->approved_by);

        // Purchasing dispatches
        $this->actingAs($this->purchasingUser)->post(route('stock-orders.dispatch-delivery', $delivery->id));
        $delivery->refresh();
        $this->assertEquals('Active', $delivery->status);
    }
}
