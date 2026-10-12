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
}