<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Client;
use App\Models\Delivery;
use App\Models\ModificationRequest;
use App\Models\Order;
use App\Models\StorageTank;
use App\Models\Warehouse;
use Tests\TestCase;

/**
 * Regression test for a real production 500.
 *
 * Production log (2026-10-06 08:14) recorded three
 *   "Array to string conversion (View: approvals/_request_card.blade.php)"
 * errors: an array-valued change diff was interpolated straight into the
 * card and blew the whole approvals page up.
 *
 * Compartment lines are arrays, so any DR edit that rewrites items hits
 * this. Both approvals pages must render array diffs without crashing.
 */
class ApprovalCardArrayDiffRenderTest extends TestCase
{
    protected Admin $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = Admin::create([
            'username' => 'approvals_' . uniqid(),
            'password' => bcrypt('secret'),
            'name' => 'Approving Admin',
            'role' => 'admin',
        ]);

        Client::first() ?? Client::create(['name' => 'Swift Logistics']);
    }

    protected function delivery(): Delivery
    {
        $warehouse = Warehouse::first() ?? Warehouse::create(['name' => 'Valenzuela Depot']);
        $tank = StorageTank::first() ?? StorageTank::create([
            'warehouse_id' => $warehouse->id,
            'name' => 'Card Tank',
            'category' => 'storage',
            'max_capacity' => 20000,
            'is_active' => true,
        ]);

        $order = Order::create([
            'account' => 'Swift Logistics',
            'ordered_by' => 'Swift Logistics',
            'location' => $warehouse->name,
            'so_number' => 'SO-CARD-' . rand(1000, 9999),
            'date' => now(),
            'qty_ordered' => 5000,
            'price' => 52.00,
            'product' => 'Unleaded',
            'order_status' => 'APPROVED',
            'clearing_status' => 'Approved',
        ]);

        return Delivery::create([
            'order_id' => $order->id,
            'storage_tank_id' => $tank->id,
            'dr_number' => 'DR-CARD-' . rand(1000, 9999),
            'delivery_date' => now(),
            'qty_out' => 5000,
            'status' => 'PENDING',
        ]);
    }

    /**
     * A diff whose old/new values are compartment-line ARRAYS — the exact
     * shape that crashed production.
     */
    protected function requestWithArrayDiff(): ModificationRequest
    {
        return ModificationRequest::create([
            'requestable_type' => Delivery::class,
            'requestable_id' => $this->delivery()->id,
            'requested_by' => $this->admin->id,
            'changes' => [
                'items' => [
                    'old' => [
                        ['product_type' => 'U', 'qty_out' => 2000],
                        ['product_type' => 'P', 'qty_out' => 500],
                    ],
                    'new' => [
                        ['product_type' => 'U', 'qty_out' => 2500],
                        ['product_type' => 'D', 'qty_out' => 1000],
                    ],
                ],
            ],
            'reason' => 'Rebalance compartments',
            'status' => 'PENDING',
        ]);
    }

    public function test_changes_stores_arrays_not_strings(): void
    {
        $req = $this->requestWithArrayDiff()->fresh();

        $this->assertIsArray($req->changes);
        $this->assertIsArray($req->changes['items']['old']);
        $this->assertSame('U', $req->changes['items']['old'][0]['product_type']);
    }

    public function test_module_one_approvals_page_renders_array_diffs(): void
    {
        $this->requestWithArrayDiff();

        $this->actingAs($this->admin)->get(route('approvals.index'))->assertOk();
    }

    public function test_module_two_approvals_page_renders_array_diffs(): void
    {
        $this->requestWithArrayDiff();

        $this->actingAs($this->admin)->get(route('wetstock.approvals.index'))->assertOk();
    }

    public function test_module_one_approvals_page_renders_with_no_diffs_at_all(): void
    {
        $this->actingAs($this->admin)->get(route('approvals.index'))->assertOk();
    }
}