<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Client;
use App\Models\Order;
use Tests\TestCase;

/**
 * The New Order form now posts a single order_type that expands into the
 * fulfillment_type + order_category pair, and SO numbers are displayed with a
 * type prefix.
 */
class OrderCategoryTest extends TestCase
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
    protected function storeOrder(array $overrides = []): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->sales)->post(route('order.store'), array_merge([
            'account' => $this->client->name,
            'location' => 'Valenzuela',
            'so_number' => 'SO-'.rand(1000, 9999),
            'date' => now()->format('Y-m-d'),
            'status' => 'Active',
            'terms' => 'COD',
            'items' => [
                ['product_type' => 'D', 'qty' => 1000, 'price' => 50.00],
            ],
        ], $overrides));
    }

    /**
     * @dataProvider categoryMatrix
     */
    public function test_order_type_expands_into_the_right_pair(
        string $orderType,
        string $fulfillmentType,
        string $orderCategory
    ): void {
        $soNumber = 'SO-CAT-'.uniqid();

        $this->storeOrder([
            'so_number' => $soNumber,
            'order_type' => $orderType,
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('orders', [
            'so_number' => $soNumber,
            'fulfillment_type' => $fulfillmentType,
            'order_category' => $orderCategory,
        ]);
    }

    /**
     * @return array<string, array<int, string>>
     */
    public static function categoryMatrix(): array
    {
        return [
            'regular' => ['REGULAR', 'DELIVERY', 'CLIENT_ORDER'],
            'fuel trade' => ['FUEL_TRADE', 'FUEL_TRADE', 'CLIENT_ORDER'],
            'buy back' => ['BUY_BACK', 'DEPOT_PICKUP', 'BUY_BACK'],
            'consumo' => ['CONSUMO', 'DELIVERY', 'CONSUMO'],
        ];
    }

    public function test_order_type_is_required(): void
    {
        $this->storeOrder()->assertSessionHasErrors('order_type');
    }

    public function test_terms_are_required(): void
    {
        $this->storeOrder(['order_type' => 'REGULAR', 'terms' => null])
            ->assertSessionHasErrors('terms');
    }

    /**
     * @dataProvider prefixCases
     */
    public function test_so_number_is_displayed_with_its_type_prefix(
        string $soNumber,
        string $fulfillmentType,
        string $orderCategory,
        string $expected
    ): void {
        $order = new Order([
            'so_number' => $soNumber,
            'fulfillment_type' => $fulfillmentType,
            'order_category' => $orderCategory,
        ]);

        $this->assertEquals($expected, $order->formatted_so_number);
    }

    /**
     * @return array<string, array<int, string>>
     */
    public static function prefixCases(): array
    {
        return [
            'fuel trade' => ['0042', 'FUEL_TRADE', 'CLIENT_ORDER', 'FT-0042'],
            'buy back' => ['0042', 'DEPOT_PICKUP', 'BUY_BACK', 'BB-0042'],
            'consumo' => ['0042', 'DELIVERY', 'CONSUMO', 'CSM-0042'],
            'regular has no prefix' => ['0042', 'DELIVERY', 'CLIENT_ORDER', '0042'],
        ];
    }

    /**
     * @dataProvider prefixSwapCases
     */
    public function test_an_existing_prefix_is_replaced_rather_than_stacked(
        string $stored,
        string $fulfillmentType,
        string $orderCategory,
        string $expected
    ): void {
        $order = new Order([
            'so_number' => $stored,
            'fulfillment_type' => $fulfillmentType,
            'order_category' => $orderCategory,
        ]);

        // SO-0042 must never render as FT-SO-0042.
        $this->assertEquals($expected, $order->formatted_so_number);
    }

    /**
     * @return array<string, array<int, string>>
     */
    public static function prefixSwapCases(): array
    {
        return [
            'so prefix becomes ft' => ['SO-0042', 'FUEL_TRADE', 'CLIENT_ORDER', 'FT-0042'],
            'so prefix becomes bb' => ['SO-0042', 'DEPOT_PICKUP', 'BUY_BACK', 'BB-0042'],
            'so prefix is stripped' => ['SO-0042', 'DELIVERY', 'CLIENT_ORDER', '0042'],
            'ft prefix becomes bb' => ['FT-0042', 'DEPOT_PICKUP', 'BUY_BACK', 'BB-0042'],
            'existing ft prefix is kept' => ['FT-0042', 'FUEL_TRADE', 'CLIENT_ORDER', 'FT-0042'],
        ];
    }

    public function test_an_order_without_a_number_shows_a_placeholder(): void
    {
        $order = new Order(['so_number' => null]);

        $this->assertEquals('—', $order->formatted_so_number);
    }

    public function test_fuel_trade_wins_the_prefix_over_buy_back(): void
    {
        $order = new Order([
            'so_number' => '0042',
            'fulfillment_type' => 'FUEL_TRADE',
            'order_category' => 'BUY_BACK',
        ]);

        $this->assertEquals('FT', $order->so_prefix);
    }

    public function test_the_edit_form_posts_a_single_order_type(): void
    {
        $order = $this->actingAs($this->sales)->post(route('order.store'), [
            'account' => $this->client->name,
            'location' => 'Valenzuela',
            'so_number' => 'SO-EDIT-'.uniqid(),
            'date' => now()->format('Y-m-d'),
            'status' => 'Active',
            'terms' => 'COD',
            'order_type' => 'FUEL_TRADE',
            'items' => [['product_type' => 'D', 'qty' => 1000, 'price' => 50.00]],
        ])->assertSessionHasNoErrors();

        $stored = Order::where('fulfillment_type', 'FUEL_TRADE')->latest('id')->firstOrFail();

        $response = $this->actingAs($this->sales)->get(route('order.edit', $stored->id));

        $response->assertOk();
        $response->assertSee('name="order_type"', false);
        // The old pair of dropdowns is gone from the form.
        $response->assertDontSee('name="fulfillment_type"', false);
        $response->assertDontSee('name="order_category"', false);
        // Terms is now mandatory.
        $response->assertSee('name="terms"', false);
    }
}