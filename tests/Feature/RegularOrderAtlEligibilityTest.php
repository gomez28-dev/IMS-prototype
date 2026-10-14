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
 * The supervisor drew the Create ATL button on a Regular order - one with no FT-
 * or BB- prefix - while scopeForModuleThree() lists every category. Both gates
 * used to refuse anything that was not Fuel Trade or Buy Back, so the button he
 * drew led nowhere and most of the page could not raise an ATL at all.
 *
 * These tests pin the category-free gates, and the product-aware summary cards
 * that replaced four flat numbers on the order's own page.
 */
class RegularOrderAtlEligibilityTest extends TestCase
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
     * @param  array<string, int>  $products
     */
    protected function order(string $soNumber, array $products = ['Diesel' => 30000], string $clearing = 'Approved'): Order
    {
        $order = Order::create([
            'so_number' => $soNumber,
            'account' => 'Venture Commercial & Industrial Corp.',
            'date' => now()->toDateString(),
            'location' => 'Valenzuela',
            'status' => 'Active',
            'terms' => '30 Days',
            'qty_ordered' => array_sum($products),
            'clearing_status' => $clearing,
            // A Regular order: no FT- and no BB- prefix on the SO number.
            'fulfillment_type' => Order::FULFILLMENT_DELIVERY,
            'order_category' => Order::CATEGORY_CLIENT_ORDER,
        ]);

        foreach ($products as $product => $qty) {
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
    protected function po(string $poNumber, array $products): PurchaseOrder
    {
        $supplier = Supplier::create([
            'company_name' => 'Regular Refinery '.rand(1000, 9999),
            'location' => 'Limay Terminal',
        ]);

        $po = PurchaseOrder::create([
            'po_number' => $poNumber,
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

    // ---- Every category can raise an ATL -----------------------------------

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function categories(): array
    {
        return [
            'regular order' => [Order::FULFILLMENT_DELIVERY, Order::CATEGORY_CLIENT_ORDER],
            'fuel trade order' => [Order::FULFILLMENT_FUEL_TRADE, Order::CATEGORY_CLIENT_ORDER],
            'buy back order' => [Order::FULFILLMENT_DELIVERY, Order::CATEGORY_BUY_BACK],
            'consumo order' => [Order::FULFILLMENT_DELIVERY, Order::CATEGORY_CONSUMO],
        ];
    }

    /**
     * @param  array<string, array{0: string, 1: string}>  $categories
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('categories')]
    public function test_a_cleared_order_of_any_category_can_open_the_atl_form(
        string $fulfillment,
        string $category
    ): void {
        $order = $this->order('SO-CAT-'.rand(1000, 9999));
        $order->update(['fulfillment_type' => $fulfillment, 'order_category' => $category]);

        $this->assertTrue(
            $order->fresh()->canBeIssuedAtl(),
            "A cleared {$category} order must be able to raise an ATL."
        );

        $this->actingAs($this->purchasing)
            ->get(route('stock-orders.create-fuel-trade-po', $order->id))
            ->assertOk();
    }

    /**
     * @param  array<string, array{0: string, 1: string}>  $categories
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('categories')]
    public function test_a_regular_order_really_can_issue_an_atl(
        string $fulfillment,
        string $category
    ): void {
        $order = $this->order('SO-ISSUE-'.rand(1000, 9999));
        $order->update(['fulfillment_type' => $fulfillment, 'order_category' => $category]);
        $po = $this->po('PO-REG-'.rand(1000, 9999), ['Diesel' => 40000]);

        $this->actingAs($this->purchasing)
            ->post(route('stock-orders.store-fuel-trade-po', $order->id), [
                'product' => 'Diesel',
                'receiving_date' => now()->addDay()->format('Y-m-d'),
                'driver_name' => 'Juan Dela Cruz',
                'plate_number' => 'NBD-1234',
                'location' => 'Petron Limay Terminal',
                'atl_type' => 'DITC_ATL',
                'atl_number' => 'ATL-REG-'.uniqid(),
                'drawdowns' => [
                    ['purchase_order_id' => $po->id, 'product' => 'Diesel', 'quantity' => 10000],
                ],
            ])
            ->assertSessionHas('success');

        $atl = PurchaseOrderDelivery::latest('id')->firstOrFail();

        $this->assertSame($order->id, $atl->order_id);
        // Buy Back is still filed as Buy Back; everything else shares FUEL_TRADE
        // because all of them lift straight to the client and must not stock a
        // depot tank.
        $this->assertSame(
            $order->isBuyBack()
                ? PurchaseOrderDelivery::CATEGORY_BUY_BACK
                : PurchaseOrderDelivery::CATEGORY_FUEL_TRADE,
            $atl->atl_category
        );
    }

    public function test_a_cancelled_order_can_never_raise_an_atl(): void
    {
        $order = $this->order('SO-CANCEL-'.rand(1000, 9999));
        $order->update(['status' => 'Cancelled']);

        $this->assertFalse($order->fresh()->canPrepareAtl());
        $this->assertFalse($order->fresh()->canBeIssuedAtl());
    }

    public function test_an_uncleared_order_cannot_issue_but_may_open_the_form(): void
    {
        $order = $this->order('SO-UNCLEARED-'.rand(1000, 9999), ['Diesel' => 30000], 'Pending');

        $this->assertTrue($order->canPrepareAtl(), 'The form should still open.');
        $this->assertFalse($order->canBeIssuedAtl(), 'But saving is blocked on clearance.');
    }

    public function test_a_regular_orders_atl_is_not_labelled_as_fuel_trade(): void
    {
        $order = $this->order('SO-LABEL-'.rand(1000, 9999));
        $po = $this->po('PO-LABEL-'.rand(1000, 9999), ['Diesel' => 40000]);

        $this->actingAs($this->purchasing)
            ->post(route('stock-orders.store-fuel-trade-po', $order->id), [
                'product' => 'Diesel',
                'receiving_date' => now()->addDay()->format('Y-m-d'),
                'driver_name' => 'Juan Dela Cruz',
                'plate_number' => 'NBD-1234',
                'location' => 'Petron Limay Terminal',
                'atl_type' => 'DITC_ATL',
                'atl_number' => 'ATL-LBL-'.uniqid(),
                'drawdowns' => [
                    ['purchase_order_id' => $po->id, 'product' => 'Diesel', 'quantity' => 10000],
                ],
            ])
            ->assertSessionHas('success');

        $atl = PurchaseOrderDelivery::latest('id')->firstOrFail()->load('order');

        // The ATL is filed under FUEL_TRADE so its tanker pick-up never stocks a
        // tank, but calling it FT on a Regular order is a mislabel.
        $this->assertSame('REGULAR', $atl->typeLabel());
    }

    // ---- Product-aware summary cards ---------------------------------------

    public function test_the_summary_cards_break_the_totals_down_by_product(): void
    {
        $order = $this->order('SO-CARDS-'.rand(1000, 9999), [
            'Diesel' => 30000,
            'Unleaded' => 10000,
        ]);
        $po = $this->po('PO-CARDS-'.rand(1000, 9999), ['Diesel' => 50000, 'Unleaded' => 50000]);

        $this->issueAtl($order, $po, 'Diesel', 20000);
        $this->issueAtl($order, $po, 'Unleaded', 4000);

        $html = $this->actingAs($this->purchasing)
            ->get(route('stock-orders.sales-orders.show', $order->id))
            ->assertOk()
            ->getContent();

        // One card per figure, each listing all three products plus a Total.
        // Blade escapes the apostrophe, so the card title reads Total ATL&#039;d
        // in the served markup.
        foreach (['Qty Ordered', 'Total ATL&#039;d', 'Total Lifted', 'Remaining'] as $title) {
            $this->assertStringContainsString($title, $html);
        }
        foreach (['Diesel', 'Unleaded', 'Premium'] as $product) {
            $this->assertStringContainsString($product, $html);
        }

        // Ordered 30,000 Diesel + 10,000 Unleaded; ATL'd 20,000 + 4,000.
        $this->assertStringContainsString('30,000', $html);
        $this->assertStringContainsString('10,000', $html);
        $this->assertStringContainsString('20,000', $html);
        $this->assertStringContainsString('4,000', $html);
    }

    public function test_lifted_counts_only_atls_that_were_actually_lifted(): void
    {
        $order = $this->order('SO-LIFT-'.rand(1000, 9999), ['Diesel' => 30000]);
        $po = $this->po('PO-LIFT-'.rand(1000, 9999), ['Diesel' => 50000]);

        $this->issueAtl($order, $po, 'Diesel', 12000);

        $this->assertSame(12000, $order->fresh()->drawnByProduct()['Diesel']);
        $this->assertSame(
            0,
            ($order->fresh()->liftedByProduct()['Diesel'] ?? 0),
            'An ATL that has not been lifted has not gone anywhere yet.'
        );

        PurchaseOrderDelivery::latest('id')->firstOrFail()->update([
            'lift_status' => PurchaseOrderDelivery::LIFT_LIFTED,
        ]);

        $this->assertSame(12000, $order->fresh()->liftedByProduct()['Diesel']);
    }

    public function test_remaining_is_ordered_minus_atld_per_product(): void
    {
        $order = $this->order('SO-REM-'.rand(1000, 9999), [
            'Diesel' => 30000,
            'Unleaded' => 10000,
        ]);
        $po = $this->po('PO-REM-'.rand(1000, 9999), ['Diesel' => 50000, 'Unleaded' => 50000]);

        $this->issueAtl($order, $po, 'Diesel', 20000);

        $html = $this->actingAs($this->purchasing)
            ->get(route('stock-orders.sales-orders.show', $order->id))
            ->assertOk()
            ->getContent();

        // 10,000 Diesel left, 10,000 Unleaded untouched.
        $this->assertStringContainsString('10,000', $html);
    }

    // ---- helpers ------------------------------------------------------------

    protected function issueAtl(Order $order, PurchaseOrder $po, string $product, int $qty): void
    {
        $this->actingAs($this->purchasing)
            ->post(route('stock-orders.store-fuel-trade-po', $order->id), [
                'product' => $product,
                'receiving_date' => now()->addDay()->format('Y-m-d'),
                'driver_name' => 'Juan Dela Cruz',
                'plate_number' => 'NBD-1234',
                'location' => 'Petron Limay Terminal',
                'atl_type' => 'DITC_ATL',
                'atl_number' => 'ATL-'.uniqid(),
                'drawdowns' => [
                    ['purchase_order_id' => $po->id, 'product' => $product, 'quantity' => $qty],
                ],
            ])
            ->assertSessionHas('success');

        $atl = PurchaseOrderDelivery::latest('id')->firstOrFail();

        if (! $atl->allocations()->exists()) {
            AtlAllocation::create([
                'purchase_order_delivery_id' => $atl->id,
                'purchase_order_id' => $po->id,
                'purchase_order_item_id' => $po->items()->where('product', $product)->value('id'),
                'product' => $product,
                'quantity' => $qty,
            ]);
        }
    }
}
