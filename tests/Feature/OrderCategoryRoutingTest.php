<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Client;
use App\Models\Order;
use Tests\TestCase;

/**
 * Order Category routing.
 *
 * Module 3 handles every category except a Buy Back order that is delivered,
 * which is routed to the Module 2 Stock Requests list instead.
 */
class OrderCategoryRoutingTest extends TestCase
{
    protected Admin $sales;

    protected Client $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sales = Admin::create([
            'username' => 'sales_' . uniqid(),
            'password' => bcrypt('secret'),
            'name' => 'Sales User',
            'role' => 'sales',
        ]);

        $this->client = Client::first() ?? Client::create(['name' => 'Apex Logistics Corp']);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    protected function storeOrder(array $overrides = []): Order
    {
        $soNumber = 'SO-'.uniqid();

        $this->actingAs($this->sales)->post(route('order.store'), array_merge([
            'account' => $this->client->name,
            'location' => 'Valenzuela',
            'so_number' => $soNumber,
            'date' => now()->format('Y-m-d'),
            'status' => 'Active',
            'terms' => 'COD',
            'items' => [['product_type' => 'D', 'qty' => 1000, 'price' => 50.00]],
        ], $overrides))->assertSessionHasNoErrors();

        return Order::where('so_number', $soNumber)->firstOrFail();
    }

    // ---- Buy Back fulfillment -------------------------------------------

    public function test_buy_back_pickup_stores_depot_pickup(): void
    {
        $order = $this->storeOrder([
            'order_type' => 'BUY_BACK',
            'buy_back_fulfillment' => 'PICKUP',
        ]);

        $this->assertEquals('BUY_BACK', $order->order_category);
        $this->assertEquals('DEPOT_PICKUP', $order->fulfillment_type);
        $this->assertFalse($order->isBuyBackDelivery());
    }

    public function test_buy_back_delivery_stores_delivery(): void
    {
        $order = $this->storeOrder([
            'order_type' => 'BUY_BACK',
            'buy_back_fulfillment' => 'DELIVERY',
        ]);

        $this->assertEquals('BUY_BACK', $order->order_category);
        $this->assertEquals('DELIVERY', $order->fulfillment_type);
        $this->assertTrue($order->isBuyBackDelivery());
    }

    public function test_buy_back_without_a_choice_keeps_its_historic_pickup_routing(): void
    {
        $order = $this->storeOrder(['order_type' => 'BUY_BACK']);

        $this->assertEquals('DEPOT_PICKUP', $order->fulfillment_type);
        $this->assertTrue(
            Order::forModuleThree()->whereKey($order->id)->exists(),
            'An unchanged Buy Back must stay in Module 3'
        );
    }

    public function test_buy_back_rejects_an_invalid_fulfillment_type(): void
    {
        $this->actingAs($this->sales)->post(route('order.store'), [
            'account' => $this->client->name,
            'location' => 'Valenzuela',
            'so_number' => 'SO-'.uniqid(),
            'date' => now()->format('Y-m-d'),
            'status' => 'Active',
            'terms' => 'COD',
            'order_type' => 'BUY_BACK',
            'buy_back_fulfillment' => 'TELEPORT',
            'items' => [['product_type' => 'D', 'qty' => 1000, 'price' => 50.00]],
        ])->assertSessionHasErrors('buy_back_fulfillment');
    }

    public function test_other_categories_do_not_need_a_fulfillment_type(): void
    {
        foreach (['REGULAR', 'FUEL_TRADE', 'CONSUMO'] as $type) {
            $this->storeOrder(['order_type' => $type]);
        }

        $this->assertSame(0, Order::buyBackDelivery()->count());
    }

    // ---- Routing to M3 ---------------------------------------------------

    /**
     * @dataProvider moduleThreeOrders
     */
    public function test_most_orders_route_to_module_three(string $type, ?string $fulfillment): void
    {
        $overrides = ['order_type' => $type];
        if ($fulfillment !== null) {
            $overrides['buy_back_fulfillment'] = $fulfillment;
        }
        $order = $this->storeOrder($overrides);

        $this->assertTrue(
            Order::forModuleThree()->whereKey($order->id)->exists(),
            "{$type} should route to Module 3"
        );
    }

    /**
     * @return array<string, array<int, string|null>>
     */
    public static function moduleThreeOrders(): array
    {
        return [
            'regular' => ['REGULAR', null],
            'fuel trade' => ['FUEL_TRADE', null],
            'consumo' => ['CONSUMO', null],
            'buy back pick-up' => ['BUY_BACK', 'PICKUP'],
        ];
    }

