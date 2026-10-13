<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\AtlAllocation;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\Warehouse;
use Tests\TestCase;

/**
 * Regression cover for PO balance accounting.
 *
 * Allocations record purchase_order_id, and two of the three creation sites
 * also recorded purchase_order_item_id. The one that did not meant a PO drawn
 * through that route still reported its full quantity as available. The
 * Allocate dropdown is built on that balance, so the item link and the
 * fallback for older unlinked rows are both pinned here.
 */
class AllocationItemLinkTest extends TestCase
{
    protected Admin $purchasing;

    protected Warehouse $depot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->purchasing = Admin::create([
            'username' => 'alloc_'.uniqid(),
            'password' => bcrypt('secret'),
            'name' => 'Purchasing Officer',
            'role' => 'purchasing',
        ]);

        $this->depot = Warehouse::create(['name' => 'Alloc Depot '.rand(1000, 9999)]);
    }

    /**
     * A PO with one Diesel line of the given quantity.
     */
    protected function po(string $number, int $qty = 10000): PurchaseOrder
    {
        $po = PurchaseOrder::create([
            'po_number' => $number,
            'po_type' => PurchaseOrder::TYPE_STANDARD_REPLENISHMENT,
            'warehouse_id' => $this->depot->id,
            'qty_ordered' => $qty,
            'request_status' => 'CONFIRMED',
            'status' => 'Pending',
            'prepared_by' => $this->purchasing->id,
        ]);

        PurchaseOrderItem::create([
            'purchase_order_id' => $po->id,
            'product' => 'Diesel',
            'quantity_ordered' => $qty,
            'unit_price' => 50,
        ]);

        return $po->fresh();
    }

    protected function clearedOrder(int $qty)
    {
        $order = \App\Models\Order::create([
            'account' => 'Apex Logistics Corp',
            'location' => 'Valenzuela',
            'so_number' => (string) rand(100000, 999999),
            'date' => now(),
            'status' => 'Active',
            'terms' => 'COD',
            'clearing_status' => 'Approved',
            'order_category' => \App\Models\Order::CATEGORY_CLIENT_ORDER,
            'fulfillment_type' => \App\Models\Order::FULFILLMENT_FUEL_TRADE,
            'qty_ordered' => $qty,
            'created_by' => $this->purchasing->id,
        ]);

        \App\Models\OrderItem::create([
            'order_id' => $order->id,
            'product_type' => 'D',
            'qty' => $qty,
            'price' => 50,
        ]);

        return $order->fresh();
    }

    /**
     * The ATL issued for a sales order must link its allocation to the PO item,
     * so the PO balance accounts for the drawdown.
     */
    public function test_an_atl_links_its_allocation_to_the_po_item(): void
    {
        $po = $this->po('PO-LINKED', 10000);
        $order = $this->clearedOrder(4000);

        $this->actingAs($this->purchasing)->post(route('stock-orders.store-fuel-trade-po', $order->id), [
            'receiving_date' => now()->addDays(2)->format('Y-m-d'),
            'driver_name' => 'Juan Dela Cruz',
            'plate_number' => 'NBD-1234',
            'location' => 'Petron Limay Terminal',
            'atl_type' => 'DITC_ATL',
            'atl_number' => 'ATL-'.uniqid(),
            'drawdowns' => [
                ['purchase_order_id' => $po->id, 'product' => 'Diesel', 'quantity' => 4000],
            ],
        ])->assertSessionHasNoErrors();

        $allocation = AtlAllocation::where('purchase_order_id', $po->id)->firstOrFail();

        $this->assertNotNull(
            $allocation->purchase_order_item_id,
            'The allocation must be linked to the PO item so the balance accounts for it.'
        );
        $this->assertSame($po->items->first()->id, $allocation->purchase_order_item_id);
        $this->assertSame(6000, $po->fresh()->getAvailableBalanceForProduct('Diesel'));
    }

    /**
     * The item-level total must also count allocations that were never linked,
     * which is the case for every ATL raised before the link was added.
     */
    public function test_an_unlinked_allocation_still_counts_against_the_balance(): void
    {
        $po = $this->po('PO-UNLINKED', 10000);
        $item = $po->items->first();

        $delivery = \App\Models\PurchaseOrderDelivery::create([
            'purchase_order_id' => $po->id,
            'delivery_channel' => 'SUPPLIER_CLIENT_PICKUP',
            'atl_category' => \App\Models\PurchaseOrderDelivery::CATEGORY_FUEL_TRADE,
            'product' => 'Diesel',
            'qty_to_receive' => 4000,
            'status' => 'Active',
            'lift_status' => \App\Models\PurchaseOrderDelivery::LIFT_LIFTED,
            'approval_status' => \App\Models\PurchaseOrderDelivery::APPROVAL_APPROVED,
        ]);

        // Deliberately no purchase_order_item_id, as legacy rows have.
        AtlAllocation::create([
            'purchase_order_delivery_id' => $delivery->id,
            'purchase_order_id' => $po->id,
            'product' => 'Diesel',
            'quantity' => 4000,
        ]);

        $this->assertSame(4000, $item->fresh()->total_drawn);
        $this->assertSame(6000, $item->fresh()->remaining_balance);
        $this->assertSame(6000, $po->fresh()->getAvailableBalanceForProduct('Diesel'));
    }

    /**
     * A cancelled delivery releases its volume again, linked or not.
     */
    public function test_a_cancelled_delivery_releases_the_volume(): void
    {
        $po = $this->po('PO-CANCELLED', 10000);
        $item = $po->items->first();

        $delivery = \App\Models\PurchaseOrderDelivery::create([
            'purchase_order_id' => $po->id,
            'delivery_channel' => 'SUPPLIER_CLIENT_PICKUP',
            'atl_category' => \App\Models\PurchaseOrderDelivery::CATEGORY_FUEL_TRADE,
            'product' => 'Diesel',
            'qty_to_receive' => 4000,
            'status' => 'Cancelled',
            'lift_status' => \App\Models\PurchaseOrderDelivery::LIFT_CANCELLED,
            'approval_status' => \App\Models\PurchaseOrderDelivery::APPROVAL_APPROVED,
        ]);

        AtlAllocation::create([
            'purchase_order_delivery_id' => $delivery->id,
            'purchase_order_id' => $po->id,
            'purchase_order_item_id' => $item->id,
            'product' => 'Diesel',
            'quantity' => 4000,
        ]);

        $this->assertSame(0, $item->fresh()->total_drawn);
        $this->assertSame(10000, $po->fresh()->getAvailableBalanceForProduct('Diesel'));
    }

    /**
     * A PO whose product was never asked for has nothing of it available.
     */
    public function test_a_product_the_po_never_bought_reports_nothing(): void
    {
        $po = $this->po('PO-DIESEL-ONLY', 10000);

        $this->assertSame(10000, $po->fresh()->getAvailableBalanceForProduct('Diesel'));
        $this->assertSame(0, $po->fresh()->getAvailableBalanceForProduct('Unleaded'));
        $this->assertSame(0, $po->fresh()->getAvailableBalanceForProduct('Premium'));
    }
}