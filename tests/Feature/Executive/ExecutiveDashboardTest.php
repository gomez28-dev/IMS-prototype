<?php

namespace Tests\Feature\Executive;

use App\Models\Admin;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class ExecutiveDashboardTest extends TestCase
{
    use DatabaseTransactions;

    public function test_guest_is_sent_to_login(): void
    {
        $this->get(route('executive.dashboard'))->assertRedirect(route('login'));
    }

    public function test_president_vp_and_admin_can_open_the_overview(): void
    {
        foreach (['viewer', 'vp', 'admin'] as $role) {
            $user = $this->admin($role);

            $this->actingAs($user)
                ->get(route('executive.dashboard'))
                ->assertStatus(200)
                ->assertSee('Executive Overview')
                ->assertSee('Needs attention')
                ->assertSee('Sales Documentation')
                ->assertSee('Wet Stock')
                ->assertSee('Stock Orders')
                ->assertSee('Logistics');
        }
    }

    public function test_operational_roles_cannot_open_the_overview(): void
    {
        foreach (['sales', 'ops_mgr', 'ops_log', 'purchasing', 'accounting'] as $role) {
            $this->actingAs($this->admin($role))
                ->get(route('executive.dashboard'))
                ->assertStatus(403);
        }
    }

    public function test_the_overview_only_links_to_screens_the_role_can_open(): void
    {
        $vp = $this->admin('vp');

        // The VP cannot open the Module 1 approval queue, so that item is absent
        // rather than a link that answers 403.
        $this->actingAs($vp)
            ->get(route('executive.dashboard'))
            ->assertStatus(200)
            ->assertDontSee('Order and delivery changes awaiting approval')
            ->assertSee('Purchase orders waiting on the VP');
    }

    public function test_login_lands_executive_roles_on_the_overview(): void
    {
        $vp = $this->admin('vp');

        $this->post('/login', [
            'username' => $vp->username,
            'password' => 'secret',
        ])->assertRedirect(route('executive.dashboard'));

        $this->assertAuthenticatedAs($vp->fresh());
    }

    public function test_login_still_lands_everyone_else_on_the_portal_picker(): void
    {
        $sales = $this->admin('sales');

        $this->post('/login', [
            'username' => $sales->username,
            'password' => 'secret',
        ])->assertRedirect(route('portal'));

        $this->assertAuthenticatedAs($sales->fresh());
    }

    private function admin(string $role): Admin
    {
        return Admin::create([
            'username' => $role . '_' . uniqid(),
            'password' => bcrypt('secret'),
            'name' => ucfirst($role),
            'role' => $role,
        ]);
    }
}
