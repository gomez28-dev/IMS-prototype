<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\AtlAllocation;
use App\Models\Client;
use App\Models\Order;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderDelivery;
use App\Models\PurchaseOrderItem;
use Tests\TestCase;

/**
 * The ATL record exactly as a person reads it, in Module 1's modal and on the
 * printed ATP PDF.
 *
 * Module 1's Deliveries details modal: the PO allocation lines the ATL is
 * drawn on, one per line instead of one squashed summary; plain labels; an
 * unnumbered ATL shown as Pending, not a made-up 'DITC-ATL'; and the whole
 * Google Drive Documentation Hub gone - attach button, attached banner and
 * edit form alike (the paperwork lives in the table's own Docs buttons).
 *
 * The printed PDF: one row per PO allocation like the on-screen record, the
 * driver on the first row, S.O. NO. in the FT-prefixed form the rest of the
 * system uses, and the destination read from the delivery's own location
 * instead of guessing a depot.
 */
class AtlModalAndPdfRedesignTest extends TestCase
{
    protected Admin $admin;

    protected Client $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = Admin::create([
            'username' => 'admin_'.uniqid(),
            'password' => bcrypt('secret'),
            'name' => 'System Administrator',
            'role' => 'admin',
        ]);

        $this->client = Client::first() ?? Client::create(['name' => 'Apex Logistics Corp']);
    }

    // ---- The shared record -------------------------------------------------

    protected function order(array $overrides = []): Order
    {
        return Order::create(array_merge([
            'account' => $this->client->name,
            'location' => 'Valenzuela',
            'so_number' => (string) rand(100000, 999999),
            'date' => now(),
            'qty_ordered' => 10000,
            'price' => 52.00,
            'status' => 'Active',
            'clearing_status' => 'Approved',
            'fulfillment_type' => Order::FULFILLMENT_FUEL_TRADE,
            'order_category' => Order::CATEGORY_CLIENT_ORDER,
        ], $overrides));
    }

    protected function po(Order $order, string $number, int $qty): PurchaseOrder
    {
        $po = PurchaseOrder::create([
            'po_number' => $number,
            'po_type' => PurchaseOrder::TYPE_STANDARD_REPLENISHMENT,
            'supplier_name' => 'Petron Bataan',
            'qty_ordered' => $qty,
            'request_status' => 'CONFIRMED',
            'status' => 'Pending',
            'linked_order_id' => $order->id,
        ]);
        PurchaseOrderItem::create([
            'purchase_order_id' => $po->id,
            'product' => 'Diesel',
            'quantity_ordered' => $qty,
        ]);

        // The modal mounts on the PO the order is linked to.
        $order->update(['linked_purchase_order_id' => $po->id]);

        return $po;
    }

    protected function atl(PurchaseOrder $po, Order $order, array $overrides = []): PurchaseOrderDelivery
    {
        $delivery = PurchaseOrderDelivery::create(array_merge([
            'purchase_order_id' => $po->id,
            'order_id' => $order->id,
            'delivery_channel' => 'SUPPLIER_CLIENT_PICKUP',
            'order_type' => 'PICK_UP',
            'atl_type' => 'DITC_ATL',
            'approval_status' => PurchaseOrderDelivery::APPROVAL_APPROVED,
            'product' => 'Diesel',
            'qty_to_receive' => 10000,
            'receiving_date' => now()->addDays(2)->format('Y-m-d'),
            'status' => 'Pending',
        ], $overrides));

        // The real store links the order to the primary PO; the modal reads that link.
        $order->update(['linked_purchase_order_id' => $po->id]);

        return $delivery;
    }

    protected function allocation(PurchaseOrderDelivery $delivery, PurchaseOrder $po, int $qty, string $product = 'Diesel'): AtlAllocation
    {
        return AtlAllocation::create([
            'purchase_order_delivery_id' => $delivery->id,
            'purchase_order_id' => $po->id,
            'purchase_order_item_id' => $po->items->first()->id,
            'product' => $product,
            'quantity' => $qty,
        ]);
    }

    // ---- Module 1's ATL Details modal --------------------------------------

    protected function modalHtml(Order $order): string
    {
        return $this->actingAs($this->admin)
            ->get(route('order.deliveries', $order->id))
            ->assertOk()
            ->getContent();
    }

    public function test_the_modal_lists_each_po_allocation_on_its_own_line(): void
    {
        $order = $this->order();
        $po1 = $this->po($order, 'PO-MOD-A-'.rand(1000, 9999), 4000);
        $po2 = $this->po($order, 'PO-MOD-B-'.rand(1000, 9999), 6000);
        $atl = $this->atl($po1, $order);
        $this->allocation($atl, $po1, 4000);
        $this->allocation($atl, $po2, 6000);

        $html = $this->modalHtml($order);

        // One line per PO: both numbers appear, and never as one jammed-up
        // summary string.
        $this->assertStringContainsString($po1->po_number, $html);
        $this->assertStringContainsString($po2->po_number, $html);
        $this->assertStringNotContainsString('allocations_summary', $html);
    }

    public function test_the_modal_labels_and_placeholder_are_rewritten(): void
    {
        $order = $this->order();
        $po = $this->po($order, 'PO-MOD-C-'.rand(1000, 9999), 10000);
        $atl = $this->atl($po, $order, [
            'atl_number' => null,
            'location' => 'Petron Limay Terminal',
        ]);
        $this->allocation($atl, $po, 10000);

        $html = $this->modalHtml($order);

        // The routing label that explained the line no longer does.
        $this->assertStringNotContainsString('Kabangga', $html);
        // Refinery Supplier is just Supplier, and the depot wording is plain Location.
        $this->assertStringNotContainsString('Refinery / Supplier', $html);
        $this->assertStringNotContainsString('Pickup Depot Location', $html);
        $this->assertStringContainsString('>Supplier<', $html);
        $this->assertStringContainsString('>Location<', $html);
        // A DITC-issued ATL without its number yet reads Pending, not the
        // invented 'DITC-ATL' placeholder.
        $this->assertStringNotContainsString('DITC-ATL</span>', $html);
        $this->assertStringContainsString('Pending</span>', $html);
    }

    public function test_the_modal_keeps_no_documentation_block_at_all(): void
    {
        $order = $this->order();
        $po = $this->po($order, 'PO-MOD-D-'.rand(1000, 9999), 10000);
        $atl = $this->atl($po, $order);
        $this->allocation($atl, $po, 10000);

        $html = $this->modalHtml($order);

        // The Documentation Hub left the modal entirely: no attach button, no
        // attached banner, no edit-collapse form, and no doc-update route
        // rendered into it. The table rows' Docs buttons and the backend
        // route itself stay - only the modal shows none of it.
        $this->assertStringNotContainsString('Scanned Physical Documents', $html);
        $this->assertStringNotContainsString('Attach Google Drive Link', $html);
        $this->assertStringNotContainsString('Verified Scanned Documents', $html);
        $this->assertStringNotContainsString('Open Google Drive', $html);
        $this->assertStringNotContainsString('Edit Link', $html);
        $this->assertStringNotContainsString('editDocCollapse', $html);
        $this->assertStringNotContainsString('update-docs', $html);
    }

    // ---- The printed PDF ----------------------------------------------------

    protected function pdfSource(): string
    {
        $path = base_path('resources/views/wetstock/stock-orders/atl-pdf.blade.php');

        return (string) file_get_contents($path);
    }

    public function test_the_pdf_table_is_one_row_per_po_allocation(): void
    {
        $source = $this->pdfSource();

        // One row per allocation of the ATL's own PO list.
        $this->assertStringContainsString('allocations as $allocation', $source);
        // The jammed summary footnote under the PO cell is gone with it.
        $this->assertStringNotContainsString('allocations_summary', $source);
    }

    public function test_the_pdf_prints_the_lift_details_once_under_the_product_lines(): void
    {
        $source = $this->pdfSource();

        // The printed form carries the lift's own particulars in one labelled
        // block below the product lines, so driver, plate, date and location
        // never repeat down the allocation table. The old DESTINATION / SITE
        // row in the metadata grid went with them: location has its own row.
        foreach (["Driver's Name", 'Plate No.', 'Pick Up Date', 'Location'] as $label) {
            $this->assertSame(1, substr_count($source, $label), $label . ' prints once.');
        }

        $this->assertStringNotContainsString('DESTINATION / SITE', $source);
    }

    public function test_the_pdf_prints_the_so_number_in_its_formatted_form(): void
    {
        $source = $this->pdfSource();

        // FT-prefixed, like every screen: FT-3012, not the raw 3012.
        $this->assertStringContainsString('formatted_so_number', $source);
        $this->assertStringNotContainsString(
            "{{ \$delivery->so_number ?: '—' }}",
            $source
        );
    }
}