    public function test_buy_back_delivery_routes_to_module_two(): void
    {
        $order = $this->storeOrder([
            'order_type' => 'BUY_BACK',
            'buy_back_fulfillment' => 'DELIVERY',
        ]);

        $this->assertFalse(
            Order::forModuleThree()->whereKey($order->id)->exists(),
            'Buy Back + Delivery must not appear in Module 3'
        );
        $this->assertTrue(Order::buyBackDelivery()->whereKey($order->id)->exists());
    }

    public function test_the_sales_orders_page_follows_the_routing(): void
    {
        $m3 = $this->storeOrder(['order_type' => 'REGULAR']);
        $ft = $this->storeOrder(['order_type' => 'FUEL_TRADE']);
        $cs = $this->storeOrder(['order_type' => 'CONSUMO']);
        $bbPickup = $this->storeOrder(['order_type' => 'BUY_BACK', 'buy_back_fulfillment' => 'PICKUP']);
        $m2Only = $this->storeOrder(['order_type' => 'BUY_BACK', 'buy_back_fulfillment' => 'DELIVERY']);

        // Module 3 is not open to the sales role.
        $purchasing = Admin::create([
            'username' => 'purchasing_' . uniqid(),
            'password' => bcrypt('secret'),
            'name' => 'Rica Esaga',
            'role' => 'purchasing',
        ]);

        $response = $this->actingAs($purchasing)
            ->get(route('stock-orders.sales-orders.index'));

        $response->assertStatus(200);

        // Regular Order and Consumo now reach Module 3 for the first time.
        foreach ([$m3, $ft, $cs, $bbPickup] as $order) {
            $response->assertSee($order->formatted_so_number);
        }

        // A delivered Buy Back belongs to Module 2 instead.
        $response->assertDontSee($m2Only->formatted_so_number);
    }

    public function test_the_module_two_page_lists_routed_buy_back_orders(): void
    {
        $ops = Admin::create([
            'username' => 'ops_' . uniqid(),
            'password' => bcrypt('secret'),
            'name' => 'Ops Manager',
            'role' => 'ops_mgr',
        ]);

        $order = $this->storeOrder([
            'order_type' => 'BUY_BACK',
            'buy_back_fulfillment' => 'DELIVERY',
        ]);

        $response = $this->actingAs($ops)
            ->get(route('wetstock.stock-requests.index'));

        $response->assertStatus(200);
        $response->assertSee($order->formatted_so_number);
    }

    public function test_a_delivered_buy_back_cannot_raise_a_module_three_atl(): void
    {
        $order = $this->storeOrder([
            'order_type' => 'BUY_BACK',
            'buy_back_fulfillment' => 'DELIVERY',
        ]);

        // Buy Back pick-up still can.
        $pickup = $this->storeOrder([
            'order_type' => 'BUY_BACK',
            'buy_back_fulfillment' => 'PICKUP',
        ]);

        $this->assertFalse($order->canPrepareAtl());
        $this->assertTrue($pickup->canPrepareAtl());
    }

    // ---- Date Needed -----------------------------------------------------

    public function test_date_needed_is_optional_for_every_category(): void
    {
        foreach (['REGULAR', 'FUEL_TRADE', 'CONSUMO', 'BUY_BACK'] as $type) {
            $overrides = ['order_type' => $type];
            if ($type === 'BUY_BACK') {
                $overrides['buy_back_fulfillment'] = 'PICKUP';
            }

            $order = $this->storeOrder($overrides);
            $this->assertNull($order->fresh()->date_needed, "{$type} may be saved without a date needed");
        }
    }

    public function test_date_needed_is_saved_when_given(): void
    {
        $order = $this->storeOrder([
            'order_type' => 'REGULAR',
            'date_needed' => '2026-11-15',
        ]);

        $this->assertEquals('2026-11-15', $order->fresh()->date_needed->format('Y-m-d'));
    }

    public function test_date_needed_is_optional_on_the_form(): void
    {
        $response = $this->actingAs($this->sales)->get(route('order.create'));

        $response->assertStatus(200);
        $response->assertSee('name="date_needed"', false);
    }

    // ---- Form ------------------------------------------------------------

    public function test_the_form_renders_the_conditional_fulfillment_control(): void
    {
        $response = $this->actingAs($this->sales)->get(route('order.create'));

        $response->assertStatus(200);
        $response->assertSee('name="buy_back_fulfillment"', false);
        $response->assertSee('Buy Back', false);
    }

    public function test_editing_a_buy_back_order_prefills_its_fulfillment(): void
    {
        $order = $this->storeOrder([
            'order_type' => 'BUY_BACK',
            'buy_back_fulfillment' => 'DELIVERY',
        ]);

        $response = $this->actingAs($this->sales)->get(route('order.edit', $order->id));

        $response->assertStatus(200);
        $this->assertMatchesRegularExpression(
            '/<option value="DELIVERY"[^>]*selected/',
            $response->getContent(),
            'Delivery should be pre-selected for a delivered Buy Back order'
        );
    }
}