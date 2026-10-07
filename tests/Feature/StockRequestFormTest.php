<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Client;
use App\Models\PurchaseOrder;
use App\Models\Warehouse;
use Tests\TestCase;

/**
 * Supervisor feedback 2026-10-07, Request Stock Replenishment form.
 *
 * 1. Product dropdown limited to Unleaded / Diesel / Premium.
 * 2. Site names carry no tank count ("San Simon", not "San Simon (7 Tanks)").
 * 3. Liters starts at 0.
 * 4. step/min no longer makes a round value like 10,000 invalid.
 */
class StockRequestFormTest extends TestCase
{
    protected Admin $opsUser;
    protected Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();

        $this->opsUser = Admin::create([
            'username' => 'reqform_' . uniqid(),
            'password' => bcrypt('secret'),
            'name' => 'Ops Officer',
            'role' => 'ops_admin',
        ]);

        $this->warehouse = Warehouse::first() ?? Warehouse::create(['name' => 'San Simon']);
        Client::first() ?? Client::create(['name' => 'Swift Logistics']);
    }

    protected function createPage(): string
    {
        $response = $this->actingAs($this->opsUser)->get(route('wetstock.stock-requests.create'));
        $response->assertOk();

        return $response->getContent();
    }

    /**
     * The bug from Image 2: min="1" step="100" makes 10,000 invalid because
     * (10000 - 1) % 100 = 99. The browser then rejects the entry and snaps
     * the value to 10,001. Any integer the user actually types must be valid,
     * so the field must not carry a coarser step than 1.
     */
    public function test_liters_field_does_not_reject_round_values(): void
    {
        $html = $this->createPage();

        preg_match('/<input[^>]*name="qty_ordered"[^>]*>/', $html, $m);
        $this->assertNotEmpty($m, 'qty_ordered input not found');

        $input = $m[0];

        if (preg_match('/\bstep="([^"]*)"/', $input, $s)) {
            $this->assertSame(
                '1',
                $s[1],
                'A step coarser than 1 makes round values invalid, which is what silently changed 10,000 into 10,001.'
            );
        }
    }

    public function test_liters_field_starts_at_zero(): void
    {
        $html = $this->createPage();

        preg_match('/<input[^>]*name="qty_ordered"[^>]*>/', $html, $m);
        $this->assertNotEmpty($m);

        // Supervisor: the pre-filled 1,000 doubled to 2,000 when typed over.
        if (preg_match('/\bvalue="([^"]*)"/', $m[0], $v)) {
            $this->assertSame(
                '0',
                $v[1],
                'Liters must start at 0 so a manual entry is not appended to a pre-filled value.'
            );
        }
    }

    public function test_product_dropdown_offers_only_the_three_canonical_products(): void
    {
        $html = $this->createPage();

        preg_match('/<select[^>]*name="product"[^>]*>(.*?)<\/select>/s', $html, $m);
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
        $html = $this->createPage();

        preg_match('/<select[^>]*name="warehouse_id"[^>]*>(.*?)<\/select>/s', $html, $m);
        $this->assertNotEmpty($m, 'warehouse select not found');

        $this->assertStringNotContainsString(
            'Tanks)',
            $m[1],
            'Site names must be plain, e.g. "San Simon" rather than "San Simon (7 Tanks)".'
        );

        $this->assertStringContainsString($this->warehouse->name, $m[1]);
    }

    /**
     * The exact value entered must be the value stored.
     */
    public function test_exact_volume_entered_is_saved_unchanged(): void
    {
        $response = $this->actingAs($this->opsUser)->post(route('wetstock.stock-requests.store'), [
            'warehouse_id' => $this->warehouse->id,
            'po_type' => 'STANDARD_REPLENISHMENT',
            'product' => 'Diesel',
            'qty_ordered' => 10000,
            'date_needed' => now()->addDays(2)->format('Y-m-d'),
            'remarks' => 'Round volume',
        ]);

        $response->assertSessionHasNoErrors();

        $po = PurchaseOrder::latest('id')->first();

        $this->assertSame(10000, (int) $po->qty_ordered);

        // requested_products is cast to array by the model.
        $products = $po->requested_products;
        $this->assertSame('Diesel', $products[0]['product']);
        $this->assertSame(10000, (int) $products[0]['quantity']);
    }

    public function test_all_three_canonical_products_are_accepted(): void
    {
        foreach (['Unleaded', 'Diesel', 'Premium'] as $product) {
            $this->actingAs($this->opsUser)->post(route('wetstock.stock-requests.store'), [
                'warehouse_id' => $this->warehouse->id,
                'po_type' => 'STANDARD_REPLENISHMENT',
                'product' => $product,
                'qty_ordered' => 5000,
                'date_needed' => now()->addDays(2)->format('Y-m-d'),
            ])->assertSessionHasNoErrors();
        }

        $stored = PurchaseOrder::latest('id')->limit(3)->get()
            ->map(fn ($po) => $po->requested_products[0]['product'])->all();

        foreach (['Unleaded', 'Diesel', 'Premium'] as $product) {
            $this->assertContains($product, $stored);
        }
    }
}