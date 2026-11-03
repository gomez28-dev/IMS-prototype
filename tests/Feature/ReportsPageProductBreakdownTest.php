<?php

namespace Tests\Feature;

use App\Exports\ReportsExport;
use App\Models\Admin;
use App\Models\Delivery;
use App\Models\Order;
use App\Models\OrderItem;
use Tests\TestCase;

/**
 * The Reports page summarises the orders it lists, in total and per product.
 *
 * Two things went wrong here before: the cards were summed from a clone of the
 * paginated query, so they only counted the ten rows on screen, and the order
 * count excluded cancelled orders while the list showed them.
 */
class ReportsPageProductBreakdownTest extends TestCase
{
    protected Admin $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = Admin::create([
            'username' => 'reports_'.uniqid(),
            'password' => bcrypt('secret'),
            'name' => 'Reports Viewer',
            'role' => 'admin',
        ]);
    }

    protected function order(int $soNumber, int $qty, string $product = 'D', string $status = 'Fulfilled'): Order
    {
        $order = Order::create([
            'account' => 'Report Account '.$soNumber,
            'date' => '2026-10-01',
            'qty_ordered' => $qty,
            'so_number' => (string) $soNumber,
            'clearing_status' => 'Approved',
            'status' => $status,
            'location' => 'Valenzuela',
        ]);

        OrderItem::create([
            'order_id' => $order->id,
            'product_type' => $product,
            'qty' => $qty,
            'price' => 70,
        ]);

        return $order;
    }

    protected function report(array $params = [])
    {
        return $this->actingAs($this->admin)
            ->get(route('reports.index', array_merge(['filter_mode' => 'year', 'year' => 2026], $params)))
            ->assertOk();
    }

    public function test_the_summary_cards_cover_every_matching_order_not_just_the_first_page(): void
    {
        // Twelve orders at 1,000 L each: ten fill the first page, two spill to
        // the second. The old summary summed the paginated page and said 10,000.
        for ($i = 1; $i <= 12; $i++) {
            $this->order(9000 + $i, 1000);
        }

        $response = $this->report();
        $stats = $response->viewData('productStats');

        $this->assertSame(12, $response->viewData('totalOrders'));
        $this->assertSame(12000, array_sum(array_column($stats, 'ordered')));
        $this->assertSame(12000, $stats['D']['ordered']);
    }

    public function test_a_cancelled_order_is_in_both_the_card_and_the_list(): void
    {
        $this->order(9101, 1000);
        $this->order(9102, 1000);
        $this->order(9103, 1000, 'D', 'Cancelled');

        $response = $this->report();

        $this->assertSame(3, $response->viewData('totalOrders'));
        $this->assertSame(3, $response->viewData('orders')->total());
        $this->assertStringContainsString('Showing 3 order(s)', $response->getContent());
        $this->assertMatchesRegularExpression('/stat-big">\s*3\s*</', $response->getContent());
    }

    public function test_delivered_litres_are_split_by_the_orders_products(): void
    {
        $diesel = $this->order(9201, 5000, 'D');
        $unleaded = $this->order(9202, 2000, 'U');

        Delivery::create([
            'order_id' => $diesel->id,
            'product_type' => 'D',
            'dr_number' => 'DR-RPT-1',
            'delivery_date' => '2026-10-02',
            'qty_out' => 3000,
            'status' => 'FULFILLED',
            'type' => 'BIG TANKER',
        ]);

        $stats = $this->report()->viewData('productStats');

        $this->assertSame(3000, $stats['D']['delivered']);
        $this->assertSame(2000, $stats['D']['balance']);
        $this->assertSame(0, $stats['P']['ordered']);
        $this->assertSame(2000, $stats['U']['balance']);
    }

    public function test_the_table_prints_one_product_line_per_product(): void
    {
        $order = $this->order(9301, 30000, 'D');

        OrderItem::create([
            'order_id' => $order->id,
            'product_type' => 'U',
            'qty' => 5000,
            'price' => 78,
        ]);

        $response = $this->report();
        $lines = $response->viewData('rowLines')[$order->id];

        $this->assertSame(30000, $lines['D']['ordered']);
        $this->assertSame(5000, $lines['U']['ordered']);
        $this->assertSame(35000, array_sum(array_column($lines, 'ordered')));

        $html = $response->getContent();
        $this->assertStringContainsString('pl-block', $html);
        // The card total and the two stacked product lines are all on the page.
        $this->assertStringContainsString('30,000', $html);
        $this->assertStringContainsString('5,000', $html);
        $this->assertStringContainsString('35,000', $html);
    }

    public function test_the_excel_export_carries_the_same_product_breakdown(): void
    {
        $order = $this->order(9401, 1000, 'D');

        $orders = Order::with(['items', 'deliveries.items'])->where('id', $order->id)->get();

        $export = new ReportsExport($orders, [
            $order->id => [
                'D' => ['ordered' => 1000, 'delivered' => 0, 'balance' => 1000],
                'U' => ['ordered' => 500, 'delivered' => 0, 'balance' => 500],
            ],
        ]);

        $this->assertContains('PRODUCTS', $export->headings());

        $rows = $export->array();

        $this->assertSame('Diesel 1,000 | Unleaded 500', $rows[0]['PRODUCTS']);
    }
}
