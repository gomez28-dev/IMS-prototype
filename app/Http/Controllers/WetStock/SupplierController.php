<?php

namespace App\Http\Controllers\WetStock;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Supplier;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class SupplierController extends Controller
{
    public function index(): View
    {
        $this->authorizeManage();

        $suppliers = Supplier::orderBy('company_name')
            ->orderBy('location')
            ->paginate(15);

        return view('wetstock.suppliers.index', compact('suppliers'));
    }

    public function create(): View
    {
        $this->authorizeManage();

        return view('wetstock.suppliers.create');
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorizeManage();

        $validated = $request->validate([
            'company_name' => ['required', 'string', 'max:128'],
            'location' => [
                'required', 'string', 'max:128',
                // One row per company/location pair.
                Rule::unique('suppliers', 'location')->where(
                    fn ($q) => $q->where('company_name', $request->input('company_name'))
                ),
            ],
            'address' => ['nullable', 'string', 'max:255'],
            'attention' => ['nullable', 'string', 'max:128'],
        ]);

        $validated['location'] = trim($validated['location']);
        $supplier = Supplier::create($validated);

        AuditLog::create([
            'admin_id' => Auth::id(),
            'action' => 'created',
            'description' => "Created supplier {$supplier->display_name}",
        ]);

        return redirect()->route('stock-orders.suppliers.index')
            ->with('success', "Supplier {$supplier->display_name} created successfully.");
    }

    public function edit(Supplier $supplier): View
    {
        $this->authorizeManage();

        return view('wetstock.suppliers.edit', compact('supplier'));
    }

    public function update(Request $request, Supplier $supplier): RedirectResponse
    {
        $this->authorizeManage();

        $validated = $request->validate([
            'company_name' => ['required', 'string', 'max:128'],
            'location' => [
                'required', 'string', 'max:128',
                Rule::unique('suppliers', 'location')
                    ->where(fn ($q) => $q->where('company_name', $request->input('company_name')))
                    ->ignore($supplier->id),
            ],
            'address' => ['nullable', 'string', 'max:255'],
            'attention' => ['nullable', 'string', 'max:128'],
        ]);

        $validated['location'] = trim($validated['location']);
        $supplier->update($validated);

        AuditLog::create([
            'admin_id' => Auth::id(),
            'action' => 'updated',
            'description' => "Updated supplier {$supplier->display_name}",
        ]);

        return redirect()->route('stock-orders.suppliers.index')
            ->with('success', "Supplier {$supplier->display_name} updated successfully.");
    }

    /**
     * Deactivate/reactivate a supplier.
     *
     * Deactivating is used instead of deleting so historical Purchase Orders
     * keep pointing at a real supplier, while the supplier stops appearing in
     * the Purchase Order dropdowns.
     */
    public function toggleActive(Supplier $supplier): RedirectResponse
    {
        $this->authorizeManage();

        $supplier->update(['is_active' => !$supplier->is_active]);
        $state = $supplier->is_active ? 'reactivated' : 'deactivated';

        AuditLog::create([
            'admin_id' => Auth::id(),
            'action' => 'updated',
            'description' => ucfirst($state) . " supplier {$supplier->display_name}",
        ]);

        return back()->with('success', "Supplier {$supplier->display_name} {$state} successfully.");
    }

    private function authorizeManage(): void
    {
        if (!Auth::user()->canManageSuppliers()) {
            abort(403, 'Unauthorized to manage suppliers.');
        }
    }
}
