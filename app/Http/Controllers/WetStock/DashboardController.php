<?php

namespace App\Http\Controllers\WetStock;

use App\Http\Controllers\Controller;
use App\Models\Delivery;
use App\Models\Warehouse;
use Illuminate\View\View;

class DashboardController extends Controller
{
    /**
     * Show the Web Stock main dashboard (per-tank stats rolling up to warehouse level).
     */
    public function index(): View
    {
        $warehouses = Warehouse::with(['tanks' => function ($query) {
            $query->where('is_active', true)->orderBy('name', 'asc');
        }])->orderBy('name', 'asc')->get();

        return view('wetstock.dashboard', [
            'warehouses' => $warehouses,
            'unassignedCount' => Delivery::where('status', '!=', 'CANCELLED')
            ->whereHas('order', fn($q) => $q->where('status', '!=', 'Cancelled'))
            ->where(function ($q) {
                $q->whereDoesntHave('allocations')
                    ->orWhereRaw('(SELECT COALESCE(SUM(quantity), 0) FROM delivery_allocations WHERE delivery_id = deliveries.id) < qty_out');
            })
            ->count(),
        ]);
    }
}
