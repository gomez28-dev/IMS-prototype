<?php

namespace App\Http\Controllers\WetStock;

use App\Http\Controllers\Controller;
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
        ]);
    }
}
