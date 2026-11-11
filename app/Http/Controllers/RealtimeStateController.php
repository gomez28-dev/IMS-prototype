<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * A cheap fingerprint of the system's operational state.
 *
 * Every authenticated page polls this every few seconds. When the checksum
 * moves, another user changed something, and the page quietly re-renders
 * itself instead of waiting for someone to press refresh.
 *
 * There are no websockets on Hostinger shared hosting, so the fingerprint has
 * to be cheap enough to run on every poll: each table is reduced to a row
 * count and the sum of a per-row CRC, computed inside MySQL. No rows travel.
 */
class RealtimeStateController extends Controller
{
    /**
     * The tables a page's statuses come from, with the columns that carry
     * their state.
     *
     * The state columns are hashed alongside each row's updated_at on purpose.
     * A status written by a query that never touched the timestamp (a bulk
     * update, a console command) still changes the checksum.
     */
    private const SIGNATURES = [
        'orders' => ['id', 'clearing_status', 'status'],
        'deliveries' => ['id', 'status', 'atl_number'],
        'purchase_orders' => ['id', 'status'],
        'purchase_order_deliveries' => ['id', 'status', 'lift_status'],
        'stock_requests' => ['id', 'status'],
        'modification_requests' => ['id', 'status'],
        'storage_tanks' => ['id', 'is_contaminated', 'contaminated_liters'],
        'logistics_trips' => ['id', 'status'],
        'logistics_unit_statuses' => ['id', 'status'],
    ];

    /**
     * How many orders the badge patch can carry. The dashboard lists at most a
     * month of orders; a page showing an older one still gets the full body
     * refresh, just not the instant badge swap.
     */
    private const RECENT_ORDERS = 200;

    public function __invoke(): JsonResponse
    {
        $signature = [];

        foreach (self::SIGNATURES as $table => $columns) {
            $signature[$table] = $this->fingerprint($table, $columns);
        }

        return response()->json([
            'checksum' => md5(json_encode($signature)),
            'timestamp' => now()->getTimestamp(),
            'orders' => $this->recentOrders(),
        ])->withHeaders([
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
        ]);
    }

    /**
     * One table reduced to "<row count>:<sum of row CRCs>".
     *
     * @param  array<int, string>  $columns
     */
    private function fingerprint(string $table, array $columns): string
    {
        $columns[] = 'updated_at';

        // Table and column names come from SIGNATURES above, never from the
        // request, so interpolating them into the expression is safe.
        $expression = "CRC32(CONCAT_WS('|', " . $this->columnList($columns) . '))';

        $row = DB::table($table)->selectRaw(
            "COUNT(*) AS row_count, COALESCE(SUM({$expression}), 0) AS signature"
        )->first();

        return $row->row_count . ':' . $row->signature;
    }

    /**
     * @param  array<int, string>  $columns
     */
    private function columnList(array $columns): string
    {
        return '`' . implode('`, `', $columns) . '`';
    }

    /**
     * The current clearing status of the most recently touched orders, keyed
     * by id so the browser can patch a badge before the page is refetched.
     *
     * @return array<string, array<string, mixed>>
     */
    private function recentOrders(): array
    {
        return DB::table('orders')
            ->select('id', 'clearing_status', 'status')
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->limit(self::RECENT_ORDERS)
            ->get()
            ->mapWithKeys(fn ($order) => [(string) $order->id => [
                'id' => (int) $order->id,
                'clearing_status' => $order->clearing_status,
                'status' => $order->status,
            ]])
            ->all();
    }
}
