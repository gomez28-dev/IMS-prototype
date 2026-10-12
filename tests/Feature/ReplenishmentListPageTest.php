<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\StockRequest;
use App\Models\StockRequestLine;
use App\Models\Warehouse;
use Tests\TestCase;

/**
 * Supervisor feedback 2026-10-07, Images 3 and 4.
 *
 * - The two lists merge onto the main Incoming Supplier Stock page.
 * - The Request # column goes away.
 * - "Depot" becomes "Site" and shows the real site, never "All Depots".
 * - The double-encoded em dash that rendered as "â€"" is gone.
 */
class ReplenishmentListPageTest extends TestCase
{
    protected Admin $opsUser;
    protected Warehouse $sanSimon;

    protected function setUp(): void
    {
        parent::setUp();

        $this->opsUser = Admin::create([
            'username' => 'replist_' . uniqid(),
            'password' => bcrypt('secret'),
            'name' => 'Ops Officer',
            'role' => 'ops_admin',
        ]);

        $this->sanSimon = Warehouse::create(['name' => 'San Simon ' . rand(1000, 9999)]);
    }

    protected function replenishmentRequest(array $overrides = []): StockRequest
    {
        $request = StockRequest::create(array_merge([
            'request_type' => StockRequest::TYPE_PICKUP,
            'warehouse_id' => $this->sanSimon->id,
            'requested_by' => $this->opsUser->id,
            'request_date' => now(),
            'status' => StockRequest::STATUS_REQUESTED,
            'remarks' => 'Tank below reorder point',
        ], $overrides));

        StockRequestLine::create([
            'stock_request_id' => $request->id,
            'product' => 'Diesel',
            'qty_requested' => 10000,
            'qty_received' => 0,
        ]);

        return $request->fresh();
    }

    protected function pageHtml(): string
    {
        $response = $this->actingAs($this->opsUser)->get(route('wetstock.stock-requests.index'));
        $response->assertOk();

        return $response->getContent();
    }

    public function test_list_renders_a_submitted_request_with_its_real_site(): void
    {
        $this->replenishmentRequest();

        $html = $this->pageHtml();

        $this->assertStringContainsString($this->sanSimon->name, $html);
        $this->assertStringNotContainsString('All Depots', $html);
    }

    public function test_request_number_column_is_removed(): void
    {
        $this->replenishmentRequest();

        $this->assertStringNotContainsString('Request #', $this->pageHtml());
    }

    public function test_depot_column_is_renamed_to_site(): void
    {
        $this->replenishmentRequest();

        $html = $this->pageHtml();

        $this->assertStringContainsString('Site', $html);
    }

    public function test_remarks_render_without_mojibake(): void
    {
        $this->replenishmentRequest(['remarks' => 'Tank below reorder point']);

        $html = $this->pageHtml();

        $this->assertStringContainsString('Tank below reorder point', $html);

        foreach (["\u{00E2}\u{20AC}\u{201D}", "\u{00E2}\u{20AC}\u{201C}", "\u{00E2}\u{20AC}"] as $bad) {
            $this->assertStringNotContainsString(
                $bad,
                $html,
                'Remarks contain the double-encoded em dash that rendered as "a€""'
            );
        }
    }

    /**
     * The empty-state placeholder used the same broken em dash and would
     * surface whenever a request had no date or no remarks.
     */
    public function test_empty_date_and_remarks_placeholders_are_clean(): void
    {
        $this->replenishmentRequest(['date_needed' => null, 'remarks' => null]);

        $html = $this->pageHtml();

        $this->assertStringNotContainsString("\u{00E2}\u{20AC}", $html);
    }

    /**
     * PO #, ATL # and DR # columns must be present on the merged page,
     * per the supervisor's Image 4 note.
     */
    public function test_merged_list_shows_po_atl_and_dr_columns(): void
    {
        $this->replenishmentRequest();

        $html = $this->pageHtml();

        $this->assertStringContainsString('PO #', $html);
        $this->assertMatchesRegularExpression('/ATL/i', $html);
        $this->assertMatchesRegularExpression('/DR/i', $html);
    }
}