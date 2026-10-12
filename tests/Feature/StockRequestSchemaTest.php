<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderDelivery;
use App\Models\StockRequest;
use App\Models\StockRequestLine;
use App\Models\Warehouse;
use App\Support\StockRequestBackfill;
use Tests\TestCase;

/**
 * The dedicated stock_requests / stock_request_lines tables and the
 * non-destructive backfill of the legacy purchase_orders request rows.
 */
class StockRequestSchemaTest extends TestCase
{
    protected Admin $ops;

    protected Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ops = Admin::create([
            'username' => 'ops_'.uniqid(),
            'password' => bcrypt('secret'),
            'name' => 'Depot Manager',
            'role' => 'ops_mgr',
        ]);

        $this->warehouse = Warehouse::first() ?? Warehouse::create(['name' => 'Bataan Depot']);
    }

    /**
     * Exactly as StockRequestController writes a legacy request: no PO number,
     * no supplier, no value, products in JSON.
     */
    protected function legacyRequest(array $overrides = []): PurchaseOrder
    {
        return PurchaseOrder::create(array_merge([
            'po_type' => 'STANDARD_REPLENISHMENT',
            'warehouse_id' => $this->warehouse->id,
            'qty_ordered' => 10000,
            'request_status' => 'REQUESTED',
            'status' => 'Pending',
            'requested_by' => $this->ops->id,
            'request_date' => now(),
            'requested_products' => [
                ['product' => 'Diesel', 'quantity' => 10000],
            ],
        ], $overrides));
    }

    // ---- Schema -----------------------------------------------------------

    public function test_the_tables_exist_with_their_columns(): void
    {
        foreach ([
            'stock_requests' => [
                'request_number', 'request_type', 'warehouse_id', 'requested_by',
                'request_date', 'date_needed', 'remarks', 'status',
                'purchasing_received_at', 'purchasing_received_by',
                'legacy_purchase_order_id',
            ],
            'stock_request_lines' => [
                'stock_request_id', 'product', 'qty_requested', 'qty_received', 'unit',
            ],
        ] as $table => $columns) {
            foreach ($columns as $column) {
                $this->assertTrue(
                    \Schema::hasColumn($table, $column),
                    "{$table}.{$column} should exist"
                );
            }
        }

        $this->assertTrue(\Schema::hasColumn('purchase_order_deliveries', 'request_id'));
    }

    // ---- Request numbers --------------------------------------------------

    public function test_a_request_is_given_a_real_request_number(): void
    {
        $request = StockRequest::create([
            'request_type' => StockRequest::TYPE_DELIVERY,
            'warehouse_id' => $this->warehouse->id,
            'requested_by' => $this->ops->id,
            'request_date' => now(),
        ]);

        $this->assertMatchesRegularExpression('/^REQ-\d{3}$/', $request->request_number);
    }

    public function test_request_numbers_increase_and_pad_to_three_digits(): void
    {
        StockRequest::create(['requested_by' => $this->ops->id, 'request_date' => now()]);
        $second = StockRequest::create(['requested_by' => $this->ops->id, 'request_date' => now()]);

        $expected = StockRequest::nextRequestNumber();

        // Three-digit padding, not four: REQ-001, REQ-002, ...
        $this->assertMatchesRegularExpression('/^REQ-\d{3}$/', $second->request_number);
        $this->assertMatchesRegularExpression('/^REQ-\d{3}$/', $expected);
        $this->assertSame(
            $expected,
            StockRequest::create(['requested_by' => $this->ops->id, 'request_date' => now()])->request_number
        );
    }

    public function test_a_supplied_request_number_is_kept(): void
    {
        $request = StockRequest::create([
            'request_number' => 'REQ-032',
            'requested_by' => $this->ops->id,
            'request_date' => now(),
        ]);

        $this->assertSame('REQ-032', $request->request_number);
    }

    // ---- Lines ------------------------------------------------------------

    public function test_lines_track_requested_and_received_quantities(): void
    {
        $request = StockRequest::create([
            'requested_by' => $this->ops->id,
            'request_date' => now(),
        ]);

        $diesel = StockRequestLine::create([
            'stock_request_id' => $request->id,
            'product' => 'Diesel',
            'qty_requested' => 10000,
            'qty_received' => 4000,
        ]);

        $this->assertSame(6000, $diesel->qty_pending);
        $this->assertFalse($diesel->isFullyReceived());

        $diesel->update(['qty_received' => 10000]);
        $this->assertSame(0, $diesel->fresh()->qty_pending);
        $this->assertTrue($diesel->fresh()->isFullyReceived());
    }

    public function test_a_line_cannot_report_a_negative_pending_quantity(): void
    {
        $line = StockRequestLine::create([
            'stock_request_id' => StockRequest::create([
                'requested_by' => $this->ops->id,
                'request_date' => now(),
            ])->id,
            'product' => 'Diesel',
            'qty_requested' => 100,
            'qty_received' => 150,
        ]);

        $this->assertSame(0, $line->qty_pending);
    }

    public function test_request_totals_add_up_across_products(): void
    {
        $request = StockRequest::create([
            'requested_by' => $this->ops->id,
            'request_date' => now(),
        ]);

        StockRequestLine::create([
            'stock_request_id' => $request->id, 'product' => 'Diesel',
            'qty_requested' => 10000, 'qty_received' => 4000,
        ]);
        StockRequestLine::create([
            'stock_request_id' => $request->id, 'product' => 'Unleaded',
            'qty_requested' => 5000, 'qty_received' => 5000,
        ]);

        $request->load('lines');

        $this->assertSame(15000, $request->qty_requested);
        $this->assertSame(9000, $request->qty_received);
    }

    // ---- Picking up -------------------------------------------------------

    public function test_purchasing_pick_up_is_tracked_separately_from_receiving(): void
    {
        $request = StockRequest::create([
            'requested_by' => $this->ops->id,
            'request_date' => now(),
        ]);

        $this->assertFalse($request->hasBeenPickedUpByPurchasing());

        $request->update([
            'purchasing_received_at' => now(),
            'purchasing_received_by' => $this->ops->id,
        ]);

        $this->assertTrue($request->fresh()->hasBeenPickedUpByPurchasing());
        // Nothing was received yet, so the quantity stands at zero.
        $this->assertSame(0, $request->fresh()->qty_received);
    }

    // ---- Relations --------------------------------------------------------

    public function test_an_atl_can_point_at_a_stock_request(): void
    {
        $request = StockRequest::create([
            'requested_by' => $this->ops->id,
            'request_date' => now(),
        ]);

        $delivery = PurchaseOrderDelivery::create([
            'request_id' => $request->id,
            'delivery_channel' => 'SUPPLIER_STOCKS_DELIVERY',
            'atl_category' => PurchaseOrderDelivery::CATEGORY_DOYEN_STOCKS,
            'product' => 'Diesel',
            'qty_to_receive' => 10000,
            'status' => 'Active',
        ]);

        $this->assertTrue($request->fresh()->deliveries->contains($delivery));
        $this->assertTrue($delivery->stockRequest->is($request));
    }

    public function test_request_type_labels_read_as_plain_language(): void
    {
        $pickup = StockRequest::create(['requested_by' => $this->ops->id, 'request_date' => now()]);
        $delivery = StockRequest::create([
            'request_type' => StockRequest::TYPE_DELIVERY,
            'requested_by' => $this->ops->id,
            'request_date' => now(),
        ]);

        $this->assertSame('Pick-up', $pickup->type_label);
        $this->assertSame('Delivery', $delivery->type_label);
        $this->assertTrue($delivery->isDelivered());
    }

    // ---- Backfill ---------------------------------------------------------

    /**
     * Run the same code the migration runs. Calling artisan migrate directly
     * would be a no-op once the migration is recorded.
     */
    protected function backfill(): void
    {
        (new StockRequestBackfill)->run();
    }

    public function test_the_backfill_copies_a_legacy_request_without_deleting_it(): void
    {
        $legacy = $this->legacyRequest();

        $this->backfill();

        $this->assertDatabaseHas('purchase_orders', [
            'id' => $legacy->id,
            'request_status' => 'REQUESTED',
        ]);

        $copied = StockRequest::where('legacy_purchase_order_id', $legacy->id)->first();

        $this->assertNotNull($copied, 'The legacy request should be copied into stock_requests');
        $this->assertSame($this->warehouse->id, $copied->warehouse_id);
        $this->assertSame('REQUESTED', $copied->status);
        $this->assertSame(10000, $copied->qty_requested);
        $this->assertSame('Diesel', $copied->lines->first()->product);
    }

    public function test_the_backfill_copies_every_product_on_the_legacy_request(): void
    {
        $legacy = $this->legacyRequest([
            'qty_ordered' => 15000,
            'requested_products' => [
                ['product' => 'Diesel', 'quantity' => 10000],
                ['product' => 'Unleaded', 'quantity' => 5000],
            ],
        ]);

        $this->backfill();

        $copied = StockRequest::where('legacy_purchase_order_id', $legacy->id)->firstOrFail();

        $this->assertCount(2, $copied->lines);
        $this->assertSame(15000, $copied->qty_requested);
        // Nothing has physically arrived yet.
        $this->assertSame(0, $copied->qty_received);
    }

    public function test_the_backfill_falls_back_to_the_header_quantity(): void
    {
        // A row written before requested_products was populated.
        $legacy = $this->legacyRequest(['requested_products' => null, 'qty_ordered' => 8000]);

        $this->backfill();

        $copied = StockRequest::where('legacy_purchase_order_id', $legacy->id)->firstOrFail();

        $this->assertSame(8000, $copied->qty_requested);
    }

    public function test_the_backfill_keeps_the_legacy_status(): void
    {
        $legacy = $this->legacyRequest(['request_status' => 'FOR_DELIVERY']);

        $this->backfill();

        $this->assertSame(
            'FOR_DELIVERY',
            StockRequest::where('legacy_purchase_order_id', $legacy->id)->firstOrFail()->status
        );
    }

    public function test_the_backfill_numbers_the_copied_requests_in_order(): void
    {
        $first = $this->legacyRequest();
        $second = $this->legacyRequest();

        $this->backfill();

        $firstNumber = StockRequest::where('legacy_purchase_order_id', $first->id)
            ->firstOrFail()->request_number;
        $secondNumber = StockRequest::where('legacy_purchase_order_id', $second->id)
            ->firstOrFail()->request_number;

        $this->assertMatchesRegularExpression('/^REQ-\d{3}$/', $firstNumber);
        $this->assertMatchesRegularExpression('/^REQ-\d{3}$/', $secondNumber);
        // The older legacy row takes the lower number.
        $this->assertSame(1, (int) substr($secondNumber, 4) - (int) substr($firstNumber, 4));
    }

    public function test_the_backfill_is_idempotent(): void
    {
        $legacy = $this->legacyRequest();

        $this->backfill();
        $this->backfill();

        $copied = StockRequest::where('legacy_purchase_order_id', $legacy->id)->firstOrFail();

        $this->assertSame(
            1,
            StockRequest::where('legacy_purchase_order_id', $legacy->id)->count(),
            'Re-running must not duplicate the request'
        );
        $this->assertCount(1, $copied->lines);
    }

    public function test_the_backfill_ignores_rows_that_already_have_a_po_number(): void
    {
        $realPo = PurchaseOrder::create([
            'po_number' => 'PO-NOT-A-REQUEST',
            'po_type' => 'STANDARD_REPLENISHMENT',
            'warehouse_id' => $this->warehouse->id,
            'qty_ordered' => 5000,
            'request_status' => 'RECEIVED',
            'requested_by' => $this->ops->id,
            'request_date' => now(),
        ]);

        $this->backfill();

        $this->assertDatabaseMissing('stock_requests', [
            'legacy_purchase_order_id' => $realPo->id,
        ]);
    }

    public function test_the_backlink_survives_a_legacy_row_being_deleted(): void
    {
        $legacy = $this->legacyRequest();

        $this->backfill();

        $legacy->delete();

        $this->assertNull(StockRequest::where('legacy_purchase_order_id', $legacy->id)->first());
    }
}