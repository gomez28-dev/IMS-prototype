<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderDelivery;
use App\Models\Warehouse;
use Tests\TestCase;

class AtlPdfGenerationTest extends TestCase
{
    protected Admin $purchasingUser;
    protected Admin $vpUser;
    protected Admin $opsUser;
    protected Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();

        $this->warehouse = Warehouse::create([
            'name' => 'San Simon Depot ' . uniqid(),
            'location' => 'San Simon',
        ]);

        $this->purchasingUser = Admin::create([
            'username' => 'purchasing_' . uniqid(),
            'password' => bcrypt('secret'),
            'name' => 'Rica Esaga',
            'role' => 'purchasing',
        ]);

        $this->vpUser = Admin::create([
            'username' => 'vp_' . uniqid(),
            'password' => bcrypt('secret'),
            'name' => 'Bernice Nikki Lee',
            'role' => 'vp',
        ]);

        $this->opsUser = Admin::create([
            'username' => 'ops_' . uniqid(),
            'password' => bcrypt('secret'),
            'name' => 'Ops User',
            'role' => 'ops_wh',
        ]);
    }

    public function test_purchasing_user_can_download_atl_pdf(): void
    {
        $po = PurchaseOrder::create([
            'po_number' => 'PO-ATL-PDF-1',
            'supplier_name' => 'Petron Bataan Refinery',
            'warehouse_id' => $this->warehouse->id,
            'qty_ordered' => 20000,
            'request_status' => 'CONFIRMED',
            'requested_by' => $this->opsUser->id,
            'approved_by' => $this->vpUser->id,
            'approved_at' => now(),
        ]);

        $delivery = PurchaseOrderDelivery::create([
            'purchase_order_id' => $po->id,
            'delivery_channel' => 'SUPPLIER_DOYEN_PICKUP',
            'order_type' => 'PICK_UP',
            'atl_type' => 'DITC_ATL',
            'atl_number' => 'ATL-2026-PDF01',
            'reference_no' => 'REF-9900',
            'so_number' => 'SO-1122',
            'product' => 'Diesel',
            'qty_to_receive' => 20000,
            'receiving_date' => now()->format('Y-m-d'),
            'driver_name' => 'Driver Juan',
            'plate_number' => 'NBC-1234',
            'location' => 'Bataan Terminal',
            'additional_remarks' => 'Check seal before departure',
            'status' => 'Pending',
        ]);

        $response = $this->actingAs($this->purchasingUser)
            ->get(route('stock-orders.pdf', $delivery->id));

        $response->assertStatus(200);
        $response->assertHeader('content-type', 'application/pdf');
    }
}
