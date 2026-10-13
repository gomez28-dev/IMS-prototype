<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\PoNumber;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use Tests\TestCase;

/**
 * The supervisor asked for the Official PO Number to be a dropdown rather
 * than typed text. Numbers are pre-generated per refinery, supplier-prefixed,
 * and spent once used, so a number can never clash or be mistyped.
 */
class PoNumberPoolTest extends TestCase
{
    protected Admin $purchasing;

    protected function setUp(): void
    {
        parent::setUp();

        $this->purchasing = Admin::create([
            'username' => 'pool_'.uniqid(),
            'password' => bcrypt('secret'),
            'name' => 'Purchasing Officer',
            'role' => 'purchasing',
        ]);
    }

    protected function supplier(string $name = 'Petron Bataan Refinery'): Supplier
    {
        return Supplier::create([
            'company_name' => $name.' '.rand(1000, 9999),
            'location' => 'Limay Terminal',
        ]);
    }

    // ---- Number format ----------------------------------------------------

    public function test_a_number_is_prefixed_with_its_refinery(): void
    {
        $supplier = Supplier::create([
            'company_name' => 'Petron Bataan Refinery',
            'location' => 'Limay Terminal',
        ]);

        PoNumber::ensurePoolFor($supplier, 3);

        $numbers = PoNumber::where('supplier_id', $supplier->id)->pluck('po_number');

        $this->assertCount(3, $numbers);
        foreach ($numbers as $number) {
            $this->assertMatchesRegularExpression(
                '/^PO-PETRON-' . now()->year . '-\d{3}$/',
                $number,
                'Numbers are supplier-prefixed so a PO traces back to its refinery.'
            );
        }
    }

    public function test_the_prefix_comes_from_the_first_word_of_the_company(): void
    {
        $this->assertSame('PETRON', PoNumber::prefixFor('Petron Bataan Refinery'));
        $this->assertSame('SHELL', PoNumber::prefixFor('Shell Philippines Inc'));
        $this->assertSame('CALTEX', PoNumber::prefixFor('Caltex'));
        // Punctuation must not leak into a number.
        $this->assertSame('ABC', PoNumber::prefixFor('A.B.C. Trading'));
        $this->assertSame('SUP', PoNumber::prefixFor(''));
    }

    public function test_a_refilled_pool_continues_the_sequence(): void
    {
        $supplier = $this->supplier();

        PoNumber::ensurePoolFor($supplier, 3);
        $first = PoNumber::where('supplier_id', $supplier->id)->orderBy('id')->pluck('po_number')->all();

        // Spend them all, then top the pool back up.
        PoNumber::where('supplier_id', $supplier->id)->update(['status' => PoNumber::STATUS_USED]);
        PoNumber::ensurePoolFor($supplier, 3);

        $second = PoNumber::where('supplier_id', $supplier->id)
            ->available()
            ->orderBy('id')
            ->pluck('po_number')
            ->all();

        $this->assertCount(3, $second);
        // None of the new numbers reissues one already spent.
        $this->assertEmpty(array_intersect($first, $second));
    }

    // ---- The dropdown -----------------------------------------------------

    public function test_the_create_form_offers_a_dropdown_not_a_text_box(): void
    {
        PoNumber::ensurePoolFor($this->supplier('Dropdown Test Refinery'), 2);

        $html = $this->actingAs($this->purchasing)
            ->get(route('stock-orders.create-supplier-po'))
            ->assertOk()
            ->getContent();

        $this->assertMatchesRegularExpression('/<select[^>]*name="po_number"/', $html);
        $this->assertDoesNotMatchRegularExpression('/<input[^>]*name="po_number"/', $html);
        // The options carry their supplier so the list can be narrowed.
        $this->assertStringContainsString('data-supplier=', $html);
    }

    public function test_loading_the_form_tops_the_pool_up(): void
    {
        $supplier = $this->supplier('Tops Up Refinery');

        $this->actingAs($this->purchasing)
            ->get(route('stock-orders.create-supplier-po'))
            ->assertOk();

        $this->assertGreaterThanOrEqual(
            PoNumber::POOL_TARGET,
            PoNumber::where('supplier_id', $supplier->id)->available()->count(),
            'The dropdown must never be empty.'
        );
    }

    // ---- Spending a number ------------------------------------------------

    public function test_saving_a_po_spends_its_number(): void
    {
        $supplier = $this->supplier('Spend Test Refinery');
        $poNumber = PoNumber::issueFor($supplier);

        $this->actingAs($this->purchasing)->post(route('stock-orders.store-supplier-po'), [
            'po_number' => $poNumber,
            'supplier_id' => $supplier->id,
            'items' => [['product' => 'Diesel', 'quantity' => 1000, 'unit_price' => 50]],
        ])->assertSessionHas('success');

        $po = PurchaseOrder::where('po_number', $poNumber)->firstOrFail();

        $pooled = PoNumber::where('po_number', $poNumber)->firstOrFail();

        $this->assertFalse($pooled->isAvailable());
        $this->assertSame($po->id, $pooled->used_by_purchase_order_id);
        $this->assertNotNull($pooled->reserved_at);
    }

