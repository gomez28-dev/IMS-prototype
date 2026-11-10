<?php

namespace Tests\Feature\Logistics;

use App\Models\Admin;
use App\Models\Delivery;
use App\Models\DeliveryAllocation;
use App\Models\LogisticsTrip;
use App\Models\Order;
use App\Models\Personnel;
use App\Models\StorageTank;
use App\Models\TankCompartment;
use App\Models\Warehouse;
use Tests\TestCase;

/**
 * The four Logistics screens render for an editor and for a read-only role,
 * and the read-only role is refused on writes.
 */
class LogisticsScreensTest extends TestCase
{
    protected Admin $admin;

    protected Admin $opsLog;

    protected Admin $hod;

    protected StorageTank $tanker;

    protected TankCompartment $compartment;

    protected Order $order;

    protected Delivery $delivery;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = Admin::create([
            'username' => 'log_admin_' . uniqid(),
            'password' => bcrypt('secret'),
            'name' => 'Logistics Admin',
            'role' => 'admin',
        ]);

        $this->opsLog = Admin::create([
            'username' => 'log_ops_' . uniqid(),
            'password' => bcrypt('secret'),
            'name' => 'Ops Log',
            'role' => 'ops_log',
        ]);

        $this->hod = Admin::create([
            'username' => 'log_hod_' . uniqid(),
            'password' => bcrypt('secret'),
            'name' => 'HOD',
            'role' => 'hod',
        ]);

        $warehouse = Warehouse::create(['name' => 'Logistics Depot ' . rand(1000, 9999)]);

        $this->tanker = StorageTank::create([
            'warehouse_id' => $warehouse->id,
            'name' => 'Tanker One',
            'category' => 'tanker',
            'max_capacity' => 20000,
            'is_active' => true,
        ]);

        $this->compartment = TankCompartment::create([
            'storage_tank_id' => $this->tanker->id,
            'compartment_no' => 1,
            'capacity' => 10000,
            'product_type' => 'D',
        ]);

        Personnel::create(['name' => 'Driver One', 'role' => 'driver']);
        Personnel::create(['name' => 'Helper One', 'role' => 'helper']);

        $this->order = Order::create([
            'so_number' => 'SO-' . rand(10000, 99999),
            'account' => 'Screen Test Client',
            'date' => now()->toDateString(),
            'location' => 'Valenzuela',
            'status' => 'Active',
            'qty_ordered' => 2000,
            'clearing_status' => 'Approved',
            'fulfillment_type' => Order::FULFILLMENT_FUEL_TRADE,
            'order_category' => Order::CATEGORY_CLIENT_ORDER,
        ]);

        $this->delivery = Delivery::create([
            'order_id' => $this->order->id,
            'product_type' => 'D',
            'dr_number' => 'DR-' . rand(10000, 99999),
            'delivery_date' => now()->addDay(),
            'qty_out' => 2000,
            'status' => 'PENDING',
            'type' => 'DELIVERY',
        ]);

        DeliveryAllocation::create([
            'delivery_id' => $this->delivery->id,
            'storage_tank_id' => $this->tanker->id,
            'tank_compartment_id' => $this->compartment->id,
            'quantity' => 2000,
        ]);
    }

    public function test_admin_opens_every_logistics_screen(): void
    {
        $this->actingAs($this->admin);

        $this->get(route('logistics.schedule'))->assertOk()->assertSee('Trip schedule');
        $this->get(route('logistics.schedule', ['view' => 'month']))->assertOk();
        $this->get(route('logistics.schedule', ['view' => 'day']))->assertOk();
        $this->get(route('logistics.job-orders.index'))->assertOk()->assertSee('Job Orders');
        $this->get(route('logistics.job-orders.index', ['tab' => 'scheduled']))->assertOk();
        $this->get(route('logistics.job-orders.index', ['tab' => 'waiting']))->assertOk();
        $this->get(route('logistics.units'))->assertOk()->assertSee('Manage Units');
        $this->get(route('logistics.personnel.index'))->assertOk()->assertSee('Driver One');
        $this->get(route('logistics.personnel.create'))->assertOk();
    }

    public function test_detail_panel_returns_the_day(): void
    {
        $this->actingAs($this->admin);

        $this->getJson(route('logistics.schedule.detail', [
            'tank' => $this->tanker->id,
            'date' => now()->toDateString(),
        ]))
            ->assertOk()
            ->assertJsonPath('tanker.id', $this->tanker->id)
            ->assertJsonPath('compartments.0.capacity', 10000)
            ->assertJsonStructure([
                'tanker' => ['id', 'name', 'code', 'small', 'warehouse', 'max'],
                'compartments' => [['compartment_no', 'capacity', 'product_type', 'planned_kl']],
                'trips',
                'stop_statuses',
                'products',
                'can_edit',
            ]);
    }

    public function test_bad_date_falls_back_to_today(): void
    {
        $this->actingAs($this->admin);

        $this->get(route('logistics.schedule', ['view' => 'nonsense', 'date' => 'not-a-date']))
            ->assertOk();
    }

    public function test_read_only_role_can_look_but_not_write(): void
    {
        $this->actingAs($this->hod);

        $this->get(route('logistics.schedule'))->assertOk();
        $this->get(route('logistics.job-orders.index'))->assertOk();
        $this->get(route('logistics.units'))->assertOk();

        $this->post(route('logistics.schedule.store'), [
            'storage_tank_id' => $this->tanker->id,
            'scheduled_date' => now()->toDateString(),
            'trip_type' => 'delivery',
        ])->assertForbidden();

        $this->post(route('logistics.units.status', $this->tanker->id), [
            'status' => 'garage',
        ])->assertForbidden();

        $this->get(route('logistics.personnel.index'))->assertForbidden();

        $this->assertSame(0, LogisticsTrip::count());
    }

    public function test_role_outside_logistics_is_refused(): void
    {
        $sales = Admin::create([
            'username' => 'log_sales_' . uniqid(),
            'password' => bcrypt('secret'),
            'name' => 'Sales',
            'role' => 'sales',
        ]);

        $this->actingAs($sales);

        $this->get(route('logistics.schedule'))->assertForbidden();
        $this->get(route('logistics.units'))->assertForbidden();
    }
}
