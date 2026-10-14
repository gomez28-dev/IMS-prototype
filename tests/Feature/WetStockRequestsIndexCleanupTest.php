<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderDelivery;
use App\Models\PurchaseOrderItem;
use App\Models\StockRequest;
use App\Models\StockRequestLine;
use App\Models\Warehouse;
use Tests\TestCase;

/**
 * The Wet Stock Requests page carried two sections that do not belong to it.
 *
 * Depot Inbound Deliveries let an operator fill a depot tank from the requests
 * queue, which is Module 2's job - the supervisor crossed the section out and
 * replaced it with the three product-aware cards. Alongside it, the page listed
 * purchase orders filed as STANDARD_REPLENISHMENT as "legacy requests". Those
 * were never a real legacy backlog: storeSupplierPo files every supplier PO
 * that way, so ordinary purchase orders were appearing here with a View Request
 * button that led to a purchase order.
 */
class WetStockRequestsIndexCleanupTest extends TestCase
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

    protected function request(string $number = 'REQ-CLEAN-1'): StockRequest
    {
        $request = StockRequest::create([
            'request_number' => $number.'-'.rand(1000, 9999),
            'request_type' => StockRequest::TYPE_PICKUP,
            'warehouse_id' => $this->warehouse->id,
            'qty_requested' => 40000,
            'status' => StockRequest::STATUS_REQUESTED,
            'requested_by' => $this->admin->id,
        ]);

        StockRequestLine::create([
            'stock_request_id' => $request->id,
            'product' => 'Diesel',
            'qty_requested' => 40000,
        ]);

        return $request;
    }

    protected function page(): string
    {
        return $this->actingAs($this->admin)
            ->get(route('stock-orders.wet-stock-requests.index'))
            ->assertOk()
            ->getContent();
    }

    public function test_the_depot_inbound_deliveries_section_is_gone(): void
    {
        $html = $this->page();

        $this->assertStringNotContainsString('Depot Inbound Deliveries', $html);
        $this->assertStringNotContainsString('Receive Stock into Depot Tank', $html);
        $this->assertStringNotContainsString('Receive Stock', $html);
        $this->assertStringNotContainsString('receiveStockModal', $html);
        $this->assertStringNotContainsString(route('wetstock.deliveries.receive-stock', 1), $html);
    }

    public function test_a_delivery_waiting_on_a_tank_fill_is_not_listed_here(): void
    {
        // Even when such a delivery exists, this page says nothing about it.
        $po = PurchaseOrder::create([
            'po_number' => 'PO-TANK-'.rand(1000, 9999),
            'po_type' => PurchaseOrder::TYPE_STANDARD_REPLENISHMENT,
            'supplier_name' => 'Petron Bataan Refinery',
            'warehouse_id' => $this->warehouse->id,
            'po_date' => now()->toDateString(),
            'qty_ordered' => 20000,
            'request_status' => 'CONFIRMED',
        ]);
        PurchaseOrderItem::create([
            'purchase_order_id' => $po->id,
            'product' => 'Diesel',
            'quantity_ordered' => 20000,
        ]);
        PurchaseOrderDelivery::create([
            'purchase_order_id' => $po->id,
            'delivery_channel' => 'SUPPLIER_STOCKS_DELIVERY',
            'order_type' => 'DELIVERY',
            'atl_type' => 'DITC_ATL',
            'atl_number' => 'ATL-TANK-'.uniqid(),
            'product' => 'Diesel',
            'qty_to_receive' => 20000,
            'receiving_date' => now()->addDay(),
            'status' => 'Pending',
        ]);

        $this->request();

        $this->assertStringNotContainsString('ATL-TANK-', $this->page());
    }

    public function test_the_three_product_cards_stay(): void
    {
        $this->request();

        $html = $this->page();

        foreach (['Diesel', 'Unleaded', 'Premium'] as $product) {
            $this->assertStringContainsString($product, $html);
        }
        $this->assertStringContainsString('Total Requested', $html);
        $this->assertStringContainsString('Total Received', $html);
        $this->assertStringContainsString('Remaining', $html);
    }

    public function test_the_request_table_is_the_only_table_left(): void
    {
        $this->request();

        $html = $this->page();

        $this->assertStringContainsString('Submitted Replenishment Requests', $html);
        // One table: the requests. The other two headings are gone.
        $this->assertSame(
            1,
            substr_count($html, '<table class="table table-custom mb-0">'),
            'Only the requests table should remain.'
        );
    }

    public function test_the_search_box_appears_exactly_once(): void
    {
        $this->request();

        $this->assertSame(
            1,
            substr_count($this->page(), 'name="search"'),
            'It moved from the page header to the section header, not duplicated.'
        );
    }

    public function test_the_row_action_is_create_atl_only(): void
    {
        $request = $this->request();

        $html = $this->page();

        $this->assertStringContainsString(
            route('stock-orders.wet-stock-requests.atls', $request->id),
            $html
        );
        $this->assertStringNotContainsString('Prepare &amp; Submit', $html);
        $this->assertStringNotContainsString('prepare-stock-request', $html);
        $this->assertStringNotContainsString('View Request', $html);
    }
}
