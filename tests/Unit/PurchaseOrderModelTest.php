<?php

namespace Tests\Unit;

use App\Models\Admin;
use App\Models\Order;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderDelivery;
use App\Models\Warehouse;
use Tests\TestCase;

class PurchaseOrderModelTest extends TestCase
{
    public function test_admin_has_purchasing_role_and_helpers(): void
    {
        $admin = new Admin(['role' => 'purchasing']);
        $this->assertTrue($admin->isPurchasing());
        $this->assertTrue($admin->canAccessStockOrders());
        $this->assertTrue($admin->canEditStockOrders());
        $this->assertFalse($admin->canApproveStockOrders());

        $vp = new Admin(['role' => 'vp']);
        $this->assertTrue($vp->canApproveStockOrders());
    }

    public function test_order_model_has_fuel_trade_and_buy_back_helpers(): void
    {
        $order = new Order([
            'fulfillment_type' => 'FUEL_TRADE',
            'order_category' => 'BUY_BACK',
            'client_atl_number' => 'ATL-CLIENT-999',
        ]);

        $this->assertTrue($order->isFuelTrade());
        $this->assertTrue($order->isBuyBack());
        $this->assertFalse($order->isDepotPickup());
        $this->assertEquals('ATL-CLIENT-999', $order->client_atl_number);
    }

    public function test_purchase_order_casts_and_types(): void
    {
        $po = new PurchaseOrder([
            'po_type' => 'FUEL_TRADE',
            'qty_ordered' => '25000',
            'requested_products' => [
                ['product' => 'Diesel', 'quantity' => 15000],
                ['product' => 'Unleaded', 'quantity' => 10000],
            ],
        ]);

        $this->assertTrue($po->isFuelTrade());
        $this->assertFalse($po->isBuyBack());
        $this->assertSame(25000, $po->qty_ordered);
        $this->assertIsArray($po->requested_products);
        $this->assertCount(2, $po->requested_products);
    }

    public function test_purchase_order_computed_status_and_balance(): void
    {
        $wh = Warehouse::first() ?? Warehouse::create(['name' => 'San Simon', 'location' => 'San Simon']);

        $po = PurchaseOrder::create([
            'warehouse_id' => $wh->id,
            'qty_ordered' => 20000,
            'status' => 'Pending',
        ]);

        $this->assertEquals('Pending', $po->computed_status);
        $this->assertEquals(20000, $po->remaining_balance);

        PurchaseOrderDelivery::create([
            'purchase_order_id' => $po->id,
            'delivery_channel' => 'SUPPLIER_DOYEN_PICKUP',
            'product' => 'Diesel',
            'qty_to_receive' => 20000,
            'status' => 'Completed',
        ]);

        $po->refresh();
        $this->assertEquals(0, $po->remaining_balance);
        $this->assertEquals('Fulfilled', $po->computed_status);
    }

    public function test_purchase_order_delivery_channel_and_atl_helpers(): void
    {
        $deliveryDirect = new PurchaseOrderDelivery([
            'delivery_channel' => 'SUPPLIER_CLIENT_PICKUP',
            'order_type' => 'PICK_UP',
            'atl_type' => 'DITC_ATL',
        ]);

        $this->assertTrue($deliveryDirect->bypassesDepotTanks());
        $this->assertTrue($deliveryDirect->requiresAtl());
        $this->assertFalse($deliveryDirect->isClientAtl());

        $deliveryClientAtl = new PurchaseOrderDelivery([
            'delivery_channel' => 'BUY_BACK_CLIENT_PICKUP',
            'order_type' => 'PICK_UP',
            'atl_type' => 'CLIENT_ATL',
            'client_atl_number' => 'C-ATL-1234',
        ]);

        $this->assertTrue($deliveryClientAtl->isClientAtl());
    }
}
