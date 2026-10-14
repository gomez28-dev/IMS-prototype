<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\AtlAllocation;
use App\Models\Order;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderDelivery;
use App\Models\PurchaseOrderItem;
use App\Models\StockRequest;
use App\Models\StockRequestLine;
use App\Models\Supplier;
use App\Models\Warehouse;
use Tests\TestCase;

/**
 * The supervisor said all newly created POs and ATLs must go through the
 * approval queue. ATLs did not: one saved without "submit for approval" was a
 * DRAFT that never appeared, and the queue filtered out any ATL whose sales
 * order had not been cleared by Accounting, so a pending ATL was invisible to
 * the VP.
 *
 * Reconciled rather than replaced, so drafting before clearance keeps working:
 * the queue lists everything not yet approved and not yet rejected.
 */
class AtlApprovalQueueCoverageTest extends TestCase
{
    protected Admin $vp;

    protected Admin $purchasing;

    protected Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();

        $this->vp = Admin::create([
            'username' => 'vp_'.uniqid(),
            'password' => bcrypt('secret'),
            'name' => 'Lorraine Bamba',
            'role' => 'vp',
        ]);

        $this->purchasing = Admin::create([
            'username' => 'purchasing_'.uniqid(),
            'password' => bcrypt('secret'),
            'name' => 'Rica Esaga',
            'role' => 'purchasing',
        ]);

