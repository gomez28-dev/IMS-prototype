<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\Supplier;
use Tests\TestCase;

class PoPdfApprovalGateTest extends TestCase
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

    protected function poWithStatus(string $requestStatus): PurchaseOrder
    {
        // The supplier is shared: company_name is unique.
        $supplier = Supplier::first() ?? Supplier::create([
            'company_name' => 'Petron Bataan Refinery',
            'location' => 'Limay',
        ]);

        $po = PurchaseOrder::create([
            'po_number' => 'PO-PDF-'.uniqid(),
            'po_type' => 'STANDARD_REPLENISHMENT',
            'supplier_id' => $supplier->id,
            'supplier_name' => $supplier->company_name,
            'qty_ordered' => 10000,
            'total_amount' => 500000.00,
            'net_payable_amount' => 500000.00,
            'request_status' => $requestStatus,
            'status' => 'Pending',
            'prepared_by' => $this->purchasing->id,
        ]);

        PurchaseOrderItem::create([
            'purchase_order_id' => $po->id,
            'product' => 'Diesel',
            'quantity_ordered' => 10000,
            'unit_price' => 50.00,
        ]);

        return $po;
    }

    /**
     * @dataProvider unapprovedStatuses
     */
    public function test_po_pdf_is_blocked_before_vp_approval(string $requestStatus): void
    {
        $po = $this->poWithStatus($requestStatus);

        // Reached by typing the URL directly, not via the list.
        $this->actingAs($this->purchasing)
            ->get(route('stock-orders.po-pdf', $po->id))
            ->assertStatus(403);
    }

    /**
     * @return array<string, array<int, string>>
     */
    public static function unapprovedStatuses(): array
    {
        return [
            'requested' => ['REQUESTED'],
            'pending vp approval' => ['RECEIVED'],
        ];
    }

    /**
     * @dataProvider approvedStatuses
     */
    public function test_po_pdf_is_available_once_the_vp_approved(string $requestStatus): void
    {
        $po = $this->poWithStatus($requestStatus);

        $this->actingAs($this->purchasing)
            ->get(route('stock-orders.po-pdf', $po->id))
            ->assertStatus(200);
    }

    /**
     * @return array<string, array<int, string>>
     */
    public static function approvedStatuses(): array
    {
        return [
            'confirmed' => ['CONFIRMED'],
            'for delivery' => ['FOR_DELIVERY'],
            'completed' => ['COMPLETED'],
        ];
    }

    public function test_approved_helper_matches_the_approval_states(): void
    {
        $this->assertTrue($this->poWithStatus('CONFIRMED')->isVpApproved());
        $this->assertTrue($this->poWithStatus('FOR_DELIVERY')->isVpApproved());
        $this->assertTrue($this->poWithStatus('COMPLETED')->isVpApproved());

        $this->assertFalse($this->poWithStatus('REQUESTED')->isVpApproved());
        $this->assertFalse($this->poWithStatus('RECEIVED')->isVpApproved());
    }
}