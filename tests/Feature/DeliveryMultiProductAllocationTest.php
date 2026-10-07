<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Client;
use App\Models\Delivery;
use App\Models\DeliveryAllocation;
use App\Models\DeliveryItem;
use App\Models\Order;
use App\Models\StockIn;
use App\Models\StorageTank;
use App\Models\Warehouse;
use Tests\TestCase;

class DeliveryMultiProductAllocationTest extends TestCase
{
    protected Admin $opsUser;
    protected Warehouse $warehouse;
    protected Warehouse $otherWarehouse;
    protected StorageTank $unleadedTank;
    protected StorageTank $dieselTank;
    protected Client $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->opsUser = Admin::create([
            'username' => 'ops_' . uniqid(),
            'password' => bcrypt('secret'),
            'name' => 'Ops Officer',
            'role' => 'ops_admin',
        ]);

        $this->warehouse = Warehouse::first() ?? Warehouse::create(['name' => 'Valenzuela Depot']);
        $this->otherWarehouse = Warehouse::create(['name' => 'Cavite Depot']);

        $this->unleadedTank = StorageTank::create([
            'warehouse_id' => $this->warehouse->id,
            'name' => 'Tank U-1',
            'category' => 'storage',
            'fuel_type' => 'Unleaded',
            'max_capacity' => 20000,
            'is_active' => true,
        ]);

        $this->dieselTank = StorageTank::create([
            'warehouse_id' => $this->warehouse->id,
            'name' => 'Tank D-1',
            'category' => 'storage',
            'fuel_type' => 'Diesel',
            'max_capacity' => 20000,
            'is_active' => true,
        ]);

        StockIn::create([
            'storage_tank_id' => $this->unleadedTank->id,
            'admin_id' => $this->opsUser->id,
            'quantity' => 10000,
            'date' => now(),
        ]);

        StockIn::create([
            'storage_tank_id' => $this->dieselTank->id,
            'admin_id' => $this->opsUser->id,
            'quantity' => 10000,
            'date' => now(),
        ]);

