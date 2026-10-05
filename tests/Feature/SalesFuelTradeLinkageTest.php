<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Client;
use App\Models\Order;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderDelivery;
use Tests\TestCase;

class SalesFuelTradeLinkageTest extends TestCase
{
    protected Admin $salesUser;
    protected Admin $purchasingUser;
    protected Admin $vpUser;
    protected Client $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->salesUser = Admin::create([
            'username' => 'sales_' . uniqid(),
            'password' => bcrypt('secret'),
            'name' => 'Sales Agent',
            'role' => 'sales',
        ]);

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

        $this->client = Client::first() ?? Client::create(['name' => 'Apex Logistics Corp']);
    }

    public function test_sales_user_can_create_fuel_trade_order(): void
    {
        $soNumber = 'SO-FT-' . rand(1000, 9999);
        $payload = [
            'account' => $this->client->name,
            'location' => 'Valenzuela',
            'so_number' => $soNumber,
            'date' => now()->format('Y-m-d'),
            'qty_ordered' => 20000,
            'price' => 54.50,
            'status' => 'Active',
            'fulfillment_type' => 'FUEL_TRADE',
            'order_category' => 'CLIENT_ORDER',
        ];

        $response = $this->actingAs($this->salesUser)->post(route('order.store'), $payload);
        $response->assertRedirect();

        $this->assertDatabaseHas('orders', [
            'so_number' => $soNumber,
            'fulfillment_type' => 'FUEL_TRADE',
            'order_category' => 'CLIENT_ORDER',
        ]);
    }

    public function test_purchasing_can_create_supplier_po_from_fuel_trade_order(): void
    {
        $order = Order::create([
            'account' => $this->client->name,
            'location' => 'Valenzuela',
            'so_number' => 'SO-FT-' . uniqid(),
            'date' => now(),
            'qty_ordered' => 15000,
            'price' => 52.00,
            'status' => 'Active',
            'clearing_status' => 'Approved',
            'fulfillment_type' => 'FUEL_TRADE',
            'order_category' => 'CLIENT_ORDER',
        ]);

        $poPayload = [
            'linked_order_id' => $order->id,
            'po_number' => 'PO-SUP-' . rand(1000, 9999),
            'supplier_name' => 'Petron Bataan Refinery',
            'product' => 'Diesel',
            'qty_to_receive' => 15000,
            'receiving_date' => now()->addDay()->format('Y-m-d'),
            'driver_name' => 'Client Driver Juan',
            'plate_number' => 'NBZ-7788',
            'location' => 'Limay Terminal',
            'reference_no' => 'REF-FT-01',
            'atl_type' => 'DITC_ATL',
            'atl_number' => 'ATL-2026-FT01',
        ];

        $response = $this->actingAs($this->purchasingUser)
            ->post(route('stock-orders.store-fuel-trade-po', $order->id), $poPayload);

        $response->assertRedirect();

        $order->refresh();
        $this->assertNotNull($order->linked_purchase_order_id);

        $po = PurchaseOrder::find($order->linked_purchase_order_id);
        $this->assertEquals('FUEL_TRADE', $po->po_type);
        $this->assertEquals('RECEIVED', $po->request_status);
        $this->assertEquals($order->id, $po->linked_order_id);

        $delivery = $po->deliveries()->first();
        $this->assertEquals('SUPPLIER_CLIENT_PICKUP', $delivery->delivery_channel);
        $this->assertEquals('ATL-2026-FT01', $delivery->atl_number);
    }

    public function test_completing_fuel_trade_delivery_marks_linked_sales_order_fulfilled(): void
    {
        $order = Order::create([
            'account' => $this->client->name,
            'location' => 'Valenzuela',
            'so_number' => 'SO-COMP-' . uniqid(),
            'date' => now(),
            'qty_ordered' => 10000,
            'price' => 50.00,
            'status' => 'Active',
            'fulfillment_type' => 'FUEL_TRADE',
        ]);

        $po = PurchaseOrder::create([
            'po_number' => 'PO-LINK-' . uniqid(),
            'po_type' => 'FUEL_TRADE',
            'supplier_name' => 'Shell Tabangao',
            'qty_ordered' => 10000,
            'linked_order_id' => $order->id,
            'request_status' => 'FOR_DELIVERY',
        ]);

        $order->update(['linked_purchase_order_id' => $po->id]);

        $delivery = PurchaseOrderDelivery::create([
            'purchase_order_id' => $po->id,
            'delivery_channel' => 'SUPPLIER_CLIENT_PICKUP',
            'order_type' => 'PICK_UP',
            'atl_type' => 'DITC_ATL',
            'atl_number' => 'ATL-DISP-09',
            'product' => 'Diesel',
            'qty_to_receive' => 10000,
            'status' => 'Active',
        ]);

        $response = $this->actingAs($this->purchasingUser)
            ->post(route('stock-orders.complete-fuel-trade', $delivery->id));

        $response->assertSessionHas('success');

        $delivery->refresh();
        $this->assertEquals('Completed', $delivery->status);

        $po->refresh();
        $this->assertEquals('COMPLETED', $po->request_status);

        $order->refresh();
        $this->assertEquals('Fulfilled', $order->status);
    }
}
