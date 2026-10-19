<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\Supplier;
use Tests\TestCase;

/**
 * Two things on the Issue ATL page for a Sales Order.
 *
 * Its back link pointed at Purchase Orders, so leaving the page you came from
 * on Sales Orders dumped you on a different module entirely. It now returns
 * there, carrying the page number and search so you land where you were.
 *
 * And the allocation pool could only ever allocate one product line. The form
 * carries a drawdown row with a product selector and a JS function that appends
 * another row, but the only way to reach it was Split Across Another PO - so
 * there was no way to allocate the order's second product at all. A PO holding
 * only Diesel meant Unleaded could not be reached, and the total read
 * "short by" with no way to close the gap.
 *
 * The product list was also wrong underneath: it was hard-coded to all three
 * fuels and rebuilt from PO availability alone, so a PO carrying Premium could
 * offer it on an order that never asked for Premium.
 */
class FuelTradeAtlMultiProductTest extends TestCase
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

    /**
     * @param  array<int, array{0: string, 1: int}>  $lines
     */
    protected function order(array $lines = [['Diesel', 300]]): Order
    {
        $order = Order::create([
            'so_number' => (string) rand(1000, 9999),
            'account' => 'A Test Only',
            'date' => now()->toDateString(),
            'location' => 'Valenzuela',
            'status' => 'Active',
            'qty_ordered' => array_sum(array_column($lines, 1)),
            'clearing_status' => 'Approved',
            'fulfillment_type' => Order::FULFILLMENT_FUEL_TRADE,
            'order_category' => Order::CATEGORY_CLIENT_ORDER,
        ]);

        foreach ($lines as [$product, $qty]) {
            OrderItem::create([
                'order_id' => $order->id,
                'product_type' => array_search($product, Order::PRODUCT_TYPES, true),
                'qty' => $qty,
            ]);
        }

        return $order;
    }

    /**
     * @param  array<string, int>  $products
     */
    protected function po(string $number, array $products): PurchaseOrder
    {
        $supplier = Supplier::create([
            'company_name' => 'Multi Product Refinery '.rand(1000, 9999),
            'location' => 'Limay Terminal',
        ]);

        $po = PurchaseOrder::create([
            'po_number' => $number,
            'po_type' => PurchaseOrder::TYPE_STANDARD_REPLENISHMENT,
            'supplier_id' => $supplier->id,
            'supplier_name' => $supplier->company_name,
            'po_date' => now()->toDateString(),
            'qty_ordered' => array_sum($products),
            'request_status' => 'CONFIRMED',
        ]);

        foreach ($products as $product => $qty) {
            PurchaseOrderItem::create([
                'purchase_order_id' => $po->id,
                'product' => $product,
                'quantity_ordered' => $qty,
            ]);
        }

        return $po;
    }

    protected function formHtml(Order $order): string
    {
        return $this->actingAs($this->admin)
            ->get(route('stock-orders.create-fuel-trade-po', $order->id))
            ->assertOk()
            ->getContent();
    }

    // ---- Task 3: the back link --------------------------------------------

    public function test_the_issue_atl_page_goes_back_to_sales_orders(): void
    {
        $order = $this->order();

        $html = $this->formHtml($order);

        $this->assertStringContainsString(
            route('stock-orders.sales-orders.index'),
            $html,
            'It should return to the list it was opened from, not to Purchase Orders.'
        );
        $this->assertStringContainsString('Back to Sales Orders', $html);
        $this->assertStringNotContainsString('Back to Stock Orders', $html);
    }

    public function test_the_back_link_keeps_the_page_and_search(): void
    {
        $order = $this->order();

        // The extra query params go in the parameter array - route()'s third
        // argument is $absolute, not more parameters.
        $html = $this->actingAs($this->admin)
            ->get(route('stock-orders.create-fuel-trade-po', [
                $order->id,
                'page' => 3,
                'search' => 'BMC',
            ]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('page=3', $html);
        $this->assertStringContainsString('search=BMC', $html);
    }

    public function test_the_back_link_omits_page_one(): void
    {
        $order = $this->order();

        $html = $this->actingAs($this->admin)
            ->get(route('stock-orders.create-fuel-trade-po', [$order->id, 'page' => 1]))
            ->assertOk()
            ->getContent();

        // page=1 is the default the index already shows; sending it is noise.
        $this->assertStringNotContainsString('page=1', $html);
    }

    public function test_the_add_atl_and_prepare_pages_keep_their_own_back_link(): void
    {
        $po = $this->po('PO-BACK-'.rand(1000, 9999), ['Diesel' => 5000]);

        $html = $this->actingAs($this->admin)
            ->get(route('stock-orders.add-atl', $po->id))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Back to Stock Orders', $html);
        $this->assertStringNotContainsString('Back to Sales Orders', $html);
    }

    // ---- Task 8: + Add Product --------------------------------------------

    public function test_the_pool_offers_an_add_product_button(): void
    {
        $order = $this->order([['Diesel', 300], ['Unleaded', 200]]);
        $this->po('PO-MP-'.rand(1000, 9999), ['Diesel' => 1000, 'Unleaded' => 1000]);

        $html = $this->formHtml($order);

        $this->assertStringContainsString('Add Product', $html);
    }

    public function test_split_across_another_po_still_works(): void
    {
        $order = $this->order([['Diesel', 300], ['Unleaded', 200]]);
        $this->po('PO-MP-'.rand(1000, 9999), ['Diesel' => 1000, 'Unleaded' => 1000]);

        $this->assertStringContainsString('Split Across Another PO', $this->formHtml($order));
    }

    public function test_the_product_dropdown_offers_only_what_the_order_asks_for(): void
    {
        // The order wants Diesel and Unleaded. Premium must not be offered even
        // though the PO holds it.
        $order = $this->order([['Diesel', 300], ['Unleaded', 200]]);
        $this->po('PO-MP-'.rand(1000, 9999), ['Diesel' => 1000, 'Unleaded' => 1000, 'Premium' => 5000]);

        $html = $this->formHtml($order);

        // The first row's product select is the one rendered by Blade.
        preg_match('/name="drawdowns\[0\]\[product\]".*?<\/select>/s', $html, $matches);
        $select = $matches[0] ?? '';

        $this->assertNotSame('', $select, 'The product select should be present.');
        $this->assertStringContainsString('>Diesel<', $select);
        $this->assertStringContainsString('>Unleaded<', $select);
        $this->assertStringNotContainsString('>Premium<', $select);
    }

    public function test_the_products_the_order_does_not_want_are_absent_from_the_script(): void
    {
        // The JS clones the first row's options into every added row, so the
        // server-rendered list is what constrains them all.
        $order = $this->order([['Diesel', 300], ['Unleaded', 200]]);
        $this->po('PO-MP-'.rand(1000, 9999), ['Diesel' => 1000, 'Unleaded' => 1000]);

        $html = $this->formHtml($order);

        preg_match('/name="drawdowns\[0\]\[product\]".*?<\/select>/s', $html, $matches);
        $select = $matches[0] ?? '';

        $this->assertStringNotContainsString('>Premium<', $select);
    }

    public function test_the_add_product_button_is_enabled_when_a_product_is_missing(): void
    {
        $order = $this->order([['Diesel', 300], ['Unleaded', 200]]);
        $this->po('PO-MP-'.rand(1000, 9999), ['Diesel' => 1000, 'Unleaded' => 1000]);

        $html = $this->formHtml($order);

        preg_match('/<button[^>]*onclick="addProductRow\(\)"[^>]*>/s', $html, $matches);
        $button = $matches[0] ?? '';

        $this->assertNotSame('', $button, 'The button should be rendered.');
        $this->assertStringNotContainsString(
            'disabled',
            $button,
            'Unleaded has no line yet, so there is something to add.'
        );
        $this->assertStringContainsString('btn-outline-primary', $button);
    }

    public function test_a_single_product_order_disables_the_add_product_button(): void
    {
        // SO 1360 in the screenshot: one product, so there is nothing left to
        // add and the button should say so rather than adding a pointless row.
        $order = $this->order([['Diesel', 300]]);
        $this->po('PO-MP-'.rand(1000, 9999), ['Diesel' => 1000]);

        $html = $this->formHtml($order);

        preg_match('/<button[^>]*onclick="addProductRow\(\)"[^>]*>/s', $html, $matches);
        $button = $matches[0] ?? '';

        $this->assertNotSame('', $button);
        $this->assertStringContainsString('disabled', $button);
        $this->assertStringContainsString(
            'one product only',
            $html,
            'The reason should be available, not just a greyed-out button.'
        );
    }

    public function test_both_pool_buttons_are_styled_the_same(): void
    {
        $order = $this->order([['Diesel', 300], ['Unleaded', 200]]);
        $this->po('PO-MP-'.rand(1000, 9999), ['Diesel' => 1000, 'Unleaded' => 1000]);

        $html = $this->formHtml($order);

        preg_match('/<button[^>]*onclick="addProductRow\(\)"[^>]*>/s', $html, $add);
        preg_match('/<button[^>]*onclick="addDrawdownRow\(\)"[^>]*>/s', $html, $split);

        $this->assertNotSame('', $add[0] ?? '');
        $this->assertNotSame('', $split[0] ?? '');

        // .btn-outline-primary is remapped to --brand-color in the layout, so
        // the two buttons read as the same orange outline.
        $this->assertStringContainsString('btn-outline-primary', $add[0]);
        $this->assertStringContainsString('btn-outline-primary', $split[0]);
        $this->assertStringContainsString('rounded-pill', $add[0]);
        $this->assertStringContainsString('rounded-pill', $split[0]);
    }

    public function test_the_per_product_total_breakdown_is_still_there(): void
    {
        $order = $this->order([['Diesel', 300], ['Unleaded', 200]]);
        $this->po('PO-MP-'.rand(1000, 9999), ['Diesel' => 1000, 'Unleaded' => 1000]);

        $html = $this->formHtml($order);

        $this->assertStringContainsString('Total Allocated for this ATL', $html);
        $this->assertStringContainsString('id="productBreakdown"', $html);
        $this->assertStringContainsString('calculateTotalDrawn()', $html);
    }

    public function test_the_allocation_pool_is_absent_from_the_add_atl_and_prepare_pages(): void
    {
        // The pool is scoped to the Sales Order's Issue ATL page; the other two
        // forms must be untouched by any of this. Asserted on the button's own
        // marker rather than the words "Add Product", which also appear in a
        // script comment this file shares across all three modes.
        $po = $this->po('PO-OTHER-'.rand(1000, 9999), ['Diesel' => 5000]);

        $html = $this->actingAs($this->admin)
            ->get(route('stock-orders.add-atl', $po->id))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('Supplier PO Allocation Pool', $html);
        // Asserted on the button's own inline handler. The words "Add Product"
        // and the data attribute both also appear in the shared script, which
        // every mode renders, so neither identifies the button.
        $this->assertStringNotContainsString('onclick="addProductRow()"', $html);
    }

    public function test_a_two_product_atl_can_actually_be_saved(): void
    {
        // The whole point: both products allocated in one ATL.
        $order = $this->order([['Diesel', 300], ['Unleaded', 200]]);
        $po = $this->po('PO-SAVE-'.rand(1000, 9999), ['Diesel' => 1000, 'Unleaded' => 1000]);

        $this->actingAs($this->admin)
            ->post(route('stock-orders.store-fuel-trade-po', $order->id), [
                'drawdowns' => [
                    ['purchase_order_id' => $po->id, 'product' => 'Diesel', 'quantity' => 150],
                    ['purchase_order_id' => $po->id, 'product' => 'Unleaded', 'quantity' => 200],
                ],
                'receiving_date' => now()->addDay()->format('Y-m-d'),
                'driver_name' => 'Juan Dela Cruz',
                'plate_number' => 'NBD-1234',
                'location' => 'Petron Limay Terminal',
                'atl_type' => 'DITC_ATL',
                'atl_number' => 'ATL-MP-'.uniqid(),
            ])
            ->assertSessionHas('success');

        $atl = \App\Models\PurchaseOrderDelivery::latest('id')->firstOrFail();

        $this->assertSame(350, (int) $atl->qty_to_receive);
        $this->assertSame(2, $atl->allocations()->count());
        $this->assertStringContainsString('Diesel', $atl->product);
        $this->assertStringContainsString('Unleaded', $atl->product);
    }
}