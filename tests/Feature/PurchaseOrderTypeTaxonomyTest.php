<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\Supplier;
use Tests\TestCase;

class PurchaseOrderTypeTaxonomyTest extends TestCase
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

    protected function po(string $poType, string $poNumber = ''): PurchaseOrder
    {
        $po = PurchaseOrder::create([
            'po_number' => $poNumber ?: 'PO-TYPE-'.uniqid(),
            'po_type' => $poType,
            'supplier_id' => $this->supplier->id,
            'supplier_name' => $this->supplier->company_name,
            'qty_ordered' => 10000,
            'total_amount' => 500000.00,
            'request_status' => 'CONFIRMED',
            'status' => 'Pending',
            'prepared_by' => $this->purchasing->id,
        ]);

        PurchaseOrderItem::create([
            'purchase_order_id' => $po->id,
            'product' => 'Diesel',
            'quantity_ordered' => 10000,
            'unit_price' => 50.00,
        ]);

        return $po->fresh();
    }

    /**
     * @dataProvider typeLabels
     */
    public function test_each_type_maps_to_its_label(string $poType, string $label): void
    {
        $po = $this->po($poType);

        $this->assertEquals($label, $po->typeLabel());
    }

    /**
     * @return array<string, array<int, string>>
     */
    public static function typeLabels(): array
    {
        return [
            'fuel trade' => ['FUEL_TRADE', 'SO-FT'],
            'buy back' => ['BUY_BACK', 'SO-BUY BACK'],
            'doyen stock' => ['STANDARD_REPLENISHMENT', 'Doyen Stock'],
        ];
    }

    public function test_the_list_no_longer_shows_a_type_column_or_filter(): void
    {
        $this->po('FUEL_TRADE');
        $this->po('BUY_BACK');
        $this->po('STANDARD_REPLENISHMENT');

        $response = $this->actingAs($this->purchasing)
            ->get(route('stock-orders.purchase-orders.index'));

        $response->assertStatus(200);

        // The labels still exist, they just no longer appear on the list.
        $response->assertDontSee('SO-BUY BACK');
        $response->assertDontSee('Doyen Stock');
        $response->assertDontSee('-- All PO Types --');
        $response->assertDontSee('name="po_type"', false);

        // The old wording stays gone.
        $response->assertDontSee('Standard Replenishment');
    }

    public function test_the_list_keeps_search_but_not_the_status_filter(): void
    {
        $response = $this->actingAs($this->purchasing)
            ->get(route('stock-orders.purchase-orders.index'));

        // The supervisor replaced the status dropdown and Filter button with a
        // single search box, styled like the one on M1.
        $response->assertStatus(200);
        $response->assertSee('name="search"', false);
        $response->assertDontSee('name="status"', false);
    }

    public function test_the_detail_page_does_not_repeat_the_po_category(): void
    {
        $po = $this->po('FUEL_TRADE');

        // The supervisor removed the metadata card, PO Type included, as
        // redundant with the figures on the page. The category is still fixed
        // at creation and at edit, both pinned by tests elsewhere in this file.
        $this->actingAs($this->purchasing)
            ->get(route('stock-orders.purchase-orders.show', $po->id))
            ->assertStatus(200)
            ->assertDontSee('SO-FT');
    }

    public function test_the_manual_create_form_only_raises_doyen_stock(): void
    {
        $this->actingAs($this->purchasing)->post(route('stock-orders.store-supplier-po'), [
            'po_number' => 'PO-MANUAL-'.uniqid(),
            'supplier_id' => $this->supplier->id,
            // A crafted POST must not be able to choose another category.
            'po_type' => 'BUY_BACK',
            'items' => [['product' => 'Diesel', 'quantity' => 1000, 'unit_price' => 50]],
        ])->assertSessionHas('success');

        $this->assertEquals('STANDARD_REPLENISHMENT', PurchaseOrder::latest('id')->value('po_type'));
    }

    public function test_the_create_form_does_not_offer_a_buy_back_choice(): void
    {
        $response = $this->actingAs($this->purchasing)
            ->get(route('stock-orders.create-supplier-po'));

        // The supervisor removed PO Classification from the form, so there is
        // no longer any category to choose from, Buy Back included.
        $response->assertStatus(200);
        $response->assertDontSee('PO Classification');
        $response->assertDontSee('PO Buy Back (Repurchase from Client)');
    }

    public function test_editing_a_po_cannot_change_its_category(): void
    {
        $po = $this->po('BUY_BACK');

        // The form no longer shows the category, and updateSupplierPo has no
        // po_type rule, so a crafted POST cannot move the PO either.
        $this->actingAs($this->purchasing)
            ->get(route('stock-orders.edit-supplier-po', $po->id))
            ->assertStatus(200)
            ->assertDontSee('PO Classification');

        $this->actingAs($this->purchasing)->post(route('stock-orders.update-supplier-po', $po->id), [
            'supplier_id' => $this->supplier->id,
            'po_type' => 'STANDARD_REPLENISHMENT',
            'items' => [['product' => 'Diesel', 'quantity' => 5000, 'unit_price' => 50]],
        ]);

        $this->assertEquals('BUY_BACK', $po->fresh()->po_type);
    }

    public function test_the_atl_table_type_column_reports_the_atl_category(): void
    {
        $po = $this->po('BUY_BACK');

        // This never reported the parent PO's type: the Type column reads
        // PurchaseOrderDelivery::typeLabel(), and the test only passed because
        // the metadata card happened to print the PO label on the same page.
        // Now that the card is gone, the column is pinned for what it is.
        $this->actingAs($this->purchasing)
            ->get(route('stock-orders.purchase-orders.show', $po->id))
            ->assertStatus(200)
            ->assertSee('Type')
            ->assertDontSee('SO-BUY BACK');
    }
}