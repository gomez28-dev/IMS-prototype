<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\AtlAllocation;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderDelivery;
use App\Models\PurchaseOrderItem;
use App\Models\Supplier;
use App\Models\Warehouse;
use Tests\TestCase;

/**
 * Module 3 Reports: the purchase-order report behind the Stock Orders
 * sidebar's Reports tab.
 *
 * The cards, the table and the spreadsheet all describe the same filtered
 * purchase orders, counted by PO date with a fallback for rows that predate it.
 */
class StockOrderReportTest extends TestCase
{
    protected Admin $purchasing;

    protected Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();

        $this->purchasing = Admin::create([
            'username' => 'purchasing_' . uniqid(),
            'password' => bcrypt('secret'),
            'name' => 'Rica Esaga',
            'role' => 'purchasing',
        ]);

        $this->warehouse = Warehouse::create([
            'name' => 'San Simon',
            'location' => 'San Simon',
        ]);
    }

    protected function supplier(string $name = 'Petron'): Supplier
    {
        // suppliers is unique on company + location, and several tests raise
        // more than one PO for the same supplier.
        return Supplier::firstOrCreate(
            ['company_name' => $name, 'location' => 'Limay'],
            ['is_active' => true]
        );
    }

    /**
     * A real purchase order with its product lines.
     *
     * @param  array<string, int>  $lines  product name => litres
     */
    protected function po(
        string $number,
        ?string $poDate,
        array $lines = ['Diesel' => 5000],
        float $amount = 500000.00,
        ?Supplier $supplier = null,
        ?string $requestDate = null
    ): PurchaseOrder {
        $supplier = $supplier ?? $this->supplier();
        $qty = array_sum($lines);

        $po = PurchaseOrder::create([
            'po_number' => $number,
            'po_type' => PurchaseOrder::TYPE_STANDARD_REPLENISHMENT,
            'supplier_id' => $supplier->id,
            'supplier_name' => $supplier->company_name,
            'warehouse_id' => $this->warehouse->id,
            'qty_ordered' => $qty,
            'total_amount' => $amount,
            'net_payable_amount' => $amount,
            'request_status' => 'CONFIRMED',
            'status' => 'Pending',
            'po_date' => $poDate,
            // request_date is non-nullable, so every PO has one; the fallback
            // test below is about rows whose po_date is the missing field.
            'request_date' => ($requestDate ?? $poDate ?? now()->toDateString()) . ' 09:00:00',
        ]);

        foreach ($lines as $product => $qty) {
            PurchaseOrderItem::create([
                'purchase_order_id' => $po->id,
                'product' => $product,
                'quantity_ordered' => $qty,
                'unit_price' => 94.00,
            ]);
        }

        return $po->fresh();
    }

    /**
     * An ATL drawing from a purchase order, at a given lift state.
     */
    protected function atl(PurchaseOrder $po, string $product, int $qty, string $liftStatus): PurchaseOrderDelivery
    {
        $delivery = PurchaseOrderDelivery::create([
            'purchase_order_id' => $po->id,
            'atl_number' => 'ATL-' . rand(100000, 999999),
            'product' => $product,
            'qty_to_receive' => $qty,
            'receiving_date' => now()->addDay(),
            'status' => 'Active',
            'lift_status' => $liftStatus,
        ]);

        AtlAllocation::create([
            'purchase_order_delivery_id' => $delivery->id,
            'purchase_order_id' => $po->id,
            'product' => $product,
            'quantity' => $qty,
        ]);

        return $delivery;
    }

    protected function open(array $query = [])
    {
        return $this->actingAs($this->purchasing)
            ->get(route('stock-orders.reports.index', $query));
    }

    public function test_the_report_page_opens_for_a_stock_orders_role(): void
    {
        $this->open()
            ->assertOk()
            ->assertSee('Stock Orders Report')
            ->assertSee('Date range')
            ->assertSee('Select a date range, month, or year above');
    }

    public function test_roles_outside_stock_orders_cannot_open_the_report(): void
    {
        $sales = Admin::create([
            'username' => 'sales_' . uniqid(),
            'password' => bcrypt('secret'),
            'name' => 'Sales',
            'role' => 'sales',
        ]);

        $this->actingAs($sales)->get(route('stock-orders.reports.index'))->assertForbidden();

        auth()->logout();
        $this->get(route('stock-orders.reports.index'))->assertRedirect(route('login'));
    }

    public function test_the_read_only_roles_can_open_the_report(): void
    {
        foreach (['viewer', 'audit'] as $role) {
            $reader = Admin::create([
                'username' => $role . '_' . uniqid(),
                'password' => bcrypt('secret'),
                'name' => $role,
                'role' => $role,
            ]);

            $this->actingAs($reader)->get(route('stock-orders.reports.index'))->assertOk();
        }
    }

    public function test_the_period_filter_counts_purchase_orders_by_their_po_date(): void
    {
        $this->po('PO-AUG-1', '2026-08-15');
        $this->po('PO-SEP-1', '2026-09-10');

        $response = $this->open([
            'filter_mode' => 'range',
            'from' => '2026-09-01',
            'to' => '2026-09-30',
        ])->assertOk();

        $this->assertSame(1, $response->viewData('summary')['count']);
        $response->assertSee('PO-SEP-1');
        $response->assertDontSee('PO-AUG-1');
    }

    public function test_a_purchase_order_without_a_po_date_falls_back_to_its_request_date(): void
    {
        $this->po('PO-NO-DATE', null, ['Diesel' => 5000], 500000.00, null, '2026-09-05');

        $response = $this->open([
            'filter_mode' => 'range',
            'from' => '2026-09-01',
            'to' => '2026-09-30',
        ])->assertOk();

        $this->assertSame(1, $response->viewData('summary')['count']);
        $response->assertSee('PO-NO-DATE');
    }

    public function test_the_month_and_year_filters_use_the_same_date(): void
    {
        $this->po('PO-SEP-2', '2026-09-20');
        $this->po('PO-OCT-1', '2026-10-02');

        $month = $this->open([
            'filter_mode' => 'month_year',
            'month' => '10',
            'year' => '2026',
        ])->assertOk();

        $this->assertSame(1, $month->viewData('summary')['count']);
        $month->assertSee('PO-OCT-1')->assertDontSee('PO-SEP-2');

        $year = $this->open([
            'filter_mode' => 'year',
            'year' => '2026',
        ])->assertOk();

        $this->assertSame(2, $year->viewData('summary')['count']);
    }

    public function test_the_cards_split_ordered_lifted_and_unlifted_by_product(): void
    {
        $po = $this->po('PO-MIXED', '2026-09-12', ['Diesel' => 6000, 'Premium' => 4000], 1000000.00);

        // One lifted ATL for Diesel, and two still unlifted: one per product.
        $this->atl($po, 'Diesel', 2000, PurchaseOrderDelivery::LIFT_LIFTED);
        $this->atl($po, 'Diesel', 1500, PurchaseOrderDelivery::LIFT_UNLIFTED);
        $this->atl($po, 'Premium', 500, PurchaseOrderDelivery::LIFT_UNLIFTED);

        // A cancelled delivery counts as neither lifted nor unlifted.
        $this->atl($po, 'Diesel', 900, PurchaseOrderDelivery::LIFT_CANCELLED);

        $summary = $this->open([
            'filter_mode' => 'range',
            'from' => '2026-09-01',
            'to' => '2026-09-30',
        ])->assertOk()->viewData('summary');

        $this->assertSame(1, $summary['count']);
        $this->assertEquals(1000000.00, $summary['value']);

        // Ordered comes from the PO lines, untouched by what has been lifted.
        $this->assertSame(6000, $summary['ordered']['D']);
        $this->assertSame(4000, $summary['ordered']['P']);
        $this->assertSame(0, $summary['ordered']['U']);

        $this->assertSame(2000, $summary['lifted']['D']);
        $this->assertSame(0, $summary['lifted']['P']);

        $this->assertSame(1500, $summary['unlifted']['D']);
        $this->assertSame(500, $summary['unlifted']['P']);
    }

    public function test_ordered_falls_back_to_the_request_payload_when_a_po_has_no_lines(): void
    {
        // Rows raised before line items existed kept their figures on the
        // request payload; without the fallback their litres would vanish.
        $po = PurchaseOrder::create([
            'po_number' => 'PO-LEGACY',
            'po_type' => PurchaseOrder::TYPE_STANDARD_REPLENISHMENT,
            'warehouse_id' => $this->warehouse->id,
            'qty_ordered' => 9901,
            'total_amount' => 900000.00,
            'request_status' => 'CONFIRMED',
            'status' => 'Pending',
            'po_date' => '2026-09-14',
            'requested_products' => [
                ['product' => 'Diesel', 'quantity' => '9901'],
            ],
        ]);

        $summary = $this->open([
            'filter_mode' => 'range',
            'from' => '2026-09-01',
            'to' => '2026-09-30',
        ])->assertOk()->viewData('summary');

        $this->assertSame(9901, $summary['ordered']['D']);
    }

    public function test_the_supplier_filter_narrows_the_report(): void
    {
        $petron = $this->supplier('Petron');
        $shell = $this->supplier('Shell');

        $this->po('PO-PETRON', '2026-09-03', ['Diesel' => 1000], 100000.00, $petron);
        $this->po('PO-SHELL', '2026-09-04', ['Diesel' => 2000], 200000.00, $shell);

        $response = $this->open([
            'filter_mode' => 'range',
            'from' => '2026-09-01',
            'to' => '2026-09-30',
            'supplier_id' => $shell->id,
        ])->assertOk();

        $this->assertSame(1, $response->viewData('summary')['count']);
        $response->assertSee('PO-SHELL')->assertDontSee('PO-PETRON');
    }

    public function test_stock_requests_and_fuel_trade_rows_stay_out_of_the_report(): void
    {
        $this->po('PO-REAL', '2026-09-06');

        // A Module 2 stock request: no PO number.
        PurchaseOrder::create([
            'po_type' => PurchaseOrder::TYPE_STANDARD_REPLENISHMENT,
            'warehouse_id' => $this->warehouse->id,
            'qty_ordered' => 100,
            'request_status' => 'REQUESTED',
            'status' => 'Pending',
            'request_date' => '2026-09-06 09:00:00',
        ]);

        // A Module 1 SO-FT drawdown row: a number, but not a real PO.
        PurchaseOrder::create([
            'po_number' => 'FT-DRAWDOWN',
            'po_type' => PurchaseOrder::TYPE_FUEL_TRADE,
            'warehouse_id' => $this->warehouse->id,
            'qty_ordered' => 1000,
            'status' => 'Pending',
            'po_date' => '2026-09-06',
        ]);

        $response = $this->open([
            'filter_mode' => 'range',
            'from' => '2026-09-01',
            'to' => '2026-09-30',
        ])->assertOk();

        $this->assertSame(1, $response->viewData('summary')['count']);
        $response->assertSee('PO-REAL')
            ->assertDontSee('FT-DRAWDOWN');
    }

    public function test_the_report_downloads_as_a_spreadsheet(): void
    {
        $this->po('PO-EXPORT', '2026-09-08');

        $response = $this->actingAs($this->purchasing)->get(route('stock-orders.reports.export', [
            'filter_mode' => 'range',
            'from' => '2026-09-01',
            'to' => '2026-09-30',
        ]));

        $response->assertOk();

        $disposition = $response->headers->get('content-disposition');
        $this->assertStringContainsString('STOCK_ORDERS_REPORT_', $disposition);
        $this->assertStringContainsString('.xlsx', $disposition);
    }

    public function test_the_stock_orders_sidebar_links_to_the_report(): void
    {
        $this->actingAs($this->purchasing)
            ->get(route('stock-orders.purchase-orders.index'))
            ->assertOk()
            ->assertSee(route('stock-orders.reports.index'), false)
            ->assertDontSee('Reports (coming soon)');
    }
}
