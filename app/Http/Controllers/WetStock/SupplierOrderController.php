<?php

namespace App\Http\Controllers\WetStock;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\ModificationRequest;
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
        $query = SupplierOrder::with(['warehouse', 'creator', 'modificationRequests' => fn ($q) => $q->where('status', 'PENDING')->with('requestedBy:id,name')])->orderBy('created_at', 'desc');

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
            'status' => ['required', 'string', 'in:UNLIFTED_PICKUP,PENDING_DELIVERY,COMPLETED,CANCELLED'],
            'remarks' => ['nullable', 'string', 'max:1000'],
        ]);

        $po = trim($validated['po_number']);
        $supplier = trim($validated['supplier_name']);
        $dup = SupplierOrder::where('po_number', $po)
            ->whereRaw('LOWER(supplier_name) = ?', [strtolower($supplier)])
            ->first();

        if ($dup) {
            return back()->withInput()->with('danger', "Duplicate blocked: PO #{$dup->po_number} already exists for supplier {$dup->supplier_name} (" . number_format($dup->liters) . "L, added " . $dup->created_at->format('M d, Y') . ") — edit the existing entry instead, or use a distinguishing PO reference.");
        }

        $validated['po_number'] = $po;
        $validated['supplier_name'] = $supplier;

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
     * Update an incoming supplier order via Modification Request (Module 2 approval).
     */
    public function update(Request $request, SupplierOrder $supplierOrder): RedirectResponse
    {
        if (!Auth::user()->canEditModule2()) {
            abort(403);
        }

        $validated = $request->validate([
            'po_number' => ['required', 'string', 'max:255'],
            'warehouse_id' => ['required', 'exists:warehouses,id'],
            'supplier_name' => ['required', 'string', 'max:255'],
            'liters' => ['required', 'integer', 'min:1'],
            'status' => ['required', 'string', 'in:UNLIFTED_PICKUP,PENDING_DELIVERY,COMPLETED,CANCELLED'],
            'remarks' => ['nullable', 'string', 'max:1000'],
            'modification_reason' => ['nullable', 'string', 'max:1000'],
        ]);

        $po = trim($validated['po_number']);
        $supplier = trim($validated['supplier_name']);
        $validated['po_number'] = $po;
        $validated['supplier_name'] = $supplier;

        $dup = SupplierOrder::where('po_number', $po)
            ->whereRaw('LOWER(supplier_name) = ?', [strtolower($supplier)])
            ->where('id', '!=', $supplierOrder->id)
            ->first();

        if ($dup) {
            return back()->withInput()->with('danger', "Duplicate blocked: PO #{$dup->po_number} already exists for supplier {$dup->supplier_name} (" . number_format($dup->liters) . "L, added " . $dup->created_at->format('M d, Y') . ") — edit the existing entry instead, or use a distinguishing PO reference.");
        }

        $newValues = [
            'po_number' => $po,
            'warehouse_id' => $validated['warehouse_id'],
            'supplier_name' => $supplier,
            'liters' => (int) $validated['liters'],
            'status' => $validated['status'],
            'remarks' => $validated['remarks'] ?? null,
        ];

        $changes = [];
        foreach ($newValues as $field => $newVal) {
            $oldVal = $supplierOrder->{$field};
            if ((string) $oldVal !== (string) $newVal) {
                $changes[$field] = ['old' => $oldVal, 'new' => $newVal];
            }
        }

        if (empty($changes)) {
            return redirect()->route('wetstock.supplier-orders.index')
                ->with('info', "No changes detected on PO #{$supplierOrder->po_number}.");
        }

        $existingPending = $supplierOrder->modificationRequests()->where('status', 'PENDING')->first();
        if ($existingPending) {
            return redirect()->route('wetstock.supplier-orders.index')
                ->with('warning', "PO #{$supplierOrder->po_number} already has a Pending Modification Request (#{$existingPending->id}) awaiting review.");
        }

        $modRequest = ModificationRequest::create([
            'requestable_type' => SupplierOrder::class,
            'requestable_id' => $supplierOrder->id,
            'requested_by' => Auth::id(),
            'changes' => $changes,
            'reason' => $validated['modification_reason'] ?? 'Supplier stock modification submitted',
            'status' => 'PENDING',
        ]);

        AuditLog::create([
            'admin_id' => Auth::id(),
            'action' => 'REQUESTED',
            'description' => "Submitted Modification Request #{$modRequest->id} for PO #{$supplierOrder->po_number} ({$supplierOrder->supplier_name}, " . count($changes) . " field(s) changed)",
        ]);

        return redirect()->route('wetstock.supplier-orders.index')
            ->with('success', "Modification request for PO #{$supplierOrder->po_number} submitted to the Approvals Queue for Operations Manager review.");
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