        $this->client = Client::first() ?? Client::create(['name' => 'Swift Logistics']);
    }

    /**
     * A single DR carrying two products in two compartments.
     */
    protected function multiProductDelivery(int $unleadedQty = 3000, int $dieselQty = 2000): Delivery
    {
        $order = Order::create([
            'account' => 'Swift Logistics',
            'ordered_by' => 'Swift Logistics',
            'location' => $this->warehouse->name,
            'so_number' => 'SO-MP-' . rand(1000, 9999),
            'date' => now(),
            'qty_ordered' => $unleadedQty + $dieselQty,
            'price' => 52.00,
            'product' => 'Multi',
            'order_status' => 'APPROVED',
            'clearing_status' => 'Approved',
        ]);

        $delivery = Delivery::create([
            'order_id' => $order->id,
            'storage_tank_id' => $this->unleadedTank->id,
            'dr_number' => 'DR-MP-' . rand(1000, 9999),
            'delivery_date' => now(),
            'qty_out' => $unleadedQty + $dieselQty,
            'status' => 'PENDING',
        ]);

        $delivery->items()->create([
            'product_type' => 'U',
            'qty_out' => $unleadedQty,
            'compartment_no' => 1,
        ]);

        $delivery->items()->create([
            'product_type' => 'D',
            'qty_out' => $dieselQty,
            'compartment_no' => 2,
        ]);

        return $delivery->fresh('items');
    }

    public function test_a_delivery_can_carry_multiple_product_compartments(): void
    {
        $delivery = $this->multiProductDelivery(3000, 2000);

        $this->assertCount(2, $delivery->items);

        $unleaded = $delivery->items->firstWhere('product_type', 'U');
        $diesel = $delivery->items->firstWhere('product_type', 'D');

        $this->assertSame('Unleaded', $unleaded->product_name);
        $this->assertSame('Diesel', $diesel->product_name);
        $this->assertSame(3000, $unleaded->qty_out);
        $this->assertSame(2000, $diesel->qty_out);
        $this->assertSame(1, $unleaded->compartment_no);
        $this->assertSame(2, $diesel->compartment_no);
    }

    public function test_new_compartment_has_its_full_quantity_remaining_to_allocate(): void
    {
        $delivery = $this->multiProductDelivery(3000, 2000);

        $unleaded = $delivery->items->firstWhere('product_type', 'U');

        $this->assertSame(0, $unleaded->allocated_quantity);
        $this->assertSame(3000, $unleaded->remaining_to_allocate);
    }

    public function test_allocating_one_compartment_leaves_the_other_untouched(): void
    {
        $delivery = $this->multiProductDelivery(3000, 2000);
        $unleadedItem = $delivery->items->firstWhere('product_type', 'U');

        $response = $this->actingAs($this->opsUser)->post(route('wetstock.deliveries.allocate', $delivery->id), [
            'delivery_item_id' => $unleadedItem->id,
            'storage_tank_id' => $this->unleadedTank->id,
            'quantity' => 1000,
        ]);

        $response->assertSessionHas('success');
        $this->assertDatabaseHas('delivery_allocations', [
            'delivery_id' => $delivery->id,
            'delivery_item_id' => $unleadedItem->id,
            'storage_tank_id' => $this->unleadedTank->id,
            'quantity' => 1000,
        ]);

        $unleadedItem->refresh()->load('allocations');
        $dieselItem = $delivery->items->firstWhere('product_type', 'D');

        $this->assertSame(1000, $unleadedItem->allocated_quantity);
        $this->assertSame(2000, $unleadedItem->remaining_to_allocate);

        $this->assertSame(0, $dieselItem->allocated_quantity);
        $this->assertSame(2000, $dieselItem->remaining_to_allocate);
    }

    public function test_allocation_is_tied_to_the_compartment_not_just_the_delivery(): void
    {
        $delivery = $this->multiProductDelivery(3000, 2000);
        $dieselItem = $delivery->items->firstWhere('product_type', 'D');

        $this->actingAs($this->opsUser)->post(route('wetstock.deliveries.allocate', $delivery->id), [
            'delivery_item_id' => $dieselItem->id,
            'storage_tank_id' => $this->dieselTank->id,
            'quantity' => 2000,
        ])->assertSessionHas('success');

        $allocation = DeliveryAllocation::where('delivery_id', $delivery->id)->firstOrFail();

        $this->assertSame($dieselItem->id, $allocation->delivery_item_id);
        $this->assertNotNull($allocation->item);
        $this->assertSame('Diesel', $allocation->item->product_name);

        $dieselItem->refresh()->load('allocations');
        $this->assertSame(2000, $dieselItem->allocated_quantity);
        $this->assertSame(0, $dieselItem->remaining_to_allocate);
    }

    public function test_allocating_an_ambiguous_multi_compartment_delivery_is_rejected(): void
    {
        $delivery = $this->multiProductDelivery(3000, 2000);

        $response = $this->actingAs($this->opsUser)->post(route('wetstock.deliveries.allocate', $delivery->id), [
            'storage_tank_id' => $this->unleadedTank->id,
            'quantity' => 1000,
        ]);

        $response->assertSessionHas('danger');
        $this->assertStringContainsString(
            'select which product/compartment',
            session('danger')
        );
        $this->assertDatabaseMissing('delivery_allocations', ['delivery_id' => $delivery->id]);
    }

    public function test_cannot_allocate_more_than_the_compartment_remaining(): void
    {
        $delivery = $this->multiProductDelivery(3000, 2000);
        $dieselItem = $delivery->items->firstWhere('product_type', 'D');

        $response = $this->actingAs($this->opsUser)->post(route('wetstock.deliveries.allocate', $delivery->id), [
            'delivery_item_id' => $dieselItem->id,
            'storage_tank_id' => $this->dieselTank->id,
            'quantity' => 2500,
        ]);

        $response->assertSessionHas('danger');
        $this->assertStringContainsString('exceeds the remaining unallocated', session('danger'));
        $this->assertDatabaseMissing('delivery_allocations', ['delivery_id' => $delivery->id]);
    }

    public function test_a_compartment_cannot_be_allocated_to_the_same_tank_twice(): void
    {
        $delivery = $this->multiProductDelivery(3000, 2000);
        $unleadedItem = $delivery->items->firstWhere('product_type', 'U');

        $this->actingAs($this->opsUser)->post(route('wetstock.deliveries.allocate', $delivery->id), [
            'delivery_item_id' => $unleadedItem->id,
            'storage_tank_id' => $this->unleadedTank->id,
            'quantity' => 1000,
        ])->assertSessionHas('success');

        $response = $this->actingAs($this->opsUser)->post(route('wetstock.deliveries.allocate', $delivery->id), [
            'delivery_item_id' => $unleadedItem->id,
            'storage_tank_id' => $this->unleadedTank->id,
            'quantity' => 500,
        ]);

        $response->assertSessionHas('danger');
        $this->assertStringContainsString('already has an allocation on tank', session('danger'));
        $this->assertSame(1, DeliveryAllocation::where('delivery_id', $delivery->id)->count());
    }

    public function test_allocating_a_compartment_beyond_the_tank_stock_is_rejected(): void
    {
        $delivery = $this->multiProductDelivery(3000, 2000);
        $unleadedItem = $delivery->items->firstWhere('product_type', 'U');

        // Tank holds 10,000L but the compartment still has 3,000L: tank stock is the binding limit here.
        $response = $this->actingAs($this->opsUser)->post(route('wetstock.deliveries.allocate', $delivery->id), [
            'delivery_item_id' => $unleadedItem->id,
            'storage_tank_id' => $this->unleadedTank->id,
            'quantity' => 99999,
        ]);

        $response->assertSessionHas('danger');
        $this->assertDatabaseMissing('delivery_allocations', ['delivery_id' => $delivery->id]);
    }

    public function test_cannot_allocate_to_a_tank_at_another_site(): void
    {
        $crossSiteTank = StorageTank::create([
            'warehouse_id' => $this->otherWarehouse->id,
            'name' => 'Cavite Tank 1',
            'category' => 'storage',
            'fuel_type' => 'Unleaded',
            'max_capacity' => 20000,
            'is_active' => true,
        ]);

        StockIn::create([
            'storage_tank_id' => $crossSiteTank->id,
            'admin_id' => $this->opsUser->id,
            'quantity' => 10000,
            'date' => now(),
        ]);

        $delivery = $this->multiProductDelivery(3000, 2000);
        $unleadedItem = $delivery->items->firstWhere('product_type', 'U');

        $response = $this->actingAs($this->opsUser)->post(route('wetstock.deliveries.allocate', $delivery->id), [
            'delivery_item_id' => $unleadedItem->id,
            'storage_tank_id' => $crossSiteTank->id,
            'quantity' => 1000,
        ]);

        $response->assertSessionHas('danger');
        $this->assertStringContainsString('Cross-site stock must be moved via a Borrow transfer', session('danger'));
        $this->assertDatabaseMissing('delivery_allocations', ['delivery_id' => $delivery->id]);
    }

    public function test_legacy_delivery_without_compartments_gets_one_built_from_its_header(): void
    {
        $order = Order::create([
            'account' => 'Swift Logistics',
            'ordered_by' => 'Swift Logistics',
            'location' => $this->warehouse->name,
            'so_number' => 'SO-LEGACY-' . rand(1000, 9999),
            'date' => now(),
            'qty_ordered' => 5000,
            'price' => 52.00,
            'product' => 'Unleaded',
            'order_status' => 'APPROVED',
            'clearing_status' => 'Approved',
        ]);

        $delivery = Delivery::create([
            'order_id' => $order->id,
            'storage_tank_id' => $this->unleadedTank->id,
            'dr_number' => 'DR-LEGACY-' . rand(1000, 9999),
            'delivery_date' => now(),
            'product_type' => 'U',
            'qty_out' => 5000,
            'status' => 'PENDING',
        ]);

        $this->assertCount(0, $delivery->fresh('items')->items);

        $this->actingAs($this->opsUser)->post(route('wetstock.deliveries.allocate', $delivery->id), [
            'storage_tank_id' => $this->unleadedTank->id,
            'quantity' => 2000,
        ])->assertSessionHas('success');

        $item = DeliveryItem::where('delivery_id', $delivery->id)->firstOrFail();
        $this->assertSame('U', $item->product_type);
        $this->assertSame(5000, $item->qty_out);
        $this->assertSame(1, $item->compartment_no);

        $allocation = DeliveryAllocation::where('delivery_id', $delivery->id)->firstOrFail();
        $this->assertSame($item->id, $allocation->delivery_item_id);
    }

    public function test_delivery_summarises_its_compartments(): void
    {
        $delivery = $this->multiProductDelivery(3000, 2000);

        $summary = $delivery->items_summary;

        $this->assertSame('U 3,000 L + D 2,000 L', $summary);
    }
}