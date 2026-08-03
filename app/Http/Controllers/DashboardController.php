<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Exports\InventoryExport;
use App\Imports\InventoryImport;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Illuminate\Http\RedirectResponse;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class DashboardController extends Controller
{
    /**
     * Display the dashboard with orders list and optional search.
     */
    public function index(Request $request): View|RedirectResponse
    {
        // Clear persisted dashboard state and reset to page 1
        if ($request->has('clear')) {
            session()->forget(['dashboard_page', 'dashboard_search']);
            return redirect()->route('dashboard');
        }

        // Capture pagination + search state whenever they appear in the request
        if ($request->has('page') || $request->has('search')) {
            session([
                'dashboard_page' => (int) $request->input('page', 1),
                'dashboard_search' => trim($request->input('search', '')),
            ]);
        }

        // Restore from session when navigating back without params
        $searchQuery = $request->input('search', session('dashboard_search', ''));
        $page = (int) $request->input('page', session('dashboard_page', 1));

        $query = Order::query();

        if ($searchQuery !== '') {
            $query->where(function ($q) use ($searchQuery) {
                $q->where('account', 'like', "%{$searchQuery}%")
                  ->orWhere('so_number', 'like', "%{$searchQuery}%");
            });
        }

        // Summary cards are scoped to the current month (monthly reset)
        $now = now('Asia/Manila');
        $statsQuery = clone $query;
        $statsQuery->whereYear('date', $now->year)->whereMonth('date', $now->month);

        $totalOrders = (clone $statsQuery)->count();
        $totalQtyOrdered = (clone $statsQuery)->get()->sum(fn($o) => $o->effective_qty_ordered);
        $totalQtyDelivered = (clone $statsQuery)->get()->sum(fn($o) => $o->total_qty_out);
        $totalRemaining = (clone $statsQuery)->get()->sum(fn($o) => $o->remaining_balance);
        $orders = $query->orderBy('so_number', 'desc')
            ->paginate(10, ['*'], 'page', $page)
            ->appends(['search' => $searchQuery]);

        return view('dashboard', compact('orders', 'searchQuery', 'totalOrders', 'totalQtyOrdered', 'totalQtyDelivered', 'totalRemaining'));
    }

    /**
     * Export database records to Excel.
     */
    public function export(): BinaryFileResponse
    {
        return Excel::download(new InventoryExport, 'inventory_export.xlsx');
    }

    /**
     * Show the Excel import form.
     */
    public function showImportForm(): View
    {
        return view('import');
    }

    /**
     * Handle the Excel file import.
     */
    public function import(Request $request): RedirectResponse
    {
        $request->validate([
            'excel_file' => ['required', 'file', 'mimes:xlsx,xls'],
        ]);

        try {
            Excel::import(new InventoryImport, $request->file('excel_file'));
            
            return redirect()->route('dashboard')
                ->with('success', 'Excel data imported and merged successfully!');
        } catch (\Exception $e) {
            return back()->with('danger', 'Error during import: ' . $e->getMessage());
        }
    }
}
