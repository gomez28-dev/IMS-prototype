<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\AtlAllocation;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderDelivery;
use App\Models\PurchaseOrderItem;
use App\Models\Warehouse;
use Tests\TestCase;

/**
 * The Purchase Orders page lists purchase orders only.
 *
 * The table also stores two kinds of placeholder row that are not purchase
 * orders: Module 2 stock requests (no PO number, no supplier, no value) and
 * Module 1 Sales Order drawdown rows for SO-FT / SO-BB. Both belong to their
 * own pages.
 */
class PurchaseOrderListingScopeTest extends TestCase
{
    protected Admin $purchasing;

    protected Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();

        $this->purchasing = Admin::create([
            'username' => 'purchasing_' . uniqid(),
            'password' => bcrypt('secret'),
            'name' => 'Rica Esaga',
            'role' => 'purchasing',
        ]);

        $this->warehouse = Warehouse::create([
            'name' => 'San Simon',
            'location' => 'San Simon',
        ]);
    }

    /**
     * A real PO: has a number, a supplier, line items and an amount.
     */
    protected function realPo(string $poNumber, int $qty = 50000): PurchaseOrder
    {
        $po = PurchaseOrder::create([
            'po_number' => $poNumber,
            'po_type' => PurchaseOrder::TYPE_STANDARD_REPLENISHMENT,
            'supplier_name' => 'Petron',
            'warehouse_id' => $this->warehouse->id,
            'qty_ordered' => $qty,
            'total_amount' => 4700000.00,
            'net_payable_amount' => 4700000.00,
            'request_status' => 'CONFIRMED',
            'status' => 'Pending',
        ]);

        PurchaseOrderItem::create([
            'purchase_order_id' => $po->id,
            'product' => 'Diesel',
            'quantity_ordered' => $qty,
            'unit_price' => 94.00,
        ]);

        return $po->fresh();
    }

    /**
     * A Module 2 stock request: exactly as StockRequestController creates it.
     * No PO number, no supplier, no items, no amount.
     */
    protected function stockRequest(int $qty = 100): PurchaseOrder
    {
        return PurchaseOrder::create([
            'po_type' => PurchaseOrder::TYPE_STANDARD_REPLENISHMENT,
            'warehouse_id' => $this->warehouse->id,
            'qty_ordered' => $qty,
            'request_status' => 'REQUESTED',
            'status' => 'Pending',
            'requested_products' => [
                ['product' => 'Diesel', 'quantity' => $qty],
            ],
        ])->fresh();
    }

    /**
     * A Module 1 SO-FT / SO-BB drawdown row, as storeFuelTradePo creates it.
     */
    protected function salesOrderDrawdown(string $poNumber): PurchaseOrder
    {
        return PurchaseOrder::create([
            'po_number' => $poNumber,
            'po_type' => PurchaseOrder::TYPE_FUEL_TRADE,
            'supplier_name' => 'Petron',
            'qty_ordered' => 1000,
            'request_status' => 'RECEIVED',
            'status' => 'Pending',
        ])->fresh();
    }

    // ---- Listing ---------------------------------------------------------

    public function test_created_pos_are_listed(): void
    {
        $this->realPo('DITC-0005');
        $this->realPo('DITC-0028', 10000);

        $response = $this->actingAs($this->purchasing)
            ->get(route('stock-orders.purchase-orders.index'));

        $response->assertStatus(200);
        $response->assertSee('DITC-0005');
        $response->assertSee('DITC-0028');
    }

    public function test_pending_stock_requests_are_not_listed(): void
    {
        $this->realPo('DITC-0005');
        $this->stockRequest();
        $this->stockRequest(10001);

        $response = $this->actingAs($this->purchasing)
            ->get(route('stock-orders.purchase-orders.index'));

        $response->assertStatus(200);
        $response->assertSee('DITC-0005');
        // The placeholder label used for rows with no PO number.
        $response->assertDontSee('Pending PO#');
    }

    public function test_sales_order_drawdown_rows_are_not_listed(): void
    {
        $this->realPo('DITC-0005');
        $this->salesOrderDrawdown('SO-FT-7219');

        $response = $this->actingAs($this->purchasing)
            ->get(route('stock-orders.purchase-orders.index'));

        $response->assertStatus(200);
        $response->assertSee('DITC-0005');
        $response->assertDontSee('SO-FT-7219');
    }

    public function test_a_blank_po_number_counts_as_a_request(): void
    {
        // Some legacy rows may hold an empty string rather than NULL.
        PurchaseOrder::create([
            'po_number' => '',
            'po_type' => PurchaseOrder::TYPE_STANDARD_REPLENISHMENT,
            'qty_ordered' => 100,
            'request_status' => 'REQUESTED',
            'status' => 'Pending',
        ]);

        $this->realPo('DITC-0005');

        $this->actingAs($this->purchasing)
            ->get(route('stock-orders.purchase-orders.index'))
            ->assertSee('DITC-0005')
            ->assertDontSee('Pending PO#');
    }

    // ---- Stat cards ------------------------------------------------------

    public function test_total_orders_excludes_requests_and_drawdowns(): void
    {
        $this->realPo('DITC-0005');
        $this->realPo('DITC-0028', 10000);
        $this->stockRequest();
        $this->stockRequest(10001);
        $this->salesOrderDrawdown('SO-FT-7219');

        // Five rows exist in total.
        $this->assertEquals(5, PurchaseOrder::count());

        $response = $this->actingAs($this->purchasing)
            ->get(route('stock-orders.purchase-orders.index'));

        $response->assertStatus(200);
        $this->assertEquals(2, PurchaseOrder::createdPos()->count());
        // The card renders the created-PO count, not the raw row count.
        $response->assertSee('Total Orders');
        $this->assertStringContainsString('stat-big', $response->getContent());
    }

    public function test_request_rows_do_not_inflate_the_volume_cards(): void
    {
        $this->realPo('DITC-0005', 50000);

        // A request carries no line items, so it cannot add to the volumes.
        $this->stockRequest(100);
        $this->stockRequest(10001);

        $volumes = PurchaseOrder::volumeTotals();

        $this->assertEquals(50000, $volumes['ordered']['Diesel']);
    }

    // ---- Other pages keep working ---------------------------------------

    public function test_a_request_becomes_a_po_once_it_is_given_a_number(): void
    {
        $request = $this->stockRequest(25000);

        $this->assertFalse(PurchaseOrder::createdPos()->whereKey($request->id)->exists());

        // Purchasing assigns the PO number when preparing it.
        $request->update([
            'po_number' => 'DITC-9999',
            'supplier_name' => 'Petron',
            'request_status' => 'RECEIVED',
        ]);

        $this->assertTrue(PurchaseOrder::createdPos()->whereKey($request->id)->exists());

        $this->actingAs($this->purchasing)
            ->get(route('stock-orders.purchase-orders.index'))
            ->assertSee('DITC-9999');
    }

    public function test_wet_stock_requests_still_lists_stock_requests(): void
    {
        $this->realPo('DITC-0005');
        $this->stockRequest();

        $response = $this->actingAs($this->purchasing)
            ->get(route('stock-orders.wet-stock-requests.index'));

        $response->assertStatus(200);
        // Its own page keeps the request, and still shows the PO as well.
        $response->assertSee('DITC-0005');
    }

    public function test_a_created_po_is_still_offered_for_allocation(): void
    {
        $po = $this->realPo('DITC-0005', 50000);

        // The Sales Orders drawdown picker only needs stock with balance left,
        // which the new scope does not touch.
        $available = PurchaseOrder::where('status', '!=', 'Cancelled')
            ->get()
            ->filter(fn ($candidate) => $candidate->getAvailableBalanceForProduct('Diesel') > 0);

        $this->assertTrue($available->contains('id', $po->id));
    }

    public function test_allocated_volume_is_still_tracked_for_created_pos(): void
    {
        $po = $this->realPo('DITC-0028', 10000);

        $delivery = PurchaseOrderDelivery::create([
            'purchase_order_id' => $po->id,
            'delivery_channel' => 'TRUCKED',
            'order_type' => 'PICK_UP',
            'atl_type' => 'DITC_ATL',
            'atl_number' => 'ATL-LIST-1',
            'product' => 'Diesel',
            'qty_to_receive' => 10000,
            'receiving_date' => now()->addDay(),
            'status' => 'Pending',
            'lift_status' => PurchaseOrderDelivery::LIFT_UNLIFTED,
        ]);

        AtlAllocation::create([
            'purchase_order_delivery_id' => $delivery->id,
            'purchase_order_id' => $po->id,
            'product' => 'Diesel',
            'quantity' => 10000,
        ]);

        // Still listed, and its outstanding ATL still shows in the cards.
        $this->actingAs($this->purchasing)
            ->get(route('stock-orders.purchase-orders.index'))
            ->assertSee('DITC-0028');

        $this->assertEquals(10000, PurchaseOrder::volumeTotals()['unlifted']['Diesel']);
    }

    public function test_the_detail_page_still_opens_for_a_created_po(): void
    {
        $po = $this->realPo('DITC-0005');

        $this->actingAs($this->purchasing)
            ->get(route('stock-orders.purchase-orders.show', $po->id))
            ->assertStatus(200)
            ->assertSee('DITC-0005');
    }
}