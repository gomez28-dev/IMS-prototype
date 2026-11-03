<?php

namespace App\Http\Controllers;

use App\Exports\ReportsExport;
use App\Models\Client;
use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ReportController extends Controller
{
    /**
     * Product codes, in the order the cards and table list them.
     */
    private const PRODUCT_CODES = ['D', 'U', 'P'];

    public function index(Request $request): View
    {
        $query = $this->filteredQuery($request);
        $activeFilter = $this->activeFilter($request);

        // Summaries are read off an unlimited clone taken BEFORE the paginator
        // is applied. paginate() sets LIMIT on the builder it is handed, and a
        // clone of a limited builder sums only the first page - which is how
        // the cards once reported the ten rows on screen as the whole year.
        $statsOrders = (clone $query)->with(['items', 'deliveries.items'])->get();
        $productStats = $this->productStats($statsOrders);

        // Every order the list can show, cancelled included, so "Total Orders"
        // and "Showing N order(s)" are the same number.
        $totalOrders = $statsOrders->count();

        $orders = (clone $query)
            ->with(['items', 'deliveries.items'])
            ->orderBy('date', 'desc')
            ->paginate(10)
            ->withQueryString();

        // Per-row product lines for the Qty Ordered / Qty Out / Balance cells.
        $rowLines = $orders->getCollection()->mapWithKeys(
            fn (Order $order) => [$order->id => $this->productLines($order)]
        );

        $from = $request->input('from');
        $to = $request->input('to');
        $month = $request->input('month');
        $year = $request->input('year');
        $type = $request->input('type');
        $account = $request->input('account');
        $filterMode = $request->input('filter_mode', 'range');

        $clients = Client::orderBy('name')->get();

        return view('reports.index', compact(
            'orders', 'from', 'to', 'month', 'year', 'type', 'activeFilter', 'account', 'clients',
            'totalOrders', 'productStats', 'rowLines', 'filterMode'
        ));
    }

    public function export(Request $request): BinaryFileResponse
    {
        $query = $this->filteredQuery($request);

        $orders = $query->with(['items', 'deliveries.items'])->orderBy('date', 'desc')->get();

        $productLines = $orders->mapWithKeys(
            fn (Order $order) => [$order->id => $this->productLines($order)]
        )->all();

        return Excel::download(new ReportsExport($orders, $productLines), 'report.xlsx');
    }

    /**
     * The report's filter set, applied once so the list and the summaries can
     * never be built from different scopes.
     */
    private function filteredQuery(Request $request)
    {
        $query = Order::query();

        $filterMode = $request->input('filter_mode', 'range');
        $from = $request->input('from');
        $to = $request->input('to');
        $month = $request->input('month');
        $year = $request->input('year');

        if ($filterMode === 'range') {
            if ($from && $to) {
                $query->whereBetween('date', [$from, $to]);
            }
        } elseif ($filterMode === 'month_year') {
            if ($month && $year) {
                $query->whereYear('date', $year)->whereMonth('date', $month);
            }
        } elseif ($filterMode === 'year') {
            if ($year) {
                $query->whereYear('date', $year);
            }
        }

        if ($type = $request->input('type')) {
            $query->whereHas('deliveries', fn ($q) => $q->where('type', $type));
        }

        if ($account = $request->input('account')) {
            $query->where('account', $account);
        }

        return $query;
    }

    private function activeFilter(Request $request): ?string
    {
        $filterMode = $request->input('filter_mode', 'range');

        return match ($filterMode) {
            'range' => ($request->input('from') && $request->input('to')) ? 'range' : null,
            'month_year' => ($request->input('month') && $request->input('year')) ? 'month' : null,
            'year' => $request->input('year') ? 'year' : null,
            default => null,
        };
    }

    /**
     * Per-product totals for the summary cards.
     *
     * The same rules as the Orders Dashboard's own cards, so the two pages
     * report the same litres for the same orders: ordered is the product lines
     * less anything cancelled, delivered is the volume out, and remaining is
     * the difference.
     *
     * @param  Collection<int, Order>  $orders
     * @return array<string, array{ordered:int, delivered:int, balance:int}>
     */
    private function productStats(Collection $orders): array
    {
        $totals = [];
        foreach (self::PRODUCT_CODES as $code) {
            $totals[$code] = ['ordered' => 0, 'delivered' => 0, 'balance' => 0];
        }

        foreach ($orders as $order) {
            foreach ($this->productLines($order) as $code => $line) {
                $totals[$code]['ordered'] += $line['ordered'];
                $totals[$code]['delivered'] += $line['delivered'];
                $totals[$code]['balance'] += $line['balance'];
            }
        }

        return $totals;
    }

    /**
     * One order's ordered / delivered / balance litres, split by product.
     *
     * Orders with no product lines count as Diesel, which is how the rest of
     * the app attributes them.
     *
     * @return array<string, array{ordered:int, delivered:int, balance:int}>
     */
    private function productLines(Order $order): array
    {
        $lines = [];
        foreach ($order->items as $item) {
            $code = in_array($item->product_type, self::PRODUCT_CODES, true) ? $item->product_type : 'D';
            $lines[$code] = ($lines[$code] ?? 0) + (int) $item->qty;
        }

        if ($lines === []) {
            $legacy = $order->getAttribute('product_type');
            $code = in_array($legacy, self::PRODUCT_CODES, true) ? $legacy : 'D';
            $lines[$code] = (int) $order->qty_ordered;
        }

        $fallback = array_key_first($lines);

        $out = [];
        $cancelled = [];
        foreach ($order->deliveries as $delivery) {
            if (! in_array($delivery->status, ['PENDING', 'FULFILLED', 'CANCELLED'], true)) {
                continue;
            }

            // A multi-product DR carries its own per-product lines; older rows
            // only have the header's single product code.
            $parts = $delivery->items->isNotEmpty()
                ? $delivery->items->map(fn ($item) => [$item->product_type, (int) $item->qty_out])->all()
                : [[$delivery->product_type, (int) $delivery->qty_out]];

            foreach ($parts as [$code, $qty]) {
                $code = in_array($code, self::PRODUCT_CODES, true) ? $code : $fallback;

                if ($delivery->status === 'CANCELLED') {
                    $cancelled[$code] = ($cancelled[$code] ?? 0) + $qty;
                } else {
                    $out[$code] = ($out[$code] ?? 0) + $qty;
                }
            }
        }

        // Older DRs carry no product at all. Rather than drop that volume, spread
        // it across the order's lines so each product's out matches the row total.
        $qtyOut = (int) $order->total_qty_out;
        if ($qtyOut !== array_sum($out)) {
            $base = array_sum($lines) ?: 1;
            $out = [];
            foreach ($lines as $code => $qty) {
                $out[$code] = (int) round($qtyOut * $qty / $base);
            }
        }

        $result = [];
        foreach ($lines as $code => $qty) {
            $ordered = max(0, $qty - ($cancelled[$code] ?? 0));
            $delivered = $out[$code] ?? 0;

            $result[$code] = [
                'ordered' => $ordered,
                'delivered' => $delivered,
                'balance' => $ordered - $delivered,
            ];
        }

        return $result;
    }
}
