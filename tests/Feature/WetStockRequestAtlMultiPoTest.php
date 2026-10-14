<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\AtlAllocation;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderDelivery;
use App\Models\PurchaseOrderItem;
use App\Models\StockRequest;
use App\Models\StockRequestLine;
use App\Models\Supplier;
use App\Models\Warehouse;
use Tests\TestCase;

/**
 * Two things on the Wet Stock Requests ATL page.
 *
 * It is meant to read as Module 1's Deliveries page - the same four cards, the
 * same table, the same action on the heading - so the stylesheet and the column
 * set are asserted against M1's rather than merely described.
 *
 * And the form behind Add ATL now splits one ATL across several purchase
 * orders, for when no single PO holds enough of the product. The dangerous part
 * is not the split itself but the validation: two rows naming the same product
 * each look small against the request's pending figure, so they have to be
 * summed before that figure is consulted.
 */
class WetStockRequestAtlMultiPoTest extends TestCase
{
    protected Admin $admin;

    protected Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = Admin::create([
            'username' => 'admin_'.uniqid(),
            'password' => bcrypt('secret'),
            'name' => 'System Administrator',
            'role' => 'admin',
        ]);

        $this->warehouse = Warehouse::create(['name' => 'Bataan Depot '.rand(1000, 9999)]);
    }

    /**
     * @param  array<int, array{0: string, 1: int}>  $lines
     */
    protected function request(array $lines = [['Diesel', 50000]]): StockRequest
    {
        $request = StockRequest::create([
            'request_number' => 'REQ-MPO-'.rand(1000, 9999),
            'request_type' => StockRequest::TYPE_PICKUP,
            'warehouse_id' => $this->warehouse->id,
            'qty_requested' => array_sum(array_column($lines, 1)),
            'status' => StockRequest::STATUS_REQUESTED,
            'requested_by' => $this->admin->id,
            'request_date' => now(),
        ]);

        foreach ($lines as [$product, $qty]) {
            StockRequestLine::create([
                'stock_request_id' => $request->id,
                'product' => $product,
                'qty_requested' => $qty,
            ]);
        }

        return $request;
    }

    /**
     * @param  array<string, int>  $products
     */
    protected function po(string $number, array $products): PurchaseOrder
    {
        $supplier = Supplier::create([
            'company_name' => 'Split Refinery '.rand(1000, 9999),
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

    /**
     * @param  array<int, array{purchase_order_id: int, product: string, quantity: int}>  $drawdowns
     * @param  array<string, mixed>  $overrides
     */
    protected function submit(StockRequest $request, array $drawdowns, array $overrides = [])
    {
        return $this->actingAs($this->admin)
            ->post(route('stock-orders.wet-stock-requests.atls.store', $request->id), array_merge([
                'drawdowns' => $drawdowns,
                'order_type' => 'DELIVERY',
                'delivery_channel' => 'SUPPLIER_STOCKS_DELIVERY',
                'atl_type' => 'DITC_ATL',
                'atl_number' => 'ATL-'.uniqid(),
                'receiving_date' => now()->addDays(3)->format('Y-m-d'),
            ], $overrides));
    }

    // ---- The form ----------------------------------------------------------

    public function test_the_form_offers_a_repeatable_po_row(): void
    {
        $request = $this->request();
        $this->po('PO-FORM-'.rand(1000, 9999), ['Diesel' => 50000]);

        $html = $this->actingAs($this->admin)
            ->get(route('stock-orders.wet-stock-requests.atls.create', $request->id))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('drawdowns[0][purchase_order_id]', $html);
        $this->assertStringContainsString('drawdowns[0][product]', $html);
        $this->assertStringContainsString('drawdowns[0][quantity]', $html);
        $this->assertStringContainsString('id="addPoRow"', $html);
        $this->assertStringContainsString('Add PO', $html);
    }

    public function test_each_po_option_reports_what_it_still_holds(): void
    {
        $request = $this->request();
        $this->po('PO-BAL-'.rand(1000, 9999), ['Diesel' => 12345]);

        $html = $this->actingAs($this->admin)
            ->get(route('stock-orders.wet-stock-requests.atls.create', $request->id))
            ->assertOk()
            ->getContent();

        // The per-PO, per-product balance rides on the option as data, which is
        // what the row hint reads. It is a raw number in the JSON, so it is not
        // comma-formatted.
        $this->assertStringContainsString('data-products=', $html);
        $this->assertStringContainsString('12345', $html);
    }

    public function test_the_form_refuses_an_empty_drawdown(): void
    {
        $request = $this->request();

        $this->submit($request, [])
            ->assertSessionHasErrors('drawdowns');

        $this->assertSame(0, PurchaseOrderDelivery::where('request_id', $request->id)->count());
    }

    // ---- The split ---------------------------------------------------------

    public function test_one_atl_can_be_split_across_two_pos(): void
    {
        $request = $this->request([['Diesel', 50000]]);
        // Neither PO holds 50,000 on its own, which is the whole point.
        $first = $this->po('PO-A-'.rand(1000, 9999), ['Diesel' => 30000]);
        $second = $this->po('PO-B-'.rand(1000, 9999), ['Diesel' => 30000]);

        $this->submit($request, [
            ['purchase_order_id' => $first->id, 'product' => 'Diesel', 'quantity' => 30000],
            ['purchase_order_id' => $second->id, 'product' => 'Diesel', 'quantity' => 20000],
        ])->assertSessionHas('success');

        $this->assertSame(1, PurchaseOrderDelivery::where('request_id', $request->id)->count());

        $atl = PurchaseOrderDelivery::where('request_id', $request->id)->firstOrFail();

        // One ATL, two allocations.
        $this->assertSame(2, $atl->allocations()->count());
        $this->assertSame(50000, (int) $atl->qty_to_receive);

        // Each allocation names its own PO and its own product line, or the PO
        // balances would not both fall: the first PO gave up all 30,000 and the
        // second gave up 20,000 of its 30,000.
        $this->assertSame(0, $first->fresh()->getAvailableBalanceForProduct('Diesel'));
        $this->assertSame(10000, $second->fresh()->getAvailableBalanceForProduct('Diesel'));

        foreach ($atl->allocations as $allocation) {
            $this->assertNotNull(
                $allocation->purchase_order_item_id,
                'Every row must link its PO line, or the balance ignores it.'
            );
        }
    }

    public function test_two_rows_for_one_product_are_checked_against_the_pending_total(): void
    {
        $request = $this->request([['Diesel', 50000]]);
        $first = $this->po('PO-C-'.rand(1000, 9999), ['Diesel' => 40000]);
        $second = $this->po('PO-D-'.rand(1000, 9999), ['Diesel' => 40000]);

        // 30,000 + 30,000 = 60,000 against 50,000 pending. Checked row by row,
        // both would pass on their own.
        $this->submit($request, [
            ['purchase_order_id' => $first->id, 'product' => 'Diesel', 'quantity' => 30000],
            ['purchase_order_id' => $second->id, 'product' => 'Diesel', 'quantity' => 30000],
        ])->assertSessionHasErrors('drawdowns');

        $this->assertSame(0, PurchaseOrderDelivery::where('request_id', $request->id)->count());
    }

    public function test_the_split_totals_exactly_at_the_pending_figure(): void
    {
        $request = $this->request([['Diesel', 50000]]);
        $first = $this->po('PO-E-'.rand(1000, 9999), ['Diesel' => 40000]);
        $second = $this->po('PO-F-'.rand(1000, 9999), ['Diesel' => 40000]);

        // One litre under the cap: the boundary has to be inclusive.
        $this->submit($request, [
            ['purchase_order_id' => $first->id, 'product' => 'Diesel', 'quantity' => 30000],
            ['purchase_order_id' => $second->id, 'product' => 'Diesel', 'quantity' => 20000],
        ])->assertSessionHas('success');

        $this->assertSame(1, PurchaseOrderDelivery::where('request_id', $request->id)->count());
    }

    public function test_two_products_split_across_pos_respect_separate_budgets(): void
    {
        $request = $this->request([['Diesel', 30000], ['Unleaded', 20000]]);
        $first = $this->po('PO-G-'.rand(1000, 9999), ['Diesel' => 50000, 'Unleaded' => 50000]);
        $second = $this->po('PO-H-'.rand(1000, 9999), ['Diesel' => 50000, 'Unleaded' => 50000]);

        $this->submit($request, [
            ['purchase_order_id' => $first->id, 'product' => 'Diesel', 'quantity' => 20000],
            ['purchase_order_id' => $second->id, 'product' => 'Diesel', 'quantity' => 10000],
            ['purchase_order_id' => $first->id, 'product' => 'Unleaded', 'quantity' => 20000],
        ])->assertSessionHas('success');

        $atl = PurchaseOrderDelivery::where('request_id', $request->id)->firstOrFail();

        // Three allocations, and the summary names both fuels.
        $this->assertSame(3, $atl->allocations()->count());
        $this->assertSame(50000, (int) $atl->qty_to_receive);
        $this->assertStringContainsString('Diesel', $atl->product);
        $this->assertStringContainsString('Unleaded', $atl->product);
    }

    public function test_a_row_naming_a_product_the_request_never_asked_for_is_refused(): void
    {
        $request = $this->request([['Diesel', 50000]]);
        $po = $this->po('PO-I-'.rand(1000, 9999), ['Diesel' => 50000, 'Premium' => 50000]);

        $this->submit($request, [
            ['purchase_order_id' => $po->id, 'product' => 'Diesel', 'quantity' => 10000],
            ['purchase_order_id' => $po->id, 'product' => 'Premium', 'quantity' => 10000],
        ])->assertSessionHasErrors('drawdowns');
    }

    public function test_a_row_asking_more_than_its_po_holds_is_refused(): void
    {
        $request = $this->request([['Diesel', 50000]]);
        $po = $this->po('PO-J-'.rand(1000, 9999), ['Diesel' => 10000]);

        $this->submit($request, [
            ['purchase_order_id' => $po->id, 'product' => 'Diesel', 'quantity' => 20000],
        ])->assertSessionHasErrors('drawdowns.0.quantity');
    }

    public function test_an_unapproved_po_cannot_be_drawn_from(): void
    {
        $request = $this->request([['Diesel', 50000]]);
        $po = $this->po('PO-K-'.rand(1000, 9999), ['Diesel' => 50000]);
        $po->update(['request_status' => 'RECEIVED']);

        $this->submit($request, [
            ['purchase_order_id' => $po->id, 'product' => 'Diesel', 'quantity' => 10000],
        ])->assertSessionHasErrors('drawdowns.0.purchase_order_id');

        $this->assertSame(0, PurchaseOrderDelivery::where('request_id', $request->id)->count());
    }

    public function test_the_split_atl_is_queued_and_lists_both_pos(): void
    {
        $request = $this->request([['Diesel', 50000]]);
        $first = $this->po('PO-L-'.rand(1000, 9999), ['Diesel' => 30000]);
        $second = $this->po('PO-M-'.rand(1000, 9999), ['Diesel' => 30000]);

        $this->submit($request, [
            ['purchase_order_id' => $first->id, 'product' => 'Diesel', 'quantity' => 30000],
            ['purchase_order_id' => $second->id, 'product' => 'Diesel', 'quantity' => 20000],
        ])->assertSessionHas('success');

        $atl = PurchaseOrderDelivery::where('request_id', $request->id)->firstOrFail();

        $this->assertSame(PurchaseOrderDelivery::APPROVAL_FOR_APPROVAL, $atl->approval_status);
        $this->assertSame(
            1,
            PurchaseOrderDelivery::pendingVpApproval()->count(),
            'It must reach the VP queue like any other.'
        );

        // The page has to name both POs, or the split is invisible on the record.
        $html = $this->actingAs($this->admin)
            ->get(route('stock-orders.wet-stock-requests.atls', $request->id))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString($first->po_number, $html);
        $this->assertStringContainsString($second->po_number, $html);
    }

    public function test_a_second_atl_completes_the_budget_across_two_splits(): void
    {
        $request = $this->request([['Diesel', 50000]]);
        $first = $this->po('PO-N-'.rand(1000, 9999), ['Diesel' => 30000]);
        $second = $this->po('PO-O-'.rand(1000, 9999), ['Diesel' => 30000]);

        $this->submit($request, [
            ['purchase_order_id' => $first->id, 'product' => 'Diesel', 'quantity' => 30000],
            ['purchase_order_id' => $second->id, 'product' => 'Diesel', 'quantity' => 10000],
        ])->assertSessionHas('success');

        // 40,000 of 50,000 is committed; 10,000 is left.
        $this->submit($request, [
            ['purchase_order_id' => $second->id, 'product' => 'Diesel', 'quantity' => 10001],
        ])->assertSessionHasErrors('drawdowns');

        $this->submit($request, [
            ['purchase_order_id' => $second->id, 'product' => 'Diesel', 'quantity' => 10000],
        ])->assertSessionHas('success');

        $this->assertSame(2, PurchaseOrderDelivery::where('request_id', $request->id)->count());
        $this->assertSame(0, $request->fresh()->pendingByProduct()['Diesel']);
    }

    // ---- The page mirrors M1 ----------------------------------------------

    protected function atlsHtml(StockRequest $request): string
    {
        return $this->actingAs($this->admin)
            ->get(route('stock-orders.wet-stock-requests.atls', $request->id))
            ->assertOk()
            ->getContent();
    }

    public function test_the_page_carries_m1s_table_stylesheet_and_columns(): void
    {
        $request = $this->request();

        $html = $this->atlsHtml($request);

        $this->assertStringContainsString('<table id="atlsTable"', $html);
        $this->assertStringContainsString('#atlsTable thead th', $html);
        $this->assertStringContainsString('.stat-card', $html);
        $this->assertStringContainsString('.id-pill', $html);
        $this->assertStringContainsString('.action-icon', $html);

        foreach (['DR#', 'ATL#', 'PO#', 'Qty Out', 'Status', 'Type', 'Action'] as $column) {
            $this->assertStringContainsString('>'.$column.'<', $html);
        }

        // Pick-up requests label the date column the way M1 does.
        $this->assertStringContainsString('Pick Up Date', $html);
    }

    public function test_a_delivery_request_labels_its_date_column_delivery(): void
    {
        $request = $this->request();
        $request->update(['request_type' => StockRequest::TYPE_DELIVERY]);

        $this->assertStringContainsString('Delivery Date', $this->atlsHtml($request->fresh()));
    }

    public function test_the_info_card_names_the_request_and_its_requester(): void
    {
        $request = $this->request();

        $html = $this->atlsHtml($request);

        $this->assertStringContainsString('REQ# '.$request->request_number, $html);
        $this->assertStringContainsString($this->admin->name, $html);
        $this->assertStringContainsString($this->warehouse->name, $html);
        $this->assertStringContainsString('Pick-up', $html);
    }

    public function test_the_four_cards_are_in_one_row(): void
    {
        $request = $this->request();

        $html = $this->atlsHtml($request);

        // Info card plus three volume cards.
        $this->assertSame(4, substr_count($html, 'class="col-md-3"'));
    }

    public function test_add_atl_sits_on_the_heading_and_opens_the_drawdown_form(): void
    {
        $request = $this->request();

        $html = $this->atlsHtml($request);

        $this->assertStringContainsString('Add ATL', $html);
        $this->assertStringContainsString(
            route('stock-orders.wet-stock-requests.atls.create', $request->id),
            $html
        );
        // The queue's Create ATL is the other half of the two-step route in.
        $this->assertStringContainsString('Create ATL', $this->actingAs($this->admin)
            ->get(route('stock-orders.wet-stock-requests.index'))
            ->getContent());
    }

    public function test_an_unapproved_atl_offers_no_pdf_and_no_lift(): void
    {
        $request = $this->request([['Diesel', 50000]]);
        $po = $this->po('PO-P-'.rand(1000, 9999), ['Diesel' => 50000]);

        $this->submit($request, [
            ['purchase_order_id' => $po->id, 'product' => 'Diesel', 'quantity' => 10000],
        ])->assertSessionHas('success');

        $atl = PurchaseOrderDelivery::where('request_id', $request->id)->firstOrFail();

        $html = $this->atlsHtml($request);

        $this->assertStringNotContainsString(route('stock-orders.atl-pdf', $atl->id), $html);
        $this->assertStringNotContainsString('Mark Lifted', $html);
        $this->assertStringContainsString('FOR APPROVAL', $html);
    }

    public function test_an_approved_atl_offers_the_pdf_and_the_lift(): void
    {
        $request = $this->request([['Diesel', 50000]]);
        $po = $this->po('PO-Q-'.rand(1000, 9999), ['Diesel' => 50000]);

        $this->submit($request, [
            ['purchase_order_id' => $po->id, 'product' => 'Diesel', 'quantity' => 10000],
        ])->assertSessionHas('success');

        $atl = PurchaseOrderDelivery::where('request_id', $request->id)->firstOrFail();
        $atl->update(['approval_status' => PurchaseOrderDelivery::APPROVAL_APPROVED]);

        $html = $this->atlsHtml($request);

        $this->assertStringContainsString(route('stock-orders.atl-pdf', $atl->id), $html);
        $this->assertStringContainsString('Mark Lifted', $html);
        $this->assertStringContainsString('APPROVED', $html);
    }

    public function test_a_lifted_atl_offers_the_lift_no_more(): void
    {
        $request = $this->request([['Diesel', 50000]]);
        $po = $this->po('PO-R-'.rand(1000, 9999), ['Diesel' => 50000]);

        $this->submit($request, [
            ['purchase_order_id' => $po->id, 'product' => 'Diesel', 'quantity' => 10000],
        ])->assertSessionHas('success');

        $atl = PurchaseOrderDelivery::where('request_id', $request->id)->firstOrFail();
        $atl->update([
            'approval_status' => PurchaseOrderDelivery::APPROVAL_APPROVED,
            'lift_status' => PurchaseOrderDelivery::LIFT_LIFTED,
        ]);

        $html = $this->atlsHtml($request);

        $this->assertStringNotContainsString('Mark Lifted', $html);
        $this->assertStringContainsString(route('stock-orders.atl-pdf', $atl->id), $html);
        $this->assertStringContainsString('LIFTED', $html);
    }

    public function test_the_quantity_column_shows_a_chip_per_product(): void
    {
        $request = $this->request([['Diesel', 30000], ['Premium', 5000]]);
        $po = $this->po('PO-S-'.rand(1000, 9999), ['Diesel' => 50000, 'Premium' => 50000]);

        $this->submit($request, [
            ['purchase_order_id' => $po->id, 'product' => 'Diesel', 'quantity' => 12000],
            ['purchase_order_id' => $po->id, 'product' => 'Premium', 'quantity' => 3000],
        ])->assertSessionHas('success');

        $atl = PurchaseOrderDelivery::where('request_id', $request->id)->firstOrFail();
        $this->assertSame(2, $atl->allocations()->count());

        $html = $this->atlsHtml($request);

        // "D - 12,000" and "P - 3,000": chip then figure.
        $this->assertStringContainsString('12,000', $html);
        $this->assertStringContainsString('3,000', $html);
        $this->assertStringContainsString('>D</span>', $html);
        $this->assertStringContainsString('>P</span>', $html);
    }

    public function test_an_empty_request_states_it_rather_than_rendering_nothing(): void
    {
        $request = $this->request();

        $html = $this->atlsHtml($request);

        $this->assertStringContainsString('No ATLs issued for this request yet', $html);
    }
}
