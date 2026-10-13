<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\StockRequest;
use App\Models\StockRequestLine;
use App\Models\Warehouse;
use Tests\TestCase;

/**
 * The Create Request pop-up silently did nothing when a send could not be
 * saved. store() redirected back without ?create=1, so the pop-up stayed shut
 * and the only copy of the error was rendered inside it. The page has to come
 * back open, and it has to say what went wrong where the user is looking.
 */
class StockRequestPopUpSubmitTest extends TestCase
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
     * A payload that passes validation but yields no usable product row.
     *
     * qty is 0 rather than '' on purpose: an empty string is not an integer, so
     * it would be caught by the rules and never reach the line handling this
     * test is about.
     *
     * @return array<string, mixed>
     */
    protected function badPayload(): array
    {
        return [
            'warehouse_id' => $this->warehouse->id,
            'request_type' => StockRequest::TYPE_PICKUP,
            'lines' => [
                ['product' => '', 'qty' => 0],
            ],
        ];
    }

    protected function request(): StockRequest
    {
        $request = StockRequest::create([
            'request_type' => StockRequest::TYPE_PICKUP,
            'warehouse_id' => $this->warehouse->id,
            'qty_requested' => 50000,
            'status' => StockRequest::STATUS_REQUESTED,
            'requested_by' => $this->admin->id,
        ]);

        StockRequestLine::create([
            'stock_request_id' => $request->id,
            'product' => 'Diesel',
            'qty_requested' => 50000,
        ]);

        return $request;
    }

    // ---- The pop-up comes back open ----------------------------------------

    public function test_a_request_that_cannot_be_saved_reopens_the_pop_up(): void
    {
        $response = $this->actingAs($this->admin)
            ->from(route('wetstock.stock-requests.index'))
            ->post(route('wetstock.stock-requests.store'), $this->badPayload());

        // Without create=1 the redirect lands on a page with the pop-up shut,
        // which is exactly how a failed send used to look like no send at all.
        $response->assertRedirectContains('create=1');
    }

    public function test_a_failed_send_says_why_outside_the_closed_pop_up(): void
    {
        $this->actingAs($this->admin)
            ->from(route('wetstock.stock-requests.index'))
            ->post(route('wetstock.stock-requests.store'), $this->badPayload());

        $html = $this->actingAs($this->admin)
            ->get(route('wetstock.stock-requests.index', ['create' => 1]))
            ->assertOk()
            ->getContent();

        // The message was always in the DOM, inside the shut pop-up, which is
        // why the page looked unchanged. It has to appear above the pop-up,
        // where the user is actually looking.
        $aboveModal = strstr($html, '<div class="modal fade" id="requestFormModal"', true);

        $this->assertIsString($aboveModal, 'The request pop-up should still be on the page.');
        $this->assertStringContainsString(
            'Add at least one product with a quantity',
            $aboveModal,
            'A failed send must say so on the page, not only inside the closed pop-up.'
        );
    }

    public function test_an_edit_that_cannot_be_saved_reopens_the_pop_up_in_edit_mode(): void
    {
        $request = $this->request();

        $response = $this->actingAs($this->admin)
            ->from(route('wetstock.stock-requests.index'))
            ->post(route('wetstock.stock-requests.update', $request), $this->badPayload());

        $response->assertRedirectContains('edit='.$request->id);
    }

    public function test_a_failed_edit_reports_the_reason(): void
    {
        $request = $this->request();

        $this->actingAs($this->admin)
            ->from(route('wetstock.stock-requests.index'))
            ->post(route('wetstock.stock-requests.update', $request), $this->badPayload());

        $html = $this->actingAs($this->admin)
            ->get(route('wetstock.stock-requests.index', ['edit' => $request->id]))
            ->assertOk()
            ->getContent();

        $aboveModal = strstr($html, '<div class="modal fade" id="requestFormModal"', true);

        $this->assertIsString($aboveModal);
        $this->assertStringContainsString('Add at least one product with a quantity', $aboveModal);
    }

    public function test_an_edit_cannot_be_saved_with_no_product_lines(): void
    {
        $request = $this->request();

        // store() refuses this. update() did not, so emptying the pop-up deleted
        // every line on the request and still reported success: the request was
        // left with nothing to buy.
        $this->actingAs($this->admin)
            ->post(route('wetstock.stock-requests.update', $request), $this->badPayload())
            ->assertSessionHasErrors('lines');

        $this->assertSame(
            1,
            $request->fresh()->lines()->count(),
            'The existing line must survive a rejected edit.'
        );
    }

    public function test_the_page_carries_the_edit_id_it_should_reopen(): void
    {
        $request = $this->request();

        $html = $this->actingAs($this->admin)
            ->get(route('wetstock.stock-requests.index', ['edit' => $request->id]))
            ->assertOk()
            ->getContent();

        // The pop-up script has to know which request to reopen on, otherwise
        // ?edit= lands on a page with the pop-up shut, same as before.
        $this->assertStringContainsString(
            'data-request-id="'.$request->id.'"',
            $html,
            'The pop-up script must be told which request to reopen.'
        );
        $this->assertStringContainsString('data-request-mode="edit"', $html);
    }

    // ---- The form survives without JavaScript ------------------------------

    public function test_the_request_form_posts_somewhere_without_javascript(): void
    {
        $html = $this->actingAs($this->admin)
            ->get(route('wetstock.stock-requests.index'))
            ->assertOk()
            ->getContent();

        // The action is set by script at the moment the pop-up opens. If that
        // script never runs, an un-actioned form posts to the current GET-only
        // URL and the browser answers 405. The lookahead keeps this independent
        // of attribute order.
        $this->assertMatchesRegularExpression(
            '/<form(?=[^>]*\bid="requestForm")[^>]*\baction="[^"]+"/',
            $html,
            'The pop-up form needs a real action so it works without JavaScript.'
        );
    }

    // ---- Regression --------------------------------------------------------

    public function test_a_good_send_still_creates_the_request(): void
    {
        $this->actingAs($this->admin)
            ->post(route('wetstock.stock-requests.store'), [
                'warehouse_id' => $this->warehouse->id,
                'request_type' => StockRequest::TYPE_PICKUP,
                'lines' => [['product' => 'Diesel', 'qty' => 25000]],
            ])
            ->assertSessionHas('success');

        $this->assertDatabaseHas('stock_requests', ['warehouse_id' => $this->warehouse->id]);
    }
}
