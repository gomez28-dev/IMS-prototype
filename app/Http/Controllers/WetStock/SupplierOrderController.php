<?php

namespace App\Http\Controllers\WetStock;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\SupplierOrder;
use App\Models\Warehouse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class SupplierOrderController extends Controller
{
    /**
     * Display a listing of incoming supplier stock orders.
     */
    public function index(Request $request): View
    {
        $query = SupplierOrder::with(['warehouse', 'creator'])->orderBy('created_at', 'desc');

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('warehouse_id')) {
            $query->where('warehouse_id', $request->warehouse_id);
        }

        $supplierOrders = $query->paginate(20);
        $warehouses = Warehouse::orderBy('name', 'asc')->get();

        return view('wetstock.supplier-orders.index', [
            'supplierOrders' => $supplierOrders,
            'warehouses' => $warehouses,
            'currentStatus' => $request->status,
            'currentWarehouse' => $request->warehouse_id,
        ]);
    }

    /**
     * Show form for creating a new incoming supplier order.
     */
    public function create(): View
    {
        $warehouses = Warehouse::orderBy('name', 'asc')->get();
        return view('wetstock.supplier-orders.form', [
            'supplierOrder' => null,
            'warehouses' => $warehouses,
            'title' => 'Add Incoming Supplier Stock',
        ]);
    }

    /**
     * Store a newly created incoming supplier order.
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'po_number' => ['required', 'string', 'max:255'],
            'warehouse_id' => ['required', 'exists:warehouses,id'],
            'supplier_name' => ['required', 'string', 'max:255'],
            'liters' => ['required', 'integer', 'min:1'],
            'status' => ['required', 'string', 'in:UNLIFTED_PICKUP,PENDING_DELIVERY,COMPLETED'],
            'remarks' => ['nullable', 'string', 'max:1000'],
        ]);

        $supplierOrder = SupplierOrder::create([
            'po_number' => $validated['po_number'],
            'warehouse_id' => $validated['warehouse_id'],
            'supplier_name' => $validated['supplier_name'],
            'liters' => $validated['liters'],
            'status' => $validated['status'],
            'remarks' => $validated['remarks'] ?? null,
            'created_by' => Auth::id(),
        ]);

        AuditLog::create([
            'admin_id' => Auth::id(),
            'action' => 'CREATED',
            'description' => "Added Incoming Supplier Stock PO #{$supplierOrder->po_number} ({$supplierOrder->supplier_name}, " . number_format($supplierOrder->liters) . "L, Status: {$supplierOrder->status})",
        ]);

        return redirect()->route('wetstock.supplier-orders.index')
            ->with('success', "Incoming Supplier Order PO #{$supplierOrder->po_number} added successfully.");
    }

    /**
     * Show form for editing an incoming supplier order.
     */
    public function edit(SupplierOrder $supplierOrder): View
    {
        $warehouses = Warehouse::orderBy('name', 'asc')->get();
        return view('wetstock.supplier-orders.form', [
            'supplierOrder' => $supplierOrder,
            'warehouses' => $warehouses,
            'title' => "Edit Incoming Supplier Order PO #{$supplierOrder->po_number}",
        ]);
    }

    /**
     * Update an incoming supplier order.
     */
    public function update(Request $request, SupplierOrder $supplierOrder): RedirectResponse
    {
        $validated = $request->validate([
            'po_number' => ['required', 'string', 'max:255'],
            'warehouse_id' => ['required', 'exists:warehouses,id'],
            'supplier_name' => ['required', 'string', 'max:255'],
            'liters' => ['required', 'integer', 'min:1'],
            'status' => ['required', 'string', 'in:UNLIFTED_PICKUP,PENDING_DELIVERY,COMPLETED'],
            'remarks' => ['nullable', 'string', 'max:1000'],
        ]);

        $supplierOrder->update($validated);

        AuditLog::create([
            'admin_id' => Auth::id(),
            'action' => 'UPDATED',
            'description' => "Updated Incoming Supplier Stock PO #{$supplierOrder->po_number} ({$supplierOrder->supplier_name}, " . number_format($supplierOrder->liters) . "L, Status: {$supplierOrder->status})",
        ]);

        return redirect()->route('wetstock.supplier-orders.index')
            ->with('success', "Incoming Supplier Order PO #{$supplierOrder->po_number} updated successfully.");
    }

    /**
     * Mark an incoming supplier order as completed.
     */
    public function complete(SupplierOrder $supplierOrder): RedirectResponse
    {
        $supplierOrder->update(['status' => 'COMPLETED']);

        AuditLog::create([
            'admin_id' => Auth::id(),
            'action' => 'UPDATED',
            'description' => "Marked Incoming Supplier Stock PO #{$supplierOrder->po_number} as COMPLETED",
        ]);

        return redirect()->back()
            ->with('success', "Supplier Order PO #{$supplierOrder->po_number} marked as COMPLETED.");
    }
}
