<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Client;
use App\Models\Order;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderDelivery;
use App\Models\PurchaseOrderItem;
use App\Models\StockIn;
use App\Models\StorageTank;
use App\Models\Warehouse;
use Tests\TestCase;

class TankerAutoStockInAndDocsTest extends TestCase
{
    protected Admin $purchasingUser;
    protected Warehouse $warehouse;
    protected StorageTank $mobileTanker;
    protected Client $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->purchasingUser = Admin::create([
            'username' => 'purchasing_' . uniqid(),
            'password' => bcrypt('secret'),
            'name' => 'Purchasing Officer',
            'role' => 'purchasing',
        ]);

        $this->warehouse = Warehouse::first() ?? Warehouse::create(['name' => 'Valenzuela Depot']);

        $this->mobileTanker = StorageTank::create([
            'warehouse_id' => $this->warehouse->id,
            'name' => 'Tanker Truck #4 (NBC-1234)',
            'category' => 'tanker',
            'max_capacity' => 20000,
            'is_active' => true,
        ]);

        $this->client = Client::first() ?? Client::create(['name' => 'Swift Logistics']);
    }

    public function test_completing_doyen_pickup_auto_creates_stock_in_for_mobile_tanker(): void
    {
        $so = Order::create([
            'account' => 'Swift Logistics',
            'ordered_by' => 'Swift Logistics',
            'location' => 'Valenzuela',
            'so_number' => 'SO-TANKER-' . rand(1000, 9999),
            'date' => now(),
            'qty_ordered' => 10000,
            'price' => 52.00,
            'product' => 'Diesel',
            'order_status' => 'APPROVED',
            'clearing_status' => 'Approved',
            'fulfillment_type' => 'FUEL_TRADE',
        ]);

        $po = PurchaseOrder::create([
            'po_number' => 'PO-TEST-' . rand(1000, 9999),
            'po_type' => 'FUEL_TRADE',
            'supplier_name' => 'Petron Bataan',
            'linked_order_id' => $so->id,
            'qty_ordered' => 10000,
            'request_status' => 'FOR_DELIVERY',
            'status' => 'Pending',
        ]);

        $delivery = PurchaseOrderDelivery::create([
            'purchase_order_id' => $po->id,
            'storage_tank_id' => $this->mobileTanker->id,
            'delivery_channel' => 'SUPPLIER_DOYEN_PICKUP',
            'order_type' => 'PICK_UP',
            'atl_type' => 'DITC_ATL',
            'atl_number' => 'ATL-TEST-99',
            'product' => 'Diesel',
            'qty_to_receive' => 10000,
            'status' => 'Active',
        ]);

        $initialTankAvailable = $this->mobileTanker->fresh()->stock_available;

        // Complete lift
        $response = $this->actingAs($this->purchasingUser)
            ->post(route('stock-orders.complete-fuel-trade', $delivery->id), [
                'supplier_dr_number' => 'DR-PETRON-5544',
                'supplier_so_number' => 'SO-PETRON-1122',
                'scanned_doc_url' => 'https://drive.google.com/file/d/test-doc-id',
            ]);

        $response->assertSessionHas('success');

        // Verify StockIn was auto-created
        $this->assertDatabaseHas('stock_ins', [
            'storage_tank_id' => $this->mobileTanker->id,
            'quantity' => 10000,
            'purchase_order_delivery_id' => $delivery->id,
        ]);

        // Verify mobile tanker physical inventory increased
        $this->assertEquals($initialTankAvailable + 10000, $this->mobileTanker->fresh()->stock_available);

        // Verify delivery and orders marked fulfilled
        $this->assertEquals('Completed', $delivery->fresh()->status);
        $this->assertEquals('DR-PETRON-5544', $delivery->fresh()->supplier_dr_number);
        $this->assertEquals('https://drive.google.com/file/d/test-doc-id', $delivery->fresh()->scanned_doc_url);
        $this->assertEquals('Fulfilled', $so->fresh()->status);
    }

    public function test_overfill_capacity_guard_blocks_auto_stock_in(): void
    {
        $so = Order::create([
            'account' => 'Swift Logistics',
            'ordered_by' => 'Swift Logistics',
            'location' => 'Valenzuela',
            'so_number' => 'SO-OVERFILL-' . rand(1000, 9999),
            'date' => now(),
            'qty_ordered' => 25000, // Exceeds 20,000L capacity
            'price' => 52.00,
            'product' => 'Diesel',
            'order_status' => 'APPROVED',
            'clearing_status' => 'Approved',
            'fulfillment_type' => 'FUEL_TRADE',
        ]);

        $po = PurchaseOrder::create([
            'po_number' => 'PO-OVERFILL-' . rand(1000, 9999),
            'po_type' => 'FUEL_TRADE',
            'supplier_name' => 'Shell Tabangao',
            'linked_order_id' => $so->id,
            'qty_ordered' => 25000,
            'request_status' => 'FOR_DELIVERY',
            'status' => 'Pending',
        ]);

        $delivery = PurchaseOrderDelivery::create([
            'purchase_order_id' => $po->id,
            'storage_tank_id' => $this->mobileTanker->id,
            'delivery_channel' => 'SUPPLIER_DOYEN_PICKUP',
            'order_type' => 'PICK_UP',
            'atl_type' => 'DITC_ATL',
            'product' => 'Diesel',
            'qty_to_receive' => 25000,
            'status' => 'Active',
        ]);

        $response = $this->actingAs($this->purchasingUser)
            ->post(route('stock-orders.complete-fuel-trade', $delivery->id));

        $response->assertSessionHas('danger');

        // Stock in must NOT be created
        $this->assertDatabaseMissing('stock_ins', [
            'purchase_order_delivery_id' => $delivery->id,
        ]);

        // Delivery must remain Active
        $this->assertEquals('Active', $delivery->fresh()->status);
    }

    public function test_client_pickup_fulfills_sales_order_without_touching_wet_stocks(): void
    {
        $so = Order::create([
            'account' => 'Direct Hauler Client',
            'ordered_by' => 'Direct Hauler Client',
            'location' => 'Bataan',
            'so_number' => 'SO-CLIENT-' . rand(1000, 9999),
            'date' => now(),
            'qty_ordered' => 15000,
            'price' => 51.50,
            'product' => 'Diesel',
            'order_status' => 'APPROVED',
            'clearing_status' => 'Approved',
            'fulfillment_type' => 'FUEL_TRADE',
        ]);

        $po = PurchaseOrder::create([
            'po_number' => 'PO-CLIENT-LIFT-' . rand(1000, 9999),
            'po_type' => 'FUEL_TRADE',
            'supplier_name' => 'Petron Bataan',
            'linked_order_id' => $so->id,
            'qty_ordered' => 15000,
            'request_status' => 'FOR_DELIVERY',
            'status' => 'Pending',
        ]);

        $delivery = PurchaseOrderDelivery::create([
            'purchase_order_id' => $po->id,
            'delivery_channel' => 'SUPPLIER_CLIENT_PICKUP',
            'order_type' => 'PICK_UP',
            'atl_type' => 'CLIENT_ATL',
            'product' => 'Diesel',
            'qty_to_receive' => 15000,
            'status' => 'Active',
        ]);

        $response = $this->actingAs($this->purchasingUser)
            ->post(route('stock-orders.complete-fuel-trade', $delivery->id), [
                'supplier_dr_number' => 'DR-CLIENT-8899',
            ]);

        $response->assertSessionHas('success');

        // Absolutely no stock in created
        $this->assertDatabaseMissing('stock_ins', [
            'purchase_order_delivery_id' => $delivery->id,
        ]);

        // Sales order marked fulfilled
        $this->assertEquals('Fulfilled', $so->fresh()->status);
        $this->assertEquals('Completed', $delivery->fresh()->status);
    }

    public function test_can_update_delivery_documentation_serials_and_google_drive_link(): void
    {
        $po = PurchaseOrder::create([
            'po_number' => 'PO-DOCS-' . rand(1000, 9999),
            'po_type' => 'STANDARD_REPLENISHMENT',
            'supplier_name' => 'Chevron Refinery',
            'qty_ordered' => 10000,
            'request_status' => 'FOR_DELIVERY',
            'status' => 'Pending',
        ]);

        $delivery = PurchaseOrderDelivery::create([
            'purchase_order_id' => $po->id,
            'product' => 'Diesel',
            'qty_to_receive' => 10000,
            'status' => 'Active',
        ]);

        $response = $this->actingAs($this->purchasingUser)
            ->post(route('stock-orders.update-docs', $delivery->id), [
                'supplier_so_number' => 'SO-CHEVRON-991',
                'supplier_dr_number' => 'DR-CHEVRON-442',
                'scanned_doc_url' => 'https://drive.google.com/drive/folders/1abcXYZ',
            ]);

        $response->assertSessionHas('success');

        $this->assertDatabaseHas('purchase_order_deliveries', [
            'id' => $delivery->id,
            'supplier_so_number' => 'SO-CHEVRON-991',
            'supplier_dr_number' => 'DR-CHEVRON-442',
            'scanned_doc_url' => 'https://drive.google.com/drive/folders/1abcXYZ',
        ]);

        $this->assertTrue($delivery->fresh()->hasScannedDocs());
    }

    public function test_issuing_atl_with_tanker_pickup_saves_mobile_tanker_and_serials(): void
    {
        $so = Order::create([
            'account' => 'Fast Trucking Inc',
            'ordered_by' => 'Fast Trucking Inc',
            'location' => 'Valenzuela',
            'so_number' => 'SO-FAST-' . rand(1000, 9999),
            'date' => now(),
            'qty_ordered' => 10000,
            'price' => 53.00,
            'product' => 'Diesel',
            'order_status' => 'APPROVED',
            'clearing_status' => 'Approved',
            'fulfillment_type' => 'FUEL_TRADE',
        ]);

        $bulkPo = PurchaseOrder::create([
            'po_number' => 'PO-PETRON-BULK-' . rand(1000, 9999),
            'supplier_name' => 'Petron Bataan',
            'po_type' => 'STANDARD_REPLENISHMENT',
            'request_status' => 'CONFIRMED',
            'status' => 'Active',
            'qty_ordered' => 30000,
        ]);

        $poItem = PurchaseOrderItem::create([
            'purchase_order_id' => $bulkPo->id,
            'product' => 'Diesel',
            'quantity_ordered' => 30000,
        ]);

        $payload = [
            'product' => 'Diesel',
            'delivery_channel' => 'SUPPLIER_DOYEN_PICKUP',
            'storage_tank_id' => $this->mobileTanker->id,
            'supplier_so_number' => 'PETRON-SO-9922',
            'supplier_dr_number' => 'PETRON-DR-1144',
            'scanned_doc_url' => 'https://drive.google.com/file/d/test-link',
            'receiving_date' => now()->format('Y-m-d'),
            'driver_name' => 'Rodolfo Reyes',
            'plate_number' => 'NBC-1234',
            'atl_type' => 'DITC_ATL',
            'atl_number' => 'ATL-2026-0901',
            'drawdowns' => [
                ['purchase_order_id' => $bulkPo->id, 'quantity' => 10000],
            ],
        ];

        $response = $this->actingAs($this->purchasingUser)
            ->post(route('stock-orders.store-fuel-trade-po', $so->id), $payload);

        // Issuing an ATL now lands on that order's ATL details page.
        $response->assertRedirect(route('stock-orders.sales-orders.show', $so->id));
        $response->assertSessionHas('success');

        $this->assertDatabaseHas('purchase_order_deliveries', [
            'delivery_channel' => 'SUPPLIER_DOYEN_PICKUP',
            'storage_tank_id' => $this->mobileTanker->id,
            'supplier_so_number' => 'PETRON-SO-9922',
            'supplier_dr_number' => 'PETRON-DR-1144',
            'scanned_doc_url' => 'https://drive.google.com/file/d/test-link',
            'atl_number' => 'ATL-2026-0901',
            'qty_to_receive' => 10000,
        ]);
    }

    public function test_stock_orders_index_view_renders_cleanly_with_mobile_tankers(): void
    {
        $so = Order::create([
            'account' => 'View Test Client',
            'ordered_by' => 'View Test Client',
            'location' => 'Valenzuela',
            'so_number' => 'SO-VIEW-' . rand(1000, 9999),
            'date' => now(),
            'qty_ordered' => 8000,
            'price' => 52.00,
            'product' => 'Diesel',
            'order_status' => 'APPROVED',
            'clearing_status' => 'Approved',
            'fulfillment_type' => 'FUEL_TRADE',
        ]);

        $po = PurchaseOrder::create([
            'po_number' => 'PO-VIEW-' . rand(1000, 9999),
            'po_type' => 'FUEL_TRADE',
            'supplier_name' => 'Petron Bataan',
            'linked_order_id' => $so->id,
            'qty_ordered' => 8000,
            'request_status' => 'FOR_DELIVERY',
            'status' => 'Pending',
        ]);

        PurchaseOrderDelivery::create([
            'purchase_order_id' => $po->id,
            'delivery_channel' => 'SUPPLIER_DOYEN_PICKUP',
            'order_type' => 'PICK_UP',
            'atl_type' => 'DITC_ATL',
            'product' => 'Diesel',
            'qty_to_receive' => 8000,
            'status' => 'Active',
        ]);

        $response = $this->actingAs($this->purchasingUser)
            ->get(route('stock-orders.purchase-orders.index'));

        $response->assertStatus(200);
        $response->assertSee('New Supplier PO');
        $response->assertSee('Tanker Truck #4');
    }
}
