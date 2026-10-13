<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\Supplier;
use Tests\TestCase;

/**
 * The supervisor stripped the PO Details header back to a title, three volume
 * cards, and one action: Edit PO while the PO is unapproved, the PDF once it is
 * approved. The metadata card and the duplicate single-figure cards are gone.
 */
class PurchaseOrderDetailHeaderTest extends TestCase
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

    protected function po(string $status = 'RECEIVED'): PurchaseOrder
    {
        $supplier = Supplier::create([
            'company_name' => 'Detail Header Refinery '.rand(1000, 9999),
            'location' => 'Limay Terminal',
        ]);

        $po = PurchaseOrder::create([
            'po_number' => 'PO-DETAIL-'.rand(1000, 9999),
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

    protected function html(PurchaseOrder $po): string
    {
        return $this->actingAs($this->purchasing)
            ->get(route('stock-orders.purchase-orders.show', $po->id))
            ->assertOk()
            ->getContent();
    }

    // ---- Cards -------------------------------------------------------------

    public function test_the_three_product_volume_cards_stay(): void
    {
        $html = $this->html($this->po());

        $this->assertStringContainsString('>Total Qty. Ordered<', $html);
        $this->assertStringContainsString('>Total Qty. Lifted<', $html);
        $this->assertStringContainsString('>Total Qty. Unlifted<', $html);
    }

    public function test_the_duplicate_single_figure_cards_are_gone(): void
    {
        // The three volume cards above already say all of this, per product.
        $html = $this->html($this->po());

        $this->assertStringNotContainsString('>Qty Ordered<', $html);
        $this->assertStringNotContainsString(">Total ATL'd<", $html);
        $this->assertStringNotContainsString('>Remaining<', $html);
    }

    // ---- Metadata ----------------------------------------------------------

    public function test_the_metadata_card_is_gone(): void
    {
        $html = $this->html($this->po());

        $this->assertStringNotContainsString('PIPELINE STATUS', $html);
        $this->assertStringNotContainsString('SITE / CHANNEL', $html);
    }

    public function test_the_supplier_and_order_date_subtitle_is_gone(): void
    {
        $po = $this->po();

        $html = $this->html($po);

        $this->assertStringNotContainsString('Order Date', $html);
    }

    // ---- Buttons -----------------------------------------------------------

    public function test_an_unapproved_po_offers_edit_and_no_pdf(): void
    {
        $html = $this->html($this->po('RECEIVED'));

        $this->assertStringContainsString('Edit PO', $html);
        $this->assertStringNotContainsString('PO PDF', $html);
        // The legacy shell-then-number path goes with it.
        $this->assertStringNotContainsString('Prepare &amp; Submit', $html);
    }

    public function test_an_approved_po_offers_the_pdf_and_no_edit(): void
    {
        $po = $this->po('CONFIRMED');

        $html = $this->html($po);

        $this->assertStringNotContainsString('Edit PO', $html);
        $this->assertStringContainsString(route('stock-orders.po-pdf', $po->id), $html);
    }

    public function test_back_to_purchase_orders_is_always_there(): void
    {
        foreach (['RECEIVED', 'CONFIRMED'] as $status) {
            $this->assertStringContainsString(
                'Back to Purchase Orders',
                $this->html($this->po($status))
            );
        }
    }

    public function test_a_legacy_requested_po_still_offers_only_edit(): void
    {
        // Legacy placeholder POs sit at REQUESTED. They used to get a third
        // button, Prepare & Submit, alongside Edit PO.
        $html = $this->html($this->po('REQUESTED'));

        $this->assertStringContainsString('Edit PO', $html);
        $this->assertStringNotContainsString('Prepare &amp; Submit', $html);
        $this->assertStringNotContainsString('PO PDF', $html);
    }
}
