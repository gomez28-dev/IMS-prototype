<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\AtlAllocation;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderDelivery;
use App\Models\PurchaseOrderItem;
use App\Models\Supplier;
use Tests\TestCase;

class EditSupplierPurchaseOrderTest extends TestCase
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
            'company_name' => 'Agieko Fuel Trading',
            'location' => 'Limay Terminal',
        ]);
    }

    /**
     * @param  array<string, int>  $lines
     */
    protected function po(string $requestStatus = 'RECEIVED', array $lines = ['Diesel' => 10000]): PurchaseOrder
    {
        $po = PurchaseOrder::create([
            'po_number' => 'PO-EDIT-' . uniqid(),
            'po_type' => 'STANDARD_REPLENISHMENT',
            'supplier_id' => $this->supplier->id,
            'supplier_name' => $this->supplier->company_name,
            'qty_ordered' => array_sum($lines),
            'total_amount' => 500000.00,
            'net_payable_amount' => 500000.00,
            'po_date' => now()->format('Y-m-d'),
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

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function payload(array $overrides = []): array
    {
        return array_merge([
            'po_number' => 'PO-EDIT-'.uniqid(),
            'supplier_id' => $this->supplier->id,
            'date_needed' => '2026-10-30',
            'po_date' => '2026-10-01',
            'items' => [
                ['product' => 'Diesel', 'quantity' => 12000, 'unit_price' => 50.00],
            ],
        ], $overrides);
    }

    public function test_edit_form_is_prefilled_from_the_po(): void
    {
        $po = $this->po('CONFIRMED', ['Diesel' => 10000, 'Premium' => 5000]);

        $response = $this->actingAs($this->purchasing)
            ->get(route('stock-orders.edit-supplier-po', $po->id));

        $response->assertStatus(200);
        $response->assertSee($po->po_number);
        $response->assertSee('Agieko Fuel Trading');
        // Both stored lines are rendered, with their quantities and prices.
        $this->assertStringContainsString('value="10000"', $response->getContent());
        $this->assertStringContainsString('value="5000"', $response->getContent());
        $response->assertSee('Save Changes');
    }

    public function test_editing_updates_the_po_and_its_lines(): void
    {
        $po = $this->po('RECEIVED', ['Diesel' => 10000]);

        $this->actingAs($this->purchasing)
            ->post(route('stock-orders.update-supplier-po', $po->id), $this->payload([
                'po_number' => 'PO-EDITED-NEW',
                'less_w_tax' => 100000.00,
                'items' => [
                    ['product' => 'Diesel', 'quantity' => 12000, 'unit_price' => 60.00],
                ],
            ]))
            ->assertRedirect(route('stock-orders.purchase-orders.show', $po->id));

        $po = $po->fresh();
        $this->assertEquals('PO-EDITED-NEW', $po->po_number);
        $this->assertEquals(12000, (int) $po->qty_ordered);
        // 12,000 x 60.00 = 720,000.00
        $this->assertEquals(720000.00, (float) $po->total_amount);
        // Net payable is still derived, not taken from the form.
        $this->assertEquals(620000.00, (float) $po->net_payable_amount);
    }

    public function test_editing_can_add_a_new_product_line(): void
    {
        $po = $this->po('RECEIVED', ['Diesel' => 10000]);

        $this->actingAs($this->purchasing)
            ->post(route('stock-orders.update-supplier-po', $po->id), $this->payload([
                'items' => [
                    ['product' => 'Diesel', 'quantity' => 10000, 'unit_price' => 50.00],
                    ['product' => 'Premium', 'quantity' => 4000, 'unit_price' => 80.00],
                ],
            ]))
            ->assertSessionHas('success');

        $this->assertCount(2, $po->fresh()->items);
        $this->assertEquals(14000, (int) $po->fresh()->qty_ordered);
    }

    public function test_po_number_uniqueness_ignores_the_po_being_edited(): void
    {
        $po = $this->po('RECEIVED');

        // Re-saving with its own number must not trip the unique check.
        $this->actingAs($this->purchasing)
            ->post(route('stock-orders.update-supplier-po', $po->id), $this->payload([
                'po_number' => $po->po_number,
            ]))
            ->assertSessionHas('success');
    }

    public function test_po_number_still_has_to_be_unique_against_other_pos(): void
    {
        $existing = $this->po('RECEIVED');
        $target = $this->po('RECEIVED');

        $this->actingAs($this->purchasing)
            ->post(route('stock-orders.update-supplier-po', $target->id), $this->payload([
                'po_number' => $existing->po_number,
            ]))
            ->assertSessionHasErrors('po_number');
    }

    public function test_editing_an_unapproved_po_leaves_its_status_alone(): void
    {
        $po = $this->po('RECEIVED');

        $this->actingAs($this->purchasing)
            ->post(route('stock-orders.update-supplier-po', $po->id), $this->payload())
            ->assertSessionHas('success');

        $this->assertEquals('RECEIVED', $po->fresh()->request_status);
    }

    public function test_editing_an_approved_po_sends_it_back_for_re_approval(): void
    {
        $po = $this->po('CONFIRMED', ['Diesel' => 10000]);

        $this->actingAs($this->purchasing)
            ->post(route('stock-orders.update-supplier-po', $po->id), $this->payload())
            ->assertSessionHas('success');

        $po = $po->fresh();
        $this->assertEquals('RECEIVED', $po->request_status);
        // The old sign-off must not linger.
        $this->assertNull($po->approved_by);
        $this->assertNull($po->approved_at);
    }

    public function test_saving_an_approved_po_unchanged_keeps_its_approval(): void
    {
        $po = $this->po('CONFIRMED', ['Diesel' => 10000]);
        $po->update(['approved_by' => $this->vp->id, 'approved_at' => now()]);

        // Resubmit exactly what is already stored.
        $this->actingAs($this->purchasing)
            ->post(route('stock-orders.update-supplier-po', $po->id), [
                'po_number' => $po->po_number,
                'supplier_id' => $this->supplier->id,
                'po_date' => $po->po_date->format('Y-m-d'),
                'items' => [['product' => 'Diesel', 'quantity' => 10000, 'unit_price' => 50.00]],
            ])
            ->assertSessionHas('success');

        $this->assertEquals('CONFIRMED', $po->fresh()->request_status);
        $this->assertEquals($this->vp->id, $po->fresh()->approved_by);
    }

    public function test_an_approved_po_number_cannot_be_changed(): void
    {
        $po = $this->po('CONFIRMED', ['Diesel' => 10000]);
        $original = $po->po_number;

        $this->actingAs($this->purchasing)
            ->post(route('stock-orders.update-supplier-po', $po->id), $this->payload([
                'po_number' => 'PO-HIJACKED',
            ]))
            ->assertSessionHas('success');

        // A crafted POST cannot rename a PO the supplier already holds.
        $this->assertEquals($original, $po->fresh()->po_number);
    }

    public function test_quantity_cannot_drop_below_what_an_atl_already_drew(): void
    {
        $po = $this->po('CONFIRMED', ['Diesel' => 10000]);

        $delivery = PurchaseOrderDelivery::create([
            'purchase_order_id' => $po->id,
            'delivery_channel' => 'TRUCKED',
            'order_type' => 'PICK_UP',
            'atl_type' => 'DITC_ATL',
            'atl_number' => 'ATL-DRAWN',
            'product' => 'Diesel',
            'qty_to_receive' => 6000,
            'receiving_date' => now()->addDay(),
            'status' => 'Dispatched',
            'lift_status' => PurchaseOrderDelivery::LIFT_LIFTED,
        ]);

        AtlAllocation::create([
            'purchase_order_delivery_id' => $delivery->id,
            'purchase_order_id' => $po->id,
            'product' => 'Diesel',
            'quantity' => 6000,
        ]);

        // 6,000 L is already drawn, so ordering only 5,000 must be refused.
        $this->actingAs($this->purchasing)
            ->post(route('stock-orders.update-supplier-po', $po->id), $this->payload([
                'items' => [['product' => 'Diesel', 'quantity' => 5000, 'unit_price' => 50.00]],
            ]))
            ->assertSessionHasErrors('items');

        $this->assertEquals(10000, (int) $po->fresh()->items()->where('product', 'Diesel')->value('quantity_ordered'));
    }

    public function test_a_product_that_has_been_drawn_from_cannot_be_removed(): void
    {
        $po = $this->po('CONFIRMED', ['Diesel' => 10000, 'Premium' => 5000]);

        $delivery = PurchaseOrderDelivery::create([
            'purchase_order_id' => $po->id,
            'delivery_channel' => 'TRUCKED',
            'order_type' => 'PICK_UP',
            'atl_type' => 'DITC_ATL',
            'atl_number' => 'ATL-PREMIUM',
            'product' => 'Premium',
            'qty_to_receive' => 2000,
            'receiving_date' => now()->addDay(),
            'status' => 'Dispatched',
        ]);

        AtlAllocation::create([
            'purchase_order_delivery_id' => $delivery->id,
            'purchase_order_id' => $po->id,
            'product' => 'Premium',
            'quantity' => 2000,
        ]);

        // Dropping Premium would orphan an ATL that drew from it.
        $this->actingAs($this->purchasing)
            ->post(route('stock-orders.update-supplier-po', $po->id), $this->payload([
                'items' => [['product' => 'Diesel', 'quantity' => 10000, 'unit_price' => 50.00]],
            ]))
            ->assertSessionHasErrors('items');

        $this->assertNotNull($po->fresh()->items()->where('product', 'Premium')->first());
    }

    public function test_an_untouched_product_line_can_be_removed(): void
    {
        $po = $this->po('RECEIVED', ['Diesel' => 10000, 'Premium' => 5000]);

        $this->actingAs($this->purchasing)
            ->post(route('stock-orders.update-supplier-po', $po->id), $this->payload([
                'items' => [['product' => 'Diesel', 'quantity' => 10000, 'unit_price' => 50.00]],
            ]))
            ->assertSessionHas('success');

        $this->assertNull($po->fresh()->items()->where('product', 'Premium')->first());
    }

    public function test_an_atl_without_an_item_link_still_counts_as_drawn(): void
    {
        $po = $this->po('CONFIRMED', ['Diesel' => 10000]);

        // Fuel Trade ATLs record no purchase_order_item_id, so the guard has to
        // aggregate on the PO id and product instead.
        $delivery = PurchaseOrderDelivery::create([
            'purchase_order_id' => $po->id,
            'delivery_channel' => 'TRUCKED',
            'order_type' => 'PICK_UP',
            'atl_type' => 'DITC_ATL',
            'atl_number' => 'ATL-NOITEM',
            'product' => 'Diesel',
            'qty_to_receive' => 7000,
            'receiving_date' => now()->addDay(),
            'status' => 'Dispatched',
        ]);

        AtlAllocation::create([
            'purchase_order_delivery_id' => $delivery->id,
            'purchase_order_id' => $po->id,
            'product' => 'Diesel',
            'quantity' => 7000,
        ]);

        $this->actingAs($this->purchasing)
            ->post(route('stock-orders.update-supplier-po', $po->id), $this->payload([
                'items' => [['product' => 'Diesel', 'quantity' => 6000, 'unit_price' => 50.00]],
            ]))
            ->assertSessionHasErrors('items');
    }

    public function test_the_po_type_cannot_be_changed_by_an_edit(): void
    {
        $po = $this->po('CONFIRMED', ['Diesel' => 10000]);
        $po->update(['po_type' => 'BUY_BACK']);

        // A crafted POST must not be able to move a PO between categories.
        $this->actingAs($this->purchasing)
            ->post(route('stock-orders.update-supplier-po', $po->id), $this->payload([
                'po_type' => 'STANDARD_REPLENISHMENT',
            ]))
            ->assertSessionHas('success');

        $this->assertEquals('BUY_BACK', $po->fresh()->po_type);
    }

    public function test_roles_that_cannot_edit_stock_orders_are_locked_out(): void
    {
        $po = $this->po('RECEIVED');

        $this->actingAs($this->vp)
            ->get(route('stock-orders.edit-supplier-po', $po->id))
            ->assertStatus(403);

        $this->actingAs($this->vp)
            ->post(route('stock-orders.update-supplier-po', $po->id), $this->payload())
            ->assertStatus(403);
    }

    public function test_the_list_offers_an_edit_link_to_purchasing_only(): void
    {
        $po = $this->po('RECEIVED');

        $this->actingAs($this->purchasing)
            ->get(route('stock-orders.purchase-orders.index'))
            ->assertSee(route('stock-orders.edit-supplier-po', $po->id), false);

        $this->actingAs($this->vp)
            ->get(route('stock-orders.purchase-orders.index'))
            ->assertDontSee(route('stock-orders.edit-supplier-po', $po->id), false);
    }
}