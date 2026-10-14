<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\Supplier;
use Tests\TestCase;

class PurchaseOrderActionColumnTest extends TestCase
{
    protected Admin $purchasing;

    protected Supplier $supplier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->purchasing = Admin::create([
            'username' => 'purchasing_' . uniqid(),
            'password' => bcrypt('secret'),
            'name' => 'Rica Esaga',
            'role' => 'purchasing',
        ]);

        $this->supplier = Supplier::create([
            'company_name' => 'Petron Bataan Refinery',
            'location' => 'Limay',
        ]);
    }

    protected function po(string $requestStatus, string $suffix = ''): PurchaseOrder
    {
        $po = PurchaseOrder::create([
            'po_number' => 'PO-ACT-'.$requestStatus.$suffix,
            'po_type' => 'STANDARD_REPLENISHMENT',
            'supplier_id' => $this->supplier->id,
            'supplier_name' => $this->supplier->company_name,
            'qty_ordered' => 10000,
            'total_amount' => 500000.00,
            'request_status' => $requestStatus,
            'status' => 'Pending',
            'prepared_by' => $this->purchasing->id,
        ]);

        PurchaseOrderItem::create([
            'purchase_order_id' => $po->id,
            'product' => 'Diesel',
            'quantity_ordered' => 10000,
            'unit_price' => 50.00,
        ]);

        return $po;
    }

    public function test_atl_processing_is_no_longer_started_from_the_list(): void
    {
        $this->po('REQUESTED');

        $response = $this->actingAs($this->purchasing)
            ->get(route('stock-orders.purchase-orders.index'));

        $response->assertStatus(200);
        $response->assertDontSee('Process / ATL');
        // The old Prepare form must not be linked from the list either.
        $response->assertDontSee(route('stock-orders.edit-request', 1), false);
    }

    /**
     * @dataProvider allStatuses
     */
    public function test_every_row_offers_a_view_link_regardless_of_status(string $requestStatus): void
    {
        $po = $this->po($requestStatus);

        $response = $this->actingAs($this->purchasing)
            ->get(route('stock-orders.purchase-orders.index'));

        $response->assertStatus(200);
        $response->assertSee(route('stock-orders.purchase-orders.show', $po->id), false);
    }

    /**
     * @return array<string, array<int, string>>
     */
    public static function allStatuses(): array
    {
        return [
            'requested' => ['REQUESTED'],
            'pending vp approval' => ['RECEIVED'],
            'confirmed' => ['CONFIRMED'],
            'for delivery' => ['FOR_DELIVERY'],
            'completed' => ['COMPLETED'],
        ];
    }

    /**
     * @dataProvider unapprovedStatuses
     */
    public function test_po_pdf_is_not_linked_before_approval(string $requestStatus): void
    {
        $po = $this->po($requestStatus);

        $this->actingAs($this->purchasing)
            ->get(route('stock-orders.purchase-orders.index'))
            ->assertDontSee(route('stock-orders.po-pdf', $po->id), false);
    }

    /**
     * @return array<string, array<int, string>>
     */
    public static function unapprovedStatuses(): array
    {
        return [
            'requested' => ['REQUESTED'],
            'pending vp approval' => ['RECEIVED'],
        ];
    }

    /**
     * @dataProvider approvedStatuses
     */
    public function test_po_pdf_is_linked_once_approved(string $requestStatus): void
    {
        $po = $this->po($requestStatus);

        $this->actingAs($this->purchasing)
            ->get(route('stock-orders.purchase-orders.index'))
            ->assertSee(route('stock-orders.po-pdf', $po->id), false);
    }

    /**
     * @return array<string, array<int, string>>
     */
    public static function approvedStatuses(): array
    {
        return [
            'confirmed' => ['CONFIRMED'],
            'for delivery' => ['FOR_DELIVERY'],
            'completed' => ['COMPLETED'],
        ];
    }

    public function test_view_link_is_available_to_roles_that_cannot_approve(): void
    {
        $po = $this->po('RECEIVED');

        $viewer = Admin::create([
            'username' => 'viewer_'.uniqid(),
            'password' => bcrypt('secret'),
            'name' => 'Audit Viewer',
            'role' => 'audit',
        ]);

        $this->actingAs($viewer)
            ->get(route('stock-orders.purchase-orders.index'))
            ->assertStatus(200)
            ->assertSee(route('stock-orders.purchase-orders.show', $po->id), false);
    }

    public function test_mark_picked_up_is_no_longer_offered_from_the_purchase_order_list(): void
    {
        $this->po('FOR_DELIVERY', '-LIFT');

        $response = $this->actingAs($this->purchasing)
            ->get(route('stock-orders.purchase-orders.index'));

        $response->assertStatus(200);
        $response->assertDontSee('Mark Picked Up');
        // The lift modal came out with the button.
        $response->assertDontSee('confirmLiftModal', false);
        $response->assertDontSee('Confirm Refinery Pick Up / Lift');
    }

    public function test_an_approved_row_offers_print_and_view_but_no_edit(): void
    {
        $po = $this->po('CONFIRMED');

        $response = $this->actingAs($this->purchasing)
            ->get(route('stock-orders.purchase-orders.index'));

        $response->assertStatus(200);

        // An approved PO can no longer be edited, so Print and View are the row's
        // two actions. Offering Edit here was a link to a 403.
        $response->assertSee(route('stock-orders.po-pdf', $po->id), false);
        $response->assertSee(route('stock-orders.purchase-orders.show', $po->id), false);
        $response->assertDontSee(route('stock-orders.edit-supplier-po', $po->id), false);
        $response->assertSee('> Print', false);
        $response->assertSee('> View', false);
        $response->assertDontSee('> Edit<', false);

        // Work that used to clutter the list now lives on the detail page.
        // Note: the Approvals route itself still appears in the sidebar, so the
        // in-row button is what is checked here.
        $response->assertDontSee('Review for Approval');
        $response->assertDontSee(route('stock-orders.wet-stock-requests.show', $po->id), false);
        $response->assertDontSee('ATL PDF');
    }

    public function test_an_unapproved_row_offers_edit_and_view_but_no_print(): void
    {
        $po = $this->po('RECEIVED');

        $response = $this->actingAs($this->purchasing)
            ->get(route('stock-orders.purchase-orders.index'));

        $response->assertStatus(200);

        // Before approval there is nothing signed to print, and the PO is still
        // the VP's to send back, so it stays editable.
        $response->assertSee(route('stock-orders.edit-supplier-po', $po->id), false);
        $response->assertSee(route('stock-orders.purchase-orders.show', $po->id), false);
        $response->assertDontSee(route('stock-orders.po-pdf', $po->id), false);
        $response->assertSee('> Edit', false);
        $response->assertSee('> View', false);
    }

    public function test_the_pen_action_is_hidden_from_roles_that_cannot_edit(): void
    {
        $po = $this->po('CONFIRMED');

        $viewer = Admin::create([
            'username' => 'viewer_'.uniqid(),
            'password' => bcrypt('secret'),
            'name' => 'Audit Viewer',
            'role' => 'audit',
        ]);

        $this->actingAs($viewer)
            ->get(route('stock-orders.purchase-orders.index'))
            ->assertStatus(200)
            ->assertDontSee(route('stock-orders.edit-supplier-po', $po->id), false)
            // Print and View remain.
            ->assertSee(route('stock-orders.po-pdf', $po->id), false)
            ->assertSee(route('stock-orders.purchase-orders.show', $po->id), false);
    }
}