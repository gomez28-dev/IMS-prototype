<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\Supplier;
use Tests\TestCase;

class SupplierPurchaseOrderTest extends TestCase
{
    protected Admin $purchasing;

    protected function setUp(): void
    {
        parent::setUp();

        $this->purchasing = Admin::create([
            'username' => 'purchasing_' . uniqid(),
            'password' => bcrypt('secret'),
            'name' => 'Rica Esaga',
            'role' => 'purchasing',
        ]);
    }

    public function test_supplier_po_links_to_a_supplier_and_mirrors_its_name(): void
    {
        $supplier = Supplier::create([
            'company_name' => 'Agieko Fuel Trading',
            'location' => 'Limay Terminal',
            'attention' => 'Mr. Aaron Uy',
        ]);

        $response = $this->actingAs($this->purchasing)->post(route('stock-orders.store-supplier-po'), [
            'po_number' => 'PO-DITCF-26-0651',
            'supplier_id' => $supplier->id,
            'po_type' => 'STANDARD_REPLENISHMENT',
            'terms' => 'VAT EX CASH',
            'po_date' => '2026-09-16',
            'items' => [
                ['product' => 'Diesel', 'quantity' => 220000, 'unit_price' => 89.15],
            ],
        ]);

        $response->assertRedirect(route('stock-orders.purchase-orders.index'));
        $response->assertSessionHas('success');

        $po = PurchaseOrder::where('po_number', 'PO-DITCF-26-0651')->firstOrFail();

        $this->assertEquals($supplier->id, $po->supplier_id);
        // Legacy mirror so existing screens keep working.
        $this->assertEquals('Agieko Fuel Trading', $po->supplier_name);
        // Attention defaults to the supplier contact.
        $this->assertEquals('Mr. Aaron Uy', $po->attention);
        $this->assertEquals('VAT EX CASH', $po->terms);
        $this->assertEquals('2026-09-16', $po->po_date->format('Y-m-d'));
        $this->assertEquals($this->purchasing->id, $po->prepared_by);
    }

    public function test_total_and_net_payable_are_derived_from_product_lines(): void
    {
        $supplier = Supplier::create([
            'company_name' => 'Agieko Fuel Trading',
            'location' => 'Limay Terminal',
        ]);

        $this->actingAs($this->purchasing)->post(route('stock-orders.store-supplier-po'), [
            'po_number' => 'PO-TOTALS-' . rand(1000, 9999),
            'supplier_id' => $supplier->id,
            'po_type' => 'STANDARD_REPLENISHMENT',
            'items' => [
                // 220,000 x 89.15 = 19,613,000.00 (matches the sample form)
                ['product' => 'Diesel', 'quantity' => 220000, 'unit_price' => 89.15],
                ['product' => 'Premium', 'quantity' => 10000, 'unit_price' => 95.50],
            ],
        ])->assertSessionHas('success');

        $po = PurchaseOrder::latest('id')->firstOrFail();

        // 19,613,000.00 + 955,000.00
        $this->assertEquals(20568000.00, (float) $po->total_amount);
        // Net payable defaults to the total when left blank.
        $this->assertEquals(20568000.00, (float) $po->net_payable_amount);
        $this->assertEquals(230000, $po->qty_ordered);
        $this->assertCount(2, $po->items);
    }

    public function test_manual_vat_and_withholding_tax_values_are_stored(): void
    {
        $supplier = Supplier::create([
            'company_name' => 'Agieko Fuel Trading',
            'location' => 'Limay Terminal',
        ]);

        $this->actingAs($this->purchasing)->post(route('stock-orders.store-supplier-po'), [
            'po_number' => 'PO-VAT-' . rand(1000, 9999),
            'supplier_id' => $supplier->id,
            'po_type' => 'STANDARD_REPLENISHMENT',
            'vatable_sales_amount' => 19613000.00,
            'vat_amount' => 2931950.00,
            'less_w_tax' => 1176780.00,
            'net_payable_amount' => 21368200.00,
            'items' => [
                ['product' => 'Diesel', 'quantity' => 220000, 'unit_price' => 89.15],
            ],
        ])->assertSessionHas('success');

        $po = PurchaseOrder::latest('id')->firstOrFail();

        $this->assertEquals(19613000.00, (float) $po->vatable_sales_amount);
        $this->assertEquals(2931950.00, (float) $po->vat_amount);
        $this->assertEquals(1176780.00, (float) $po->less_w_tax);
        // Manual value wins over the derived default.
        $this->assertEquals(21368200.00, (float) $po->net_payable_amount);
    }

    public function test_supplier_is_required_when_creating_a_purchase_order(): void
    {
        $this->actingAs($this->purchasing)->post(route('stock-orders.store-supplier-po'), [
            'po_number' => 'PO-NOSUP-' . rand(1000, 9999),
            'po_type' => 'STANDARD_REPLENISHMENT',
            'items' => [
                ['product' => 'Diesel', 'quantity' => 1000, 'unit_price' => 50],
            ],
        ])->assertSessionHasErrors(['supplier_id']);
    }

    public function test_deactivated_supplier_cannot_be_selected_on_a_new_purchase_order(): void
    {
        $active = Supplier::create(['company_name' => 'Active Supplier', 'location' => 'A']);
        Supplier::create(['company_name' => 'Retired Supplier', 'location' => 'B', 'is_active' => false]);

        // The form only offers active suppliers.
        $response = $this->actingAs($this->purchasing)->get(route('stock-orders.create-supplier-po'));
        $response->assertStatus(200);
        $response->assertSee('Active Supplier');
        $response->assertDontSee('Retired Supplier');

        // Rejected server-side too, so a crafted POST cannot use one.
        $this->actingAs($this->purchasing)->post(route('stock-orders.store-supplier-po'), [
            'po_number' => 'PO-RET-' . rand(1000, 9999),
            'supplier_id' => Supplier::where('company_name', 'Retired Supplier')->value('id'),
            'po_type' => 'STANDARD_REPLENISHMENT',
            'items' => [['product' => 'Diesel', 'quantity' => 100, 'unit_price' => 50]],
        ])->assertSessionHasErrors(['supplier_id']);
    }

    public function test_purchase_order_can_still_draw_down_after_the_supplier_change(): void
    {
        $supplier = Supplier::create([
            'company_name' => 'Petron Bataan Refinery',
            'location' => 'Limay',
        ]);

        $po = PurchaseOrder::create([
            'po_number' => 'PO-DRAW-' . rand(1000, 9999),
            'po_type' => 'STANDARD_REPLENISHMENT',
            'supplier_id' => $supplier->id,
            'supplier_name' => $supplier->company_name,
            'qty_ordered' => 40000,
            'request_status' => 'CONFIRMED',
            'status' => 'Pending',
        ]);
        PurchaseOrderItem::create([
            'purchase_order_id' => $po->id,
            'product' => 'Diesel',
            'quantity_ordered' => 40000,
            'unit_price' => 50.00,
        ]);

        // Per-product remaining balance still works with the new columns present.
        $this->assertEquals(40000, $po->getAvailableBalanceForProduct('Diesel'));
        $this->assertEquals($supplier->company_name, $po->supplier->company_name);
    }
}
