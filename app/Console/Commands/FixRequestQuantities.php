<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\StockRequest;
use App\Models\StockRequestLine;
use Illuminate\Console\Command;

/**
 * Repairs replenishment quantities that a browser silently incremented.
 *
 * The old request form rendered the liters field with min="1" step="100".
 * Browsers validate (value - min) mod step == 0, so a round value like
 * 10,000 was invalid and the browser snapped it to 10,001 on entry. The form
 * itself was fixed in e988cea (step="1"), so this can no longer happen; the
 * rows typed before that fix are still stored with the bumped figure.
 *
 * The snap signature is exact: with min=1 and step=100 the only reachable
 * values are 1, 101, 201 and so on, so anything with qty % 100 === 1 and
 * qty > 1 is a snapped value rather than a genuine quantity. That makes the
 * rule narrow enough to apply unattended.
 *
 * Reports first and only writes when --apply is passed.
 */
class FixRequestQuantities extends Command
{
    protected $signature = 'stock-requests:fix-quantities
                            {--apply : Correct the snapped quantities instead of only reporting them}
                            {--admin= : Admin id to attribute the correction to; defaults to each request\'s own requester}';

    protected $description = 'Report on replenishment quantities a browser snapped by 1 (min=1 step=100), and optionally correct them';

    public function handle(): int
    {
        $this->info('Snapped replenishment quantity audit');
        $this->line(str_repeat('-', 64));

        // qty % 100 === 1 with qty > 1 is the only shape min=1 step=100 can
        // produce. Genuine quantities are never excluded by this.
        $suspects = StockRequestLine::with('stockRequest')
            ->where('qty_requested', '>', 1)
            ->whereRaw('MOD(qty_requested, 100) = 1')
            ->get()
            ->sortBy(fn ($line) => $line->stockRequest?->request_number ?? '—');

        $this->line('  Lines scanned        '.StockRequestLine::count());
        $this->line('  Suspect lines        '.$suspects->count());

        if ($suspects->isEmpty()) {
            $this->newLine();
            $this->info('Nothing looks snapped. No changes needed.');

            return self::SUCCESS;
        }

        $this->newLine();
        $this->table(
            ['REQ #', 'Product', 'Stored', 'Corrected to', 'Confirm?'],
            $suspects->map(fn ($line) => [
                $line->stockRequest?->request_number ?? ('request #'.$line->stock_request_id),
                $line->product,
                number_format($line->qty_requested),
                number_format($line->qty_requested - 1),
                'yes / no',
            ])->all()
        );

        $this->newLine();
        $this->warn('Read this list before applying. Anything ending 01 may also be a genuine');
        $this->warn('quantity (101 L, 201 L, 1,001 L all match), so confirm each row is really a');
        $this->warn('snapped 10,000-style figure before using --apply.');

        if (! $this->option('apply')) {
            $this->newLine();
            $this->comment('Dry run. Re-run with --apply to correct these.');
            $this->line('Nothing was written.');

            return self::SUCCESS;
        }

        foreach ($suspects as $line) {
            $before = (int) $line->qty_requested;
            $after = $before - 1;

            $line->update(['qty_requested' => $after]);

            // Keep the legacy placeholder row in step, so anything still
            // reading purchase_orders sees the same corrected figure.
            $legacyId = $line->stockRequest?->legacy_purchase_order_id;
            if ($legacyId) {
                $this->syncLegacyRow($legacyId, $line, $after);
            }

            AuditLog::create([
                // audit_logs.admin_id is NOT NULL, and a console command has no
                // authenticated user, so the correction is attributed to the
                // person who raised the request unless --admin says otherwise.
                'admin_id' => $this->option('admin') ?: $line->stockRequest?->requested_by,
                'action' => 'updated',
                'description' => "Automated correction: snapped replenishment quantity on request "
                    .($line->stockRequest?->request_number ?? $line->stock_request_id)
                    ." ({$line->product}): ".number_format($before).' L -> '.number_format($after).' L.',
            ]);
        }

        $this->newLine();
        $this->info('Corrected '.$suspects->count().' line(s).');

        return self::SUCCESS;
    }

    /**
     * Mirrors the corrected quantity into the legacy purchase_orders row the
     * request was backfilled from: the products JSON entry and the header
     * total that sums it.
     */
    private function syncLegacyRow(int $purchaseOrderId, StockRequestLine $line, int $corrected): void
    {
        $legacy = \App\Models\PurchaseOrder::find($purchaseOrderId);

        if (! $legacy) {
            return;
        }

        $products = is_array($legacy->requested_products)
            ? $legacy->requested_products
            : json_decode((string) $legacy->requested_products, true);

        if (! is_array($products)) {
            $products = [];
        }

        foreach ($products as $index => $product) {
            if (is_array($product) && ($product['product'] ?? null) === $line->product) {
                $products[$index]['quantity'] = $corrected;
            }
        }

        $legacy->update([
            'requested_products' => $products,
            'qty_ordered' => array_sum(array_map(
                fn ($product) => (int) ($product['quantity'] ?? 0),
                array_filter($products, 'is_array')
            )),
        ]);
    }
}