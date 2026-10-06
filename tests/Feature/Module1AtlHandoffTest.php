<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Order;
use Tests\TestCase;

class Module1AtlHandoffTest extends TestCase
{
    protected function fuelTradeOrder(string $clearance = 'Approved'): Order
    {
        return Order::create([
            'account' => 'Apex Logistics',
            'location' => 'Valenzuela',
            'so_number' => 'FT-HANDOFF-' . uniqid(),
            'date' => now(),
            'qty_ordered' => 5000,
            'price' => 50.00,
            'status' => 'Active',
            'clearing_status' => $clearance,
            'fulfillment_type' => 'FUEL_TRADE',
            'order_category' => 'CLIENT_ORDER',
        ]);
    }

    protected function user(string $role, string $label): Admin
    {
        return Admin::create([
            'username' => $role . '_' . uniqid(),
            'password' => bcrypt('secret'),
            'name' => $label,
            'role' => $role,
        ]);
    }

    public function test_module1_no_longer_offers_an_issue_atl_button(): void
    {
        $order = $this->fuelTradeOrder();
        $purchasing = $this->user('purchasing', 'Rica Esaga');

        $response = $this->actingAs($purchasing)->get(route('order.deliveries', $order->id));

        $response->assertStatus(200);
        // ATL issuance moved to Module 3, so Module 1 must not offer it.
        $response->assertDontSee('Issue ATL');
        $response->assertDontSee('stock-orders.create-fuel-trade-po', false);
    }

    public function test_purchasing_is_sent_to_module_3(): void
    {
        $order = $this->fuelTradeOrder();
        $purchasing = $this->user('purchasing', 'Rica Esaga');

        $response = $this->actingAs($purchasing)->get(route('order.deliveries', $order->id));

        $response->assertStatus(200);
        $response->assertSee('Open in Module 3', false);
        $response->assertSee(route('stock-orders.atls.show', $order->id), false);
    }

    public function test_admin_is_also_sent_to_module_3(): void
    {
        $order = $this->fuelTradeOrder();
        $admin = $this->user('admin', 'Portal Admin');

        $response = $this->actingAs($admin)->get(route('order.deliveries', $order->id));

        $response->assertStatus(200);
        $response->assertSee('Open in Module 3', false);
    }

    public function test_other_roles_are_told_atl_is_for_purchasing(): void
    {
        $order = $this->fuelTradeOrder();
        $sales = $this->user('sales', 'Sales Agent');

        $response = $this->actingAs($sales)->get(route('order.deliveries', $order->id));

        $response->assertStatus(200);
        $response->assertSee('For Purchasing input', false);
        $response->assertDontSee('Open in Module 3', false);
    }

    public function test_uncleared_orders_still_show_the_clearance_message(): void
    {
        $order = $this->fuelTradeOrder('Pending');
        $sales = $this->user('sales', 'Sales Agent');

        $response = $this->actingAs($sales)->get(route('order.deliveries', $order->id));

        $response->assertStatus(200);
        $response->assertSee('Awaiting Accounting Clearance');
    }
}
