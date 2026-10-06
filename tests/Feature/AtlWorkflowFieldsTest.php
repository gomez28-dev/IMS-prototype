<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\AtlAllocation;
use App\Models\Order;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderDelivery;
use Tests\TestCase;

class AtlWorkflowFieldsTest extends TestCase
{
    public function test_atl_can_be_linked_to_a_sales_order_and_reports_its_category(): void
    {
        $order = $this->fuelTradeOrder('SO-ATL-1');

        $po = PurchaseOrder::create([
            'po_number' => 'PO-ATL-1',
            'po_type' => 'FUEL_TRADE',
            'supplier_name' => 'Petron Bataan',
            'qty_ordered' => 20000,
            'request_status' => 'CONFIRMED',
        ]);

        $atl = PurchaseOrderDelivery::create([
            'purchase_order_id' => $po->id,
            'order_id' => $order->id,
            'atl_category' => PurchaseOrderDelivery::CATEGORY_FUEL_TRADE,
            'atl_source' => PurchaseOrderDelivery::SOURCE_DOYEN_ISSUED,
            'approval_status' => PurchaseOrderDelivery::APPROVAL_DRAFT,
            'lift_status' => PurchaseOrderDelivery::LIFT_UNLIFTED,
            'issued_at' => now(),
            'so_number' => $order->so_number,
            'product' => 'Diesel',
            'qty_to_receive' => 20000,
            'receiving_date' => now()->addDay(),
        ]);

        $this->assertEquals($order->id, $atl->order->id);
        $this->assertEquals('FUEL_TRADE', $atl->atl_category);
        $this->assertFalse($atl->isClientProvided());
        $this->assertFalse($atl->isApproved());
        $this->assertFalse($atl->isLifted());
    }

    public function test_atl_can_draw_from_several_purchase_orders(): void
    {
        $order = $this->fuelTradeOrder('SO-ATL-2');

        $poA = PurchaseOrder::create([
            'po_number' => 'PO-A', 'po_type' => 'FUEL_TRADE',
            'supplier_name' => 'Petron', 'qty_ordered' => 10000, 'request_status' => 'CONFIRMED',
        ]);
        $poB = PurchaseOrder::create([
            'po_number' => 'PO-B', 'po_type' => 'FUEL_TRADE',
            'supplier_name' => 'Shell', 'qty_ordered' => 10000, 'request_status' => 'CONFIRMED',
        ]);

        $atl = PurchaseOrderDelivery::create([
            // purchase_order_id only records the primary PO.
            'purchase_order_id' => $poA->id,
            'order_id' => $order->id,
            'atl_category' => PurchaseOrderDelivery::CATEGORY_FUEL_TRADE,
            'lift_status' => PurchaseOrderDelivery::LIFT_UNLIFTED,
            'so_number' => $order->so_number,
            'product' => 'Diesel',
            'qty_to_receive' => 15000,
            'receiving_date' => now()->addDay(),
        ]);

        AtlAllocation::create([
            'purchase_order_delivery_id' => $atl->id,
            'purchase_order_id' => $poA->id,
            'product' => 'Diesel',
            'quantity' => 10000,
        ]);
        AtlAllocation::create([
            'purchase_order_delivery_id' => $atl->id,
            'purchase_order_id' => $poB->id,
            'product' => 'Diesel',
            'quantity' => 5000,
        ]);

        // Allocations are the authoritative ATL -> PO relationship.
        $sources = $atl->sourcePurchaseOrders()->pluck('po_number')->sort()->values()->all();
        $this->assertEquals(['PO-A', 'PO-B'], $sources);
        $this->assertEquals(15000, $atl->productBreakdown()['Diesel']);
    }

    public function test_multi_product_atl_reports_each_product_separately(): void
    {
        $order = $this->fuelTradeOrder('SO-ATL-3');
        $po = PurchaseOrder::create([
            'po_number' => 'PO-MP', 'po_type' => 'FUEL_TRADE',
            'supplier_name' => 'Petron', 'qty_ordered' => 50000, 'request_status' => 'CONFIRMED',
        ]);

        $atl = PurchaseOrderDelivery::create([
            'purchase_order_id' => $po->id,
            'order_id' => $order->id,
            'atl_category' => PurchaseOrderDelivery::CATEGORY_FUEL_TRADE,
            'lift_status' => PurchaseOrderDelivery::LIFT_UNLIFTED,
            'product' => 'Diesel + Premium',
            'qty_to_receive' => 8000,
            'receiving_date' => now()->addDay(),
        ]);

        AtlAllocation::create([
            'purchase_order_delivery_id' => $atl->id,
            'purchase_order_id' => $po->id,
            'product' => 'Diesel', 'quantity' => 5000,
        ]);
        AtlAllocation::create([
            'purchase_order_delivery_id' => $atl->id,
            'purchase_order_id' => $po->id,
            'product' => 'Premium', 'quantity' => 3000,
        ]);

        $breakdown = $atl->productBreakdown();
        $this->assertEquals(5000, $breakdown['Diesel']);
        $this->assertEquals(3000, $breakdown['Premium']);
        $this->assertEquals(8000, array_sum($breakdown));
    }

