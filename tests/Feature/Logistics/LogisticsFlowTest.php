<?php

namespace Tests\Feature\Logistics;

use App\Models\Admin;
use App\Models\Delivery;
use App\Models\DeliveryAllocation;
use App\Models\LogisticsTrip;
use App\Models\LogisticsTripStop;
use App\Models\ModificationRequest;
use App\Models\Order;
use App\Models\Personnel;
use App\Models\StorageTank;
use App\Models\TankCompartment;
use App\Models\Warehouse;
use Tests\TestCase;

/**
 * End-to-end Module 4 behaviour through the HTTP routes: planning a trip,
 * adding a stop, turning a Wet Stock delivery into a drop-off, the date-change
 * approval request, and who may fulfil the delivery.
 */
class LogisticsFlowTest extends TestCase
{
    protected Admin $admin;

    protected Admin $opsLog;

    protected StorageTank $tanker;

    protected TankCompartment $compartment;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = Admin::create([
            'username' => 'flow_admin_' . uniqid(),
            'password' => bcrypt('secret'),
            'name' => 'Flow Admin',
            'role' => 'admin',
        ]);

        $this->opsLog = Admin::create([
            'username' => 'flow_ops_' . uniqid(),
            'password' => bcrypt('secret'),
            'name' => 'Flow Ops',
            'role' => 'ops_log',
        ]);

        $warehouse = Warehouse::create(['name' => 'Flow Depot ' . rand(1000, 9999)]);

        $this->tanker = StorageTank::create([
            'warehouse_id' => $warehouse->id,
            'name' => 'Tanker Flow',
            'category' => 'tanker',
            'max_capacity' => 20000,
            'is_active' => true,
        ]);

