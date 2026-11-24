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
 * The supervisor wants the highest SO number first. The list is Fuel Trade
 * only, so every row renders with the FT- prefix; the numeric part is what
 * gets compared, so "999" still trails "1363" rather than sorting above it.
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
            'fulfillment_type' => Order::FULFILLMENT_FUEL_TRADE,
            'order_category' => Order::CATEGORY_CLIENT_ORDER,
        ], $overrides));
    }

    /**
     * The SO numbers in the order the page actually rendered them.
     *
     * Anchored on the cell the view gives the SO number, rather than any <td>,
     * so the digit-free rows this file also tests are picked up: the column
     * renders formatted_so_number, which prefixes Fuel Trade rows with FT-.
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

    protected function numberOf(string $formattedSoNumber): int
    {
        return (int) preg_replace('/\D+/', '', $formattedSoNumber);
    }

    public function test_orders_are_listed_highest_so_number_first(): void
    {
        foreach (['1360', '1361', '1362', '1363'] as $so) {
            $this->order($so);
        }

        $this->assertSame(['FT-1363', 'FT-1362', 'FT-1361', 'FT-1360'], $this->listedOrder());
    }

    public function test_a_short_number_sorts_numerically_not_as_text(): void
    {
        // As text, '999' > '1363' and would come first. Numerically it is the
        // oldest of the three and must come last.
        $this->order('999');
        $this->order('1000');
        $this->order('1363');

        $this->assertSame(['FT-1363', 'FT-1000', 'FT-999'], $this->listedOrder());
    }

    public function test_a_stored_ft_prefix_does_not_change_the_sequence(): void
    {
        // Some rows were saved with the FT- prefix and some without. Both
        // formats render the same way and sort as one run.
        $this->order('FT-2089');
        $this->order('2090');
        $this->order('FT-2091');
        $this->order('2092');

        $this->assertSame(['FT-2092', 'FT-2091', 'FT-2090', 'FT-2089'], $this->listedOrder());
    }

    public function test_non_fuel_trade_orders_are_not_listed(): void
    {
        $this->order('1360');
        $this->order('1361', ['fulfillment_type' => Order::FULFILLMENT_DELIVERY]);
        $this->order('BB-3009', [
            'fulfillment_type' => Order::FULFILLMENT_PICKUP,
            'order_category' => Order::CATEGORY_BUY_BACK,
        ]);

        $this->assertSame(['FT-1360'], $this->listedOrder());
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

        $this->assertSame(['FT-1363', 'FT-1360'], array_slice($listed, 0, 2));
        $this->assertSame(
            ['FT-CANCEL ORDER', 'FT-PENDING REISSUE'],
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

        $this->assertSame(['FT-1363', 'FT-CANCEL ORDER 12'], $this->listedOrder());
    }

    public function test_cancelled_orders_stay_off_the_list(): void
    {
        $this->order('1362');
        $this->order('1363', ['status' => 'Cancelled']);

        $this->assertSame(['FT-1362'], $this->listedOrder());
    }

    public function test_the_order_holds_across_pages(): void
    {
        // 20 orders, 15 to a page, so page 2 has to exist and continue the run.
        for ($i = 0; $i < 20; $i++) {
            $this->order((string) (1400 - $i));
        }

        $first = $this->listedOrder();
        $this->assertSame('FT-1400', $first[0], 'The newest is first.');

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
            $this->numberOf((string) end($first)),
            $this->numberOf($second[0]),
            'Page 2 must continue below page 1, not restart.'
        );
    }
}
