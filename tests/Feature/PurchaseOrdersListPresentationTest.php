<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\AtlAllocation;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderDelivery;
use App\Models\PurchaseOrderItem;
use App\Models\Supplier;
use Tests\TestCase;

/**
 * Four corrections to the Purchase Orders pages, all from the same screenshot
 * pass.
 *
 * The search and Create P.O. sat in the header beside the page title, which is
 * where they read as page-wide chrome rather than as controls for the table
 * below; the supervisor wants them sitting over the columns they affect.
 *
 * The Create form's header carried a "Purchasing Allocation Pool" badge, and
 * its price column was headed and prefixed with a garbled peso sign - three
 * bytes of UTF-8 that had been round-tripped through Windows-1252, so the page
 * rendered "a double-encoded peso sign".
 *
 * And the list's Status column showed two labels drawn from the same idea: a
 * numbered request_status badge over a computed_status line. The user elected to
 * keep computed_status and drop the numbered badge, accepting that an approved
 * PO then reads "Pending" - the Print button replacing Edit is the remaining
 * approval signal in the row.
 */
class PurchaseOrdersListPresentationTest extends TestCase
{
    protected Admin $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = Admin::create([
            'username' => 'admin_'.uniqid(),
            'password' => bcrypt('secret'),
            'name' => 'System Administrator',
            'role' => 'admin',
        ]);
    }

    protected function po(
        string $number,
        string $requestStatus = 'CONFIRMED',
        array $products = ['Diesel' => 50000]
    ): PurchaseOrder {
        $supplier = Supplier::create([
            'company_name' => 'Petron '.rand(1000, 9999),
            'location' => 'Bataan',
        ]);

        $po = PurchaseOrder::create([
            'po_number' => $number,
            'po_type' => PurchaseOrder::TYPE_STANDARD_REPLENISHMENT,
            'supplier_id' => $supplier->id,
            'supplier_name' => $supplier->company_name,
            'supplier_location' => 'Bataan',
            'po_date' => now()->toDateString(),
            'qty_ordered' => array_sum($products),
            'request_status' => $requestStatus,
        ]);

        foreach ($products as $product => $qty) {
            PurchaseOrderItem::create([
                'purchase_order_id' => $po->id,
                'product' => $product,
                'quantity_ordered' => $qty,
                'unit_price' => 80.00,
            ]);
        }

        return $po;
    }

    /**
     * A PO whose whole ordered volume has already been drawn, which is what
     * computed_status reads as Fulfilled.
     *
     * The balance is taken off the PO's own item rows via an allocation, not
     * from the delivery's summary column, so that is how this has to be set up.
     */
    protected function drawnDownPo(string $number): PurchaseOrder
    {
        $po = $this->po($number, 'FOR_DELIVERY', ['Diesel' => 1000]);

        $delivery = PurchaseOrderDelivery::create([
            'purchase_order_id' => $po->id,
            'delivery_channel' => 'SUPPLIER_STOCKS_DELIVERY',
            'order_type' => 'DELIVERY',
            'atl_type' => 'DITC_ATL',
            'atl_number' => 'ATL-'.uniqid(),
            'product' => 'Diesel',
            'qty_to_receive' => 1000,
            'receiving_date' => now()->addDay(),
            'status' => 'Completed',
        ]);

        AtlAllocation::create([
            'purchase_order_delivery_id' => $delivery->id,
            'purchase_order_id' => $po->id,
            'purchase_order_item_id' => $po->items()->where('product', 'Diesel')->value('id'),
            'product' => 'Diesel',
            'quantity' => 1000,
        ]);

        return $po;
    }

    protected function listHtml(): string
    {
        return $this->actingAs($this->admin)
            ->get(route('stock-orders.purchase-orders.index'))
            ->assertOk()
            ->getContent();
    }

    protected function formHtml(): string
    {
        return $this->actingAs($this->admin)
            ->get(route('stock-orders.create-supplier-po'))
            ->assertOk()
            ->getContent();
    }

    // ---- Task 4: the toolbar sits over the table it controls ----------------

    public function test_the_search_input_and_button_sit_above_the_table(): void
    {
        $html = $this->listHtml();

        $searchAt = strpos($html, 'name="search"');
        $tableAt = strpos($html, '<table');

        $this->assertIsInt($searchAt);
        $this->assertIsInt($tableAt);
        $this->assertLessThan(
            $tableAt,
            $searchAt,
            'The search belongs between the cards and the table it filters.'
        );

        // Both controls, and they come after the header block rather than
        // inside it.
        $this->assertGreaterThan($searchAt, strpos($html, '>Search</button>'));
        $this->assertGreaterThan(
            strpos($html, '<h3 class="fw-bold text-dark mb-0">'),
            $searchAt,
            'It has left the page header.'
        );
    }

    public function test_create_po_sits_above_the_action_column(): void
    {
        $html = $this->listHtml();

        $createAt = strpos($html, 'create-supplier-po');
        $tableAt = strpos($html, '<table');
        $actionAt = strpos($html, '>Action<');

        $this->assertIsInt($createAt);
        $this->assertLessThan($tableAt, $createAt, 'It sits above the table.');
        $this->assertIsInt($actionAt);
    }

    public function test_the_search_still_filters(): void
    {
        $this->po('DITC-KEEP-0001');
        $this->po('DITC-KEEP-0002');

        $html = $this->actingAs($this->admin)
            ->get(route('stock-orders.purchase-orders.index', ['search' => '0001']))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('DITC-KEEP-0001', $html);
        $this->assertStringNotContainsString('DITC-KEEP-0002', $html);
    }

    // ---- Task 5: the badge is gone -----------------------------------------

    public function test_the_allocation_pool_badge_is_gone(): void
    {
        $this->assertStringNotContainsString('Purchasing Allocation Pool', $this->formHtml());
    }

    // ---- Task 6: the peso sign ---------------------------------------------

    public function test_the_price_column_is_headed_with_a_peso_sign(): void
    {
        $this->assertStringContainsString('Price / Liter (&#8369;)', $this->formHtml());
    }

    public function test_the_price_inputs_are_prefixed_with_a_peso_sign(): void
    {
        $html = $this->formHtml();

        // Two prefixes today: the row already on the page, and the one the
        // Add Product Row button clones into the DOM.
        $this->assertSame(
            2,
            substr_count($html, '<span class="input-group-text">&#8369;</span>'),
            'Both the rendered row and the cloned row need the fix.'
        );
    }

    public function test_no_garbled_peso_sign_survives_anywhere(): void
    {
        // Assembled from code points rather than pasted, so this file does not
        // itself become a second place the mojibake has to be hunted down.
        $mojibake = "\u{00E2}\u{201A}\u{00B1}";

        foreach ([
            'resources/views/wetstock/stock-orders/create-po.blade.php',
            'resources/views/wetstock/purchase-orders/index.blade.php',
            'resources/views/wetstock/purchase-orders/show.blade.php',
            'resources/views/dashboard.blade.php',
        ] as $file) {
            $this->assertStringNotContainsString(
                $mojibake,
                file_get_contents(base_path($file)),
                "{$file} still holds the double-encoded peso sign."
            );
        }
    }

    public function test_the_peso_sign_is_ascii_in_source(): void
    {
        // So another editor round-trip through Windows-1252 cannot re-corrupt
        // it the way a literal peso character was.
        $this->assertStringContainsString(
            '&#8369;',
            file_get_contents(base_path('resources/views/wetstock/stock-orders/create-po.blade.php'))
        );
    }

    // ---- Task 7: one status label, and the table's own font -----------------

    public function test_the_numbered_status_badge_is_gone(): void
    {
        $this->po('DITC-STATUS-0001', 'CONFIRMED');

        $html = $this->listHtml();

        foreach (['1. REQUESTED', '2. RECEIVED', '3. CONFIRMED', '4. FOR DELIVERY', '5. COMPLETED'] as $badge) {
            $this->assertStringNotContainsString($badge, $html);
        }
    }

    public function test_the_computed_status_label_remains(): void
    {
        $this->po('DITC-STATUS-0002', 'CONFIRMED');

        $html = $this->listHtml();

        // This is the label the user chose to keep.
        $this->assertStringContainsString('Pending', $html);
    }

    public function test_a_fulfilled_po_reads_fulfilled(): void
    {
        $this->drawnDownPo('DITC-FULL-0001');

        $this->assertStringContainsString('Fulfilled', $this->listHtml());
    }

    public function test_the_list_no_longer_uses_a_monospace_font(): void
    {
        $this->po('DITC-FONT-0001');

        $this->assertStringNotContainsString('font-monospace', $this->listHtml());
    }

    public function test_the_qty_and_price_cells_match_the_dashboard_pattern(): void
    {
        $this->po('DITC-SHAPE-0001');

        $html = $this->listHtml();

        // One line per product, keyed by its initial, as the Orders Dashboard
        // does - rather than a column of monospace figures.
        $this->assertStringContainsString('pl-block', $html);
        $this->assertStringContainsString('pl-row', $html);
        $this->assertStringContainsString('pl-key', $html);
    }

    public function test_the_detail_page_is_untouched(): void
    {
        // Task 7 is scoped to the list. The PO detail page keeps its own layout.
        $po = $this->po('DITC-DETAIL-0001');

        $this->actingAs($this->admin)
            ->get(route('stock-orders.purchase-orders.show', $po->id))
            ->assertOk()
            ->assertSee('Total Qty. Ordered', false);
    }
}