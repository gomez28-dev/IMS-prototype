<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\PurchaseOrder;
use App\Models\Warehouse;
use Tests\TestCase;

/**
 * A PO number must be unique when a PO is created or renumbered.
 *
 * The supervisor's rule: each PO should be unique. Within a single ATL the
 * same PO may legitimately appear more than once, which is a different
 * concern and is covered by SupplierPoAllocationDrawdownTest.
 *
 * Root cause this guards: updateRequest() overwrote po_number straight
 * from user input with no uniqueness check, so submitting a number another
 * PO already carried silently left two POs sharing it.
 */
class PurchaseOrderNumberUniquenessTest extends TestCase
{
    protected Admin $purchasing;
    protected Warehouse $depot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->purchasing = Admin::create([
            'username' => 'poun_' . uniqid(),
            'password' => bcrypt('secret'),
            'name' => 'Purchasing Officer',
            'role' => 'purchasing',
        ]);

        $this->depot = Warehouse::create(['name' => 'Uniqueness Depot ' . rand(1000, 9999)]);
    }

    protected function replenishmentPo(?string $poNumber = null): PurchaseOrder
    {
        return PurchaseOrder::create([
            'po_number' => $poNumber,
            'po_type' => 'STANDARD_REPLENISHMENT',
            'warehouse_id' => $this->depot->id,
            'qty_ordered' => 10000,
            'request_status' => 'REQUESTED',
            'status' => 'Pending',
            'requested_by' => $this->purchasing->id,
            'request_date' => now(),
            'requested_products' => [['product' => 'Diesel', 'quantity' => 10000]],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function payload(PurchaseOrder $po, string $poNumber): array
    {
        return [
            'po_number' => $poNumber,
            'supplier_name' => 'Petron Bataan',
            'order_type' => 'PICK_UP',
            'delivery_channel' => 'SUPPLIER_DOYEN_PICKUP',
            'product' => 'Diesel',
            'qty_to_receive' => 10000,
            'receiving_date' => now()->addDay()->format('Y-m-d'),
        ];
    }

    public function test_a_po_can_be_renumbered_to_an_unused_number(): void
    {
        $po = $this->replenishmentPo('PO-FREE-1');

        $this->actingAs($this->purchasing)
            ->post(route('stock-orders.update-request', $po->id), $this->payload($po, 'PO-FREE-2'))
            ->assertSessionHasNoErrors();

        $this->assertSame('PO-FREE-2', $po->fresh()->po_number);
    }

    /**
     * Re-saving a PO with the number it already has must not fail:
     * the uniqueness rule has to ignore the record being edited.
     */
    public function test_a_po_can_be_resaved_with_its_own_number(): void
    {
        $po = $this->replenishmentPo('PO-SAME-1');

        $this->actingAs($this->purchasing)
            ->post(route('stock-orders.update-request', $po->id), $this->payload($po, 'PO-SAME-1'))
            ->assertSessionHasNoErrors();

        $this->assertSame('PO-SAME-1', $po->fresh()->po_number);
    }

    public function test_a_po_cannot_take_a_number_already_used_by_another_po(): void
    {
        $taken = $this->replenishmentPo('PO-TAKEN-1');
        $po = $this->replenishmentPo('PO-ORIGINAL-1');

        $response = $this->actingAs($this->purchasing)
            ->post(route('stock-orders.update-request', $po->id), $this->payload($po, 'PO-TAKEN-1'));

        $response->assertSessionHasErrors('po_number');

        $this->assertSame(
            'PO-ORIGINAL-1',
            $po->fresh()->po_number,
            'The rejected PO must keep its previous number.'
        );

        $this->assertSame(
            1,
            PurchaseOrder::where('po_number', 'PO-TAKEN-1')->count(),
            'Two POs must never share one PO number.'
        );
    }

    /**
     * Two POs that both arrive with no number must not be blocked:
     * NULL is not a duplicate.
     */
    public function test_po_numbers_stay_unique_when_both_are_null(): void
    {
        $first = $this->replenishmentPo();
        $second = $this->replenishmentPo();

        $this->assertNull($first->po_number);
        $this->assertNull($second->po_number);

        $this->assertSame(
            2,
            PurchaseOrder::whereNull('po_number')->count(),
            'Unnumbered POs are pending issuance, not duplicates.'
        );
    }

    /**
     * The supplier PO creation path already enforced uniqueness; this
     * confirms the rule is consistent across both entry points.
     */
    public function test_supplier_po_creation_still_rejects_a_duplicate_number(): void
    {
        $this->replenishmentPo('PO-SUP-1');

        $this->actingAs($this->purchasing)
            ->post(route('stock-orders.store-supplier-po'), [
                'po_number' => 'PO-SUP-1',
                'supplier_id' => \App\Models\Supplier::first()?->id
                    ?? \App\Models\Supplier::create([
                        'company_name' => 'Petron Bataan',
                        'location' => 'Bataan',
                    ])->id,
                'po_type' => 'STANDARD_REPLENISHMENT',
            ])
            ->assertSessionHasErrors('po_number');
    }
}