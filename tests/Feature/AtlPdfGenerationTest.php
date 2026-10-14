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
            // The ATL is its own document and has to be approved in its own right,
            // even when the PO it draws on was approved weeks earlier.
            'approval_status' => PurchaseOrderDelivery::APPROVAL_APPROVED,
        ]);

        $response = $this->actingAs($this->purchasingUser)
            ->get(route('stock-orders.pdf', $delivery->id));

        $response->assertStatus(200);
        $response->assertHeader('content-type', 'application/pdf');
    }

    public function test_an_unapproved_atl_cannot_print_itself_off_an_approved_po(): void
    {
        $po = PurchaseOrder::create([
            'po_number' => 'PO-ATL-PDF-APPROVED',
            'supplier_name' => 'Petron Bataan Refinery',
            'warehouse_id' => $this->warehouse->id,
            'qty_ordered' => 20000,
            'request_status' => 'CONFIRMED',
            'requested_by' => $this->opsUser->id,
            'approved_by' => $this->vpUser->id,
            'approved_at' => now(),
        ]);

        foreach ([
            PurchaseOrderDelivery::APPROVAL_DRAFT,
            PurchaseOrderDelivery::APPROVAL_FOR_APPROVAL,
            null,
        ] as $index => $status) {
            $delivery = PurchaseOrderDelivery::create([
                'purchase_order_id' => $po->id,
                'delivery_channel' => 'SUPPLIER_DOYEN_PICKUP',
                'order_type' => 'PICK_UP',
                'atl_type' => 'DITC_ATL',
                'atl_number' => 'ATL-UNAPPROVED-'.$index,
                'product' => 'Diesel',
                'qty_to_receive' => 20000,
                'receiving_date' => now()->format('Y-m-d'),
                'status' => 'Pending',
                'approval_status' => $status,
            ]);

            // The old rule was an `elseif`: an ATL sitting on a VP-approved PO was
            // taken as approved by association and never checked itself. That let
            // a draft or a pending ATL hand out a signed-looking document.
            $this->actingAs($this->purchasingUser)
                ->get(route('stock-orders.pdf', $delivery->id))
                ->assertForbidden();
        }
    }

    public function test_an_approved_atl_on_an_unapproved_po_is_refused(): void
    {
        $po = PurchaseOrder::create([
            'po_number' => 'PO-ATL-PDF-PENDING',
            'supplier_name' => 'Petron Bataan Refinery',
            'warehouse_id' => $this->warehouse->id,
            'qty_ordered' => 20000,
            // Still in the Approvals queue: the VP has not signed this off.
            'request_status' => 'RECEIVED',
            'requested_by' => $this->opsUser->id,
        ]);

        $delivery = PurchaseOrderDelivery::create([
            'purchase_order_id' => $po->id,
            'delivery_channel' => 'SUPPLIER_DOYEN_PICKUP',
            'order_type' => 'PICK_UP',
            'atl_type' => 'DITC_ATL',
            'atl_number' => 'ATL-ON-PENDING-PO',
            'product' => 'Diesel',
            'qty_to_receive' => 20000,
            'receiving_date' => now()->format('Y-m-d'),
            'status' => 'Pending',
            'approval_status' => PurchaseOrderDelivery::APPROVAL_APPROVED,
        ]);

        $this->actingAs($this->purchasingUser)
            ->get(route('stock-orders.pdf', $delivery->id))
            ->assertForbidden();
    }
}
