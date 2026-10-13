<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\Supplier;
use App\Models\Warehouse;
use Tests\TestCase;

/**
 * The supervisor narrowed the Create Supplier Purchase Order form down to four
 * things: a typed Official PO Number, the Company, its Location, and the Target
 * Availability Date. PO Classification and Destination Depot come off.
 */
class CreateSupplierPoFormTest extends TestCase
{
    protected Admin $purchasing;

    protected function setUp(): void
    {
        parent::setUp();

        $this->purchasing = Admin::create([
            'username' => 'purchasing_'.uniqid(),
            'password' => bcrypt('secret'),
            'name' => 'Rica Esaga',
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

    protected function createHtml(): string
    {
        return $this->actingAs($this->purchasing)
            ->get(route('stock-orders.create-supplier-po'))
            ->assertOk()
            ->getContent();
    }

    // ---- What stays --------------------------------------------------------

    public function test_the_form_keeps_the_four_fields_the_supervisor_asked_for(): void
    {
        $html = $this->createHtml();

        $this->assertStringContainsString('Official PO Number', $html);
        $this->assertStringContainsString('name="supplier_id"', $html);
        $this->assertStringContainsString('id="supplier_location"', $html);
        $this->assertStringContainsString('Target Availability Date', $html);
    }

    public function test_the_po_number_is_typed_not_chosen(): void
    {
        $html = $this->createHtml();

        // Nothing is picked from a pool, so a clash or a typo is impossible to
        // make by accident.
        $this->assertMatchesRegularExpression('/<input[^>]*name="po_number"/', $html);
        $this->assertDoesNotMatchRegularExpression('/<select[^>]*name="po_number"/', $html);
    }

    public function test_product_volume_allocations_stay(): void
    {
        // Everything not named for removal is left alone.
        $this->assertStringContainsString('items[0][product]', $this->createHtml());
    }

    // ---- What goes ---------------------------------------------------------

    public function test_po_classification_is_gone(): void
    {
        // The controller hardcodes STANDARD_REPLENISHMENT, so the field only
        // ever showed a value nobody could change.
        $this->assertStringNotContainsString('PO Classification', $this->createHtml());
    }

    public function test_destination_depot_is_gone(): void
    {
        $html = $this->createHtml();

        $this->assertStringNotContainsString('Destination Depot', $html);
        $this->assertStringNotContainsString('name="warehouse_id"', $html);
    }

    // ---- Editing -----------------------------------------------------------

    public function test_an_unapproved_po_cannot_have_its_number_changed(): void
    {
        $supplier = $this->supplier('Editable Number Refinery');
        $po = PurchaseOrder::create([
            'po_number' => 'PO-EDITABLE-001',
            'po_type' => PurchaseOrder::TYPE_STANDARD_REPLENISHMENT,
            'supplier_id' => $supplier->id,
            'supplier_name' => $supplier->company_name,
            'po_date' => now()->toDateString(),
            'qty_ordered' => 1000,
        ]);
        PurchaseOrderItem::create([
            'purchase_order_id' => $po->id,
            'product' => 'Diesel',
            'quantity_ordered' => 1000,
        ]);

        $html = $this->actingAs($this->purchasing)
            ->get(route('stock-orders.edit-supplier-po', $po->id))
            ->assertOk()
            ->getContent();

        // updateSupplierPo has no po_number rule, so the field is shown but
        // its value is thrown away. Showing it editable would be a lie.
        $this->assertMatchesRegularExpression('/<input[^>]*name="po_number"[^>]*readonly/', $html);
    }

    // ---- Regression --------------------------------------------------------

    public function test_a_po_can_still_be_created_with_a_typed_number(): void
    {
        $supplier = $this->supplier('Typed Number Refinery');
        Warehouse::create(['name' => 'Bataan Depot '.rand(1000, 9999)]);

        $this->actingAs($this->purchasing)->post(route('stock-orders.store-supplier-po'), [
            'po_number' => 'PO-TYPED-'.rand(1000, 9999),
            'supplier_id' => $supplier->id,
            'items' => [['product' => 'Diesel', 'quantity' => 1000, 'unit_price' => 50]],
        ])->assertSessionHas('success');

        $this->assertDatabaseHas('purchase_orders', [
            'supplier_id' => $supplier->id,
            'po_type' => PurchaseOrder::TYPE_STANDARD_REPLENISHMENT,
        ]);
    }
}
