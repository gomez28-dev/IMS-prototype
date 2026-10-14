<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\AtlAllocation;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderDelivery;
use App\Models\PurchaseOrderItem;
use App\Models\StockRequest;
use App\Models\StockRequestLine;
use App\Models\Supplier;
use App\Models\Warehouse;
use Tests\TestCase;

/**
 * The Wet Stock Requests page's action became Create ATL, which opens a
 * per-request ATL page laid out like the M1 Deliveries page: an info card,
 * product-aware totals, an ATL table, and an Add ATL button. Prepare stays
 * available as its own action, because raising the PO is still its own job.
 */
class WetStockRequestCreateAtlPageTest extends TestCase
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

    protected function request(array $lines = [['Diesel', 50000]]): StockRequest
    {
        $request = StockRequest::create([
            'request_number' => 'REQ-'.rand(100, 999),
            'request_type' => StockRequest::TYPE_PICKUP,
            'warehouse_id' => $this->warehouse->id,
            'qty_requested' => array_sum(array_column($lines, 1)),
            'status' => StockRequest::STATUS_REQUESTED,
            'requested_by' => $this->admin->id,
        ]);

        foreach ($lines as [$product, $qty]) {
            StockRequestLine::create([
                'stock_request_id' => $request->id,
                'product' => $product,
                'qty_requested' => $qty,
            ]);
        }

        return $request;
    }

    protected function atl(StockRequest $request, PurchaseOrder $po, string $product, int $qty): PurchaseOrderDelivery
    {
        $atl = PurchaseOrderDelivery::create([
            'purchase_order_id' => $po->id,
            'request_id' => $request->id,
            'delivery_channel' => 'SUPPLIER_STOCKS_DELIVERY',
            'order_type' => 'DELIVERY',
            'atl_type' => 'DITC_ATL',
            'atl_number' => 'ATL-'.rand(1000, 9999),
            'product' => $product,
            'qty_to_receive' => $qty,
            'receiving_date' => now()->addDays(3),
            'status' => 'Active',
            'lift_status' => PurchaseOrderDelivery::LIFT_UNLIFTED,
        ]);

        AtlAllocation::create([
            'purchase_order_delivery_id' => $atl->id,
            'purchase_order_id' => $po->id,
            'product' => $product,
            'quantity' => $qty,
        ]);

        return $atl;
    }

    protected function purchaseOrder(int $qty = 100000): PurchaseOrder
    {
        $supplier = Supplier::create([
            'company_name' => 'Atl Page Refinery '.rand(1000, 9999),
            'location' => 'Limay Terminal',
        ]);

        $po = PurchaseOrder::create([
            'po_number' => 'PO-ATLPAGE-'.rand(1000, 9999),
            'po_type' => PurchaseOrder::TYPE_STANDARD_REPLENISHMENT,
            'supplier_id' => $supplier->id,
            'supplier_name' => $supplier->company_name,
            'po_date' => now()->toDateString(),
            'qty_ordered' => $qty,
            'request_status' => 'CONFIRMED',
        ]);

        PurchaseOrderItem::create([
            'purchase_order_id' => $po->id,
            'product' => 'Diesel',
            'quantity_ordered' => $qty,
        ]);

        return $po;
    }

    // ---- The list page's action --------------------------------------------

    public function test_the_list_page_offers_create_atl(): void
    {
        $request = $this->request();

        $html = $this->actingAs($this->admin)
            ->get(route('stock-orders.wet-stock-requests.index'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString(
            route('stock-orders.wet-stock-requests.atls', $request->id),
            $html
        );
        $this->assertStringContainsString('Create ATL', $html);
    }

    public function test_the_prepare_route_still_works_even_though_the_button_is_gone(): void
    {
        // The button was withdrawn from the queue in favour of Create ATL, but
        // the route and its handler are untouched, so a URL still works rather
        // than being deleted out from under anyone with the link bookmarked.
        $request = $this->request();

        $html = $this->actingAs($this->admin)
            ->get(route('stock-orders.wet-stock-requests.index'))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('Prepare &amp; Submit', $html);
        $this->assertStringNotContainsString(
            route('stock-orders.prepare-stock-request', ['stock_request' => $request->id]),
            $html,
            'The queue should no longer offer it.'
        );

        $this->actingAs($this->admin)
            ->get(route('stock-orders.prepare-stock-request', ['stock_request' => $request->id]))
            ->assertOk();
    }

    // ---- The per-request ATL page ------------------------------------------

    public function test_the_atl_page_opens_for_a_request(): void
    {
        $request = $this->request();

        $this->actingAs($this->admin)
            ->get(route('stock-orders.wet-stock-requests.atls', $request->id))
            ->assertOk();
    }

    public function test_the_atl_page_uses_the_m1_deliveries_layout(): void
    {
        $request = $this->request();

        $html = $this->actingAs($this->admin)
            ->get(route('stock-orders.wet-stock-requests.atls', $request->id))
            ->assertOk()
            ->getContent();

        // Image 2's structure: info card, stat cards, a table, and the action
        // button sitting on the table's heading.
        $this->assertStringContainsString('stat-card', $html);
        $this->assertStringContainsString('stat-head', $html);
        $this->assertStringContainsString('ATLs', $html);
        $this->assertStringContainsString('Add ATL', $html);
    }

    public function test_the_atl_page_carries_product_aware_totals(): void
    {
        $request = $this->request([
            ['Diesel', 50000],
            ['Unleaded', 20000],
        ]);
        $po = $this->purchaseOrder();
        $this->atl($request, $po, 'Diesel', 20000);

        $html = $this->actingAs($this->admin)
            ->get(route('stock-orders.wet-stock-requests.atls', $request->id))
            ->assertOk()
            ->getContent();

        // Titles follow M1's Deliveries cards.
        $this->assertStringContainsString('Qty Requested', $html);
        // Blade escapes the apostrophe in the card title, so match the part
        // before it rather than the rendered entity.
        $this->assertStringContainsString('Total ATL', $html);
        $this->assertStringContainsString('Remaining', $html);
        $this->assertStringContainsString('Diesel', $html);
        $this->assertStringContainsString('Unleaded', $html);
    }

    public function test_the_atl_page_lists_the_atls_already_raised(): void
    {
        $request = $this->request();
        $po = $this->purchaseOrder();
        $atl = $this->atl($request, $po, 'Diesel', 20000);

        $html = $this->actingAs($this->admin)
            ->get(route('stock-orders.wet-stock-requests.atls', $request->id))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString($atl->atl_number, $html);
        $this->assertStringContainsString($po->po_number, $html);
        $this->assertStringNotContainsString('No ATLs issued for this request yet.', $html);
    }

    public function test_the_atl_page_says_so_when_there_are_none(): void
    {
        $html = $this->actingAs($this->admin)
            ->get(route('stock-orders.wet-stock-requests.atls', $this->request()->id))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('No ATLs issued for this request yet.', $html);
    }

    public function test_the_atl_page_offers_the_add_atl_button(): void
    {
        $request = $this->request();

        $html = $this->actingAs($this->admin)
            ->get(route('stock-orders.wet-stock-requests.atls', $request->id))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString(
            route('stock-orders.wet-stock-requests.atls.create', $request->id),
            $html,
            'Add ATL must lead somewhere.'
        );
    }
}
