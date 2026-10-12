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

    public function test_the_list_shows_the_new_type_labels(): void
    {
        $this->po('FUEL_TRADE');
        $this->po('BUY_BACK');
        $this->po('STANDARD_REPLENISHMENT');

        $response = $this->actingAs($this->purchasing)
            ->get(route('stock-orders.purchase-orders.index'));

        $response->assertStatus(200);
        $response->assertSee('SO-FT');
        $response->assertSee('SO-BUY BACK');
        $response->assertSee('Doyen Stock');
        // The old wording should be gone from the list.
        $response->assertDontSee('Standard Replenishment');
    }

    public function test_the_type_filter_offers_the_new_labels(): void
    {
        $response = $this->actingAs($this->purchasing)
            ->get(route('stock-orders.purchase-orders.index'));

        $response->assertStatus(200);
        $response->assertSee('SO-FT');
        $response->assertSee('SO-BUY BACK');
        $response->assertSee('Doyen Stock');
    }

    public function test_the_detail_page_shows_the_type_label(): void
    {
        $po = $this->po('FUEL_TRADE');

        $this->actingAs($this->purchasing)
            ->get(route('stock-orders.purchase-orders.show', $po->id))
            ->assertStatus(200)
            ->assertSee('SO-FT');
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

        $response->assertStatus(200);
        $response->assertSee('Doyen Stock');
        $response->assertDontSee('PO Buy Back (Repurchase from Client)');
    }

    public function test_the_edit_form_shows_the_type_read_only(): void
    {
        $po = $this->po('BUY_BACK');

        $this->actingAs($this->purchasing)
            ->get(route('stock-orders.edit-supplier-po', $po->id))
            ->assertStatus(200)
            ->assertSee('SO-BUY BACK');
    }

    public function test_the_atl_table_reports_the_parent_po_type(): void
    {
        $po = $this->po('BUY_BACK');

        $this->actingAs($this->purchasing)
            ->get(route('stock-orders.purchase-orders.show', $po->id))
            ->assertStatus(200)
            ->assertSee('SO-BUY BACK');
    }
}