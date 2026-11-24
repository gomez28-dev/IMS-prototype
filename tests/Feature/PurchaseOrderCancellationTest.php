<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderActivity;
use App\Models\PurchaseOrderDelivery;
use App\Models\PurchaseOrderItem;
use App\Models\Supplier;
use Tests\TestCase;

/**
 * Cancelling a purchase order from the P.O. list.
 *
 * Cancelling is the VP side's call: Purchasing raises and edits POs, the VP
 * approves or cancels them. A PO that already has an active ATL cannot be
 * cancelled underneath it - the ATL has to be cancelled first - and a
 * cancelled PO drops out of the stat cards while staying on the list so its
 * number and history remain reachable.
 */
class PurchaseOrderCancellationTest extends TestCase
{
    protected Admin $purchasing;

    protected Admin $vp;

    protected function setUp(): void
    {
        parent::setUp();

        $this->purchasing = Admin::create([
            'username' => 'purchasing_'.uniqid(),
            'password' => bcrypt('secret'),
            'name' => 'Rica Esaga',
            'role' => 'purchasing',
        ]);

        $this->vp = Admin::create([
            'username' => 'vp_'.uniqid(),
            'password' => bcrypt('secret'),
            'name' => 'Bernice Nikki Lee',
            'role' => 'vp',
        ]);
    }

    protected function po(string $number = 'PO-CANCEL-0001', int $qty = 1000): PurchaseOrder
    {
        $supplier = Supplier::create([
            'company_name' => 'Petron '.uniqid(),
            'location' => 'Bataan',
        ]);

        $po = PurchaseOrder::create([
            'po_number' => $number,
            'po_type' => PurchaseOrder::TYPE_STANDARD_REPLENISHMENT,
            'supplier_id' => $supplier->id,
            'supplier_name' => $supplier->company_name,
            'qty_ordered' => $qty,
            'request_status' => 'CONFIRMED',
        ]);

        PurchaseOrderItem::create([
            'purchase_order_id' => $po->id,
            'product' => 'Diesel',
            'quantity_ordered' => $qty,
            'unit_price' => 50.00,
        ]);

        return $po;
    }

    protected function atl(PurchaseOrder $po, string $number, string $status): PurchaseOrderDelivery
    {
        return PurchaseOrderDelivery::create([
            'purchase_order_id' => $po->id,
            'atl_number' => $number,
            'product' => 'Diesel',
            'qty_to_receive' => 500,
            'receiving_date' => now()->addDay(),
            'status' => $status,
            'lift_status' => PurchaseOrderDelivery::LIFT_UNLIFTED,
        ]);
    }

    protected function totalOrders(): int
    {
        $html = $this->actingAs($this->vp)
            ->get(route('stock-orders.purchase-orders.index'))
            ->assertOk()
            ->getContent();

        preg_match('/stat-big">\s*([\d,]+)\s*</', $html, $matches);

        return (int) str_replace(',', '', $matches[1] ?? '0');
    }

    public function test_purchasing_cannot_cancel_a_purchase_order(): void
    {
        $po = $this->po();

        $this->actingAs($this->purchasing)
            ->post(route('stock-orders.cancel', $po->id))
            ->assertForbidden();

        $this->assertFalse($po->fresh()->isCancelled());
    }

    public function test_the_vp_cancels_a_purchase_order_and_it_reads_as_cancelled(): void
    {
        $po = $this->po();

        $this->actingAs($this->vp)
            ->post(route('stock-orders.cancel', $po->id))
            ->assertRedirect()
            ->assertSessionHas('success');

        $po->refresh();
        $this->assertSame('Cancelled', $po->status);
        $this->assertSame('CANCELLED', $po->request_status);
        $this->assertTrue($po->isCancelled());

        // The cancellation is on the PO's activity log and the audit log.
        $this->assertDatabaseHas('purchase_order_activities', [
            'purchase_order_id' => $po->id,
            'actor_id' => $this->vp->id,
            'action' => 'cancelled',
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'admin_id' => $this->vp->id,
            'action' => 'cancelled',
        ]);

        // The row stays on the list, labelled Cancelled rather than Approved.
        $html = $this->actingAs($this->vp)
            ->get(route('stock-orders.purchase-orders.index'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString($po->po_number, $html);
        $this->assertStringContainsString('Cancelled', $html);
    }

    public function test_a_po_with_an_active_atl_cannot_be_cancelled(): void
    {
        $po = $this->po();
        $this->atl($po, 'ATL-ACTIVE-1', 'Active');

        $this->actingAs($this->vp)
            ->post(route('stock-orders.cancel', $po->id))
            ->assertRedirect()
            ->assertSessionHas('info');

        $this->assertFalse($po->fresh()->isCancelled());
    }

    public function test_a_po_whose_only_atl_was_cancelled_can_be_cancelled(): void
    {
        $po = $this->po();
        $this->atl($po, 'ATL-CANCELLED-1', 'Cancelled');

        $this->actingAs($this->vp)
            ->post(route('stock-orders.cancel', $po->id))
            ->assertSessionHas('success');

        $this->assertTrue($po->fresh()->isCancelled());
    }

    public function test_a_cancelled_po_no_longer_counts_in_the_stat_cards(): void
    {
        $po = $this->po('PO-CANCEL-STATS', 123456);

        $before = $this->totalOrders();

        $this->actingAs($this->vp)
            ->post(route('stock-orders.cancel', $po->id))
            ->assertSessionHas('success');

        $this->assertSame($before - 1, $this->totalOrders());
    }

    public function test_cancelling_twice_is_harmless(): void
    {
        $po = $this->po();

        $this->actingAs($this->vp)
            ->post(route('stock-orders.cancel', $po->id))
            ->assertSessionHas('success');

        $this->actingAs($this->vp)
            ->post(route('stock-orders.cancel', $po->id))
            ->assertSessionHas('info');

        $this->assertSame(1, PurchaseOrderActivity::where('purchase_order_id', $po->id)
            ->where('action', 'cancelled')
            ->count());
    }
}
