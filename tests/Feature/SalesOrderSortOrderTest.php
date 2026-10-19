<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Order;
use Tests\TestCase;

/**
 * "Sales Orders Needing an ATL" listed orders in whatever order the database
 * happened to return them - index() had no ordering at all, so the ascending
 * sequence was just insertion order and would change as rows were touched.
 *
 * The supervisor wants the highest SO number first. The subtlety is that one
 * sequence is shared across formats: BB-3010, BB-3009, 2092, FT-2091, 2090,
 * FT-2089. Sorting the column as text puts 999 above 1363 and splits one
 * sequence into four, so the numeric part is what gets compared and the prefix
 * is ignored.
 */
class SalesOrderSortOrderTest extends TestCase
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
     * @param  array<string, string>  $overrides
     */
    protected function order(string $soNumber, array $overrides = []): Order
    {
        return Order::create(array_merge([
            'so_number' => $soNumber,
            'account' => 'Sort Test Co.',
            'date' => now()->toDateString(),
            'location' => 'Valenzuela',
            'status' => 'Active',
            'qty_ordered' => 1000,
            'clearing_status' => 'Approved',
            'fulfillment_type' => Order::FULFILLMENT_DELIVERY,
            'order_category' => Order::CATEGORY_CLIENT_ORDER,
        ], $overrides));
    }

    /**
     * The SO numbers in the order the page actually rendered them.
     *
     * Anchored on the cell the view gives the SO number, rather than any <td>,
     * so the digit-free rows this file also tests are picked up: the column
     * renders formatted_so_number, which carries the FT-/BB- prefix.
     *
     * @return array<int, string>
     */
    protected function listedOrder(): array
    {
        $html = $this->actingAs($this->admin)
            ->get(route('stock-orders.sales-orders.index'))
            ->assertOk()
            ->getContent();

        preg_match_all(
            '/<td class="ps-3 fw-bold text-dark">\s*([^<]+?)\s*<\/td>/',
            $html,
            $matches
        );

        return array_values(array_map('trim', $matches[1]));
    }

    public function test_orders_are_listed_highest_so_number_first(): void
    {
        foreach (['1360', '1361', '1362', '1363'] as $so) {
            $this->order($so);
        }

        $this->assertSame(['1363', '1362', '1361', '1360'], $this->listedOrder());
    }

    public function test_a_short_number_sorts_numerically_not_as_text(): void
    {
        // As text, '999' > '1363' and would come first. Numerically it is the
        // oldest of the three and must come last.
        $this->order('999');
        $this->order('1000');
        $this->order('1363');

        $this->assertSame(['1363', '1000', '999'], $this->listedOrder());
    }

    public function test_the_prefix_does_not_split_one_sequence(): void
    {
        // One shared sequence across four formats. The number decides, not the
        // prefix, so these come out as a single descending run.
        $this->order('2088', ['fulfillment_type' => Order::FULFILLMENT_DELIVERY, 'order_category' => Order::CATEGORY_CLIENT_ORDER]);
        $this->order('FT-2089', ['fulfillment_type' => Order::FULFILLMENT_FUEL_TRADE]);
        $this->order('2090');
        $this->order('FT-2091', ['fulfillment_type' => Order::FULFILLMENT_FUEL_TRADE]);
        $this->order('2092');
        $this->order('BB-3009', ['order_category' => Order::CATEGORY_BUY_BACK]);
        $this->order('BB-3010', ['order_category' => Order::CATEGORY_BUY_BACK]);

        $this->assertSame(
            ['BB-3010', 'BB-3009', '2092', 'FT-2091', '2090', 'FT-2089', '2088'],
            $this->listedOrder()
        );
    }

    public function test_a_buy_back_outranks_a_higher_numbered_fuel_trade(): void
    {
        // Prefixes carry no weight: BB-3010 beats FT-2999 on the number alone.
        $this->order('FT-2999', ['fulfillment_type' => Order::FULFILLMENT_FUEL_TRADE]);
        $this->order('BB-3010', ['order_category' => Order::CATEGORY_BUY_BACK]);

        $this->assertSame(['BB-3010', 'FT-2999'], $this->listedOrder());
    }

    public function test_an_so_with_no_numeric_part_sorts_last(): void
    {
        // An unnumbered row. A migration in this repo already references
        // so_number LIKE '%CANCEL ORDER%'; CAST would silently make it 0 and
        // drop it in among the very oldest real orders.
        $this->order('CANCEL ORDER');
        $this->order('1363');
        $this->order('PENDING REISSUE');
        $this->order('1360');

        $listed = $this->listedOrder();

        $this->assertSame(['1363', '1360'], array_slice($listed, 0, 2));
        $this->assertSame(
            ['CANCEL ORDER', 'PENDING REISSUE'],
            array_slice($listed, 2),
            'Rows with no number trail, ordered among themselves.'
        );
    }

    public function test_a_trailing_number_in_an_unnumbered_row_still_sorts_by_it(): void
    {
        // "CANCEL ORDER 12" does carry a number, so it sorts by 12 - the oldest
        // of the three - rather than being treated as unnumbered.
        $this->order('CANCEL ORDER 12');
        $this->order('1363');

        $this->assertSame(['1363', 'CANCEL ORDER 12'], $this->listedOrder());
    }

    public function test_cancelled_orders_stay_off_the_list(): void
    {
        $this->order('1362');
        $this->order('1363', ['status' => 'Cancelled']);

        $this->assertSame(['1362'], $this->listedOrder());
    }

    public function test_the_order_holds_across_pages(): void
    {
        // 20 orders, 15 to a page, so page 2 has to exist and continue the run.
        for ($i = 0; $i < 20; $i++) {
            $this->order((string) (1400 - $i));
        }

        $first = $this->listedOrder();
        $this->assertSame('1400', $first[0], 'The newest is first.');

        $secondPage = $this->actingAs($this->admin)
            ->get(route('stock-orders.sales-orders.index', ['page' => 2]))
            ->assertOk()
            ->getContent();

        preg_match_all(
            '/<td class="ps-3 fw-bold text-dark">\s*([^<]+?)\s*<\/td>/',
            $secondPage,
            $matches
        );
        $second = array_values(array_map('trim', $matches[1]));

        $this->assertNotEmpty($second, 'There should be a second page.');
        $this->assertLessThan(
            (int) end($first),
            (int) $second[0],
            'Page 2 must continue below page 1, not restart.'
        );
    }
}