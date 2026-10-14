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
 * A stock request no longer needs a PO of its own. Its ATLs draw from whichever
 * purchase orders still have volume, which is what the supervisor asked for when
 * the action became Create ATL.
 *
 * The limit is the request's own pending volume - asked for less what has already
 * arrived - not a purchase order's requirement. That guard is new: the Sales
 * Order one built last round is bound to an Order and cannot be reused.
 */
class WetStockRequestAddAtlTest extends TestCase
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

    protected function request(array $lines = [['Diesel', 50000]], array $received = []): StockRequest
    {
        $request = StockRequest::create([
            'request_number' => 'REQ-'.rand(100, 999),
            'request_type' => StockRequest::TYPE_PICKUP,
            'warehouse_id' => $this->warehouse->id,
            'qty_requested' => array_sum(array_column($lines, 1)),
            'status' => StockRequest::STATUS_REQUESTED,
            'requested_by' => $this->admin->id,
        ]);

        foreach ($lines as [$product, $qty]) {
            StockRequestLine::create([
                'stock_request_id' => $request->id,
                'product' => $product,
                'qty_requested' => $qty,
                'qty_received' => $received[$product] ?? 0,
            ]);
        }

        return $request;
    }

    protected function po(array $products, string $status = 'CONFIRMED'): PurchaseOrder
    {
        $supplier = Supplier::create([
            'company_name' => 'Add Atl Refinery '.rand(1000, 9999),
            'location' => 'Limay Terminal',
        ]);

        $po = PurchaseOrder::create([
            'po_number' => 'PO-ADDATL-'.rand(1000, 9999),
            'po_type' => PurchaseOrder::TYPE_STANDARD_REPLENISHMENT,
            'supplier_id' => $supplier->id,
            'supplier_name' => $supplier->company_name,
            'po_date' => now()->toDateString(),
            'qty_ordered' => array_sum($products),
            'request_status' => $status,
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
     * @param  array<string, mixed>  $overrides
     */
    protected function submit(StockRequest $request, PurchaseOrder $po, array $overrides = [])
    {
        return $this->actingAs($this->admin)
            ->post(route('stock-orders.wet-stock-requests.atls.store', $request->id), array_merge([
                'purchase_order_id' => $po->id,
                'product' => 'Diesel',
                'qty_to_receive' => 20000,
                'order_type' => 'DELIVERY',
                'delivery_channel' => 'SUPPLIER_STOCKS_DELIVERY',
                'atl_type' => 'DITC_ATL',
                'atl_number' => 'ATL-'.uniqid(),
                'receiving_date' => now()->addDays(3)->format('Y-m-d'),
                'driver_name' => 'Juan Dela Cruz',
                'plate_number' => 'NBD-1234',
            ], $overrides));
    }

    // ---- The form ----------------------------------------------------------

    public function test_the_form_offers_purchase_orders_that_still_have_volume(): void
    {
        $request = $this->request();
        $po = $this->po(['Diesel' => 100000]);

        $html = $this->actingAs($this->admin)
            ->get(route('stock-orders.wet-stock-requests.atls.create', $request->id))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('name="purchase_order_id"', $html);
        $this->assertStringContainsString($po->po_number, $html);
    }

    public function test_the_form_omits_a_fully_drawn_purchase_order(): void
    {
        $request = $this->request();
        $this->po(['Diesel' => 100000]);
        $drained = $this->po(['Diesel' => 1000]);

        // Take all of it.
        $this->submit($request, $drained, ['qty_to_receive' => 1000])->assertSessionHasNoErrors();

        $this->assertSame(
            0,
            $drained->fresh()->getAvailableBalanceForProduct('Diesel'),
            'Precondition: the PO really is drained.'
        );

        $html = $this->actingAs($this->admin)
            ->get(route('stock-orders.wet-stock-requests.atls.create', $request->id))
            ->assertOk()
            ->getContent();

        // Scoped to the dropdown element. The layout prints any leftover success
        // flash from the save above, which quotes the PO number, so asserting on
        // the whole document would be measuring the wrong thing.
        $start = strpos($html, 'name="purchase_order_id"');
        $this->assertIsInt($start, 'The form should offer a purchase order dropdown.');

        $end = strpos($html, '</select>', $start);
        $dropdown = substr($html, $start, $end - $start);

        $this->assertStringNotContainsString($drained->po_number, $dropdown);
    }

    // ---- Storing -----------------------------------------------------------

    public function test_storing_links_the_atl_to_the_request_and_the_chosen_po(): void
    {
        $request = $this->request();
        $po = $this->po(['Diesel' => 100000]);

        $this->submit($request, $po)->assertSessionHas('success');

        $atl = PurchaseOrderDelivery::where('request_id', $request->id)->firstOrFail();

        $this->assertSame($po->id, $atl->purchase_order_id);

        $allocation = AtlAllocation::where('purchase_order_delivery_id', $atl->id)->firstOrFail();
        $this->assertSame('Diesel', $allocation->product);
        $this->assertSame(20000, (int) $allocation->quantity);
    }

    public function test_storing_draws_the_po_balance_down(): void
    {
        $request = $this->request();
        $po = $this->po(['Diesel' => 100000]);

        $this->submit($request, $po)->assertSessionHas('success');

        $this->assertSame(80000, $po->fresh()->getAvailableBalanceForProduct('Diesel'));
    }

    // ---- The guards --------------------------------------------------------

    public function test_a_po_with_no_volume_left_is_refused(): void
    {
        $request = $this->request();
        $po = $this->po(['Diesel' => 10000]);

        $this->submit($request, $po, ['qty_to_receive' => 20000])
            ->assertSessionHasErrors('qty_to_receive');

        $this->assertSame(0, PurchaseOrderDelivery::where('request_id', $request->id)->count());
    }

    public function test_a_po_the_vp_has_not_approved_is_refused(): void
    {
        // createAtl already refuses this elsewhere; a crafted POST must not slip past.
        $request = $this->request();
        $po = $this->po(['Diesel' => 100000], 'RECEIVED');

        $this->submit($request, $po)->assertSessionHasErrors('purchase_order_id');

        $this->assertSame(0, PurchaseOrderDelivery::where('request_id', $request->id)->count());
    }

    public function test_a_product_the_request_never_asked_for_is_refused(): void
    {
        $request = $this->request([['Diesel', 50000]]);
        $po = $this->po(['Diesel' => 100000, 'Premium' => 100000]);

        $this->submit($request, $po, ['product' => 'Premium'])
            ->assertSessionHasErrors('product');
    }

    public function test_the_atl_cannot_exceed_the_request_pending_volume(): void
    {
        // Asked for 50,000 of Diesel and none has arrived, so 50,000 is pending.
        $request = $this->request([['Diesel', 50000]]);
        $po = $this->po(['Diesel' => 200000]);

        $this->submit($request, $po, ['qty_to_receive' => 50001])
            ->assertSessionHasErrors('qty_to_receive');

        $this->assertSame(0, PurchaseOrderDelivery::where('request_id', $request->id)->count());
    }

    public function test_volume_already_received_reduces_what_can_still_be_atled(): void
    {
        // 60,000 asked for, 20,000 already arrived, so 40,000 is pending.
        $request = $this->request([['Diesel', 60000]], ['Diesel' => 20000]);
        $po = $this->po(['Diesel' => 200000]);

        $this->submit($request, $po, ['qty_to_receive' => 40001])
            ->assertSessionHasErrors('qty_to_receive');

        $this->submit($request, $po, ['qty_to_receive' => 40000])->assertSessionHas('success');
    }

    public function test_several_atls_share_one_pending_budget(): void
    {
        $request = $this->request([['Diesel', 50000]]);
        $po = $this->po(['Diesel' => 200000]);

        $this->submit($request, $po, ['qty_to_receive' => 30000])->assertSessionHas('success');
        $this->submit($request, $po, ['qty_to_receive' => 20000])->assertSessionHas('success');

        // 50,000 of 50,000 pending is now committed.
        $this->submit($request, $po, ['qty_to_receive' => 1])
            ->assertSessionHasErrors('qty_to_receive');

        $this->assertSame(2, PurchaseOrderDelivery::where('request_id', $request->id)->count());
    }

    public function test_the_cap_is_per_product_not_flat(): void
    {
        $request = $this->request([['Diesel', 50000], ['Premium', 20000]]);
        $po = $this->po(['Diesel' => 200000, 'Premium' => 200000]);

        $this->submit($request, $po, ['product' => 'Diesel', 'qty_to_receive' => 50000])
            ->assertSessionHas('success');

        // Premium has its own budget, untouched by the Diesel draw.
        $this->submit($request, $po, ['product' => 'Premium', 'qty_to_receive' => 20000])
            ->assertSessionHas('success');

        $this->submit($request, $po, ['product' => 'Premium', 'qty_to_receive' => 1])
            ->assertSessionHasErrors('qty_to_receive');
    }

    public function test_a_crafted_post_cannot_choose_a_po_outside_the_pool(): void
    {
        $request = $this->request();
        $cancelled = $this->po(['Diesel' => 100000]);
        $cancelled->update(['status' => 'Cancelled']);

        $this->submit($request, $cancelled)->assertSessionHasErrors('purchase_order_id');

        $this->assertSame(0, PurchaseOrderDelivery::where('request_id', $request->id)->count());
    }
}
