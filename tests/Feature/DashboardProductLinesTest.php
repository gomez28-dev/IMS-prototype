<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PurchaseOrder;
use Tests\TestCase;

class DashboardProductLinesTest extends TestCase
{
    protected Admin $sales;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sales = Admin::create([
            'username' => 'sales_' . uniqid(),
            'password' => bcrypt('secret'),
            'name' => 'Sales Agent',
            'role' => 'sales',
        ]);
    }

    public function test_dashboard_renders_an_order_with_several_product_lines(): void
    {
        $order = $this->order('FT-MULTI');
        OrderItem::create(['order_id' => $order->id, 'product_type' => 'D', 'qty' => 20000, 'price' => 50.00]);
        OrderItem::create(['order_id' => $order->id, 'product_type' => 'P', 'qty' => 10000, 'price' => 55.00]);

        $response = $this->actingAs($this->sales)->get(route('dashboard', ['tab' => 'approved']));

        $response->assertStatus(200);
        // Both product chips are shown on the row.
        $response->assertSee($order->so_number);
        $response->assertSee('2 products');
    }

    public function test_dashboard_renders_a_legacy_order_with_no_product_lines(): void
    {
        // Orders created before multi-product lines exist still have to render,
        // falling back to the order's own totals.
        $order = $this->order('FT-LEGACY');

        $this->assertEquals(0, $order->items()->count());

        $response = $this->actingAs($this->sales)->get(route('dashboard', ['tab' => 'approved']));

        $response->assertStatus(200);
        $response->assertSee($order->so_number);
    }

    public function test_dashboard_works_for_a_fuel_trade_order_with_a_linked_purchase_order(): void
    {
        $order = $this->order('FT-LINKED');
        OrderItem::create(['order_id' => $order->id, 'product_type' => 'D', 'qty' => 5000, 'price' => 52.00]);

        $po = PurchaseOrder::create([
            'po_number' => 'PO-DASH-' . rand(1000, 9999),
            'po_type' => 'FUEL_TRADE',
            'supplier_name' => 'Petron Bataan',
            'qty_ordered' => 5000,
            'request_status' => 'CONFIRMED',
        ]);
        $order->update(['linked_purchase_order_id' => $po->id]);

        $response = $this->actingAs($this->sales)->get(route('dashboard', ['tab' => 'approved']));

        $response->assertStatus(200);
        $response->assertSee($order->so_number);
    }

    private function order(string $soNumber): Order
    {
        return Order::create([
            'account' => 'Apex Logistics',
            'location' => 'Valenzuela',
            'so_number' => $soNumber . '-' . uniqid(),
            'date' => now(),
            'qty_ordered' => 1000,
            'price' => 50.00,
            'status' => 'Active',
            'clearing_status' => 'Approved',
            'fulfillment_type' => 'FUEL_TRADE',
            'order_category' => 'CLIENT_ORDER',
        ]);
    }
}
