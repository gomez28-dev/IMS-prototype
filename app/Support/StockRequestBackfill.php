<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Copies stock requests out of purchase_orders and into stock_requests.
 *
 * A legacy request is a purchase_orders row with no PO number. These are
 * copied rather than moved, so nothing is deleted and the existing Purchasing
 * and receive flows keep working. legacy_purchase_order_id keeps the two
 * linked, and an already-linked row is skipped, which makes running this twice
 * a no-op.
 *
 * Lives outside the migration so it can be exercised directly; the migration
 * is just the deployment hook.
 */
class StockRequestBackfill
{
    public function run(): int
    {
        if (!Schema::hasTable('stock_requests') || !Schema::hasTable('purchase_orders')) {
            return 0;
        }

        $legacyRequests = DB::table('purchase_orders')
            ->where(function ($query) {
                $query->whereNull('po_number')->orWhere('po_number', '');
            })
            ->whereIn('po_type', ['STANDARD_REPLENISHMENT', 'BUY_BACK'])
            ->orderBy('id')
            ->get();

        $nextNumber = $this->nextRequestNumber();
        $copied = 0;

        foreach ($legacyRequests as $row) {
            $alreadyCopied = DB::table('stock_requests')
                ->where('legacy_purchase_order_id', $row->id)
                ->exists();

            if ($alreadyCopied) {
                continue;
            }

            $requestId = DB::table('stock_requests')->insertGetId([
                'request_number' => $nextNumber++,
                // Depots raised these for their own tanks, so fuel is collected.
                'request_type' => 'PICKUP',
                'warehouse_id' => $row->warehouse_id,
                'requested_by' => $row->requested_by,
                'request_date' => $row->request_date ?? now(),
                'date_needed' => $row->date_needed,
                'remarks' => $row->remarks,
                'status' => $row->request_status ?: 'REQUESTED',
                'legacy_purchase_order_id' => $row->id,
                'created_at' => $row->created_at ?? now(),
                'updated_at' => $row->updated_at ?? now(),
            ]);

            foreach ($this->productsFor($row) as $product => $quantity) {
                DB::table('stock_request_lines')->insert([
                    'stock_request_id' => $requestId,
                    'product' => $product,
                    'qty_requested' => (int) $quantity,
                    'qty_received' => 0,
                    'unit' => 'LTRS',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            $copied++;
        }

        return $copied;
    }

    /**
     * Products come from the requested_products JSON, falling back to the
     * header quantity for rows written before that column was populated.
     *
     * @return array<string, int>
     */
    private function productsFor(object $row): array
    {
        $products = [];

        $requested = $row->requested_products
            ? json_decode($row->requested_products, true)
            : null;

        if (is_array($requested)) {
            foreach ($requested as $line) {
                if (!is_array($line) || empty($line['product'])) {
                    continue;
                }

                $products[$line['product']] = (int) ($line['quantity'] ?? 0);
            }
        }

        if ($products === [] && (int) $row->qty_ordered > 0) {
            $products['Diesel'] = (int) $row->qty_ordered;
        }

        return $products;
    }

    /**
     * Continue the REQ-001 sequence past whatever is already stored.
     */
    private function nextRequestNumber(): string
    {
        $highest = DB::table('stock_requests')
            ->where('request_number', 'like', 'REQ-%')
            ->pluck('request_number')
            ->map(fn ($number) => (int) substr($number, 4))
            ->max() ?? 0;

        return 'REQ-'.str_pad((string) ($highest + 1), 3, '0', STR_PAD_LEFT);
    }
}