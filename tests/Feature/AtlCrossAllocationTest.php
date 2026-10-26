<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\AtlAllocation;
use App\Models\Order;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderDelivery;
use App\Models\PurchaseOrderItem;
use App\Models\StockRequest;
use App\Models\StockRequestLine;
use App\Models\Supplier;
use App\Models\Warehouse;
use Tests\TestCase;

/**
 * One lift, one rack.
 *
 * A tanker is loaded at a single refinery terminal, so a single ATL may not
 * draw half its volume from one supplier's PO and half from another's - or
 * even from the same supplier at a different terminal. Splitting across POs
 * stays legal, but every row must agree on the same supplier AND the same
 * loading location.
 *
 * The rule lives on both forms: the Fuel Trade ATL form and the Wet Stock
 * request ATL form. The loading location is the PO's warehouse, falling back
 * to the supplier's own location when the PO has no warehouse.
 */
class AtlCrossAllocationTest extends TestCase
{
    protected Admin $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = Admin::create([
            'username' => 'admin_'.uniqid(),
            'password' => bcrypt('secret'),
            'name' => 'System Administrator',
            'role' => 'admin',
        ]);
    }

    protected function supplier(string $company, string $location): Supplier
    {
        return Supplier::create([
            'company_name' => $company,
            'location' => $location,
        ]);
    }

    protected function po(Supplier $supplier, ?Warehouse $warehouse, string $number, array $products): PurchaseOrder
    {
        $po = PurchaseOrder::create([
            'po_number' => $number,
            'po_type' => PurchaseOrder::TYPE_STANDARD_REPLENISHMENT,
            'supplier_id' => $supplier->id,
            'supplier_name' => $supplier->company_name,
            'warehouse_id' => $warehouse?->id,
            'po_date' => now()->toDateString(),
            'qty_ordered' => array_sum($products),
            'request_status' => 'CONFIRMED',
            'status' => 'Pending',
        ]);

        foreach ($products as $product => $qty) {
            PurchaseOrderItem::create([
                'purchase_order_id' => $po->id,
                'product' => $product,
                'quantity_ordered' => $qty,
            ]);
        }

        return $po;
    }

    // ---- The Fuel Trade form --------------------------------------------

    protected function salesOrder(): Order
    {
        return Order::create([
            'account' => 'Apex Logistics Corp',
            'location' => 'Valenzuela',
            'so_number' => 'SO-XA-'.rand(100000, 999999),
            'date' => now(),
            'qty_ordered' => 10000,
            'price' => 52.00,
            'status' => 'Active',
            'clearing_status' => 'Approved',
            'fulfillment_type' => Order::FULFILLMENT_FUEL_TRADE,
            'order_category' => Order::CATEGORY_CLIENT_ORDER,
        ]);
    }

    public function test_a_fuel_trade_atl_cannot_mix_two_suppliers(): void
    {
        $rack = Warehouse::create(['name' => 'Limay Rack '.rand(1000, 9999)]);
        $petron = $this->supplier('Petron Bataan Refinery', 'Limay');
        $shell = $this->supplier('Shell Tabangao', 'Tabangao');
        $petronPo = $this->po($petron, $rack, 'PO-XA-P-'.rand(1000, 9999), ['Diesel' => 8000]);
        $shellPo = $this->po($shell, $rack, 'PO-XA-S-'.rand(1000, 9999), ['Diesel' => 8000]);

        $response = $this->actingAs($this->admin)
            ->post(route('stock-orders.store-fuel-trade-po', $this->salesOrder()->id), [
                'receiving_date' => now()->addDays(2)->format('Y-m-d'),
                'atl_type' => 'DITC_ATL',
                'atl_number' => 'ATL-XA-'.uniqid(),
                'drawdowns' => [
                    ['purchase_order_id' => $petronPo->id, 'product' => 'Diesel', 'quantity' => 5000],
                    ['purchase_order_id' => $shellPo->id, 'product' => 'Diesel', 'quantity' => 5000],
                ],
            ]);

        $response->assertSessionHasErrors('drawdowns');
        $this->assertSame(
            0,
            PurchaseOrderDelivery::count(),
            'A mixed-supplier ATL must not be created.'
        );

        // Both POs keep their balances: the refusal must not have drawn.
        $this->assertSame(8000, $petronPo->fresh()->getAvailableBalanceForProduct('Diesel'));
        $this->assertSame(8000, $shellPo->fresh()->getAvailableBalanceForProduct('Diesel'));
    }

    public function test_two_pos_of_one_supplier_at_two_racks_cannot_mix(): void
    {
        $supplier = $this->supplier('Petron Bataan Refinery', 'Limay');
        $limay = Warehouse::create(['name' => 'Limay Rack '.rand(1000, 9999)]);
        $mariveles = Warehouse::create(['name' => 'Mariveles Rack '.rand(1000, 9999)]);
        $limayPo = $this->po($supplier, $limay, 'PO-XA-L-'.rand(1000, 9999), ['Diesel' => 8000]);
        $marivelesPo = $this->po($supplier, $mariveles, 'PO-XA-M-'.rand(1000, 9999), ['Diesel' => 8000]);

        $this->actingAs($this->admin)
            ->post(route('stock-orders.store-fuel-trade-po', $this->salesOrder()->id), [
                'receiving_date' => now()->addDays(2)->format('Y-m-d'),
                'atl_type' => 'DITC_ATL',
                'atl_number' => 'ATL-XA-'.uniqid(),
                'drawdowns' => [
                    ['purchase_order_id' => $limayPo->id, 'product' => 'Diesel', 'quantity' => 5000],
                    ['purchase_order_id' => $marivelesPo->id, 'product' => 'Diesel', 'quantity' => 5000],
                ],
            ])
            ->assertSessionHasErrors('drawdowns');

        $this->assertSame(0, PurchaseOrderDelivery::count());
    }

    public function test_rows_from_one_supplier_at_one_rack_still_save(): void
    {
        $supplier = $this->supplier('Petron Bataan Refinery', 'Limay');
        $rack = Warehouse::create(['name' => 'Limay Rack '.rand(1000, 9999)]);
        $first = $this->po($supplier, $rack, 'PO-XA-A-'.rand(1000, 9999), ['Diesel' => 4000]);
        $second = $this->po($supplier, $rack, 'PO-XA-B-'.rand(1000, 9999), ['Diesel' => 6000]);

        $this->actingAs($this->admin)
            ->post(route('stock-orders.store-fuel-trade-po', $this->salesOrder()->id), [
                'receiving_date' => now()->addDays(2)->format('Y-m-d'),
                'atl_type' => 'DITC_ATL',
                'atl_number' => 'ATL-XA-OK-'.uniqid(),
                'drawdowns' => [
                    ['purchase_order_id' => $first->id, 'product' => 'Diesel', 'quantity' => 4000],
                    ['purchase_order_id' => $second->id, 'product' => 'Diesel', 'quantity' => 6000],
                ],
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(1, PurchaseOrderDelivery::count());
    }

    // ---- The Wet Stock request form -------------------------------------

    protected function request(array $lines = [['Diesel', 50000]]): StockRequest
    {
        $request = StockRequest::create([
            'request_number' => 'REQ-XA-'.rand(1000, 9999),
            'request_type' => StockRequest::TYPE_PICKUP,
            'warehouse_id' => Warehouse::create(['name' => 'Bataan Depot '.rand(1000, 9999)])->id,
            'qty_requested' => array_sum(array_column($lines, 1)),
            'status' => StockRequest::STATUS_REQUESTED,
            'requested_by' => $this->admin->id,
            'request_date' => now(),
        ]);

        foreach ($lines as [$product, $qty]) {
            StockRequestLine::create([
                'stock_request_id' => $request->id,
                'product' => $product,
                'qty_requested' => $qty,
            ]);
        }

        return $request;
    }

    public function test_a_request_atl_cannot_mix_two_suppliers(): void
    {
        $rack = Warehouse::create(['name' => 'Limay Rack '.rand(1000, 9999)]);
        $petron = $this->supplier('Petron Bataan Refinery', 'Limay');
        $shell = $this->supplier('Shell Tabangao', 'Tabangao');
        $petronPo = $this->po($petron, $rack, 'PO-XA-RP-'.rand(1000, 9999), ['Diesel' => 30000]);
        $shellPo = $this->po($shell, $rack, 'PO-XA-RS-'.rand(1000, 9999), ['Diesel' => 30000]);
        $request = $this->request();

        $this->actingAs($this->admin)
            ->post(route('stock-orders.wet-stock-requests.atls.store', $request->id), [
                'drawdowns' => [
                    ['purchase_order_id' => $petronPo->id, 'product' => 'Diesel', 'quantity' => 30000],
                    ['purchase_order_id' => $shellPo->id, 'product' => 'Diesel', 'quantity' => 20000],
                ],
                'order_type' => 'DELIVERY',
                'delivery_channel' => 'SUPPLIER_STOCKS_DELIVERY',
                'atl_type' => 'DITC_ATL',
                'atl_number' => 'ATL-XA-'.uniqid(),
                'receiving_date' => now()->addDays(3)->format('Y-m-d'),
            ])
            ->assertSessionHasErrors('drawdowns');

        $this->assertSame(0, PurchaseOrderDelivery::where('request_id', $request->id)->count());

        // No allocation was written either: the refusal precedes all drawing.
        $this->assertSame(
            0,
            AtlAllocation::whereIn('purchase_order_id', [$petronPo->id, $shellPo->id])->count()
        );
    }

    // ---- The form marks each PO so the dropdown can pair on it ----------

    public function test_the_fuel_trade_form_screen_pos_mark_their_supplier_and_rack(): void
    {
        $supplier = $this->supplier('Petron Bataan Refinery', 'Limay');
        $rack = Warehouse::create(['name' => 'Limay Rack '.rand(1000, 9999)]);
        $this->po($supplier, $rack, 'PO-XA-F-'.rand(1000, 9999), ['Diesel' => 50000]);

        $html = $this->actingAs($this->admin)
            ->get(route('stock-orders.create-fuel-trade-po', $this->salesOrder()->id))
            ->assertOk()
            ->getContent();

        // The pairing data the JS filters rows 2+ on, and the rack the
        // Refinery Rack field auto-fills from.
        $this->assertStringContainsString('data-alloc-key=', $html);
        $this->assertStringContainsString('data-rack=', $html);
        $this->assertStringContainsString('updateRackLocation', $html);
    }

    public function test_the_request_form_screen_pos_mark_their_supplier_and_rack(): void
    {
        $supplier = $this->supplier('Shell Tabangao', 'Tabangao');
        $rack = Warehouse::create(['name' => 'Tabangao Rack '.rand(1000, 9999)]);
        $this->po($supplier, $rack, 'PO-XA-W-'.rand(1000, 9999), ['Diesel' => 50000]);

        $html = $this->actingAs($this->admin)
            ->get(route('stock-orders.wet-stock-requests.atls.create', $this->request()->id))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('data-alloc-key=', $html);
    }
}
