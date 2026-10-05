@extends('layouts.app')

@section('title', 'New Supplier Purchase Order')

@section('content')
<div class="row justify-content-center">
    <div class="col-md-10 col-lg-9">
        <div class="mb-3">
            <a href="{{ route('stock-orders.index') }}" class="text-decoration-none text-secondary small">
                <i class="bi bi-arrow-left me-1"></i> Back to Stock Orders
            </a>
        </div>

        <div class="card card-custom border-0 shadow-sm">
            <div class="card-header bg-white py-3 border-bottom d-flex justify-content-between align-items-center">
                <div>
                    <h4 class="fw-bold mb-0 text-dark">
                        <i class="bi bi-bag-plus text-primary me-2"></i>Create Supplier Purchase Order
                    </h4>
                    <p class="text-muted small mb-0 mt-1">
                        Contract bulk fuel volume from refinery suppliers (Petron, Shell, etc.) for allocation and drawdown.
                    </p>
                </div>
                <span class="badge bg-primary-subtle text-primary border border-primary-subtle rounded-pill px-3 py-2">
                    <i class="bi bi-shield-check me-1"></i>Purchasing Allocation Pool
                </span>
            </div>

            <div class="card-body p-4">
                <form method="POST" action="{{ route('stock-orders.store-supplier-po') }}" id="supplierPoForm">
                    @csrf

                    @if ($errors->any())
                        <div class="alert alert-danger border-0 rounded-3 shadow-sm mb-4">
                            <ul class="mb-0 small ps-3">
                                @foreach ($errors->all() as $err)
                                    <li>{{ $err }}</li>
                                @endforeach
                            </ul>
                        </div>
                    @endif

                    <div class="row g-3 mb-4">
                        <div class="col-md-6">
                            <label for="po_number" class="form-label fw-semibold small">Official PO Number <span class="text-danger">*</span></label>
                            <input type="text" class="form-control @error('po_number') is-invalid @enderror" id="po_number" name="po_number"
                                   placeholder="e.g. PO-PETRON-2026-001" value="{{ old('po_number') }}" required>
                            <div class="form-text extra-small">Unique purchasing reference number issued to refinery.</div>
                        </div>

                        <div class="col-md-6">
                            <label for="supplier_name" class="form-label fw-semibold small">Supplier / Refinery <span class="text-danger">*</span></label>
                            <input type="text" class="form-control @error('supplier_name') is-invalid @enderror" id="supplier_name" name="supplier_name"
                                   list="supplierSuggestions" placeholder="e.g. Petron Bataan Refinery" value="{{ old('supplier_name') }}" required>
                            <datalist id="supplierSuggestions">
                                <option value="Petron Bataan Refinery">
                                <option value="Shell Tabangao Batangas">
                                <option value="Total Limay Terminal">
                                <option value="Insular Oil Subic">
                                <option value="Phoenix Petroleum Batangas">
                            </datalist>
                        </div>

                        <div class="col-md-4">
                            <label for="po_type" class="form-label fw-semibold small">PO Classification <span class="text-danger">*</span></label>
                            <select class="form-select" id="po_type" name="po_type" required>
                                <option value="STANDARD_REPLENISHMENT" {{ old('po_type') === 'STANDARD_REPLENISHMENT' ? 'selected' : '' }}>Standard Refinery Replenishment</option>
                                <option value="BUY_BACK" {{ old('po_type') === 'BUY_BACK' ? 'selected' : '' }}>PO Buy Back (Repurchase from Client)</option>
                            </select>
                        </div>

                        <div class="col-md-4">
                            <label for="date_needed" class="form-label fw-semibold small">Target Availability Date</label>
                            <input type="date" class="form-control" id="date_needed" name="date_needed" value="{{ old('date_needed', now()->format('Y-m-d')) }}">
                        </div>

                        <div class="col-md-4">
                            <label for="warehouse_id" class="form-label fw-semibold small">Destination Depot (Optional)</label>
                            <select class="form-select" id="warehouse_id" name="warehouse_id">
                                <option value="">-- General Allocation Pool (Refinery Rack) --</option>
                                @foreach ($warehouses as $wh)
                                    <option value="{{ $wh->id }}" {{ old('warehouse_id') == $wh->id ? 'selected' : '' }}>{{ $wh->name }}</option>
                                @endforeach
                            </select>
                            <div class="form-text extra-small">Leave blank if volume can be lifted by Fuel Trade or Tankers.</div>
                        </div>
                    </div>

                    {{-- Multi-Product Line Items --}}
                    <div class="card border border-light-subtle rounded-3 mb-4 shadow-none bg-light bg-opacity-50">
                        <div class="card-header bg-white py-3 border-bottom d-flex justify-content-between align-items-center">
                            <div>
                                <h6 class="fw-bold mb-0 text-dark">
                                    <i class="bi bi-fuel-pump text-primary me-2"></i>Product Volume Allocations
                                </h6>
                                <small class="text-muted">Specify products and contract volumes included in this Supplier PO.</small>
                            </div>
                            <button type="button" class="btn btn-sm btn-outline-primary rounded-pill px-3" onclick="addProductRow()">
                                <i class="bi bi-plus-lg me-1"></i> Add Product Line
                            </button>
                        </div>
                        <div class="card-body p-0">
                            <div class="table-responsive">
                                <table class="table table-borderless align-middle mb-0" id="productsTable">
                                    <thead class="table-light small">
                                        <tr>
                                            <th class="ps-3" style="width: 35%;">Product</th>
                                            <th style="width: 35%;">Volume (Liters) <span class="text-danger">*</span></th>
                                            <th style="width: 20%;">Price / Liter (₱)</th>
                                            <th class="pe-3 text-center" style="width: 10%;">Remove</th>
                                        </tr>
                                    </thead>
                                    <tbody id="productRowsBody">
                                        <tr class="product-row">
                                            <td class="ps-3">
                                                <select name="items[0][product]" class="form-select form-select-sm" required>
                                                    <option value="Diesel" selected>Diesel</option>
                                                    <option value="Premium">Premium (Mogas 95)</option>
                                                    <option value="Unleaded">Unleaded (Mogas 91)</option>
                                                </select>
                                            </td>
                                            <td>
                                                <div class="input-group input-group-sm">
                                                    <input type="number" name="items[0][quantity]" class="form-control form-control-sm qty-input"
                                                           placeholder="e.g. 50000" min="1" required oninput="calculateTotalVolume()">
                                                    <span class="input-group-text">L</span>
                                                </div>
                                            </td>
                                            <td>
                                                <div class="input-group input-group-sm">
                                                    <span class="input-group-text">₱</span>
                                                    <input type="number" step="0.01" name="items[0][unit_price]" class="form-control form-control-sm" placeholder="0.00">
                                                </div>
                                            </td>
                                            <td class="pe-3 text-center">
                                                <button type="button" class="btn btn-sm btn-link text-danger p-0" onclick="removeProductRow(this)" disabled>
                                                    <i class="bi bi-trash"></i>
                                                </button>
                                            </td>
                                        </tr>
                                    </tbody>
                                    <tfoot class="table-light border-top">
                                        <tr>
                                            <td class="ps-3 fw-bold text-dark">Total PO Volume:</td>
                                            <td colspan="3" class="fw-bold text-primary fs-6 font-monospace" id="totalVolumeDisplay">
                                                0 L
                                            </td>
                                        </tr>
                                    </tfoot>
                                </table>
                            </div>
                        </div>
                    </div>

                    <div class="mb-4">
                        <label for="remarks" class="form-label fw-semibold small">Special Terms / Refinery Remarks</label>
                        <textarea class="form-control" id="remarks" name="remarks" rows="2" placeholder="e.g. Pre-paid contract, valid for lift at Bataan Limay rack until month-end.">{{ old('remarks') }}</textarea>
                    </div>

                    <div class="d-flex justify-content-end gap-2">
                        <a href="{{ route('stock-orders.index') }}" class="btn btn-light border rounded-pill px-4">Cancel</a>
                        <button type="submit" class="btn btn-primary-custom rounded-pill px-4 shadow-sm">
                            <i class="bi bi-check2-circle me-1"></i> Save Supplier Purchase Order
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
let rowIndex = 1;

