<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Client;
use App\Models\Delivery;
use App\Models\DeliveryAllocation;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderDelivery;
use App\Models\StorageTank;
use App\Models\Warehouse;
use Tests\TestCase;

class MobileResponsivenessUiTest extends TestCase
{
    private Admin $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = Admin::create([
            'username' => 'admin_mob_'.uniqid(),
            'password' => bcrypt('secret'),
            'name' => 'Mobile Test Admin',
            'role' => 'admin',
            'is_active' => true,
        ]);
    }

    private function createOrder(array $overrides = []): Order
    {
        $order = Order::create(array_merge([
            'account' => 'Mobile Client Corp',
            'location' => 'Valenzuela',
            'so_number' => 'MOB-'.rand(10000, 99999),
            'po_number' => 'PO-'.rand(1000, 9999),
            'date' => now()->format('Y-m-d'),
            'status' => 'Active',
            'qty_ordered' => 10000,
            'clearing_status' => 'Pending',
            'fulfillment_type' => Order::FULFILLMENT_DELIVERY,
            'order_category' => Order::CATEGORY_CLIENT_ORDER,
            'terms' => '5 Days',
        ], $overrides));

        OrderItem::create([
            'order_id' => $order->id,
            'product_type' => 'D',
            'qty' => 10000,
            'price' => 80.00,
        ]);

        return $order->fresh();
    }

    public function test_orders_dashboard_removes_declined_tab_but_keeps_status_logic_and_updates_mobile_cards(): void
    {
        $regular = $this->createOrder(['date_needed' => '2026-12-22']);
        $buyBack = $this->createOrder([
            'order_category' => Order::CATEGORY_BUY_BACK,
            'fulfillment_type' => Order::FULFILLMENT_PICKUP,
            'date_needed' => null,
        ]);
        $fuelTrade = $this->createOrder([
            'fulfillment_type' => Order::FULFILLMENT_FUEL_TRADE,
            'date_needed' => '2026-12-25',
        ]);
        $declined = $this->createOrder([
            'clearing_status' => 'Declined',
            'account' => 'Declined Logic Still Works Client',
        ]);

        $html = $this->actingAs($this->admin)
            ->get(route('dashboard', ['tab' => 'pending']))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('tab=declined', $html);
        $this->assertStringContainsString('tab=pending', $html);
        $this->assertStringContainsString('tab=hold', $html);
        $this->assertStringContainsString('tab=approved', $html);
        $this->assertStringContainsString('tab=cancelled', $html);
        $this->assertStringContainsString('app-footer', $html);

        // Mobile cards section should show Order Date:, Date Needed:, — when empty, and omit order.receipt
        $mobileStart = strpos($html, '<div class="rec-cards">');
        $this->assertNotFalse($mobileStart);
        $desktopTable = substr($html, 0, $mobileStart);
        $mobileCards = substr($html, $mobileStart);

        $this->assertStringContainsString(route('order.receipt', $regular->id), $desktopTable);
        $this->assertStringNotContainsString(route('order.receipt', $regular->id), $mobileCards);
        $this->assertStringNotContainsString(route('order.receipt', $buyBack->id), $mobileCards);
        $this->assertStringNotContainsString(route('order.receipt', $fuelTrade->id), $mobileCards);

        $this->assertStringContainsString('Order Date:', $mobileCards);
        $this->assertStringContainsString('Date Needed:', $mobileCards);
        $this->assertStringContainsString('2026-12-22', $mobileCards);
        $this->assertStringContainsString('2026-12-25', $mobileCards);
        $this->assertStringContainsString('rec-action-icon', $mobileCards);

        // Declined status query logic remains intact
        $this->actingAs($this->admin)
            ->get(route('dashboard', ['tab' => 'declined']))
            ->assertOk()
            ->assertSee('Declined Logic Still Works Client');
        $this->assertEquals('Declined', $declined->fresh()->clearing_status);
    }

    public function test_new_order_form_places_date_needed_between_order_date_and_po_number_and_propagates_to_m3_and_m4(): void
    {
        $formHtml = $this->actingAs($this->admin)
            ->get(route('order.create'))
            ->assertOk()
            ->getContent();

        $orderDatePos = strpos($formHtml, 'name="date"');
        $dateNeededPos = strpos($formHtml, 'name="date_needed"');
        $poPos = strpos($formHtml, 'name="po_number"');

        $this->assertNotFalse($orderDatePos);
        $this->assertNotFalse($dateNeededPos);
        $this->assertNotFalse($poPos);
        $this->assertGreaterThan($orderDatePos, $dateNeededPos);
        $this->assertLessThan($poPos, $dateNeededPos);
        $this->assertEquals(1, substr_count($formHtml, 'name="date_needed"'));
        $this->assertStringContainsString('Optional. Leave blank if the date is not confirmed yet; you can add it later.', $formHtml);

        // Create FT order -> visible in Dashboard, Module 3 list & detail
        $ftOrder = $this->createOrder([
            'fulfillment_type' => Order::FULFILLMENT_FUEL_TRADE,
            'clearing_status' => 'Approved',
            'date_needed' => '2026-12-28',
        ]);

        $this->actingAs($this->admin)
            ->get(route('stock-orders.sales-orders.index'))
            ->assertOk()
            ->assertSee('Dec 28, 2026');

        $this->actingAs($this->admin)
            ->get(route('stock-orders.sales-orders.show', $ftOrder->id))
            ->assertOk()
            ->assertSee('Dec 28, 2026');

        // Delivery assigned to tanker -> Module 4 Job Orders & Trip Schedule show order.date_needed
        $delOrder = $this->createOrder([
            'clearing_status' => 'Approved',
            'date_needed' => '2026-12-29',
        ]);
        $deliveryDate = now()->addDays(2)->format('Y-m-d');
        $delivery = Delivery::create([
            'order_id' => $delOrder->id,
            'dr_number' => 'DR-MOB-'.rand(100, 999),
            'delivery_date' => $deliveryDate,
            'type' => 'BIG TANKER',
            'qty_out' => 5000,
            'product_type' => 'D',
            'status' => 'PENDING',
        ]);
        $wh = Warehouse::create(['name' => 'Test Depot '.uniqid()]);
        $tank = StorageTank::create([
            'warehouse_id' => $wh->id,
            'name' => 'TANKER MOB 1',
            'category' => 'tanker',
            'max_capacity' => 20000,
            'is_active' => true,
        ]);
        DeliveryAllocation::create([
            'delivery_id' => $delivery->id,
            'storage_tank_id' => $tank->id,
            'quantity' => 5000,
        ]);

        $this->actingAs($this->admin)
            ->get(route('logistics.job-orders.index'))
            ->assertOk()
            ->assertSee('Dec 29, 2026');

        $this->actingAs($this->admin)
            ->getJson(route('logistics.schedule.detail', ['tank' => $tank->id, 'date' => $deliveryDate]))
            ->assertOk()
            ->assertSee('Dec 29, 2026');
    }

    public function test_deliveries_summary_and_fuel_trade_header_are_responsive(): void
    {
        $ftOrder = $this->createOrder([
            'fulfillment_type' => Order::FULFILLMENT_FUEL_TRADE,
            'clearing_status' => 'Approved',
        ]);
        $po = PurchaseOrder::create([
            'po_number' => 'PO-FT-'.rand(1000, 9999),
            'po_type' => 'FUEL_TRADE',
            'supplier_name' => 'Petron',
            'linked_order_id' => $ftOrder->id,
            'qty_ordered' => 10000,
            'request_status' => 'RECEIVED',
            'status' => 'Pending',
        ]);
        $ftOrder->update(['linked_purchase_order_id' => $po->id]);
        PurchaseOrderDelivery::create([
            'purchase_order_id' => $po->id,
            'order_id' => $ftOrder->id,
            'delivery_channel' => 'SUPPLIER_CLIENT_PICKUP',
            'order_type' => 'PICK_UP',
            'atl_type' => 'DITC_ATL',
            'atl_number' => 'ATL-MOB-1',
            'product' => 'Diesel',
            'qty_to_receive' => 10000,
            'receiving_date' => now()->addDay(),
            'status' => 'Pending',
        ]);

        $html = $this->actingAs($this->admin)
            ->get(route('order.deliveries', $ftOrder->id))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('deliv-summary stat-grid', $html);
        $this->assertStringContainsString('d-none d-md-flex align-items-center', $html);
        $this->assertStringContainsString('btn-ft-atl-pdf', $html);
    }

    public function test_clients_accounts_reports_wetstock_and_transfers_mobile_structures(): void
    {
        Client::create(['name' => 'Mobile Compact Client']);
        Admin::create([
            'username' => 'inactive_mob_'.uniqid(),
            'password' => bcrypt('secret'),
            'name' => 'Inactive User',
            'role' => 'sales',
            'is_active' => false,
        ]);

        $this->actingAs($this->admin)
            ->get(route('clients.index'))
            ->assertOk()
            ->assertSee('ims-compact-list', false)
            ->assertSee('ims-icon-btn', false);

        $this->actingAs($this->admin)
            ->get(route('accounts.index'))
            ->assertOk()
            ->assertSee('ims-compact-list', false)
            ->assertSee('bi-play-circle', false);

        $reportsHtml = $this->actingAs($this->admin)
            ->get(route('reports.index', ['filter_mode' => 'year', 'year' => now()->year]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('reportsFilterToggle', $reportsHtml);
        $this->assertStringContainsString('class="collapse reports-filter-collapse"', $reportsHtml);
        $this->assertStringContainsString('stat-grid', $reportsHtml);

        $wh = Warehouse::create(['name' => 'Valenzuela Test '.uniqid()]);
        StorageTank::create([
            'warehouse_id' => $wh->id,
            'name' => 'DEPOT MOB 1',
            'category' => 'depot',
            'product_type' => 'D',
            'max_capacity' => 20000,
            'is_active' => true,
        ]);

        $wsHtml = $this->actingAs($this->admin)
            ->get(route('wetstock.dashboard'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('ws-sum-grid', $wsHtml);
        $this->assertStringContainsString('ws-manage-btn', $wsHtml);
        $this->assertStringContainsString('ws-unit-tabs', $wsHtml);
        $this->assertStringContainsString('wsu-grid', $wsHtml);

        $extractCreateWrap = static function (string $html): string {
            $start = strpos($html, 'wt-create-wrap');
            $end = strpos($html, 'wt-filter-card');
            return ($start !== false && $end !== false && $end > $start)
                ? substr($html, $start, $end - $start)
                : '';
        };

        $txTransfer = $this->actingAs($this->admin)
            ->get(route('wetstock.transfers.index', ['type' => 'transfer']))
            ->assertOk()
            ->getContent();
        $this->assertStringContainsString('wt-top-bar', $txTransfer);
        $transferCreateArea = $extractCreateWrap($txTransfer);
        $this->assertStringContainsString('data-type="transfer"', $transferCreateArea);
        $this->assertStringNotContainsString('data-type="borrow"', $transferCreateArea);
        $this->assertStringNotContainsString('data-type="return"', $transferCreateArea);

        $txBorrow = $this->actingAs($this->admin)
            ->get(route('wetstock.transfers.index', ['type' => 'borrow']))
            ->assertOk()
            ->getContent();
        $borrowCreateArea = $extractCreateWrap($txBorrow);
        $this->assertStringContainsString('data-type="borrow"', $borrowCreateArea);
        $this->assertStringNotContainsString('data-type="transfer"', $borrowCreateArea);
        $this->assertStringNotContainsString('data-type="return"', $borrowCreateArea);

        $txReturn = $this->actingAs($this->admin)
            ->get(route('wetstock.transfers.index', ['type' => 'return']))
            ->assertOk()
            ->getContent();
        $returnCreateArea = $extractCreateWrap($txReturn);
        $this->assertStringContainsString('data-type="return"', $returnCreateArea);
        $this->assertStringNotContainsString('data-type="transfer"', $returnCreateArea);
        $this->assertStringNotContainsString('data-type="borrow"', $returnCreateArea);
    }
}
