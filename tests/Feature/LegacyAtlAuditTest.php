<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderDelivery;
use Tests\TestCase;

class LegacyAtlAuditTest extends TestCase
{
    public function test_report_only_mode_changes_no_data(): void
    {
        $this->makeLegacyPair('SO-LEGACY-1');

        $before = PurchaseOrderDelivery::whereNull('order_id')->count();

        $this->artisan('stock-orders:legacy-atl')
            ->expectsOutputToContain('Can be linked confidently')
            ->assertExitCode(0);

        $this->assertEquals(
            $before,
            PurchaseOrderDelivery::whereNull('order_id')->count(),
            'Report mode must not change any data'
        );
    }

    public function test_apply_links_an_atl_to_its_sales_order_by_so_number(): void
    {
        [$order, $atl] = $this->makeLegacyPair('SO-LEGACY-2');

        $this->artisan('stock-orders:legacy-atl --apply')->assertExitCode(0);

        $this->assertEquals($order->id, $atl->fresh()->order_id);
    }

    public function test_apply_is_idempotent(): void
    {
        [$order, $atl] = $this->makeLegacyPair('SO-LEGACY-3');

        $this->artisan('stock-orders:legacy-atl --apply')->assertExitCode(0);
        $this->artisan('stock-orders:legacy-atl --apply')->assertExitCode(0);

        $this->assertEquals($order->id, $atl->fresh()->order_id);
        // Still exactly one delivery row; a second run must not duplicate.
        $this->assertEquals(1, PurchaseOrderDelivery::where('id', $atl->id)->count());
    }

    public function test_apply_infers_the_order_when_only_one_is_attached_to_the_po(): void
    {
        $order = Order::create([
            'account' => 'Apex',
            'location' => 'Valenzuela',
            'so_number' => 'SO-NO-SO-' . uniqid(),
            'date' => now(),
            'qty_ordered' => 5000,
            'price' => 50.00,
            'status' => 'Active',
            'clearing_status' => 'Approved',
            'fulfillment_type' => 'FUEL_TRADE',
            'order_category' => 'CLIENT_ORDER',
        ]);

        $po = PurchaseOrder::create([
            'po_number' => 'PO-INFER-' . rand(1000, 9999),
            'po_type' => 'FUEL_TRADE',
            'supplier_name' => 'Petron',
            'qty_ordered' => 5000,
            'request_status' => 'CONFIRMED',
        ]);

        $order->update(['linked_purchase_order_id' => $po->id]);

        // No so_number on the ATL, so it can only be inferred from the PO.
        $atl = PurchaseOrderDelivery::create([
            'purchase_order_id' => $po->id,
            'so_number' => null,
            'atl_type' => 'DITC_ATL',
            'atl_number' => 'ATL-INFER-' . rand(1000, 9999),
            'product' => 'Diesel',
            'qty_to_receive' => 5000,
            'receiving_date' => now(),
            'status' => 'Pending',
        ]);

        $this->artisan('stock-orders:legacy-atl --apply')->assertExitCode(0);

        $this->assertEquals($order->id, $atl->fresh()->order_id);
    }

    public function test_apply_leaves_ambiguous_records_unlinked(): void
    {
        $po = PurchaseOrder::create([
            'po_number' => 'PO-AMBIG-' . rand(1000, 9999),
            'po_type' => 'STANDARD_REPLENISHMENT',
            'supplier_name' => 'Petron',
            'qty_ordered' => 1000,
            'request_status' => 'CONFIRMED',
        ]);

        // No sales order number and no order attached to the PO: too risky to
        // guess, so it must be reported rather than linked.
        $atl = PurchaseOrderDelivery::create([
            'purchase_order_id' => $po->id,
            'so_number' => null,
            'atl_type' => 'DITC_ATL',
            'atl_number' => 'ATL-AMBIG-' . rand(1000, 9999),
            'product' => 'Diesel',
            'qty_to_receive' => 1000,
            'receiving_date' => now(),
            'status' => 'Pending',
        ]);

        $this->artisan('stock-orders:legacy-atl')
            ->expectsOutputToContain('Ambiguous')
            ->assertExitCode(0);

        $this->assertNull($atl->fresh()->order_id);
    }

    public function test_report_flags_a_purchase_order_shared_by_several_orders(): void
    {
        $po = PurchaseOrder::create([
            'po_number' => 'PO-SHARED-' . rand(1000, 9999),
            'po_type' => 'FUEL_TRADE',
            'supplier_name' => 'Petron',
            'qty_ordered' => 20000,
            'request_status' => 'CONFIRMED',
        ]);

        foreach (range(1, 3) as $i) {
            $order = Order::create([
                'account' => 'Apex',
                'location' => 'Valenzuela',
                'so_number' => 'SO-SHARED-' . $i . '-' . uniqid(),
                'date' => now(),
                'qty_ordered' => 1000,
                'price' => 50.00,
                'status' => 'Active',
                'clearing_status' => 'Approved',
                'fulfillment_type' => 'FUEL_TRADE',
                'order_category' => 'CLIENT_ORDER',
            ]);
            $order->update(['linked_purchase_order_id' => $po->id]);
        }

        $this->artisan('stock-orders:legacy-atl')
            ->expectsOutputToContain('3 sales orders linked')
            ->assertExitCode(0);
    }

    /**
     * @return array{0: Order, 1: PurchaseOrderDelivery}
     */
    private function makeLegacyPair(string $soPrefix): array
    {
        $order = Order::create([
            'account' => 'Apex Logistics',
            'location' => 'Valenzuela',
            'so_number' => $soPrefix . '-' . uniqid(),
            'date' => now(),
            'qty_ordered' => 5000,
            'price' => 50.00,
            'status' => 'Active',
            'clearing_status' => 'Approved',
            'fulfillment_type' => 'FUEL_TRADE',
            'order_category' => 'CLIENT_ORDER',
        ]);

        $po = PurchaseOrder::create([
            'po_number' => 'PO-LEG-' . rand(1000, 9999),
            'po_type' => 'FUEL_TRADE',
            'supplier_name' => 'Petron Bataan',
            'qty_ordered' => 5000,
            'request_status' => 'CONFIRMED',
            'linked_order_id' => $order->id,
        ]);

        $order->update(['linked_purchase_order_id' => $po->id]);

        $atl = PurchaseOrderDelivery::create([
            'purchase_order_id' => $po->id,
            'so_number' => $order->so_number,
            'atl_type' => 'DITC_ATL',
            'atl_number' => 'ATL-' . rand(1000, 9999),
            'product' => 'Diesel',
            'qty_to_receive' => 5000,
            'receiving_date' => now(),
            'status' => 'Pending',
        ]);

        return [$order, $atl];
    }
}
