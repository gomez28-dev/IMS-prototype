<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Client;
use App\Models\Order;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderDelivery;
use App\Models\StockIn;
use App\Models\StorageTank;
use App\Models\Warehouse;
use Tests\TestCase;

/**
 * Module 3 restructure, 2026-10-07.
 *
 * Split the module into three dedicated pages:
 *   Purchase Orders    - creating POs and listing all POs
 *   Sales Orders       - only SO-FT and SO-BUY BACK PICK UP orders
 *   Wet Stock Requests - incoming wet stock replenishment only
 *
 * Each has a main dashboard and a per-row detail view.
 */
class Module3DedicatedPagesTest extends TestCase
{
    protected Admin $purchasing;
    protected Admin $vp;
    protected Admin $sidebarUser;
    protected Warehouse $sanSimon;

    protected function setUp(): void
    {
        parent::setUp();

        $this->purchasing = Admin::create([
            'username' => 'm3_' . uniqid(),
            'password' => bcrypt('secret'),
            'name' => 'Purchasing Officer',
            'role' => 'purchasing',
        ]);

        $this->vp = Admin::create([
            'username' => 'm3vp_' . uniqid(),
            'password' => bcrypt('secret'),
            'name' => 'VP',
            'role' => 'vp',
        ]);

        // The sidebar is inspected as the VP, who can see every entry.
        $this->sidebarUser = $this->vp;

        $this->sanSimon = Warehouse::create(['name' => 'San Simon ' . rand(1000, 9999)]);
        Client::first() ?? Client::create(['name' => 'Swift Logistics']);
    }

    protected function replenishmentPo(array $overrides = []): PurchaseOrder
    {
        return PurchaseOrder::create(array_merge([
            'po_type' => 'STANDARD_REPLENISHMENT',
            'warehouse_id' => $this->sanSimon->id,
            'qty_ordered' => 10000,
            'request_status' => 'REQUESTED',
            'status' => 'Pending',
            'requested_by' => $this->purchasing->id,
            'request_date' => now(),
            'requested_products' => [['product' => 'Diesel', 'quantity' => 10000]],
        ], $overrides));
    }

    protected function fuelTradeOrder(array $overrides = []): Order
    {
        return Order::create(array_merge([
            'account' => 'Swift Logistics',
            'ordered_by' => 'Swift Logistics',
            'location' => $this->sanSimon->name,
            'so_number' => 'SO-FT-' . rand(1000, 9999),
            'date' => now(),
            'qty_ordered' => 10000,
            'price' => 52.00,
            'product' => 'Diesel',
            'order_status' => 'APPROVED',
            'clearing_status' => 'Approved',
            'fulfillment_type' => 'FUEL_TRADE',
        ], $overrides));
    }

    // ---- Purchase Orders ----

    public function test_purchase_orders_page_lists_all_purchase_orders(): void
    {
        $this->replenishmentPo(['po_number' => 'PO-DEDICATED-1']);

        $response = $this->actingAs($this->purchasing)->get(route('stock-orders.purchase-orders.index'));

        $response->assertOk();
        $response->assertSee('PO-DEDICATED-1');
    }

    public function test_purchase_order_detail_page_renders(): void
    {
        $po = $this->replenishmentPo(['po_number' => 'PO-DETAIL-1']);

        $this->actingAs($this->purchasing)
            ->get(route('stock-orders.purchase-orders.show', $po->id))
            ->assertOk();
    }

