<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\AtlAllocation;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderDelivery;
use App\Models\PurchaseOrderItem;
use App\Models\Supplier;
use Tests\TestCase;

class PurchaseOrderVolumeCardsTest extends TestCase
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

    protected function po(array $lines, string $requestStatus = 'CONFIRMED'): PurchaseOrder
    {
        $po = PurchaseOrder::create([
            'po_number' => 'PO-VOL-'.uniqid(),
            'po_type' => 'STANDARD_REPLENISHMENT',
            'supplier_id' => $this->supplier->id,
            'supplier_name' => $this->supplier->company_name,
            'qty_ordered' => array_sum($lines),
            'total_amount' => 500000.00,
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

    protected function atl(PurchaseOrder $po, string $liftStatus, int $quantity, string $channel = 'TRUCKED'): PurchaseOrderDelivery
    {
        $delivery = PurchaseOrderDelivery::create([
            'purchase_order_id' => $po->id,
            'delivery_channel' => $channel,
            'order_type' => 'PICK_UP',
            'atl_type' => 'DITC_ATL',
            'atl_number' => 'ATL-'.uniqid(),
            'product' => 'Diesel',
            'qty_to_receive' => $quantity,
            'receiving_date' => now()->addDay(),
            'status' => 'Dispatched',
            'lift_status' => $liftStatus,
            'approval_status' => PurchaseOrderDelivery::APPROVAL_APPROVED,
        ]);

        AtlAllocation::create([
            'purchase_order_delivery_id' => $delivery->id,
            'purchase_order_id' => $po->id,
            'product' => 'Diesel',
            'quantity' => $quantity,
        ]);

        return $delivery;
    }

    public function test_ordered_volume_is_split_by_product(): void
    {
        $this->po(['Diesel' => 10000, 'Premium' => 4000, 'Unleaded' => 6000]);

        $volumes = PurchaseOrder::volumeTotals();

        $this->assertEquals(10000, $volumes['ordered']['Diesel']);
        $this->assertEquals(4000, $volumes['ordered']['Premium']);
        $this->assertEquals(6000, $volumes['ordered']['Unleaded']);
    }

    public function test_lifted_and_unlifted_are_split_by_product(): void
    {
        $po = $this->po(['Diesel' => 20000]);

        $this->atl($po, PurchaseOrderDelivery::LIFT_LIFTED, 8000);
        $this->atl($po, PurchaseOrderDelivery::LIFT_UNLIFTED, 5000);

        $volumes = PurchaseOrder::volumeTotals();

        $this->assertEquals(8000, $volumes['lifted']['Diesel']);
        $this->assertEquals(5000, $volumes['unlifted']['Diesel']);
    }

    public function test_unlifted_means_an_atl_is_outstanding_not_merely_unordered(): void
    {
        $po = $this->po(['Diesel' => 20000]);

        // Only 5,000 L has an ATL; the other 15,000 was never ATL'd at all.
        $this->atl($po, PurchaseOrderDelivery::LIFT_UNLIFTED, 5000);

        $volumes = PurchaseOrder::volumeTotals();

        // 15,000 L ordered without an ATL must not be counted as unlifted.
        $this->assertEquals(5000, $volumes['unlifted']['Diesel']);
        $this->assertEquals(20000, $volumes['ordered']['Diesel']);
    }

    public function test_cancelled_deliveries_are_counted_as_neither_lifted_nor_unlifted(): void
    {
        $po = $this->po(['Diesel' => 20000]);

        $cancelled = $this->atl($po, PurchaseOrderDelivery::LIFT_UNLIFTED, 5000);
        $cancelled->update([
            'status' => 'Cancelled',
            'lift_status' => PurchaseOrderDelivery::LIFT_CANCELLED,
        ]);

        $volumes = PurchaseOrder::volumeTotals();

        $this->assertEquals(0, $volumes['unlifted']['Diesel']);
        $this->assertEquals(0, $volumes['lifted']['Diesel']);
    }

    public function test_every_product_always_reports_a_figure(): void
    {
        // Only Diesel has any rows; the other two must still appear as zero.
        $this->po(['Diesel' => 10000]);

        $volumes = PurchaseOrder::volumeTotals();

        foreach (['ordered', 'lifted', 'unlifted'] as $bucket) {
            $this->assertArrayHasKey('Diesel', $volumes[$bucket]);
            $this->assertArrayHasKey('Unleaded', $volumes[$bucket]);
            $this->assertArrayHasKey('Premium', $volumes[$bucket]);
            $this->assertEquals(0, $volumes[$bucket]['Premium']);
        }
    }

    public function test_volumes_can_be_scoped_to_a_single_purchase_order(): void
    {
        $mine = $this->po(['Diesel' => 10000]);
        $other = $this->po(['Diesel' => 7000, 'Premium' => 3000]);

        $this->atl($mine, PurchaseOrderDelivery::LIFT_LIFTED, 4000);
        $this->atl($other, PurchaseOrderDelivery::LIFT_LIFTED, 9000);

        $scoped = PurchaseOrder::volumeTotals($mine->id);

        $this->assertEquals(10000, $scoped['ordered']['Diesel']);
        $this->assertEquals(4000, $scoped['lifted']['Diesel']);
        $this->assertEquals(0, $scoped['ordered']['Premium']);

        // System-wide figures still see both POs.
        $all = PurchaseOrder::volumeTotals();
        $this->assertEquals(17000, $all['ordered']['Diesel']);
        $this->assertEquals(13000, $all['lifted']['Diesel']);
    }

    public function test_the_list_renders_the_volume_cards(): void
    {
        $po = $this->po(['Diesel' => 10000]);
        $this->atl($po, PurchaseOrderDelivery::LIFT_LIFTED, 4000);
        $this->atl($po, PurchaseOrderDelivery::LIFT_UNLIFTED, 1000);

        $response = $this->actingAs($this->purchasing)
            ->get(route('stock-orders.purchase-orders.index'));

        $response->assertStatus(200);
        $response->assertSee('Total Orders');
        $response->assertSee('Total Qty. Ordered');
        $response->assertSee('Total Qty. Lifted');
        $response->assertSee('Total Qty. Unlifted');
        $this->assertStringContainsString('10,000', $response->getContent());
        $this->assertStringContainsString('4,000', $response->getContent());
        $this->assertStringContainsString('1,000', $response->getContent());
    }
}