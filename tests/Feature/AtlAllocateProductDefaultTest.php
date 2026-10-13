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
 * "Use that in the ATL flow": the Allocate table should open on the product the
 * Sales Order is actually for. Legacy orders carry no product_type, so the
 * default used to be hard-coded to Diesel whatever the order really was.
 */
class AtlAllocateProductDefaultTest extends TestCase
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

    protected function order(?string $productType, int $qty = 50000): Order
    {
        $order = Order::create([
            'so_number' => 'SO-DEF-'.rand(1000, 9999),
            'account' => 'Venture Commercial & Industrial Corp.',
            'date' => now()->toDateString(),
            'location' => 'Valenzuela',
            'status' => 'Active',
            'terms' => '30 Days',
            'qty_ordered' => $qty,
            'clearing_status' => 'Approved',
            'fulfillment_type' => Order::FULFILLMENT_FUEL_TRADE,
            'order_category' => Order::CATEGORY_CLIENT_ORDER,
        ]);

        OrderItem::create([
            'order_id' => $order->id,
            'product_type' => $productType,
            'qty' => $qty,
        ]);

        return $order;
    }

    protected function poWith(array $products, ?Order $order = null): PurchaseOrder
    {
        $supplier = Supplier::create([
            'company_name' => 'Default Refinery '.rand(1000, 9999),
            'location' => 'Limay Terminal',
        ]);

        $po = PurchaseOrder::create([
            'po_number' => 'PO-DEF-'.rand(1000, 9999),
            'po_type' => PurchaseOrder::TYPE_FUEL_TRADE,
            // The link is what makes the PO the fallback source for the order's
            // product when the order itself carries none.
            'linked_order_id' => $order?->id,
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

    protected function atlForm(Order $order): string
    {
        return $this->actingAs($this->purchasing)
            ->get(route('stock-orders.create-fuel-trade-po', $order->id))
            ->assertOk()
            ->getContent();
    }

    // ---- The default follows the order -------------------------------------

    public function test_a_new_order_defaults_to_its_own_product(): void
    {
        $order = $this->order('U');
        $this->poWith(['Unleaded' => 100000]);

        $html = $this->atlForm($order);

        $this->assertMatchesRegularExpression(
            '/<option value="Unleaded"\s+selected>/',
            $html,
            'The first Allocate row should open on the order\'s own product.'
        );
    }

    public function test_a_legacy_order_defaults_to_the_product_recovered_from_its_po(): void
    {
        $order = $this->order(null);
        $this->poWith(['Premium' => 100000], $order);

        $html = $this->atlForm($order);

        // The order's line says nothing, and the PO it raised says Premium, so
        // Premium it is - not the hard-coded Diesel.
        $this->assertMatchesRegularExpression(
            '/<option value="Premium"\s+selected>/',
            $html,
            'A legacy order should default to the product recovered from its PO.'
        );
        $this->assertDoesNotMatchRegularExpression('/<option value="Diesel"\s+selected>/', $html);
    }

    // ---- It stays overridable ----------------------------------------------

    public function test_the_other_products_remain_selectable(): void
    {
        $order = $this->order('P');
        $this->poWith(['Premium' => 100000]);

        $html = $this->atlForm($order);

        // Pre-selected, not locked: a PO can genuinely hold more than one.
        $this->assertStringContainsString('<option value="Diesel"', $html);
        $this->assertStringContainsString('<option value="Unleaded"', $html);
        $this->assertMatchesRegularExpression('/<option value="Premium"\s+selected>/', $html);
    }

    public function test_an_added_row_defaults_to_the_same_product(): void
    {
        $order = $this->order(null);
        $this->poWith(['Premium' => 100000], $order);

        $html = $this->atlForm($order);

        // Rows added later reuse the first row's own option markup rather than
        // a hard-coded product list, so they inherit its selected product.
        $this->assertMatchesRegularExpression(
            '/\$\{productOptionsHtml\}/',
            $html,
            'An added row must reuse the first row\'s options so it inherits the selected product.'
        );
        $this->assertMatchesRegularExpression(
            '/const productOptionsHtml = \(function \(\) \{.*?querySelector\(\'\.dd-product-select\'\)/s',
            $html,
            'The options template must be taken from the first row\'s product select.'
        );
    }
}
