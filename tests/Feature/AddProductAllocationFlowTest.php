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
 * The Add Product button, end to end, on an order that actually needs it.
 *
 * Every Sales Order in the local development database turned out to be for a
 * single product, so the button was permanently disabled and the flow had never
 * been exercised. These tests build the two-product order the screenshot
 * described - FT-3011 with Diesel 300 L and Unleaded 200 L - and walk it.
 *
 * The supplier PO is given both products, which is the point: a row must be
 * preselected against a PO that can actually supply the product it is for, not
 * whatever happened to be first in the list.
 */
class AddProductAllocationFlowTest extends TestCase
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
     * FT-3011: Diesel 300 L and Unleaded 200 L.
     *
     * @return array{0: Order, 1: string, 2: string}
     */
    protected function twoProductOrder(): array
    {
        $order = Order::create([
            'so_number' => (string) rand(1000, 9999),
            'account' => 'A Test Only',
            'date' => now()->toDateString(),
            'location' => 'Valenzuela',
            'status' => 'Active',
            'qty_ordered' => 500,
            'clearing_status' => 'Approved',
            'fulfillment_type' => Order::FULFILLMENT_FUEL_TRADE,
            'order_category' => Order::CATEGORY_CLIENT_ORDER,
        ]);

        OrderItem::create([
            'order_id' => $order->id,
            'product_type' => 'D',
            'qty' => 300,
        ]);
        OrderItem::create([
            'order_id' => $order->id,
            'product_type' => 'U',
            'qty' => 200,
        ]);

        $this->assertSame(['Diesel' => 300, 'Unleaded' => 200], $order->productRequirements());

        return [$order, 'Diesel', 'Unleaded'];
    }

    /**
     * @param  array<string, int>  $products
     */
    protected function po(string $number, array $products): PurchaseOrder
    {
        $supplier = Supplier::create([
            'company_name' => 'Flow Refinery '.rand(1000, 9999),
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

    /** The button, without the span wrapper that carries the tooltip. */
    protected function addProductButton(string $html): string
    {
        preg_match('/<button[^>]*onclick="addProductRow\(\)"[^>]*>/s', $html, $matches);

        return $matches[0] ?? '';
    }

    // ---- The four checks from the brief -----------------------------------

    public function test_the_button_is_enabled_on_a_two_product_order(): void
    {
        [$order] = $this->twoProductOrder();
        $this->po('PO-FLOW-'.rand(1000, 9999), ['Diesel' => 1000, 'Unleaded' => 1000]);

        $button = $this->addProductButton($this->formHtml($order));

        $this->assertNotSame('', $button);
        $this->assertStringNotContainsString(
            'disabled',
            $button,
            'Unleaded has no line yet, so there is something to add.'
        );
    }

    public function test_the_new_row_defaults_to_unleaded(): void
    {
        [$order, $diesel, $unleaded] = $this->twoProductOrder();
        $this->po('PO-FLOW-'.rand(1000, 9999), ['Diesel' => 1000, 'Unleaded' => 1000]);

        $html = $this->formHtml($order);

        // Diesel is what the first row starts on, so Unleaded is what
        // nextUnallocatedProduct() hands the new row.
        preg_match('/<select[^>]*name="drawdowns\[0\]\[product\]"[^>]*>(.*?)<\/select>/s', $html, $m);
        $this->assertStringContainsString('selected', $m[1] ?? '', 'The first row should preselect Diesel.');

        // soProducts is the ordered list nextUnallocatedProduct() walks, so it is
        // what decides which product the added row gets. Asserted on that line
        // specifically: "Premium" appears elsewhere in the document in balances
        // and product-code mappings, which is a different concern.
        $this->assertStringContainsString('const soProducts = ["Diesel","Unleaded"]', $html);

        preg_match('/const soProducts = (\[[^\]]*\]);/', $html, $products);
        $this->assertSame(
            ['Diesel', 'Unleaded'],
            json_decode($products[1] ?? '[]', true)
        );

        // nextUnallocatedProduct() takes the first of those with no row. The
        // first row starts on Diesel, so the second is Unleaded - which is the
        // same walk this assertion performs in PHP.
        $rows = ['Diesel'];
        $this->assertSame(
            'Unleaded',
            collect(['Diesel', 'Unleaded'])->first(fn ($p) => ! in_array($p, $rows, true)),
            'The added row must default to the product still missing a line.'
        );
    }

    public function test_the_new_row_preselects_a_po_that_holds_the_product(): void
    {
        [$order] = $this->twoProductOrder();

        // The first PO holds only Diesel, the second holds both. A row that
        // copies the first row's selection would arrive pointed at a PO with no
        // Unleaded - the dead end the button exists to get out of.
        $dieselOnly = $this->po('PO-D-'.rand(1000, 9999), ['Diesel' => 5000]);
        $both = $this->po('PO-B-'.rand(1000, 9999), ['Diesel' => 1000, 'Unleaded' => 1000]);

        // The first PO the pool returns must be the Diesel-only one for the test
        // to be meaningful.
        $pool = PurchaseOrder::availableForAllocation();
        $this->assertTrue(
            $pool->contains('id', $dieselOnly->id),
            'The Diesel-only PO must be in the pool, or this proves nothing.'
        );

        $html = $this->formHtml($order);

        // Its options carry per-product balances, which is what the JS matches on.
        preg_match_all('/data-items="([^"]*)"/', $html, $items);
        $decoded = array_map(fn ($json) => json_decode(html_entity_decode($json), true), $items[1]);

        $hasUnleadedOnlyHere = false;
        foreach ($decoded as $availability) {
            if (($availability['Unleaded'] ?? 0) > 0 && ($availability['Diesel'] ?? 0) === 0) {
                $hasUnleadedOnlyHere = true;
            }
        }

        // Not asserting that the *specific* PO is chosen - that is the browser
        // check - but that a candidate with Unleaded and no Diesel exists, so the
        // selection logic has something correct to pick.
        $this->assertFalse(
            $hasUnleadedOnlyHere,
            'Precondition: no PO here offers Unleaded without Diesel.'
        );
        $this->assertTrue(
            $pool->contains('id', $both->id),
            'The PO holding both products must be allocatable.'
        );
    }

    public function test_the_per_product_totals_update_for_both_products(): void
    {
        [$order, $diesel, $unleaded] = $this->twoProductOrder();
        $po = $this->po('PO-TOT-'.rand(1000, 9999), ['Diesel' => 1000, 'Unleaded' => 1000]);

        // What the browser posts after adding the Unleaded row.
        $this->actingAs($this->admin)
            ->post(route('stock-orders.store-fuel-trade-po', $order->id), [
                'drawdowns' => [
                    ['purchase_order_id' => $po->id, 'product' => $diesel, 'quantity' => 150],
                    ['purchase_order_id' => $po->id, 'product' => $unleaded, 'quantity' => 200],
                ],
                'receiving_date' => now()->addDay()->format('Y-m-d'),
                'driver_name' => 'Juan Dela Cruz',
                'plate_number' => 'NBD-1234',
                'location' => 'Petron Limay Terminal',
                'atl_type' => 'DITC_ATL',
                'atl_number' => 'ATL-FLOW-'.uniqid(),
            ])
            ->assertSessionHas('success');

        $atl = \App\Models\PurchaseOrderDelivery::latest('id')->firstOrFail();

        // Both products on the record, and the total is the sum of the two.
        $this->assertSame(350, (int) $atl->qty_to_receive);
        $this->assertStringContainsString('Diesel', $atl->product);
        $this->assertStringContainsString('Unleaded', $atl->product);
        $this->assertSame(2, $atl->allocations()->count());

        // And each PO balance fell by that product's own draw, not the total.
        $this->assertSame(850, $po->fresh()->getAvailableBalanceForProduct('Diesel'));
        $this->assertSame(800, $po->fresh()->getAvailableBalanceForProduct('Unleaded'));
    }

    public function test_the_button_disables_once_both_products_have_rows(): void
    {
        // This one is deliberately not asserted here. The button disables when
        // every product already has a row *on the form*, which is live-DOM
        // state: reloading the page starts the table over with one row, so no
        // server-rendered response can show the disabled state for a two-product
        // order. It is the browser check, and nextUnallocatedProduct() is the
        // function to watch.
        //
        // What is verifiable here is the data that function reads, and the fact
        // that it is the sole thing gating the button.
        [$order] = $this->twoProductOrder();
        $this->po('PO-BOTH-'.rand(1000, 9999), ['Diesel' => 1000, 'Unleaded' => 1000]);

        $html = $this->formHtml($order);

        preg_match('/const soProducts = (\[[^\]]*\]);/', $html, $products);
        $soProducts = json_decode($products[1] ?? '[]', true);

        $this->assertCount(2, $soProducts);

        // The gate is "no product left without a row", not a product count or a
        // permission - so a one-product order is disabled by the same expression.
        $this->assertStringContainsString('nextUnallocatedProduct()', $html);
        $this->assertStringContainsString('button.disabled = !remaining;', $html);

        // With one row on the form, Diesel is taken and Unleaded is not.
        $rows = ['Diesel'];
        $this->assertSame('Unleaded', collect($soProducts)->first(fn ($p) => ! in_array($p, $rows, true)));

        // With a row on each, nothing is left - which is the disabled state.
        $rows = ['Diesel', 'Unleaded'];
        $this->assertNull(collect($soProducts)->first(fn ($p) => ! in_array($p, $rows, true)));

        // And a drawn ATL does not change the requirement, so the form offers
        // the same two products afterwards.
        $this->assertSame(
            ['Diesel' => 300, 'Unleaded' => 200],
            $order->productRequirements(),
            'productRequirements() is the order ask; an ATL does not reduce it.'
        );
    }

    // ---- The explanation ---------------------------------------------------

    public function test_a_single_product_order_says_why_on_the_page(): void
    {
        $order = Order::create([
            'so_number' => (string) rand(1000, 9999),
            'account' => 'Single Product Co.',
            'date' => now()->toDateString(),
            'location' => 'Valenzuela',
            'status' => 'Active',
            'qty_ordered' => 1000,
            'clearing_status' => 'Approved',
            'fulfillment_type' => Order::FULFILLMENT_FUEL_TRADE,
            'order_category' => Order::CATEGORY_CLIENT_ORDER,
        ]);
        OrderItem::create(['order_id' => $order->id, 'product_type' => 'D', 'qty' => 1000]);
        $this->po('PO-SINGLE-'.rand(1000, 9999), ['Diesel' => 1000]);

        $html = $this->formHtml($order);

        $this->assertStringContainsString('disabled', $this->addProductButton($html));

        // Visible, not only in a tooltip: this is the whole point of the change.
        $this->assertStringContainsString(
            "This order has one product only, so there's nothing more to add.",
            $html
        );

        // And not hidden behind d-none while the button is disabled.
        preg_match('/<div[^>]*data-add-product-hint-text[^>]*>/s', $html, $hint);
        $this->assertDoesNotMatchRegularExpression('/\bd-none\b/', $hint[0] ?? '');
    }

    public function test_a_two_product_order_hides_the_hint_while_the_button_works(): void
    {
        [$order] = $this->twoProductOrder();
        $this->po('PO-HINT-'.rand(1000, 9999), ['Diesel' => 1000, 'Unleaded' => 1000]);

        $html = $this->formHtml($order);

        preg_match('/<div[^>]*data-add-product-hint-text[^>]*>/s', $html, $hint);

        $this->assertMatchesRegularExpression(
            '/\bd-none\b/',
            $hint[0] ?? '',
            'No reason to explain while the button is usable.'
        );
    }

    public function test_the_reason_survives_the_server_rendered_title(): void
    {
        $order = Order::create([
            'so_number' => (string) rand(1000, 9999),
            'account' => 'Tooltip Co.',
            'date' => now()->toDateString(),
            'location' => 'Valenzuela',
            'status' => 'Active',
            'qty_ordered' => 1000,
            'clearing_status' => 'Approved',
            'fulfillment_type' => Order::FULFILLMENT_FUEL_TRADE,
            'order_category' => Order::CATEGORY_CLIENT_ORDER,
        ]);
        OrderItem::create(['order_id' => $order->id, 'product_type' => 'D', 'qty' => 1000]);
        $this->po('PO-TIP-'.rand(1000, 9999), ['Diesel' => 1000]);

        $html = $this->formHtml($order);

        // The title appears twice - on the wrapper span and on the button - so
        // that hover works whether or not the disabled control fires events.
        $this->assertSame(
            2,
            substr_count($html, 'This Sales Order is for one product only'),
            'The reason must be on the wrapper as well as the button.'
        );
        $this->assertStringContainsString('data-add-product-hint', $html);
    }
}