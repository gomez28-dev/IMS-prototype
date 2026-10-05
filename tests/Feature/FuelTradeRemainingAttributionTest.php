<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderDelivery;
use Tests\TestCase;

class FuelTradeRemainingAttributionTest extends TestCase
{
    public function test_fuel_trade_order_only_counts_its_own_completed_deliveries_from_linked_po(): void
    {
        // One supplier PO feeds several Fuel Trade SOs (real-world shape:
        // deliveries carry the SO they serve via their own so_number).
        $soA = 'SO-FTA-' . uniqid();
        $soB = '4321-' . uniqid();

        $orderA = Order::create([
            'account' => 'ACME Logistics',
            'location' => 'Valenzuela',
            'so_number' => $soA,
            'date' => now(),
            'qty_ordered' => 20000,
            'price' => 50.00,
            'status' => 'Active',
            'fulfillment_type' => 'FUEL_TRADE',
        ]);

        $orderB = Order::create([
            'account' => 'ACME Logistics',
            'location' => 'Valenzuela',
            'so_number' => $soB,
            'date' => now(),
            'qty_ordered' => 1000,
            'price' => 50.00,
            'status' => 'Active',
            'fulfillment_type' => 'FUEL_TRADE',
        ]);

        $po = PurchaseOrder::create([
            'po_number' => 'PO-FTA-' . uniqid(),
            'so_number' => null,
            'po_type' => 'FUEL_TRADE',
            'supplier_name' => 'Petron',
            'qty_ordered' => 50000,
            'request_status' => 'FOR_DELIVERY',
        ]);

        // Both orders point at the same PO.
        $orderA->update(['linked_purchase_order_id' => $po->id]);
        $orderB->update(['linked_purchase_order_id' => $po->id]);

        // Delivery for SO A completed; delivery for SO B still pending.
        PurchaseOrderDelivery::create([
            'purchase_order_id' => $po->id,
            'delivery_channel' => 'SUPPLIER_CLIENT_PICKUP',
            'order_type' => 'PICK_UP',
            'atl_type' => 'DITC_ATL',
            'product' => 'Diesel',
            'qty_to_receive' => 20000,
            'so_number' => $soA,
            'status' => 'Completed',
        ]);

        PurchaseOrderDelivery::create([
            'purchase_order_id' => $po->id,
            'delivery_channel' => 'SUPPLIER_CLIENT_PICKUP',
            'order_type' => 'PICK_UP',
            'atl_type' => 'DITC_ATL',
            'product' => 'Diesel',
            'qty_to_receive' => 1000,
            'so_number' => $soB,
            'status' => 'Pending',
        ]);

        $orderA->refresh();
        $orderB->refresh();

        // A should see its 20,000 delivered; B should see 0, not 20,000.
        $this->assertEquals(20000, $orderA->total_qty_out);
        $this->assertEquals(0, $orderB->total_qty_out);
        $this->assertEquals(0, $orderA->remaining_balance);
        $this->assertEquals(1000, $orderB->remaining_balance);

        // Committed: A counts only its Completed row; B counts its Pending row.
        $this->assertEquals(20000, $orderA->committed_qty_out);
        $this->assertEquals(1000, $orderB->committed_qty_out);
    }
}
