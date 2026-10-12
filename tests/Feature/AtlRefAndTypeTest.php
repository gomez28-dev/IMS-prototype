<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Client;
use App\Models\Order;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderDelivery;
use App\Models\StockRequest;
use App\Models\Supplier;
use Tests\TestCase;

/**
 * Whiteboard section 6: the Per ATL reference, Type, the View pop-up, and the
 * VP approval gate on the ATL document.
 */
class AtlRefAndTypeTest extends TestCase
{
    protected Admin $purchasing;

    protected Admin $vp;

    protected Client $client;

    protected Supplier $supplier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->purchasing = Admin::create([
            'username' => 'purchasing_'.uniqid(),
            'password' => bcrypt('secret'),
            'name' => 'Rica Esaga',
            'role' => 'purchasing',
        ]);

        $this->vp = Admin::create([
            'username' => 'vp_'.uniqid(),
            'password' => bcrypt('secret'),
            'name' => 'Lorraine Bamba',
            'role' => 'vp',
        ]);

        $this->client = Client::first() ?? Client::create(['name' => 'Apex Logistics Corp']);

        $this->supplier = Supplier::create([
            'company_name' => 'Petron Bataan Refinery',
            'location' => 'Limay',
        ]);
    }

    protected function fuelTradeOrder(): Order
    {
        return Order::create([
            'account' => $this->client->name,
            'location' => 'Valenzuela',
            'so_number' => '12345',
            'date' => now(),
            'status' => 'Active',
            'terms' => 'COD',
            'order_category' => Order::CATEGORY_CLIENT_ORDER,
            'fulfillment_type' => Order::FULFILLMENT_FUEL_TRADE,
            'qty_ordered' => 5000,
            'created_by' => $this->purchasing->id,
        ]);
    }

    protected function buyBackOrder(): Order
    {
        return Order::create([
            'account' => $this->client->name,
            'location' => 'Valenzuela',
            'so_number' => '67890',
            'date' => now(),
            'status' => 'Active',
            'terms' => 'COD',
            'order_category' => Order::CATEGORY_BUY_BACK,
            'fulfillment_type' => Order::FULFILLMENT_PICKUP,
            'qty_ordered' => 5000,
            'created_by' => $this->purchasing->id,
        ]);
    }

    /**
     * An ATL against a sales order, at a given PO approval state.
     */
    protected function atlFor(Order $order, string $poRequestStatus, string $category): PurchaseOrderDelivery
    {
        $po = PurchaseOrder::create([
            'po_number' => 'PO-'.uniqid(),
            'po_type' => PurchaseOrder::TYPE_FUEL_TRADE,
            'supplier_id' => $this->supplier->id,
            'supplier_name' => $this->supplier->company_name,
            'qty_ordered' => 5000,
            'total_amount' => 250000.00,
            'request_status' => $poRequestStatus,
            'status' => 'Pending',
            'linked_order_id' => $order->id,
            'prepared_by' => $this->purchasing->id,
        ]);

        return PurchaseOrderDelivery::create([
            'purchase_order_id' => $po->id,
            'order_id' => $order->id,
            'delivery_channel' => 'SUPPLIER_DOYEN_PICKUP',
            'atl_category' => $category,
            'atl_number' => 'ATL-'.uniqid(),
            'product' => 'Diesel',
            'qty_to_receive' => 5000,
            'status' => 'Active',
            'approval_status' => PurchaseOrderDelivery::APPROVAL_APPROVED,
        ]);
    }

    // ---- REF # ------------------------------------------------------------

    public function test_a_fuel_trade_atl_references_its_sales_order(): void
    {
        $order = $this->fuelTradeOrder();
        $atl = $this->atlFor($order, 'CONFIRMED', PurchaseOrderDelivery::CATEGORY_FUEL_TRADE);

        $this->assertSame('SO-FT 12345', $atl->refNumber());
    }

    public function test_a_buy_back_atl_references_its_sales_order(): void
    {
        $order = $this->buyBackOrder();
        $atl = $this->atlFor($order, 'CONFIRMED', PurchaseOrderDelivery::CATEGORY_BUY_BACK);

        $this->assertSame('SO-BB 67890', $atl->refNumber());
    }

    public function test_a_doyen_stocks_atl_references_its_stock_request(): void
    {
        $request = StockRequest::create([
            'requested_by' => $this->purchasing->id,
            'request_date' => now(),
        ]);

        $atl = PurchaseOrderDelivery::create([
            'request_id' => $request->id,
            'delivery_channel' => 'SUPPLIER_STOCKS_DELIVERY',
            'atl_category' => PurchaseOrderDelivery::CATEGORY_DOYEN_STOCKS,
            'atl_number' => 'ATL-'.uniqid(),
            'product' => 'Diesel',
            'qty_to_receive' => 10000,
            'status' => 'Active',
            'approval_status' => PurchaseOrderDelivery::APPROVAL_APPROVED,
        ]);

        $this->assertSame($request->request_number, $atl->refNumber());
        $this->assertStringStartsWith('REQ-', $atl->refNumber());
    }

    public function test_an_atl_with_no_source_still_gets_a_reference(): void
    {
        $atl = PurchaseOrderDelivery::create([
            'delivery_channel' => 'SUPPLIER_STOCKS_DELIVERY',
            'product' => 'Diesel',
            'qty_to_receive' => 100,
            'status' => 'Active',
        ]);

        $this->assertStringStartsWith('REQ-', $atl->refNumber());
    }

    // ---- Type -------------------------------------------------------------

    public function test_the_atl_type_reads_as_ft_buy_back_or_doyen_pick_up(): void
    {
        $fuelTrade = $this->atlFor(
            $this->fuelTradeOrder(), 'CONFIRMED', PurchaseOrderDelivery::CATEGORY_FUEL_TRADE
        );
        $buyBack = $this->atlFor(
            $this->buyBackOrder(), 'CONFIRMED', PurchaseOrderDelivery::CATEGORY_BUY_BACK
        );

        $pickupRequest = StockRequest::create([
            'requested_by' => $this->purchasing->id,
            'request_date' => now(),
        ]);
        $doyenPickup = PurchaseOrderDelivery::create([
            'request_id' => $pickupRequest->id,
            'delivery_channel' => 'SUPPLIER_DOYEN_PICKUP',
            'atl_category' => PurchaseOrderDelivery::CATEGORY_DOYEN_STOCKS,
            'product' => 'Diesel',
            'qty_to_receive' => 100,
            'status' => 'Active',
        ]);

        $this->assertSame('FT', $fuelTrade->typeLabel());
        $this->assertSame('BUY BACK', $buyBack->typeLabel());
        $this->assertSame('DOYEN - PICK UP', $doyenPickup->typeLabel());
    }

    // ---- VP gate on the ATL document --------------------------------------

    public function test_the_atl_document_is_blocked_before_the_vp_approves_the_po(): void
    {
        $order = $this->fuelTradeOrder();
        $atl = $this->atlFor($order, 'RECEIVED', PurchaseOrderDelivery::CATEGORY_FUEL_TRADE);

        $this->actingAs($this->purchasing)
            ->get(route('stock-orders.pdf', $atl->id))
            ->assertForbidden();
    }

    public function test_the_atl_document_is_available_once_the_vp_approves_the_po(): void
    {
        $order = $this->fuelTradeOrder();
        $atl = $this->atlFor($order, 'CONFIRMED', PurchaseOrderDelivery::CATEGORY_FUEL_TRADE);

        $this->actingAs($this->purchasing)
            ->get(route('stock-orders.pdf', $atl->id))
            ->assertStatus(200);
    }

    public function test_a_request_atl_needs_its_own_approval_rather_than_a_po(): void
    {
        $request = StockRequest::create([
            'requested_by' => $this->purchasing->id,
            'request_date' => now(),
        ]);

        $draft = PurchaseOrderDelivery::create([
            'request_id' => $request->id,
            'delivery_channel' => 'SUPPLIER_STOCKS_DELIVERY',
            'atl_category' => PurchaseOrderDelivery::CATEGORY_DOYEN_STOCKS,
            'atl_number' => 'ATL-'.uniqid(),
            'product' => 'Diesel',
            'qty_to_receive' => 100,
            'status' => 'Active',
            'approval_status' => PurchaseOrderDelivery::APPROVAL_FOR_APPROVAL,
        ]);

        $this->actingAs($this->purchasing)
            ->get(route('stock-orders.pdf', $draft->id))
            ->assertForbidden();

        $draft->update(['approval_status' => PurchaseOrderDelivery::APPROVAL_APPROVED]);

        $this->actingAs($this->purchasing)
            ->get(route('stock-orders.pdf', $draft->id))
            ->assertStatus(200);
    }

    public function test_the_po_page_hides_the_atl_link_before_the_vp_approves(): void
    {
        $order = $this->fuelTradeOrder();
        $atl = $this->atlFor($order, 'RECEIVED', PurchaseOrderDelivery::CATEGORY_FUEL_TRADE);

        $this->actingAs($this->purchasing)
            ->get(route('stock-orders.purchase-orders.show', $atl->purchase_order_id))
            ->assertStatus(200)
            ->assertDontSee(route('stock-orders.pdf', $atl->id), false);
    }

    // ---- The View pop-up --------------------------------------------------

    public function test_the_sales_order_page_opens_an_atl_pop_up_per_row(): void
    {
        $order = $this->fuelTradeOrder();
        $atl = $this->atlFor($order, 'CONFIRMED', PurchaseOrderDelivery::CATEGORY_FUEL_TRADE);

        $response = $this->actingAs($this->purchasing)
            ->get(route('stock-orders.sales-orders.show', $order->id));

        $response->assertStatus(200);
        $response->assertSee('atlDetailModal'.$atl->id, false);
        $response->assertSee('SO-FT 12345');
        $response->assertSee('>FT<', false);
    }

    public function test_the_atl_row_offers_exactly_view_and_pen(): void
    {
        $order = $this->fuelTradeOrder();
        $atl = $this->atlFor($order, 'CONFIRMED', PurchaseOrderDelivery::CATEGORY_FUEL_TRADE);

        $response = $this->actingAs($this->purchasing)
            ->get(route('stock-orders.sales-orders.show', $order->id));

        $response->assertStatus(200);
        $response->assertSee('> View', false);
        $response->assertSee('> Pen', false);
        // The PDF download moved into the pop-up, not the row.
        $response->assertDontSee('ATL PDF</a>', false);
    }

    public function test_the_atl_document_link_sits_inside_the_pop_up(): void
    {
        $order = $this->fuelTradeOrder();
        $atl = $this->atlFor($order, 'CONFIRMED', PurchaseOrderDelivery::CATEGORY_FUEL_TRADE);

        $response = $this->actingAs($this->purchasing)
            ->get(route('stock-orders.sales-orders.show', $order->id));

        $content = $response->getContent();
        $modalStart = strpos($content, 'atlDetailModal'.$atl->id);

        $this->assertNotFalse($modalStart, 'The ATL pop-up should be present');
        $this->assertStringContainsString(
            route('stock-orders.pdf', $atl->id),
            substr($content, $modalStart),
            'The VP-gated ATL PDF link belongs inside the pop-up'
        );
    }
}