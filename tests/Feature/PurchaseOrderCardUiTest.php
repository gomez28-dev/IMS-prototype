<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\AtlAllocation;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderDelivery;
use App\Models\PurchaseOrderItem;
use Tests\TestCase;

/**
 * The Purchase Orders cards must match the Orders Dashboard card design:
 * tinted icon square, product chips in bordered rows, and a Total footer.
 */
class PurchaseOrderCardUiTest extends TestCase
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

    public function test_list_cards_use_the_dashboard_card_markup(): void
    {
        $response = $this->actingAs($this->purchasing)
            ->get(route('stock-orders.purchase-orders.index'));

        $response->assertStatus(200);

        $html = $response->getContent();

        // Icon square + uppercase title + oversized headline number.
        $this->assertStringContainsString('class="stat-icon"', $html);
        $this->assertStringContainsString('class="stat-title"', $html);
        $this->assertStringContainsString('class="stat-big"', $html);

        // One bordered row per product, then a Total footer.
        $this->assertSame(9, substr_count($html, 'class="stat-row"'), 'expected 3 cards x 3 product rows');
        $this->assertSame(3, substr_count($html, 'class="stat-total"'));

        // Product chips in their per-product colours.
        $this->assertStringContainsString('prod-chip', $html);
        $this->assertStringContainsString('#e0e7ff', $html); // D
        $this->assertStringContainsString('#dcfce7', $html); // U
        $this->assertStringContainsString('#fee2e2', $html); // P

        // The four card titles.
        $response->assertSee('Total Orders');
        $response->assertSee('Total Qty. Ordered');
        $response->assertSee('Total Qty. Lifted');
        $response->assertSee('Total Qty. Unlifted');
    }

    public function test_detail_page_cards_use_the_same_markup(): void
    {
        $po = PurchaseOrder::create([
            'po_number' => 'PO-UI-'.uniqid(),
            'po_type' => 'STANDARD_REPLENISHMENT',
            'supplier_name' => 'Agieko Fuel Trading',
            'qty_ordered' => 30000,
            'total_amount' => 1500000.00,
            'request_status' => 'CONFIRMED',
            'status' => 'Pending',
        ]);

        PurchaseOrderItem::create([
            'purchase_order_id' => $po->id,
            'product' => 'Diesel',
            'quantity_ordered' => 30000,
            'unit_price' => 50.00,
        ]);

        $delivery = PurchaseOrderDelivery::create([
            'purchase_order_id' => $po->id,
            'delivery_channel' => 'TRUCKED',
            'order_type' => 'PICK_UP',
            'atl_type' => 'DITC_ATL',
            'atl_number' => 'ATL-UI-1',
            'product' => 'Diesel',
            'qty_to_receive' => 10000,
            'receiving_date' => now()->addDay(),
            'status' => 'Dispatched',
            'lift_status' => PurchaseOrderDelivery::LIFT_LIFTED,
        ]);

        AtlAllocation::create([
            'purchase_order_delivery_id' => $delivery->id,
            'purchase_order_id' => $po->id,
            'product' => 'Diesel',
            'quantity' => 10000,
        ]);

        $response = $this->actingAs($this->purchasing)
            ->get(route('stock-orders.purchase-orders.show', $po->id));

        $response->assertStatus(200);

        $html = $response->getContent();

        $this->assertStringContainsString('class="stat-icon"', $html);
        $this->assertStringContainsString('class="stat-title"', $html);
        $this->assertStringContainsString('prod-chip', $html);

        // 3 per-product cards x 3 products.
        $this->assertSame(9, substr_count($html, 'class="stat-row"'));
        $this->assertSame(3, substr_count($html, 'class="stat-total"'));

        // The supervisor removed the duplicate Qty Ordered / Total ATL'd /
        // Remaining cards, which were the only big-number layout on this page.
        $this->assertSame(0, substr_count($html, 'class="stat-big"'));

        // The lifted figure is carried through from the allocations.
        $response->assertSee('Total Qty. Lifted');
    }
}