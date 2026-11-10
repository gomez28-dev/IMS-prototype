<?php

namespace App\Http\Controllers\WetStock;

use App\Exports\StockOrderReportExport;
use App\Http\Controllers\Controller;
use App\Models\AtlAllocation;
use App\Models\Order;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderDelivery;
use App\Models\PurchaseOrderItem;
use App\Models\Supplier;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Module 3 - Stock Orders report.
 *
 * The purchase-order counterpart of the Sales report: pick a period, see what
 * was committed, what has been lifted and what is still unlifted, then export
 * the same figures. The page and the spreadsheet are built from one compiled
 * report so they cannot disagree.
 */
class StockOrderReportController extends Controller
{
    /**
     * Product codes, in the order the cards and every row list them.
     */
    private const PRODUCT_CODES = ['D', 'U', 'P'];

    /**
     * The date a PO is reported under.
     *
     * Old rows carry no po_date (it was added later), so the request date and
     * then the creation date stand in rather than dropping the PO out of every
     * period the supervisor filters by.
     */
    private const EFFECTIVE_DATE = 'COALESCE(purchase_orders.po_date, DATE(purchase_orders.request_date), DATE(purchase_orders.created_at))';

    public function index(Request $request): View
    {
        $filters = $this->filters($request);
        $query = $this->query($filters);

        // The cards describe every PO the filter matches, not the ten rows on
        // the page, so their figures and "Showing N" are the same scope.
        $allOrders = (clone $query)->get();
        $lines = $this->productLines($allOrders);
        $summary = $this->summary($allOrders, $lines);

        $purchaseOrders = (clone $query)
            ->with(['supplier', 'warehouse', 'items'])
            ->orderByRaw(self::EFFECTIVE_DATE . ' desc')
            ->orderByDesc('purchase_orders.id')
            ->paginate(10)
            ->withQueryString();

        return view('wetstock.reports.stock-orders', [
            'purchaseOrders' => $purchaseOrders,
            'lines' => $lines,
            'summary' => $summary,
            'suppliers' => Supplier::orderBy('company_name')->get(),
            'filters' => $filters,
            'activeFilter' => $this->activeFilter($filters),
        ]);
    }

    public function export(Request $request): BinaryFileResponse
    {
        $filters = $this->filters($request);

        $orders = $this->query($filters)
            ->with('supplier')
            ->orderByRaw(self::EFFECTIVE_DATE . ' desc')
            ->orderByDesc('purchase_orders.id')
            ->get();

        $lines = $this->productLines($orders);
        $summary = $this->summary($orders, $lines);

        return Excel::download(
            new StockOrderReportExport($orders, $lines, $summary),
            'STOCK_ORDERS_REPORT_' . now()->format('Y_m_d_His') . '.xlsx'
        );
    }

    /**
     * The filter set, normalised once so the list, the cards and the export
     * all read the same values.
     *
     * @return array{mode:string, from:?string, to:?string, month:?string, year:?string, supplier_id:?string}
     */
    private function filters(Request $request): array
    {
        $mode = $request->input('filter_mode', 'range');

        return [
            'mode' => in_array($mode, ['range', 'month_year', 'year'], true) ? $mode : 'range',
            'from' => $request->input('from'),
            'to' => $request->input('to'),
            'month' => $request->input('month'),
            'year' => $request->input('year'),
            'supplier_id' => $request->input('supplier_id'),
        ];
    }

    /**
     * The report's scope: real purchase orders only, so Module 2 stock requests
     * and Module 1 SO-FT drawdown rows cannot inflate the figures.
     */
    private function query(array $filters): Builder
    {
        $query = PurchaseOrder::query()->createdPos();

        if ($filters['mode'] === 'range' && $filters['from'] && $filters['to']) {
            $query->whereRaw(self::EFFECTIVE_DATE . ' BETWEEN ? AND ?', [$filters['from'], $filters['to']]);
        } elseif ($filters['mode'] === 'month_year' && $filters['month'] && $filters['year']) {
            $query->whereRaw('YEAR(' . self::EFFECTIVE_DATE . ') = ?', [$filters['year']])
                ->whereRaw('MONTH(' . self::EFFECTIVE_DATE . ') = ?', [$filters['month']]);
        } elseif ($filters['mode'] === 'year' && $filters['year']) {
            $query->whereRaw('YEAR(' . self::EFFECTIVE_DATE . ') = ?', [$filters['year']]);
        }

        if ($filters['supplier_id']) {
            $query->where('purchase_orders.supplier_id', $filters['supplier_id']);
        }

        return $query;
    }

    private function activeFilter(array $filters): ?string
    {
        return match ($filters['mode']) {
            'range' => ($filters['from'] && $filters['to']) ? 'range' : null,
            'month_year' => ($filters['month'] && $filters['year']) ? 'month' : null,
            'year' => $filters['year'] ? 'year' : null,
            default => null,
        };
    }

