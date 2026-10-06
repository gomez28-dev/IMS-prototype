<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Order;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderDelivery;
use Tests\TestCase;

class AtlApprovalQueueTest extends TestCase
{
    protected Admin $vp;
    protected Admin $purchasing;

    protected function setUp(): void
    {
        parent::setUp();

        $this->vp = Admin::create([
            'username' => 'vp_' . uniqid(),
            'password' => bcrypt('secret'),
            'name' => 'Bernice Nikki Lee',
            'role' => 'vp',
        ]);

        $this->purchasing = Admin::create([
            'username' => 'purchasing_' . uniqid(),
            'password' => bcrypt('secret'),
            'name' => 'Rica Esaga',
            'role' => 'purchasing',
        ]);
    }

    public function test_approval_queue_shows_both_po_and_atl_tabs(): void
    {
        $po = PurchaseOrder::create([
            'po_number' => 'PO-Q-1', 'po_type' => 'STANDARD_REPLENISHMENT',
            'supplier_name' => 'Petron', 'qty_ordered' => 1000, 'request_status' => 'RECEIVED',
        ]);

        $atl = $this->atl('ATL-Q-1', PurchaseOrderDelivery::APPROVAL_FOR_APPROVAL, 'Approved');

        $response = $this->actingAs($this->vp)->get(route('stock-orders.approvals'));

        $response->assertStatus(200);
        $response->assertSee('PO Approvals');
        $response->assertSee('ATL Approvals');
        // PO tab is the default view.
        $response->assertSee($po->po_number);
        $response->assertDontSee('ATL-Q-1');
    }

    public function test_atl_tab_lists_only_atls_waiting_for_approval(): void
    {
        $this->atl('ATL-WAITING', PurchaseOrderDelivery::APPROVAL_FOR_APPROVAL, 'Approved');
        $this->atl('ATL-DRAFT', PurchaseOrderDelivery::APPROVAL_DRAFT, 'Approved');
        $this->atl('ATL-DONE', PurchaseOrderDelivery::APPROVAL_APPROVED, 'Approved');

        $response = $this->actingAs($this->vp)
            ->get(route('stock-orders.approvals', ['tab' => 'atls']));

        $response->assertStatus(200);
        $response->assertSee('ATL-WAITING');
        $response->assertDontSee('ATL-DRAFT');
        $response->assertDontSee('ATL-DONE');
    }

    public function test_atl_with_unapproved_clearance_cannot_reach_the_queue(): void
    {
        // Defence in depth: the issuance gate should stop this, but if such a
        // row ever existed it must not be offered for approval.
        $this->atl('ATL-UNCLEARED', PurchaseOrderDelivery::APPROVAL_FOR_APPROVAL, 'Pending');

        $response = $this->actingAs($this->vp)
            ->get(route('stock-orders.approvals', ['tab' => 'atls']));

        $response->assertStatus(200);
        $response->assertDontSee('ATL-UNCLEARED');
    }

    public function test_vp_can_approve_an_atl(): void
    {
        $atl = $this->atl('ATL-APPROVE-ME', PurchaseOrderDelivery::APPROVAL_FOR_APPROVAL, 'Approved');

        $response = $this->actingAs($this->vp)
            ->post(route('stock-orders.atl-approve', $atl->id));

        $response->assertSessionHas('success');

        $atl->refresh();
        $this->assertEquals(PurchaseOrderDelivery::APPROVAL_APPROVED, $atl->approval_status);
        $this->assertEquals($this->vp->id, $atl->approved_by);
        $this->assertNotNull($atl->approved_at);
    }

    public function test_vp_can_reject_an_atl(): void
    {
        $atl = $this->atl('ATL-REJECT-ME', PurchaseOrderDelivery::APPROVAL_FOR_APPROVAL, 'Approved');

        $response = $this->actingAs($this->vp)
            ->post(route('stock-orders.atl-reject', $atl->id), [
                'rejection_reason' => 'Quantity does not match the sales order',
            ]);

        $response->assertSessionHas('success');

        $atl->refresh();
        $this->assertEquals(PurchaseOrderDelivery::APPROVAL_REJECTED, $atl->approval_status);
    }