    /**
     * The requirement: PO detail shows which ATLs have drawn from that PO.
     */
    public function test_purchase_order_detail_lists_the_atls_drawn_from_it(): void
    {
        $po = $this->replenishmentPo(['po_number' => 'PO-ATL-LINK', 'request_status' => 'FOR_DELIVERY']);

        $tank = StorageTank::create([
            'warehouse_id' => $this->sanSimon->id,
            'name' => 'PO Detail Tank ' . rand(1000, 9999),
            'category' => 'storage',
            'max_capacity' => 20000,
            'is_active' => true,
        ]);

        $delivery = PurchaseOrderDelivery::create([
            'purchase_order_id' => $po->id,
            'storage_tank_id' => $tank->id,
            'delivery_channel' => 'SUPPLIER_DOYEN_PICKUP',
            'order_type' => 'PICK_UP',
            'atl_type' => 'DITC_ATL',
            'atl_number' => 'ATL-PO-DETAIL-77',
            'dr_number' => 'DR-PO-DETAIL-77',
            'product' => 'Diesel',
            'qty_to_receive' => 10000,
            'receiving_date' => now(),
            'status' => 'Pending',
        ]);

        $html = $this->actingAs($this->purchasing)
            ->get(route('stock-orders.purchase-orders.show', $po->id))
            ->getContent();

        $this->assertStringContainsString('ATL-PO-DETAIL-77', $html);
        $this->assertStringContainsString('DR-PO-DETAIL-77', $html);
    }

    // ---- Sales Orders ----

    public function test_sales_orders_page_lists_fuel_trade_orders(): void
    {
        $order = $this->fuelTradeOrder();

        $response = $this->actingAs($this->purchasing)->get(route('stock-orders.sales-orders.index'));

        $response->assertOk();
        // The list renders formatted_so_number, which prefixes SO- orders with FT-.
        $response->assertSee($order->formatted_so_number);
        $response->assertSee($order->account);
    }

    public function test_sales_orders_page_includes_buy_back_pickup_orders(): void
    {
        $buyBack = $this->fuelTradeOrder([
            'so_number' => 'SO-BB-' . rand(1000, 9999),
            'fulfillment_type' => 'DEPOT_PICKUP',
            'order_category' => 'BUY_BACK',
        ]);

        $this->actingAs($this->purchasing)
            ->get(route('stock-orders.sales-orders.index'))
            ->assertSee($buyBack->formatted_so_number);
    }

    /**
     * A plain depot delivery order is not a Fuel Trade / Buy Back order and
     * must not appear on the Sales Orders page.
     */
    public function test_sales_orders_page_excludes_other_order_types(): void
    {
        $other = Order::create([
            'account' => 'Swift Logistics',
            'ordered_by' => 'Swift Logistics',
            'location' => $this->sanSimon->name,
            'so_number' => 'SO-OTHER-' . rand(1000, 9999),
            'date' => now(),
            'qty_ordered' => 1000,
            'price' => 52.00,
            'product' => 'Diesel',
            'order_status' => 'APPROVED',
            'clearing_status' => 'Approved',
            'fulfillment_type' => 'DELIVERY',
        ]);

        $this->actingAs($this->purchasing)
            ->get(route('stock-orders.sales-orders.index'))
            ->assertDontSee($other->so_number);
    }

    public function test_sales_order_detail_page_renders(): void
    {
        $order = $this->fuelTradeOrder();

        $this->actingAs($this->purchasing)
            ->get(route('stock-orders.sales-orders.show', $order->id))
            ->assertOk();
    }

    // ---- Wet Stock Requests ----

    public function test_wet_stock_requests_page_lists_replenishment_requests(): void
    {
        $this->replenishmentPo();

        $this->actingAs($this->purchasing)
            ->get(route('stock-orders.wet-stock-requests.index'))
            ->assertOk();
    }

    public function test_wet_stock_request_detail_page_renders_with_a_dashboard(): void
    {
        $po = $this->replenishmentPo(['remarks' => 'Below reorder point']);

        $html = $this->actingAs($this->purchasing)
            ->get(route('stock-orders.wet-stock-requests.show', $po->id))
            ->getContent();

        $this->assertStringContainsString('Below reorder point', $html);
        $this->assertStringContainsString($this->sanSimon->name, $html);
    }

