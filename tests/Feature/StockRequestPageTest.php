<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\StockRequest;
use App\Models\StockRequestLine;
use App\Models\Warehouse;
use Tests\TestCase;

/**
 * Whiteboard sections 7-9 on the Module 2 requestor page: the REQ list, the
 * Purchasing column reported separately from Qty Received, and
 * Pending = Requested - Received per product.
 */
class StockRequestPageTest extends TestCase
{
    protected Admin $opsManager;

    protected Admin $purchasing;

    protected Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();

        $this->opsManager = Admin::create([
            'username' => 'ops_'.uniqid(),
            'password' => bcrypt('secret'),
            'name' => 'Depot Manager',
            'role' => 'ops_mgr',
        ]);

        $this->purchasing = Admin::create([
            'username' => 'purchasing_'.uniqid(),
            'password' => bcrypt('secret'),
            'name' => 'Rica Esaga',
            'role' => 'purchasing',
        ]);

        $this->warehouse = Warehouse::create(['name' => 'San Simon Depot']);
    }

    /**
     * @param  array<int, array{product: string, qty: int}>  $lines
     */
    protected function request(array $lines = [['product' => 'Diesel', 'qty' => 10000]]): StockRequest
    {
        $request = StockRequest::create([
            'request_type' => StockRequest::TYPE_PICKUP,
            'warehouse_id' => $this->warehouse->id,
            'requested_by' => $this->opsManager->id,
            'request_date' => now(),
            'status' => StockRequest::STATUS_REQUESTED,
        ]);

        foreach ($lines as $line) {
            StockRequestLine::create([
                'stock_request_id' => $request->id,
                'product' => $line['product'],
                'qty_requested' => $line['qty'],
                'qty_received' => 0,
            ]);
        }

        return $request->fresh();
    }

    protected function page(): string
    {
        $response = $this->actingAs($this->opsManager)
            ->get(route('wetstock.stock-requests.index'));
        $response->assertOk();

        return $response->getContent();
    }

    // ---- The list ---------------------------------------------------------

    public function test_the_list_shows_a_real_req_number(): void
    {
        $request = $this->request();

        $html = $this->page();

        $this->assertMatchesRegularExpression('/^REQ-\d{3}$/', $request->request_number);
        $this->assertStringContainsString($request->request_number, $html);
    }

    public function test_the_list_reports_requested_received_and_pending_per_product(): void
    {
        $this->request([
            ['product' => 'Diesel', 'qty' => 10000],
            ['product' => 'Unleaded', 'qty' => 5000],
        ]);

        $html = $this->page();

        $this->assertStringContainsString('Qty. Requested', $html);
        $this->assertStringContainsString('Qty. Received', $html);
        $this->assertStringContainsString('Qty. Pending', $html);
        $this->assertStringContainsString('10,000', $html);
        $this->assertStringContainsString('5,000', $html);
    }

    public function test_purchasing_is_a_column_of_its_own(): void
    {
        $this->request();

        $html = $this->page();

        $this->assertStringContainsString('Purchasing', $html);
        $this->assertStringContainsString('Awaiting', $html);
    }

    public function test_an_untouched_request_reports_pending_equal_to_requested(): void
    {
        $request = $this->request();

        $this->assertSame(10000, $request->fresh()->lines->first()->qty_pending);
        $this->assertStringContainsString('Awaiting', $this->page());
    }

    public function test_the_list_filters_by_site(): void
    {
        $mine = $this->request();
        $other = Warehouse::create(['name' => 'Bataan Depot']);
        $theirs = StockRequest::create([
            'warehouse_id' => $other->id,
            'requested_by' => $this->opsManager->id,
            'request_date' => now(),
        ]);
        StockRequestLine::create([
            'stock_request_id' => $theirs->id,
            'product' => 'Diesel',
            'qty_requested' => 1000,
        ]);

        $response = $this->actingAs($this->opsManager)
            ->get(route('wetstock.stock-requests.index', ['warehouse_id' => $this->warehouse->id]));

        $response->assertOk();
        $response->assertSee($mine->request_number);
        $response->assertDontSee($theirs->request_number);
    }

    // ---- Picking up -------------------------------------------------------

    public function test_picking_up_is_recorded_and_is_not_received(): void
    {
        $request = $this->request();

        $this->actingAs($this->opsManager)
            ->post(route('wetstock.stock-requests.picked-up', $request->id))
            ->assertRedirect();

        $fresh = $request->fresh();

        $this->assertTrue($fresh->hasBeenPickedUpByPurchasing());
        $this->assertSame($this->opsManager->id, $fresh->purchasing_received_by);
        // Picking a request up is not the same as fuel arriving.
        $this->assertSame(0, $fresh->qty_received);

        $html = $this->page();
        $this->assertStringContainsString($this->opsManager->name, $html);
        $this->assertStringNotContainsString('Awaiting', $html);
    }

    public function test_picking_up_twice_keeps_the_first_record(): void
    {
        $request = $this->request();

        $this->actingAs($this->opsManager)->post(route('wetstock.stock-requests.picked-up', $request->id));
        $first = $request->fresh()->purchasing_received_at;

        $this->actingAs($this->opsManager)->post(route('wetstock.stock-requests.picked-up', $request->id));

        $this->assertEquals($first, $request->fresh()->purchasing_received_at);
    }

    // ---- Recording received ------------------------------------------------

    public function test_recorded_quantities_drive_pending_down(): void
    {
        $request = $this->request([
            ['product' => 'Diesel', 'qty' => 10000],
            ['product' => 'Unleaded', 'qty' => 5000],
        ]);
        $lines = $request->lines;

        $this->actingAs($this->opsManager)
            ->post(route('wetstock.stock-requests.received', $request->id), [
                'quantities' => [
                    $lines[0]->id => 4000,
                    $lines[1]->id => 5000,
                ],
            ])
            ->assertRedirect();

        $lines->each->refresh();
        $this->assertSame(4000, $lines[0]->fresh()->qty_received);
        $this->assertSame(6000, $lines[0]->fresh()->qty_pending);
        $this->assertSame(0, $lines[1]->fresh()->qty_pending);
    }

    public function test_a_fully_received_request_is_completed(): void
    {
        $request = $this->request();
        $line = $request->lines->first();

        $this->actingAs($this->opsManager)->post(route('wetstock.stock-requests.received', $request->id), [
            'quantities' => [$line->id => 10000],
        ]);

        $this->assertSame(StockRequest::STATUS_COMPLETED, $request->fresh()->status);
    }

    public function test_a_partly_received_request_stays_open(): void
    {
        $request = $this->request();
        $line = $request->lines->first();

        $this->actingAs($this->opsManager)->post(route('wetstock.stock-requests.received', $request->id), [
            'quantities' => [$line->id => 4000],
        ]);

        $this->assertSame(StockRequest::STATUS_RECEIVED, $request->fresh()->status);
    }

    public function test_re_recording_corrects_rather_than_adds(): void
    {
        $request = $this->request();
        $line = $request->lines->first();

        $this->actingAs($this->opsManager)->post(route('wetstock.stock-requests.received', $request->id), [
            'quantities' => [$line->id => 4000],
        ]);
        $this->actingAs($this->opsManager)->post(route('wetstock.stock-requests.received', $request->id), [
            'quantities' => [$line->id => 10000],
        ]);

        $this->assertSame(10000, $line->fresh()->qty_received);
        $this->assertSame(0, $line->fresh()->qty_pending);
    }

    public function test_a_negative_quantity_is_rejected(): void
    {
        $request = $this->request();
        $line = $request->lines->first();

        $this->actingAs($this->opsManager)
            ->post(route('wetstock.stock-requests.received', $request->id), [
                'quantities' => [$line->id => -5],
            ])
            ->assertSessionHasErrors("quantities.{$line->id}");

        $this->assertSame(0, $line->fresh()->qty_received);
    }

    public function test_roles_without_depot_receiving_cannot_record_quantities(): void
    {
        $request = $this->request();
        $line = $request->lines->first();

        // Purchasing edits stock orders but does not receive into depot tanks.
        $this->actingAs($this->purchasing)
            ->post(route('wetstock.stock-requests.received', $request->id), [
                'quantities' => [$line->id => 10000],
            ])
            ->assertForbidden();
    }

    // ---- Stat cards -------------------------------------------------------

    public function test_the_stat_cards_total_the_product_lines(): void
    {
        $request = $this->request([
            ['product' => 'Diesel', 'qty' => 10000],
            ['product' => 'Unleaded', 'qty' => 5000],
            ['product' => 'Premium', 'qty' => 2500],
        ]);

        // Lines are looked up by product, never by collection index: the
        // database does not promise to return them in insertion order.
        $request->lines->firstWhere('product', 'Diesel')->update(['qty_received' => 4000]);
        $request->lines->firstWhere('product', 'Unleaded')->update(['qty_received' => 5000]);

        $totals = StockRequest::volumeTotals();

        $this->assertSame(10000, $totals['requested']['Diesel']);
        $this->assertSame(5000, $totals['requested']['Unleaded']);
        $this->assertSame(4000, $totals['received']['Diesel']);
        $this->assertSame(5000, $totals['received']['Unleaded']);
        // Diesel 10,000 - 4,000; Premium 2,500 - 0.
        $this->assertSame(6000, $totals['pending']['Diesel']);
        $this->assertSame(2500, $totals['pending']['Premium']);

        $html = $this->page();

        $this->assertStringContainsString('Total Requests', $html);
        $this->assertStringContainsString('Total Qty. Requested', $html);
        $this->assertStringContainsString('Total Qty. Received', $html);
        $this->assertStringContainsString('Total Qty. Pending', $html);
        // The cards break out per product rather than showing one grand total,
        // so the per-product figures are what appear on the page.
        foreach (['10,000', '5,000', '2,500', '4,000', '6,000'] as $figure) {
            $this->assertStringContainsString($figure, $html);
        }
    }

    public function test_the_stat_cards_reconcile_with_the_rows_on_screen(): void
    {
        $this->request([['product' => 'Diesel', 'qty' => 10000]]);
        $mine = $this->request([['product' => 'Unleaded', 'qty' => 5000]]);

        $other = Warehouse::create(['name' => 'Bataan Depot']);
        $theirs = StockRequest::create([
            'warehouse_id' => $other->id,
            'requested_by' => $this->opsManager->id,
            'request_date' => now(),
        ]);
        StockRequestLine::create([
            'stock_request_id' => $theirs->id,
            'product' => 'Diesel',
            'qty_requested' => 9999,
        ]);

        // Filtering to one site must narrow the cards to match the table.
        $totals = StockRequest::volumeTotals(
            StockRequest::where('warehouse_id', $this->warehouse->id)
        );

        $this->assertSame(10000, $totals['requested']['Diesel']);
        $this->assertSame(5000, $totals['requested']['Unleaded']);
        // The other site's 9,999 is not counted in this site's cards.
        $this->assertSame(2, StockRequest::where('warehouse_id', $this->warehouse->id)->count());
    }

    public function test_buy_back_orders_are_not_listed_on_this_page(): void
    {
        // Buy Back orders are assigned through Assign Delivery, so they have
        // no section here. The cards only ever total StockRequest lines.
        $totals = StockRequest::volumeTotals();

        foreach ($totals as $bucket) {
            $this->assertSame(0, $bucket['Diesel']);
        }
    }

    // ---- Search -----------------------------------------------------------

    public function test_search_matches_the_req_number(): void
    {
        $mine = $this->request();
        $this->request();

        $response = $this->actingAs($this->opsManager)
            ->get(route('wetstock.stock-requests.index', ['search' => $mine->request_number]));

        $response->assertOk();
        $response->assertSee($mine->request_number);
        $response->assertSee('Search', false);
    }

    public function test_search_matches_remarks(): void
    {
        $mine = $this->request();
        $mine->update(['remarks' => 'Unique tank survey marker']);
        $this->request();

        $response = $this->actingAs($this->opsManager)
            ->get(route('wetstock.stock-requests.index', ['search' => 'tank survey marker']));

        $response->assertOk();
        $response->assertSee('Unique tank survey marker');
    }

    public function test_search_matches_the_requesters_name(): void
    {
        $mine = $this->request();

        $response = $this->actingAs($this->opsManager)
            ->get(route('wetstock.stock-requests.index', ['search' => $this->opsManager->name]));

        $response->assertOk();
        $response->assertSee($mine->request_number);
    }

    public function test_search_combines_with_the_site_filter(): void
    {
        $mine = $this->request();

        $other = Warehouse::create(['name' => 'Bataan Depot']);
        $theirs = StockRequest::create([
            'warehouse_id' => $other->id,
            'requested_by' => $this->opsManager->id,
            'request_date' => now(),
        ]);
        StockRequestLine::create([
            'stock_request_id' => $theirs->id,
            'product' => 'Diesel',
            'qty_requested' => 1000,
        ]);

        $response = $this->actingAs($this->opsManager)
            ->get(route('wetstock.stock-requests.index', [
                'search' => 'REQ-',
                'warehouse_id' => $this->warehouse->id,
            ]));

        $response->assertOk();
        $response->assertSee($mine->request_number);
        $response->assertDontSee($theirs->request_number);
    }

    // ---- Findability ------------------------------------------------------

    public function test_the_stat_cards_match_the_purchase_orders_cards(): void
    {
        $this->request([
            ['product' => 'Diesel', 'qty' => 10000],
            ['product' => 'Premium', 'qty' => 5000],
        ]);

        $html = $this->page();

        // Same building blocks as the Purchase Orders page: icon tile, product
        // chips in their own rows, and a Total footer.
        $this->assertStringContainsString('stat-icon', $html);
        $this->assertStringContainsString('stat-title', $html);
        $this->assertStringContainsString('stat-row', $html);
        $this->assertStringContainsString('stat-total', $html);
        $this->assertStringContainsString('stat-big', $html);
        $this->assertStringContainsString('prod-chip', $html);

        // Nothing has arrived, so Requested and Pending both total 15,000
        // while Received totals 0.
        $this->assertSame(2, substr_count($html, '<span class="stat-val">15,000</span>'));
    }

    public function test_the_page_has_its_own_title_and_sidebar_entry(): void
    {
        $html = $this->page();

        $this->assertStringContainsString('Stock Requests', $html);
        $this->assertStringContainsString(route('wetstock.stock-requests.index'), $html);
    }

    public function test_the_two_module_two_pages_no_longer_share_a_title(): void
    {
        $opsAdmin = Admin::create([
            'username' => 'opsadmin_'.uniqid(),
            'password' => bcrypt('secret'),
            'name' => 'Ops Admin',
            'role' => 'ops_admin',
        ]);

        $requests = $this->actingAs($this->opsManager)
            ->get(route('wetstock.stock-requests.index'))
            ->assertOk()
            ->getContent();

        $incoming = $this->actingAs($opsAdmin)
            ->get(route('wetstock.supplier-orders.index'))
            ->assertOk()
            ->getContent();

        // Both pages used to be titled "Incoming Supplier Stock", which made
        // the Stock Request page impossible to tell apart.
        $this->assertStringNotContainsString('Incoming Supplier Stock', $requests);
        $this->assertStringContainsString('Incoming Supplier Stock', $incoming);
    }
}