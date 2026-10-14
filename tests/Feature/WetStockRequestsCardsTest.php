<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\StockRequest;
use App\Models\StockRequestLine;
use App\Models\Warehouse;
use Tests\TestCase;

/**
 * The supervisor asked for product-aware dashboard cards on the Wet Stock
 * Requests page, like the other pages have. StockRequest::volumeTotals() already
 * produces the per-product figures; this pins that they reach the page.
 */
class WetStockRequestsCardsTest extends TestCase
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

    /**
     * @param  array<int, array{product: string, qty: int}>  $lines
     * @param  array<string, int>  $received
     */
    protected function request(array $lines, array $received = []): StockRequest
    {
        $request = StockRequest::create([
            'request_type' => StockRequest::TYPE_PICKUP,
            'warehouse_id' => $this->warehouse->id,
            'qty_requested' => array_sum(array_map(fn ($l) => $l['qty'], $lines)),
            'status' => StockRequest::STATUS_REQUESTED,
            'requested_by' => $this->admin->id,
        ]);

        foreach ($lines as $line) {
            StockRequestLine::create([
                'stock_request_id' => $request->id,
                'product' => $line['product'],
                'qty_requested' => $line['qty'],
                'qty_received' => $received[$line['product']] ?? 0,
            ]);
        }

        return $request;
    }

    protected function page(array $query = []): string
    {
        return $this->actingAs($this->admin)
            ->get(route('stock-orders.wet-stock-requests.index', $query))
            ->assertOk()
            ->getContent();
    }

    // ---- The cards ---------------------------------------------------------

    public function test_the_page_shows_three_product_aware_cards(): void
    {
        $html = $this->page();

        $this->assertStringContainsString('Total Requested', $html);
        $this->assertStringContainsString('Total Received', $html);
        $this->assertStringContainsString('Remaining', $html);
    }

    public function test_the_cards_use_the_shared_card_vocabulary(): void
    {
        $html = $this->page();

        // stat-icon / stat-row / stat-total / prod-chip are shared by the Module 3
        // lists and the M1 Deliveries page, which is what makes them read as one
        // set. The wrapper itself stays card-custom, as on the other M3 pages;
        // the M1-only stat-card wrapper belongs on the ATL page, not here.
        foreach (['stat-icon', 'stat-row', 'stat-total', 'prod-chip'] as $class) {
            $this->assertStringContainsString($class, $html, "Cards should use {$class}.");
        }
        $this->assertStringContainsString('card-custom', $html);
    }

    public function test_every_product_gets_a_chip_in_the_cards(): void
    {
        $this->request([
            ['product' => 'Diesel', 'qty' => 50000],
            ['product' => 'Unleaded', 'qty' => 20000],
            ['product' => 'Premium', 'qty' => 10000],
        ]);

        $html = $this->page();

        // U / D / P chips, as on the other pages.
        $this->assertStringContainsString('prod-chip', $html);
        foreach (['Unleaded', 'Diesel', 'Premium'] as $product) {
            $this->assertStringContainsString($product, $html);
        }
    }

    // ---- The figures -------------------------------------------------------

    public function test_the_cards_total_what_the_table_below_lists(): void
    {
        $this->request([
            ['product' => 'Diesel', 'qty' => 50000],
            ['product' => 'Unleaded', 'qty' => 20000],
        ]);

        $html = $this->page();

        $this->assertStringContainsString('50,000', $html);
        $this->assertStringContainsString('20,000', $html);
        // 70,000 requested in total.
        $this->assertStringContainsString('70,000', $html);
    }

    public function test_received_volume_appears_in_the_cards(): void
    {
        $this->request(
            [['product' => 'Diesel', 'qty' => 50000]],
            ['Diesel' => 20000]
        );

        $html = $this->page();

        $this->assertStringContainsString('20,000', $html);
        // 30,000 still outstanding.
        $this->assertStringContainsString('30,000', $html);
    }

    public function test_the_cards_move_with_the_search_filter(): void
    {
        $this->request([['product' => 'Diesel', 'qty' => 50000]]);

        // A search that matches nothing must not leave the old totals on screen.
        $html = $this->page(['search' => 'ZZZ-NO-SUCH-REQUEST']);

        $this->assertStringNotContainsString('50,000', $html);
    }
}