    /**
     * Ordered / lifted / unlifted litres per product, keyed by PO id.
     *
     * Ordered comes from the PO's own lines. Rows raised before line items
     * existed keep the figures on the request payload, and a row with neither
     * still reports its header quantity rather than vanishing from the totals.
     *
     * Lifted and unlifted come from the ATL allocations: a delivery that was
     * cancelled contributes to neither, which is the rule the PO page uses.
     *
     * @param  Collection<int, PurchaseOrder>  $orders
     * @return array<int, array<string, array<string, int>>>
     */
    private function productLines(Collection $orders): array
    {
        if ($orders->isEmpty()) {
            return [];
        }

        $ids = $orders->pluck('id')->all();

        $itemsByPo = PurchaseOrderItem::whereIn('purchase_order_id', $ids)
            ->get()
            ->groupBy('purchase_order_id');

        $allocations = AtlAllocation::query()
            ->join('purchase_order_deliveries as d', 'atl_allocations.purchase_order_delivery_id', '=', 'd.id')
            ->whereIn('atl_allocations.purchase_order_id', $ids)
            ->whereIn('d.lift_status', [PurchaseOrderDelivery::LIFT_LIFTED, PurchaseOrderDelivery::LIFT_UNLIFTED])
            ->groupBy('atl_allocations.purchase_order_id', 'atl_allocations.product', 'd.lift_status')
            ->selectRaw('atl_allocations.purchase_order_id as po_id, atl_allocations.product as product, d.lift_status as lift_status, SUM(atl_allocations.quantity) as qty')
            ->get();

        $out = [];

        foreach ($orders as $order) {
            $ordered = [];
            foreach ($itemsByPo[$order->id] ?? [] as $item) {
                $code = $this->code($item->product);
                $ordered[$code] = ($ordered[$code] ?? 0) + (int) $item->quantity_ordered;
            }

            if ($ordered === []) {
                foreach ((array) ($order->requested_products ?? []) as $requested) {
                    $code = $this->code($requested['product'] ?? null);
                    $ordered[$code] = ($ordered[$code] ?? 0) + (int) ($requested['quantity'] ?? 0);
                }
            }

            if ($ordered === [] && (int) $order->qty_ordered > 0) {
                $ordered['D'] = (int) $order->qty_ordered;
            }

            $lifted = [];
            $unlifted = [];

            foreach ($allocations->where('po_id', $order->id) as $allocation) {
                $code = $this->code($allocation->product);
                $bucket = $allocation->lift_status === PurchaseOrderDelivery::LIFT_LIFTED ? 'lifted' : 'unlifted';

                if ($bucket === 'lifted') {
                    $lifted[$code] = ($lifted[$code] ?? 0) + (int) $allocation->qty;
                } else {
                    $unlifted[$code] = ($unlifted[$code] ?? 0) + (int) $allocation->qty;
                }
            }

            $out[$order->id] = [
                'ordered' => $this->normalise($ordered),
                'lifted' => $this->normalise($lifted),
                'unlifted' => $this->normalise($unlifted),
            ];
        }

        return $out;
    }

    /**
     * Card figures: PO count and committed value, then the three product
     * totals. Each card's rows add up to its own total.
     *
     * @param  Collection<int, PurchaseOrder>  $orders
     * @return array{count:int, value:float, ordered:array<string,int>, lifted:array<string,int>, unlifted:array<string,int>}
     */
    private function summary(Collection $orders, array $lines): array
    {
        $summary = [
            'count' => $orders->count(),
            'value' => (float) $orders->sum(fn (PurchaseOrder $po) => (float) $po->total_amount),
            'ordered' => $this->blank(),
            'lifted' => $this->blank(),
            'unlifted' => $this->blank(),
        ];

        foreach ($lines as $poLines) {
            foreach (['ordered', 'lifted', 'unlifted'] as $key) {
                foreach ($poLines[$key] as $code => $qty) {
                    $summary[$key][$code] += $qty;
                }
            }
        }

        return $summary;
    }

    /**
     * @return array<string, int>
     */
    private function blank(): array
    {
        return array_fill_keys(self::PRODUCT_CODES, 0);
    }

    /**
     * @param  array<string, int>  $totals
     * @return array<string, int>
     */
    private function normalise(array $totals): array
    {
        $out = $this->blank();
        foreach ($totals as $code => $qty) {
            $out[$code] = ($out[$code] ?? 0) + $qty;
        }

        return $out;
    }

    /**
     * A product reaches Module 3 either as a name (Diesel) or a code (D).
     */
    private function code(?string $product): string
    {
        if ($product === null || $product === '') {
            return 'D';
        }

        if (in_array($product, self::PRODUCT_CODES, true)) {
            return $product;
        }

        $code = array_search($product, Order::PRODUCT_TYPES, true);

        return $code !== false ? $code : 'D';
    }
}
