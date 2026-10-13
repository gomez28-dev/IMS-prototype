<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\StockRequest;
use App\Models\StockRequestLine;
use App\Models\Supplier;
use App\Models\Warehouse;
use Tests\TestCase;

/**
 * The supervisor removed "Products Required by this Order" and the Create ATL
 * button from the PO details page: the per-product volume cards already carry
 * the requirement, and ATLs are raised from Sales Orders and Stock Requests.
 */
class PurchaseOrderLowerSectionTest extends TestCase
{
    protected Admin $purchasing;

    protected Admin $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->purchasing = Admin::create([
            'username' => 'purchasing_'.uniqid(),
            'password' => bcrypt('secret'),
            'name' => 'Rica Esaga',
            'role' => 'purchasing',
        ]);

        // Module 2's routes sit behind a role: middleware that does not include
        // 'purchasing'. Only an admin can both open the Stock Requests page and
        // raise an ATL, so that is the role the button is asserted against.
        $this->admin = Admin::create([
            'username' => 'admin_'.uniqid(),
            'password' => bcrypt('secret'),
            'name' => 'System Administrator',
            'role' => 'admin',
        ]);
    }

    protected function po(string $status = 'CONFIRMED'): PurchaseOrder
    {
        $supplier = Supplier::create([
            'company_name' => 'Lower Section Refinery '.rand(1000, 9999),
            'location' => 'Limay Terminal',
        ]);

        $po = PurchaseOrder::create([
            'po_number' => 'PO-LOWER-'.rand(1000, 9999),
            'po_type' => PurchaseOrder::TYPE_STANDARD_REPLENISHMENT,
            'supplier_id' => $supplier->id,
            'supplier_name' => $supplier->company_name,
            'po_date' => now()->toDateString(),
            'qty_ordered' => 80000,
            'request_status' => $status,
        ]);

        PurchaseOrderItem::create([
            'purchase_order_id' => $po->id,
            'product' => 'Diesel',
            'quantity_ordered' => 80000,
        ]);

        return $po;
    }

    protected function stockRequest(?PurchaseOrder $po = null, ?Warehouse $warehouse = null): StockRequest
    {
        $warehouse ??= Warehouse::first() ?? Warehouse::create(['name' => 'Bataan Depot '.rand(1000, 9999)]);

        $request = StockRequest::create([
            'request_type' => StockRequest::TYPE_PICKUP,
            'warehouse_id' => $warehouse->id,
            'qty_requested' => 50000,
            'status' => StockRequest::STATUS_REQUESTED,
            'requested_by' => $this->purchasing->id,
        ]);

        StockRequestLine::create([
            'stock_request_id' => $request->id,
            'product' => 'Diesel',
            'qty_requested' => 50000,
        ]);

        if ($po) {
            $request->purchase_order_id = $po->id;
            $request->save();
        }

        return $request->fresh();
    }

    // ---- PO details --------------------------------------------------------

    public function test_products_required_is_gone_from_the_po_details_page(): void
    {
        $html = $this->actingAs($this->purchasing)
            ->get(route('stock-orders.purchase-orders.show', $this->po()->id))
            ->assertOk()
            ->getContent();

        // The three volume cards above already state the requirement per product.
        $this->assertStringNotContainsString('Products Required by this Order', $html);
    }

    public function test_the_po_details_page_offers_no_create_atl_button(): void
    {
        $po = $this->po('CONFIRMED');

        $html = $this->actingAs($this->purchasing)
            ->get(route('stock-orders.purchase-orders.show', $po->id))
            ->assertOk()
            ->getContent();

        // ATLs are raised from Sales Orders and Stock Requests now.
        $this->assertStringNotContainsString(route('stock-orders.add-atl', $po->id), $html);
    }

    // ---- Stock Requests ----------------------------------------------------

    public function test_a_prepared_and_approved_request_offers_add_atl(): void
    {
        $request = $this->stockRequest($this->po('CONFIRMED'));

        $html = $this->actingAs($this->admin)
            ->get(route('wetstock.stock-requests.index'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString(route('stock-orders.add-atl', $request->purchase_order_id), $html);
    }

    public function test_a_request_without_a_po_offers_no_add_atl(): void
    {
        $this->stockRequest();

        $html = $this->actingAs($this->admin)
            ->get(route('wetstock.stock-requests.index'))
            ->assertOk()
            ->getContent();

        // Nothing has been raised against yet, so there is nothing to allocate from.
        $this->assertStringNotContainsString('stock-orders.add-atl', $html);
    }

    public function test_a_request_whose_po_is_not_approved_offers_no_add_atl(): void
    {
        $this->stockRequest($this->po('RECEIVED'));

        $html = $this->actingAs($this->admin)
            ->get(route('wetstock.stock-requests.index'))
            ->assertOk()
            ->getContent();

        // createAtl refuses a PO the VP has not approved, so the button would
        // only ever 403.
        $this->assertStringNotContainsString('stock-orders.add-atl', $html);
    }
}
