<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Client;
use App\Models\Order;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderDelivery;
use Tests\TestCase;

class FuelTradeAccountingClearanceGateTest extends TestCase
{
    protected Admin $adminUser;
    protected Admin $purchasingUser;
    protected Client $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adminUser = Admin::create([
            'username' => 'admin_' . uniqid(),
            'password' => bcrypt('secret'),
            'name' => 'Admin User',
            'role' => 'admin',
        ]);

        $this->purchasingUser = Admin::create([
            'username' => 'purchasing_' . uniqid(),
            'password' => bcrypt('secret'),
            'name' => 'Purchasing Officer',
            'role' => 'purchasing',
        ]);

        $this->client = Client::first() ?? Client::create(['name' => 'Apex Logistics Corp']);
    }

    public function test_fuel_trade_order_formats_so_number_with_ft_prefix(): void
    {
        $ftOrder1 = new Order([
            'so_number' => 'SO-0042',
            'fulfillment_type' => 'FUEL_TRADE',
        ]);
        $this->assertEquals('FT-0042', $ftOrder1->formatted_so_number);

        $ftOrder2 = new Order([
            'so_number' => '1234',
            'fulfillment_type' => 'FUEL_TRADE',
        ]);
        $this->assertEquals('FT-1234', $ftOrder2->formatted_so_number);

        $ftOrder3 = new Order([
            'so_number' => 'FT-999',
            'fulfillment_type' => 'FUEL_TRADE',
        ]);
        $this->assertEquals('FT-999', $ftOrder3->formatted_so_number);

        $depotOrder = new Order([
            'so_number' => 'SO-0042',
            'fulfillment_type' => 'DEPOT_DELIVERY',
        ]);
        $this->assertEquals('SO-0042', $depotOrder->formatted_so_number);
    }

    public function test_cannot_access_fuel_trade_po_creation_when_clearance_is_not_approved(): void
    {
        $order = Order::create([
            'account' => $this->client->name,
            'location' => 'Valenzuela',
            'so_number' => 'SO-FT-' . rand(1000, 9999),
            'date' => now(),
            'qty_ordered' => 10000,
            'price' => 50.00,
            'status' => 'Active',
            'clearing_status' => 'Pending',
            'fulfillment_type' => 'FUEL_TRADE',
            'order_category' => 'CLIENT_ORDER',
        ]);

        $response = $this->actingAs($this->purchasingUser)
            ->get(route('stock-orders.create-fuel-trade-po', $order));

        $response->assertRedirect(route('stock-orders.index'));
        $response->assertSessionHas('error');
    }

    public function test_can_save_an_atl_as_a_draft_when_clearance_is_not_approved(): void
    {
        $order = Order::create([
            'account' => $this->client->name,
            'location' => 'Valenzuela',
            'so_number' => 'SO-FT-' . rand(1000, 9999),
            'date' => now(),
            'qty_ordered' => 10000,
            'price' => 50.00,
            'status' => 'Active',
            'clearing_status' => 'Pending',
            'fulfillment_type' => 'FUEL_TRADE',
            'order_category' => 'CLIENT_ORDER',
        ]);

        $payload = [
            'po_number' => 'PO-TEST-' . rand(1000, 9999),
            'supplier_name' => 'Petron Bataan Refinery',
            'product' => 'Diesel',
            'qty_to_receive' => 10000,
            'receiving_date' => now()->addDays(2)->format('Y-m-d'),
            'atl_type' => 'DITC_ATL',
            'atl_number' => 'ATL-123',
            // Explicitly saving a draft, not submitting for approval.
            'submit_for_approval' => 0,
        ];

        $response = $this->actingAs($this->purchasingUser)
            ->post(route('stock-orders.store-fuel-trade-po', $order), $payload);

        // Drafting is allowed before Accounting clears the order.
        $response->assertSessionHas('success');

        $atl = \App\Models\PurchaseOrderDelivery::latest('id')->firstOrFail();
        $this->assertEquals('DRAFT', $atl->approval_status);
    }

    public function test_cannot_submit_atl_for_approval_when_clearance_is_not_approved(): void
    {
        $order = Order::create([
            'account' => $this->client->name,
            'location' => 'Valenzuela',
            'so_number' => 'SO-FT-' . rand(1000, 9999),
            'date' => now(),
            'qty_ordered' => 10000,
            'price' => 50.00,
            'status' => 'Active',
            'clearing_status' => 'Pending',
            'fulfillment_type' => 'FUEL_TRADE',
            'order_category' => 'CLIENT_ORDER',
        ]);

        $payload = [
            'po_number' => 'PO-TEST-' . rand(1000, 9999),
            'supplier_name' => 'Petron Bataan Refinery',
            'product' => 'Diesel',
            'qty_to_receive' => 10000,
            'receiving_date' => now()->addDays(2)->format('Y-m-d'),
            'atl_type' => 'DITC_ATL',
            'atl_number' => 'ATL-123',
            'submit_for_approval' => 1,
        ];

        $response = $this->actingAs($this->purchasingUser)
            ->post(route('stock-orders.store-fuel-trade-po', $order), $payload);

        $response->assertSessionHasErrors();
        $this->assertNull(\App\Models\PurchaseOrderDelivery::latest('id')->first());
    }

    public function test_can_access_and_store_fuel_trade_po_when_clearance_is_approved(): void
    {
        $order = Order::create([
            'account' => $this->client->name,
            'location' => 'Valenzuela',
            'so_number' => 'SO-FT-' . rand(1000, 9999),
            'date' => now(),
            'qty_ordered' => 10000,
            'price' => 50.00,
            'status' => 'Active',
            'clearing_status' => 'Approved',
            'fulfillment_type' => 'FUEL_TRADE',
            'order_category' => 'CLIENT_ORDER',
        ]);

        // GET form
        $response = $this->actingAs($this->purchasingUser)
            ->get(route('stock-orders.create-fuel-trade-po', $order));
        $response->assertStatus(200);

        // POST submit
        $poNumber = 'PO-APP-' . rand(1000, 9999);
        $payload = [
            'po_number' => $poNumber,
            'supplier_name' => 'Petron Bataan Refinery',
            'product' => 'Diesel',
            'qty_to_receive' => 10000,
            'receiving_date' => now()->addDays(2)->format('Y-m-d'),
            'atl_type' => 'DITC_ATL',
            'atl_number' => 'ATL-APPROVED-001',
        ];

        $storeResponse = $this->actingAs($this->purchasingUser)
            ->post(route('stock-orders.store-fuel-trade-po', $order), $payload);

        $storeResponse->assertRedirect(route('stock-orders.index'));
        $storeResponse->assertSessionHas('success');

        $this->assertDatabaseHas('purchase_orders', [
            'po_number' => $poNumber,
            'linked_order_id' => $order->id,
        ]);
    }

    public function test_fuel_trade_deliveries_view_adapts_columns_and_shows_locked_or_atl_buttons(): void
    {
        // 1. Pending clearance order
        $pendingOrder = Order::create([
            'account' => $this->client->name,
            'location' => 'Valenzuela',
            'so_number' => 'SO-0077',
            'date' => now(),
            'qty_ordered' => 15000,
            'price' => 50.00,
            'status' => 'Active',
            'clearing_status' => 'Pending',
            'fulfillment_type' => 'FUEL_TRADE',
            'order_category' => 'CLIENT_ORDER',
        ]);

        $pendingView = $this->actingAs($this->adminUser)
            ->get(route('order.deliveries', $pendingOrder));

        $pendingView->assertStatus(200);
        $pendingView->assertSee('PO#');
        $pendingView->assertSee('Pick Up Date');
        $pendingView->assertSee('Locked: Pending Accounting Clearance');

        // 2. Approved order without ATL
        $approvedOrder = Order::create([
            'account' => $this->client->name,
            'location' => 'Valenzuela',
            'so_number' => 'SO-0088',
            'date' => now(),
            'qty_ordered' => 20000,
            'price' => 50.00,
            'status' => 'Active',
            'clearing_status' => 'Approved',
            'fulfillment_type' => 'FUEL_TRADE',
            'order_category' => 'CLIENT_ORDER',
        ]);

        $approvedView = $this->actingAs($this->adminUser)
            ->get(route('order.deliveries', $approvedOrder));

        $approvedView->assertStatus(200);
        // ATL issuance moved to Module 3, so Module 1 hands off instead of
        // offering the Issue ATL button.
        $approvedView->assertSee('Open in Module 3');
        $approvedView->assertDontSee('Issue ATL (Module 3)');

        // 3. Approved order with issued ATL
        $po = PurchaseOrder::create([
            'po_number' => 'PO-LINKED-01',
            'po_type' => 'FUEL_TRADE',
            'supplier_name' => 'Petron Refinery',
            'linked_order_id' => $approvedOrder->id,
            'qty_ordered' => 20000,
            'request_status' => 'RECEIVED',
            'status' => 'Pending',
        ]);
        $approvedOrder->update(['linked_purchase_order_id' => $po->id]);

        $delivery = PurchaseOrderDelivery::create([
            'purchase_order_id' => $po->id,
            'delivery_channel' => 'SUPPLIER_CLIENT_PICKUP',
            'order_type' => 'PICK_UP',
            'atl_type' => 'DITC_ATL',
            'atl_number' => 'ATL-ISSUED-99',
            'product' => 'Diesel',
            'qty_to_receive' => 20000,
            'receiving_date' => now()->addDay(),
            'status' => 'Pending',
        ]);

        $issuedView = $this->actingAs($this->adminUser)
            ->get(route('order.deliveries', $approvedOrder));

        $issuedView->assertStatus(200);
        $issuedView->assertSee('View ATL Details');
        $issuedView->assertSee('Download ATL PDF');
        $issuedView->assertSee('ATL-ISSUED-99');
        $issuedView->assertSee('PO-LINKED-01');
    }

    public function test_fuel_trade_queue_displays_clearance_status_and_locks_unapproved_orders(): void
    {
        $unapprovedOrder = Order::create([
            'account' => $this->client->name,
            'location' => 'Valenzuela',
            'so_number' => 'SO-1011',
            'date' => now(),
            'qty_ordered' => 12000,
            'price' => 50.00,
            'status' => 'Active',
            'clearing_status' => 'Hold',
            'fulfillment_type' => 'FUEL_TRADE',
            'order_category' => 'CLIENT_ORDER',
        ]);

        $approvedOrder = Order::create([
            'account' => $this->client->name,
            'location' => 'Valenzuela',
            'so_number' => 'SO-1012',
            'date' => now(),
            'qty_ordered' => 14000,
            'price' => 50.00,
            'status' => 'Active',
            'clearing_status' => 'Approved',
            'fulfillment_type' => 'FUEL_TRADE',
            'order_category' => 'CLIENT_ORDER',
        ]);

        $response = $this->actingAs($this->purchasingUser)
            ->get(route('stock-orders.index'));

        $response->assertStatus(200);
        $response->assertSee('FT-1011');
        $response->assertSee('FT-1012');
        $response->assertSee('Locked: Pending Clearance');
        $response->assertSee('Prepare PO & ATL', false);
    }

    public function test_orders_dashboard_displays_ft_formatted_so_number(): void
    {
        $ftOrder = Order::create([
            'account' => $this->client->name,
            'location' => 'Valenzuela',
            'so_number' => 'SO-5555',
            'date' => now(),
            'qty_ordered' => 15000,
            'price' => 52.00,
            'status' => 'Active',
            'clearing_status' => 'Pending',
            'fulfillment_type' => 'FUEL_TRADE',
            'order_category' => 'CLIENT_ORDER',
        ]);

        $response = $this->actingAs($this->adminUser)
            ->get(route('dashboard'));

        $response->assertStatus(200);
        $response->assertSee('FT-5555');
    }
}
