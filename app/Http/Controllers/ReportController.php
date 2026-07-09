<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Exports\ReportsExport;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ReportController extends Controller
{
    public function index(Request $request): View
    {
        $query = Order::query()->with('deliveries');

        $from = $request->input('from');
        $to = $request->input('to');
        $month = $request->input('month');
        $year = $request->input('year');
        $type = $request->input('type');
        $filterMode = $request->input('filter_mode', 'range');

        $activeFilter = null;

        if ($filterMode === 'range') {
            if ($from && $to) {
                $query->whereBetween('date', [$from, $to]);
                $activeFilter = 'range';
            }
        } elseif ($filterMode === 'month_year') {
            if ($month && $year) {
                $query->whereYear('date', $year)->whereMonth('date', $month);
                $activeFilter = 'month';
            }
        } elseif ($filterMode === 'year') {
            if ($year) {
                $query->whereYear('date', $year);
                $activeFilter = 'year';
            }
        }

        if ($type) {
            $query->whereHas('deliveries', fn($q) => $q->where('type', $type));
        }

        $account = $request->input('account');
        if ($account) {
            $query->where('account', $account);
        }

        $orders = $query->orderBy('date', 'desc')->paginate(10)->withQueryString();

        $clients = \App\Models\Client::orderBy('name')->get();

        $totalOrders = $query->clone()->count();
        $totalQtyOrdered = $query->clone()->sum('qty_ordered');
        $totalQtyDelivered = $query->clone()
            ->join('deliveries', 'orders.id', '=', 'deliveries.order_id')
            ->where('deliveries.status', 'FULFILLED')
            ->sum('deliveries.qty_out');
        $totalRemaining = $totalQtyOrdered - $totalQtyDelivered;

        return view('reports.index', compact('orders', 'from', 'to', 'month', 'year', 'type', 'activeFilter', 'account', 'clients', 'totalOrders', 'totalQtyOrdered', 'totalQtyDelivered', 'totalRemaining', 'filterMode'));
    }

    public function export(Request $request): BinaryFileResponse
    {
        $query = Order::query()->with('deliveries');

        $from = $request->input('from');
        $to = $request->input('to');
        $month = $request->input('month');
        $year = $request->input('year');
        $type = $request->input('type');
        $filterMode = $request->input('filter_mode', 'range');

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

        if ($type) {
            $query->whereHas('deliveries', fn($q) => $q->where('type', $type));
        }

        $account = $request->input('account');
        if ($account) {
            $query->where('account', $account);
        }

        $orders = $query->orderBy('date', 'desc')->get();

        return Excel::download(new ReportsExport($orders), 'report.xlsx');
    }
}
