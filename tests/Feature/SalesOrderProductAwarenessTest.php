<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\AtlAllocation;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderDelivery;
use Tests\TestCase;

/**
 * Supervisor feedback: the Sales Orders page has to be product-aware, and the
 * date under the SO number becomes its own column showing the Module 1
 * "date needed" rather than the order date.
 */
class SalesOrderProductAwarenessTest extends TestCase
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
    }

    /**
     * @param  array<string, int>  $products  e.g. ['D' => 1000, 'U' => 500]
     */
    protected function order(array $products, array $overrides = []): Order
    {
        $order = Order::create(array_merge([
            'account' => 'Apex Logistics Corp',
            'location' => 'Valenzuela',
            'so_number' => (string) rand(100000, 999999),
            'date' => now()->subDays(30),
            'date_needed' => now()->addDays(7)->toDateString(),
            'status' => 'Active',
            'terms' => 'COD',
            'order_category' => Order::CATEGORY_CLIENT_ORDER,
            'fulfillment_type' => Order::FULFILLMENT_FUEL_TRADE,
            'qty_ordered' => array_sum($products),
            'created_by' => $this->purchasing->id,
        ], $overrides));

        foreach ($products as $code => $qty) {
            OrderItem::create([
                'order_id' => $order->id,
                'product_type' => $code,
                'qty' => $qty,
                'price' => 50,
            ]);
        }

        return $order->fresh();
    }

    /**
     * An ATL carrying an allocation against the order, at a lift status.
     */
    protected function atlFor(Order $order, string $liftStatus, array $products): PurchaseOrderDelivery
    {
        $po = PurchaseOrder::create([
            'po_number' => 'PO-'.uniqid(),
            'po_type' => PurchaseOrder::TYPE_FUEL_TRADE,
            'qty_ordered' => array_sum($products),
            'request_status' => 'CONFIRMED',
            'status' => 'Pending',
            'linked_order_id' => $order->id,
            'prepared_by' => $this->purchasing->id,
        ]);

        $delivery = PurchaseOrderDelivery::create([
            'purchase_order_id' => $po->id,
            'order_id' => $order->id,
            'delivery_channel' => 'SUPPLIER_DOYEN_PICKUP',
            'atl_category' => PurchaseOrderDelivery::CATEGORY_FUEL_TRADE,
            'atl_number' => 'ATL-'.uniqid(),
            'product' => implode(' + ', array_keys($products)),
            'qty_to_receive' => array_sum($products),
            'status' => 'Active',
            'lift_status' => $liftStatus,
            'approval_status' => PurchaseOrderDelivery::APPROVAL_APPROVED,
        ]);

        foreach ($products as $code => $qty) {
            AtlAllocation::create([
                'purchase_order_delivery_id' => $delivery->id,
                'purchase_order_id' => $po->id,
                'product' => Order::PRODUCT_TYPES[$code],
                'quantity' => $qty,
            ]);
        }

        return $delivery;
    }

    protected function pageHtml(): string
    {
        $response = $this->actingAs($this->purchasing)
            ->get(route('stock-orders.sales-orders.index'));
        $response->assertOk();

        return $response->getContent();
    }

    // ---- Product-aware cards ---------------------------------------------

    public function test_the_cards_break_volumes_out_by_product(): void
    {
        $this->order(['D' => 235000, 'P' => 10000]);
        $this->atlFor(Order::latest('id')->firstOrFail(), PurchaseOrderDelivery::LIFT_LIFTED, ['D' => 20000]);

        $html = $this->pageHtml();

        // Same card furniture as the Purchase Orders page.
        $this->assertStringContainsString('stat-icon', $html);
        $this->assertStringContainsString('prod-chip', $html);
        $this->assertStringContainsString('Total Qty. Ordered', $html);
        $this->assertStringContainsString('Unlifted ATLs', $html);
        $this->assertStringContainsString('Lifted ATLs', $html);

        // Ordered volume, per product.
        $this->assertStringContainsString('235,000', $html);
        $this->assertStringContainsString('10,000', $html);
        // Lifted volume, per product.
        $this->assertStringContainsString('20,000', $html);
    }

    public function test_all_three_products_are_always_shown_even_when_zero(): void
    {
        $this->order(['D' => 1000]);

        $html = $this->pageHtml();

        foreach (['Diesel', 'Unleaded', 'Premium'] as $product) {
            $this->assertStringContainsString($product, $html);
        }
    }

    public function test_pending_clearance_rides_under_the_headline_count(): void
    {
        $this->order(['D' => 1000], ['clearing_status' => 'Pending']);

        $html = $this->pageHtml();

        // It is a count of orders, not a volume, so it has no product rows.
        $this->assertStringContainsString('Orders Listed', $html);
        $this->assertStringContainsString('pending clearance', $html);
        $this->assertStringNotContainsString('Pending Clearance</span>', $html);
    }

    // ---- Product-aware rows ----------------------------------------------

    public function test_a_multi_product_row_reports_each_quantity(): void
    {
        $order = $this->order(['D' => 1000, 'U' => 500]);

        $html = $this->pageHtml();

        $this->assertStringContainsString('Qty Ordered / Products', $html);
        $this->assertStringContainsString('1,000', $html);
        $this->assertStringContainsString('500', $html);
        // The cumulative Total row under the product lines is gone; the cards
        // keep their own totals.
        $this->assertStringNotContainsString('1,500 L', $html);
        // The old name-only badges are gone.
        $this->assertStringNotContainsString('<span class="badge bg-light text-dark border me-1">', $html);
    }

    // ---- Date Needed column -------------------------------------------------

    public function test_date_needed_is_gone_from_the_list(): void
    {
        $this->order(['D' => 1000]);

        $html = $this->pageHtml();

        // The column was removed with the SO form's field: the delivery date
        // is the ATL form's Authorized Pick Up Date, and nothing on the Sales
        // Orders list quotes a "date needed".
        $this->assertStringNotContainsString('Date Needed', $html);
    }

    /**
     * Volumes are card figures, so the cards must still agree with the rows.
     */
    public function test_the_cards_reconcile_with_the_rows(): void
    {
        $this->order(['D' => 1000, 'U' => 500]);
        $this->order(['D' => 2000]);

        $html = $this->pageHtml();

        // Ordered total across both orders is 3,500 L.
        $this->assertStringContainsString('3,500', $html);
    }

    public function test_the_cards_respect_the_search_filter(): void
    {
        $mine = $this->order(['D' => 1000]);
        $mine->update(['account' => 'Unique Account Marker']);
        $this->order(['D' => 2000]);

        $response = $this->actingAs($this->purchasing)
            ->get(route('stock-orders.sales-orders.index', ['search' => 'Unique Account Marker']));

        $response->assertOk();
        $response->assertSee('1,000');
        // The other order's 2,000 L is filtered out, so its card reads 1,000.
        $this->assertStringNotContainsString('3,000', $response->getContent());
    }
}