function addProductRow() {
    const tbody = document.getElementById('productRowsBody');
    const tr = document.createElement('tr');
    tr.className = 'product-row';
    tr.innerHTML = `
        <td class="ps-3">
            <select name="items[${rowIndex}][product]" class="form-select form-select-sm" required>
                <option value="Diesel">Diesel</option>
                <option value="Premium" selected>Premium (Mogas 95)</option>
                <option value="Unleaded">Unleaded (Mogas 91)</option>
            </select>
        </td>
        <td>
            <div class="input-group input-group-sm">
                <input type="number" name="items[${rowIndex}][quantity]" class="form-control form-control-sm qty-input"
                       placeholder="e.g. 20000" min="1" required oninput="calculateTotalVolume()">
                <span class="input-group-text">L</span>
            </div>
        </td>
        <td>
            <div class="input-group input-group-sm">
                <span class="input-group-text">₱</span>
                <input type="number" step="0.01" name="items[${rowIndex}][unit_price]" class="form-control form-control-sm" placeholder="0.00">
            </div>
        </td>
        <td class="pe-3 text-center">
            <button type="button" class="btn btn-sm btn-link text-danger p-0" onclick="removeProductRow(this)">
                <i class="bi bi-trash"></i>
            </button>
        </td>
    `;
    tbody.appendChild(tr);
    rowIndex++;
    updateRemoveButtons();
    calculateTotalVolume();
}

function removeProductRow(btn) {
    const row = btn.closest('tr');
    row.remove();
    updateRemoveButtons();
    calculateTotalVolume();
}

function updateRemoveButtons() {
    const rows = document.querySelectorAll('.product-row');
    rows.forEach(r => {
        const removeBtn = r.querySelector('button');
        if (removeBtn) {
            removeBtn.disabled = rows.length === 1;
        }
    });
}

function calculateTotalVolume() {
    let total = 0;
    document.querySelectorAll('.qty-input').forEach(input => {
        const val = parseInt(input.value) || 0;
        total += val;
    });
    document.getElementById('totalVolumeDisplay').textContent = total.toLocaleString() + ' L';
}
</script>
@endsection
