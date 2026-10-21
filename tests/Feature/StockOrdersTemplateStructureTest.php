<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * The last round shipped two layout breakages that the entire behavioural suite
 * passed straight through, because nothing looked at structure - only that a
 * page returned 200 and contained certain strings. An unclosed <div> inside a
 * flex header does neither: the page renders, it just renders wrong.
 *
 * Both were the same mistake - a <div> opened and never closed - and both put
 * the rest of a card's contents inside a d-flex parent, so a title and a form
 * ended up side by side and a table overflowed its card.
 *
 * These tests are deliberately blunt: count the tags.
 */
class StockOrdersTemplateStructureTest extends TestCase
{
    /**
     * Every Blade template these round-4 changes touched, plus the nav layout.
     *
     * @return array<int, string>
     */
    protected function templates(): array
    {
        return [
            'resources/views/wetstock/stock-orders/create-po.blade.php',
            'resources/views/wetstock/stock-orders/form.blade.php',
            'resources/views/wetstock/purchase-orders/index.blade.php',
            'resources/views/wetstock/sales-orders/index.blade.php',
            'resources/views/wetstock/sales-orders/show.blade.php',
            'resources/views/layouts/app.blade.php',
        ];
    }

    /**
     * Counts opening and closing tags on a line, ignoring Blade comments and
     * the {!! !!} / {{ }} forms, which cannot contain structural markup.
     */
    protected function divCounts(string $source): array
    {
        // Blade comments and echoes are stripped first: a {{ }} span may contain
        // the characters "<div" in a string without being a real element.
        $source = preg_replace('/\{\{--.*?--\}\}/s', '', $source);
        $source = preg_replace('/\{\{.*?\}\}/s', '', $source);
        $source = preg_replace('/\{!!.*?!!\}/s', '', $source);
        // Comments inside the shared <script> blocks mention <div> in prose.
        $source = preg_replace('/<script\b.*?<\/script>/s', '', $source);

        preg_match_all('/<div\b/i', $source, $open);
        preg_match_all('/<\/div\s*>/i', $source, $close);

        return [count($open[0]), count($close[0])];
    }

