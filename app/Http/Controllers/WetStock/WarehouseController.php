<?php

namespace App\Http\Controllers\WetStock;

use App\Http\Controllers\Controller;
use App\Models\Warehouse;
use Illuminate\View\View;

class WarehouseController extends Controller
{
    /**
     * Show a warehouse with its tanks.
     */
    public function show(Warehouse $warehouse): View
    {
        $tanks = $warehouse->tanks()->orderBy('name', 'asc')->get();

        return view('wetstock.warehouses.show', [
            'warehouse' => $warehouse,
            'tanks' => $tanks,
        ]);
    }
}
