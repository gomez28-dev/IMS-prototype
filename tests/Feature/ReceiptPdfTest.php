<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Delivery;
use App\Models\Order;
use App\Models\OrderItem;
use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\PDF as PdfDocument;
use Illuminate\Http\Response;
use Mockery;
use Tests\TestCase;

/**
 * The Sales Order Receipt and Delivery Receipt PDFs.
 *
 * Both documents are generated from a Blade view. The controller is asked for
 * the view and the data it would render, the data is checked, and then the
 * same view is rendered in-process so the printed figures are verified too.
 */
class ReceiptPdfTest extends TestCase
{
    protected Admin $admin;

    /** @var array{0: string, 1: array}|null */
    protected ?array $capturedView = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = Admin::create([
            'username' => 'admin_'.uniqid(),
            'password' => bcrypt('secret'),
            'name' => 'Admin User',
            'role' => 'admin',
        ]);
    }

    protected function order(string $soNumber, string $vatType = 'VAT_EX'): Order
    {
        return Order::create([
            'so_number' => $soNumber,
            'account' => 'ACME Fuels',
            'date' => now(),
            'qty_ordered' => 0,
            'terms' => 'COD',
            'po_number' => 'PO-ACME-1',
            'vat_type' => $vatType,
            'status' => 'Active',
        ]);
    }

    /**
     * Capture what the controller asks DomPDF to render, and stand in for the
     * generated document so no PDF is actually built.
     */
    protected function fakePdf(string $expectedView): void
    {
        $this->capturedView = null;

        $pdf = Mockery::mock(PdfDocument::class);
        $pdf->shouldReceive('setPaper')->once()->with('a5', 'landscape')->andReturnSelf();
        $pdf->shouldReceive('download')
            ->once()
            ->andReturn(new Response('', 200, ['Content-Type' => 'application/pdf']));

        Pdf::shouldReceive('loadView')
            ->once()
            ->withArgs(function ($view, $data) use ($expectedView) {
                $this->capturedView = [$view, $data];

                return $view === $expectedView;
            })
            ->andReturn($pdf);
    }

    public function test_the_sales_order_receipt_adds_vat_on_top_of_a_vat_inclusive_order(): void
    {
        $order = $this->order('SO-RECEIPT-1', 'VAT_INC');

        OrderItem::create([
            'order_id' => $order->id,
            'product_type' => 'D',
            'qty' => 1000,
            'price' => 100,
        ]);
        OrderItem::create([
            'order_id' => $order->id,
            'product_type' => 'P',
            'qty' => 500,
            'price' => 120,
        ]);

        $this->fakePdf('deliveries.receipt-pdf');

        $this->actingAs($this->admin)
            ->get(route('order.receipt', $order->id))
            ->assertOk();

        [$view, $data] = $this->capturedView;

        // 100,000 + 60,000 = 160,000; VAT is 12% of that.
        $this->assertEqualsWithDelta(160000.0, $data['subtotal'], 0.001);
        $this->assertTrue($data['vatApplied']);
        $this->assertSame('VAT INC. (12%)', $data['vatLabel']);
        $this->assertEqualsWithDelta(19200.0, $data['vatAmount'], 0.001);
        $this->assertEqualsWithDelta(179200.0, $data['totalDue'], 0.001);
        $this->assertSame(1500, $data['totalQty']);

        $html = view($view, $data)->render();
        $this->assertStringContainsString('VAT INC. (12%)', $html);
        $this->assertStringContainsString('19,200.00', $html);
        $this->assertStringContainsString('179,200.00', $html);
        $this->assertStringContainsString('Diesel', $html);
        $this->assertStringContainsString('Premium', $html);
    }

    public function test_the_sales_order_receipt_adds_no_vat_to_a_vat_exempt_order(): void
    {
        $order = $this->order('SO-RECEIPT-2', 'VAT_EX');

        OrderItem::create([
            'order_id' => $order->id,
            'product_type' => 'D',
            'qty' => 1000,
            'price' => 100,
        ]);

        $this->fakePdf('deliveries.receipt-pdf');

        $this->actingAs($this->admin)
            ->get(route('order.receipt', $order->id))
            ->assertOk();

        [$view, $data] = $this->capturedView;

        $this->assertEqualsWithDelta(100000.0, $data['subtotal'], 0.001);
        $this->assertFalse($data['vatApplied']);
        $this->assertSame('VAT EX.', $data['vatLabel']);
        $this->assertEqualsWithDelta(0.0, $data['vatAmount'], 0.001);
        $this->assertEqualsWithDelta(100000.0, $data['totalDue'], 0.001);

        $html = view($view, $data)->render();
        $this->assertStringContainsString('VAT EX.', $html);
        $this->assertStringNotContainsString('Add: VAT (12%)', $html);
        $this->assertStringContainsString('100,000.00', $html);
    }

    public function test_the_sales_order_receipt_falls_back_to_the_order_totals_for_a_legacy_order(): void
    {
        // No order_items rows: older orders only carry qty_ordered / price.
        $order = $this->order('SO-RECEIPT-3');
        $order->update(['qty_ordered' => 2000, 'price' => 50]);

        $this->fakePdf('deliveries.receipt-pdf');

        $this->actingAs($this->admin)
            ->get(route('order.receipt', $order->id))
            ->assertOk();

        [, $data] = $this->capturedView;

        $this->assertCount(1, $data['rows']);
        $this->assertSame('Unspecified', $data['rows'][0]['product']);
        $this->assertSame(2000, $data['rows'][0]['qty']);
        $this->assertEqualsWithDelta(50.0, $data['rows'][0]['price'], 0.001);
        $this->assertEqualsWithDelta(100000.0, $data['rows'][0]['amount'], 0.001);
        $this->assertEqualsWithDelta(100000.0, $data['subtotal'], 0.001);
    }

    public function test_the_sales_order_receipt_downloads_as_a_pdf(): void
    {
        $order = $this->order('SO-RECEIPT-4');
        OrderItem::create([
            'order_id' => $order->id,
            'product_type' => 'D',
            'qty' => 1000,
            'price' => 100,
        ]);

        $this->actingAs($this->admin)
            ->get(route('order.receipt', $order->id))
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');
    }

    public function test_the_delivery_receipt_prints_the_address_and_dr_number(): void
    {
        $order = $this->order('SO-RECEIPT-5');

        $delivery = Delivery::create([
            'order_id' => $order->id,
            'dr_number' => 'DR-RECEIPT-9001',
            'delivery_date' => now(),
            'qty_out' => 1000,
            'status' => 'PENDING',
            'product_type' => 'D',
            'delivery_address' => "123 Mabini St.\nBarangay San Jose",
            'remarks' => 'Deliver before noon',
        ]);

        $this->fakePdf('deliveries.delivery-receipt-pdf');

        $this->actingAs($this->admin)
            ->get(route('delivery.receipt', $delivery->id))
            ->assertOk();

        [$view, $data] = $this->capturedView;

        $this->assertSame('DR-RECEIPT-9001', $data['delivery']->dr_number);

        $html = view($view, $data)->render();
        $this->assertStringContainsString('DR-RECEIPT-9001', $html);
        $this->assertStringContainsString('123 Mabini St.', $html);
        $this->assertStringContainsString('Barangay San Jose', $html);
        $this->assertStringContainsString('ACME Fuels', $html);
        $this->assertStringContainsString('Deliver before noon', $html);
    }

    public function test_the_delivery_receipt_uses_its_compartment_lines_when_present(): void
    {
        $order = $this->order('SO-RECEIPT-6');

        $delivery = Delivery::create([
            'order_id' => $order->id,
            'dr_number' => 'DR-RECEIPT-9002',
            'delivery_date' => now(),
            'qty_out' => 1500,
            'status' => 'PENDING',
        ]);

        $delivery->items()->create([
            'product_type' => 'D',
            'qty_out' => 1000,
            'compartment_no' => 1,
        ]);
        $delivery->items()->create([
            'product_type' => 'P',
            'qty_out' => 500,
            'compartment_no' => 2,
        ]);

        $this->fakePdf('deliveries.delivery-receipt-pdf');

        $this->actingAs($this->admin)
            ->get(route('delivery.receipt', $delivery->id))
            ->assertOk();

        [, $data] = $this->capturedView;

        $this->assertCount(2, $data['rows']);
        $this->assertSame('Diesel', $data['rows'][0]['product']);
        $this->assertSame(1000, $data['rows'][0]['qty']);
        $this->assertSame('Premium', $data['rows'][1]['product']);
        $this->assertSame(500, $data['rows'][1]['qty']);
    }
}
