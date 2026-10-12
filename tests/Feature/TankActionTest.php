<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\StockAdjustment;
use App\Models\StockIn;
use App\Models\StorageTank;
use App\Models\TankCompartment;
use App\Models\Warehouse;
use Tests\TestCase;

/**
 * The dashboard pop-up tank actions: add stock, reduce volume, and flag or
 * clear contamination.
 */
class TankActionTest extends TestCase
{
    protected Admin $ops;

    protected Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();

        // Tank actions are gated on canEditModule2(), which admits the depot
        // operations roles and the VP -- not Purchasing.
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
            'max_capacity' => 100000,
            'is_active' => true,
            'is_contaminated' => false,
            'contaminated_liters' => 0,
        ], $attributes))->fresh();
    }

    protected function tanker(int $compartments = 2): StorageTank
    {
        $tank = $this->warehouse->tanks()->create([
            'name' => 'Tanker 1',
            'category' => 'tanker',
            'max_capacity' => 100000,
            'is_active' => true,
            'is_contaminated' => false,
            'contaminated_liters' => 0,
        ]);

        for ($i = 1; $i <= $compartments; $i++) {
            TankCompartment::create([
                'storage_tank_id' => $tank->id,
                'compartment_no' => $i,
                'capacity' => 20000,
                'product_type' => 'D',
                'contaminated_liters' => 0,
                'opening_balance' => 0,
            ]);
        }

        return $tank->fresh();
    }

    // ---- Add -------------------------------------------------------------

    public function test_adding_stock_records_a_stock_in_row(): void
    {
        $tank = $this->depot();

        $this->actingAs($this->ops)
            ->post(route('wetstock.tank-actions.add', $tank->id), [
                'quantity' => 25000,
                'product_type' => 'D',
            ])
            ->assertRedirect();

        $this->assertEquals(25000, (int) StockIn::where('storage_tank_id', $tank->id)->sum('quantity'));
        $this->assertEquals(25000, $tank->fresh()->stock_available);
    }

    public function test_adding_more_than_the_capacity_is_refused(): void
    {
        $tank = $this->depot(['max_capacity' => 30000]);

        $this->actingAs($this->ops)
            ->post(route('wetstock.tank-actions.add', $tank->id), [
                'quantity' => 40000,
                'product_type' => 'D',
            ])
            ->assertSessionHas('danger');

        $this->assertEquals(0, StockIn::where('storage_tank_id', $tank->id)->count());
    }

    public function test_an_empty_tank_takes_the_product_being_added(): void
    {
        $tank = $this->depot(['product_type' => null]);

        $this->actingAs($this->ops)
            ->post(route('wetstock.tank-actions.add', $tank->id), [
                'quantity' => 10000,
                'product_type' => 'P',
            ])
            ->assertRedirect();

        $this->assertEquals('P', $tank->fresh()->product_type);
    }

    public function test_a_tank_that_holds_stock_keeps_its_product(): void
    {
        $tank = $this->depot(['product_type' => 'D']);
        StockIn::create([
            'storage_tank_id' => $tank->id,
            'admin_id' => $this->ops->id,
            'quantity' => 10000,
            'date' => now()->toDateString(),
        ]);

        $this->actingAs($this->ops)
            ->post(route('wetstock.tank-actions.add', $tank->id), [
                'quantity' => 5000,
                'product_type' => 'P',
            ])
            ->assertRedirect();

        // A tank that already holds fuel keeps its product rather than
        // switching to whatever the form last submitted.
        $this->assertEquals('D', $tank->fresh()->product_type);
        $this->assertEquals(15000, $tank->fresh()->stock_available);
    }

    public function test_stock_cannot_be_added_to_a_fully_contaminated_unit(): void
    {
        $tank = $this->depot(['contaminated_liters' => 10000]);
        StockIn::create([
            'storage_tank_id' => $tank->id,
            'admin_id' => $this->ops->id,
            'quantity' => 10000,
            'date' => now()->toDateString(),
        ]);

        $this->actingAs($this->ops)
            ->post(route('wetstock.tank-actions.add', $tank->id), ['quantity' => 1000, 'product_type' => 'D'])
            ->assertSessionHas('danger');
    }

    public function test_adding_to_a_compartment_credits_that_compartment(): void
    {
        $tank = $this->tanker();
        $compartment = $tank->compartments()->first();

        $this->actingAs($this->ops)
            ->post(route('wetstock.tank-actions.add', $tank->id), [
                'quantity' => 12000,
                'product_type' => 'D',
                'compartment_id' => $compartment->id,
            ])
            ->assertRedirect();

        $this->assertEquals(12000, (int) StockIn::where('tank_compartment_id', $compartment->id)->sum('quantity'));
        $this->assertEquals(12000, $compartment->fresh()->stock_available);
    }

    // ---- Reduce ----------------------------------------------------------

    public function test_reducing_stock_records_an_adjustment_and_lowers_the_level(): void
    {
        $tank = $this->depot();
        StockIn::create([
            'storage_tank_id' => $tank->id,
            'admin_id' => $this->ops->id,
            'quantity' => 30000,
            'date' => now()->toDateString(),
        ]);

        $this->actingAs($this->ops)
            ->post(route('wetstock.tank-actions.reduce', $tank->id), [
                'quantity' => 5000,
                'reason' => 'Sampling / test',
            ])
            ->assertRedirect();

        $this->assertEquals(1, StockAdjustment::where('storage_tank_id', $tank->id)->count());
        $this->assertEquals(25000, $tank->fresh()->stock_available);
    }

    public function test_cannot_reduce_more_than_the_tank_holds(): void
    {
        $tank = $this->depot();

        $this->actingAs($this->ops)
            ->post(route('wetstock.tank-actions.reduce', $tank->id), [
                'quantity' => 1000,
                'reason' => 'Disposal',
            ])
            ->assertSessionHas('danger');

        $this->assertEquals(0, StockAdjustment::count());
    }

    public function test_reducing_requires_a_known_reason(): void
    {
        $tank = $this->depot();
        StockIn::create([
            'storage_tank_id' => $tank->id,
            'admin_id' => $this->ops->id,
            'quantity' => 30000,
            'date' => now()->toDateString(),
        ]);

        $this->actingAs($this->ops)
            ->post(route('wetstock.tank-actions.reduce', $tank->id), [
                'quantity' => 1000,
                'reason' => 'Because I said so',
            ])
            ->assertSessionHasErrors('reason');
    }

    // ---- Contamination ---------------------------------------------------

    public function test_flagging_contamination_blocks_liters_from_sellable_stock(): void
    {
        $tank = $this->depot();
        StockIn::create([
            'storage_tank_id' => $tank->id,
            'admin_id' => $this->ops->id,
            'quantity' => 30000,
            'date' => now()->toDateString(),
        ]);

        $this->actingAs($this->ops)
            ->post(route('wetstock.tank-actions.contamination', $tank->id), [
                'liters' => 8000,
                'remarks' => 'Water found in the sump.',
            ])
            ->assertRedirect();

        $tank = $tank->fresh();
        $this->assertEquals(8000, (int) $tank->contaminated_liters);
        $this->assertEquals(22000, $tank->sellable_available);
        $this->assertTrue($tank->hasContamination());
    }

    public function test_flagging_contamination_again_clears_the_flag(): void
    {
        $tank = $this->depot();
        StockIn::create([
            'storage_tank_id' => $tank->id,
            'admin_id' => $this->ops->id,
            'quantity' => 30000,
            'date' => now()->toDateString(),
        ]);

        $url = route('wetstock.tank-actions.contamination', $tank->id);

        $this->actingAs($this->ops)->post($url, ['liters' => 8000]);
        $this->assertEquals(8000, (int) $tank->fresh()->contaminated_liters);

        // A second call toggles the flag back off.
        $this->actingAs($this->ops)->post($url, []);
        $tank = $tank->fresh();
        $this->assertEquals(0, (int) $tank->contaminated_liters);
        $this->assertFalse($tank->hasContamination());
    }

    public function test_an_empty_unit_cannot_be_flagged_contaminated(): void
    {
        $tank = $this->depot();

        $this->actingAs($this->ops)
            ->post(route('wetstock.tank-actions.contamination', $tank->id), [])
            ->assertSessionHas('danger');
    }

    public function test_contaminated_liters_are_capped_at_the_remaining_level(): void
    {
        $tank = $this->depot();
        StockIn::create([
            'storage_tank_id' => $tank->id,
            'admin_id' => $this->ops->id,
            'quantity' => 10000,
            'date' => now()->toDateString(),
        ]);

        // Flag everything, then reduce the stock below the flagged amount.
        $this->actingAs($this->ops)
            ->post(route('wetstock.tank-actions.contamination', $tank->id), ['liters' => 10000]);

        $this->actingAs($this->ops)
            ->post(route('wetstock.tank-actions.reduce', $tank->id), [
                'quantity' => 8000,
                'reason' => 'Disposal',
            ]);

        // The flag can never exceed what is physically left.
        $this->assertEquals(2000, (int) $tank->fresh()->contaminated_liters);
    }

    public function test_a_contaminated_compartment_blocks_further_additions(): void
    {
        $tank = $this->tanker();
        $compartment = $tank->compartments()->first();
        $compartment->update(['contaminated_liters' => 20000]);

        $this->actingAs($this->ops)
            ->post(route('wetstock.tank-actions.add', $tank->id), [
                'quantity' => 1000,
                'product_type' => 'D',
                'compartment_id' => $compartment->id,
            ])
            ->assertSessionHas('danger');
    }

    public function test_truck_contamination_flags_roll_up_to_the_tank(): void
    {
        $tank = $this->tanker();
        $compartment = $tank->compartments()->first();

        $this->actingAs($this->ops)
            ->post(route('wetstock.tank-actions.add', $tank->id), [
                'quantity' => 12000,
                'product_type' => 'D',
                'compartment_id' => $compartment->id,
            ])
            ->assertRedirect();

        // Quarantining the compartment marks the truck as contaminated too.
        $this->actingAs($this->ops)
            ->post(route('wetstock.tank-actions.contamination', $tank->id), [
                'compartment_id' => $compartment->id,
                'remarks' => 'Sample failed.',
            ])
            ->assertRedirect();

        $this->assertEquals(12000, (int) $compartment->fresh()->contaminated_liters);
        $this->assertEquals(12000, (int) $tank->fresh()->contaminated_liters);
        $this->assertTrue($tank->fresh()->is_contaminated);
    }

    // ---- Access ----------------------------------------------------------

    public function test_roles_that_cannot_edit_module_two_are_locked_out(): void
    {
        $viewer = Admin::create([
            'username' => 'viewer_' . uniqid(),
            'password' => bcrypt('secret'),
            'name' => 'Auditor',
            'role' => 'audit',
        ]);

        $tank = $this->depot();
        $url = route('wetstock.tank-actions.add', $tank->id);

        $this->actingAs($viewer)->post($url, ['quantity' => 1000, 'product_type' => 'D'])->assertStatus(403);
        $this->actingAs($viewer)->post(route('wetstock.tank-actions.reduce', $tank->id), [
            'quantity' => 1000,
            'reason' => 'Disposal',
        ])->assertStatus(403);
        $this->actingAs($viewer)
            ->post(route('wetstock.tank-actions.contamination', $tank->id), [])
            ->assertStatus(403);
    }
}