        $this->compartment = TankCompartment::create([
            'storage_tank_id' => $this->tanker->id,
            'compartment_no' => 1,
            'capacity' => 10000,
        ]);
    }

    public function test_a_trip_and_its_stops_are_created_through_the_board(): void
    {
        $this->actingAs($this->admin);

        $this->post(route('logistics.schedule.store'), [
            'storage_tank_id' => $this->tanker->id,
            'scheduled_date' => now()->toDateString(),
            'trip_type' => 'delivery',
        ])->assertRedirect();

        $trip = LogisticsTrip::firstOrFail();

        $this->assertStringStartsWith('TRP-' . now()->format('ymd') . '-', $trip->trip_no);
        $this->assertSame('scheduled', $trip->status);

        $response = $this->post(route('logistics.schedule.stops.store'), [
            'trip_id' => $trip->id,
            'client_name' => 'ACME Fuels',
            'address' => 'Valenzuela',
            'lines' => [
                ['compartment_no' => 1, 'product_type' => 'D', 'quantity' => 2000],
            ],
        ]);

        $response->assertRedirect();
        $response->assertSessionHasNoErrors();

        $stop = LogisticsTripStop::where('logistics_trip_id', $trip->id)->firstOrFail();

        $this->assertSame('ACME Fuels', $stop->client_name);
        $this->assertSame(2000, $stop->total_litres);
        $this->assertSame('D', $this->compartment->fresh()->product_type);
    }

    public function test_a_stop_over_capacity_is_refused(): void
    {
        $this->actingAs($this->admin);

        $this->post(route('logistics.schedule.store'), [
            'storage_tank_id' => $this->tanker->id,
            'scheduled_date' => now()->toDateString(),
            'trip_type' => 'delivery',
        ]);

        $trip = LogisticsTrip::firstOrFail();

        $response = $this->post(route('logistics.schedule.stops.store'), [
            'trip_id' => $trip->id,
            'client_name' => 'Too Much',
            'lines' => [
                ['compartment_no' => 1, 'quantity' => 11000],
            ],
        ]);

        $response->assertSessionHasErrors('lines');
        $this->assertSame(0, LogisticsTripStop::count());
    }

    public function test_job_order_scheduling_creates_the_trip_and_requests_the_new_date(): void
    {
        [$delivery] = $this->deliveryOn($this->tanker, 2000, now()->addDays(3));

        $this->actingAs($this->admin);

        $this->post(route('logistics.job-orders.schedule'), [
            'delivery_id' => $delivery->id,
            'storage_tank_id' => $this->tanker->id,
            'scheduled_date' => now()->toDateString(),
        ])->assertRedirect();

        $trip = LogisticsTrip::firstOrFail();
        $stop = LogisticsTripStop::where('delivery_id', $delivery->id)->firstOrFail();

        $this->assertSame($trip->id, $stop->logistics_trip_id);
        $this->assertSame('Screen Test Client', $stop->client_name);
        $this->assertSame($delivery->dr_number, $stop->dr_no);
        $this->assertSame(2000, $stop->total_litres);

        // The delivery date itself never moves from Logistics.
        $this->assertSame(
            now()->addDays(3)->toDateString(),
            $delivery->fresh()->delivery_date->toDateString()
        );

        $request = ModificationRequest::firstOrFail();
        $this->assertSame('PENDING', $request->status);
        $this->assertSame(now()->toDateString(), $request->changes['delivery_date']['new']);
    }

    public function test_completing_the_stop_fulfils_the_delivery_for_an_authorised_role(): void
    {
        [$delivery] = $this->deliveryOn($this->tanker, 2000, now());

        $this->actingAs($this->admin);

        $this->post(route('logistics.job-orders.schedule'), [
            'delivery_id' => $delivery->id,
            'storage_tank_id' => $this->tanker->id,
            'scheduled_date' => now()->toDateString(),
        ]);

        $stop = LogisticsTripStop::where('delivery_id', $delivery->id)->firstOrFail();

        $this->postJson(route('logistics.schedule.stops.status', $stop->id), [
            'status' => 'completed',
        ])->assertOk()->assertJson(['ok' => true]);

        $this->assertSame('FULFILLED', $delivery->fresh()->status);
        $this->assertNotNull($delivery->fresh()->fulfilled_by);
        $this->assertSame('completed', $stop->fresh()->status);
        $this->assertSame('completed', LogisticsTrip::firstOrFail()->status);
    }

    public function test_ops_log_completes_the_stop_but_leaves_the_delivery_pending(): void
    {
        [$delivery] = $this->deliveryOn($this->tanker, 1500, now());

        $this->actingAs($this->admin);

        $this->post(route('logistics.job-orders.schedule'), [
            'delivery_id' => $delivery->id,
            'storage_tank_id' => $this->tanker->id,
            'scheduled_date' => now()->toDateString(),
        ]);

        $stop = LogisticsTripStop::where('delivery_id', $delivery->id)->firstOrFail();

        $this->actingAs($this->opsLog)
            ->postJson(route('logistics.schedule.stops.status', $stop->id), ['status' => 'completed'])
            ->assertOk();

        $this->assertSame('completed', $stop->fresh()->status);
        $this->assertSame('PENDING', $delivery->fresh()->status);
        $this->assertNull($delivery->fresh()->fulfilled_by);
    }

    public function test_a_cancelled_delivery_date_cannot_be_requested(): void
    {
        [$delivery] = $this->deliveryOn($this->tanker, 1000, now());
        $delivery->update(['status' => 'CANCELLED']);

        $this->actingAs($this->admin)
            ->postJson(route('logistics.schedule.delivery-date', $delivery->id), [
                'delivery_date' => now()->addDay()->toDateString(),
            ])
            ->assertStatus(422);

        $this->assertSame(0, ModificationRequest::count());
    }

    public function test_unit_status_advances_the_open_stop(): void
    {
        [$delivery] = $this->deliveryOn($this->tanker, 1000, now());

        $this->actingAs($this->admin);

        $this->post(route('logistics.job-orders.schedule'), [
            'delivery_id' => $delivery->id,
            'storage_tank_id' => $this->tanker->id,
            'scheduled_date' => now()->toDateString(),
        ]);

        $stop = LogisticsTripStop::where('delivery_id', $delivery->id)->firstOrFail();

        $this->postJson(route('logistics.units.status', $this->tanker->id), [
            'status' => 'to_client',
            'stop_id' => $stop->id,
        ])->assertOk()->assertJson(['ok' => true]);

        $this->assertSame('in_transit', $stop->fresh()->status);
        $this->get(route('logistics.units'))->assertOk()->assertSee('In transit to client');
    }

    public function test_personnel_names_are_unique_per_role_and_can_be_deactivated(): void
    {
        $this->actingAs($this->admin);

        $this->post(route('logistics.personnel.store'), [
            'name' => 'Same Name',
            'role' => 'driver',
        ])->assertRedirect();

        $this->post(route('logistics.personnel.store'), [
            'name' => 'Same Name',
            'role' => 'driver',
        ])->assertSessionHasErrors('name');

        // The same name is fine in the other role.
        $this->post(route('logistics.personnel.store'), [
            'name' => 'Same Name',
            'role' => 'helper',
        ])->assertSessionHasNoErrors();

        $this->assertSame(2, Personnel::count());

        $person = Personnel::where('role', 'driver')->firstOrFail();
        $this->post(route('logistics.personnel.toggle', $person->id))->assertRedirect();

        $this->assertFalse($person->fresh()->is_active);
        $this->assertCount(0, Personnel::active()->role('driver')->get());
    }

    public function test_a_cancelled_trip_refuses_new_stops(): void
    {
        $this->actingAs($this->admin);

        $this->post(route('logistics.schedule.store'), [
            'storage_tank_id' => $this->tanker->id,
            'scheduled_date' => now()->toDateString(),
            'trip_type' => 'delivery',
        ]);

        $trip = LogisticsTrip::firstOrFail();
        $this->post(route('logistics.schedule.destroy', $trip->id))->assertRedirect();
        $this->assertSame('cancelled', $trip->fresh()->status);

        $this->post(route('logistics.schedule.stops.store'), [
            'trip_id' => $trip->id,
            'client_name' => 'Too late',
            'lines' => [['compartment_no' => 1, 'quantity' => 1000]],
        ])->assertSessionHasErrors('trip');

        $this->assertSame(0, LogisticsTripStop::count());
    }

    /** @return array{0: Delivery} */
    private function deliveryOn(StorageTank $tanker, int $litres, \DateTimeInterface $date): array
    {
        $order = Order::create([
            'so_number' => 'SO-' . rand(10000, 99999),
            'account' => 'Screen Test Client',
            'date' => now()->toDateString(),
            'location' => 'Valenzuela',
            'status' => 'Active',
            'qty_ordered' => $litres,
            'clearing_status' => 'Approved',
            'fulfillment_type' => Order::FULFILLMENT_FUEL_TRADE,
            'order_category' => Order::CATEGORY_CLIENT_ORDER,
        ]);

        $delivery = Delivery::create([
            'order_id' => $order->id,
            'product_type' => 'D',
            'dr_number' => 'DR-' . rand(10000, 99999),
            'delivery_date' => $date,
            'qty_out' => $litres,
            'status' => 'PENDING',
            'type' => 'DELIVERY',
        ]);

        DeliveryAllocation::create([
            'delivery_id' => $delivery->id,
            'storage_tank_id' => $tanker->id,
            'tank_compartment_id' => $this->compartment->id,
            'quantity' => $litres,
        ]);

        return [$delivery];
    }
}
