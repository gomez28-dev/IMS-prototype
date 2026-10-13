<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use Tests\TestCase;

class SupplierPoNetPayableTest extends TestCase
{
    protected Admin $purchasing;

    protected function setUp(): void
    {
        parent::setUp();

        $this->purchasing = Admin::create([
            'username' => 'purchasing_' . uniqid(),
            'password' => bcrypt('secret'),
            'name' => 'Rica Esaga',
            'role' => 'purchasing',
        ]);
    }

    protected function supplier(): Supplier
    {
        return Supplier::create([
            'company_name' => 'Agieko Fuel Trading',
            'location' => 'Limay Terminal',
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    protected function createPo(array $overrides = []): PurchaseOrder
    {
        $this->actingAs($this->purchasing)->post(route('stock-orders.store-supplier-po'), array_merge([
            'po_number' => 'PO-NP-' . rand(1000, 9999),
            'supplier_id' => $this->supplier()->id,
            'po_type' => 'STANDARD_REPLENISHMENT',
            'items' => [
                // 220,000 x 89.15 = 19,613,000.00
                ['product' => 'Diesel', 'quantity' => 220000, 'unit_price' => 89.15],
            ],
        ], $overrides))->assertSessionHas('success');

        return PurchaseOrder::latest('id')->firstOrFail();
    }

    public function test_net_payable_is_total_amount_minus_withholding_tax(): void
    {
        $po = $this->createPo(['less_w_tax' => 1176780.00]);

        // 19,613,000.00 - 1,176,780.00
        $this->assertEquals(18436220.00, (float) $po->net_payable_amount);
    }

    public function test_submitted_net_payable_is_ignored_so_it_cannot_be_tampered_with(): void
    {
        $po = $this->createPo([
            'less_w_tax' => 100000.00,
            // A crafted POST must not be able to inflate the payable amount.
            'net_payable_amount' => 99999999.00,
        ]);

        $this->assertEquals(19513000.00, (float) $po->net_payable_amount);
    }

    public function test_net_payable_is_never_negative(): void
    {
        $po = $this->createPo(['less_w_tax' => 99999999.00]);

        $this->assertEquals(0.00, (float) $po->net_payable_amount);
    }

    public function test_net_payable_equals_the_total_when_no_withholding_tax_is_given(): void
    {
        $po = $this->createPo();

        $this->assertEquals(19613000.00, (float) $po->total_amount);
        $this->assertEquals(19613000.00, (float) $po->net_payable_amount);
    }

    public function test_net_payable_field_is_read_only_with_explanatory_helper_text(): void
    {
        $response = $this->actingAs($this->purchasing)->get(route('stock-orders.create-supplier-po'));

        $response->assertStatus(200);

        $html = $response->getContent();

        // The browser must not let anyone type into this field. Attribute
        // order varies, so match the whole <input> tag rather than a sequence.
        $this->assertMatchesRegularExpression(
            '/<input(?=[^>]*id="net_payable_amount")[^>]*\breadonly\b/',
            $html,
            'The Net Payable Amount input should be marked readonly.'
        );

        // The old "adjust if the terms differ" hint no longer applies.
        $this->assertStringNotContainsString('adjust if the terms differ', $html);
        $this->assertStringContainsString(
            'Automatically calculated as Total Amount minus Less w/ Tax',
            $html
        );
    }

    public function test_net_payable_recalculates_from_less_w_tax_in_the_browser(): void
    {
        $response = $this->actingAs($this->purchasing)->get(route('stock-orders.create-supplier-po'));

        $html = $response->getContent();

        // The live calculation has to read the withholding tax input.
        $this->assertStringContainsString("getElementById('less_w_tax')", $html);
        $this->assertStringContainsString('calculateNetPayable', $html);

        // And it must no longer stop tracking once the field has been typed in.
        $this->assertStringNotContainsString('dataset.touched', $html);
    }

    public function test_withholding_tax_is_still_stored_exactly_as_entered(): void
    {
        $po = $this->createPo([
            'vatable_sales_amount' => 19613000.00,
            'vat_amount' => 2931950.00,
            'less_w_tax' => 1176780.00,
        ]);

        // Vatable Sales and VAT Amount stay manual; only Net Payable is derived.
        $this->assertEquals(19613000.00, (float) $po->vatable_sales_amount);
        $this->assertEquals(2931950.00, (float) $po->vat_amount);
        $this->assertEquals(1176780.00, (float) $po->less_w_tax);
    }
}