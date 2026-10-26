<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Order;
use App\Models\OrderItem;
use Tests\TestCase;

/**
 * Date Needed leaves the Sales Order entirely: the supervisor ruled it lives
 * with the delivery (the ATL form's Authorized Pick Up Date is the only
 * source for that date), so the SO form stops asking and nothing on the SO
 * flows reads a date from it anymore. The list also stops being a launchpad -
 * Create ATL moves behind ATL Details, which is where it already lives - and
 * the product lines stand alone without a cumulative Total row.
 */
class SoDateNeededRemovedTest extends TestCase
{
    protected Admin $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = Admin::create([
            'username' => 'admin_'.uniqid(),
            'password' => bcrypt('secret'),
            'name' => 'System Administrator',
            'role' => 'admin',
        ]);
    }

    protected function order(array $overrides = []): Order
    {
        $order = Order::create(array_merge([
            'account' => 'Apex Logistics Corp',
            'location' => 'Valenzuela',
            'so_number' => (string) rand(100000, 999999),
            'date' => now(),
            'status' => 'Active',
            'qty_ordered' => 1500,
            'clearing_status' => 'Approved',
            'fulfillment_type' => Order::FULFILLMENT_FUEL_TRADE,
            'order_category' => Order::CATEGORY_CLIENT_ORDER,
        ], $overrides));

        OrderItem::create(['order_id' => $order->id, 'product_type' => 'D', 'qty' => 1000, 'price' => 50]);
        OrderItem::create(['order_id' => $order->id, 'product_type' => 'U', 'qty' => 500, 'price' => 50]);

        return $order->fresh();
    }

    protected function listHtml(): string
    {
        return $this->actingAs($this->admin)
            ->get(route('stock-orders.sales-orders.index'))
            ->assertOk()
            ->getContent();
    }

    // ---- The field itself ---------------------------------------------------

    public function test_the_create_form_no_longer_asks_for_a_date_needed(): void
    {
        $html = $this->actingAs($this->admin)
            ->get(route('order.create'))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('Date Needed', $html);
    }

    public function test_the_edit_form_no_longer_prefills_a_date_needed(): void
    {
        $order = $this->order(['date_needed' => now()->addDays(7)->toDateString()]);

        $html = $this->actingAs($this->admin)
            ->get(route('order.edit', $order->id))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('Date Needed', $html);
        $this->assertStringNotContainsString('name="date_needed"', $html);
    }

    public function test_a_saved_date_needed_does_not_resurface_on_the_edit_form(): void
    {
        $order = $this->order(['date_needed' => '2026-09-15']);

        $html = $this->actingAs($this->admin)
            ->get(route('order.edit', $order->id))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('2026-09-15', $html);
    }

    // ---- The list -----------------------------------------------------------

    public function test_a_cleared_order_is_offered_atl_details_but_not_create_atl(): void
    {
        $this->order();

        $html = $this->listHtml();

        $this->assertStringContainsString('ATL Details', $html);
        $this->assertStringNotContainsString('Create ATL', $html, 'Create ATL now lives inside the ATL Details page.');
    }

    public function test_product_lines_report_each_product_without_a_total_row(): void
    {
        $this->order();

        $html = $this->listHtml();

        // The per-product lines survive...
        $this->assertStringContainsString('Diesel', $html);
        $this->assertStringContainsString('1,000', $html);
        $this->assertStringContainsString('500', $html);
        // ...but the cumulative Total row under them is gone.
        $this->assertStringNotContainsString('1,500 L', $html);
    }

    // ---- The decoupling -----------------------------------------------------

    public function test_the_atl_form_keeps_the_pick_up_date_and_names_nothing_date_needed(): void
    {
        $order = $this->order();

        $html = $this->actingAs($this->admin)
            ->get(route('stock-orders.create-fuel-trade-po', $order->id))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Authorized Pick Up Date', $html);
        $this->assertStringNotContainsString('Date Needed', $html);
    }
}