        $this->warehouse = Warehouse::create(['name' => 'Bataan Depot '.rand(1000, 9999)]);
    }

    protected function po(array $products = ['Diesel' => 100000], string $status = 'CONFIRMED'): PurchaseOrder
    {
        $supplier = Supplier::create([
            'company_name' => 'Queue Refinery '.rand(1000, 9999),
            'location' => 'Limay Terminal',
        ]);

        $po = PurchaseOrder::create([
            'po_number' => 'PO-QUEUE-'.rand(1000, 9999),
            'po_type' => PurchaseOrder::TYPE_STANDARD_REPLENISHMENT,
            'supplier_id' => $supplier->id,
            'supplier_name' => $supplier->company_name,
            'po_date' => now()->toDateString(),
            'qty_ordered' => array_sum($products),
            'request_status' => $status,
        ]);

        foreach ($products as $product => $qty) {
            PurchaseOrderItem::create([
                'purchase_order_id' => $po->id,
                'product' => $product,
                'quantity_ordered' => $qty,
            ]);
        }

        return $po;
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    protected function atl(
        PurchaseOrder $po,
        string $approvalStatus,
        ?Order $order = null,
        array $overrides = []
    ): PurchaseOrderDelivery {
        $atl = PurchaseOrderDelivery::create(array_merge([
            'purchase_order_id' => $po->id,
            'order_id' => $order?->id,
            'delivery_channel' => 'SUPPLIER_CLIENT_PICKUP',
            'order_type' => 'PICK_UP',
            'atl_type' => 'DITC_ATL',
            'atl_number' => 'ATL-Q-'.uniqid(),
            'product' => 'Diesel',
            'qty_to_receive' => 20000,
            'receiving_date' => now()->addDays(3),
            'status' => 'Active',
            'lift_status' => PurchaseOrderDelivery::LIFT_UNLIFTED,
            'approval_status' => $approvalStatus,
        ], $overrides));

        AtlAllocation::create([
            'purchase_order_delivery_id' => $atl->id,
            'purchase_order_id' => $po->id,
            'product' => 'Diesel',
            'quantity' => 20000,
        ]);

        return $atl;
    }

    protected function queueHtml(): string
    {
        return $this->actingAs($this->vp)
            ->get(route('stock-orders.approvals', ['tab' => 'atls']))
            ->assertOk()
            ->getContent();
    }

    // ---- What belongs in the queue ----------------------------------------

    public function test_a_submitted_atl_is_in_the_queue(): void
    {
        $atl = $this->atl($this->po(), PurchaseOrderDelivery::APPROVAL_FOR_APPROVAL);

        $this->assertStringContainsString($atl->atl_number, $this->queueHtml());
    }

    public function test_a_draft_atl_is_also_in_the_queue(): void
    {
        // The change the supervisor asked for: an ATL saved without ticking
        // "submit for approval" used to be a DRAFT that never reached the VP.
        $atl = $this->atl($this->po(), PurchaseOrderDelivery::APPROVAL_DRAFT);

        $this->assertStringContainsString($atl->atl_number, $this->queueHtml());
    }

    public function test_an_atl_on_an_uncleared_order_is_in_the_queue(): void
    {
        // The queue filtered these out on clearing_status, so a pending ATL was
        // invisible until Accounting cleared the order.
        $order = Order::create([
            'so_number' => 'SO-UNCLEARED-'.rand(100, 999),
            'account' => 'Apex Logistics Corp.',
            'date' => now(),
            'location' => 'Valenzuela',
            'qty_ordered' => 20000,
            'status' => 'Active',
            'clearing_status' => 'Hold',
            'fulfillment_type' => Order::FULFILLMENT_FUEL_TRADE,
            'order_category' => Order::CATEGORY_CLIENT_ORDER,
        ]);

        $atl = $this->atl($this->po(), PurchaseOrderDelivery::APPROVAL_FOR_APPROVAL, $order);

        $this->assertStringContainsString($atl->atl_number, $this->queueHtml());
    }

    public function test_an_atl_for_a_stock_request_is_in_the_queue(): void
    {
        $request = StockRequest::create([
            'request_number' => 'REQ-Q-'.rand(100, 999),
            'request_type' => StockRequest::TYPE_PICKUP,
            'warehouse_id' => $this->warehouse->id,
            'qty_requested' => 50000,
            'status' => StockRequest::STATUS_REQUESTED,
            'requested_by' => $this->purchasing->id,
        ]);
        StockRequestLine::create([
            'stock_request_id' => $request->id,
            'product' => 'Diesel',
            'qty_requested' => 50000,
        ]);

        $atl = PurchaseOrderDelivery::create([
            'purchase_order_id' => $this->po()->id,
            'request_id' => $request->id,
            'delivery_channel' => 'SUPPLIER_STOCKS_DELIVERY',
            'order_type' => 'DELIVERY',
            'atl_type' => 'DITC_ATL',
            'atl_number' => 'ATL-REQ-Q-'.rand(1000, 9999),
            'product' => 'Diesel',
            'qty_to_receive' => 20000,
            'receiving_date' => now()->addDays(3),
            'status' => 'Active',
            'lift_status' => PurchaseOrderDelivery::LIFT_UNLIFTED,
            'approval_status' => PurchaseOrderDelivery::APPROVAL_FOR_APPROVAL,
        ]);

        $this->assertStringContainsString($atl->atl_number, $this->queueHtml());
    }

    // ---- What does not ----------------------------------------------------

    public function test_an_approved_atl_leaves_the_queue(): void
    {
        $atl = $this->atl($this->po(), PurchaseOrderDelivery::APPROVAL_APPROVED);

        $this->assertStringNotContainsString($atl->atl_number, $this->queueHtml());
    }

    public function test_a_rejected_atl_leaves_the_queue(): void
    {
        $atl = $this->atl($this->po(), PurchaseOrderDelivery::APPROVAL_REJECTED);

        $this->assertStringNotContainsString($atl->atl_number, $this->queueHtml());
    }

    public function test_a_client_provided_atl_is_not_queued(): void
    {
        // The client's own document: the system records it, it does not issue
        // it, so there is nothing for the VP to approve.
        $atl = $this->atl(
            $this->po(),
            PurchaseOrderDelivery::APPROVAL_FOR_APPROVAL,
            null,
            ['atl_source' => PurchaseOrderDelivery::SOURCE_CLIENT_PROVIDED]
        );

        $this->assertStringNotContainsString($atl->atl_number, $this->queueHtml());
    }

    // ---- The PO tab is unaffected -----------------------------------------

    public function test_a_po_waiting_on_the_vp_is_still_queued(): void
    {
        $po = $this->po(['Diesel' => 100000], 'RECEIVED');

        $html = $this->actingAs($this->vp)
            ->get(route('stock-orders.approvals', ['tab' => 'pos']))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString($po->po_number, $html);
    }

    public function test_a_vp_can_still_approve_a_draft_atl(): void
    {
        // Listing a draft is only useful if the VP can act on it.
        $atl = $this->atl($this->po(), PurchaseOrderDelivery::APPROVAL_DRAFT);

        $this->actingAs($this->vp)
            ->post(route('stock-orders.atl-approve', $atl->id))
            ->assertRedirect();

        $this->assertSame(
            PurchaseOrderDelivery::APPROVAL_APPROVED,
            $atl->fresh()->approval_status
        );
    }

    // ---- End to end through the controller --------------------------------

    public function test_an_atl_saved_on_the_new_form_lands_in_the_queue(): void
    {
        // Built through the HTTP POST rather than the model, so the two things
        // that decide queueability are actually pinned: approval_status, and
        // atl_source being Doyen-issued rather than client-provided.
        $request = StockRequest::create([
            'request_number' => 'REQ-E2E-'.rand(100, 999),
            'request_type' => StockRequest::TYPE_PICKUP,
            'warehouse_id' => Warehouse::create(['name' => 'E2E Depot '.rand(1000, 9999)])->id,
            'qty_requested' => 50000,
            'status' => StockRequest::STATUS_REQUESTED,
            'requested_by' => $this->purchasing->id,
        ]);
        StockRequestLine::create([
            'stock_request_id' => $request->id,
            'product' => 'Diesel',
            'qty_requested' => 50000,
        ]);

        $po = $this->po();

        $this->actingAs($this->purchasing)
            ->post(route('stock-orders.wet-stock-requests.atls.store', $request->id), [
                'drawdowns' => [
                    ['purchase_order_id' => $po->id, 'product' => 'Diesel', 'quantity' => 20000],
                ],
                'order_type' => 'DELIVERY',
                'delivery_channel' => 'SUPPLIER_STOCKS_DELIVERY',
                'atl_type' => 'DITC_ATL',
                'atl_number' => 'ATL-E2E-'.uniqid(),
                'receiving_date' => now()->addDays(3)->format('Y-m-d'),
            ])
            ->assertSessionHas('success');

        $atl = PurchaseOrderDelivery::where('request_id', $request->id)->firstOrFail();

        $this->assertSame(
            PurchaseOrderDelivery::APPROVAL_FOR_APPROVAL,
            $atl->approval_status,
            'There is no checkbox any more: a Doyen-issued ATL is queued on creation.'
        );
        $this->assertSame(
            PurchaseOrderDelivery::SOURCE_DOYEN_ISSUED,
            $atl->atl_source,
            'Stamped explicitly, so the queue is reading a deliberate decision.'
        );

        $this->assertStringContainsString($atl->atl_number, $this->queueHtml());
    }

    public function test_an_atl_from_a_stock_request_needs_no_checkbox_to_be_queued(): void
    {
        // This form used to carry a "Submit to the VP for approval" checkbox, and
        // unticking it left the ATL in DRAFT. Drafting has been withdrawn, so the
        // same POST with no approval field at all must still queue.
        $request = StockRequest::create([
            'request_number' => 'REQ-E2ED-'.rand(100, 999),
            'request_type' => StockRequest::TYPE_PICKUP,
            'warehouse_id' => Warehouse::create(['name' => 'E2E Draft Depot '.rand(1000, 9999)])->id,
            'qty_requested' => 50000,
            'status' => StockRequest::STATUS_REQUESTED,
            'requested_by' => $this->purchasing->id,
        ]);
        StockRequestLine::create([
            'stock_request_id' => $request->id,
            'product' => 'Diesel',
            'qty_requested' => 50000,
        ]);

        $po = $this->po();

        $this->actingAs($this->purchasing)
            ->post(route('stock-orders.wet-stock-requests.atls.store', $request->id), [
                'drawdowns' => [
                    ['purchase_order_id' => $po->id, 'product' => 'Diesel', 'quantity' => 20000],
                ],
                'order_type' => 'DELIVERY',
                'delivery_channel' => 'SUPPLIER_STOCKS_DELIVERY',
                'atl_type' => 'DITC_ATL',
                'atl_number' => 'ATL-E2ED-'.uniqid(),
                'receiving_date' => now()->addDays(3)->format('Y-m-d'),
            ])
            ->assertSessionHas('success');

        $atl = PurchaseOrderDelivery::where('request_id', $request->id)->firstOrFail();

        $this->assertSame(PurchaseOrderDelivery::APPROVAL_FOR_APPROVAL, $atl->approval_status);
        $this->assertStringContainsString($atl->atl_number, $this->queueHtml());
    }
}
