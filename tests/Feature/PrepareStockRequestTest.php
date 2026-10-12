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
            ->assertSee('Prepare &amp; Submit', false);
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
            ->post(route('stock-orders.prepare-stock-request', $request->id));

        $po = PurchaseOrder::latest('id')->firstOrFail();

        // Lands on the existing Prepare & Submit form.
        $response->assertRedirect(route('stock-orders.edit-request', $po->id));

        $this->assertSame('STANDARD_REPLENISHMENT', $po->po_type);
        $this->assertSame($this->warehouse->id, (int) $po->warehouse_id);
        $this->assertSame(15000, (int) $po->qty_ordered);
        $this->assertSame('REQUESTED', $po->request_status);
        // Still unnumbered, so it does not appear on the Purchase Orders list.
        $this->assertNull($po->po_number);

        $this->assertCount(2, $po->items);
        $this->assertEqualsCanonicalizing(
            ['Diesel 10000', 'Unleaded 5000'],
            $po->items->map(fn ($item) => "{$item->product} {$item->quantity_ordered}")->all()
        );

        $this->assertSame(StockRequest::STATUS_RECEIVED, $request->fresh()->status);
        $this->assertTrue($request->fresh()->isPrepared());
        $this->assertTrue($request->fresh()->purchaseOrder->is($po));
    }

    public function test_a_prepared_request_leaves_the_open_queue(): void
    {
        $request = $this->request();

        $this->actingAs($this->purchasing)
            ->post(route('stock-orders.prepare-stock-request', $request->id));

        // It drops out of the queue of requests awaiting preparation.
        $this->assertFalse(StockRequest::open()->whereKey($request->id)->exists());

        $this->actingAs($this->purchasing)
            ->get(route('stock-orders.wet-stock-requests.index'))
            ->assertOk()
            // The Prepare action for this request is gone.
            ->assertDontSee(route('stock-orders.prepare-stock-request', $request->id), false)
            // It now shows as an unnumbered purchase order instead.
            ->assertSee('Pending PO#');
    }

    public function test_preparing_the_same_request_twice_does_not_raise_two_orders(): void
    {
        $request = $this->request();

        $this->actingAs($this->purchasing)
            ->post(route('stock-orders.prepare-stock-request', $request->id));

        $this->actingAs($this->purchasing)
            ->post(route('stock-orders.prepare-stock-request', $request->id))
            ->assertRedirect();

        $this->assertSame(1, PurchaseOrder::count());
    }

    public function test_roles_outside_purchasing_cannot_prepare(): void
    {
        $request = $this->request();

        $this->actingAs($this->opsManager)
            ->post(route('stock-orders.prepare-stock-request', $request->id))
            ->assertForbidden();

        $this->assertSame(0, PurchaseOrder::count());
    }

    public function test_legacy_request_rows_still_appear_in_the_queue(): void
    {
        $legacy = PurchaseOrder::create([
            'po_type' => 'STANDARD_REPLENISHMENT',
            'warehouse_id' => $this->warehouse->id,
            'qty_ordered' => 7500,
            'request_status' => 'REQUESTED',
            'status' => 'Pending',
            'requested_by' => $this->opsManager->id,
            'request_date' => now(),
            'requested_products' => [['product' => 'Diesel', 'quantity' => 7500]],
            'remarks' => 'Legacy row still awaiting purchase',
        ]);

        $this->actingAs($this->purchasing)
            ->get(route('stock-orders.wet-stock-requests.index'))
            ->assertOk()
            ->assertSee('Pending PO#')
            ->assertSee('Legacy row still awaiting purchase');
    }
}