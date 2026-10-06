<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderDelivery;
use App\Models\Warehouse;
use Tests\TestCase;

class DoyenStocksReceivedPermissionsTest extends TestCase
{
    protected Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();

        $this->warehouse = Warehouse::firstOrCreate(['name' => 'Valenzuela Depot']);
    }

    /**
     * Roles allowed to mark stock received, per the redesign's allowlist.
     */
    public static function allowedRoles(): array
    {
        return [
            'ops admin' => ['ops_admin', true],
            'ops warehouse' => ['ops_wh', true],
            'ops manager' => ['ops_mgr', true],
            'admin' => ['admin', true],
        ];
    }

    /**
     * Roles that must NOT be able to confirm fuel arrived, even though the
     * broad Module 2 blocklist would have let several of them through.
     */
    public static function blockedRoles(): array
    {
        return [
            'purchasing' => ['purchasing', false],
            'vp' => ['vp', false],
            'hod' => ['hod', false],
            'ops logistics' => ['ops_log', false],
            'accounting' => ['accounting', false],
            'sales' => ['sales', false],
        ];
    }

    public function test_allowed_roles_can_receive_doyen_stocks(): void
    {
        foreach (self::allowedRoles() as $label => [$role, $allowed]) {
            $user = $this->user($role);
            $this->assertTrue($user->canReceiveStockIntoDepot(), "{$label} should be allowed to receive");
        }
    }

    public function test_other_roles_cannot_receive_doyen_stocks(): void
    {
        foreach (self::blockedRoles() as $label => [$role, $allowed]) {
            $user = $this->user($role);
            $this->assertFalse(
                $user->canReceiveStockIntoDepot(),
                "{$label} must not be able to mark stock received"
            );
        }
    }

    public function test_blocked_role_is_refused_by_the_receive_endpoint(): void
    {
        $tank = $this->tank();
        $delivery = $this->delivery();

        // ops_log may edit Module 2 generally, but must not confirm receipt.
        $opsLog = $this->user('ops_log');

        $this->actingAs($opsLog)
            ->post(route('wetstock.deliveries.receive-stock', $delivery->id), [
                'storage_tank_id' => $tank->id,
                'date' => now()->format('Y-m-d'),
            ])
            ->assertForbidden();

        $this->assertEquals(0, \App\Models\StockIn::where('storage_tank_id', $tank->id)->count());
    }

    public function test_receiving_records_who_and_when(): void
    {
        $tank = $this->tank();
        $delivery = $this->delivery();
        $opsManager = $this->user('ops_mgr');

        $this->actingAs($opsManager)
            ->post(route('wetstock.deliveries.receive-stock', $delivery->id), [
                'storage_tank_id' => $tank->id,
                'date' => now()->format('Y-m-d'),
            ])
            ->assertSessionHas('success');

        $delivery->refresh();
        $this->assertNotNull($delivery->received_at, 'Receiving must record when it happened');
        $this->assertEquals($opsManager->id, $delivery->received_by);
        $this->assertEquals('Completed', $delivery->status);
    }

    public function test_lifting_is_limited_to_purchasing_and_admin(): void
    {
        $this->assertTrue($this->user('purchasing')->canMarkAtlLifted());
        $this->assertTrue($this->user('admin')->canMarkAtlLifted());

        foreach (['vp', 'hod', 'ops_admin', 'ops_wh', 'ops_mgr', 'ops_log', 'accounting'] as $role) {
            $this->assertFalse(
                $this->user($role)->canMarkAtlLifted(),
                "{$role} must not be able to mark an ATL lifted"
            );
        }
    }

    private function user(string $role): Admin
    {
        return Admin::create([
            'username' => $role . '_' . uniqid(),
            'password' => bcrypt('secret'),
            'name' => ucfirst($role) . ' User',
            'role' => $role,
        ]);
    }

    private function delivery(): PurchaseOrderDelivery
    {
        $po = PurchaseOrder::create([
            'po_number' => 'PO-RCV-' . rand(1000, 9999),
            'po_type' => 'STANDARD_REPLENISHMENT',
            'supplier_name' => 'Petron Bataan',
            'warehouse_id' => $this->warehouse->id,
            'qty_ordered' => 10000,
            'request_status' => 'CONFIRMED',
            'status' => 'Pending',
        ]);

        return PurchaseOrderDelivery::create([
            'purchase_order_id' => $po->id,
            'delivery_channel' => 'SUPPLIER_STOCKS_DELIVERY',
            'order_type' => 'DELIVERY',
            'atl_category' => PurchaseOrderDelivery::CATEGORY_DOYEN_STOCKS,
            'product' => 'Diesel',
            'qty_to_receive' => 10000,
            'status' => 'Active',
            'dr_number' => 'DR-RCV-1',
        ]);
    }

    private function tank(): \App\Models\StorageTank
    {
        return \App\Models\StorageTank::create([
            'warehouse_id' => $this->warehouse->id,
            'name' => 'Tank ' . rand(1000, 9999),
            'category' => 'depot',
            'max_capacity' => 50000,
            'is_active' => true,
        ]);
    }
}
