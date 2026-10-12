<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\AtlAllocation;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderDelivery;
use App\Models\PurchaseOrderItem;
use App\Models\Supplier;
use Tests\TestCase;

class PurchaseOrderDetailPageTest extends TestCase
{
    protected Admin $purchasing;

    protected Supplier $supplier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->purchasing = Admin::create([
            'username' => 'purchasing_' . uniqid(),
            'password' => bcrypt('secret'),
            'name' => 'Rica Esaga',
            'role' => 'purchasing',
        ]);

        $this->supplier = Supplier::create([
            'company_name' => 'Petron Bataan Refinery',
            'location' => 'Limay',
        ]);
    }

    /**
     * @param  array<string, int>  $lines
     * @return array<int, PurchaseOrderItem>
     */
    protected function poWithLines(string $requestStatus, array $lines): PurchaseOrder
    {
        $po = PurchaseOrder::create([
            'po_number' => 'PO-DET-'.uniqid(),
            'po_type' => 'STANDARD_REPLENISHMENT',
            'supplier_id' => $this->supplier->id,
            'supplier_name' => $this->supplier->company_name,
            'qty_ordered' => array_sum($lines),
            'total_amount' => 500000.00,
            'request_status' => $requestStatus,
            'status' => 'Pending',
            'prepared_by' => $this->purchasing->id,
        ]);

        foreach ($lines as $product => $qty) {
            PurchaseOrderItem::create([
                'purchase_order_id' => $po->id,
                'product' => $product,
                'quantity_ordered' => $qty,
                'unit_price' => 50.00,
            ]);
        }

        return $po->fresh();
    }

    protected function delivery(
        PurchaseOrder $po,
        string $atlNumber,
        int $qty,
        string $liftStatus = PurchaseOrderDelivery::LIFT_UNLIFTED,
        array $allocations = []
    ): PurchaseOrderDelivery {
        $delivery = PurchaseOrderDelivery::create([
            'purchase_order_id' => $po->id,
            'delivery_channel' => 'TRUCKED',
            'order_type' => 'PICK_UP',
            'atl_type' => 'DITC_ATL',
            'atl_number' => $atlNumber,
            'product' => $allocations ? implode(' + ', array_keys($allocations)) : 'Diesel',
            'qty_to_receive' => $qty,
            'receiving_date' => now()->addDay(),
            'status' => 'Dispatched',
            'lift_status' => $liftStatus,
            'approval_status' => PurchaseOrderDelivery::APPROVAL_APPROVED,
            'prepared_by' => $this->purchasing->id,
        ]);

        foreach ($allocations as $product => $quantity) {
            AtlAllocation::create([
                'purchase_order_delivery_id' => $delivery->id,
                'purchase_order_id' => $po->id,
                'product' => $product,
                'quantity' => $quantity,
            ]);
        }

        return $delivery;
    }

    public function test_header_shows_the_po_number_supplier_and_a_back_link(): void
    {
        $po = $this->poWithLines('CONFIRMED', ['Diesel' => 10000]);

        $response = $this->actingAs($this->purchasing)
            ->get(route('stock-orders.purchase-orders.show', $po->id));

        $response->assertStatus(200);
        $response->assertSee($po->po_number);
        $response->assertSee('Petron Bataan Refinery');
        $response->assertSee('Back to Purchase Orders');
        $response->assertSee(route('stock-orders.purchase-orders.index'), false);
    }

    public function test_summary_cards_add_up_the_atl_and_lifted_volumes(): void
    {
        $po = $this->poWithLines('CONFIRMED', ['Diesel' => 20000, 'Premium' => 10000]);

        // Diesel 15,000 lifted, Premium 5,000 not lifted.
        $this->delivery($po, 'ATL-DET-1', 15000, PurchaseOrderDelivery::LIFT_LIFTED, [
            'Diesel' => 10000,
            'Premium' => 5000,
        ]);
        $this->delivery($po, 'ATL-DET-2', 10000, PurchaseOrderDelivery::LIFT_UNLIFTED, [
            'Diesel' => 5000,
        ]);

        $response = $this->actingAs($this->purchasing)
            ->get(route('stock-orders.purchase-orders.show', $po->id));

        $response->assertStatus(200);

        // Per-product cards, as on the Purchase Orders list.
        $response->assertSee('Total Qty. Ordered', false);
        $response->assertSee('Total Qty. Lifted', false);
        $response->assertSee('Total Qty. Unlifted', false);

        // Single-figure cards retained alongside them.
        $response->assertSee('Qty Ordered', false);
        $response->assertSee('Total ATL', false);
        $response->assertSee('Remaining', false);

        $this->assertStringContainsString('30,000', $response->getContent());
        $this->assertStringContainsString('25,000', $response->getContent());
        $this->assertStringContainsString('15,000', $response->getContent());
        $this->assertStringContainsString('5,000', $response->getContent());
    }

    public function test_per_product_cards_split_lifted_and_unlifted_volume(): void
    {
        $po = $this->poWithLines('CONFIRMED', ['Diesel' => 20000, 'Premium' => 10000]);

        // Diesel fully lifted, Premium ATL'd but still unlifted.
        $this->delivery($po, 'ATL-MIX-1', 20000, PurchaseOrderDelivery::LIFT_LIFTED, ['Diesel' => 15000]);
        $this->delivery($po, 'ATL-MIX-2', 15000, PurchaseOrderDelivery::LIFT_UNLIFTED, [
            'Diesel' => 5000,
            'Premium' => 15000,
        ]);

        $volumes = PurchaseOrder::volumeTotals($po->id);

        $this->assertEquals(['Diesel' => 20000, 'Unleaded' => 0, 'Premium' => 10000], $volumes['ordered']);
        // 15,000 Diesel lifted.
        $this->assertEquals(15000, $volumes['lifted']['Diesel']);
        // 5,000 Diesel and 15,000 Premium have an ATL but are not lifted yet.
        $this->assertEquals(5000, $volumes['unlifted']['Diesel']);
        $this->assertEquals(15000, $volumes['unlifted']['Premium']);
        // Premium was never lifted.
        $this->assertEquals(0, $volumes['lifted']['Premium']);
        // Unleaded has no rows at all, so it still reports zero.
        $this->assertEquals(0, $volumes['ordered']['Unleaded']);
    }

    public function test_atl_table_shows_supplier_type_qty_price_and_amount(): void
    {
        $po = $this->poWithLines('CONFIRMED', ['Diesel' => 20000]);

        // 10,000 L at 50.00 = 500,000.00
        $this->delivery($po, 'ATL-AMT-1', 10000, PurchaseOrderDelivery::LIFT_UNLIFTED, ['Diesel' => 10000]);

        $response = $this->actingAs($this->purchasing)
            ->get(route('stock-orders.purchase-orders.show', $po->id));

        $response->assertStatus(200);

        // Column headers from the reference design.
        $response->assertSee('Supplier');
        $response->assertSee('Amount');
        $response->assertSee('Actions');

        // The rate is derived from the PO line, not stored on the ATL.
        $this->assertStringContainsString('50.00', $response->getContent());
        $this->assertStringContainsString('500,000.00', $response->getContent());

        // The PO's own type label is what the Type column reports.
        $response->assertSee('Doyen Stock');
    }

    public function test_products_required_section_reports_atld_volume_and_a_badge(): void
    {
        $po = $this->poWithLines('CONFIRMED', ['Diesel' => 20000, 'Premium' => 10000]);

        // Diesel fully drawn, Premium not ATL'd at all.
        $this->delivery($po, 'ATL-DET-1', 20000, PurchaseOrderDelivery::LIFT_LIFTED, [
            'Diesel' => 20000,
        ]);

        $response = $this->actingAs($this->purchasing)
            ->get(route('stock-orders.purchase-orders.show', $po->id));

        $response->assertStatus(200);
        $response->assertSee('Products Required by this Order');
        $response->assertSee('Fully ATL', false);
        $response->assertSee('Not ATL', false);
    }

    public function test_atl_records_table_lists_every_atl_that_drew_from_this_po(): void
    {
        $po = $this->poWithLines('CONFIRMED', ['Diesel' => 20000]);

        $this->delivery($po, 'ATL-ROW-1', 10000, PurchaseOrderDelivery::LIFT_LIFTED, ['Diesel' => 10000]);
        $this->delivery($po, 'ATL-ROW-2', 5000, PurchaseOrderDelivery::LIFT_UNLIFTED, ['Diesel' => 5000]);

        $response = $this->actingAs($this->purchasing)
            ->get(route('stock-orders.purchase-orders.show', $po->id));

        $response->assertStatus(200);
        $response->assertSee('ATL Records');
        $response->assertSee('ATL-ROW-1');
        $response->assertSee('ATL-ROW-2');
        $response->assertSee('Amount');
        $response->assertDontSee('No ATLs issued for this PO yet.');
    }

    public function test_atl_search_filters_the_table_without_hiding_the_po(): void
    {
        $po = $this->poWithLines('CONFIRMED', ['Diesel' => 30000]);

        $this->delivery($po, 'ATL-FIND-1', 10000, PurchaseOrderDelivery::LIFT_UNLIFTED, ['Diesel' => 10000]);
        $this->delivery($po, 'ATL-OTHER-2', 10000, PurchaseOrderDelivery::LIFT_UNLIFTED, ['Diesel' => 10000]);

        $response = $this->actingAs($this->purchasing)->get(
            route('stock-orders.purchase-orders.show', [$po->id, 'atl_search' => 'FIND'])
        );

        $response->assertStatus(200);
        $response->assertSee('ATL-FIND-1');
        $response->assertDontSee('ATL-OTHER-2');

        // The PO's own summary still describes the whole PO, not the filtered rows.
        $this->assertStringContainsString('30,000', $response->getContent());

        // A search with no hits says so rather than claiming none exist.
        $this->actingAs($this->purchasing)->get(
            route('stock-orders.purchase-orders.show', [$po->id, 'atl_search' => 'nothing-matches'])
        )
            ->assertStatus(200)
            ->assertSee('No ATLs match your search.')
            ->assertDontSee('No ATLs issued for this PO yet.');
    }

    public function test_empty_state_is_shown_when_no_atls_exist(): void
    {
        $po = $this->poWithLines('REQUESTED', ['Diesel' => 10000]);

        $this->actingAs($this->purchasing)
            ->get(route('stock-orders.purchase-orders.show', $po->id))
            ->assertStatus(200)
            ->assertSee('No ATLs issued for this PO yet.');
    }

    public function test_po_pdf_button_is_gated_on_approval(): void
    {
        $pending = $this->poWithLines('RECEIVED', ['Diesel' => 10000]);
        $approved = $this->poWithLines('CONFIRMED', ['Diesel' => 10000]);

        $this->actingAs($this->purchasing)
            ->get(route('stock-orders.purchase-orders.show', $pending->id))
            ->assertDontSee(route('stock-orders.po-pdf', $pending->id), false);

        $this->actingAs($this->purchasing)
            ->get(route('stock-orders.purchase-orders.show', $approved->id))
            ->assertSee(route('stock-orders.po-pdf', $approved->id), false);
    }

    public function test_prepare_button_is_offered_on_requested_pos(): void
    {
        $requested = $this->poWithLines('REQUESTED', ['Diesel' => 10000]);
        $approved = $this->poWithLines('CONFIRMED', ['Diesel' => 10000]);

        $this->actingAs($this->purchasing)
            ->get(route('stock-orders.purchase-orders.show', $requested->id))
            ->assertSee(route('stock-orders.edit-request', $requested->id), false);

        $this->actingAs($this->purchasing)
            ->get(route('stock-orders.purchase-orders.show', $approved->id))
            ->assertDontSee(route('stock-orders.edit-request', $approved->id), false);
    }
}