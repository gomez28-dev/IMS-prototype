<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderDelivery;
use App\Models\Supplier;
use Tests\TestCase;

/**
 * The supervisor said the approval queue should be visible to everyone with M2 or
 * M3 access, and that M2 users can reach Module 3 because M2 is connected to
 * stocks. Neither was true: the queue was VP/Admin only, and every M2 operations
 * role was locked out of Stock Orders entirely.
 *
 * Visibility is widened. Authority is not: Approve and Reject stay VP/Admin.
 */
class StockApprovalsVisibilityTest extends TestCase
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function admin(string $role, array $attributes = []): Admin
    {
        return Admin::create(array_merge([
            'username' => $role.'_'.uniqid(),
            'password' => bcrypt('secret'),
            'name' => 'Test '.$role,
            'role' => $role,
        ], $attributes));
    }

    protected function pendingPo(): PurchaseOrder
    {
        $supplier = Supplier::create([
            'company_name' => 'Visibility Refinery '.rand(1000, 9999),
            'location' => 'Limay Terminal',
        ]);

        return PurchaseOrder::create([
            'po_number' => 'PO-VIS-'.rand(1000, 9999),
            'po_type' => PurchaseOrder::TYPE_STANDARD_REPLENISHMENT,
            'supplier_id' => $supplier->id,
            'supplier_name' => $supplier->company_name,
            'po_date' => now()->toDateString(),
            'qty_ordered' => 10000,
            'request_status' => 'RECEIVED',
        ]);
    }

    // ---- M2 roles can see the queue ---------------------------------------

    /**
     * @return array<string, array{0: string}>
     */
    public static function m2Roles(): array
    {
        return [
            'ops_admin' => ['ops_admin'],
            'ops_mgr' => ['ops_mgr'],
            'ops_wh' => ['ops_wh'],
            'ops_log' => ['ops_log'],
            'hod' => ['hod'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('m2Roles')]
    public function test_an_m2_role_can_open_the_approval_queue(string $role): void
    {
        $this->actingAs($this->admin($role))
            ->get(route('stock-orders.approvals'))
            ->assertOk();
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('m2Roles')]
    public function test_an_m2_role_can_reach_module_3(string $role): void
    {
        $this->actingAs($this->admin($role))
            ->get(route('stock-orders.purchase-orders.index'))
            ->assertOk();
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('m2Roles')]
    public function test_the_queue_nav_entry_is_shown_to_an_m2_role(string $role): void
    {
        $html = $this->actingAs($this->admin($role))
            ->get(route('stock-orders.purchase-orders.index'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString(route('stock-orders.approvals'), $html);
    }

    // ---- M1-only roles still cannot ---------------------------------------

    public function test_an_m1_only_role_cannot_open_the_queue(): void
    {
        // Sales has no business with fuel movements.
        $this->actingAs($this->admin('sales'))
            ->get(route('stock-orders.approvals'))
            ->assertForbidden();
    }

    public function test_the_queue_nav_entry_is_hidden_from_an_m1_only_role(): void
    {
        $html = $this->actingAs($this->admin('sales'))
            ->get(route('wetstock.dashboard'))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString(route('stock-orders.approvals'), $html);
    }

    // ---- Visibility is not authority --------------------------------------

    #[\PHPUnit\Framework\Attributes\DataProvider('m2Roles')]
    public function test_an_m2_role_can_see_the_queue_but_not_approve(string $role): void
    {
        $po = $this->pendingPo();
        $user = $this->admin($role);

        $this->actingAs($user)
            ->get(route('stock-orders.approvals'))
            ->assertOk()
            ->assertSee($po->po_number);

        // Seeing a PO pending the VP does not let you approve it.
        $this->actingAs($user)
            ->post(route('stock-orders.approve', $po->id))
            ->assertForbidden();

        $this->assertNotEquals('CONFIRMED', $po->fresh()->request_status);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('m2Roles')]
    public function test_an_m2_role_cannot_approve_an_atl(string $role): void
    {
        $po = $this->pendingPo();
        $atl = PurchaseOrderDelivery::create([
            'purchase_order_id' => $po->id,
            'delivery_channel' => 'SUPPLIER_STOCKS_DELIVERY',
            'order_type' => 'DELIVERY',
            'atl_type' => 'DITC_ATL',
            'atl_number' => 'ATL-VIS-'.rand(1000, 9999),
            'product' => 'Diesel',
            'qty_to_receive' => 10000,
            'receiving_date' => now()->addDays(2),
            'status' => 'Active',
            'lift_status' => PurchaseOrderDelivery::LIFT_UNLIFTED,
            'approval_status' => PurchaseOrderDelivery::APPROVAL_FOR_APPROVAL,
        ]);

        $this->actingAs($this->admin($role))
            ->post(route('stock-orders.atl-approve', $atl->id))
            ->assertForbidden();

        $this->assertSame(
            PurchaseOrderDelivery::APPROVAL_FOR_APPROVAL,
            $atl->fresh()->approval_status
        );
    }

    // ---- The VP is unaffected ---------------------------------------------

    public function test_the_vp_still_approves(): void
    {
        $po = $this->pendingPo();

        $this->actingAs($this->admin('vp'))
            ->post(route('stock-orders.approve', $po->id))
            ->assertRedirect();

        $this->assertSame('CONFIRMED', $po->fresh()->request_status);
    }

    // ---- The badge tells the truth -----------------------------------------

    public function test_the_sidebar_badge_counts_pending_pos_and_atls_together(): void
    {
        $vp = $this->admin('vp');

        // One PO waiting, one ATL waiting.
        $po = $this->pendingPo();
        $atl = PurchaseOrderDelivery::create([
            'purchase_order_id' => $po->id,
            'delivery_channel' => 'SUPPLIER_DOYEN_PICKUP',
            'order_type' => 'PICK_UP',
            'atl_type' => 'DITC_ATL',
            'atl_number' => 'ATL-BADGE-'.rand(1000, 9999),
            'product' => 'Diesel',
            'qty_to_receive' => 5000,
            'receiving_date' => now()->format('Y-m-d'),
            'approval_status' => PurchaseOrderDelivery::APPROVAL_FOR_APPROVAL,
        ]);

        $html = $this->actingAs($vp)->get(route('stock-orders.purchase-orders.index'))->getContent();

        // The badge used to count pending POs only, so an ATL waiting on the same
        // VP was invisible from the sidebar: the number under-reported the queue
        // the link next to it opened.
        $this->assertStringContainsString(
            '>2<',
            $html,
            'Two items are waiting on the VP, so the badge should say 2.'
        );

        $this->assertStringContainsString($atl->atl_number, $this->queueHtml($vp));
    }

    public function test_the_badge_does_not_count_an_atl_the_vp_has_already_ruled_on(): void
    {
        $vp = $this->admin('vp');
        $po = $this->pendingPo();

        foreach ([
            PurchaseOrderDelivery::APPROVAL_APPROVED,
            PurchaseOrderDelivery::APPROVAL_REJECTED,
        ] as $status) {
            PurchaseOrderDelivery::create([
                'purchase_order_id' => $po->id,
                'delivery_channel' => 'SUPPLIER_DOYEN_PICKUP',
                'order_type' => 'PICK_UP',
                'atl_type' => 'DITC_ATL',
                'atl_number' => 'ATL-DONE-'.rand(1000, 9999),
                'product' => 'Diesel',
                'qty_to_receive' => 5000,
                'receiving_date' => now()->format('Y-m-d'),
                'approval_status' => $status,
            ]);
        }

        // A client-provided ATL is the client's own document; there is nothing on
        // it for the VP to approve, so it must not inflate the number.
        PurchaseOrderDelivery::create([
            'purchase_order_id' => $po->id,
            'delivery_channel' => 'SUPPLIER_DOYEN_PICKUP',
            'order_type' => 'PICK_UP',
            'atl_type' => 'CLIENT_ATL',
            'client_atl_number' => 'CLIENT-'.rand(1000, 9999),
            'product' => 'Diesel',
            'qty_to_receive' => 5000,
            'receiving_date' => now()->format('Y-m-d'),
            'atl_source' => PurchaseOrderDelivery::SOURCE_CLIENT_PROVIDED,
            'approval_status' => PurchaseOrderDelivery::APPROVAL_DRAFT,
        ]);

        $html = $this->actingAs($vp)->get(route('stock-orders.purchase-orders.index'))->getContent();

        $this->assertStringContainsString(
            '>1<',
            $html,
            'Only the one PO is still waiting, so the badge should say 1.'
        );
    }

    public function test_a_fuel_trade_placeholder_does_not_join_the_po_queue(): void
    {
        $vp = $this->admin('vp');
        $this->pendingPo();

        // The ATL flow raises one of these per sales order when its ATL names a PO
        // that does not exist yet. It is created unapproved, so it was landing in
        // the VP's PO queue as though someone had asked for it to be signed off.
        PurchaseOrder::create([
            'po_number' => 'FT-PLACEHOLDER-'.rand(1000, 9999),
            'po_type' => PurchaseOrder::TYPE_FUEL_TRADE,
            'supplier_name' => 'Placeholder Depot',
            'po_date' => now()->toDateString(),
            'qty_ordered' => 10000,
            'request_status' => 'RECEIVED',
        ]);

        $html = $this->actingAs($vp)->get(route('stock-orders.purchase-orders.index'))->getContent();

        $this->assertStringContainsString(
            '>1<',
            $html,
            'The placeholder is not the VP to approve, so only the real PO counts.'
        );
    }

    protected function queueHtml(Admin $vp): string
    {
        return $this->actingAs($vp)
            ->get(route('stock-orders.approvals', ['tab' => 'atls']))
            ->assertOk()
            ->getContent();
    }
}
