<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\AtlAllocation;
use App\Models\Client;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderDelivery;
use Tests\TestCase;

/**
 * Supervisor wireframe: ALLOCATE PO# -> ALLOCATE PRODUCT -> ALLOCATE QUANTITY,
 * where the system states the available volume and the user then types a
 * quantity. The dropdown offers only POs that still have something left.
 */
class AllocatePoolTest extends TestCase
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

    protected function clearedOrder(int $qty = 1000): Order
    {
        $client = Client::first() ?? Client::create(['name' => 'Apex Logistics Corp']);

        $order = Order::create([
            'account' => $client->name,
            'location' => 'Valenzuela',
            'so_number' => (string) rand(100000, 999999),
            'date' => now(),
            'date_needed' => now()->addDays(5)->toDateString(),
            'status' => 'Active',
            'terms' => 'COD',
            'clearing_status' => 'Approved',
            'order_category' => Order::CATEGORY_CLIENT_ORDER,
            'fulfillment_type' => Order::FULFILLMENT_FUEL_TRADE,
            'qty_ordered' => $qty,
            'created_by' => $this->purchasing->id,
        ]);

        OrderItem::create([
            'order_id' => $order->id,
            'product_type' => 'D',
            'qty' => $qty,
            'price' => 50,
        ]);

        return $order->fresh();
    }

    /**
     * A supplier PO with the given per-product balances. Passing an empty
     * array means nothing has been drawn, so everything is still available.
     */
    protected function po(string $number, array $balances, array $drawn = []): PurchaseOrder
    {
        $po = PurchaseOrder::create([
            'po_number' => $number,
            'po_type' => PurchaseOrder::TYPE_STANDARD_REPLENISHMENT,
            'qty_ordered' => array_sum($balances),
            'request_status' => 'CONFIRMED',
            'status' => 'Pending',
            'prepared_by' => $this->purchasing->id,
        ]);

        foreach ($balances as $product => $qty) {
            \App\Models\PurchaseOrderItem::create([
                'purchase_order_id' => $po->id,
                'product' => $product,
                'quantity_ordered' => $qty,
                'unit_price' => 50,
            ]);
        }

        if ($drawn !== []) {
            $delivery = PurchaseOrderDelivery::create([
                'purchase_order_id' => $po->id,
                'delivery_channel' => 'SUPPLIER_CLIENT_PICKUP',
                'atl_category' => PurchaseOrderDelivery::CATEGORY_FUEL_TRADE,
                'product' => implode(' + ', array_keys($drawn)),
                'qty_to_receive' => array_sum($drawn),
                'status' => 'Active',
                'lift_status' => PurchaseOrderDelivery::LIFT_LIFTED,
                'approval_status' => PurchaseOrderDelivery::APPROVAL_APPROVED,
            ]);

            foreach ($drawn as $product => $qty) {
                AtlAllocation::create([
                    'purchase_order_delivery_id' => $delivery->id,
                    'purchase_order_id' => $po->id,
                    'product' => $product,
                    'quantity' => $qty,
                ]);
            }
        }

        return $po->fresh();
    }

    // ---- The three steps --------------------------------------------------

    public function test_the_three_allocate_steps_are_labelled(): void
    {
        $this->po('DITC-0056', ['Diesel' => 5000]);
        $order = $this->clearedOrder();

        $html = $this->actingAs($this->purchasing)
            ->get(route('stock-orders.create-fuel-trade-po', $order->id))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Allocate PO#', $html);
        $this->assertStringContainsString('Allocate Product', $html);
        $this->assertStringContainsString('Allocate Quantity', $html);
    }

    public function test_the_system_states_the_available_volume_before_the_user_types(): void
    {
        $this->po('DITC-0056', ['Diesel' => 5000]);
        $order = $this->clearedOrder();

        $html = $this->actingAs($this->purchasing)
            ->get(route('stock-orders.create-fuel-trade-po', $order->id))
            ->assertOk()
            ->getContent();

        // A visible badge in the quantity step, populated by the JS.
        $this->assertStringContainsString('data-avail-hint', $html);
        $this->assertStringContainsString('L Available', $html);
        $this->assertStringContainsString('function updateAvailHint', $html);
    }

    public function test_the_quantity_stays_manual_with_a_step_of_one(): void
    {
        $this->po('DITC-0056', ['Diesel' => 5000]);
        $order = $this->clearedOrder();

        $html = $this->actingAs($this->purchasing)
            ->get(route('stock-orders.create-fuel-trade-po', $order->id))
            ->assertOk()
            ->getContent();

        // Manual entry, and a step fine enough not to snap round values.
        $this->assertMatchesRegularExpression('/name="drawdowns\[0\]\[quantity\]"[^>]*step="1"/', $html);
    }

    public function test_products_narrow_to_what_the_chosen_po_holds(): void
    {
        // This PO has Diesel only.
        $this->po('DITC-0056', ['Diesel' => 5000]);
        $order = $this->clearedOrder();

        $html = $this->actingAs($this->purchasing)
            ->get(route('stock-orders.create-fuel-trade-po', $order->id))
            ->assertOk()
            ->getContent();

        // The dropdown carries this PO's availability for the JS to filter by.
        $this->assertStringContainsString('data-items=', $html);
        $this->assertStringContainsString('function refreshProductOptions', $html);

        // This PO holds Diesel only, so its data-items map must not advertise
        // the other two products.
        preg_match('/data-items="([^"]*)"/', $html, $m);
        $this->assertNotEmpty($m, 'No PO availability map rendered');
        $availability = json_decode(html_entity_decode($m[1], ENT_QUOTES), true);

        $this->assertArrayHasKey('Diesel', $availability);
        $this->assertArrayNotHasKey('Unleaded', $availability);
        $this->assertArrayNotHasKey('Premium', $availability);
    }

    // ---- The PO dropdown --------------------------------------------------

    public function test_the_dropdown_offers_a_po_with_remaining_quantity(): void
    {
        $this->po('DITC-0056', ['Diesel' => 5000]);
        $order = $this->clearedOrder();

        $this->actingAs($this->purchasing)
            ->get(route('stock-orders.create-fuel-trade-po', $order->id))
            ->assertOk()
            ->assertSee('DITC-0056', false);
    }

    public function test_the_dropdown_omits_a_po_that_is_fully_drawn(): void
    {
        $this->po('DITC-EMPTY', ['Diesel' => 5000], ['Diesel' => 5000]);
        $this->po('DITC-LEFT', ['Diesel' => 5000]);
        $order = $this->clearedOrder();

        $response = $this->actingAs($this->purchasing)
            ->get(route('stock-orders.create-fuel-trade-po', $order->id));

        $response->assertOk();
        $response->assertSee('DITC-LEFT', false);
        $response->assertDontSee('DITC-EMPTY', false);
    }

    /**
     * A PO holding only Premium still has something left, so it belongs in the
     * dropdown even though it has no Diesel.
     */
    public function test_a_po_holding_only_premium_is_still_offered(): void
    {
        $this->po('DITC-PREMIUM-ONLY', ['Premium' => 3000]);
        $order = $this->clearedOrder();

        $this->actingAs($this->purchasing)
            ->get(route('stock-orders.create-fuel-trade-po', $order->id))
            ->assertOk()
            ->assertSee('DITC-PREMIUM-ONLY', false);
    }

    // ---- Multiple rows ----------------------------------------------------

    public function test_a_second_allocation_row_can_be_added(): void
    {
        $this->po('DITC-0056', ['Diesel' => 5000]);
        $this->po('DITC-0057', ['Diesel' => 3000]);
        $order = $this->clearedOrder();

        $html = $this->actingAs($this->purchasing)
            ->get(route('stock-orders.create-fuel-trade-po', $order->id))
            ->assertOk()
            ->getContent();

        // The supervisor answered "A" for this: a second row is allowed.
        $this->assertStringContainsString('addDrawdownRow', $html);
        $this->assertStringContainsString('Split Across Another PO', $html);
        $this->assertStringContainsString('DITC-0057', $html);
    }

    /**
     * The supervisor's worked example: SO-FT 001 needs 50 KL, Purchasing buys
     * 150 KL, draws 50 KL for that order, and the remaining 100 KL stays on
     * the PO for Doyen Stock Requests and future SO-FT / SO-BB orders.
     */
    public function test_surplus_stays_available_after_a_drawdown(): void
    {
        $po = $this->po('DITC-SURPLUS', ['Diesel' => 150000]);
        $order = $this->clearedOrder(50000);

        $this->actingAs($this->purchasing)
            ->post(route('stock-orders.store-fuel-trade-po', $order->id), [
                'receiving_date' => now()->addDays(2)->format('Y-m-d'),
                'driver_name' => 'Juan Dela Cruz',
                'plate_number' => 'NBD-1234',
                'location' => 'Petron Limay Terminal',
                'atl_type' => 'DITC_ATL',
                'atl_number' => 'ATL-'.uniqid(),
                'drawdowns' => [
                    ['purchase_order_id' => $po->id, 'product' => 'Diesel', 'quantity' => 50000],
                ],
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(100000, $po->fresh()->getAvailableBalanceForProduct('Diesel'));
    }
}