<?php

namespace App\Services\Sales;

use Illuminate\Support\Collection;

/**
 * Ordered / delivered / undelivered litres per product, for a set of orders.
 *
 * Extracted from DashboardController so the executive dashboard and the
 * Module 1 summary cards share one copy of the arithmetic. The rules:
 *
 * - Ordered: product lines, minus litres on cancelled DRs.
 * - Delivered: fulfilled DR lines. Orders whose delivered total disagrees with
 *   the order's own total are spread across its products in proportion to what
 *   was ordered.
 * - Undelivered: ordered minus delivered.
 *
 * Orders with no product lines count as Diesel, matching the rest of the app.
 */
class OrderProductStats
{
    public const CODES = ['D', 'U', 'P'];

    /**
     * @param  Collection<int, \App\Models\Order>  $orders
     * @return array{ordered: array<string,int>, delivered: array<string,int>, undelivered: array<string,int>}
     */
    public function forCollection(Collection $orders): array
    {
        $codes = self::CODES;
        $ordered = array_fill_keys($codes, 0);
        $delivered = array_fill_keys($codes, 0);
        $undelivered = array_fill_keys($codes, 0);

        foreach ($orders as $o) {
            $lines = [];
            foreach ($o->items as $i) {
                $c = in_array($i->product_type, $codes, true) ? $i->product_type : 'D';
                $lines[$c] = ($lines[$c] ?? 0) + (int) $i->qty;
            }
            if (empty($lines)) {
                $legacy = $o->getAttribute('product_type');
                $c = in_array($legacy, $codes, true) ? $legacy : 'D';
                $lines[$c] = (int) $o->qty_ordered;
            }
            $fallback = array_key_first($lines);

            $done = [];
            $cancel = [];
            foreach ($o->deliveries as $d) {
                if (!in_array($d->status, ['FULFILLED', 'CANCELLED'], true)) {
                    continue;
                }

                $parts = $d->items->isNotEmpty()
                    ? $d->items->map(fn ($i) => [$i->product_type, (int) $i->qty_out])->all()
                    : [[$d->product_type, (int) $d->qty_out]];

                foreach ($parts as [$code, $qty]) {
                    $code = in_array($code, $codes, true) ? $code : $fallback;
                    if ($d->status === 'CANCELLED') {
                        $cancel[$code] = ($cancel[$code] ?? 0) + $qty;
                    } else {
                        $done[$code] = ($done[$code] ?? 0) + $qty;
                    }
                }
            }

            $out = (int) $o->total_qty_out;
            if ($out !== array_sum($done)) {
                $base = array_sum($lines) ?: 1;
                $done = [];
                foreach ($lines as $c => $q) {
                    $done[$c] = (int) round($out * $q / $base);
                }
            }

            foreach ($lines as $c => $q) {
                $ordered[$c] += max(0, $q - ($cancel[$c] ?? 0));
            }
            foreach ($done as $c => $q) {
                $delivered[$c] += $q;
            }
        }

        foreach ($codes as $c) {
            $undelivered[$c] = max(0, $ordered[$c] - $delivered[$c]);
        }

        return compact('ordered', 'delivered', 'undelivered');
    }
}