    public function test_purchasing_cannot_approve_or_reject_atls(): void
    {
        $atl = $this->atl('ATL-NOT-YOURS', PurchaseOrderDelivery::APPROVAL_FOR_APPROVAL, 'Approved');

        $this->actingAs($this->purchasing)
            ->post(route('stock-orders.atl-approve', $atl->id))
            ->assertForbidden();

        $this->actingAs($this->purchasing)
            ->post(route('stock-orders.atl-reject', $atl->id))
            ->assertForbidden();

        $atl->refresh();
        $this->assertEquals(PurchaseOrderDelivery::APPROVAL_FOR_APPROVAL, $atl->approval_status);
    }

    public function test_only_atls_awaiting_approval_can_be_approved(): void
    {
        $alreadyApproved = $this->atl('ATL-ALREADY', PurchaseOrderDelivery::APPROVAL_APPROVED, 'Approved');

        $this->actingAs($this->vp)
            ->post(route('stock-orders.atl-approve', $alreadyApproved->id))
            ->assertSessionHas('error');

        $alreadyApproved->refresh();
        $this->assertEquals(PurchaseOrderDelivery::APPROVAL_APPROVED, $alreadyApproved->approval_status);
    }

    public function test_client_provided_atl_is_not_offered_for_approval(): void
    {
        $this->atl('ATL-CLIENT-Q', PurchaseOrderDelivery::APPROVAL_FOR_APPROVAL, 'Approved', true);

        $response = $this->actingAs($this->vp)
            ->get(route('stock-orders.approvals', ['tab' => 'atls']));

        $response->assertStatus(200);
        $response->assertDontSee('ATL-CLIENT-Q');
    }

    public function test_atl_pdf_is_blocked_for_client_provided_and_rejected_atls(): void
    {
        $client = $this->atl('ATL-CLIENT-PDF', PurchaseOrderDelivery::APPROVAL_DRAFT, 'Approved', true);
        $rejected = $this->atl('ATL-REJECT-PDF', PurchaseOrderDelivery::APPROVAL_REJECTED, 'Approved');

        $this->actingAs($this->vp)
            ->get(route('stock-orders.pdf', $client->id))
            ->assertForbidden();

        $this->actingAs($this->vp)
            ->get(route('stock-orders.pdf', $rejected->id))
            ->assertForbidden();

        // An approved Doyen-issued ATL still downloads.
        $approved = $this->atl('ATL-OK-PDF', PurchaseOrderDelivery::APPROVAL_APPROVED, 'Approved');
        $this->actingAs($this->vp)
            ->get(route('stock-orders.pdf', $approved->id))
            ->assertStatus(200);
    }

    private function atl(
        string $atlNumber,
        string $approvalStatus,
        string $clearance,
        bool $clientProvided = false
    ): PurchaseOrderDelivery {
        $order = Order::create([
            'account' => 'Apex Logistics',
            'location' => 'Valenzuela',
            'so_number' => 'SO-' . $atlNumber . '-' . uniqid(),
            'date' => now(),
            'qty_ordered' => 1000,
            'price' => 50.00,
            'status' => 'Active',
            'clearing_status' => $clearance,
            'fulfillment_type' => 'FUEL_TRADE',
            'order_category' => 'CLIENT_ORDER',
        ]);

        $po = PurchaseOrder::create([
            'po_number' => 'PO-' . $atlNumber,
            'po_type' => 'FUEL_TRADE',
            'supplier_name' => 'Petron Bataan Refinery',
            'qty_ordered' => 10000,
            'request_status' => 'CONFIRMED',
        ]);

        return PurchaseOrderDelivery::create([
            'purchase_order_id' => $po->id,
            'order_id' => $order->id,
            'so_number' => $order->so_number,
            'atl_number' => $clientProvided ? null : $atlNumber,
            'client_atl_number' => $clientProvided ? $atlNumber : null,
            'atl_category' => PurchaseOrderDelivery::CATEGORY_FUEL_TRADE,
            'atl_source' => $clientProvided
                ? PurchaseOrderDelivery::SOURCE_CLIENT_PROVIDED
                : PurchaseOrderDelivery::SOURCE_DOYEN_ISSUED,
            'approval_status' => $approvalStatus,
            'lift_status' => PurchaseOrderDelivery::LIFT_UNLIFTED,
            'issued_at' => now(),
            'product' => 'Diesel',
            'qty_to_receive' => 1000,
            'receiving_date' => now()->addDay(),
            'status' => 'Pending',
            'atl_type' => $clientProvided ? 'CLIENT_ATL' : 'DITC_ATL',
            'driver_name' => 'Test Driver',
            'plate_number' => 'NBD-0001',
        ]);
    }
}
