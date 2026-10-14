<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\AtlAllocation;
use App\Models\Order;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderDelivery;
use App\Models\PurchaseOrderItem;
use App\Models\StockRequest;
use App\Models\StockRequestLine;
use App\Models\Supplier;
use App\Models\Warehouse;
use Tests\TestCase;

/**
 * Three defects a whole-branch review found in the stock-request ATL path.
 *
 * The allocation it writes left purchase_order_item_id null. That is harmless on
 * its own, but PurchaseOrderItem::getTotalDrawnAttribute() falls back to counting
 * null-item allocations only while the item has no linked ones - so the moment
 * any other ATL drew from the same PO, this one's volume vanished from the
 * balance and the PO could be over-drawn.
 *
 * Its pending-volume guard also subtracted what the depot had received as well as
 * what an ATL had committed, but a receipt is the settling of an ATL, not a second
 * draw, so the same litres came off twice.
 *
 * And Approve/Reject rendered on the ATL tab to every role that can now open the
 * queue, which after the access widening includes five Module 2 operations roles
 * that can only get a 403 from those routes.
 */
class WetStockRequestAtlDefectsTest extends TestCase
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

    protected function request(int $qty = 50000, array $received = []): StockRequest
    {
        $request = StockRequest::create([
            'request_number' => 'REQ-'.rand(100, 999),
            'request_type' => StockRequest::TYPE_PICKUP,
            'warehouse_id' => $this->warehouse->id,
            'qty_requested' => $qty,
            'status' => StockRequest::STATUS_REQUESTED,
            'requested_by' => $this->admin->id,
        ]);

        StockRequestLine::create([
            'stock_request_id' => $request->id,
            'product' => 'Diesel',
            'qty_requested' => $qty,
            'qty_received' => $received['Diesel'] ?? 0,
        ]);

        return $request;
    }

    protected function po(array $products = ['Diesel' => 100000]): PurchaseOrder
    {
        $supplier = Supplier::create([
            'company_name' => 'Defect Refinery '.rand(1000, 9999),
            'location' => 'Limay Terminal',
        ]);

        $po = PurchaseOrder::create([
            'po_number' => 'PO-DEFECT-'.rand(1000, 9999),
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
     * An ATL raised the other way round - the Sales Order path - which DOES link
     * its allocation to the purchase order item.
     */
    protected function linkedAtl(PurchaseOrder $po, string $product, int $qty): PurchaseOrderDelivery
    {
        $atl = PurchaseOrderDelivery::create([
            'purchase_order_id' => $po->id,
            'delivery_channel' => 'SUPPLIER_CLIENT_PICKUP',
            'order_type' => 'PICK_UP',
            'atl_type' => 'DITC_ATL',
            'atl_number' => 'ATL-LINKED-'.uniqid(),
            'product' => $product,
            'qty_to_receive' => $qty,
            'receiving_date' => now()->addDays(2),
            'status' => 'Active',
            'lift_status' => PurchaseOrderDelivery::LIFT_UNLIFTED,
        ]);

        AtlAllocation::create([
            'purchase_order_delivery_id' => $atl->id,
            'purchase_order_id' => $po->id,
            'purchase_order_item_id' => $po->items()->where('product', $product)->value('id'),
            'product' => $product,
            'quantity' => $qty,
        ]);

        return $atl;
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    protected function submit(StockRequest $request, PurchaseOrder $po, array $overrides = [])
    {
        // The form posts a drawdown array now, because one ATL can be split across
        // several POs. Call sites keep the flat product/quantity they always used
        // and this builds the row.
        $product = $overrides['product'] ?? 'Diesel';
        $quantity = $overrides['qty_to_receive'] ?? 10000;
        $poId = $overrides['purchase_order_id'] ?? $po->id;

        unset($overrides['product'], $overrides['qty_to_receive'], $overrides['purchase_order_id']);

        return $this->actingAs($this->admin)
            ->post(route('stock-orders.wet-stock-requests.atls.store', $request->id), array_merge([
                'drawdowns' => [
                    ['purchase_order_id' => $poId, 'product' => $product, 'quantity' => $quantity],
                ],
                'order_type' => 'DELIVERY',
                'delivery_channel' => 'SUPPLIER_STOCKS_DELIVERY',
                'atl_type' => 'DITC_ATL',
                'atl_number' => 'ATL-'.uniqid(),
                'receiving_date' => now()->addDays(3)->format('Y-m-d'),
            ], $overrides));
    }

    // ---- The allocation must link the purchase order item ------------------

    public function test_the_allocation_is_linked_to_the_purchase_order_item(): void
    {
        $request = $this->request();
        $po = $this->po();

        $this->submit($request, $po)->assertSessionHas('success');

        $allocation = AtlAllocation::where('purchase_order_id', $po->id)->firstOrFail();

        $this->assertNotNull(
            $allocation->purchase_order_item_id,
            'A null item link hides this volume from the PO balance once any linked allocation exists.'
        );
    }

    public function test_the_balance_falls_when_both_kinds_of_atl_drew_from_one_po(): void
    {
        // The mix that hid the volume: a linked allocation first, then one from
        // the new stock-request page.
        $po = $this->po(['Diesel' => 10000]);
        $this->linkedAtl($po, 'Diesel', 6000);

        $this->assertSame(4000, $po->fresh()->getAvailableBalanceForProduct('Diesel'));

        $this->submit($this->request(10000), $po, ['qty_to_receive' => 4000])
            ->assertSessionHas('success');

        $this->assertSame(
            0,
            $po->fresh()->getAvailableBalanceForProduct('Diesel'),
            'Both drawdowns must count against the same PO.'
        );
    }

    public function test_a_drained_po_cannot_be_drawn_from_again(): void
    {
        $po = $this->po(['Diesel' => 10000]);
        $this->linkedAtl($po, 'Diesel', 6000);

        $this->submit($this->request(10000), $po, ['qty_to_receive' => 4000])
            ->assertSessionHas('success');

        $second = $this->request(10000);

        // A second request must not be able to take the last of it twice. The
        // refusal comes from the pool lookup, which no longer offers a drained
        // PO, so the field asserted is the PO rather than the quantity.
        $this->submit($second, $po, ['qty_to_receive' => 1000])
            ->assertSessionHasErrors();

        $this->assertSame(
            0,
            PurchaseOrderDelivery::where('request_id', $second->id)->count(),
            'Nothing may be ATLd against a request from a drained PO.'
        );
        $this->assertSame(0, $po->fresh()->getAvailableBalanceForProduct('Diesel'));
    }

    // ---- Received volume must not be subtracted twice ----------------------

    public function test_a_receipt_does_not_shrink_what_can_still_be_atled(): void
    {
        // 50,000 asked, 20,000 already ATL'd, and the depot has recorded that
        // same 20,000 arriving. A receipt settles an ATL; it is not a second draw.
        $request = $this->request(50000, ['Diesel' => 20000]);
        $po = $this->po(['Diesel' => 200000]);

        $this->submit($request, $po, ['qty_to_receive' => 20000])->assertSessionHas('success');

        $request->lines()->update(['qty_received' => 20000]);

        $pending = $request->fresh()->pendingByProduct();

        $this->assertSame(
            30000,
            $pending['Diesel'],
            'Only the ATL already issued should come off the outstanding figure.'
        );

        // And that budget is usable.
        $this->submit($request->fresh(), $po, ['qty_to_receive' => 30000])
            ->assertSessionHas('success');
    }

    // ---- Approve and Reject are only offered where they work --------------

    public function test_the_atl_tab_hides_approve_and_reject_from_an_m2_role(): void
    {
        $atl = PurchaseOrderDelivery::create([
            'purchase_order_id' => $this->po()->id,
            'delivery_channel' => 'SUPPLIER_STOCKS_DELIVERY',
            'order_type' => 'DELIVERY',
            'atl_type' => 'DITC_ATL',
            'atl_number' => 'ATL-BTN-'.rand(1000, 9999),
            'product' => 'Diesel',
            'qty_to_receive' => 10000,
            'receiving_date' => now()->addDays(2),
            'status' => 'Active',
            'lift_status' => PurchaseOrderDelivery::LIFT_UNLIFTED,
            'approval_status' => PurchaseOrderDelivery::APPROVAL_FOR_APPROVAL,
        ]);

        $ops = Admin::create([
            'username' => 'ops_'.uniqid(),
            'password' => bcrypt('secret'),
            'name' => 'Ops Manager',
            'role' => 'ops_mgr',
        ]);

        $html = $this->actingAs($ops)
            ->get(route('stock-orders.approvals', ['tab' => 'atls']))
            ->assertOk()
            ->getContent();

        // The row is listed - visibility is widened - but the actions that can
        // only ever 403 must not be drawn.
        $this->assertStringContainsString($atl->atl_number, $html);
        $this->assertStringNotContainsString(route('stock-orders.atl-approve', $atl->id), $html);
        $this->assertStringNotContainsString(route('stock-orders.atl-reject', $atl->id), $html);
    }

    public function test_the_vp_still_gets_approve_and_reject(): void
    {
        $atl = PurchaseOrderDelivery::create([
            'purchase_order_id' => $this->po()->id,
            'delivery_channel' => 'SUPPLIER_STOCKS_DELIVERY',
            'order_type' => 'DELIVERY',
            'atl_type' => 'DITC_ATL',
            'atl_number' => 'ATL-BTN2-'.rand(1000, 9999),
            'product' => 'Diesel',
            'qty_to_receive' => 10000,
            'receiving_date' => now()->addDays(2),
            'status' => 'Active',
            'lift_status' => PurchaseOrderDelivery::LIFT_UNLIFTED,
            'approval_status' => PurchaseOrderDelivery::APPROVAL_FOR_APPROVAL,
        ]);

        $vp = Admin::create([
            'username' => 'vp_'.uniqid(),
            'password' => bcrypt('secret'),
            'name' => 'Lorraine Bamba',
            'role' => 'vp',
        ]);

        $html = $this->actingAs($vp)
            ->get(route('stock-orders.approvals', ['tab' => 'atls']))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString(route('stock-orders.atl-approve', $atl->id), $html);
        $this->assertStringContainsString(route('stock-orders.atl-reject', $atl->id), $html);
    }
}
