<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Client;
use App\Models\Order;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderDelivery;
use App\Models\Supplier;
use Tests\TestCase;

/**
 * The tanker-pickup lift keeps its confirm modal on the Sales Orders detail
 * page: a Doyen pick-up without an assigned truck cannot lift from the bare
 * lift-status control, because the auto stock-in needs the tanker answer
 * first. The control's Lifted choice opens the modal instead.
 */
class MarkPickedUpLocationTest extends TestCase
{
    protected Admin $purchasing;

    protected Client $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->purchasing = Admin::create([
            'username' => 'purchasing_'.uniqid(),
            'password' => bcrypt('secret'),
            'name' => 'Rica Esaga',
            'role' => 'purchasing',
        ]);

        $this->client = Client::first() ?? Client::create(['name' => 'Apex Logistics Corp']);
    }

    /**
     * A Fuel Trade order with an ATL that has not been lifted yet.
     */
    protected function fuelTradeOrder(): Order
    {
        $order = Order::create([
            'account' => $this->client->name,
            'location' => 'Valenzuela',
            'so_number' => 'SO-FT-'.uniqid(),
            'date' => now(),
            'status' => 'Active',
            'terms' => 'COD',
            'order_category' => Order::CATEGORY_CLIENT_ORDER,
            'fulfillment_type' => Order::FULFILLMENT_FUEL_TRADE,
            'qty_ordered' => 5000,
            'created_by' => $this->purchasing->id,
        ]);

        $supplier = Supplier::create([
            'company_name' => 'Petron Bataan Refinery',
            'location' => 'Limay',
        ]);

        $po = PurchaseOrder::create([
            'po_number' => 'PO-FT-'.uniqid(),
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

        PurchaseOrderDelivery::create([
            'purchase_order_id' => $po->id,
            'order_id' => $order->id,
            'delivery_channel' => 'SUPPLIER_DOYEN_PICKUP',
            'atl_category' => PurchaseOrderDelivery::CATEGORY_FUEL_TRADE,
            'product' => 'Diesel',
            'qty_to_receive' => 5000,
            'status' => 'Active',
            'lift_status' => PurchaseOrderDelivery::LIFT_UNLIFTED,
            'approval_status' => 'APPROVED',
        ]);

        return $order->fresh();
    }

    public function test_the_sales_order_page_offers_the_tanker_confirm_modal(): void
    {
        $order = $this->fuelTradeOrder();

        $response = $this->actingAs($this->purchasing)
            ->get(route('stock-orders.sales-orders.show', $order->id));

        $response->assertStatus(200);
        $response->assertSee('confirmLiftModal', false);
        $response->assertSee('Confirm Pick Up / Lift');
        $response->assertSee('data-requires-modal', false);

        // The round-5 per-row buttons are gone; the control replaced them.
        $response->assertDontSee('Mark Picked Up');
    }

    public function test_the_tanker_modal_still_posts_to_the_full_lift_route(): void
    {
        $order = $this->fuelTradeOrder();
        $delivery = PurchaseOrderDelivery::where('order_id', $order->id)->firstOrFail();

        $response = $this->actingAs($this->purchasing)
            ->get(route('stock-orders.sales-orders.show', $order->id));

        $response->assertSee(route('stock-orders.complete-fuel-trade', $delivery->id), false);
    }

    public function test_an_already_lifted_atl_offers_no_confirm_modal(): void
    {
        $order = $this->fuelTradeOrder();
        PurchaseOrderDelivery::where('order_id', $order->id)
            ->update(['lift_status' => PurchaseOrderDelivery::LIFT_LIFTED]);

        $response = $this->actingAs($this->purchasing)
            ->get(route('stock-orders.sales-orders.show', $order->id));

        $response->assertStatus(200);
        $response->assertDontSee('Mark Picked Up');
        // Scoped to real markup: the page's inline script mentions the modal
        // id it opens for the liftable rows.
        $response->assertDontSee('id="confirmLiftModal', false);
    }

    public function test_the_control_stays_with_roles_allowed_to_mark_an_atl_lifted(): void
    {
        $order = $this->fuelTradeOrder();

        $sales = Admin::create([
            'username' => 'sales_'.uniqid(),
            'password' => bcrypt('secret'),
            'name' => 'Sales User',
            'role' => 'sales',
        ]);

        // The route itself is still gated, whatever the page shows.
        $this->actingAs($sales)
            ->post(route('stock-orders.lift-status', PurchaseOrderDelivery::where('order_id', $order->id)->value('id')), [
                'lift_status' => PurchaseOrderDelivery::LIFT_LIFTED,
            ])
            ->assertForbidden();

        // And the Sales role has no Module 3 access at all.
        $this->actingAs($sales)
            ->get(route('stock-orders.sales-orders.show', $order->id))
            ->assertForbidden();
    }
}