    protected function source(string $relativePath): string
    {
        $path = base_path($relativePath);

        $this->assertFileExists($path);

        return (string) file_get_contents($path);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function templateProvider(): array
    {
        return [
            'create supplier po' => ['resources/views/wetstock/stock-orders/create-po.blade.php'],
            'issue atl form' => ['resources/views/wetstock/stock-orders/form.blade.php'],
            'purchase orders list' => ['resources/views/wetstock/purchase-orders/index.blade.php'],
            'sales orders list' => ['resources/views/wetstock/sales-orders/index.blade.php'],
            'sales order detail' => ['resources/views/wetstock/sales-orders/show.blade.php'],
            'app layout' => ['resources/views/layouts/app.blade.php'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('templateProvider')]
    public function test_the_template_balances_its_div_tags(string $relativePath): void
    {
        [$open, $close] = $this->divCounts($this->source($relativePath));

        $this->assertSame(
            $open,
            $close,
            "{$relativePath} opens {$open} <div> and closes {$close}. "
            .'An unbalanced div puts the rest of a card inside a flex container.'
        );
    }

    /**
     * Nesting, not just the totals: a template can open and close the right
     * number while closing them in the wrong order.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('templateProvider')]
    public function test_div_tags_nest_in_order(string $relativePath): void
    {
        $source = $this->source($relativePath);
        $source = preg_replace('/\{\{--.*?--\}\}/s', '', $source);
        $source = preg_replace('/\{\{.*?\}\}/s', '', $source);
        $source = preg_replace('/\{!!.*?!!\}/s', '', $source);
        $source = preg_replace('/<script\b.*?<\/script>/s', '', $source);

        preg_match_all('/<div\b[^>]*>|<\/div\s*>/i', $source, $matches);

        $depth = 0;
        foreach ($matches[0] as $index => $tag) {
            if (str_starts_with(strtolower($tag), '</div')) {
                $depth--;

                $this->assertGreaterThanOrEqual(
                    0,
                    $depth,
                    "{$relativePath} closes a <div> that was never opened, around tag #{$index}."
                );
            } else {
                $depth++;
            }
        }

        $this->assertSame(0, $depth, "{$relativePath} ends with {$depth} <div> still open.");
    }

    // ---- The specific structure each broken page lost ---------------------

    public function test_the_po_card_header_closes_before_the_card_body(): void
    {
        $source = $this->source('resources/views/wetstock/stock-orders/create-po.blade.php');

        $headerAt = strpos($source, 'class="card-header');
        $bodyAt = strpos($source, 'class="card-body');

        $this->assertIsInt($headerAt);
        $this->assertIsInt($bodyAt);

        // The header's own closing tag has to sit between the two, or the body
        // becomes a flex child of the header and the title ends up in a column
        // beside the form.
        $between = substr($source, $headerAt, $bodyAt - $headerAt);

        $this->assertSame(
            substr_count($between, '<div'),
            substr_count($between, '</div>'),
            'The card-header block must be closed before the card-body begins.'
        );
    }

    public function test_the_allocation_pool_buttons_are_wrapped_and_closed(): void
    {
        $source = $this->source('resources/views/wetstock/stock-orders/form.blade.php');

        $wrapperAt = strpos($source, '<div class="d-flex gap-2 align-items-center flex-shrink-0">');
        // Searched from the wrapper - this form has several cards - and for the
        // whole opening tag, so the range stops before the card-body's own <div>
        // rather than including half of it.
        $bodyAt = strpos($source, '<div class="card-body p-3"', $wrapperAt);

        $this->assertIsInt($wrapperAt, 'The two buttons should share a flex wrapper.');
        $this->assertIsInt($bodyAt);

        // The wrapper opens for the buttons and must be closed again before the
        // card body starts. Unclosed, the header's own </div> is consumed and
        // the table is pulled up beside the header as a third flex child.
        //
        // Measured to the card-body, not to the table: the card-body and the
        // table-responsive legitimately open in that stretch.
        $between = substr($source, $wrapperAt, $bodyAt - $wrapperAt);

        $this->assertSame(
            1,
            substr_count($between, '<div'),
            'Only the button wrapper should open before the card body.'
        );
        $this->assertSame(
            2,
            substr_count($between, '</div>'),
            'The button wrapper and the card header must both be closed before the '
            .'card body. With only one, the card body is left open as a third flex '
            .'child of the header and the table renders beside the title.'
        );
    }

    public function test_the_purchase_orders_toolbar_does_not_wrap(): void
    {
        $source = $this->source('resources/views/wetstock/purchase-orders/index.blade.php');

        // The row between the summary cards and the table.
        $start = strpos($source, 'The search and Create P.O. sit directly above the table');
        $this->assertIsInt($start);

        $row = substr($source, $start, 1400);

        $this->assertStringContainsString('justify-content-between', $row);
        $this->assertStringContainsString('align-items-center', $row);

        // Wrapping is what pushes the Search button under the input and drops
        // Create P.O. onto its own line.
        $this->assertStringNotContainsString(
            'flex-wrap',
            $row,
            'The toolbar row must stay on one line.'
        );
    }

    public function test_the_search_input_is_width_constrained(): void
    {
        $source = $this->source('resources/views/wetstock/purchase-orders/index.blade.php');

        $start = strpos($source, 'The search and Create P.O. sit directly above the table');
        $row = substr($source, $start, 1400);

        // An unconstrained input group grows to its content and shoves the
        // Search button off the end of the row.
        $this->assertMatchesRegularExpression(
            '/input-group[^"]*"[^>]*style="[^"]*width/',
            $row,
            'The input group needs an explicit width so the button fits beside it.'
        );
    }

    public function test_the_sidebar_does_not_use_a_glob_that_swallows_the_atl_route(): void
    {
        $source = $this->source('resources/views/layouts/app.blade.php');

        // stock-orders.create* is a glob that also matches
        // create-fuel-trade-po, which is why the Issue ATL page highlighted
        // Purchase Orders.
        $this->assertStringNotContainsString(
            "routeIs('stock-orders.create*')",
            $source,
            'This glob claims the Sales Order ATL form. Name the PO routes instead.'
        );
        $this->assertStringNotContainsString(
            "routeIs('stock-orders.edit*')",
            $source,
            'This glob claims the Prepare form. Name the PO routes instead.'
        );

        $this->assertStringContainsString("routeIs('stock-orders.create-supplier-po')", $source);
        $this->assertStringContainsString("routeIs('stock-orders.edit-supplier-po')", $source);
        $this->assertStringContainsString("routeIs('stock-orders.create-fuel-trade-po')", $source);
    }

    // ---- The disabled button has to stay brand-coloured -------------------

    public function test_a_disabled_outline_primary_button_keeps_the_brand_colour(): void
    {
        // .btn-outline-primary remaps the normal, hover and active states to
        // --brand-color but never the disabled ones, so Bootstrap's grey won and
        // the button read as blue next to its orange sibling.
        $layout = $this->source('resources/views/layouts/app.blade.php');

        $this->assertMatchesRegularExpression(
            '/\.btn-outline-primary[^{]*\{[^}]*--bs-btn-disabled-color:\s*var\(--brand-color\)/s',
            $layout,
            'The disabled border and text must be remapped too, or the brand '
            .'outline reads as blue when the button is disabled.'
        );
        $this->assertMatchesRegularExpression(
            '/\.btn-outline-primary[^{]*\{[^}]*--bs-btn-disabled-border-color:\s*var\(--brand-color\)/s',
            $layout,
        );
    }

    public function test_the_pool_buttons_do_not_wrap_their_labels(): void
    {
        $source = $this->source('resources/views/wetstock/stock-orders/form.blade.php');

        // Both labels wrapped onto two lines in a cramped flex header.
        preg_match_all('/<button[^>]*onclick="(?:addProductRow|addDrawdownRow)\(\)"[^>]*>/s', $source, $matches);

        $this->assertCount(2, $matches[0]);

        foreach ($matches[0] as $tag) {
            $this->assertStringContainsString(
                'text-nowrap',
                $tag,
                'A pool button must keep its label on one line.'
            );
        }

        // And their wrapper must not be squeezed out of the header.
        $this->assertMatchesRegularExpression(
            '/<div class="d-flex gap-2 align-items-center flex-shrink-0[^"]*">\s*\{\{--/s',
            $source,
            'The button wrapper needs flex-shrink-0 so the header cannot crush it.'
        );
    }

    public function test_the_disabled_add_product_button_is_wrapped_so_its_tooltip_can_show(): void
    {
        $source = $this->source('resources/views/wetstock/stock-orders/form.blade.php');

        // A disabled control does not reliably fire hover, so a title on the
        // button itself is never seen. The wrapper carries it instead.
        $this->assertMatchesRegularExpression(
            '/<span[^>]*data-add-product-hint[^>]*title="[^"]+"\s*>\s*<button[^>]*onclick="addProductRow\(\)"/s',
            $source,
            'The disabled button needs a titled wrapper for the reason to be readable.'
        );
    }

    public function test_the_pool_total_cell_is_not_a_flex_container(): void
    {
        $source = $this->source('resources/views/wetstock/stock-orders/form.blade.php');

        // d-flex on a <td> takes the cell out of table layout: the row
        // background stops under it and the figure wraps onto two lines.
        $this->assertDoesNotMatchRegularExpression(
            '/<td[^>]*class="[^"]*\bd-flex\b[^"]*"[^>]*>\s*<div[^>]*>\s*<span[^>]*id="totalDrawnDisplay"/s',
            $source,
            'The total cell must not be a flex container itself.'
        );

        $this->assertMatchesRegularExpression(
            '/<td class="pe-2">\s*(?:\{\{--.*?--\}\}\s*)?<div class="d-flex[^"]*">\s*<span class="[^"]*text-nowrap[^"]*"[^>]*id="totalDrawnDisplay"/s',
            $source,
            'The flex row belongs inside the cell, not on it.'
        );
    }

    public function test_the_pool_total_figure_uses_the_table_font_and_stays_on_one_line(): void
    {
        $source = $this->source('resources/views/wetstock/stock-orders/form.blade.php');

        $this->assertMatchesRegularExpression(
            '/<span class="[^"]*text-nowrap[^"]*"[^>]*id="totalDrawnDisplay"/s',
            $source,
            'The total must not wrap.'
        );
        $this->assertDoesNotMatchRegularExpression(
            '/<span class="[^"]*font-monospace[^"]*"[^>]*id="totalDrawnDisplay"/s',
            $source,
            'Monospace is used nowhere else in this system.'
        );
    }

    public function test_a_visible_hint_explains_why_add_product_is_disabled(): void
    {
        $source = $this->source('resources/views/wetstock/stock-orders/form.blade.php');

        // The button is disabled on render, so the reason has to be on the page
        // rather than only in a tooltip nobody can hover.
        $this->assertStringContainsString('data-add-product-hint-text', $source);
        $this->assertStringContainsString('one product only', $source);
        $this->assertStringContainsString('already has a line', $source);
    }

    public function test_the_js_does_not_overwrite_the_server_reason_on_load(): void
    {
        $source = $this->source('resources/views/wetstock/stock-orders/form.blade.php');

        // updateAddProductButton() used to set the title unconditionally, which
        // discarded the server's clearer wording the moment the page loaded.
        $this->assertMatchesRegularExpression(
            '/function updateAddProductButton\(options\)/s',
            $source,
            'It must take a flag so the initial call can skip the title.'
        );
        $this->assertMatchesRegularExpression(
            '/if \(options && options\.title\) button\.title =/s',
            $source,
            'The title must only be rewritten when asked for.'
        );

        // The three call sites: two interactive, one on load.
        $this->assertSame(
            2,
            preg_match_all('/updateAddProductButton\(\{ title: true \}\);/', $source),
            'Adding or removing a row has new information, so both should rewrite the title.'
        );
        $this->assertSame(
            1,
            preg_match_all('/updateAddProductButton\(\);/', $source),
            'The page-init call must not pass the title flag.'
        );
    }
}