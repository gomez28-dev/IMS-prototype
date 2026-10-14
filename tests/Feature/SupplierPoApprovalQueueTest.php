<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use Tests\TestCase;

class SupplierPoApprovalQueueTest extends TestCase
{
    protected Admin $purchasing;

    protected Admin $vp;

    protected function setUp(): void
    {
        parent::setUp();

        $this->purchasing = Admin::create([
            'username' => 'purchasing_' . uniqid(),
            'password' => bcrypt('secret'),
            'name' => 'Rica Esaga',
            'role' => 'purchasing',
        ]);

        $this->vp = Admin::create([
            'username' => 'vp_' . uniqid(),
            'password' => bcrypt('secret'),
            'name' => 'Bernice Nikki Lee',
            'role' => 'vp',
        ]);
    }

    protected function createRequestedSupplierPo(string $poNumber): PurchaseOrder
    {
        $supplier = Supplier::create([
            'company_name' => 'Agieko Fuel Trading',
            'location' => 'Limay Terminal',
        ]);

        $this->actingAs($this->purchasing)->post(route('stock-orders.store-supplier-po'), [
            'po_number' => $poNumber,
            'supplier_id' => $supplier->id,
            'po_type' => 'STANDARD_REPLENISHMENT',
            'items' => [['product' => 'Diesel', 'quantity' => 1000, 'unit_price' => 50]],
        ])->assertSessionHas('success');

        return PurchaseOrder::where('po_number', $poNumber)->firstOrFail();
    }

    public function test_new_supplier_po_is_saved_pending_vp_approval(): void
    {
        $po = $this->createRequestedSupplierPo('PO-Q-'.rand(1000, 9999));

        // RECEIVED is this codebase's "waiting on the VP" state, which is what
        // the Approvals queue filters on.
        $this->assertEquals('RECEIVED', $po->request_status);
    }

    public function test_new_supplier_po_is_not_self_approved_by_its_creator(): void
    {
        $po = $this->createRequestedSupplierPo('PO-Q-'.rand(1000, 9999));

        // Creating a PO must never approve it.
        $this->assertNull($po->approved_by);
        $this->assertNull($po->approved_at);
        $this->assertEquals($this->purchasing->id, $po->requested_by);
    }

    public function test_new_supplier_po_appears_in_the_vp_approval_queue(): void
    {
        $po = $this->createRequestedSupplierPo('PO-QUEUE-VISIBLE');

        $this->assertEquals('RECEIVED', $po->request_status);

        $response = $this->actingAs($this->vp)->get(route('stock-orders.approvals'));

        $response->assertStatus(200);
        $response->assertSee('PO-QUEUE-VISIBLE');
    }

    public function test_approved_supplier_po_leaves_the_queue(): void
    {
        $po = $this->createRequestedSupplierPo('PO-QUEUE-GONE');

        $this->actingAs($this->vp)
            ->post(route('stock-orders.approve', $po->id))
            ->assertSessionHas('success');

        $this->assertEquals('CONFIRMED', $po->fresh()->request_status);
        $this->assertEquals($this->vp->id, $po->fresh()->approved_by);

        // The first GET burns the flash banner, which echoes the PO number.
        $this->actingAs($this->vp)->get(route('stock-orders.approvals'));
        $this->actingAs($this->vp)
            ->get(route('stock-orders.approvals'))
            ->assertDontSee('PO-QUEUE-GONE');
    }

    public function test_rejected_supplier_po_returns_to_purchasing(): void
    {
        $po = $this->createRequestedSupplierPo('PO-QUEUE-REJECT');

        $this->actingAs($this->vp)->post(route('stock-orders.reject', $po->id), [
            'rejection_reason' => 'Volume exceeds the approved allocation.',
        ])->assertSessionHas('success');

        $this->assertEquals('REQUESTED', $po->fresh()->request_status);

        // The first GET burns the flash banner, which echoes the PO number.
        $this->actingAs($this->vp)->get(route('stock-orders.approvals'));
        $this->actingAs($this->vp)
            ->get(route('stock-orders.approvals'))
            ->assertDontSee('PO-QUEUE-REJECT');
    }

    public function test_purchasing_can_see_the_approval_queue_but_not_approve(): void
    {
        // The queue is now visible to anyone who can reach Module 3, so
        // Purchasing can see what is waiting. Ruling on it is still the VP's:
        // test_purchasing_cannot_approve_a_supplier_po below pins that.
        $this->actingAs($this->purchasing)
            ->get(route('stock-orders.approvals'))
            ->assertOk();
    }

    public function test_purchasing_cannot_approve_a_supplier_po(): void
    {
        $po = $this->createRequestedSupplierPo('PO-QUEUE-SELFAPPROVE');

        $this->actingAs($this->purchasing)
            ->post(route('stock-orders.approve', $po->id))
            ->assertStatus(403);

        $this->assertEquals('RECEIVED', $po->fresh()->request_status);
    }
}