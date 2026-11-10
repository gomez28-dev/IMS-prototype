<?php

namespace Tests\Feature\Executive;

use App\Models\Admin;
use App\Models\Delivery;
use App\Models\LogisticsTrip;
use App\Models\LogisticsTripStop;
use App\Models\LogisticsUnitStatus;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderDelivery;
use App\Models\StockIn;
use App\Models\StorageTank;
use App\Models\Warehouse;
use App\Services\Executive\ExecutiveSummary;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ExecutiveSummaryTest extends TestCase
{
    use DatabaseTransactions;

    private Carbon $now;

    private Admin $actor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->now = Carbon::parse('2026-11-10 10:00:00', 'Asia/Manila');
        $this->actor = $this->admin('admin');
    }

    public function test_sales_figures_count_only_this_month(): void
    {
        $thisMonth = $this->order('SO-EXEC-1', '2026-11-03', 2000, 50);
        OrderItem::create(['order_id' => $thisMonth->id, 'product_type' => 'D', 'qty' => 1000, 'price' => 50]);
        OrderItem::create(['order_id' => $thisMonth->id, 'product_type' => 'U', 'qty' => 1000, 'price' => 50]);

        Delivery::create([
            'order_id' => $thisMonth->id,
            'dr_number' => 'DR-EXEC-1',
            'delivery_date' => '2026-11-05',
            'qty_out' => 500,
            'status' => 'FULFILLED',
            'product_type' => 'D',
        ]);

        // Last month, still undelivered: out of this month's totals, but it is
        // exactly what the "open" figure is for.
        $lastMonth = $this->order('SO-EXEC-2', '2026-10-02', 1000, 50);
        OrderItem::create(['order_id' => $lastMonth->id, 'product_type' => 'D', 'qty' => 1000, 'price' => 50]);

        $sales = $this->summary()->sales();

        $this->assertSame(1, $sales['figures']['orders']['value']);
        $this->assertSame(2000, $sales['figures']['ordered']['value']);
        $this->assertSame(500, $sales['figures']['delivered']['value']);
        $this->assertSame(1500, $sales['figures']['undelivered']['value']);
        $this->assertSame(100000.0, (float) $sales['figures']['value']['value']);
        // Month-to-date shortfall (1500) plus the carry-over order (1000).
        $this->assertSame(2500, $sales['figures']['open_undelivered']['value']);
    }

    public function test_sales_value_of_november_excludes_a_cancelled_order(): void
    {
        $kept = $this->order('SO-EXEC-3', '2026-11-04', 1000, 40);
        OrderItem::create(['order_id' => $kept->id, 'product_type' => 'P', 'qty' => 1000, 'price' => 40]);

        $cancelled = $this->order('SO-EXEC-4', '2026-11-05', 5000, 40);
        $cancelled->update(['status' => 'Cancelled']);

        $sales = $this->summary()->sales();

        $this->assertSame(1, $sales['figures']['orders']['value']);
        $this->assertSame(40000.0, (float) $sales['figures']['value']['value']);
        $this->assertSame(1000, $sales['figures']['open_undelivered']['value']);
    }

    public function test_wet_stock_totals_depot_stock_by_product(): void
    {
        $diesel = $this->depotTank('D', 'Diesel Depot');
        StockIn::create([
            'storage_tank_id' => $diesel->id,
            'admin_id' => $this->actor->id,
            'quantity' => 5000,
            'date' => '2026-11-01',
        ]);
        $diesel->update(['contaminated_liters' => 300]);

        $unleaded = $this->depotTank('U', 'Unleaded Depot');
        StockIn::create([
            'storage_tank_id' => $unleaded->id,
            'admin_id' => $this->actor->id,
            'quantity' => 2000,
            'date' => '2026-11-02',
        ]);

        $wet = $this->summary()->wetStock();

        $this->assertSame(5000, $wet['figures']['diesel']['value']);
        $this->assertSame(2000, $wet['figures']['unleaded']['value']);
        $this->assertSame(0, $wet['figures']['premium']['value']);
        $this->assertSame(300, $wet['figures']['contaminated']['value']);
        $this->assertSame(6700, $wet['figures']['sellable']['value']);
    }

    public function test_stock_order_figures_track_the_vp_queue(): void
    {
        PurchaseOrder::create([
            'po_number' => 'PO-EXEC-1',
            'po_type' => 'STANDARD_REPLENISHMENT',
            'qty_ordered' => 5000,
            'request_status' => 'RECEIVED',
            'request_date' => '2026-11-04',
            'status' => 'Pending',
            'total_amount' => 250000,
        ]);

        // Last month's PO must not inflate this month's count or value.
        PurchaseOrder::create([
            'po_number' => 'PO-EXEC-2',
            'po_type' => 'STANDARD_REPLENISHMENT',
            'qty_ordered' => 9000,
            'request_status' => 'COMPLETED',
            'request_date' => '2026-10-04',
            'status' => 'Completed',
            'total_amount' => 900000,
        ]);

        $po = PurchaseOrder::where('po_number', 'PO-EXEC-1')->firstOrFail();
        PurchaseOrderDelivery::create([
            'purchase_order_id' => $po->id,
            'product' => 'Diesel',
            'qty_to_receive' => 5000,
            'status' => 'Active',
            'lift_status' => PurchaseOrderDelivery::LIFT_UNLIFTED,
        ]);

        $stock = $this->summary()->stockOrders();

        $this->assertSame(1, $stock['figures']['po_count']['value']);
        $this->assertSame(250000.0, (float) $stock['figures']['po_value']['value']);
        $this->assertSame(1, $stock['figures']['awaiting_vp']['value']);
        $this->assertSame(1, $stock['figures']['unlifted']['value']);
    }

    public function test_logistics_figures_are_todays_only(): void
    {
        $tanker = $this->tanker('T-EXEC');

        $today = LogisticsTrip::create([
            'storage_tank_id' => $tanker->id,
            'scheduled_date' => '2026-11-10',
            'trip_type' => 'delivery',
            'status' => 'scheduled',
        ]);
        LogisticsTripStop::create([
            'logistics_trip_id' => $today->id,
            'client_name' => 'Apex',
            'status' => 'pending',
        ]);

        $yesterday = LogisticsTrip::create([
            'storage_tank_id' => $tanker->id,
            'scheduled_date' => '2026-11-09',
            'trip_type' => 'delivery',
            'status' => 'completed',
        ]);
        LogisticsTripStop::create([
            'logistics_trip_id' => $yesterday->id,
            'client_name' => 'Old stop',
            'status' => 'completed',
        ]);

        LogisticsUnitStatus::create([
            'storage_tank_id' => $tanker->id,
            'status' => 'to_client',
            'target_name' => 'Apex',
        ]);

        $logistics = $this->summary()->logistics();

        $this->assertSame(1, $logistics['figures']['trips_today']['value']);
        $this->assertSame(1, $logistics['figures']['dropoffs_due']['value']);
        $this->assertSame(0, $logistics['figures']['running']['value']);
        $this->assertSame(1, $logistics['figures']['units_in_transit']['value']);
    }

    public function test_attention_hides_links_the_role_cannot_reach(): void
    {
        $vp = $this->admin('vp');
        $sales = $this->admin('sales');

        $items = $this->summary()->attention($vp);

        $this->assertContains('po_approvals', array_column($items, 'key'));
        $this->assertNotContains('delivery_modifications', array_column($items, 'key'));

        // A role that cannot see this page still gets no privileged links.
        foreach ($this->summary()->attention($sales) as $item) {
            if ($item['key'] === 'delivery_modifications') {
                $this->assertNull($item['url']);
            }
        }
    }

    private function summary(): ExecutiveSummary
    {
        return new ExecutiveSummary($this->now);
    }

    private function order(string $so, string $date, int $qty, float $price): Order
    {
        return Order::create([
            'account' => 'Apex Logistics',
            'location' => 'Valenzuela',
            'so_number' => $so . '-' . uniqid(),
            'date' => $date,
            'qty_ordered' => $qty,
            'price' => $price,
            'status' => 'Active',
            'clearing_status' => 'Pending',
            'fulfillment_type' => 'DELIVERY',
            'order_category' => 'CLIENT_ORDER',
        ]);
    }

    private function depotTank(string $product, string $name): StorageTank
    {
        return StorageTank::create([
            'warehouse_id' => Warehouse::create(['name' => $name . ' ' . uniqid()])->id,
            'name' => $name,
            'category' => 'depot',
            'product_type' => $product,
            'max_capacity' => 50000,
            'is_active' => true,
        ]);
    }

    private function tanker(string $name): StorageTank
    {
        return StorageTank::create([
            'warehouse_id' => Warehouse::create(['name' => 'Garage ' . uniqid()])->id,
            'name' => $name,
            'category' => 'tanker',
            'max_capacity' => 40000,
            'is_active' => true,
        ]);
    }

    private function admin(string $role): Admin
    {
        return Admin::create([
            'username' => $role . '_' . uniqid(),
            'password' => bcrypt('secret'),
            'name' => ucfirst($role),
            'role' => $role,
        ]);
    }
}
