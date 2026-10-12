<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Client;
use App\Models\Delivery;
use App\Models\DeliveryAllocation;
use App\Models\Order;
use App\Models\StorageTank;
use App\Models\StockIn;
use App\Models\TankCompartment;
use App\Models\Warehouse;
use Tests\TestCase;

/**
 * Allocating a delivery to a truck that has compartments.
 *
 * This is the path that was silently broken on the server: the allocation
 * screen filtered on effective_available, which TankCompartment did not
 * define, so every truck-compartment allocation found nothing available.
 */
class TankCompartmentAllocationTest extends TestCase
{
    protected Admin $ops;

    protected Warehouse $warehouse;

    protected Client $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ops = Admin::create([
            'username' => 'ops_' . uniqid(),
            'password' => bcrypt('secret'),
            'name' => 'Ops Manager',
            'role' => 'ops_mgr',
        ]);

        $this->warehouse = Warehouse::create([
            'name' => 'Limay Depot',
            'location' => 'Limay',
        ]);

        $this->client = Client::first() ?? Client::create(['name' => 'Apex Logistics Corp']);
    }

    /**
     * @param  array<string, array<int, array{capacity?: int, product?: string, stock?: int}>>  $spec
     */
    protected function tankerWith(array $spec): StorageTank
    {
        $tank = $this->warehouse->tanks()->create([
            'name' => 'Tanker 1',
            'category' => 'tanker',
            'max_capacity' => 100000,
            'is_active' => true,
            'is_contaminated' => false,
            'contaminated_liters' => 0,
        ]);

        $no = 0;
        foreach ($spec as $compartmentSpec) {
            $no++;
            TankCompartment::create([
                'storage_tank_id' => $tank->id,
                'compartment_no' => $no,
                'capacity' => $compartmentSpec['capacity'] ?? 20000,
                'product_type' => $compartmentSpec['product'] ?? 'D',
                'contaminated_liters' => 0,
                'opening_balance' => 0,
            ]);

            if (! empty($compartmentSpec['stock'])) {
                StockIn::create([
                    'storage_tank_id' => $tank->id,
                    'tank_compartment_id' => $tank->compartments()->where('compartment_no', $no)->value('id'),
                    'admin_id' => $this->ops->id,
                    'quantity' => $compartmentSpec['stock'],
                    'date' => now()->toDateString(),
                ]);
            }
        }

        return $tank->fresh();
    }

    protected function delivery(int $qtyOut = 10000, string $productType = 'D'): Delivery
    {
        $order = Order::create([
            'client_id' => $this->client->id,
            'account' => $this->client->name,
            // The site lock matches this against the tank's warehouse name.
            'location' => $this->warehouse->name,
            'so_number' => 'SO-TCA-'.uniqid(),
            'date' => now()->format('Y-m-d'),
            'qty_ordered' => $qtyOut,
            'status' => 'Active',
            'fulfillment_type' => 'DEPOT_DELIVERY',
            'order_category' => 'CLIENT_ORDER',
        ]);

        return Delivery::create([
            'order_id' => $order->id,
            'dr_number' => 'DR-'.uniqid(),
            'delivery_date' => now()->format('Y-m-d'),
            'qty_out' => $qtyOut,
            'status' => 'PENDING',
            'product_type' => $productType,
        ]);
    }

    public function test_a_compartment_exposes_effective_available(): void
    {
        // This is the accessor the allocation screen depends on. Without it
        // effective_available resolves to null and nothing is ever allocatable.
        $tank = $this->tankerWith([
            ['product' => 'D', 'capacity' => 20000, 'stock' => 12000],
        ]);

        $compartment = $tank->compartments()->first();

        $this->assertEquals(12000, $compartment->stock_available);
        $this->assertEquals(12000, $compartment->sellable_available);
        $this->assertEquals(12000, $compartment->effective_available);
        $this->assertEquals(8000, $compartment->remaining_capacity);
    }

    public function test_effective_available_falls_as_allocations_are_taken(): void
    {
        $tank = $this->tankerWith([
            ['product' => 'D', 'capacity' => 20000, 'stock' => 12000],
        ]);
        $compartment = $tank->compartments()->first();

        $delivery = $this->delivery(4000);
        DeliveryAllocation::create([
            'delivery_id' => $delivery->id,
            'storage_tank_id' => $tank->id,
            'tank_compartment_id' => $compartment->id,
            'quantity' => 4000,
        ]);

        $this->assertEquals(8000, $compartment->fresh()->effective_available);
    }

    public function test_allocating_to_a_tanker_targets_the_matching_compartment(): void
    {
        $tank = $this->tankerWith([
            ['product' => 'D', 'capacity' => 20000, 'stock' => 15000],
        ]);
        $delivery = $this->delivery(5000);

        $this->actingAs($this->ops)
            ->post(route('wetstock.deliveries.allocate', $delivery->id), [
                'storage_tank_id' => $tank->id,
                'quantity' => 5000,
            ])
            ->assertSessionHasNoErrors();

        $allocation = DeliveryAllocation::where('delivery_id', $delivery->id)->firstOrFail();

        $this->assertNotNull($allocation->tank_compartment_id);
        $this->assertEquals(5000, (int) $allocation->quantity);
    }

    public function test_a_request_spanning_two_compartments_is_split_first_in_first_out(): void
    {
        $tank = $this->tankerWith([
            ['product' => 'D', 'capacity' => 10000, 'stock' => 10000],
            ['product' => 'D', 'capacity' => 10000, 'stock' => 10000],
        ]);
        $delivery = $this->delivery(15000);

        $this->actingAs($this->ops)
            ->post(route('wetstock.deliveries.allocate', $delivery->id), [
                'storage_tank_id' => $tank->id,
                'quantity' => 15000,
            ])
            ->assertSessionHasNoErrors();

        $allocations = DeliveryAllocation::where('delivery_id', $delivery->id)
            ->orderBy('tank_compartment_id')
            ->get();

        $this->assertCount(2, $allocations);
        // C1 fills completely, the remainder lands in C2.
        $this->assertEquals(10000, (int) $allocations[0]->quantity);
        $this->assertEquals(5000, (int) $allocations[1]->quantity);
    }

    public function test_allocating_more_than_the_compartments_hold_is_refused(): void
    {
        $tank = $this->tankerWith([
            ['product' => 'D', 'capacity' => 10000, 'stock' => 10000],
        ]);
        $delivery = $this->delivery(25000);

        $this->actingAs($this->ops)
            ->post(route('wetstock.deliveries.allocate', $delivery->id), [
                'storage_tank_id' => $tank->id,
                'quantity' => 25000,
            ])
            ->assertSessionHas('danger');

        $this->assertEquals(0, DeliveryAllocation::where('delivery_id', $delivery->id)->count());
    }

    public function test_allocation_is_refused_when_the_compartment_holds_a_different_product(): void
    {
        $tank = $this->tankerWith([
            ['product' => 'P', 'capacity' => 20000, 'stock' => 20000],
        ]);
        $delivery = $this->delivery(5000, 'D');

        $this->actingAs($this->ops)
            ->post(route('wetstock.deliveries.allocate', $delivery->id), [
                'storage_tank_id' => $tank->id,
                'quantity' => 5000,
            ])
            ->assertSessionHas('danger');

        $this->assertEquals(0, DeliveryAllocation::where('delivery_id', $delivery->id)->count());
    }

    public function test_a_fully_contaminated_compartment_is_not_allocatable(): void
    {
        $tank = $this->tankerWith([
            ['product' => 'D', 'capacity' => 10000, 'stock' => 10000],
        ]);
        $tank->compartments()->first()->update(['contaminated_liters' => 10000]);

        $delivery = $this->delivery(5000);

        $this->actingAs($this->ops)
            ->post(route('wetstock.deliveries.allocate', $delivery->id), [
                'storage_tank_id' => $tank->id,
                'quantity' => 5000,
            ])
            ->assertSessionHas('danger');
    }

    public function test_a_depot_tank_still_allocates_at_tank_level(): void
    {
        $tank = $this->warehouse->tanks()->create([
            'name' => 'Depot Tank A',
            'category' => 'depot',
            'product_type' => 'D',
            'max_capacity' => 50000,
            'is_active' => true,
            'is_contaminated' => false,
            'contaminated_liters' => 0,
        ]);

        StockIn::create([
            'storage_tank_id' => $tank->id,
            'admin_id' => $this->ops->id,
            'quantity' => 40000,
            'date' => now()->toDateString(),
        ]);

        $delivery = $this->delivery(5000);

        $this->actingAs($this->ops)
            ->post(route('wetstock.deliveries.allocate', $delivery->id), [
                'storage_tank_id' => $tank->id,
                'quantity' => 5000,
            ])
            ->assertSessionHasNoErrors();

        $allocation = DeliveryAllocation::where('delivery_id', $delivery->id)->firstOrFail();

        // A depot has no compartments, so it allocates straight against the tank.
        $this->assertNull($allocation->tank_compartment_id);
        $this->assertEquals(5000, (int) $allocation->quantity);
    }
}