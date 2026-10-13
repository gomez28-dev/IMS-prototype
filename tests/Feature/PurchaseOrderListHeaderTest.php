<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\Supplier;
use Tests\TestCase;

/**
 * The Purchase Orders list keeps one button in its header (Create P.O.) plus a
 * search field styled like the one on M1. The status filter, the Filter button,
 * and the two links that duplicate the left navigation are gone.
 */
class PurchaseOrderListHeaderTest extends TestCase
{
    protected Admin $purchasing;

    protected function setUp(): void
    {
        parent::setUp();

        $this->purchasing = Admin::create([
            'username' => 'purchasing_'.uniqid(),
            'password' => bcrypt('secret'),
            'name' => 'Rica Esaga',
            'role' => 'purchasing',
        ]);

        $supplier = Supplier::create([
            'company_name' => 'List Header Refinery '.rand(1000, 9999),
            'location' => 'Limay Terminal',
        ]);

        $po = PurchaseOrder::create([
            'po_number' => 'PO-LIST-'.rand(1000, 9999),
            'po_type' => PurchaseOrder::TYPE_STANDARD_REPLENISHMENT,
            'supplier_id' => $supplier->id,
            'supplier_name' => $supplier->company_name,
            'po_date' => now()->toDateString(),
            'qty_ordered' => 1000,
        ]);
        PurchaseOrderItem::create([
            'purchase_order_id' => $po->id,
            'product' => 'Diesel',
            'quantity_ordered' => 1000,
        ]);
    }

    protected function listHtml(): string
    {
        return $this->actingAs($this->purchasing)
            ->get(route('stock-orders.purchase-orders.index'))
            ->assertOk()
            ->getContent();
    }

    // ---- What goes ---------------------------------------------------------

    public function test_the_approvals_queue_button_is_gone_from_the_list(): void
    {
        // It lives in the left navigation, where it is role-gated properly.
        $this->assertStringNotContainsString('Approvals Queue', $this->listHtml());
    }

    public function test_the_deliveries_and_atls_button_is_gone_from_the_list(): void
    {
        // Blade leaves literal template text unescaped, so the ampersand here
        // is raw rather than &amp;.
        $this->assertStringNotContainsString('Deliveries & ATLs', $this->listHtml());
    }

    public function test_the_status_filter_is_gone(): void
    {
        $html = $this->listHtml();

        $this->assertStringNotContainsString('All Statuses', $html);
        $this->assertStringNotContainsString('name="status"', $html);
    }

    public function test_the_filter_button_is_gone(): void
    {
        $this->assertStringNotContainsString('>Filter</button>', $this->listHtml());
    }

    // ---- What stays --------------------------------------------------------

    public function test_create_po_stays_in_the_header(): void
    {
        $this->assertStringContainsString('Create P.O.', $this->listHtml());
    }

    public function test_the_search_field_sits_beside_create_po_in_the_header(): void
    {
        $html = $this->listHtml();

        $this->assertStringContainsString('name="search"', $html);

        // M1's signature: a bordered-off search glyph inside an input group,
        // with the field butted up against it.
        $this->assertMatchesRegularExpression(
            '/<span class="input-group-text[^"]*">\s*<i class="bi bi-search/',
            $html,
            'The search box should carry the same glyph wrapper as the one on M1.'
        );
        $this->assertMatchesRegularExpression(
            '/<input[^>]*name="search"[^>]*border-start-0/',
            $html,
            'M1 removes the left border where the glyph meets the field.'
        );
    }

    public function test_search_still_works(): void
    {
        $target = PurchaseOrder::first();

        $html = $this->actingAs($this->purchasing)
            ->get(route('stock-orders.purchase-orders.index', ['search' => $target->po_number]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString($target->po_number, $html);

        $html = $this->actingAs($this->purchasing)
            ->get(route('stock-orders.purchase-orders.index', ['search' => 'ZZZ-NO-SUCH-PO']))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString($target->po_number, $html);
    }
}
