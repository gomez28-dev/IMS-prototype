<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\PurchaseOrder;
use App\Models\StockRequest;
use App\Models\StockRequestLine;
use Tests\TestCase;

/**
 * The old request form used min="1" step="100", so browsers validated
 * (value - min) mod step == 0 and snapped a round 10,000 to 10,001.
 * The form is fixed; this command repairs rows typed before the fix.
 */
class FixRequestQuantitiesTest extends TestCase
{
    protected function request(): StockRequest
    {
        $ops = Admin::create([
            'username' => 'ops_'.uniqid(),
            'password' => bcrypt('secret'),
            'name' => 'Depot Manager',
            'role' => 'ops_mgr',
        ]);

        return StockRequest::create([
            'request_type' => StockRequest::TYPE_PICKUP,
            'requested_by' => $ops->id,
            'request_date' => now(),
        ]);
    }

    protected function line(StockRequest $request, int $qty, string $product = 'Diesel'): StockRequestLine
    {
        return StockRequestLine::create([
            'stock_request_id' => $request->id,
            'product' => $product,
            'qty_requested' => $qty,
            'qty_received' => 0,
        ]);
    }

    public function test_it_reports_a_snapped_quantity_without_changing_it(): void
    {
        $request = $this->request();
        $line = $this->line($request, 10001);

        $this->artisan('stock-requests:fix-quantities')
            ->expectsOutputToContain('10,001')
            ->expectsOutputToContain('Nothing was written.')
            ->assertSuccessful();

        // Dry run must not touch anything.
        $this->assertSame(10001, $line->fresh()->qty_requested);
    }

    public function test_apply_corrects_a_snapped_quantity(): void
    {
        $request = $this->request();
        $line = $this->line($request, 10001);

        $this->artisan('stock-requests:fix-quantities', ['--apply' => true])
            ->assertSuccessful();

        $this->assertSame(10000, $line->fresh()->qty_requested);
    }

    public function test_round_quantities_are_left_alone(): void
    {
        $request = $this->request();
        $diesel = $this->line($request, 10000);
        $unleaded = $this->line($request, 5000, 'Unleaded');
        $odd = $this->line($request, 12345, 'Premium');

        $this->artisan('stock-requests:fix-quantities', ['--apply' => true])
            ->assertSuccessful();

        $this->assertSame(10000, $diesel->fresh()->qty_requested);
        $this->assertSame(5000, $unleaded->fresh()->qty_requested);
        $this->assertSame(12345, $odd->fresh()->qty_requested);
    }

    /**
     * The signature is deliberately broad, so anything ending 01 is listed for
     * a human to confirm rather than corrected silently.
     */
    public function test_a_genuine_quantity_ending_in_01_is_listed_not_hidden(): void
    {
        $request = $this->request();
        $line = $this->line($request, 101, 'Unleaded');

        $this->artisan('stock-requests:fix-quantities')
            ->expectsOutputToContain('genuine')
            ->assertSuccessful();

        // Reported, but a dry run leaves it alone.
        $this->assertSame(101, $line->fresh()->qty_requested);
    }

    public function test_a_single_litre_is_never_flagged(): void
    {
        $request = $this->request();
        $line = $this->line($request, 1, 'Premium');

        $this->artisan('stock-requests:fix-quantities')
            ->expectsOutputToContain('Nothing looks snapped')
            ->assertSuccessful();

        $this->assertSame(1, $line->fresh()->qty_requested);
    }

    public function test_it_records_an_audit_entry(): void
    {
        $request = $this->request();
        $this->line($request, 10001);

        $before = \DB::table('audit_logs')->count();

        $this->artisan('stock-requests:fix-quantities', ['--apply' => true])
            ->assertSuccessful();

        $this->assertSame($before + 1, \DB::table('audit_logs')->count());
        $this->assertStringContainsString(
            '10,001 L -> 10,000 L',
            \DB::table('audit_logs')->latest('id')->value('description')
        );
    }

