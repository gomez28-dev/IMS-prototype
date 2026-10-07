<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\SupplierOrder;
use App\Models\Warehouse;
use Tests\TestCase;

/**
 * Supervisor feedback 2026-10-07, Image 3: the remarks column rendered
 * "â€"" instead of an em dash.
 *
 * Root cause was a double-encoded UTF-8 sequence (C3 A2 E2 82 AC E2 80 9D)
 * left in the Blade source. This guards both affected views so a stray
 * byte sequence cannot be reintroduced.
 */
class MojibakeEncodingTest extends TestCase
{
    /** The double-encoded em dash that rendered as "â€"". */
    private const MOJIBAKE_EM_DASH = "\xC3\xA2\xE2\x82\xAC\xE2\x80\x9D";

    protected function setUp(): void
    {
        parent::setUp();
    }

    protected function viewsUnderTest(): array
    {
        return [
            'wetstock/stock-requests/index.blade.php',
            'wetstock/supplier-orders/index.blade.php',
        ];
    }

    protected function viewContents(string $relative): string
    {
        return file_get_contents(resource_path('views/' . $relative));
    }

    public function test_affected_views_contain_no_double_encoded_em_dash(): void
    {
        foreach ($this->viewsUnderTest() as $view) {
            $contents = $this->viewContents($view);

            $this->assertStringNotContainsString(
                self::MOJIBAKE_EM_DASH,
                $contents,
                "{$view} contains a double-encoded em dash, which renders as mojibake."
            );
        }
    }

    public function test_affected_views_are_valid_utf8(): void
    {
        foreach ($this->viewsUnderTest() as $view) {
            $this->assertTrue(
                mb_check_encoding($this->viewContents($view), 'UTF-8'),
                "{$view} is not valid UTF-8."
            );
        }
    }

    /**
     * No source file anywhere in the app should carry the double-encoded
     * em dash; it is the specific artefact the supervisor reported.
     */
    public function test_no_source_file_carries_the_double_encoded_em_dash(): void
    {
        $offenders = [];

        foreach (['app', 'resources', 'routes', 'config', 'database'] as $root) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator(base_path($root))
            );

            foreach ($iterator as $file) {
                if (! $file->isFile()) {
                    continue;
                }

                if (! in_array($file->getExtension(), ['php', 'js', 'css'], true)) {
                    continue;
                }

                if (str_contains((string) file_get_contents($file->getPathname()), self::MOJIBAKE_EM_DASH)) {
                    $offenders[] = $file->getPathname();
                }
            }
        }

        $this->assertSame([], $offenders, 'These files contain a double-encoded em dash: ' . implode(', ', $offenders));
    }

    /**
     * The em dash placeholder must still be present and correct, so the
     * empty-state fallback was not simply removed.
     */
    public function test_empty_state_placeholders_use_a_real_em_dash(): void
    {
        foreach ($this->viewsUnderTest() as $view) {
            $contents = $this->viewContents($view);

            $this->assertStringContainsString(
                "\xE2\x80\x94",
                $contents,
                "{$view} should still contain a properly encoded em dash (E2 80 94)."
            );
        }
    }

    /**
     * End-to-end: a request with no remarks must render a clean dash.
     */
    public function test_blank_remarks_render_a_clean_dash_on_the_page(): void
    {
        $admin = Admin::create([
            'username' => 'moji_' . uniqid(),
            'password' => bcrypt('secret'),
            'name' => 'Ops Officer',
            'role' => 'ops_admin',
        ]);

        $warehouse = Warehouse::create(['name' => 'Mojibake Site ' . rand(1000, 9999)]);

        \App\Models\PurchaseOrder::create([
            'po_type' => 'STANDARD_REPLENISHMENT',
            'warehouse_id' => $warehouse->id,
            'qty_ordered' => 5000,
            'request_status' => 'REQUESTED',
            'status' => 'Pending',
            'requested_by' => $admin->id,
            'request_date' => now(),
            'requested_products' => [['product' => 'Diesel', 'quantity' => 5000]],
            'remarks' => null,
        ]);

        $html = $this->actingAs($admin)->get(route('wetstock.stock-requests.index'))->getContent();

        $this->assertStringContainsString("\xE2\x80\x94", $html);
        $this->assertStringNotContainsString(self::MOJIBAKE_EM_DASH, $html);
    }

    /**
     * The supplier-orders page had the same defect in its remarks column.
     */
    public function test_supplier_orders_page_renders_blank_remarks_cleanly(): void
    {
        $admin = Admin::create([
            'username' => 'moji2_' . uniqid(),
            'password' => bcrypt('secret'),
            'name' => 'Ops Officer',
            'role' => 'ops_admin',
        ]);

        $warehouse = Warehouse::create(['name' => 'Supplier Site ' . rand(1000, 9999)]);

        SupplierOrder::create([
            'warehouse_id' => $warehouse->id,
            'supplier_name' => 'Petron Bataan',
            'po_number' => 'PO-MOJI-' . rand(1000, 9999),
            'liters' => 5000,
            'status' => 'Pending',
            'created_by' => $admin->id,
            'remarks' => null,
        ]);

        $html = $this->actingAs($admin)->get(route('wetstock.supplier-orders.index'))->getContent();

        $this->assertStringNotContainsString(self::MOJIBAKE_EM_DASH, $html);
        $this->assertStringContainsString("\xE2\x80\x94", $html);
    }
}