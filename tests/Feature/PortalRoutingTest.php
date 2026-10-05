<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\PurchaseOrder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class PortalRoutingTest extends TestCase
{
    use DatabaseTransactions;

    public function test_guest_is_redirected_to_login_from_portal(): void
    {
        $response = $this->get(route('portal'));
        $response->assertRedirect(route('login'));
    }

    public function test_admin_sees_all_three_portals_on_welcome_screen(): void
    {
        $admin = Admin::create([
            'username' => 'admin_' . uniqid(),
            'password' => bcrypt('password'),
            'name' => 'Admin User',
            'role' => 'admin',
        ]);

        $response = $this->actingAs($admin)->get(route('portal'));
        $response->assertStatus(200);
        $response->assertSee('Sales Documentation');
        $response->assertSee('Wet Stock');
        $response->assertSee('Stock Orders');
    }

    public function test_purchasing_user_sees_stock_orders_portal(): void
    {
        $purchasing = Admin::create([
            'username' => 'purchasing_' . uniqid(),
            'password' => bcrypt('password'),
            'name' => 'Rica Esaga',
            'role' => 'purchasing',
        ]);

        $response = $this->actingAs($purchasing)->get(route('portal'));
        $response->assertStatus(200);
        $response->assertSee('Stock Orders');
    }

    public function test_unauthorized_role_cannot_access_stock_orders_routes(): void
    {
        $sales = Admin::create([
            'username' => 'sales_' . uniqid(),
            'password' => bcrypt('password'),
            'name' => 'Sales User',
            'role' => 'sales',
        ]);

        $response = $this->actingAs($sales)->get(route('stock-orders.index'));
        $response->assertStatus(403);
    }

    public function test_stock_orders_approvals_badge_only_counts_received_orders(): void
    {
        $vp = Admin::create([
            'username' => 'vp_' . uniqid(),
            'password' => bcrypt('password'),
            'name' => 'Bernice Nikki Lee',
            'role' => 'vp',
        ]);

        // Create an order in FOR_DELIVERY (not pending VP review)
        PurchaseOrder::create([
            'po_number' => 'PO-TEST-FOR-DELIVERY',
            'po_type' => 'STANDARD_REPLENISHMENT',
            'qty_ordered' => 5000,
            'request_status' => 'FOR_DELIVERY',
            'status' => 'Pending',
        ]);

        // Badge reflects the live RECEIVED count (no hardcoded "empty DB" assumption)
        $existing = PurchaseOrder::where('request_status', 'RECEIVED')->count();

        // Exactly one tab-badge under "ms-auto bg-danger" when 0, none when 0? assert properly below
        $response = $this->actingAs($vp)->get(route('stock-orders.approvals'));
        $response->assertStatus(200);

        if ($existing === 0) {
            $response->assertDontSee('<span class="badge rounded-pill ms-auto bg-danger text-white">', false);
        }

        // Now create an order in RECEIVED status (awaiting VP review)
        PurchaseOrder::create([
            'po_number' => 'PO-TEST-RECEIVED',
            'po_type' => 'STANDARD_REPLENISHMENT',
            'qty_ordered' => 12000,
            'request_status' => 'RECEIVED',
            'status' => 'Pending',
        ]);

        // Badge should show exactly existing+1
        $response2 = $this->actingAs($vp)->get(route('stock-orders.approvals'));
        $response2->assertStatus(200);
        $response2->assertSee('<span class="badge rounded-pill ms-auto bg-danger text-white">' . ($existing + 1) . '</span>', false);
    }
}
