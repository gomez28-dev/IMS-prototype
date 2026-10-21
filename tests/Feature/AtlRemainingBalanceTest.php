<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderDelivery;
use App\Models\PurchaseOrderItem;
use App\Models\Supplier;
use Tests\TestCase;

/**
 * The remaining balance on the Create ATL form.
 *
 * The form used to speak the order's full quantity - "200 L of 300 L, short
 * by 100 L" - while the validation sitting beside it spoke in remaining
 * terms ("with the 150 L already ATL'd, that would take the total to 350").
 * The display now uses the same measure as the validation: ordered volume
 * minus the volume the order's live ATLs carry.
 *
 * An ATL counts toward that figure only while it lives. Rejected and
 * cancelled ones release their volume back; pending, approved and
 * client-provided ones hold it.
 */
class AtlRemainingBalanceTest extends TestCase
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

    /**
     * FT-7788's shape: Diesel 300 L and Unleaded 200 L.
     */
    protected function twoProductOrder(): Order
    {
        $order = Order::create([
            'so_number' => (string) rand(1000, 9999),
            'account' => 'A Test Only',
            'date' => now()->toDateString(),
            'location' => 'Valenzuela',
            'status' => 'Active',
            'qty_ordered' => 500,
            'clearing_status' => 'Approved',
            'fulfillment_type' => Order::FULFILLMENT_FUEL_TRADE,
            'order_category' => Order::CATEGORY_CLIENT_ORDER,
        ]);

        OrderItem::create(['order_id' => $order->id, 'product_type' => 'D', 'qty' => 300]);
        OrderItem::create(['order_id' => $order->id, 'product_type' => 'U', 'qty' => 200]);

        return $order;
    }

    /**
     * @param  array<string, int>  $products
     */
    protected function po(string $number, array $products): PurchaseOrder
    {
        $supplier = Supplier::create([
            'company_name' => 'Flow Refinery '.rand(1000, 9999),
            'location' => 'Limay Terminal',
        ]);

        $po = PurchaseOrder::create([
            'po_number' => $number,
            'po_type' => PurchaseOrder::TYPE_STANDARD_REPLENISHMENT,
            'supplier_id' => $supplier->id,
            'supplier_name' => $supplier->company_name,
            'po_date' => now()->toDateString(),
            'qty_ordered' => array_sum($products),
            'request_status' => 'CONFIRMED',
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

    /**
     * Issue an ATL through the real store route.
     *
     * @param  array<int, array{0: string, 1: int}>  $lines  [product, qty] per row
     */
    protected function issueAtl(Order $order, PurchaseOrder $po, array $lines): PurchaseOrderDelivery
    {
        $this->actingAs($this->admin)
            ->post(route('stock-orders.store-fuel-trade-po', $order->id), [
                'drawdowns' => collect($lines)
                    ->map(fn (array $l) => ['purchase_order_id' => $po->id, 'product' => $l[0], 'quantity' => $l[1]])
                    ->all(),
                'receiving_date' => now()->addDay()->format('Y-m-d'),
                'driver_name' => 'Juan Dela Cruz',
                'plate_number' => 'NBD-1234',
                'location' => 'Petron Limay Terminal',
                'atl_type' => 'DITC_ATL',
                'atl_number' => 'ATL-'.rand(100000, 999999),
            ])
            ->assertSessionHas('success');

        return PurchaseOrderDelivery::where('order_id', $order->id)->latest('id')->firstOrFail();
    }

    protected function formHtml(Order $order): string
    {
        return $this->actingAs($this->admin)
            ->get(route('stock-orders.create-fuel-trade-po', $order->id))
            ->assertOk()
            ->getContent();
    }

    // ---- The display -------------------------------------------------------

    public function test_a_fresh_order_displays_its_remaining_balance(): void
    {
        $order = $this->twoProductOrder();
        $this->po('PO-REM-'.rand(1000, 9999), ['Diesel' => 1000, 'Unleaded' => 1000]);

        $html = $this->formHtml($order);

        // Nothing issued yet, so the remaining balance is the whole order. Both
        // states have to be carried into the script the comparisons run in.
        $this->assertStringContainsString('const soRemaining = {"Diesel":300,"Unleaded":200}', $html);

        // And the visible note says it per product, not as one flat number.
        $this->assertStringContainsString('Remaining un-ATL volume', $html);
        $this->assertStringContainsString('Diesel remaining: 300 L', $html);
        $this->assertStringContainsString('Unleaded remaining: 200 L', $html);
    }

    public function test_previous_atls_shrink_the_remaining_balance(): void
    {
        $order = $this->twoProductOrder();
        $po = $this->po('PO-REM-'.rand(1000, 9999), ['Diesel' => 2000, 'Unleaded' => 2000]);
        $this->issueAtl($order, $po, [['Diesel', 150], ['Unleaded', 150]]);

        $html = $this->formHtml($order);

        // First ATL took 150 of each; the form must show 150 Diesel and 50
        // Unleaded left - not 300 and 200.
        $this->assertStringContainsString('const soRemaining = {"Diesel":150,"Unleaded":50}', $html);
        $this->assertStringContainsString('Diesel remaining: 150 L', $html);
        $this->assertStringContainsString('Unleaded remaining: 50 L', $html);

        // The full ordered quantity still travels with the form, because the
        // breakdown quotes both figures ("150 L of 150 L remaining (300 ordered)").
        $this->assertStringContainsString(
            '{"Diesel":300,"Unleaded":200}',
            html_entity_decode($html),
            'soRequirements must keep carrying the ordered volume.'
        );
    }

    public function test_the_row_suggestion_starts_from_the_remaining_balance(): void
    {
        $order = $this->twoProductOrder();
        $po = $this->po('PO-REM-'.rand(1000, 9999), ['Diesel' => 2000, 'Unleaded' => 2000]);
        $this->issueAtl($order, $po, [['Diesel', 150], ['Unleaded', 150]]);

        $html = $this->formHtml($order);

        // The suggested first-row quantity used min(ordered, PO available) -
        // 300 here - and would again talk the user into over-drawing. It now
        // starts from what is still un-ATL'd.
        $this->assertMatchesRegularExpression(
            '/name="drawdowns\[0\]\[quantity\]"[^>]*value="150"/s',
            $html,
            'The first row should suggest the remaining 150 L of Diesel.'
        );
    }

    // ---- An ATL holds its volume only while it lives ------------------------

    public function test_a_pending_atl_holds_its_volume(): void
    {
        $order = $this->twoProductOrder();
        $po = $this->po('PO-REM-'.rand(1000, 9999), ['Diesel' => 2000, 'Unleaded' => 2000]);
        $this->issueAtl($order, $po, [['Diesel', 150], ['Unleaded', 150]]);

        // Awaiting VP approval is still a real draw: the volume is committed.
        $this->assertSame(
            ['Diesel' => 150, 'Unleaded' => 150],
            $order->fresh()->drawnByProduct()
        );
    }

    public function test_a_rejected_atl_releases_its_volume(): void
    {
        $order = $this->twoProductOrder();
        $po = $this->po('PO-REM-'.rand(1000, 9999), ['Diesel' => 2000, 'Unleaded' => 2000]);
        $atl = $this->issueAtl($order, $po, [['Diesel', 150], ['Unleaded', 150]]);

        $this->actingAs($this->admin)
            ->post(route('stock-orders.atl-reject', $atl->id), ['rejection_reason' => 'Wrong PO drawn.']);

        $this->assertSame([], $order->fresh()->drawnByProduct(), 'A rejected ATL committed nothing.');

        $html = $this->formHtml($order);
        $this->assertStringContainsString('const soRemaining = {"Diesel":300,"Unleaded":200}', $html);
    }

    public function test_a_cancelled_atl_releases_its_volume(): void
    {
        $order = $this->twoProductOrder();
        $po = $this->po('PO-REM-'.rand(1000, 9999), ['Diesel' => 2000, 'Unleaded' => 2000]);
        $atl = $this->issueAtl($order, $po, [['Diesel', 150], ['Unleaded', 150]]);

        $atl->update(['status' => 'Cancelled']);

        $this->assertSame([], $order->fresh()->drawnByProduct());
    }

    public function test_an_approved_atl_still_holds_its_volume(): void
    {
        $order = $this->twoProductOrder();
        $po = $this->po('PO-REM-'.rand(1000, 9999), ['Diesel' => 2000, 'Unleaded' => 2000]);
        $atl = $this->issueAtl($order, $po, [['Diesel', 150], ['Unleaded', 150]]);

        $this->actingAs($this->admin)->post(route('stock-orders.atl-approve', $atl->id));

        $this->assertSame(
            ['Diesel' => 150, 'Unleaded' => 150],
            $order->fresh()->drawnByProduct()
        );
    }

    // ---- Display and validation speak the same language ---------------------

    public function test_the_validator_keeps_talking_in_cumulative_terms(): void
    {
        $order = $this->twoProductOrder();
        $po = $this->po('PO-REM-'.rand(1000, 9999), ['Diesel' => 2000, 'Unleaded' => 2000]);
        $this->issueAtl($order, $po, [['Diesel', 150], ['Unleaded', 150]]);

        // 150 L is already ATL'd, so 200 L more would take the order to 350 of
        // 300 - refused, with the message that names the previously ATL'd
        // volume. This is the wording the new display stays consistent with.
        $this->actingAs($this->admin)
            ->post(route('stock-orders.store-fuel-trade-po', $order->id), [
                'drawdowns' => [['purchase_order_id' => $po->id, 'product' => 'Diesel', 'quantity' => 200]],
                'receiving_date' => now()->addDay()->format('Y-m-d'),
                'atl_type' => 'DITC_ATL',
                'atl_number' => 'ATL-'.rand(100000, 999999),
            ])
            ->assertSessionHasErrors('drawdowns');

        $final = PurchaseOrderDelivery::where('order_id', $order->id)->count();
        $this->assertSame(1, $final, 'The over-remaining draw created no second ATL.');

        // Rejected volume is released, so the same draw that over-ran before...
        $atl = PurchaseOrderDelivery::where('order_id', $order->id)->firstOrFail();
        $this->actingAs($this->admin)->post(route('stock-orders.atl-reject', $atl->id), [
            'rejection_reason' => 'Released for the consistency check.',
        ]);

        // ...is accepted once the rejected ATL no longer holds the volume.
        $this->actingAs($this->admin)
            ->post(route('stock-orders.store-fuel-trade-po', $order->id), [
                'drawdowns' => [['purchase_order_id' => $po->id, 'product' => 'Diesel', 'quantity' => 200]],
                'receiving_date' => now()->addDay()->format('Y-m-d'),
                'atl_type' => 'DITC_ATL',
                'atl_number' => 'ATL-'.rand(100000, 999999),
            ])
            ->assertSessionHas('success');

        $this->assertSame(
            ['Diesel' => 200],
            $order->fresh()->drawnByProduct(),
            'The released volume is issuable again.'
        );
    }
}
