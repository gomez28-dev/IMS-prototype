<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\AtlAllocation;
use App\Models\Client;
use App\Models\Delivery;
use App\Models\Order;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderDelivery;
use App\Models\StockIn;
use App\Models\StorageTank;
use App\Models\Supplier;
use App\Models\Warehouse;
use Tests\TestCase;

/**
 * The lift-status control.
 *
 * The round-5 per-row Mark Picked Up / Mark Unlifted buttons are replaced by
 * one control per ATL row: a select carrying the row's lift state that posts
 * to a single lift-status endpoint. Choosing Lifted runs the same lift
 * pipeline the old route ran, choosing Unlifted runs the same reversal, and
 * a record the depot will never lift can be marked Cancelled, which releases
 * the volume it drew back to the order. View and Pen stay the only actions.
 *
 * The records table itself drops the REF # and Type columns (both repeat what
 * the modal and the page title already say) and shows the products as their
 * U / D / P codes with the litres: a driver line has no business inside a
 * status cell.
 *
 * A tanker pick-up that has not been assigned a truck cannot lift blind: the
 * control's Lifted choice opens the confirm modal that asks for the tanker
 * instead, so the auto stock-in keeps its answer.
 */
class AtlStatusControlTest extends TestCase
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
        $this->purchasing->refresh();

        Client::first() ?? Client::create(['name' => 'Apex Logistics Corp']);
    }

    /**
     * A Fuel Trade order with one approved, dispatched, unlifted ATL.
     *
     * @return array{0: Order, 1: PurchaseOrder, 2: PurchaseOrderDelivery}
     */
    protected function atlOrder(string $channel = 'SUPPLIER_CLIENT_PICKUP', ?int $storageTankId = null, ?string $category = null): array
    {
        $order = Order::create([
            'account' => 'Apex Logistics Corp',
            'location' => 'Valenzuela',
            'so_number' => 'FT-'.rand(1000, 9999),
            'date' => now(),
            'status' => 'Active',
            'terms' => 'COD',
            'order_category' => Order::CATEGORY_CLIENT_ORDER,
            'fulfillment_type' => Order::FULFILLMENT_FUEL_TRADE,
            'qty_ordered' => 5000,
            'clearing_status' => 'Approved',
            'created_by' => $this->purchasing->id,
        ]);

        $supplier = Supplier::create([
            'company_name' => 'Petron Bataan Refinery',
            'location' => 'Limay',
        ]);

        $po = PurchaseOrder::create([
            'po_number' => 'PO-SC-'.rand(1000, 9999),
            'po_type' => PurchaseOrder::TYPE_FUEL_TRADE,
            'supplier_id' => $supplier->id,
            'supplier_name' => $supplier->company_name,
            'qty_ordered' => 5000,
            'total_amount' => 250000.00,
            'request_status' => 'FOR_DELIVERY',
            'status' => 'For Delivery',
            'linked_order_id' => $order->id,
            'prepared_by' => $this->purchasing->id,
        ]);

        $order->update(['linked_purchase_order_id' => $po->id]);

        $delivery = PurchaseOrderDelivery::create([
            'purchase_order_id' => $po->id,
            'order_id' => $order->id,
            'so_number' => $order->so_number,
            'delivery_channel' => $channel,
            'order_type' => 'PICK_UP',
            'atl_type' => 'DITC_ATL',
            'atl_number' => 'ATL-'.rand(100000, 999999),
            'atl_category' => $category ?? PurchaseOrderDelivery::CATEGORY_FUEL_TRADE,
            'product' => 'Diesel',
            'qty_to_receive' => 5000,
            'receiving_date' => now()->addDay(),
            'status' => 'Active',
            'lift_status' => PurchaseOrderDelivery::LIFT_UNLIFTED,
            'storage_tank_id' => $storageTankId,
            'approval_status' => 'APPROVED',
            'prepared_by' => $this->purchasing->id,
        ]);

        AtlAllocation::create([
            'purchase_order_delivery_id' => $delivery->id,
            'purchase_order_id' => $po->id,
            'product' => 'Diesel',
            'quantity' => 5000,
        ]);

        return [$order->fresh(), $po->fresh(), $delivery->fresh()];
    }

    protected function showHtml(Order $order): string
    {
        return $this->actingAs($this->purchasing)
            ->get(route('stock-orders.sales-orders.show', $order->id))
            ->assertOk()
            ->getContent();
    }

    // ---- The control replaces the row buttons -------------------------------

    public function test_each_row_carries_one_lift_status_control(): void
    {
        [$order] = $this->atlOrder();

        $html = $this->showHtml($order);

        // One control per row, each posting to the shared lift-status endpoint.
        $this->assertSame(1, substr_count($html, 'lift-status-form'),
            'Every ATL row needs its own lift-status control.');
        $this->assertStringContainsString('name="lift_status"', $html);
        $this->assertStringContainsString(route('stock-orders.lift-status', PurchaseOrderDelivery::where('order_id', $order->id)->value('id')), $html);

        // The three states the control speaks, with the row's current state.
        $this->assertStringContainsString('value="UNLIFTED"', $html);
        $this->assertStringContainsString('value="LIFTED"', $html);
        $this->assertStringContainsString('value="CANCELLED"', $html);
        $this->assertStringContainsString('data-current="', $html);
    }

    public function test_the_action_column_keeps_only_view_and_pen(): void
    {
        [$order] = $this->atlOrder();

        $html = $this->showHtml($order);

        $this->assertStringContainsString('> View', $html);
        $this->assertStringContainsString('> Pen', $html);
        $this->assertStringNotContainsString('Mark Picked Up', $html);
        $this->assertStringNotContainsString('Mark Unlifted', $html);
        // The reversal modal left with its button: Unlifted posts straight
        // from the control now.
        $this->assertStringNotContainsString('unliftModal', $html);
    }

    public function test_the_table_drops_the_ref_and_type_columns(): void
    {
        [$order] = $this->atlOrder();

        $html = $this->showHtml($order);

        // Scoped to the table headers - the pop-up still explains REF # and
        // Type, so the bare words cannot be the needle.
        $this->assertStringNotContainsString('>REF #</th>', $html);
        $this->assertStringNotContainsString('>Type</th>', $html);
    }

    public function test_the_products_read_as_code_dash_litres(): void
    {
        [$order] = $this->atlOrder();

        $html = $this->showHtml($order);

        $this->assertStringContainsString('<strong>D</strong> - 5,000', $html);
    }

    public function test_the_status_cell_carries_no_driver_line(): void
    {
        [$order] = $this->atlOrder();

        $html = $this->showHtml($order);

        // The driver belongs to the pop-up and the PDF, not a status cell.
        $this->assertStringNotContainsString('extra-small text-muted mt-1', $html);
    }

    public function test_a_tanker_pickup_without_a_truck_keeps_the_confirm_modal(): void
    {
        [$order, $po, $delivery] = $this->atlOrder('SUPPLIER_DOYEN_PICKUP');

        $html = $this->showHtml($order);

        $this->assertStringContainsString('confirmLiftModal'.$delivery->id, $html);
        $this->assertStringContainsString('Confirm Pick Up / Lift', $html);
        // The control marks the Lifted choice as needing the modal: the JS
        // opens it instead of posting, so the tanker is asked for.
        $this->assertStringContainsString('data-requires-modal', $html);
        // The modal itself still posts to the full lift route.
        $this->assertStringContainsString(route('stock-orders.complete-fuel-trade', $delivery->id), $html);
    }

    public function test_a_tanker_pickup_with_a_truck_lifts_without_the_modal(): void
    {
        $tank = StorageTank::create([
            'warehouse_id' => Warehouse::create(['name' => 'Lift Depot '.rand(1000, 9999)])->id,
            'name' => 'Tanker T-'.rand(100, 999),
            'category' => 'tanker',
            'product_type' => 'D',
            'max_capacity' => 50000,
            'is_active' => true,
        ]);

        [$order, $po, $delivery] = $this->atlOrder('SUPPLIER_DOYEN_PICKUP', $tank->id);

        $html = $this->showHtml($order);

        $this->assertStringNotContainsString('data-requires-modal', $html);
    }

    // ---- The endpoint -------------------------------------------------------------

    public function test_choosing_lifted_runs_the_lift_pipeline(): void
    {
        [$order, $po, $delivery] = $this->atlOrder();

        $this->actingAs($this->purchasing)
            ->post(route('stock-orders.lift-status', $delivery->id), [
                'lift_status' => PurchaseOrderDelivery::LIFT_LIFTED,
            ])
            ->assertSessionHas('success');

        $delivery->refresh();
        $this->assertSame(PurchaseOrderDelivery::LIFT_LIFTED, $delivery->lift_status);
        $this->assertSame('Completed', $delivery->status);

        $po->refresh();
        $this->assertSame('COMPLETED', $po->request_status);
        $this->assertSame('Fulfilled', $po->status);

        $this->assertSame('Fulfilled', $order->fresh()->status);
        $this->assertSame(1, Delivery::where('order_id', $order->id)->count(),
            'The lift wrote the Module 1 mirror.');
        $this->assertEquals(5000, $order->fresh()->total_qty_out);
    }

    public function test_choosing_unlifted_reverses_the_pipeline(): void
    {
        [$order, $po, $delivery] = $this->atlOrder();

        $this->actingAs($this->purchasing)
            ->post(route('stock-orders.lift-status', $delivery->id), [
                'lift_status' => PurchaseOrderDelivery::LIFT_LIFTED,
            ])
            ->assertSessionHas('success');

        $this->actingAs($this->purchasing)
            ->post(route('stock-orders.lift-status', $delivery->id), [
                'lift_status' => PurchaseOrderDelivery::LIFT_UNLIFTED,
            ])
            ->assertSessionHas('success');

        $delivery->refresh();
        $this->assertSame(PurchaseOrderDelivery::LIFT_UNLIFTED, $delivery->lift_status);
        $this->assertNull($delivery->lifted_at);
        $this->assertSame('Active', $delivery->status);

        $po->refresh();
        $this->assertSame('FOR_DELIVERY', $po->request_status);
        $this->assertSame('Active', $order->fresh()->status);
        $this->assertSame(0, Delivery::where('order_id', $order->id)->count(),
            'The Module 1 mirror is removed with the reversal.');
        $this->assertSame(['Diesel' => 5000], $order->fresh()->drawnByProduct());
    }

    public function test_choosing_unlifted_refuses_a_received_atl(): void
    {
        [$order, $po, $delivery] = $this->atlOrder();

        $delivery->update(['lift_status' => PurchaseOrderDelivery::LIFT_LIFTED, 'received_at' => now()]);

        $this->actingAs($this->purchasing)
            ->post(route('stock-orders.lift-status', $delivery->id), [
                'lift_status' => PurchaseOrderDelivery::LIFT_UNLIFTED,
            ])
            ->assertSessionHas('error');

        $this->assertSame(PurchaseOrderDelivery::LIFT_LIFTED, $delivery->fresh()->lift_status);
    }

    public function test_choosing_cancelled_marks_the_record_and_releases_the_draw(): void
    {
        [$order, $po, $delivery] = $this->atlOrder();

        $this->actingAs($this->purchasing)
            ->post(route('stock-orders.lift-status', $delivery->id), [
                'lift_status' => PurchaseOrderDelivery::LIFT_CANCELLED,
            ])
            ->assertSessionHas('success');

        $delivery->refresh();
        $this->assertSame(PurchaseOrderDelivery::LIFT_CANCELLED, $delivery->lift_status);
        $this->assertSame('Cancelled', $delivery->status,
            'A cancelled ATL is not volume in flight any more.');

        // The released draw: nothing counts against the order.
        $this->assertSame([], $order->fresh()->drawnByProduct());
        $this->assertSame('Active', $order->fresh()->status);

        // The PO stays in flight, free for another ATL to draw.
        $po->refresh();
        $this->assertSame('FOR_DELIVERY', $po->request_status);
    }

    public function test_cancelling_a_lifted_atl_is_refused(): void
    {
        [$order, $po, $delivery] = $this->atlOrder();

        $delivery->update(['lift_status' => PurchaseOrderDelivery::LIFT_LIFTED]);

        $this->actingAs($this->purchasing)
            ->post(route('stock-orders.lift-status', $delivery->id), [
                'lift_status' => PurchaseOrderDelivery::LIFT_CANCELLED,
            ])
            ->assertSessionHas('error');

        $this->assertSame(PurchaseOrderDelivery::LIFT_LIFTED, $delivery->fresh()->lift_status);
    }

    public function test_lifting_an_already_lifted_atl_is_refused(): void
    {
        $tank = StorageTank::create([
            'warehouse_id' => Warehouse::create(['name' => 'Lift Depot '.rand(1000, 9999)])->id,
            'name' => 'Tanker T-'.rand(100, 999),
            'category' => 'tanker',
            'product_type' => 'D',
            'max_capacity' => 50000,
            'is_active' => true,
        ]);

        [$order, $po, $delivery] = $this->atlOrder(
            'SUPPLIER_DOYEN_PICKUP',
            $tank->id,
            // Doyen Stocks is the category whose tanker lifts auto-stock; a
            // fuel-trade pick-up never stocks tanks, so it cannot count
            // anything twice here.
            PurchaseOrderDelivery::CATEGORY_DOYEN_STOCKS
        );

        $this->actingAs($this->purchasing)
            ->post(route('stock-orders.lift-status', $delivery->id), [
                'lift_status' => PurchaseOrderDelivery::LIFT_LIFTED,
            ])
            ->assertSessionHas('success');
        $this->assertSame(1, StockIn::where('purchase_order_delivery_id', $delivery->id)->count());

        // A second Lifted through the control must not stock the tanker
        // twice.
        $this->actingAs($this->purchasing)
            ->post(route('stock-orders.lift-status', $delivery->id), [
                'lift_status' => PurchaseOrderDelivery::LIFT_LIFTED,
            ])
            ->assertSessionHas('error');

        $this->assertSame(1, StockIn::where('purchase_order_delivery_id', $delivery->id)->count());
    }

    public function test_sending_an_unknown_state_is_refused(): void
    {
        [$order, $po, $delivery] = $this->atlOrder();

        $this->actingAs($this->purchasing)
            ->post(route('stock-orders.lift-status', $delivery->id), [
                'lift_status' => 'SOMEWHERE',
            ])
            ->assertSessionHasErrors('lift_status');
    }

    public function test_the_control_stays_with_purchasing_and_admin(): void
    {
        [$order, $po, $delivery] = $this->atlOrder();

        $sales = Admin::create([
            'username' => 'sales_'.uniqid(),
            'password' => bcrypt('secret'),
            'name' => 'Sales User',
            'role' => 'sales',
        ]);

        $this->actingAs($sales)
            ->post(route('stock-orders.lift-status', $delivery->id), [
                'lift_status' => PurchaseOrderDelivery::LIFT_LIFTED,
            ])
            ->assertForbidden();
    }
}