    public function test_the_correction_can_be_attributed_to_an_operator(): void
    {
        $request = $this->request();
        $this->line($request, 10001);

        $operator = Admin::create([
            'username' => 'vp_'.uniqid(),
            'password' => bcrypt('secret'),
            'name' => 'Lorraine Bamba',
            'role' => 'vp',
        ]);

        $this->artisan('stock-requests:fix-quantities', [
            '--apply' => true,
            '--admin' => $operator->id,
        ])->assertSuccessful();

        $this->assertSame(
            $operator->id,
            (int) \DB::table('audit_logs')->latest('id')->value('admin_id')
        );
    }

    public function test_it_keeps_the_legacy_row_in_step(): void
    {
        $ops = Admin::create([
            'username' => 'ops_'.uniqid(),
            'password' => bcrypt('secret'),
            'name' => 'Depot Manager',
            'role' => 'ops_mgr',
        ]);

        $legacy = PurchaseOrder::create([
            'po_type' => 'STANDARD_REPLENISHMENT',
            'qty_ordered' => 10001,
            'request_status' => 'REQUESTED',
            'status' => 'Pending',
            'requested_by' => $ops->id,
            'request_date' => now(),
            'requested_products' => [['product' => 'Diesel', 'quantity' => 10001]],
        ]);

        $request = StockRequest::create([
            'request_type' => StockRequest::TYPE_PICKUP,
            'requested_by' => $ops->id,
            'request_date' => now(),
            'legacy_purchase_order_id' => $legacy->id,
        ]);
        $this->line($request, 10001);

        $this->artisan('stock-requests:fix-quantities', ['--apply' => true])
            ->assertSuccessful();

        $legacy->refresh();

        $this->assertSame(10000, $legacy->requested_products[0]['quantity']);
        $this->assertSame(10000, (int) $legacy->qty_ordered);
    }

    public function test_running_twice_changes_nothing_more(): void
    {
        $request = $this->request();
        $line = $this->line($request, 10001);

        $this->artisan('stock-requests:fix-quantities', ['--apply' => true])->assertSuccessful();
        $this->artisan('stock-requests:fix-quantities', ['--apply' => true])
            ->expectsOutputToContain('Nothing looks snapped')
            ->assertSuccessful();

        $this->assertSame(10000, $line->fresh()->qty_requested);
    }

    /**
     * The live bug: the form must never snap a round value again.
     */
    public function test_the_pop_up_input_cannot_snap_a_round_value(): void
    {
        $ops = Admin::create([
            'username' => 'ops_'.uniqid(),
            'password' => bcrypt('secret'),
            'name' => 'Depot Manager',
            'role' => 'ops_mgr',
        ]);

        $html = $this->actingAs($ops)
            ->get(route('wetstock.stock-requests.index'))
            ->assertOk()
            ->getContent();

        $this->assertMatchesRegularExpression(
            '/name="lines\[__INDEX__\]\[qty\]"[^>]*step="1"/',
            $html,
            'The quantity input must keep step="1" or the browser snaps round values again.'
        );
    }

    public function test_the_stored_quantity_is_exactly_what_was_typed(): void
    {
        $ops = Admin::create([
            'username' => 'ops_'.uniqid(),
            'password' => bcrypt('secret'),
            'name' => 'Depot Manager',
            'role' => 'ops_mgr',
        ]);

        $warehouse = \App\Models\Warehouse::create(['name' => 'Guard Depot '.rand(1000, 9999)]);

        $this->actingAs($ops)->post(route('wetstock.stock-requests.store'), [
            'warehouse_id' => $warehouse->id,
            'request_type' => 'PICKUP',
            'lines' => [['product' => 'Diesel', 'qty' => 10000]],
        ])->assertSessionHasNoErrors();

        $this->assertSame(10000, StockRequest::latest('id')->firstOrFail()->qty_requested);
    }
}