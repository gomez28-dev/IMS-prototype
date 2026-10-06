<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Client;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderDelivery;
use App\Models\PurchaseOrderItem;
use Tests\TestCase;

class SupplierPoAllocationDrawdownTest extends TestCase
{
    protected Admin $purchasingUser;
    protected Admin $vpUser;
    protected Client $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->purchasingUser = Admin::create([
            'username' => 'purchasing_' . uniqid(),
            'password' => bcrypt('secret'),
            'name' => 'Purchasing Officer',
            'role' => 'purchasing',
        ]);

        $this->vpUser = Admin::create([
            'username' => 'vp_' . uniqid(),
            'password' => bcrypt('secret'),
            'name' => 'VP Operations',
            'role' => 'vp',
        ]);

        $this->client = Client::first() ?? Client::create(['name' => 'Mega Freight Corp']);
    }

    public function test_purchasing_can_create_independent_supplier_po_with_multiple_products(): void
    {
        $payload = [
            'po_number' => 'PO-BULK-' . rand(1000, 9999),
            'supplier_name' => 'Petron Bataan Refinery',
            'po_type' => 'STANDARD_REPLENISHMENT',
            'date_needed' => now()->addDays(5)->format('Y-m-d'),
            'remarks' => 'Bulk refinery order for next week',
            'items' => [
                ['product' => 'Diesel', 'quantity' => 50000, 'unit_price' => 48.50],
                ['product' => 'Premium', 'quantity' => 20000, 'unit_price' => 55.00],
            ],
        ];

        $response = $this->actingAs($this->purchasingUser)
            ->post(route('stock-orders.store-supplier-po'), $payload);

        $response->assertRedirect(route('stock-orders.index'));
        $response->assertSessionHas('success');

        $this->assertDatabaseHas('purchase_orders', [
            'po_number' => $payload['po_number'],
            'supplier_name' => 'Petron Bataan Refinery',
            'qty_ordered' => 70000,
        ]);

        $po = PurchaseOrder::where('po_number', $payload['po_number'])->first();
        $this->assertNotNull($po);
        $this->assertCount(2, $po->items);
        $this->assertEquals(50000, $po->getAvailableBalanceForProduct('Diesel'));
        $this->assertEquals(20000, $po->getAvailableBalanceForProduct('Premium'));
    }

    public function test_purchasing_can_issue_atl_drawn_from_single_supplier_po(): void
    {
        // 1. Create a bulk PO with 50,000L Diesel
        $po = PurchaseOrder::create([
            'po_number' => 'PO-POOL-01',
            'po_type' => 'STANDARD_REPLENISHMENT',
            'supplier_name' => 'Shell Tabangao',
            'qty_ordered' => 50000,
            'request_status' => 'CONFIRMED',
            'status' => 'Pending',
        ]);
        PurchaseOrderItem::create([
            'purchase_order_id' => $po->id,
            'product' => 'Diesel',
            'quantity_ordered' => 50000,
        ]);

        // 2. Create an Approved Fuel Trade SO for 10,000L
        $order = Order::create([
            'account' => $this->client->name,
            'location' => 'Valenzuela',
            'so_number' => 'SO-FT-001',
            'date' => now(),
            'qty_ordered' => 10000,
            'price' => 50.00,
            'status' => 'Active',
            'clearing_status' => 'Approved',
            'fulfillment_type' => 'FUEL_TRADE',
            'order_category' => 'CLIENT_ORDER',
        ]);

        $payload = [
            'receiving_date' => now()->addDays(2)->format('Y-m-d'),
            'driver_name' => 'Juan Dela Cruz',
            'plate_number' => 'NBD-1234',
            'location' => 'Shell Tabangao Terminal',
            'atl_type' => 'DITC_ATL',
            'atl_number' => 'ATL-2026-0001',
            'drawdowns' => [
                ['purchase_order_id' => $po->id, 'quantity' => 10000],
            ],
        ];

        $response = $this->actingAs($this->purchasingUser)
            ->post(route('stock-orders.store-fuel-trade-po', $order->id), $payload);

        $response->assertRedirect(route('stock-orders.index'));
        $response->assertSessionHas('success');

        $delivery = PurchaseOrderDelivery::where('atl_number', 'ATL-2026-0001')->first();
        $this->assertNotNull($delivery);
        $this->assertEquals(10000, $delivery->qty_to_receive);
        $this->assertCount(1, $delivery->allocations);

        // Remaining balance of the Supplier PO must now be 40,000L
        $this->assertEquals(40000, $po->fresh()->getAvailableBalanceForProduct('Diesel'));
    }

    public function test_purchasing_can_issue_atl_split_across_multiple_supplier_pos(): void
    {
        // PO 1: 4,000L Diesel remaining
        $po1 = PurchaseOrder::create([
            'po_number' => 'PO-PETRON-01',
            'po_type' => 'STANDARD_REPLENISHMENT',
            'supplier_name' => 'Petron Bataan',
            'qty_ordered' => 4000,
            'request_status' => 'CONFIRMED',
            'status' => 'Pending',
        ]);
        PurchaseOrderItem::create([
            'purchase_order_id' => $po1->id,
            'product' => 'Diesel',
            'quantity_ordered' => 4000,
        ]);

        // PO 2: 20,000L Diesel remaining
        $po2 = PurchaseOrder::create([
            'po_number' => 'PO-SHELL-02',
            'po_type' => 'STANDARD_REPLENISHMENT',
            'supplier_name' => 'Shell Tabangao',
            'qty_ordered' => 20000,
            'request_status' => 'CONFIRMED',
            'status' => 'Pending',
        ]);
        PurchaseOrderItem::create([
            'purchase_order_id' => $po2->id,
            'product' => 'Diesel',
            'quantity_ordered' => 20000,
        ]);

        // Client SO for 10,000L Diesel
        $order = Order::create([
            'account' => $this->client->name,
            'location' => 'Valenzuela',
            'so_number' => 'SO-FT-002',
            'date' => now(),
            'qty_ordered' => 10000,
            'price' => 50.00,
            'status' => 'Active',
            'clearing_status' => 'Approved',
            'fulfillment_type' => 'FUEL_TRADE',
            'order_category' => 'CLIENT_ORDER',
        ]);

        // Draw 4,000 from PO1 and 6,000 from PO2
        $payload = [
            'receiving_date' => now()->addDays(2)->format('Y-m-d'),
            'driver_name' => 'Carlos Santos',
            'plate_number' => 'XYZ-9988',
            'location' => 'Multiple Refineries',
            'atl_type' => 'DITC_ATL',
            'atl_number' => 'ATL-MULTI-01',
            'drawdowns' => [
                ['purchase_order_id' => $po1->id, 'quantity' => 4000],
                ['purchase_order_id' => $po2->id, 'quantity' => 6000],
            ],
        ];

        $response = $this->actingAs($this->purchasingUser)
            ->post(route('stock-orders.store-fuel-trade-po', $order->id), $payload);

        $response->assertRedirect(route('stock-orders.index'));

        // PO1 is fully depleted (0L remaining)
        $this->assertEquals(0, $po1->fresh()->getAvailableBalanceForProduct('Diesel'));
        // PO2 has 14,000L remaining (20,000 - 6,000)
        $this->assertEquals(14000, $po2->fresh()->getAvailableBalanceForProduct('Diesel'));

        $delivery = PurchaseOrderDelivery::where('atl_number', 'ATL-MULTI-01')->first();
        $this->assertNotNull($delivery);
        $this->assertCount(2, $delivery->allocations);
    }

    public function test_cannot_draw_more_volume_than_available_on_supplier_po(): void
    {
        $po = PurchaseOrder::create([
            'po_number' => 'PO-SMALL-01',
            'po_type' => 'STANDARD_REPLENISHMENT',
            'supplier_name' => 'Petron Bataan',
            'qty_ordered' => 5000,
            'request_status' => 'CONFIRMED',
            'status' => 'Pending',
        ]);
        PurchaseOrderItem::create([
            'purchase_order_id' => $po->id,
            'product' => 'Diesel',
            'quantity_ordered' => 5000,
        ]);

        $order = Order::create([
            'account' => $this->client->name,
            'location' => 'Valenzuela',
            'so_number' => 'SO-FT-003',
            'date' => now(),
            'qty_ordered' => 10000,
            'price' => 50.00,
            'status' => 'Active',
            'clearing_status' => 'Approved',
            'fulfillment_type' => 'FUEL_TRADE',
            'order_category' => 'CLIENT_ORDER',
        ]);

        // Attempting to draw 10,000 from a PO that only has 5,000
        $payload = [
            'receiving_date' => now()->addDays(2)->format('Y-m-d'),
            'driver_name' => 'Driver',
            'plate_number' => 'ABC-1111',
            'atl_type' => 'DITC_ATL',
            'atl_number' => 'ATL-FAIL-01',
            'drawdowns' => [
                ['purchase_order_id' => $po->id, 'quantity' => 10000],
            ],
        ];

        $response = $this->actingAs($this->purchasingUser)
            ->post(route('stock-orders.store-fuel-trade-po', $order->id), $payload);

        $response->assertSessionHasErrors();
        $this->assertEquals(5000, $po->fresh()->getAvailableBalanceForProduct('Diesel'));
    }

    public function test_cannot_issue_atl_when_total_drawn_does_not_match_so_quantity(): void
    {
        $po = PurchaseOrder::create([
            'po_number' => 'PO-BIG-01',
            'po_type' => 'STANDARD_REPLENISHMENT',
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

        $order = Order::create([
            'account' => $this->client->name,
            'location' => 'Valenzuela',
            'so_number' => 'SO-FT-004',
            'date' => now(),
            'qty_ordered' => 10000,
            'price' => 50.00,
            'status' => 'Active',
            'clearing_status' => 'Approved',
            'fulfillment_type' => 'FUEL_TRADE',
            'order_category' => 'CLIENT_ORDER',
        ]);

        // SO is for 10,000, but only drawing 7,000
        $payload = [
            'receiving_date' => now()->addDays(2)->format('Y-m-d'),
            'driver_name' => 'Driver',
            'plate_number' => 'ABC-1111',
            'atl_type' => 'DITC_ATL',
            'atl_number' => 'ATL-FAIL-02',
            'drawdowns' => [
                ['purchase_order_id' => $po->id, 'quantity' => 7000],
            ],
        ];

        $response = $this->actingAs($this->purchasingUser)
            ->post(route('stock-orders.store-fuel-trade-po', $order->id), $payload);

        $response->assertSessionHasErrors();
    }

    /**
     * A Fuel Trade SO can carry several product lines (order_items), so the
     * ATL must draw each product separately instead of one flat total.
     */
    public function test_multi_product_fuel_trade_order_draws_each_product_separately(): void
    {
        $po = PurchaseOrder::create([
            'po_number' => 'PO-MULTIPROD-' . rand(1000, 9999),
            'po_type' => 'STANDARD_REPLENISHMENT',
            'supplier_name' => 'Petron Bataan',
            'qty_ordered' => 60000,
            'request_status' => 'CONFIRMED',
            'status' => 'Pending',
        ]);
        PurchaseOrderItem::create([
            'purchase_order_id' => $po->id,
            'product' => 'Diesel',
            'quantity_ordered' => 40000,
        ]);
        PurchaseOrderItem::create([
            'purchase_order_id' => $po->id,
            'product' => 'Premium',
            'quantity_ordered' => 20000,
        ]);

        // SO with two product lines: Diesel 5,000 + Premium 3,000 (8,000 total)
        $order = Order::create([
            'account' => $this->client->name,
            'location' => 'Valenzuela',
            'so_number' => 'SO-FT-MP-' . uniqid(),
            'date' => now(),
            'qty_ordered' => 8000,
            'price' => 52.00,
            'status' => 'Active',
            'clearing_status' => 'Approved',
            'fulfillment_type' => 'FUEL_TRADE',
            'order_category' => 'CLIENT_ORDER',
        ]);
        OrderItem::create([
            'order_id' => $order->id,
            'product_type' => 'D',
            'qty' => 5000,
            'price' => 50.00,
        ]);
        OrderItem::create([
            'order_id' => $order->id,
            'product_type' => 'P',
            'qty' => 3000,
            'price' => 55.00,
        ]);

        $payload = [
            'receiving_date' => now()->addDays(2)->format('Y-m-d'),
            'driver_name' => 'Driver',
            'plate_number' => 'ABC-2222',
            'atl_type' => 'DITC_ATL',
            'atl_number' => 'ATL-MP-01',
            'drawdowns' => [
                ['purchase_order_id' => $po->id, 'product' => 'Diesel', 'quantity' => 5000],
                ['purchase_order_id' => $po->id, 'product' => 'Premium', 'quantity' => 3000],
            ],
        ];

        $response = $this->actingAs($this->purchasingUser)
            ->post(route('stock-orders.store-fuel-trade-po', $order->id), $payload);

        $response->assertRedirect(route('stock-orders.index'));
        $response->assertSessionHas('success');

        $delivery = PurchaseOrderDelivery::where('atl_number', 'ATL-MP-01')->first();
        $this->assertNotNull($delivery);

        // The ATL row keeps a summary: total liters across all products.
        $this->assertEquals(8000, $delivery->qty_to_receive);

        // Per-product quantities live on the allocations.
        $this->assertCount(2, $delivery->allocations);
        $drawn = $delivery->allocations->pluck('quantity', 'product')->all();
        $this->assertEquals(5000, $drawn['Diesel']);
        $this->assertEquals(3000, $drawn['Premium']);

        // Both products came off the same PO.
        $this->assertEquals(35000, $po->fresh()->getAvailableBalanceForProduct('Diesel'));
        $this->assertEquals(17000, $po->fresh()->getAvailableBalanceForProduct('Premium'));
    }

    public function test_multi_product_fuel_trade_rejects_wrong_product_mix(): void
    {
        $po = PurchaseOrder::create([
            'po_number' => 'PO-MPBAD-' . rand(1000, 9999),
            'po_type' => 'STANDARD_REPLENISHMENT',
            'supplier_name' => 'Petron Bataan',
            'qty_ordered' => 60000,
            'request_status' => 'CONFIRMED',
            'status' => 'Pending',
        ]);
        PurchaseOrderItem::create([
            'purchase_order_id' => $po->id,
            'product' => 'Diesel',
            'quantity_ordered' => 40000,
        ]);
        PurchaseOrderItem::create([
            'purchase_order_id' => $po->id,
            'product' => 'Premium',
            'quantity_ordered' => 20000,
        ]);

        $order = Order::create([
            'account' => $this->client->name,
            'location' => 'Valenzuela',
            'so_number' => 'SO-FT-MPBAD-' . uniqid(),
            'date' => now(),
            'qty_ordered' => 8000,
            'price' => 52.00,
            'status' => 'Active',
            'clearing_status' => 'Approved',
            'fulfillment_type' => 'FUEL_TRADE',
            'order_category' => 'CLIENT_ORDER',
        ]);
        OrderItem::create([
            'order_id' => $order->id,
            'product_type' => 'D',
            'qty' => 5000,
            'price' => 50.00,
        ]);
        OrderItem::create([
            'order_id' => $order->id,
            'product_type' => 'P',
            'qty' => 3000,
            'price' => 55.00,
        ]);

        // Total is right (8,000) but split across the wrong products.
        $payload = [
            'receiving_date' => now()->addDays(2)->format('Y-m-d'),
            'driver_name' => 'Driver',
            'plate_number' => 'ABC-3333',
            'atl_type' => 'DITC_ATL',
            'atl_number' => 'ATL-MPBAD-01',
            'drawdowns' => [
                ['purchase_order_id' => $po->id, 'product' => 'Diesel', 'quantity' => 8000],
            ],
        ];

        $response = $this->actingAs($this->purchasingUser)
            ->post(route('stock-orders.store-fuel-trade-po', $order->id), $payload);

        $response->assertSessionHasErrors();
        $this->assertNull(PurchaseOrderDelivery::where('atl_number', 'ATL-MPBAD-01')->first());
    }

    public function test_cannot_draw_a_product_the_sales_order_does_not_require(): void
    {
        $po = PurchaseOrder::create([
            'po_number' => 'PO-MPXTRA-' . rand(1000, 9999),
            'po_type' => 'STANDARD_REPLENISHMENT',
            'supplier_name' => 'Petron Bataan',
            'qty_ordered' => 30000,
            'request_status' => 'CONFIRMED',
            'status' => 'Pending',
        ]);
        PurchaseOrderItem::create([
            'purchase_order_id' => $po->id,
            'product' => 'Diesel',
            'quantity_ordered' => 20000,
        ]);
        PurchaseOrderItem::create([
            'purchase_order_id' => $po->id,
            'product' => 'Unleaded',
            'quantity_ordered' => 10000,
        ]);

        // SO requires Diesel only.
        $order = Order::create([
            'account' => $this->client->name,
            'location' => 'Valenzuela',
            'so_number' => 'SO-FT-MPX-' . uniqid(),
            'date' => now(),
            'qty_ordered' => 5000,
            'price' => 52.00,
            'status' => 'Active',
            'clearing_status' => 'Approved',
            'fulfillment_type' => 'FUEL_TRADE',
            'order_category' => 'CLIENT_ORDER',
        ]);
        OrderItem::create([
            'order_id' => $order->id,
            'product_type' => 'D',
            'qty' => 5000,
            'price' => 50.00,
        ]);

        // Total matches (5,000) but the product does not.
        $payload = [
            'receiving_date' => now()->addDays(2)->format('Y-m-d'),
            'driver_name' => 'Driver',
            'plate_number' => 'ABC-4444',
            'atl_type' => 'DITC_ATL',
            'atl_number' => 'ATL-MPX-01',
            'drawdowns' => [
                ['purchase_order_id' => $po->id, 'product' => 'Unleaded', 'quantity' => 5000],
            ],
        ];

        $response = $this->actingAs($this->purchasingUser)
            ->post(route('stock-orders.store-fuel-trade-po', $order->id), $payload);

        $response->assertSessionHasErrors();
        $this->assertNull(PurchaseOrderDelivery::where('atl_number', 'ATL-MPX-01')->first());
    }
}
