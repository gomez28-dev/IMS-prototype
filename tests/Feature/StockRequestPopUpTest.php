<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\StockRequest;
use App\Models\StockRequestLine;
use App\Models\Warehouse;
use Tests\TestCase;

/**
 * The floating request pop-up on the Stock Requests page.
 *
 * The same pop-up serves "+ CREATE REQUEST" and "PEN", it carries several
 * products via "+ ADD PRODUCT", and sending it leaves the requestor in
 * Module 2 rather than pushing them to Module 3.
 */
class StockRequestPopUpTest extends TestCase
{
    protected Admin $opsManager;

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
            'remarks' => 'Tank below reorder point',
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

    // ---- Create -----------------------------------------------------------

    public function test_create_and_edit_share_one_pop_up(): void
    {
        $request = $this->request();

        $html = $this->actingAs($this->opsManager)
            ->get(route('wetstock.stock-requests.index'))
            ->assertOk()
            ->getContent();

        // One modal, opened by both buttons.
        $this->assertSame(1, substr_count($html, 'id="requestFormModal"'));
        $this->assertStringContainsString('data-request-mode="create"', $html);
        $this->assertStringContainsString('data-request-mode="edit"', $html);
        $this->assertStringContainsString('data-request-id="'.$request->id.'"', $html);
    }

    public function test_sending_keeps_the_requestor_in_module_two(): void
    {
        $response = $this->actingAs($this->opsManager)
            ->post(route('wetstock.stock-requests.store'), [
                'warehouse_id' => $this->warehouse->id,
                'request_type' => 'PICKUP',
                'lines' => [['product' => 'Diesel', 'qty' => 5000]],
            ]);

        $response->assertRedirect(route('wetstock.stock-requests.index'));

        // The whiteboard is explicit: the requestor is not sent to Module 3.
        $this->assertStringStartsNotWith(route('stock-orders.index', [], false), $response->headers->get('Location'));
        $this->assertStringNotContainsString('/stock-orders/', $response->headers->get('Location'));
    }

    public function test_the_pop_up_posts_several_products(): void
    {
        $this->actingAs($this->opsManager)
            ->post(route('wetstock.stock-requests.store'), [
                'warehouse_id' => $this->warehouse->id,
                'request_type' => 'PICKUP',
                'lines' => [
                    ['product' => 'Diesel', 'qty' => 10000],
                    ['product' => 'Unleaded', 'qty' => 5000],
                ],
            ])
            ->assertRedirect(route('wetstock.stock-requests.index'));

        $request = StockRequest::latest('id')->firstOrFail();

        $this->assertCount(2, $request->lines);
        $this->assertSame(15000, $request->qty_requested);
    }

    // ---- Edit -------------------------------------------------------------

    public function test_pen_saves_the_edited_details(): void
    {
        $request = $this->request();

        $this->actingAs($this->opsManager)
            ->post(route('wetstock.stock-requests.update', $request->id), [
                'warehouse_id' => $this->warehouse->id,
                'request_type' => 'DELIVERY',
                'date_needed' => '2026-12-01',
                'remarks' => 'Revised after tank survey',
                'lines' => [[
                    'id' => $request->lines->first()->id,
                    'product' => 'Diesel',
                    'qty' => 12000,
                ]],
            ])
            ->assertRedirect(route('wetstock.stock-requests.index'));

        $fresh = $request->fresh();

        $this->assertSame('DELIVERY', $fresh->request_type);
        $this->assertEquals('2026-12-01', $fresh->date_needed->format('Y-m-d'));
        $this->assertSame('Revised after tank survey', $fresh->remarks);
        $this->assertSame(12000, $fresh->qty_requested);
    }

    public function test_pen_can_add_a_product(): void
    {
        $request = $this->request();

        $this->actingAs($this->opsManager)
            ->post(route('wetstock.stock-requests.update', $request->id), [
                'warehouse_id' => $this->warehouse->id,
                'request_type' => 'PICKUP',
                'lines' => [
                    ['id' => $request->lines->first()->id, 'product' => 'Diesel', 'qty' => 10000],
                    ['product' => 'Premium', 'qty' => 2000],
                ],
            ])
            ->assertRedirect(route('wetstock.stock-requests.index'));

        $this->assertCount(2, $request->fresh()->lines);
        $this->assertSame(12000, $request->fresh()->qty_requested);
    }

    public function test_pen_can_remove_a_product(): void
    {
        $request = $this->request([
            ['product' => 'Diesel', 'qty' => 10000],
            ['product' => 'Premium', 'qty' => 2000],
        ]);

        $this->actingAs($this->opsManager)
            ->post(route('wetstock.stock-requests.update', $request->id), [
                'warehouse_id' => $this->warehouse->id,
                'request_type' => 'PICKUP',
                'lines' => [[
                    'id' => $request->lines->firstWhere('product', 'Diesel')->id,
                    'product' => 'Diesel',
                    'qty' => 10000,
                ]],
            ])
            ->assertRedirect(route('wetstock.stock-requests.index'));

        $fresh = $request->fresh();

        $this->assertCount(1, $fresh->lines);
        $this->assertSame('Diesel', $fresh->lines->first()->product);
    }

    /**
     * Editing must never ask for less than has already arrived, or Pending
     * would go negative.
     */
    public function test_pen_cannot_push_pending_below_zero(): void
    {
        $request = $this->request([['product' => 'Diesel', 'qty' => 10000]]);
        $line = $request->lines->first();
        $line->update(['qty_received' => 8000]);

        $this->actingAs($this->opsManager)
            ->post(route('wetstock.stock-requests.update', $request->id), [
                'warehouse_id' => $this->warehouse->id,
                'request_type' => 'PICKUP',
                'lines' => [['id' => $line->id, 'product' => 'Diesel', 'qty' => 100]],
            ])
            ->assertRedirect(route('wetstock.stock-requests.index'));

        $fresh = $line->fresh();

        $this->assertSame(8000, (int) $fresh->qty_requested);
        $this->assertSame(0, $fresh->qty_pending);
    }

    public function test_roles_outside_module_two_cannot_edit_a_request(): void
    {
        $request = $this->request();

        $viewer = Admin::create([
            'username' => 'audit_'.uniqid(),
            'password' => bcrypt('secret'),
            'name' => 'Audit Viewer',
            'role' => 'audit',
        ]);

        $this->actingAs($viewer)
            ->post(route('wetstock.stock-requests.update', $request->id), [
                'warehouse_id' => $this->warehouse->id,
                'request_type' => 'PICKUP',
                'lines' => [['product' => 'Diesel', 'qty' => 1]],
            ])
            ->assertForbidden();

        $this->assertSame(10000, $request->fresh()->qty_requested);
    }

    // ---- View -------------------------------------------------------------

    public function test_the_view_action_opens_the_request_atl_dashboard(): void
    {
        $request = $this->request();

        $html = $this->actingAs($this->opsManager)
            ->get(route('wetstock.stock-requests.index'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString(
            route('stock-orders.wet-stock-requests.atls', $request->id),
            $html
        );
        $this->assertStringNotContainsString('requestViewModal'.$request->id, $html);
        $this->assertStringNotContainsString('> Pick Up<', $html);
        $this->assertStringNotContainsString('> Received<', $html);
    }
}