    public function test_wet_stock_request_detail_lists_its_linked_atls(): void
    {
        $po = $this->replenishmentPo(['request_status' => 'FOR_DELIVERY']);

        $tank = StorageTank::create([
            'warehouse_id' => $this->sanSimon->id,
            'name' => 'Request Tank ' . rand(1000, 9999),
            'category' => 'storage',
            'max_capacity' => 20000,
            'is_active' => true,
        ]);

        StockIn::create([
            'storage_tank_id' => $tank->id,
            'admin_id' => $this->purchasing->id,
            'quantity' => 10000,
            'date' => now(),
        ]);

        PurchaseOrderDelivery::create([
            'purchase_order_id' => $po->id,
            'storage_tank_id' => $tank->id,
            'delivery_channel' => 'SUPPLIER_DOYEN_PICKUP',
            'order_type' => 'PICK_UP',
            'atl_type' => 'DITC_ATL',
            'atl_number' => 'ATL-REQ-88',
            'dr_number' => 'DR-REQ-88',
            'product' => 'Diesel',
            'qty_to_receive' => 10000,
            'receiving_date' => now(),
            'status' => 'Pending',
        ]);

        $this->actingAs($this->purchasing)
            ->get(route('stock-orders.wet-stock-requests.show', $po->id))
            ->assertSee('ATL-REQ-88');
    }

    /**
     * A request that has not been actioned yet has no ATLs; the detail view
     * must still render rather than assume one exists.
     */
    public function test_wet_stock_request_detail_handles_a_request_with_no_atl_yet(): void
    {
        $po = $this->replenishmentPo();

        $this->actingAs($this->purchasing)
            ->get(route('stock-orders.wet-stock-requests.show', $po->id))
            ->assertOk();
    }

    // ---- Redirects from the old URLs ----

    public static function legacyUrlProvider(): array
    {
        return [
            'all orders to purchase orders' => ['/stock-orders', 'stock-orders.purchase-orders.index'],
            'dashboard to purchase orders' => ['/stock-orders/dashboard', 'stock-orders.purchase-orders.index'],
            'atl queue to sales orders' => ['/stock-orders/atls', 'stock-orders.sales-orders.index'],
            'deliveries and atls to wet stock requests' => ['/stock-orders/deliveries', 'stock-orders.wet-stock-requests.index'],
        ];
    }

    /**
     * @dataProvider legacyUrlProvider
     */
    public function test_legacy_module_3_urls_redirect_to_their_new_page(string $from, string $to): void
    {
        $this->actingAs($this->purchasing)
            ->get($from)
            ->assertRedirect(route($to));
    }

    // ---- Sidebar ----

    public function test_sidebar_lists_the_three_pages_in_order(): void
    {
        $html = $this->sidebarHtml();

        foreach (['Purchase Orders', 'Sales Orders', 'Wet Stock Requests'] as $label) {
            $this->assertStringContainsString($label, $html);
        }

        $this->assertLessThan(
            strpos($html, 'Sales Orders'),
            strpos($html, 'Purchase Orders'),
            'Purchase Orders must come before Sales Orders.'
        );

        $this->assertLessThan(
            strpos($html, 'Wet Stock Requests'),
            strpos($html, 'Sales Orders'),
            'Sales Orders must come before Wet Stock Requests.'
        );
    }

    public function test_sidebar_has_no_standalone_dashboard_entry(): void
    {
        $html = $this->sidebarHtml();

        $this->assertStringNotContainsString(
            '>Dashboard<',
            $html,
            'The sidebar Dashboard entry was replaced by per-page stat cards.'
        );
    }

    public function test_sidebar_shows_a_reports_placeholder(): void
    {
        $this->assertStringContainsString('Reports', $this->sidebarHtml());
    }

    public function test_sidebar_keeps_the_switch_portal_link(): void
    {
        $html = $this->actingAs($this->purchasing)
            ->get(route('stock-orders.purchase-orders.index'))
            ->getContent();

        $this->assertStringContainsString('Switch Portal', $html);
    }

