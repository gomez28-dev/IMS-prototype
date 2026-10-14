<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\PurchaseOrder;
use App\Models\StockRequest;
use App\Models\StockRequestLine;
use App\Models\Warehouse;
use Tests\TestCase;

/**
 * A Module 2 request has to reach Purchasing, and Preparing it has to raise a
 * real purchase order with real item rows.
 */
class PrepareStockRequestTest extends TestCase
{
    protected Admin $purchasing;

    protected Admin $opsManager;

    protected Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();

        $this->purchasing = Admin::create([
            'username' => 'purchasing_'.uniqid(),
            'password' => bcrypt('secret'),
            'name' => 'Rica Esaga',
            'role' => 'purchasing',
        ]);

        $this->opsManager = Admin::create([
            'username' => 'ops_'.uniqid(),
            'password' => bcrypt('secret'),
            'name' => 'Depot Manager',
            'role' => 'ops_mgr',
        ]);

        $this->warehouse = Warehouse::create(['name' => 'San Simon Depot']);
    }

    protected function request(): StockRequest
    {
        $request = StockRequest::create([
            'request_type' => StockRequest::TYPE_PICKUP,
            'warehouse_id' => $this->warehouse->id,
            'requested_by' => $this->opsManager->id,
            'request_date' => now(),
            'date_needed' => now()->addDays(3)->toDateString(),
            'remarks' => 'Tank below reorder point',
            'status' => StockRequest::STATUS_REQUESTED,
        ]);

        StockRequestLine::create([
            'stock_request_id' => $request->id,
            'product' => 'Diesel',
            'qty_requested' => 10000,
        ]);
        StockRequestLine::create([
            'stock_request_id' => $request->id,
            'product' => 'Unleaded',
            'qty_requested' => 5000,
        ]);

        return $request->fresh();
    }

    public function test_the_request_reaches_the_purchasing_queue(): void
    {
        $request = $this->request();

        $this->actingAs($this->purchasing)
            ->get(route('stock-orders.wet-stock-requests.index'))
            ->assertOk()
            ->assertSee($request->request_number)
            ->assertSee('Create ATL', false);
    }

    public function test_the_queue_search_matches_the_req_number(): void
    {
        $request = $this->request();

        $this->actingAs($this->purchasing)
            ->get(route('stock-orders.wet-stock-requests.index', ['search' => $request->request_number]))
            ->assertOk()
            ->assertSee($request->request_number);
    }

    public function test_preparing_a_request_raises_a_purchase_order_with_item_rows(): void
    {
        $request = $this->request();

        $response = $this->actingAs($this->purchasing)
            ->post(route('stock-orders.store-stock-request-po'), [
                'stock_request_id' => $request->id,
                'po_number' => 'PO-FROM-REQ',
                'supplier_name' => 'Petron Bataan Refinery',
            ]);

        $po = PurchaseOrder::latest('id')->firstOrFail();

        $response->assertRedirect(route('stock-orders.purchase-orders.show', $po->id));

        $this->assertSame('PO-FROM-REQ', $po->po_number);
        $this->assertSame('STANDARD_REPLENISHMENT', $po->po_type);
        $this->assertSame($this->warehouse->id, (int) $po->warehouse_id);
        $this->assertSame(15000, (int) $po->qty_ordered);
        $this->assertSame('RECEIVED', $po->request_status);

        $this->assertCount(2, $po->items);
        $this->assertEqualsCanonicalizing(
            ['Diesel 10000', 'Unleaded 5000'],
            $po->items->map(fn ($item) => "{$item->product} {$item->quantity_ordered}")->all()
        );

        $this->assertSame(StockRequest::STATUS_RECEIVED, $request->fresh()->status);
        $this->assertTrue($request->fresh()->isPrepared());
        $this->assertTrue($request->fresh()->purchaseOrder->is($po));
    }

    public function test_the_po_number_is_a_dropdown_not_free_text(): void
    {
        $request = $this->request();

        $html = $this->actingAs($this->purchasing)
            ->get(route('stock-orders.prepare-stock-request'))
            ->assertOk()
            ->getContent();

        // The number is chosen from the requests awaiting one.
        $this->assertStringContainsString('name="stock_request_id"', $html);
        $this->assertStringNotContainsString('name="po_number"', $html);
        $this->assertStringContainsString($request->request_number, $html);
    }

    public function test_the_queue_no_longer_offers_prepare_but_the_page_still_works(): void
    {
        $request = $this->request();

        $html = $this->actingAs($this->purchasing)
            ->get(route('stock-orders.wet-stock-requests.index'))
            ->assertOk()
            ->getContent();

        // Create ATL is the queue's one action now. Prepare survives as a route
        // so an existing link still works, but the queue stopped advertising it.
        $this->assertStringNotContainsString(
            route('stock-orders.prepare-stock-request', ['stock_request' => $request->id]),
            $html
        );

        $this->actingAs($this->purchasing)
            ->get(route('stock-orders.prepare-stock-request', ['stock_request' => $request->id]))
            ->assertOk()
            ->assertSee($request->request_number);
    }

    public function test_a_prepared_request_leaves_the_open_queue(): void
    {
        $request = $this->request();

        $this->actingAs($this->purchasing)->post(route('stock-orders.store-stock-request-po'), [
            'stock_request_id' => $request->id,
            'po_number' => 'PO-FROM-REQ',
            'supplier_name' => 'Petron Bataan Refinery',
        ]);

        // It drops out of the queue of requests awaiting preparation.
        $this->assertFalse(StockRequest::open()->whereKey($request->id)->exists());

        $this->actingAs($this->purchasing)
            ->get(route('stock-orders.wet-stock-requests.index'))
            ->assertOk()
            ->assertDontSee(route('stock-orders.prepare-stock-request', ['stock_request' => $request->id]), false);

        // And off the Prepare page's dropdown.
        $this->actingAs($this->purchasing)
            ->get(route('stock-orders.prepare-stock-request'))
            ->assertOk()
            ->assertDontSee($request->request_number);
    }

    public function test_preparing_the_same_request_twice_does_not_raise_two_orders(): void
    {
        $request = $this->request();

        foreach (range(1, 2) as $ignored) {
            $this->actingAs($this->purchasing)->post(route('stock-orders.store-stock-request-po'), [
                'stock_request_id' => $request->id,
                'po_number' => 'PO-FROM-REQ',
                'supplier_name' => 'Petron Bataan Refinery',
            ]);
        }

        $this->assertSame(1, PurchaseOrder::count());
    }

    public function test_a_po_number_already_in_use_is_rejected(): void
    {
        $request = $this->request();

        PurchaseOrder::create([
            'po_number' => 'PO-ALREADY-TAKEN',
            'po_type' => PurchaseOrder::TYPE_STANDARD_REPLENISHMENT,
            'qty_ordered' => 1000,
            'request_status' => 'RECEIVED',
            'status' => 'Pending',
        ]);

        $this->actingAs($this->purchasing)
            ->post(route('stock-orders.store-stock-request-po'), [
                'stock_request_id' => $request->id,
                'po_number' => 'PO-ALREADY-TAKEN',
                'supplier_name' => 'Petron Bataan Refinery',
            ])
            ->assertSessionHasErrors('po_number');

        $this->assertSame(1, PurchaseOrder::count());
    }

    public function test_roles_outside_purchasing_cannot_prepare(): void
    {
        $request = $this->request();

        $this->actingAs($this->opsManager)
            ->get(route('stock-orders.prepare-stock-request'))
            ->assertForbidden();

        $this->actingAs($this->opsManager)
            ->post(route('stock-orders.store-stock-request-po'), [
                'stock_request_id' => $request->id,
                'po_number' => 'PO-FROM-REQ',
                'supplier_name' => 'Petron Bataan Refinery',
            ])
            ->assertForbidden();

        $this->assertSame(0, PurchaseOrder::count());
    }

    public function test_the_created_po_is_immediately_allocatable(): void
    {
        // The split depends on this: the PO raised from a request must carry
        // enough balance for the Allocate flow to draw the delivery from.
        $request = $this->request();

        $this->actingAs($this->purchasing)->post(route('stock-orders.store-stock-request-po'), [
            'stock_request_id' => $request->id,
            'po_number' => 'PO-ALLOCATABLE',
            'supplier_name' => 'Petron Bataan Refinery',
        ]);

        $po = PurchaseOrder::latest('id')->firstOrFail();

        $this->assertSame(10000, $po->getAvailableBalanceForProduct('Diesel'));
        $this->assertSame(5000, $po->getAvailableBalanceForProduct('Unleaded'));
        // A product the request never asked for has nothing available.
        $this->assertSame(0, $po->getAvailableBalanceForProduct('Premium'));
    }

    public function test_the_prepare_page_reports_when_nothing_is_waiting(): void
    {
        $this->actingAs($this->purchasing)
            ->get(route('stock-orders.prepare-stock-request'))
            ->assertOk()
            ->assertSee('nothing to prepare');
    }

    public function test_purchase_orders_no_longer_leak_into_the_request_queue(): void
    {
        // This row is not a request at all: storeSupplierPo files every supplier
        // PO as STANDARD_REPLENISHMENT, which is exactly what the legacy query
        // matched on. It was surfacing on this page with a View Request button
        // that led to a purchase order.
        $legacy = PurchaseOrder::create([
            'po_type' => 'STANDARD_REPLENISHMENT',
            'warehouse_id' => $this->warehouse->id,
            'qty_ordered' => 7500,
            'request_status' => 'REQUESTED',
            'status' => 'Pending',
            'requested_by' => $this->opsManager->id,
            'request_date' => now(),
            'requested_products' => [['product' => 'Diesel', 'quantity' => 7500]],
            'remarks' => 'Ordinary supplier PO, not a request',
        ]);

        $html = $this->actingAs($this->purchasing)
            ->get(route('stock-orders.wet-stock-requests.index'))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('Ordinary supplier PO, not a request', $html);
        $this->assertStringNotContainsString('View Request', $html);
        $this->assertStringNotContainsString(
            route('stock-orders.wet-stock-requests.show', $legacy->id),
            $html
        );
    }
}