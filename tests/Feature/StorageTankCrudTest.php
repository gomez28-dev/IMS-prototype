<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\StockIn;
use App\Models\StorageTank;
use App\Models\TankCompartment;
use App\Models\Warehouse;
use Tests\TestCase;

/**
 * Creating, editing and deleting tanks and tankers.
 */
class StorageTankCrudTest extends TestCase
{
    protected Admin $ops;

    protected Warehouse $warehouse;

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
    }

    protected function depot(array $attributes = []): StorageTank
    {
        return $this->warehouse->tanks()->create(array_merge([
            'name' => 'Depot Tank A',
            'category' => 'depot',
            'product_type' => 'D',
            'max_capacity' => 50000,
            'is_active' => true,
            'is_contaminated' => false,
            'contaminated_liters' => 0,
        ], $attributes))->fresh();
    }

    protected function stockIn(StorageTank $tank, int $quantity): void
    {
        StockIn::create([
            'storage_tank_id' => $tank->id,
            'admin_id' => $this->ops->id,
            'quantity' => $quantity,
            'date' => now()->toDateString(),
        ]);
    }

    // ---- Create ----------------------------------------------------------

    public function test_a_depot_is_created_with_a_product_and_capacity(): void
    {
        $this->actingAs($this->ops)
            ->post(route('wetstock.tanks.store', $this->warehouse->id), [
                'name' => 'Depot Tank B',
                'category' => 'depot',
                'max_capacity' => 60000,
                'product_type' => 'D',
            ])
            ->assertSessionHasNoErrors();

        $tank = StorageTank::where('name', 'Depot Tank B')->firstOrFail();

        $this->assertEquals('depot', $tank->category);
        $this->assertEquals('D', $tank->product_type);
        $this->assertEquals(60000, (int) $tank->max_capacity);
        $this->assertEquals(0, $tank->compartments()->count());
    }

    public function test_a_depot_requires_a_capacity_and_a_product(): void
    {
        $this->actingAs($this->ops)
            ->post(route('wetstock.tanks.store', $this->warehouse->id), [
                'name' => 'Incomplete Depot',
                'category' => 'depot',
            ])
            ->assertSessionHasErrors(['max_capacity', 'product_type']);
    }

    public function test_a_tanker_derives_its_capacity_from_its_compartments(): void
    {
        $this->actingAs($this->ops)
            ->post(route('wetstock.tanks.store', $this->warehouse->id), [
                'name' => 'Tanker 9',
                'category' => 'tanker',
                'compartments' => [
                    ['capacity' => 25000, 'product_type' => 'D'],
                    ['capacity' => 25000, 'product_type' => 'P'],
                ],
            ])
            ->assertSessionHasNoErrors();

        $tank = StorageTank::where('name', 'Tanker 9')->firstOrFail();

        $this->assertEquals('tanker', $tank->category);
        $this->assertEquals(2, $tank->compartments()->count());
        // Total capacity comes from the compartments, not a typed field.
        $this->assertEquals(50000, (int) $tank->max_capacity);
    }

    public function test_a_tanker_requires_at_least_one_compartment(): void
    {
        $this->actingAs($this->ops)
            ->post(route('wetstock.tanks.store', $this->warehouse->id), [
                'name' => 'Tanker With No Compartments',
                'category' => 'tanker',
            ])
            ->assertSessionHasErrors('compartments');
    }

    public function test_roles_that_cannot_edit_module_two_cannot_create_tanks(): void
    {
        $viewer = Admin::create([
            'username' => 'viewer_' . uniqid(),
            'password' => bcrypt('secret'),
            'name' => 'Auditor',
            'role' => 'audit',
        ]);

        $this->actingAs($viewer)
            ->post(route('wetstock.tanks.store', $this->warehouse->id), [
                'name' => 'Nope',
                'category' => 'depot',
                'max_capacity' => 1000,
                'product_type' => 'D',
            ])
            ->assertStatus(403);
    }

    // ---- Update ----------------------------------------------------------

    public function test_updating_a_tanker_replaces_its_compartments(): void
    {
        $tank = $this->warehouse->tanks()->create([
            'name' => 'Tanker 3',
            'category' => 'tanker',
            'max_capacity' => 40000,
            'is_active' => true,
        ]);

        TankCompartment::create([
            'storage_tank_id' => $tank->id,
            'compartment_no' => 1,
            'capacity' => 20000,
            'product_type' => 'D',
            'contaminated_liters' => 0,
            'opening_balance' => 0,
        ]);
        TankCompartment::create([
            'storage_tank_id' => $tank->id,
            'compartment_no' => 2,
            'capacity' => 20000,
            'product_type' => 'D',
            'contaminated_liters' => 0,
            'opening_balance' => 0,
        ]);

        $firstId = $tank->compartments()->orderBy('compartment_no')->first()->id;

        $this->actingAs($this->ops)
            ->post(route('wetstock.tanks.update', $tank->id), [
                'name' => 'Tanker 3',
                'category' => 'tanker',
                'compartments' => [
                    ['id' => $firstId, 'capacity' => 30000, 'product_type' => 'D'],
                ],
            ])
            ->assertSessionHasNoErrors();

        $tank = $tank->fresh();
        $this->assertEquals(1, $tank->compartments()->count());
        $this->assertEquals(30000, (int) $tank->max_capacity);
    }

    public function test_capacity_cannot_be_shrunk_below_the_current_level(): void
    {
        $tank = $this->depot(['max_capacity' => 50000]);
        $this->stockIn($tank, 30000);

        $this->actingAs($this->ops)
            ->post(route('wetstock.tanks.update', $tank->id), [
                'name' => 'Depot Tank A',
                'category' => 'depot',
                'max_capacity' => 10000,
                'product_type' => 'D',
            ])
            ->assertSessionHas('danger');
    }

    public function test_the_product_cannot_change_while_the_tank_holds_stock(): void
    {
        $tank = $this->depot();
        $this->stockIn($tank, 30000);

        $this->actingAs($this->ops)
            ->post(route('wetstock.tanks.update', $tank->id), [
                'name' => 'Depot Tank A',
                'category' => 'depot',
                'max_capacity' => 50000,
                'product_type' => 'P',
            ])
            ->assertSessionHas('danger');

        $this->assertEquals('D', $tank->fresh()->product_type);
    }

    public function test_an_empty_tank_can_change_its_product(): void
    {
        $tank = $this->depot();

        $this->actingAs($this->ops)
            ->post(route('wetstock.tanks.update', $tank->id), [
                'name' => 'Depot Tank A',
                'category' => 'depot',
                'max_capacity' => 50000,
                'product_type' => 'P',
            ])
            ->assertSessionHasNoErrors();

        $this->assertEquals('P', $tank->fresh()->product_type);
    }

    // ---- Delete ----------------------------------------------------------

    public function test_a_tank_that_still_holds_stock_cannot_be_deleted(): void
    {
        $tank = $this->depot();
        $this->stockIn($tank, 10000);

        $this->actingAs($this->ops)
            ->post(route('wetstock.tanks.destroy', $tank->id))
            ->assertSessionHas('warning');

        $this->assertNotNull(StorageTank::find($tank->id));
    }

    public function test_a_tank_with_stock_history_cannot_be_deleted(): void
    {
        $tank = $this->depot();
        // Fully reversed, so it holds nothing, but the history remains.
        $this->stockIn($tank, 10000);
        StockIn::create([
            'storage_tank_id' => $tank->id,
            'admin_id' => $this->ops->id,
            'quantity' => -10000,
            'date' => now()->toDateString(),
        ]);

        $this->actingAs($this->ops)
            ->post(route('wetstock.tanks.destroy', $tank->id))
            ->assertSessionHas('warning');

        $this->assertNotNull(StorageTank::find($tank->id));
    }

    public function test_an_unused_tank_is_deleted_along_with_its_compartments(): void
    {
        $tank = $this->warehouse->tanks()->create([
            'name' => 'Spare Tanker',
            'category' => 'tanker',
            'max_capacity' => 20000,
            'is_active' => true,
        ]);

        TankCompartment::create([
            'storage_tank_id' => $tank->id,
            'compartment_no' => 1,
            'capacity' => 20000,
            'product_type' => 'D',
            'contaminated_liters' => 0,
            'opening_balance' => 0,
        ]);

        $this->actingAs($this->ops)
            ->post(route('wetstock.tanks.destroy', $tank->id))
            ->assertSessionHas('success');

        $this->assertNull(StorageTank::find($tank->id));
        $this->assertEquals(0, TankCompartment::where('storage_tank_id', $tank->id)->count());
    }

    public function test_deactivating_is_offered_as_the_alternative_to_deleting(): void
    {
        $tank = $this->depot();
        $this->stockIn($tank, 10000);

        $this->actingAs($this->ops)
            ->post(route('wetstock.tanks.toggle-active', $tank->id));

        $this->assertFalse($tank->fresh()->is_active);
        $this->assertNotNull(StorageTank::find($tank->id));
    }

    public function test_contamination_cannot_be_toggled_on_a_tanker_with_compartments(): void
    {
        $tank = $this->warehouse->tanks()->create([
            'name' => 'Tanker 7',
            'category' => 'tanker',
            'max_capacity' => 20000,
            'is_active' => true,
            'is_contaminated' => false,
            'contaminated_liters' => 0,
        ]);

        TankCompartment::create([
            'storage_tank_id' => $tank->id,
            'compartment_no' => 1,
            'capacity' => 20000,
            'product_type' => 'D',
            'contaminated_liters' => 0,
            'opening_balance' => 0,
        ]);

        // Contamination is tracked per compartment for a truck, so the
        // tank-level toggle has nothing to act on.
        $this->actingAs($this->ops)
            ->post(route('wetstock.tanks.toggle-contamination', $tank->id));

        $this->assertFalse($tank->fresh()->is_contaminated);
    }
}