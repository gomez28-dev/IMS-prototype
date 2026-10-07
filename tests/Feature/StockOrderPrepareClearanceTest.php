<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\PurchaseOrder;
use Tests\TestCase;

/**
 * Supervisor feedback 2026-10-07, Images 5 and 6.
 *
 * Opening a pending Standard Replenishment PO at /stock-orders/{id}/prepare
 * threw:
 *   ErrorException: Undefined variable $isCleared
 *   at resources/views/wetstock/stock-orders/form.blade.php:438
 *
 * Root cause: $isCleared was assigned only inside the `fuel_trade` branch of
 * the view, but the Submit-for-Approval button that reads it sits after that
 * branch's @endif, so edit_request mode never defined it.
 */
class StockOrderPrepareClearanceTest extends TestCase
{
    protected Admin $purchasing;
    protected PurchaseOrder $pendingPo;

    protected function setUp(): void
    {
        parent::setUp();

        $this->purchasing = Admin::create([
            'username' => 'prep_' . uniqid(),
            'password' => bcrypt('secret'),
            'name' => 'Purchasing Officer',
            'role' => 'purchasing',
        ]);
    }

    /**
     * A pending Depot Replenishment PO of the exact shape from Image 6:
     * Standard Replenishment, a site, no linked Sales Order, no ATL yet.
     */
    protected function pendingReplenishmentPo(): PurchaseOrder
    {
        $warehouse = \App\Models\Warehouse::first()
            ?? \App\Models\Warehouse::create(['name' => 'San Simon']);

        return PurchaseOrder::create([
            'po_type' => 'STANDARD_REPLENISHMENT',
            'warehouse_id' => $warehouse->id,
            'qty_ordered' => 10001,
            'request_status' => 'REQUESTED',
            'status' => 'Pending',
            'requested_by' => $this->purchasing->id,
            'request_date' => now(),
            'requested_products' => [['product' => 'Diesel', 'quantity' => 10001]],
        ]);
    }

    public function test_process_atl_button_on_a_pending_replenishment_po_renders(): void
    {
        $po = $this->pendingReplenishmentPo();

        $response = $this->actingAs($this->purchasing)
            ->get(route('stock-orders.edit-request', $po->id));

        $response->assertOk();
        $response->assertDontSee('Undefined variable', false);
    }

    public function test_prepare_page_renders_the_submit_button_without_error(): void
    {
        $po = $this->pendingReplenishmentPo();

        $response = $this->actingAs($this->purchasing)
            ->get(route('stock-orders.edit-request', $po->id));

        $response->assertOk();
        $response->assertSee('Submit for Approval');
    }

    /**
     * A Depot Replenishment PO has no Sales Order and therefore no Accounting
     * clearance gate, so the submit button must be usable.
     */
    public function test_submit_for_approval_is_not_disabled_for_a_replenishment_po(): void
    {
        $po = $this->pendingReplenishmentPo();

        $html = $this->actingAs($this->purchasing)
            ->get(route('stock-orders.edit-request', $po->id))
            ->getContent();

        $this->assertMatchesRegularExpression(
            '/id="submitBtn"/',
            $html,
            'submit button should be present'
        );

        if (preg_match('/<button[^>]*id="submitBtn".*?>/s', $html, $m)) {
            $this->assertStringNotContainsString(
                'disabled',
                $m[0],
                'A pending Depot Replenishment PO has no Accounting clearance gate, so submit must be enabled.'
            );
        }
    }

    /**
     * The fuel-trade branch must keep its clearance gate working; that gate
     * exists because those orders really are cleared by Accounting first.
     */
    public function test_fuel_trade_clearance_gate_still_applies(): void
    {
        $warehouse = \App\Models\Warehouse::first()
            ?? \App\Models\Warehouse::create(['name' => 'San Simon']);

        $notCleared = \App\Models\Order::create([
            'account' => 'Swift Logistics',
            'ordered_by' => 'Swift Logistics',
            'location' => $warehouse->name,
            'so_number' => 'SO-CLEAR-' . rand(1000, 9999),
            'date' => now(),
            'qty_ordered' => 10000,
            'price' => 52.00,
            'product' => 'Diesel',
            'order_status' => 'APPROVED',
            'clearing_status' => 'Pending',
            'fulfillment_type' => 'FUEL_TRADE',
        ]);

        // createFromSalesOrder() redirects an uncleared order away with an
        // error, so the gate is asserted at that boundary.
        $this->actingAs($this->purchasing)
            ->get(route('stock-orders.create-fuel-trade-po', $notCleared->id))
            ->assertRedirect(route('stock-orders.purchase-orders.index'));
    }
}