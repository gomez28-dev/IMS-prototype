<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\SupplierOrder;
use App\Models\Warehouse;
use Tests\TestCase;

/**
 * The Incoming Supplier Stock header once offered three buttons; the
 * Request Replenishment button left it, leaving Stock Requests and Add
 * Incoming Stock.
 *
 * Pinned by rendered route URLs, not button text: "Stock Requests" also
 * lives in the sidebar, so the ?create=1 deep link is the only thing
 * that proves which button carried it - and it renders nowhere else on
 * this page.
 */
class SupplierOrdersHeaderButtonsTest extends TestCase
{
    public function test_the_header_keeps_stock_requests_and_add_incoming_but_not_replenishment(): void
    {
        $admin = Admin::create([
            'username' => 'sohdr_' . uniqid(),
            'password' => bcrypt('secret'),
            'name' => 'Ops Officer',
            'role' => 'ops_admin',
        ]);

        $warehouse = Warehouse::create(['name' => 'Supplier Header Site ' . rand(1000, 9999)]);
        SupplierOrder::create([
            'warehouse_id' => $warehouse->id,
            'supplier_name' => 'Petron Bataan',
            'po_number' => 'PO-SOHR-' . rand(1000, 9999),
            'liters' => 5000,
            'status' => 'Pending',
            'created_by' => $admin->id,
            'remarks' => null,
        ]);

        $html = $this->actingAs($admin)
            ->get(route('wetstock.supplier-orders.index'))
            ->assertOk()
            ->getContent();

        // Request Replenishment went to the stock requests pop up armed
        // with ?create=1; until it goes, both the text and the deep link
        // are on the page.
        $this->assertStringNotContainsString('Request Replenishment', $html);
        $this->assertStringNotContainsString('create=1', $html);

        // The other two header buttons survive, Stock Requests without
        // the flag.
        $this->assertStringContainsString(route('wetstock.stock-requests.index'), $html);
        $this->assertStringContainsString(route('wetstock.supplier-orders.create'), $html);
    }
}
