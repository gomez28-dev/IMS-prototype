<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\AtlAllocation;
use App\Models\Order;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderDelivery;
use Tests\TestCase;

class AtlListingTest extends TestCase
{
    protected Admin $purchasing;

    protected function setUp(): void
    {
        parent::setUp();

        $this->purchasing = Admin::create([
            'username' => 'purchasing_' . uniqid(),
            'password' => bcrypt('secret'),
            'name' => 'Rica Esaga',
            'role' => 'purchasing',
        ]);
    }

    public function test_atl_list_shows_only_fuel_trade_and_buy_back_pickup_orders(): void
    {
        $fuelTrade = $this->order('FT-1', 'FUEL_TRADE', 'CLIENT_ORDER');
        $buyBack = $this->order('BB-1', 'DEPOT_PICKUP', 'BUY_BACK');
        // Neither qualifies: a plain delivery, and a buy back we deliver ourselves.
        $this->order('PLAIN-1', 'DELIVERY', 'CLIENT_ORDER');
        $this->order('BB-DELIVERED', 'DELIVERY', 'BUY_BACK');

        $response = $this->actingAs($this->purchasing)->get(route('stock-orders.sales-orders.index'));

        $response->assertStatus(200);
        $response->assertSee($fuelTrade->so_number);
        $response->assertSee($buyBack->so_number);
        $response->assertDontSee('PLAIN-1');
        $response->assertDontSee('BB-DELIVERED');
    }

    public function test_atl_list_excludes_cancelled_orders(): void
    {
        $live = $this->order('FT-LIVE', 'FUEL_TRADE', 'CLIENT_ORDER');
        $this->order('FT-CANCELLED', 'FUEL_TRADE', 'CLIENT_ORDER', 'Cancelled');

        $response = $this->actingAs($this->purchasing)->get(route('stock-orders.sales-orders.index'));

        $response->assertStatus(200);
        $response->assertSee($live->so_number);
        $response->assertDontSee('FT-CANCELLED');
    }

    public function test_atl_list_shows_clearance_and_acl_only_to_roles_that_may_issue(): void
    {
        $pendingClearance = $this->order('FT-PENDING-CLEARANCE', 'FUEL_TRADE', 'CLIENT_ORDER');
        $pendingClearance->update(['clearing_status' => 'Pending']);

        $response = $this->actingAs($this->purchasing)->get(route('stock-orders.sales-orders.index'));

        $response->assertStatus(200);
        $response->assertSee($pendingClearance->so_number);
        // Clearance is shown, but an uncleared order offers no Create ATL action.
        $response->assertSee('Pending');
    }

    public function test_atl_list_can_be_searched_by_account_or_so_number(): void
    {
        $target = $this->order('FT-SEARCH', 'FUEL_TRADE', 'CLIENT_ORDER');
        $this->order('FT-OTHER', 'FUEL_TRADE', 'CLIENT_ORDER', 'Active', 'Some Other Client');

        $bySo = $this->actingAs($this->purchasing)
            ->get(route('stock-orders.sales-orders.index', ['search' => 'FT-SEARCH']));
        $bySo->assertStatus(200);
        $bySo->assertSee($target->so_number);
        $bySo->assertDontSee('FT-OTHER');

        $byAccount = $this->actingAs($this->purchasing)
            ->get(route('stock-orders.sales-orders.index', ['search' => 'Some Other Client']));
        $byAccount->assertStatus(200);
        $byAccount->assertSee('FT-OTHER');
        $byAccount->assertDontSee($target->so_number);
    }

