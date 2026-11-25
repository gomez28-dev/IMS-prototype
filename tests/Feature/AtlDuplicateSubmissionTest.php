<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderDelivery;
use App\Models\PurchaseOrderItem;
use App\Models\StockRequest;
use App\Models\StockRequestLine;
use App\Models\Supplier;
use App\Models\Warehouse;
use Tests\TestCase;

/**
 * The ATL save endpoints used to insert a row on every POST, so a double-click
 * on Save - or a refreshed POST, or Back followed by Save - filed the same ATL
 * twice. Both rows carried the same PO and, when the number was left blank,
 * both read "Not yet issued" in the VP queue, which is what looked like
 * duplicates in the list.
 *
 * The form now carries a token generated once per render; a token that has
 * already produced a record is answered with a notice instead of a second row.
 */
class AtlDuplicateSubmissionTest extends TestCase
{
    protected Admin $purchasing;

    protected Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();

        $this->purchasing = Admin::create([
            'username' => 'purchasing_'.uniqid(),
            'password' => bcrypt('secret'),
            'name' => 'Rica Esaga',
            'role' => 'purchasing',
        ]);

        $this->warehouse = Warehouse::create(['name' => 'Bataan Depot '.rand(1000, 9999)]);
    }

    protected function request(int $qty = 50000): StockRequest
    {
        $request = StockRequest::create([
            'request_number' => 'REQ-DUP-'.rand(1000, 9999),
            'request_type' => StockRequest::TYPE_PICKUP,
            'warehouse_id' => $this->warehouse->id,
            'qty_requested' => $qty,
            'status' => StockRequest::STATUS_REQUESTED,
            'requested_by' => $this->purchasing->id,
        ]);

        StockRequestLine::create([
            'stock_request_id' => $request->id,
            'product' => 'Diesel',
            'qty_requested' => $qty,
        ]);

        return $request;
    }

    protected function po(): PurchaseOrder
    {
        $supplier = Supplier::create([
            'company_name' => 'Duplicate Refinery '.rand(1000, 9999),
            'location' => 'Limay Terminal',
        ]);

        $po = PurchaseOrder::create([
            'po_number' => 'PO-DUP-'.rand(1000, 9999),
            'po_type' => PurchaseOrder::TYPE_STANDARD_REPLENISHMENT,
            'supplier_id' => $supplier->id,
            'supplier_name' => $supplier->company_name,
            'po_date' => now()->toDateString(),
            'qty_ordered' => 100000,
            'request_status' => 'CONFIRMED',
        ]);

        PurchaseOrderItem::create([
            'purchase_order_id' => $po->id,
            'product' => 'Diesel',
            'quantity_ordered' => 100000,
        ]);

        return $po;
    }

    protected function payload(PurchaseOrder $po, ?string $token): array
    {
        $payload = [
            'drawdowns' => [
                ['purchase_order_id' => $po->id, 'product' => 'Diesel', 'quantity' => 20000],
            ],
            'order_type' => 'DELIVERY',
            'delivery_channel' => 'SUPPLIER_STOCKS_DELIVERY',
            'atl_type' => 'DITC_ATL',
            'atl_number' => 'ATL-DUP-'.rand(1000, 9999),
            'receiving_date' => now()->addDays(3)->format('Y-m-d'),
        ];

        if ($token !== null) {
            $payload['submission_token'] = $token;
        }

        return $payload;
    }

    public function test_the_same_form_submission_is_saved_only_once(): void
    {
        $request = $this->request();
        $po = $this->po();
        $token = (string) \Illuminate\Support\Str::uuid();

        $this->actingAs($this->purchasing)
            ->post(route('stock-orders.wet-stock-requests.atls.store', $request->id), $this->payload($po, $token))
            ->assertSessionHas('success');

        // The double-click, the refreshed POST, or Back-then-Save.
        $this->actingAs($this->purchasing)
            ->post(route('stock-orders.wet-stock-requests.atls.store', $request->id), $this->payload($po, $token))
            ->assertSessionHas('info');

        $this->assertSame(
            1,
            PurchaseOrderDelivery::where('request_id', $request->id)->count(),
            'A repeated submission of the same form must not write a second ATL.'
        );
    }

    public function test_a_fresh_form_still_files_another_atl(): void
    {
        $request = $this->request();
        $po = $this->po();

        $this->actingAs($this->purchasing)
            ->post(route('stock-orders.wet-stock-requests.atls.store', $request->id), $this->payload($po, (string) \Illuminate\Support\Str::uuid()))
            ->assertSessionHas('success');

        // A genuinely new form render is a new load and must still be recorded.
        $this->actingAs($this->purchasing)
            ->post(route('stock-orders.wet-stock-requests.atls.store', $request->id), $this->payload($po, (string) \Illuminate\Support\Str::uuid()))
            ->assertSessionHas('success');

        $this->assertSame(2, PurchaseOrderDelivery::where('request_id', $request->id)->count());
    }

    public function test_a_separate_save_of_the_same_unnumbered_atl_is_refused(): void
    {
        $request = $this->request();
        $po = $this->po();

        $first = $this->payload($po, (string) \Illuminate\Support\Str::uuid());
        unset($first['atl_number']);

        $this->actingAs($this->purchasing)
            ->post(route('stock-orders.wet-stock-requests.atls.store', $request->id), $first)
            ->assertSessionHas('success');

        // A new render of the form carries a new token, so the token guard cannot
        // see this one. It is a separate save of the same ATL - the shape of row
        // that used to reach the VP queue as a second "Not yet issued" entry.
        $second = $this->payload($po, (string) \Illuminate\Support\Str::uuid());
        unset($second['atl_number']);

        $this->actingAs($this->purchasing)
            ->post(route('stock-orders.wet-stock-requests.atls.store', $request->id), $second)
            ->assertSessionHasErrors('atl')
            ->assertSessionHas(
                'duplicate_atl_id',
                PurchaseOrderDelivery::where('request_id', $request->id)->value('id')
            );

        $this->assertSame(
            1,
            PurchaseOrderDelivery::where('request_id', $request->id)->count(),
            'A separate save of the same unnumbered ATL on the same PO must be refused.'
        );

        // The embedded form says which ATL to correct instead of dead-ending.
        $this->actingAs($this->purchasing)
            ->get(route('stock-orders.wet-stock-requests.atls', $request->id))
            ->assertOk()
            ->assertSee('Correct the saved ATL instead');
    }

    public function test_the_dedupe_command_reports_the_existing_duplicates_without_deleting_them(): void
    {
        $po = $this->po();

        foreach ([1000, 1000] as $qty) {
            PurchaseOrderDelivery::create([
                'purchase_order_id' => $po->id,
                'delivery_channel' => 'SUPPLIER_CLIENT_PICKUP',
                'order_type' => 'PICK_UP',
                'atl_type' => 'DITC_ATL',
                'product' => 'Diesel',
                'qty_to_receive' => $qty,
                'receiving_date' => now(),
                'status' => 'Pending',
                'approval_status' => PurchaseOrderDelivery::APPROVAL_DRAFT,
                'atl_source' => PurchaseOrderDelivery::SOURCE_DOYEN_ISSUED,
                'lift_status' => PurchaseOrderDelivery::LIFT_UNLIFTED,
            ]);
        }

        $this->artisan('stock-orders:dedupe-atls')
            ->expectsOutputToContain('1 duplicate group(s); 1 row(s) safe to delete.')
            ->assertExitCode(0);

        $this->assertSame(
            2,
            PurchaseOrderDelivery::where('purchase_order_id', $po->id)->count(),
            'The report is a dry run: nothing is deleted without --apply.'
        );
    }

    public function test_the_dedupe_command_apply_removes_the_duplicate_and_its_allocation(): void
    {
        $po = $this->po();

        foreach ([1000, 1000] as $qty) {
            $atl = PurchaseOrderDelivery::create([
                'purchase_order_id' => $po->id,
                'delivery_channel' => 'SUPPLIER_CLIENT_PICKUP',
                'order_type' => 'PICK_UP',
                'atl_type' => 'DITC_ATL',
                'product' => 'Diesel',
                'qty_to_receive' => $qty,
                'receiving_date' => now(),
                'status' => 'Pending',
                'approval_status' => PurchaseOrderDelivery::APPROVAL_DRAFT,
                'atl_source' => PurchaseOrderDelivery::SOURCE_DOYEN_ISSUED,
                'lift_status' => PurchaseOrderDelivery::LIFT_UNLIFTED,
            ]);

            \App\Models\AtlAllocation::create([
                'purchase_order_delivery_id' => $atl->id,
                'purchase_order_id' => $po->id,
                'product' => 'Diesel',
                'quantity' => $qty,
            ]);
        }

        $ids = PurchaseOrderDelivery::where('purchase_order_id', $po->id)->orderBy('id')->pluck('id');

        $this->artisan('stock-orders:dedupe-atls', ['--apply' => true])->assertExitCode(0);

        $this->assertSame(
            [$ids->first()],
            PurchaseOrderDelivery::where('purchase_order_id', $po->id)->pluck('id')->all(),
            'The original is kept and the duplicate removed.'
        );
        $this->assertSame(
            1,
            \App\Models\AtlAllocation::where('purchase_order_delivery_id', $ids->first())->count(),
            'The duplicate allocation leaves with the duplicate row, so the PO is not double-counted.'
        );
    }

    /**
     * An ATL already on file on this PO, ready to be corrected.
     */
    protected function savedAtl(PurchaseOrder $po, array $overrides = []): PurchaseOrderDelivery
    {
        return PurchaseOrderDelivery::create(array_merge([
            'purchase_order_id' => $po->id,
            'delivery_channel' => 'SUPPLIER_DOYEN_PICKUP',
            'order_type' => 'PICK_UP',
            'atl_type' => 'DITC_ATL',
            'atl_number' => 'ATL-FIX-'.rand(1000, 9999),
            'product' => 'Diesel',
            'qty_to_receive' => 20000,
            'receiving_date' => now()->addDays(3),
            'driver_name' => 'Juan Dela Cruz',
            'plate_number' => 'ABC 123',
            'status' => 'Pending',
            'approval_status' => PurchaseOrderDelivery::APPROVAL_FOR_APPROVAL,
            'atl_source' => PurchaseOrderDelivery::SOURCE_DOYEN_ISSUED,
            'lift_status' => PurchaseOrderDelivery::LIFT_UNLIFTED,
            'issued_at' => now(),
        ], $overrides));
    }

    /**
     * Two identical unnumbered ATLs on one PO, as the unconditional insert left
     * them. Returns [keeper id, duplicate id].
     *
     * @return array{0: int, 1: int}
     */
    protected function duplicatePair(PurchaseOrder $po): array
    {
        $keeper = $this->savedAtl($po, ['atl_number' => null]);
        $duplicate = $this->savedAtl($po, ['atl_number' => null]);

        return [$keeper->id, $duplicate->id];
    }

    public function test_a_saved_atl_is_corrected_in_place_instead_of_filed_again(): void
    {
        $po = $this->po();
        $atl = $this->savedAtl($po);

        $this->actingAs($this->purchasing)
            ->get(route('stock-orders.atls.edit', $atl->id))
            ->assertOk()
            ->assertSee('Save Corrections')
            ->assertSee($atl->atl_number);

        // The date and the driver are what a user actually comes back to fix.
        // Order type and channel are posted too, to prove a correction cannot
        // change what the ATL was filed against.
        $this->actingAs($this->purchasing)
            ->post(route('stock-orders.atls.update', $atl->id), [
                'order_type' => 'DELIVERY',
                'delivery_channel' => 'BUY_BACK_STOCKS_DELIVERY',
                'atl_number' => $atl->atl_number,
                'receiving_date' => now()->addDays(9)->format('Y-m-d'),
                'driver_name' => 'Pedro Reyes',
                'plate_number' => 'XYZ 987',
            ])
            ->assertSessionHas('success');

        $atl->refresh();

        $this->assertSame(
            1,
            PurchaseOrderDelivery::where('purchase_order_id', $po->id)->count(),
            'Correcting an ATL must not file a second ATL on the PO.'
        );
        $this->assertSame('Pedro Reyes', $atl->driver_name);
        $this->assertSame(now()->addDays(9)->format('Y-m-d'), $atl->receiving_date->format('Y-m-d'));
        $this->assertSame('PICK_UP', $atl->order_type, 'A correction cannot change the order type.');
        $this->assertSame(
            'SUPPLIER_DOYEN_PICKUP',
            $atl->delivery_channel,
            'A correction cannot change the channel the ATL was filed against.'
        );
        $this->assertNotNull($atl->revised_at);
    }

    public function test_correcting_an_approved_atl_sends_it_back_for_approval(): void
    {
        $po = $this->po();
        $atl = $this->savedAtl($po, ['approval_status' => PurchaseOrderDelivery::APPROVAL_APPROVED]);

        $this->actingAs($this->purchasing)
            ->post(route('stock-orders.atls.update', $atl->id), [
                'atl_number' => $atl->atl_number,
                'receiving_date' => now()->addDays(9)->format('Y-m-d'),
                'driver_name' => $atl->driver_name,
            ])
            ->assertSessionHas('success');

        $atl->refresh();

        $this->assertSame(
            PurchaseOrderDelivery::APPROVAL_FOR_APPROVAL,
            $atl->approval_status,
            'An approved ATL whose details changed is no longer what the VP approved.'
        );
    }

    public function test_a_refused_duplicate_points_at_the_atl_to_correct(): void
    {
        $po = $this->po();
        $atl = $this->savedAtl($po, ['atl_number' => null, 'delivery_channel' => 'SUPPLIER_CLIENT_PICKUP']);

        $this->actingAs($this->purchasing)
            ->post(route('stock-orders.store-atl', $po->id), [
                'drawdowns' => [
                    ['purchase_order_id' => $po->id, 'product' => 'Diesel', 'quantity' => 20000],
                ],
                'order_type' => 'PICK_UP',
                'delivery_channel' => 'SUPPLIER_CLIENT_PICKUP',
                'atl_type' => 'DITC_ATL',
                'product' => 'Diesel',
                'qty_to_receive' => 20000,
                'receiving_date' => now()->addDay()->format('Y-m-d'),
                'submission_token' => (string) \Illuminate\Support\Str::uuid(),
            ])
            ->assertSessionHasErrors('atl')
            ->assertSessionHas('duplicate_atl_id', $atl->id);

        $this->assertSame(
            1,
            PurchaseOrderDelivery::where('purchase_order_id', $po->id)->count(),
            'The second save is refused; the ATL it collided with is the one to correct.'
        );
    }

    public function test_the_dedupe_command_deletes_only_the_ids_it_is_given(): void
    {
        $firstPo = $this->po();
        $secondPo = $this->po();

        [$firstKeeper, $chosen] = $this->duplicatePair($firstPo);
        [$otherKeeper, $otherDuplicate] = $this->duplicatePair($secondPo);

        $this->artisan('stock-orders:dedupe-atls', ['--ids' => (string) $chosen, '--apply' => true])
            ->assertExitCode(0);

        $this->assertNull(PurchaseOrderDelivery::find($chosen), 'The picked duplicate is deleted.');
        $this->assertNotNull(PurchaseOrderDelivery::find($firstKeeper), 'Its original stays.');

        $this->assertNotNull(
            PurchaseOrderDelivery::find($otherDuplicate),
            'A group the operator did not pick is left alone.'
        );
        $this->assertNotNull(PurchaseOrderDelivery::find($otherKeeper));
    }

    public function test_the_dedupe_command_refuses_an_id_it_must_not_delete(): void
    {
        $po = $this->po();
        [$keeper, $duplicate] = $this->duplicatePair($po);

        // The original the report keeps is not a duplicate, and naming it would
        // leave the copy behind while deleting the real ATL.
        $this->artisan('stock-orders:dedupe-atls', ['--ids' => (string) $keeper, '--apply' => true])
            ->assertExitCode(1);

        $this->assertSame(2, PurchaseOrderDelivery::where('purchase_order_id', $po->id)->count());
        $this->assertNotNull(PurchaseOrderDelivery::find($keeper));
        $this->assertNotNull(PurchaseOrderDelivery::find($duplicate));
    }

    public function test_the_atl_lists_offer_a_correct_action(): void
    {
        $po = $this->po();
        $request = $this->request(20000);
        $atl = $this->savedAtl($po, ['request_id' => $request->id]);

        // The request's own ATL page.
        $this->actingAs($this->purchasing)
            ->get(route('stock-orders.wet-stock-requests.atls', $request->id))
            ->assertOk()
            ->assertSee(route('stock-orders.atls.edit', $atl->id), false);

        // The PO's Deliveries & ATL Records list.
        $this->actingAs($this->purchasing)
            ->get(route('stock-orders.purchase-orders.show', $po->id))
            ->assertOk()
            ->assertSee(route('stock-orders.atls.edit', $atl->id), false);

        // Once lifted, its date and driver are part of that record, so no
        // correction is offered - the route refuses it either way.
        $atl->update([
            'lift_status' => PurchaseOrderDelivery::LIFT_LIFTED,
            'lifted_at' => now(),
        ]);

        $this->actingAs($this->purchasing)
            ->get(route('stock-orders.purchase-orders.show', $po->id))
            ->assertOk()
            ->assertDontSee(route('stock-orders.atls.edit', $atl->id), false);
    }

    public function test_the_approval_queue_shows_what_a_correction_changed_and_who_made_it(): void
    {
        $po = $this->po();
        $atl = $this->savedAtl($po, [
            'driver_name' => 'Juan Dela Cruz',
            'receiving_date' => now()->addDays(3),
        ]);
        $newDate = now()->addDays(9);

        $this->actingAs($this->purchasing)
            ->post(route('stock-orders.atls.update', $atl->id), [
                'atl_number' => $atl->atl_number,
                'receiving_date' => $newDate->format('Y-m-d'),
                'driver_name' => 'Pedro Reyes',
            ])
            ->assertSessionHas('success');

        $correction = $atl->corrections()->first();

        $this->assertNotNull($correction, 'A correction that changes a field is recorded.');
        $this->assertSame($this->purchasing->id, $correction->admin_id, 'The record says who corrected it.');
        // The database's JSON column returns the keys in its own order, so the
        // pairs are compared by content rather than by position.
        $this->assertEquals(
            ['from' => 'Juan Dela Cruz', 'to' => 'Pedro Reyes'],
            $correction->field_changes['driver_name']
        );
        $this->assertEquals(
            ['from' => now()->addDays(3)->format('Y-m-d'), 'to' => $newDate->format('Y-m-d')],
            $correction->field_changes['receiving_date']
        );

        $vp = Admin::create([
            'username' => 'vp_'.uniqid(),
            'password' => bcrypt('secret'),
            'name' => 'Lorraine Bamba',
            'role' => 'vp',
        ]);

        $this->actingAs($vp)
            ->get(route('stock-orders.approvals', ['tab' => 'atls']))
            ->assertOk()
            ->assertSee('Corrected')
            ->assertSee('What changed')
            ->assertSee('Rica Esaga')
            ->assertSee('Pick Up Date')
            // Before then after, in that order: the VP can see what moved.
            ->assertSeeInOrder(['What changed', 'Juan Dela Cruz', 'Pedro Reyes']);
    }

    public function test_the_correction_history_survives_approval_on_the_atl_and_po_pages(): void
    {
        $order = Order::create([
            'so_number' => 'SO-ATLH-'.rand(1000, 9999),
            'account' => 'Venture Commercial & Industrial Corp.',
            'date' => now()->toDateString(),
            'location' => 'Valenzuela',
            'status' => 'Active',
            'terms' => '30 Days',
            'qty_ordered' => 50000,
            'clearing_status' => 'Approved',
            'fulfillment_type' => Order::FULFILLMENT_FUEL_TRADE,
            'order_category' => Order::CATEGORY_CLIENT_ORDER,
        ]);

        OrderItem::create([
            'order_id' => $order->id,
            'product_type' => 'D',
            'qty' => 50000,
        ]);

        $po = $this->po();
        $atl = $this->savedAtl($po, [
            'order_id' => $order->id,
            'so_number' => $order->so_number,
            'driver_name' => 'Juan Dela Cruz',
        ]);

        $this->actingAs($this->purchasing)
            ->post(route('stock-orders.atls.update', $atl->id), [
                'atl_number' => $atl->atl_number,
                'receiving_date' => $atl->receiving_date->format('Y-m-d'),
                'driver_name' => 'Pedro Reyes',
            ])
            ->assertSessionHas('success');

        $vp = Admin::create([
            'username' => 'vp_'.uniqid(),
            'password' => bcrypt('secret'),
            'name' => 'Lorraine Bamba',
            'role' => 'vp',
        ]);

        $this->actingAs($vp)
            ->post(route('stock-orders.atl-approve', $atl->id))
            ->assertSessionHas('success');

        $atl->refresh();

        $this->assertSame(PurchaseOrderDelivery::APPROVAL_APPROVED, $atl->approval_status);
        $this->assertSame(1, $atl->corrections()->count(), 'Approving the ATL does not clear its history.');

        // The order's ATL details page.
        $this->actingAs($this->purchasing)
            ->get(route('stock-orders.sales-orders.show', $order->id))
            ->assertOk()
            ->assertSee('What changed')
            ->assertSee('Rica Esaga')
            ->assertSeeInOrder(['What changed', 'Juan Dela Cruz', 'Pedro Reyes']);

        // The PO's ATL Records table.
        $this->actingAs($this->purchasing)
            ->get(route('stock-orders.purchase-orders.show', $po->id))
            ->assertOk()
            ->assertSee('What changed')
            ->assertSee('Rica Esaga')
            ->assertSeeInOrder(['What changed', 'Juan Dela Cruz', 'Pedro Reyes']);
    }

    public function test_the_request_atl_page_shows_the_correction_history(): void
    {
        $request = $this->request();
        $po = $this->po();
        $atl = $this->savedAtl($po, [
            'request_id' => $request->id,
            'driver_name' => 'Juan Dela Cruz',
        ]);

        $this->actingAs($this->purchasing)
            ->post(route('stock-orders.atls.update', $atl->id), [
                'atl_number' => $atl->atl_number,
                'receiving_date' => $atl->receiving_date->format('Y-m-d'),
                'driver_name' => 'Pedro Reyes',
            ])
            ->assertSessionHas('success');

        $this->actingAs($this->purchasing)
            ->get(route('stock-orders.wet-stock-requests.atls', $request->id))
            ->assertOk()
            ->assertSee('What changed')
            ->assertSee('Rica Esaga')
            ->assertSeeInOrder(['What changed', 'Juan Dela Cruz', 'Pedro Reyes']);
    }

    public function test_saving_a_correction_without_changes_keeps_an_approved_atl_approved(): void
    {
        $po = $this->po();
        $atl = $this->savedAtl($po, ['approval_status' => PurchaseOrderDelivery::APPROVAL_APPROVED]);

        $this->actingAs($this->purchasing)
            ->post(route('stock-orders.atls.update', $atl->id), [
                'atl_number' => $atl->atl_number,
                'receiving_date' => $atl->receiving_date->format('Y-m-d'),
                'driver_name' => $atl->driver_name,
                'plate_number' => $atl->plate_number,
            ])
            ->assertSessionHas('success');

        $atl->refresh();

        $this->assertSame(PurchaseOrderDelivery::APPROVAL_APPROVED, $atl->approval_status);
        $this->assertSame(0, $atl->corrections()->count(), 'Re-saving the same values is not a correction.');
        $this->assertNull($atl->revised_at);
    }

    public function test_the_form_carries_a_submission_token(): void
    {
        $request = $this->request();
        $this->po();

        $html = $this->actingAs($this->purchasing)
            ->get(route('stock-orders.wet-stock-requests.atls.create', $request->id))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('name="submission_token"', $html);
    }

    public function test_the_atl_queue_offers_view_instead_of_row_actions(): void
    {
        $atl = PurchaseOrderDelivery::create([
            'purchase_order_id' => $this->po()->id,
            'delivery_channel' => 'SUPPLIER_STOCKS_DELIVERY',
            'order_type' => 'DELIVERY',
            'atl_type' => 'DITC_ATL',
            'atl_number' => 'ATL-VIEW-'.rand(1000, 9999),
            'product' => 'Diesel',
            'qty_to_receive' => 10000,
            'receiving_date' => now()->addDays(2),
            'status' => 'Pending',
            'lift_status' => PurchaseOrderDelivery::LIFT_UNLIFTED,
            'approval_status' => PurchaseOrderDelivery::APPROVAL_FOR_APPROVAL,
        ]);

        $vp = Admin::create([
            'username' => 'vp_'.uniqid(),
            'password' => bcrypt('secret'),
            'name' => 'Lorraine Bamba',
            'role' => 'vp',
        ]);

        $html = $this->actingAs($vp)
            ->get(route('stock-orders.approvals', ['tab' => 'atls']))
            ->assertOk()
            ->getContent();

        // The row opens the review card; the ruling itself happens in the card.
        $this->assertStringContainsString('openAtlCard', $html);
        $this->assertStringContainsString('data-atl=', $html);

        // The handlers still exist for the card to drive, but not as row buttons.
        $this->assertStringContainsString(route('stock-orders.atl-approve', $atl->id), $html);
        $this->assertStringNotContainsString("confirm('Approve this ATL?')", $html);
    }
}
