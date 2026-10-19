<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Supplier;
use App\Models\Warehouse;
use Tests\TestCase;

/**
 * Which sidebar entry is highlighted on which page.
 *
 * The desktop sidebar marked Purchase Orders with routeIs('stock-orders.create*'),
 * a glob that also matched stock-orders.create-fuel-trade-po - the Issue ATL form
 * that belongs to Sales Orders. The mobile drawer had the mirror problem: it
 * matched only the two list patterns, so on the ATL form it highlighted neither.
 *
 * Both navs are asserted here, because they are separate markup that drifted
 * apart, and fixing one while leaving the other stale is how the discrepancy
 * reappears.
 */
class StockOrdersNavHighlightTest extends TestCase
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

    protected function order(): Order
    {
        $order = Order::create([
            'so_number' => (string) rand(1000, 9999),
            'account' => 'Nav Test Co.',
            'date' => now()->toDateString(),
            'location' => 'Valenzuela',
            'status' => 'Active',
            'qty_ordered' => 1000,
            'clearing_status' => 'Approved',
            'fulfillment_type' => Order::FULFILLMENT_FUEL_TRADE,
            'order_category' => Order::CATEGORY_CLIENT_ORDER,
        ]);

        OrderItem::create([
            'order_id' => $order->id,
            'product_type' => 'D',
            'qty' => 1000,
        ]);

        return $order;
    }

    protected function supplierPo(): string
    {
        $supplier = Supplier::create([
            'company_name' => 'Nav Refinery '.rand(1000, 9999),
            'location' => 'Limay Terminal',
        ]);

        $po = \App\Models\PurchaseOrder::create([
            'po_number' => 'PO-NAV-'.rand(1000, 9999),
            'po_type' => \App\Models\PurchaseOrder::TYPE_STANDARD_REPLENISHMENT,
            'supplier_id' => $supplier->id,
            'supplier_name' => $supplier->company_name,
            'po_date' => now()->toDateString(),
            'qty_ordered' => 5000,
            'request_status' => 'RECEIVED',
        ]);

        \App\Models\PurchaseOrderItem::create([
            'purchase_order_id' => $po->id,
            'product' => 'Diesel',
            'quantity_ordered' => 5000,
        ]);

        return $po->po_number;
    }

    /**
     * The sidebar and mobile-drawer anchors for a nav target.
     *
     * Scoped to anchors carrying a nav class. Breadcrumb links and in-page
     * buttons point at the same URLs, so matching on href alone picks up
     * `<li>` anchors and page buttons that are not the nav at all.
     *
     * @return array<int, string>
     */
    protected function anchorsTo(string $html, string $href): array
    {
        preg_match_all(
            '/<a\b(?=[^>]*\bclass="[^"]*\b(?:sidebar-nav-link|nav-link)\b)[^>]*href="[^"]*'
                .preg_quote($href, '/') . '"[^>]*>/i',
            $html,
            $matches
        );

        return $matches[0];
    }

    protected function assertNoNavHighlighted(string $html, string $message = ''): void
    {
        foreach (['purchase-orders.index', 'sales-orders.index', 'wet-stock-requests.index'] as $route) {
            foreach ($this->anchorsTo($html, route('stock-orders.'.$route)) as $tag) {
                $this->assertDoesNotMatchRegularExpression(
                    '/\bactive\b/',
                    $tag,
                    $message ?: "Nothing should be highlighted in the {$route} nav."
                );
            }
        }
    }

    public function test_the_issue_atl_page_highlights_sales_orders(): void
    {
        $order = $this->order();

        $html = $this->actingAs($this->admin)
            ->get(route('stock-orders.create-fuel-trade-po', $order->id))
            ->assertOk()
            ->getContent();

        $sales = $this->anchorsTo($html, route('stock-orders.sales-orders.index'));
        $purchase = $this->anchorsTo($html, route('stock-orders.purchase-orders.index'));

        $this->assertNotEmpty($sales, 'The Sales Orders nav entry should be present.');
        $this->assertNotEmpty($purchase, 'The Purchase Orders nav entry should be present.');

        foreach ($sales as $tag) {
            $this->assertMatchesRegularExpression(
                '/\bactive\b/',
                $tag,
                'The Issue ATL form belongs to Sales Orders, so that entry is the one '
                .'highlighted - in the sidebar and the mobile drawer alike.'
            );
        }

        foreach ($purchase as $tag) {
            $this->assertDoesNotMatchRegularExpression(
                '/\bactive\b/',
                $tag,
                'Purchase Orders must not stay highlighted on a Sales Order page.'
            );
        }
    }

    public function test_the_purchase_order_form_still_highlights_purchase_orders(): void
    {
        $html = $this->actingAs($this->admin)
            ->get(route('stock-orders.create-supplier-po'))
            ->assertOk()
            ->getContent();

        foreach ($this->anchorsTo($html, route('stock-orders.purchase-orders.index')) as $tag) {
            $this->assertMatchesRegularExpression(
                '/\bactive\b/',
                $tag,
                'The Create Supplier PO form is a Purchase Orders page.'
            );
        }

        foreach ($this->anchorsTo($html, route('stock-orders.sales-orders.index')) as $tag) {
            $this->assertDoesNotMatchRegularExpression('/\bactive\b/', $tag);
        }
    }

    public function test_each_list_page_highlights_its_own_entry(): void
    {
        $cases = [
            ['stock-orders.purchase-orders.index', 'stock-orders.sales-orders.index'],
            ['stock-orders.sales-orders.index', 'stock-orders.purchase-orders.index'],
            ['stock-orders.wet-stock-requests.index', 'stock-orders.purchase-orders.index'],
        ];

        foreach ($cases as [$own, $other]) {
            $html = $this->actingAs($this->admin)
                ->get(route($own))
                ->assertOk()
                ->getContent();

            foreach ($this->anchorsTo($html, route($own)) as $tag) {
                $this->assertMatchesRegularExpression(
                    '/\bactive\b/',
                    $tag,
                    "{$own} should highlight itself."
                );
            }

            foreach ($this->anchorsTo($html, route($other)) as $tag) {
                $this->assertDoesNotMatchRegularExpression(
                    '/\bactive\b/',
                    $tag,
                    "{$own} must not also highlight {$other}."
                );
            }
        }
    }

    public function test_a_cancelled_sales_order_page_highlights_nothing(): void
    {
        // Every route returns 404 for a cancelled order, so there is no page and
        // no nav to assert. This documents that rather than leaving it implied.
        $order = $this->order();
        $order->update(['status' => 'Cancelled']);

        $this->actingAs($this->admin)
            ->get(route('stock-orders.sales-orders.show', $order->id))
            ->assertNotFound();
    }

    // ---- The mislabelled dashboard button ---------------------------------

    public function test_the_sales_orders_page_no_longer_offers_a_dashboard_button(): void
    {
        // It linked to stock-orders.purchase-orders.index - the Purchase Orders
        // page - under a label promising somewhere else.
        $html = $this->actingAs($this->admin)
            ->get(route('stock-orders.sales-orders.index'))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('Stock Orders Dashboard', $html);
        $this->assertStringNotContainsString('bi-speedometer2', $html);
    }

    public function test_the_other_dashboard_button_is_in_unreachable_dead_code(): void
    {
        // A second "Stock Orders Dashboard" button exists in
        // resources/views/wetstock/atl/index.blade.php. It is not a live page:
        // AtlController has no route in routes/web.php at all, and
        // stock-orders.atls.index is a redirect to the Sales Orders list. So it
        // was reported rather than changed - removing a button nobody can reach
        // would be noise, and deleting the dead view is a separate call.
        $this->assertFileExists(
            base_path('resources/views/wetstock/atl/index.blade.php')
        );
        $this->assertStringContainsString(
            'Stock Orders Dashboard',
            (string) file_get_contents(base_path('resources/views/wetstock/atl/index.blade.php'))
        );

        // The proof it is unreachable: the route it would answer to redirects.
        $this->actingAs($this->admin)
            ->get(route('stock-orders.atls.index'))
            ->assertRedirect(route('stock-orders.sales-orders.index'));

        $this->assertStringNotContainsString(
            'AtlController',
            (string) file_get_contents(base_path('routes/web.php')),
            'If AtlController is ever routed, that dead page becomes live and the '
            .'second dashboard button needs the same treatment.'
        );
    }

    public function test_the_sales_orders_page_still_renders_its_title_and_cards(): void
    {
        // The header lost a sibling column; make sure the title survived it.
        $html = $this->actingAs($this->admin)
            ->get(route('stock-orders.sales-orders.index'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Sales Orders Needing an ATL', $html);
        $this->assertStringContainsString('Orders Listed', $html);
    }
}