<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Client;
use App\Models\StockRequest;
use App\Models\StockRequestLine;
use App\Models\Warehouse;
use Tests\TestCase;

/**
 * The request form is a floating pop-up on the Stock Requests page, carrying
 * the supervisor's rules from 2026-10-07:
 *
 * 1. Product dropdown limited to Unleaded / Diesel / Premium.
 * 2. Site names carry no tank count ("San Simon", not "San Simon (7 Tanks)").
 * 3. Liters starts at 0.
 * 4. step/min no longer makes a round value like 10,000 invalid.
 *
 * It also takes several products at once, and no longer has a dead
 * "Request Category" field.
 */
class StockRequestFormTest extends TestCase
{
    protected Admin $opsUser;

    protected Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();

        $this->opsUser = Admin::create([
            'username' => 'reqform_'.uniqid(),
            'password' => bcrypt('secret'),
            'name' => 'Ops Officer',
            'role' => 'ops_admin',
        ]);

        $this->warehouse = Warehouse::first() ?? Warehouse::create(['name' => 'San Simon']);
        Client::first() ?? Client::create(['name' => 'Swift Logistics']);
    }

    protected function formHtml(): string
    {
        $response = $this->actingAs($this->opsUser)->get(route('wetstock.stock-requests.index'));
        $response->assertOk();

        return $response->getContent();
    }

    public function test_the_form_is_a_pop_up_on_the_requests_page(): void
    {
        $existing = StockRequest::create([
            'request_type' => 'PICKUP',
            'warehouse_id' => $this->warehouse->id,
            'requested_by' => $this->opsUser->id,
            'request_date' => now(),
        ]);
        StockRequestLine::create([
            'stock_request_id' => $existing->id,
            'product' => 'Diesel',
            'qty_requested' => 1000,
        ]);

        $html = $this->formHtml();

        $this->assertStringContainsString('id="requestFormModal"', $html);
        $this->assertStringContainsString('id="requestAddProduct"', $html);
        $this->assertStringContainsString('data-request-mode="create"', $html);
        $this->assertStringContainsString('data-request-mode="edit"', $html);
        // The Pen button hands the pop-up the existing request to pre-fill.
        $this->assertStringContainsString('data-request-id="'.$existing->id.'"', $html);
    }

    public function test_the_old_create_page_route_lands_on_the_requests_page(): void
    {
        $this->actingAs($this->opsUser)
            ->get(route('wetstock.stock-requests.create'))
            ->assertRedirect(route('wetstock.stock-requests.index', ['create' => 1]));
    }

    /**
     * The bug from Image 2: min="1" step="100" makes 10,000 invalid because
     * (10000 - 1) % 100 = 99. The browser then rejects the entry and snaps
     * the value to 10,001. Any integer the user actually types must be valid,
     * so the field must not carry a step coarser than 1.
     */
    public function test_liters_field_does_not_reject_round_values(): void
    {
        preg_match('/<input[^>]*name="lines\[__INDEX__\]\[qty\]"[^>]*>/', $this->formHtml(), $m);
        $this->assertNotEmpty($m, 'quantity input not found');

        if (preg_match('/\bstep="([^"]*)"/', $m[0], $s)) {
            $this->assertSame(
                '1',
                $s[1],
                'A step coarser than 1 makes round values invalid, which is what silently changed 10,000 into 10,001.'
            );
        }
    }

    public function test_liters_field_starts_at_zero(): void
    {
        preg_match('/<input[^>]*name="lines\[__INDEX__\]\[qty\]"[^>]*>/', $this->formHtml(), $m);
        $this->assertNotEmpty($m);

        // Supervisor: the pre-filled 1,000 doubled to 2,000 when typed over.
        // The field now carries no value attribute at all, so the browser
        // starts it blank rather than pre-filled.
        $this->assertStringNotContainsString('value=', $m[0]);
    }

    public function test_product_dropdown_offers_only_the_three_canonical_products(): void
    {
        preg_match('/<select[^>]*name="lines\[__INDEX__\]\[product\]"[^>]*>(.*?)<\/select>/s', $this->formHtml(), $m);
        $this->assertNotEmpty($m, 'product select not found');

        preg_match_all('/value="([^"]*)"/', $m[1], $values);

        $offered = array_values(array_filter($values[1], fn ($v) => $v !== ''));

        $this->assertSame(['Unleaded', 'Diesel', 'Premium'], $offered);

        // Explicitly the products the supervisor asked to remove.
        foreach (['Kerosene', 'Mogas 91', 'Mogas 95'] as $dropped) {
            $this->assertNotContains($dropped, $offered, "{$dropped} should no longer be selectable.");
        }
    }

    public function test_site_names_do_not_show_tank_counts(): void
    {
        preg_match('/<select[^>]*name="warehouse_id"[^>]*>(.*?)<\/select>/s', $this->formHtml(), $m);
        $this->assertNotEmpty($m, 'warehouse select not found');

        $this->assertStringNotContainsString(
            'Tanks)',
            $m[1],
            'Site names must be plain, e.g. "San Simon" rather than "San Simon (7 Tanks)".'
        );

        $this->assertStringContainsString($this->warehouse->name, $m[1]);
    }

    public function test_the_form_offers_a_type_and_no_dead_category_field(): void
    {
        $html = $this->formHtml();

        $this->assertStringContainsString('name="request_type"', $html);
        $this->assertStringContainsString('PICKUP', $html);
        $this->assertStringContainsString('DELIVERY', $html);

        // The category select was silently discarded when requests moved to
        // their own tables, so it must not still be on the form.
        $this->assertStringNotContainsString('name="po_type"', $html);
        $this->assertStringNotContainsString('Request Category', $html);
    }

    /**
     * The exact value entered must be the value stored.
     */
    public function test_exact_volume_entered_is_saved_unchanged(): void
    {
        $response = $this->actingAs($this->opsUser)->post(route('wetstock.stock-requests.store'), [
            'warehouse_id' => $this->warehouse->id,
            'request_type' => 'PICKUP',
            'date_needed' => now()->addDays(2)->format('Y-m-d'),
            'remarks' => 'Round volume',
            'lines' => [['product' => 'Diesel', 'qty' => 10000]],
        ]);

        $response->assertSessionHasNoErrors();

        $request = StockRequest::latest('id')->firstOrFail();

        $this->assertSame(10000, $request->qty_requested);
        $this->assertSame(0, $request->qty_received);
        $this->assertSame(10000, $request->lines->first()->qty_pending);

        // A real REQ-001 style number, not a padded row id.
        $this->assertMatchesRegularExpression('/^REQ-\d{3}$/', $request->request_number);
        $this->assertSame('Diesel', $request->lines->first()->product);
    }

    public function test_all_three_canonical_products_are_accepted(): void
    {
        foreach (['Unleaded', 'Diesel', 'Premium'] as $product) {
            $this->actingAs($this->opsUser)->post(route('wetstock.stock-requests.store'), [
                'warehouse_id' => $this->warehouse->id,
                'request_type' => 'PICKUP',
                'date_needed' => now()->addDays(2)->format('Y-m-d'),
                'lines' => [['product' => $product, 'qty' => 5000]],
            ])->assertSessionHasNoErrors();
        }

        $stored = StockRequest::latest('id')->limit(3)->get()
            ->map(fn ($request) => $request->lines->first()->product)->all();

        foreach (['Unleaded', 'Diesel', 'Premium'] as $product) {
            $this->assertContains($product, $stored);
        }
    }

    /**
     * The pop-up carries "+ ADD PRODUCT", so one request can cover several.
     */
    public function test_one_request_can_carry_several_products(): void
    {
        $this->actingAs($this->opsUser)->post(route('wetstock.stock-requests.store'), [
            'warehouse_id' => $this->warehouse->id,
            'request_type' => 'PICKUP',
            'lines' => [
                ['product' => 'Diesel', 'qty' => 10000],
                ['product' => 'Unleaded', 'qty' => 5000],
                ['product' => 'Premium', 'qty' => 2500],
            ],
        ])->assertSessionHasNoErrors();

        $request = StockRequest::latest('id')->firstOrFail();

        $this->assertCount(3, $request->lines);
        $this->assertSame(17500, $request->qty_requested);
        $this->assertEqualsCanonicalizing(
            ['Diesel', 'Unleaded', 'Premium'],
            $request->lines->pluck('product')->all()
        );
    }

    public function test_a_delivery_request_keeps_its_type(): void
    {
        $this->actingAs($this->opsUser)->post(route('wetstock.stock-requests.store'), [
            'warehouse_id' => $this->warehouse->id,
            'request_type' => 'DELIVERY',
            'lines' => [['product' => 'Diesel', 'qty' => 1000]],
        ])->assertSessionHasNoErrors();

        $request = StockRequest::latest('id')->firstOrFail();

        $this->assertSame('DELIVERY', $request->request_type);
        $this->assertTrue($request->isDelivered());
        $this->assertSame('Delivery', $request->type_label);
    }

    /**
     * Leaving a blank row behind after removing a product should not block
     * sending the form.
     */
    public function test_blank_product_rows_are_ignored(): void
    {
        $this->actingAs($this->opsUser)->post(route('wetstock.stock-requests.store'), [
            'warehouse_id' => $this->warehouse->id,
            'request_type' => 'PICKUP',
            'lines' => [
                ['product' => 'Diesel', 'qty' => 1000],
                ['product' => '', 'qty' => ''],
            ],
        ])->assertSessionHasNoErrors();

        $request = StockRequest::latest('id')->firstOrFail();

        $this->assertCount(1, $request->lines);
        $this->assertSame('Diesel', $request->lines->first()->product);
    }

    public function test_a_request_with_no_usable_product_line_is_rejected(): void
    {
        $this->actingAs($this->opsUser)->post(route('wetstock.stock-requests.store'), [
            'warehouse_id' => $this->warehouse->id,
            'request_type' => 'PICKUP',
            'lines' => [['product' => '', 'qty' => '']],
        ])->assertSessionHasErrors('lines');
    }
}