<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\AtlAllocation;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderDelivery;
use App\Models\PurchaseOrderItem;
use App\Models\Supplier;
use Tests\TestCase;

/**
 * The ATL Details page used to read the product straight off the order's own
 * lines, so every legacy order showed "Unspecified": all of them predate the
 * product_type column. The product is recoverable from the PO the order raised,
 * or from an ATL already issued against it, so the page resolves it rather than
 * writing anything back.
 */
class SalesOrderProductResolutionTest extends TestCase
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

    protected function order(?string $productType = null, int $qty = 80000): Order
    {
        $order = Order::create([
            'so_number' => 'SO-PROD-'.rand(1000, 9999),
            'account' => 'Venture Commercial & Industrial Corp.',
            'date' => now()->toDateString(),
            'location' => 'Valenzuela',
            'status' => 'Active',
            'order_type' => Order::CATEGORY_CLIENT_ORDER,
            'fulfillment_type' => Order::FULFILLMENT_FUEL_TRADE,
            'order_category' => Order::CATEGORY_CLIENT_ORDER,
            'terms' => '30 Days',
            'qty_ordered' => $qty,
            'clearing_status' => 'Approved',
        ]);

        OrderItem::create([
            'order_id' => $order->id,
            'product_type' => $productType,
            'qty' => $qty,
        ]);

        return $order;
    }

    protected function purchaseOrderFor(Order $order, string $product, int $qty = 80000, bool $withItems = true): PurchaseOrder
    {
        $supplier = Supplier::create([
            'company_name' => 'Product Refinery '.rand(1000, 9999),
            'location' => 'Limay Terminal',
        ]);

        $po = PurchaseOrder::create([
            'po_number' => 'PO-PROD-'.rand(1000, 9999),
            'po_type' => PurchaseOrder::TYPE_FUEL_TRADE,
            'linked_order_id' => $order->id,
            'supplier_id' => $supplier->id,
            'supplier_name' => $supplier->company_name,
            'po_date' => now()->toDateString(),
            'qty_ordered' => $qty,
            'request_status' => 'CONFIRMED',
        ]);

        if ($withItems) {
            PurchaseOrderItem::create([
                'purchase_order_id' => $po->id,
                'product' => $product,
                'quantity_ordered' => $qty,
            ]);
        }

        return $po;
    }

    protected function atlFor(Order $order, PurchaseOrder $po, string $product, int $qty = 80000): PurchaseOrderDelivery
    {
        $atl = PurchaseOrderDelivery::create([
            'purchase_order_id' => $po->id,
            'order_id' => $order->id,
            'delivery_channel' => 'SUPPLIER_CLIENT_PICKUP',
            'order_type' => 'PICK_UP',
            'atl_type' => 'DITC_ATL',
            'atl_number' => 'ATL-PROD-'.rand(1000, 9999),
            'product' => $product,
            'qty_to_receive' => $qty,
            'receiving_date' => now()->addDay(),
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

    // ---- The fallback chain ------------------------------------------------

    public function test_a_product_on_the_order_wins(): void
    {
        $order = $this->order('D');

        $this->assertSame('Diesel', $order->resolvedProduct());
        $this->assertSame('D', $order->resolvedProductCode());
    }

    public function test_a_legacy_order_falls_back_to_its_purchase_order(): void
    {
        $order = $this->order(null);
        $this->purchaseOrderFor($order, 'Premium');

        // This is the case the supervisor saw: no product on the order, but the
        // PO raised against it knows exactly what was bought.
        $this->assertSame('Premium', $order->fresh()->resolvedProduct());
    }

    public function test_a_legacy_order_with_no_po_falls_back_to_an_issued_atl(): void
    {
        $order = $this->order(null);

        // A PO carrying no item rows of its own: a Fuel Trade ATL can record
        // its allocations without them, so the ATL is the only place left that
        // knows what was taken.
        $po = $this->purchaseOrderFor($order, 'Unleaded', 80000, false);
        $this->atlFor($order, $po, 'Unleaded');

        $this->assertSame('Unleaded', $order->fresh()->resolvedProduct());
    }

    public function test_an_order_with_nothing_to_go_on_stays_unspecified(): void
    {
        $order = $this->order(null);

        $this->assertSame('Unspecified', $order->resolvedProduct());
        $this->assertNull($order->resolvedProductCode());
    }

    public function test_a_blank_product_type_is_not_mistaken_for_a_product(): void
    {
        // A form posted with an empty select stores '' rather than null.
        $order = $this->order('');

        $this->assertSame('Unspecified', $order->resolvedProduct());
    }

    // ---- The page ----------------------------------------------------------

    public function test_the_atl_details_page_shows_the_real_product(): void
    {
        $order = $this->order(null);
        $this->purchaseOrderFor($order, 'Premium');

        $html = $this->actingAs($this->purchasing)
            ->get(route('stock-orders.sales-orders.show', $order->id))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Premium', $html);
        $this->assertStringNotContainsString('Unspecified', $html);
    }

    public function test_the_page_still_says_unspecified_when_there_is_nothing_to_go_on(): void
    {
        $order = $this->order(null);

        $html = $this->actingAs($this->purchasing)
            ->get(route('stock-orders.sales-orders.show', $order->id))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Unspecified', $html);
    }
}
