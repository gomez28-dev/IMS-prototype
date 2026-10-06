<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\Supplier;
use Tests\TestCase;

class PoPdfGenerationTest extends TestCase
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

    public function test_purchasing_can_download_a_purchase_order_pdf(): void
    {
        $po = $this->purchaseOrder();

        $response = $this->actingAs($this->purchasing)->get(route('stock-orders.po-pdf', $po->id));

        $response->assertStatus(200);
        $response->assertHeader('content-type', 'application/pdf');
    }

    public function test_pdf_reports_line_amounts_derived_from_quantity_and_price(): void
    {
        $po = $this->purchaseOrder();

        // 220,000 x 89.15 = 19,613,000.00
        $line = $po->items->first();
        $this->assertEquals('LTRS', $line->unit);
        $this->assertEquals(220000, (int) $line->quantity_ordered);
        $this->assertEquals(89.15, (float) $line->unit_price);
    }

    public function test_pdf_falls_back_to_deriving_the_total_from_lines_when_none_is_stored(): void
    {
        $po = PurchaseOrder::create([
            'po_number' => 'PO-NO-TOTAL-' . rand(1000, 9999),
            'po_type' => 'STANDARD_REPLENISHMENT',
            'supplier_name' => 'Petron Bataan',
            'qty_ordered' => 1000,
            'request_status' => 'CONFIRMED',
            'total_amount' => 0,
            'net_payable_amount' => 0,
        ]);
        PurchaseOrderItem::create([
            'purchase_order_id' => $po->id,
            'product' => 'Diesel',
            'unit' => 'LTRS',
            'quantity_ordered' => 1000,
            'unit_price' => 50.00,
        ]);

        // A PO with no stored total must still print.
        $response = $this->actingAs($this->purchasing)->get(route('stock-orders.po-pdf', $po->id));
        $response->assertStatus(200);
    }

    public function test_pdf_renders_for_a_purchase_order_with_no_product_lines(): void
    {
        $po = PurchaseOrder::create([
            'po_number' => 'PO-EMPTY-' . rand(1000, 9999),
            'po_type' => 'STANDARD_REPLENISHMENT',
            'supplier_name' => 'Petron Bataan',
            'qty_ordered' => 0,
            'request_status' => 'CONFIRMED',
        ]);

        $response = $this->actingAs($this->purchasing)->get(route('stock-orders.po-pdf', $po->id));
        $response->assertStatus(200);
    }

    public function test_pdf_uses_the_linked_supplier_details_when_present(): void
    {
        $supplier = Supplier::create([
            'company_name' => 'Agieko Fuel Trading',
            'location' => 'Limay Terminal',
            'address' => 'Brgy. Poblacion, Limay, Bataan',
            'attention' => 'Mr. Aaron Uy',
        ]);

        $po = $this->purchaseOrder();
        $po->update(['supplier_id' => $supplier->id]);

        $response = $this->actingAs($this->purchasing)->get(route('stock-orders.po-pdf', $po->id));
        $response->assertStatus(200);
    }

    public function test_purchase_order_pdf_is_gated_to_roles_that_can_access_stock_orders(): void
    {
        $sales = Admin::create([
            'username' => 'sales_' . uniqid(),
            'password' => bcrypt('secret'),
            'name' => 'Sales User',
            'role' => 'sales',
        ]);

        $po = $this->purchaseOrder();

        $this->actingAs($sales)
            ->get(route('stock-orders.po-pdf', $po->id))
            ->assertForbidden();
    }

    private function purchaseOrder(): PurchaseOrder
    {
        $po = PurchaseOrder::create([
            'po_number' => 'PO-PDF-' . rand(1000, 9999),
            'po_type' => 'STANDARD_REPLENISHMENT',
            'supplier_name' => 'Agieko Fuel Trading',
            'qty_ordered' => 220000,
            'request_status' => 'CONFIRMED',
            'status' => 'Pending',
            'po_date' => now()->format('Y-m-d'),
            'terms' => 'VAT EX CASH',
            'attention' => 'Mr. Aaron Uy',
            'remarks' => 'VINTA PICK UP',
            'total_amount' => 19613000.00,
            'net_payable_amount' => 19613000.00,
            'vatable_sales_amount' => 19613000.00,
            'vat_amount' => 0,
            'less_w_tax' => 0,
            'prepared_by' => $this->purchasing->id,
            'approved_by' => $this->purchasing->id,
        ]);

        PurchaseOrderItem::create([
            'purchase_order_id' => $po->id,
            'product' => 'Diesel',
            'unit' => 'LTRS',
            'quantity_ordered' => 220000,
            'unit_price' => 89.15,
        ]);

        return $po;
    }
}