    public function test_a_spent_number_cannot_be_used_again(): void
    {
        $supplier = $this->supplier('Reuse Test Refinery');
        $poNumber = PoNumber::issueFor($supplier);

        $payload = [
            'supplier_id' => $supplier->id,
            'items' => [['product' => 'Diesel', 'quantity' => 1000, 'unit_price' => 50]],
        ];

        $this->actingAs($this->purchasing)
            ->post(route('stock-orders.store-supplier-po'), $payload + ['po_number' => $poNumber])
            ->assertSessionHas('success');

        $this->actingAs($this->purchasing)
            ->post(route('stock-orders.store-supplier-po'), $payload + ['po_number' => $poNumber])
            ->assertSessionHasErrors('po_number');

        $this->assertSame(1, PurchaseOrder::where('po_number', $poNumber)->count());
    }

    public function test_a_number_that_was_never_generated_is_refused(): void
    {
        $supplier = $this->supplier('Unknown Number Refinery');

        $this->actingAs($this->purchasing)
            ->post(route('stock-orders.store-supplier-po'), [
                'po_number' => 'PO-MADE-UP-001',
                'supplier_id' => $supplier->id,
                'items' => [['product' => 'Diesel', 'quantity' => 1000, 'unit_price' => 50]],
            ])
            ->assertSessionHasErrors('po_number');
    }

    public function test_a_number_belonging_to_another_refinery_is_refused(): void
    {
        $mine = $this->supplier('Mine Refinery');
        $theirs = $this->supplier('Theirs Refinery');

        $theirNumber = PoNumber::issueFor($theirs);

        $this->actingAs($this->purchasing)
            ->post(route('stock-orders.store-supplier-po'), [
                'po_number' => $theirNumber,
                'supplier_id' => $mine->id,
                'items' => [['product' => 'Diesel', 'quantity' => 1000, 'unit_price' => 50]],
            ])
            ->assertSessionHasErrors('po_number');

        $this->assertSame(0, PurchaseOrder::count());
    }

    public function test_an_approved_po_keeps_its_number_locked_on_edit(): void
    {
        $supplier = $this->supplier('Locked Test Refinery');
        $poNumber = PoNumber::issueFor($supplier);

        $this->actingAs($this->purchasing)->post(route('stock-orders.store-supplier-po'), [
            'po_number' => $poNumber,
            'supplier_id' => $supplier->id,
            'items' => [['product' => 'Diesel', 'quantity' => 1000, 'unit_price' => 50]],
        ])->assertSessionHas('success');

        $po = PurchaseOrder::where('po_number', $poNumber)->firstOrFail();

        // The VP approves it, which is what locks the number.
        $vp = Admin::create([
            'username' => 'vp_'.uniqid(),
            'password' => bcrypt('secret'),
            'name' => 'Lorraine Bamba',
            'role' => 'vp',
        ]);

        $this->actingAs($vp)
            ->post(route('stock-orders.approve', $po->id))
            ->assertRedirect();

        $this->assertTrue($po->fresh()->isVpApproved());

        $html = $this->actingAs($this->purchasing)
            ->get(route('stock-orders.edit-supplier-po', $po->id))
            ->assertOk()
            ->getContent();

        // A locked number shows as a read-only field, not a dropdown, so it
        // can never drift from the PO the VP approved.
        $this->assertMatchesRegularExpression('/<input[^>]*name="po_number"[^>]*readonly/', $html);
    }

    public function test_an_unapproved_po_can_still_be_renumbered_from_the_pool(): void
    {
        $supplier = $this->supplier('Renumber Test Refinery');
        $poNumber = PoNumber::issueFor($supplier);

        $this->actingAs($this->purchasing)->post(route('stock-orders.store-supplier-po'), [
            'po_number' => $poNumber,
            'supplier_id' => $supplier->id,
            'items' => [['product' => 'Diesel', 'quantity' => 1000, 'unit_price' => 50]],
        ])->assertSessionHas('success');

        $po = PurchaseOrder::where('po_number', $poNumber)->firstOrFail();

        $html = $this->actingAs($this->purchasing)
            ->get(route('stock-orders.edit-supplier-po', $po->id))
            ->assertOk()
            ->getContent();

        // Still a dropdown, and its own spent number is offered back so the
        // field is never blank.
        $this->assertMatchesRegularExpression('/<select[^>]*name="po_number"/', $html);
        $this->assertStringContainsString($poNumber, $html);
    }
}