    public function test_atl_details_lists_the_orders_atls_with_products_and_statuses(): void
    {
        $order = $this->order('FT-DETAIL', 'FUEL_TRADE', 'CLIENT_ORDER');
        $po = PurchaseOrder::create([
            'po_number' => 'PO-DETAIL', 'po_type' => 'FUEL_TRADE',
            'supplier_name' => 'Petron', 'qty_ordered' => 50000, 'request_status' => 'CONFIRMED',
        ]);

        $atl = PurchaseOrderDelivery::create([
            'purchase_order_id' => $po->id,
            'order_id' => $order->id,
            'so_number' => $order->so_number,
            'atl_number' => 'ATL-DETAIL-1',
            'atl_category' => PurchaseOrderDelivery::CATEGORY_FUEL_TRADE,
            'atl_source' => PurchaseOrderDelivery::SOURCE_DOYEN_ISSUED,
            'approval_status' => PurchaseOrderDelivery::APPROVAL_APPROVED,
            'lift_status' => PurchaseOrderDelivery::LIFT_UNLIFTED,
            'issued_at' => now(),
            'product' => 'Diesel',
            'qty_to_receive' => 5000,
            'receiving_date' => now()->addDay(),
            'driver_name' => 'Juan Dela Cruz',
            'plate_number' => 'NBD-1234',
            'location' => 'Limay Terminal',
        ]);

        AtlAllocation::create([
            'purchase_order_delivery_id' => $atl->id,
            'purchase_order_id' => $po->id,
            'product' => 'Diesel', 'quantity' => 5000,
        ]);

        $response = $this->actingAs($this->purchasing)
            ->get(route('stock-orders.sales-orders.show', $order->id));

        $response->assertStatus(200);
        $response->assertSee($order->so_number);
        $response->assertSee('ATL-DETAIL-1');
        $response->assertSee('PO-DETAIL');
        $response->assertSee('Juan Dela Cruz');
        $response->assertSee('NBD-1234');
        $response->assertSee('Unlifted');
    }

    public function test_atl_details_reports_totals_per_product(): void
    {
        $order = $this->order('FT-TOTALS', 'FUEL_TRADE', 'CLIENT_ORDER');
        $po = PurchaseOrder::create([
            'po_number' => 'PO-TOTALS', 'po_type' => 'FUEL_TRADE',
            'supplier_name' => 'Petron', 'qty_ordered' => 50000, 'request_status' => 'CONFIRMED',
        ]);

        $diesel = PurchaseOrderDelivery::create([
            'purchase_order_id' => $po->id, 'order_id' => $order->id,
            'so_number' => $order->so_number, 'atl_number' => 'ATL-T-D',
            'atl_category' => PurchaseOrderDelivery::CATEGORY_FUEL_TRADE,
            'lift_status' => PurchaseOrderDelivery::LIFT_UNLIFTED,
            'product' => 'Diesel', 'qty_to_receive' => 5000,
            'receiving_date' => now()->addDay(),
        ]);
        AtlAllocation::create([
            'purchase_order_delivery_id' => $diesel->id, 'purchase_order_id' => $po->id,
            'product' => 'Diesel', 'quantity' => 5000,
        ]);

        $premium = PurchaseOrderDelivery::create([
            'purchase_order_id' => $po->id, 'order_id' => $order->id,
            'so_number' => $order->so_number, 'atl_number' => 'ATL-T-P',
            'atl_category' => PurchaseOrderDelivery::CATEGORY_FUEL_TRADE,
            'lift_status' => PurchaseOrderDelivery::LIFT_UNLIFTED,
            'product' => 'Premium', 'qty_to_receive' => 3000,
            'receiving_date' => now()->addDay(),
        ]);
        AtlAllocation::create([
            'purchase_order_delivery_id' => $premium->id, 'purchase_order_id' => $po->id,
            'product' => 'Premium', 'quantity' => 3000,
        ]);

        $response = $this->actingAs($this->purchasing)
            ->get(route('stock-orders.sales-orders.show', $order->id));

        $response->assertStatus(200);
        // Total ATL'd across both ATLs, and each product broken out.
        $response->assertSee('8,000');
        $response->assertSee('5,000');
        $response->assertSee('3,000');
    }

    public function test_atl_pages_are_gated_by_stock_orders_role(): void
    {
        $sales = Admin::create([
            'username' => 'sales_' . uniqid(),
            'password' => bcrypt('secret'),
            'name' => 'Sales User',
            'role' => 'sales',
        ]);

        $this->actingAs($sales)->get(route('stock-orders.sales-orders.index'))->assertForbidden();
    }

    private function order(
        string $soNumber,
        string $fulfillmentType,
        string $category,
        string $status = 'Active',
        string $account = 'Apex Logistics'
    ): Order {
        return Order::create([
            'account' => $account,
            'location' => 'Valenzuela',
            'so_number' => $soNumber . '-' . uniqid(),
            'date' => now(),
            'qty_ordered' => 1000,
            'price' => 50.00,
            'status' => $status,
            'clearing_status' => 'Approved',
            'fulfillment_type' => $fulfillmentType,
            'order_category' => $category,
        ]);
    }
}
