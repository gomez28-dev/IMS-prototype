<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Client;
use App\Models\Delivery;
use App\Models\Order;
use App\Models\StorageTank;
use App\Models\Warehouse;
use Tests\TestCase;

/**
 * Renders every page touched by the multi-product delivery work, including
 * LEGACY deliveries that have no delivery_items rows and no product_type.
 *
 * Production still holds pre-migration deliveries, so these views must not
 * assume a compartment breakdown exists. A crash here means a 500 on the
 * live site after deploy.
 */
class DeliveryLegacyRowRenderTest extends TestCase
{
    protected Admin $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = Admin::create([
            'username' => 'smoke_' . uniqid(),
            'password' => bcrypt('secret'),
            'name' => 'Smoke Admin',
            'role' => 'admin',
        ]);

        Client::first() ?? Client::create(['name' => 'Swift Logistics']);
    }

    /**
     * A delivery exactly as it exists on production today: header only,
     * no compartment lines, null product_type.
     */
    protected function legacyDelivery(): Delivery
    {
        $warehouse = Warehouse::first() ?? Warehouse::create(['name' => 'Valenzuela Depot']);
        $tank = StorageTank::first() ?? StorageTank::create([
            'warehouse_id' => $warehouse->id,
            'name' => 'Legacy Tank',
            'category' => 'storage',
            'max_capacity' => 20000,
            'is_active' => true,
        ]);

        $order = Order::create([
            'account' => 'Swift Logistics',
            'ordered_by' => 'Swift Logistics',
            'location' => $warehouse->name,
            'so_number' => 'SO-LEGACY-' . rand(1000, 9999),
            'date' => now(),
            'qty_ordered' => 5000,
            'price' => 52.00,
            'product' => 'Unleaded',
            'order_status' => 'APPROVED',
            'clearing_status' => 'Approved',
        ]);

        return Delivery::create([
            'order_id' => $order->id,
            'storage_tank_id' => $tank->id,
            'dr_number' => 'DR-LEGACY-' . rand(1000, 9999),
            'delivery_date' => now(),
            'qty_out' => 5000,
            'status' => 'PENDING',
        ]);
    }

    public function test_dashboard_renders(): void
    {
        $this->legacyDelivery();

        $this->actingAs($this->admin)->get(route('dashboard'))->assertOk();
    }

    public function test_module_one_order_deliveries_renders_legacy_rows(): void
    {
        $delivery = $this->legacyDelivery();

        $this->actingAs($this->admin)
            ->get(route('order.deliveries', $delivery->order_id))
            ->assertOk();
    }

    public function test_delivery_assignment_page_renders_legacy_rows(): void
    {
        $this->legacyDelivery();

        $this->actingAs($this->admin)->get(route('wetstock.deliveries.index'))->assertOk();
    }

    public function test_approvals_page_renders(): void
    {
        $this->actingAs($this->admin)->get(route('approvals.index'))->assertOk();
    }

    public function test_module_one_approvals_page_renders(): void
    {
        $this->actingAs($this->admin)->get(route('stock-orders.approvals'))->assertOk();
    }

    public function test_atl_queue_renders(): void
    {
        $this->actingAs($this->admin)->get(route('stock-orders.atls.index'))->assertOk();
    }

    public function test_stock_orders_pages_render(): void
    {
        $this->legacyDelivery();

        $this->actingAs($this->admin)->get(route('stock-orders.dashboard'))->assertOk();
        $this->actingAs($this->admin)->get(route('stock-orders.deliveries'))->assertOk();
        $this->actingAs($this->admin)->get(route('stock-orders.index'))->assertOk();
    }

    public function test_legacy_delivery_reports_a_summary_without_compartments(): void
    {
        $delivery = $this->legacyDelivery()->fresh('items');

        $this->assertCount(0, $delivery->items);
        $this->assertStringContainsString('5,000 L', $delivery->items_summary);
        $this->assertSame(0, $delivery->allocated_quantity);
        $this->assertSame(5000, $delivery->remaining_to_allocate);
    }
}