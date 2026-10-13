<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderDelivery;
use App\Models\PurchaseOrderItem;
use App\Models\Supplier;
use Tests\TestCase;

/**
 * The Create ATL action used to vanish entirely unless Accounting had cleared
 * the order, so on an uncleared order the page simply had no button and it
 * looked like the action did not exist. M1's Deliveries page instead renders a
 * disabled, locked button that says why. This is that behaviour, on this page.
 */
class SalesOrderCreateAtlButtonTest extends TestCase
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

    protected function order(string $clearing = 'Approved'): Order
    {
        $order = Order::create([
            'so_number' => 'SO-ATLB-'.rand(1000, 9999),
            'account' => 'Venture Commercial & Industrial Corp.',
            'date' => now()->toDateString(),
            'location' => 'Valenzuela',
            'status' => 'Active',
            'terms' => '30 Days',
            'qty_ordered' => 50000,
            'clearing_status' => $clearing,
            'fulfillment_type' => Order::FULFILLMENT_FUEL_TRADE,
            'order_category' => Order::CATEGORY_CLIENT_ORDER,
        ]);

        OrderItem::create([
            'order_id' => $order->id,
            'product_type' => 'D',
            'qty' => 50000,
        ]);

        return $order;
    }

    protected function po(string $poNumber, array $products): PurchaseOrder
    {
        $supplier = Supplier::create([
            'company_name' => 'Button Refinery '.rand(1000, 9999),
            'location' => 'Limay Terminal',
        ]);

        $po = PurchaseOrder::create([
            'po_number' => $poNumber,
            'po_type' => PurchaseOrder::TYPE_FUEL_TRADE,
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

    protected function detail(Order $order): string
    {
        return $this->actingAs($this->purchasing)
            ->get(route('stock-orders.sales-orders.show', $order->id))
            ->assertOk()
            ->getContent();
    }

    // ---- The button is always there ----------------------------------------

    public function test_a_cleared_order_offers_a_working_create_atl(): void
    {
        $order = $this->order('Approved');

        $html = $this->detail($order);

        $this->assertStringContainsString(route('stock-orders.create-fuel-trade-po', $order->id), $html);
        $this->assertStringContainsString('Create ATL', $html);
    }

    public function test_an_uncleared_order_shows_a_locked_button_rather_than_nothing(): void
    {
        $order = $this->order('Hold');

        $html = $this->detail($order);

        // M1's wording, so the two pages read the same way.
        $this->assertStringContainsString('Locked: Pending Accounting Clearance', $html);
        $this->assertStringContainsString('bi-lock-fill', $html);
    }

    public function test_the_locked_button_cannot_be_pressed(): void
    {
        $order = $this->order('Hold');

        $html = $this->detail($order);

        // A disabled button, not a link: there is nowhere to go.
        $this->assertStringNotContainsString(route('stock-orders.create-fuel-trade-po', $order->id), $html);
        $this->assertMatchesRegularExpression(
            '/<button[^>]*\bdisabled\b[^>]*>(?:(?!<\/button>).)*Pending Accounting Clearance/s',
            $html,
            'The locked state must be a disabled button, not merely a greyed-out link.'
        );
    }

    public function test_the_locked_button_says_nothing_about_create_atl(): void
    {
        // Keeps the clearance gate honest: an uncleared order must not be
        // offered the action in any wording.
        $this->assertStringNotContainsString('Create ATL', $this->detail($this->order('Hold')));
    }

    public function test_the_button_sits_with_the_atl_records(): void
    {
        $html = $this->detail($this->order('Approved'));

        $atlRecordsAt = strpos($html, 'ATL Records');
        $buttonAt = strpos($html, 'Create ATL');

        $this->assertIsInt($atlRecordsAt);
        $this->assertIsInt($buttonAt);
        // The supervisor pointed at the ATL Records heading as where the
        // action belongs, the way Add Delivery sits above its table on M1.
        $this->assertGreaterThan($atlRecordsAt, $buttonAt);
    }

    // ---- More than one ATL per order ---------------------------------------

    public function test_a_second_atl_can_be_raised_against_the_same_order(): void
    {
        $order = $this->order('Approved');
        $po = $this->po('PO-TWICE-'.rand(1000, 9999), ['Diesel' => 100000]);

        $submit = fn () => $this->actingAs($this->purchasing)
            ->post(route('stock-orders.store-fuel-trade-po', $order->id), [
                'receiving_date' => now()->addDays(2)->format('Y-m-d'),
                'driver_name' => 'Juan Dela Cruz',
                'plate_number' => 'NBD-1234',
                'location' => 'Petron Limay Terminal',
                'atl_type' => 'DITC_ATL',
                'atl_number' => 'ATL-'.uniqid(),
                'drawdowns' => [
                    ['purchase_order_id' => $po->id, 'product' => 'Diesel', 'quantity' => 20000],
                ],
            ])
            ->assertSessionHasNoErrors();

        $submit();
        $submit();

        $this->assertSame(
            2,
            PurchaseOrderDelivery::where('order_id', $order->id)->count(),
            'An order may carry several ATLs, as the supervisor asked.'
        );

        // And the page offers the action again afterwards.
        $this->assertStringContainsString('Create ATL', $this->detail($order->fresh()));
    }
}
