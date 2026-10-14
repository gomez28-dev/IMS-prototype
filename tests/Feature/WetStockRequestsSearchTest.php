<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\StockRequest;
use App\Models\StockRequestLine;
use App\Models\Warehouse;
use Tests\TestCase;

/**
 * The Wet Stock Requests page already filtered on ?search= from the controller,
 * but nothing on the page could set it. The supervisor asked for a search bar,
 * styled like M1's.
 */
class WetStockRequestsSearchTest extends TestCase
{
    protected Admin $admin;

    protected Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = Admin::create([
            'username' => 'admin_'.uniqid(),
            'password' => bcrypt('secret'),
            'name' => 'System Administrator',
            'role' => 'admin',
        ]);

        $this->warehouse = Warehouse::create(['name' => 'Bataan Depot '.rand(1000, 9999)]);
    }

    protected function request(string $number, string $remarks = ''): StockRequest
    {
        $request = StockRequest::create([
            'request_number' => $number,
            'request_type' => StockRequest::TYPE_PICKUP,
            'warehouse_id' => $this->warehouse->id,
            'qty_requested' => 50000,
            'status' => StockRequest::STATUS_REQUESTED,
            'requested_by' => $this->admin->id,
            'remarks' => $remarks,
        ]);

        StockRequestLine::create([
            'stock_request_id' => $request->id,
            'product' => 'Diesel',
            'qty_requested' => 50000,
        ]);

        return $request;
    }

    protected function page(array $query = []): string
    {
        return $this->actingAs($this->admin)
            ->get(route('stock-orders.wet-stock-requests.index', $query))
            ->assertOk()
            ->getContent();
    }

    // ---- The field ---------------------------------------------------------

    public function test_the_page_offers_a_search_box(): void
    {
        $html = $this->page();

        $this->assertStringContainsString('name="search"', $html);
    }

    public function test_the_search_box_is_styled_like_m1s(): void
    {
        $html = $this->page();

        // M1's signature: a bordered-off search glyph inside an input group.
        $this->assertMatchesRegularExpression(
            '/<span class="input-group-text[^"]*">\s*<i class="bi bi-search/',
            $html
        );
        $this->assertMatchesRegularExpression(
            '/<input[^>]*name="search"[^>]*border-start-0/',
            $html
        );
        $this->assertMatchesRegularExpression('/btn-primary-custom[^"]*"?>\s*Search/', $html);
    }

    public function test_the_search_box_sits_in_the_page_header(): void
    {
        $html = $this->page();

        // Level with the two buttons it belongs beside.
        $searchAt = strpos($html, 'name="search"');
        $incomingAt = strpos($html, 'Incoming Stock Log');

        $this->assertIsInt($searchAt);
        $this->assertIsInt($incomingAt);
        $this->assertLessThan($incomingAt, $searchAt, 'Search belongs before the header buttons.');
    }

    // ---- It works ----------------------------------------------------------

    public function test_search_narrows_the_list_to_a_request_number(): void
    {
        $want = $this->request('REQ-SEARCH-1');
        $this->request('REQ-OTHER-2');

        $html = $this->page(['search' => $want->request_number]);

        $this->assertStringContainsString($want->request_number, $html);
        $this->assertStringNotContainsString('REQ-OTHER-2', $html);
    }

    public function test_search_also_matches_remarks(): void
    {
        $this->request('REQ-NOTE-1', 'Urgent for Bataan pipeline');
        $this->request('REQ-NOTE-2', 'Routine top-up');

        $html = $this->page(['search' => 'Bataan pipeline']);

        $this->assertStringContainsString('REQ-NOTE-1', $html);
        $this->assertStringNotContainsString('REQ-NOTE-2', $html);
    }

    public function test_search_shows_everything_when_nothing_matches(): void
    {
        $this->request('REQ-SHOW-ALL-1');

        $html = $this->page(['search' => 'ZZZ-NO-SUCH-THING']);

        $this->assertStringNotContainsString('REQ-SHOW-ALL-1', $html);
    }

    public function test_the_search_term_is_kept_in_the_box(): void
    {
        $html = $this->page(['search' => 'Bataan']);

        $this->assertMatchesRegularExpression(
            '/<input[^>]*name="search"[^>]*value="Bataan"/',
            $html,
            'The box should come back holding what was searched for.'
        );
    }
}