    /**
     * The three pages must appear in the order the supervisor specified,
     * and Approvals must sit after them.
     */
    public function test_sidebar_order_matches_the_specified_sequence(): void
    {
        // admin sees every entry: Approvals (admin/vp) and Suppliers (admin/purchasing).
        $html = $this->sidebarFor('admin');

        $pos = fn (string $label) => strpos($html, $label);

        $this->assertNotFalse($pos('Purchase Orders'));
        $this->assertNotFalse($pos('Sales Orders'));
        $this->assertNotFalse($pos('Wet Stock Requests'));
        $this->assertNotFalse($pos('Approvals'));
        $this->assertNotFalse($pos('Suppliers'));
        $this->assertNotFalse($pos('Reports'));

        $this->assertLessThan($pos('Sales Orders'), $pos('Purchase Orders'));
        $this->assertLessThan($pos('Wet Stock Requests'), $pos('Sales Orders'));
        $this->assertLessThan($pos('Approvals'), $pos('Wet Stock Requests'));
        $this->assertLessThan($pos('Suppliers'), $pos('Approvals'));
        $this->assertLessThan($pos('Reports'), $pos('Suppliers'));
    }

    // ---- Approvals visibility in the sidebar: VP and Administrator only ----
    //
    // Asserted against the sidebar block rather than the whole document:
    // in-page buttons legitimately link to the approvals queue for every
    // role, so only the navigation entry is role-gated.

    /**
     * The Module 3 desktop sidebar block only.
     *
     * Asserting on a slice of the page avoids two false results: the mobile
     * nav also lists these entries, and in-page buttons link to the
     * approvals queue for every role. Only the nav entry is role-gated.
     */
    protected function sidebarFor(?string $role = null): string
    {
        if ($role !== null) {
            $this->sidebarUser = Admin::create([
                'username' => 'sb_' . $role . '_' . uniqid(),
                'password' => bcrypt('secret'),
                'name' => ucfirst($role),
                'role' => $role,
            ]);
        }

        $html = $this->actingAs($this->sidebarUser)
            ->get(route('stock-orders.purchase-orders.index'))
            ->getContent();

        // The layout renders several sidebars; the Module 3 one is the last
        // to list "Purchase Orders" as a nav label, and it ends at
        // "Switch Portal". Slice exactly that block.
        $label = strrpos($html, 'nav-label">Purchase Orders');
        $this->assertNotFalse($label, 'Module 3 sidebar not found');

        $start = strrpos(substr($html, 0, $label), 'sidebar-nav-link');
        $this->assertNotFalse($start, 'Desktop sidebar block not found');

        $end = strpos($html, 'Switch Portal', $label);
        $this->assertNotFalse($end, 'Switch Portal link not found in sidebar');

        return substr($html, $start, $end - $start);
    }

    protected function sidebarHtml(): string
    {
        return $this->sidebarFor();
    }


    public function test_approvals_link_is_visible_to_vp(): void
    {
        $this->assertStringContainsString(
            'Approvals',
            $this->sidebarHtml(),
            'VP must see the Approvals entry.'
        );
    }

    public function test_approvals_link_is_visible_to_admin(): void
    {
        $this->assertStringContainsString(
            'Approvals',
            $this->sidebarFor('admin'),
            'Administrator must see the Approvals entry.'
        );
    }

    public function test_approvals_link_is_hidden_from_purchasing(): void
    {
        $this->assertStringNotContainsString(
            'Approvals',
            $this->sidebarFor('purchasing'),
            'Purchasing must not see the Approvals entry.'
        );
    }

    public function test_approvals_link_is_hidden_from_other_roles(): void
    {
        // Only these roles can reach Module 3 at all (canAccessStockOrders);
        // ops_admin and friends are blocked by the route middleware before a
        // sidebar is ever rendered, so they are not asserted here.
        foreach (['audit', 'viewer'] as $role) {
            $this->assertStringNotContainsString(
                'Approvals',
                $this->sidebarFor($role),
                "Role {$role} must not see the Approvals entry."
            );
        }
    }

    /**
     * Module 3 routes are gated to admin, purchasing, vp, audit and viewer.
     * A role outside that list must be refused outright.
     */
    public function test_roles_outside_stock_orders_are_refused(): void
    {
        foreach (['ops_admin', 'sales'] as $role) {
            $user = Admin::create([
                'username' => 'blk_' . $role . '_' . uniqid(),
                'password' => bcrypt('secret'),
                'name' => ucfirst($role),
                'role' => $role,
            ]);

            $this->actingAs($user)
                ->get(route('stock-orders.purchase-orders.index'))
                ->assertForbidden();
        }
    }
}