    public function test_doyen_stocks_atl_has_no_sales_order(): void
    {
        $po = PurchaseOrder::create([
            'po_number' => 'PO-STOCKS', 'po_type' => 'STANDARD_REPLENISHMENT',
            'supplier_name' => 'Petron', 'qty_ordered' => 10000, 'request_status' => 'CONFIRMED',
        ]);

        $atl = PurchaseOrderDelivery::create([
            'purchase_order_id' => $po->id,
            'order_id' => null,
            'atl_category' => PurchaseOrderDelivery::CATEGORY_DOYEN_STOCKS,
            'lift_status' => PurchaseOrderDelivery::LIFT_UNLIFTED,
            'product' => 'Diesel',
            'qty_to_receive' => 10000,
            'receiving_date' => now()->addDay(),
        ]);

        $this->assertNull($atl->order);
        $this->assertTrue($atl->isDoyenStocks());
    }

    public function test_lifted_atl_records_who_lifted_it(): void
    {
        $purchasing = Admin::create([
            'username' => 'purchasing_' . uniqid(),
            'password' => bcrypt('secret'),
            'name' => 'Rica Esaga',
            'role' => 'purchasing',
        ]);

        $atl = PurchaseOrderDelivery::create([
            'purchase_order_id' => PurchaseOrder::create([
                'po_number' => 'PO-LIFT', 'po_type' => 'FUEL_TRADE',
                'supplier_name' => 'Petron', 'qty_ordered' => 1000, 'request_status' => 'CONFIRMED',
            ])->id,
            'atl_category' => PurchaseOrderDelivery::CATEGORY_FUEL_TRADE,
            'approval_status' => PurchaseOrderDelivery::APPROVAL_APPROVED,
            'lift_status' => PurchaseOrderDelivery::LIFT_LIFTED,
            'lifted_at' => now(),
            'lifted_by' => $purchasing->id,
            'product' => 'Diesel',
            'qty_to_receive' => 1000,
            'receiving_date' => now()->addDay(),
        ]);

        $this->assertTrue($atl->isLifted());
        $this->assertEquals('Rica Esaga', $atl->lifter->name);
    }

    public function test_legacy_delivery_rows_are_backfilled_into_the_new_lifecycle_fields(): void
    {
        $order = $this->fuelTradeOrder('SO-LEGACY');

        $po = PurchaseOrder::create([
            'po_number' => 'PO-LEGACY', 'po_type' => 'FUEL_TRADE',
            'supplier_name' => 'Petron', 'qty_ordered' => 1000,
            'request_status' => 'CONFIRMED',
        ]);

        $legacy = PurchaseOrderDelivery::create([
            'purchase_order_id' => $po->id,
            'so_number' => $order->so_number,
            'atl_type' => 'DITC_ATL',
            'product' => 'Diesel',
            'qty_to_receive' => 1000,
            'status' => 'Completed',
        ]);

        // Run the migration's backfill against this row.
        $migration = require database_path('migrations/2026_10_06_000003_add_atl_workflow_fields_to_purchase_order_deliveries.php');
        $method = new \ReflectionMethod($migration, 'backfillAtlFields');
        $method->setAccessible(true);
        $method->invoke($migration);

        $legacy->refresh();

        $this->assertEquals('LIFTED', $legacy->lift_status);
        $this->assertEquals('FUEL_TRADE', $legacy->atl_category);
        $this->assertEquals('DOYEN_ISSUED', $legacy->atl_source);
        // Already approved, so it must not reappear in the approval queue.
        $this->assertEquals('APPROVED', $legacy->approval_status);
    }

    private function fuelTradeOrder(string $soNumber): Order
    {
        return Order::create([
            'account' => 'Apex Logistics',
            'location' => 'Valenzuela',
            'so_number' => $soNumber . '-' . uniqid(),
            'date' => now(),
            'qty_ordered' => 1000,
            'price' => 50.00,
            'status' => 'Active',
            'clearing_status' => 'Approved',
            'fulfillment_type' => 'FUEL_TRADE',
            'order_category' => 'CLIENT_ORDER',
        ]);
    }
}
