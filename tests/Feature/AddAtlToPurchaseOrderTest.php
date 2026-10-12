<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\AtlAllocation;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderDelivery;
use App\Models\PurchaseOrderItem;
use App\Models\Supplier;
use Tests\TestCase;

class AddAtlToPurchaseOrderTest extends TestCase
{
    protected Admin $purchasing;

    protected Admin $vp;

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

        $this->vp = Admin::create([
            'username' => 'vp_' . uniqid(),
            'password' => bcrypt('secret'),
            'name' => 'Bernice Nikki Lee',
            'role' => 'vp',
        ]);

        $this->supplier = Supplier::create([
            'company_name' => 'Petron Bataan Refinery',
            'location' => 'Limay',
        ]);
    }

    protected function po(string $requestStatus, int $dieselQty = 10000): PurchaseOrder
    {
        $po = PurchaseOrder::create([
            'po_number' => 'PO-ATL-'.uniqid(),
            'po_type' => 'STANDARD_REPLENISHMENT',
            'supplier_id' => $this->supplier->id,
            'supplier_name' => $this->supplier->company_name,
            'qty_ordered' => $dieselQty,
            'total_amount' => 500000.00,
            'request_status' => $requestStatus,
            'status' => 'Pending',
            'prepared_by' => $this->purchasing->id,
        ]);

        PurchaseOrderItem::create([
            'purchase_order_id' => $po->id,
            'product' => 'Diesel',
            'quantity_ordered' => $dieselQty,
            'unit_price' => 50.00,
        ]);

        return $po->fresh();
    }

    /**
     * @return array<string, mixed>
     */
    protected function payload(array $overrides = []): array
    {
        return array_merge([
            'po_number' => 'PO-ATL-IGNORED',
            'supplier_name' => 'Should Be Ignored',
            'order_type' => 'PICK_UP',
            'delivery_channel' => 'SUPPLIER_DOYEN_PICKUP',
            'atl_number' => 'ATL-NEW-1',
            'product' => 'Diesel',
            'qty_to_receive' => 4000,
            'receiving_date' => now()->addDay()->format('Y-m-d'),
            'driver_name' => 'Juan Dela Cruz',
            'plate_number' => 'ABC 123',
        ], $overrides);
    }

    public function test_purchasing_can_open_the_add_atl_form_for_an_approved_po(): void
    {
        $po = $this->po('CONFIRMED');

        $this->actingAs($this->purchasing)
            ->get(route('stock-orders.add-atl', $po->id))
            ->assertStatus(200)
            ->assertSee($po->po_number);
    }

    public function test_adding_an_atl_creates_a_delivery_without_touching_approval_status(): void
    {
        $po = $this->po('CONFIRMED');

        $this->actingAs($this->purchasing)
            ->post(route('stock-orders.store-atl', $po->id), $this->payload())
            ->assertRedirect(route('stock-orders.purchase-orders.show', $po->id))
            ->assertSessionHas('success');

        $delivery = PurchaseOrderDelivery::where('atl_number', 'ATL-NEW-1')->firstOrFail();

        $this->assertEquals($po->id, $delivery->purchase_order_id);
        $this->assertEquals(4000, (int) $delivery->qty_to_receive);
        // The PO was already approved, so it must stay approved.
        $this->assertEquals('CONFIRMED', $po->fresh()->request_status);
        // A crafted POST must not be able to rewrite PO identity fields.
        $this->assertEquals($po->po_number, $po->fresh()->po_number);
        $this->assertEquals($this->supplier->company_name, $po->fresh()->supplier_name);
    }

    public function test_adding_an_atl_records_the_per_product_allocation(): void
    {
        $po = $this->po('CONFIRMED');

        $this->actingAs($this->purchasing)
            ->post(route('stock-orders.store-atl', $po->id), $this->payload())
            ->assertSessionHas('success');

        $delivery = PurchaseOrderDelivery::where('atl_number', 'ATL-NEW-1')->firstOrFail();
        $allocation = AtlAllocation::where('purchase_order_delivery_id', $delivery->id)->firstOrFail();

        $this->assertEquals($po->id, (int) $allocation->purchase_order_id);
        $this->assertEquals('Diesel', $allocation->product);
        $this->assertEquals(4000, (int) $allocation->quantity);
    }

    public function test_more_atls_can_be_added_until_the_po_is_drawn_down(): void
    {
        $po = $this->po('CONFIRMED', 10000);

        $this->actingAs($this->purchasing)
            ->post(route('stock-orders.store-atl', $po->id), $this->payload(['atl_number' => 'ATL-A', 'qty_to_receive' => 6000]))
            ->assertSessionHas('success');

        $this->actingAs($this->purchasing)
            ->post(route('stock-orders.store-atl', $po->id), $this->payload(['atl_number' => 'ATL-B', 'qty_to_receive' => 4000]))
            ->assertSessionHas('success');

        $this->assertEquals(2, PurchaseOrderDelivery::where('purchase_order_id', $po->id)->count());
        $this->assertEquals(0, $po->fresh()->getAvailableBalanceForProduct('Diesel'));
    }

    public function test_an_atl_cannot_exceed_the_remaining_volume(): void
    {
        $po = $this->po('CONFIRMED', 10000);

        $this->actingAs($this->purchasing)
            ->post(route('stock-orders.store-atl', $po->id), $this->payload(['qty_to_receive' => 99999]))
            ->assertSessionHasErrors('qty_to_receive');

        $this->assertEquals(0, PurchaseOrderDelivery::where('purchase_order_id', $po->id)->count());
    }

    public function test_an_atl_cannot_be_raised_against_an_unapproved_po(): void
    {
        foreach (['REQUESTED', 'RECEIVED'] as $status) {
            $po = $this->po($status);

            $this->actingAs($this->purchasing)
                ->post(route('stock-orders.store-atl', $po->id), $this->payload())
                ->assertStatus(403);

            $this->actingAs($this->purchasing)
                ->get(route('stock-orders.add-atl', $po->id))
                ->assertStatus(403);
        }
    }

    public function test_roles_that_cannot_edit_stock_orders_cannot_add_an_atl(): void
    {
        $po = $this->po('CONFIRMED');

        $this->actingAs($this->vp)
            ->post(route('stock-orders.store-atl', $po->id), $this->payload())
            ->assertStatus(403);

        $this->assertEquals(0, PurchaseOrderDelivery::where('purchase_order_id', $po->id)->count());
    }
}