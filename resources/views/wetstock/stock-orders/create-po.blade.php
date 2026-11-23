@extends('layouts.app')

@php
    // The same view renders both creating a PO and editing an existing one.
    $isEdit = $isEdit ?? false;
    $po = $isEdit ? ($purchaseOrder ?? null) : null;
    $poLocked = $po && $po->isVpApproved();
    $formAction = $isEdit
        ? route('stock-orders.update-supplier-po', $po->id)
        : route('stock-orders.store-supplier-po');
    // Editing renders one row per existing line; creating renders one blank row.
    $lineItems = ($isEdit && $po && $po->items->isNotEmpty()) ? $po->items->values() : collect([null]);

    // The Date is never typed in. A new PO is dated today (the server sets it
    // again on save); an existing PO keeps the date it already has.
    $poDate = ($isEdit && $po?->po_date) ? $po->po_date : now();
    $dateNeeded = ($isEdit && $po?->date_needed) ? $po->date_needed : $poDate;
@endphp

@section('title', $isEdit ? 'Edit Purchase Order' : 'Create Purchase Order')

@section('content')
<style>
    /* Submitted confirmation card. Only the card moves; the form stays still. */
    .po-overlay { position: fixed; inset: 0; z-index: 2050; display: none; align-items: center; justify-content: center; padding: 1rem; background: rgba(15, 23, 42, .5); -webkit-backdrop-filter: blur(6px); backdrop-filter: blur(6px); }
    .po-overlay.show { display: flex; animation: poFade .25s ease-out both; }
    .po-scene { perspective: 900px; }
    .po-float { animation: poRise .55s cubic-bezier(.2, .9, .3, 1.15) both, poFloat 4.5s ease-in-out .55s infinite; }
    .po-card { position: relative; width: min(92vw, 400px); padding: 3rem 1.75rem 1.5rem; border-radius: 22px; text-align: center; background: #fff; transform-style: preserve-3d; transition: transform .18s ease-out; box-shadow: 0 1px 0 rgba(255, 255, 255, .9) inset, 0 40px 70px -20px rgba(15, 23, 42, .45), 0 18px 30px -18px rgba(15, 23, 42, .35), 0 0 0 1px rgba(15, 23, 42, .06); }
    .po-badge { width: 76px; height: 76px; margin: -5.4rem auto 1rem; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 2.4rem; color: #fff; background: radial-gradient(circle at 30% 25%, #ff9a6b, #ff5a1f 60%, #e04300); box-shadow: 0 14px 24px -6px rgba(255, 90, 31, .55), inset 0 -6px 10px rgba(0, 0, 0, .18), inset 0 4px 8px rgba(255, 255, 255, .4); transform: translateZ(60px); }
    .po-card h5 { font-weight: 700; color: #0f172a; margin-bottom: .5rem; transform: translateZ(30px); }
    .po-card p { color: #475569; font-size: .92rem; margin-bottom: .6rem; transform: translateZ(20px); }
    .po-ref { display: inline-block; margin: .1rem 0 1.1rem; padding: .3rem .85rem; border-radius: 999px; background: #fff1ea; color: #c2410c; font-weight: 600; font-size: .85rem; transform: translateZ(25px); }
    .po-card .btn { transform: translateZ(35px); }
    .po-countdown { margin-top: .9rem; font-size: .78rem; color: #94a3b8; }
    .po-ground { width: 58%; height: 16px; margin: 28px auto 0; border-radius: 50%; background: rgba(15, 23, 42, .4); filter: blur(10px); animation: poGround 4.5s ease-in-out .55s infinite; }
    @keyframes poFade { from { opacity: 0; } to { opacity: 1; } }
    @keyframes poRise { from { opacity: 0; transform: translateY(30px) scale(.92); } to { opacity: 1; transform: none; } }
    @keyframes poFloat { 0%, 100% { transform: translateY(0); } 50% { transform: translateY(-10px); } }
    @keyframes poGround { 0%, 100% { transform: scale(1); opacity: .5; } 50% { transform: scale(.8); opacity: .3; } }
    @media (prefers-reduced-motion: reduce) {
        .po-float, .po-ground { animation: none; }
        .po-card { transition: none; }
    }
</style>

<div class="row justify-content-center">
    <div class="col-md-10 col-lg-9">
        <div class="mb-3">
            <a href="{{ route('stock-orders.index') }}" class="text-decoration-none text-secondary small">
                <i class="bi bi-arrow-left me-1"></i> Back to Stock Orders
            </a>
        </div>

        <div class="card card-custom border-0 shadow-sm">
            <div class="card-header bg-white py-3 border-bottom">
                <h4 class="fw-bold mb-0 text-dark">{{ $isEdit ? 'Edit Purchase Order' : 'Create Purchase Order' }}</h4>
                @if ($isEdit)
                    <p class="text-muted small mb-0 mt-1">
                        @if ($poLocked)
                            Editing an approved PO. Saving will send it back to the VP for approval.
                        @else
                            Editing PO {{ $po->po_number }}.
                        @endif
                    </p>
                @endif
            </div>

            <div class="card-body p-4">
                <form method="POST" action="{{ $formAction }}" id="supplierPoForm">
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

                    {{-- Filled by the script when a save is rejected without leaving the page. --}}
                    <div class="alert alert-danger border-0 rounded-3 shadow-sm mb-4 d-none" id="poFormErrors" role="alert">
                        <ul class="mb-0 small ps-3" id="poFormErrorList"></ul>
                    </div>

                    <div class="row g-3 mb-4">
                        {{-- Left column: PO#, Address, Attention. Right column: Vendor Company, Date, Terms. --}}
                        <div class="col-md-6">
                            <label for="po_number" class="form-label fw-semibold small">
                                PO#
                                @unless ($poLocked)<span class="text-danger">*</span>@endunless
                            </label>
                            @php
                                // Readonly once the PO exists. updateSupplierPo has no
                                // po_number rule, so an editable box here would take a
                                // value and silently throw it away.
                                $poNumberLocked = $poLocked || $isEdit;
                            @endphp
                            <input type="text" class="form-control @error('po_number') is-invalid @enderror @if ($poNumberLocked) bg-light @endif" id="po_number" name="po_number"
                                   @if ($poNumberLocked) readonly @else required @endif
                                   placeholder="e.g. PO-PETRON-2026-001" value="{{ old('po_number', $po->po_number ?? '') }}">
                            <div class="form-text extra-small">
                                @if ($poLocked)
                                    Locked: this PO is already approved, so its number cannot change.
                                @elseif ($isEdit)
                                    Locked: the number was issued when the PO was raised and cannot be changed.
                                @else
                                    Unique purchasing reference number issued to refinery.
                                @endif
                            </div>
                        </div>

                        <div class="col-md-6">
                            <label for="supplier_id" class="form-label fw-semibold small">Vendor Company <span class="text-danger">*</span></label>
                            <select class="form-select @error('supplier_id') is-invalid @enderror" id="supplier_id" name="supplier_id" required>
                                <option value="">-- Choose Vendor Company --</option>
                                @foreach ($suppliers as $supplier)
                                    <option value="{{ $supplier->id }}" data-location="{{ $supplier->location }}" {{ (int) old('supplier_id', $po->supplier_id ?? 0) === $supplier->id ? 'selected' : '' }}>
                                        {{ $supplier->company_name }}
                                    </option>
                                @endforeach
                            </select>
                            <div class="form-text extra-small">
                                <a href="{{ route('stock-orders.suppliers.create') }}" target="_blank" class="text-decoration-underline">Add a new supplier</a>
                                if none is listed.
                            </div>
                            @error('supplier_id')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>

                        <div class="col-md-6">
                            <label for="supplier_location" class="form-label fw-semibold small">Address <span class="text-danger">*</span></label>
                            <select class="form-select" id="supplier_location" required disabled>
                                <option value="">-- Choose Vendor Company first --</option>
                            </select>
                            <div class="form-text extra-small" id="supplierAddressHint"></div>
                        </div>

                        <div class="col-md-6">
                            <label for="po_date_display" class="form-label fw-semibold small">Date</label>
                            {{-- Display only. The values that are saved are the hidden fields, and the server sets the date itself on a new PO. --}}
                            <input type="text" class="form-control bg-light" id="po_date_display" value="{{ $poDate->format('m/d/Y') }}" readonly tabindex="-1">
                            <input type="hidden" name="po_date" value="{{ $poDate->format('Y-m-d') }}">
                            <input type="hidden" name="date_needed" value="{{ $dateNeeded->format('Y-m-d') }}">
                        </div>

                        <div class="col-md-6">
                            <label for="attention" class="form-label fw-semibold small">Attention</label>
                            <input type="text" class="form-control @error('attention') is-invalid @enderror" id="attention" name="attention"
                                   placeholder="e.g. Mr. Aaron Uy" value="{{ old('attention', $po?->attention) }}">
                            <div class="form-text extra-small">Defaults to the supplier's contact when left blank.</div>
                            @error('attention')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>

                        <div class="col-md-6">
                            <label for="terms" class="form-label fw-semibold small">Terms</label>
                            <input type="text" class="form-control @error('terms') is-invalid @enderror" id="terms" name="terms"
                                   placeholder="e.g. VAT EX CASH" value="{{ old('terms', $po?->terms) }}">
                            @error('terms')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
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
                                            <th class="ps-3" style="width: 32%;">Product</th>
                                            <th style="width: 26%;">Volume (Liters) <span class="text-danger">*</span></th>
                                            <th style="width: 20%;">Price</th>
                                            <th style="width: 12%;">Amount</th>
                                            <th class="pe-3 text-center" style="width: 10%;">Remove</th>
                                        </tr>
                                    </thead>
                                    <tbody id="productRowsBody">
                                        @foreach ($lineItems as $index => $item)
                                            <tr class="product-row">
                                                <td class="ps-3">
                                                    {{-- Fuel is always measured in liters, so the unit is no longer shown; it is still sent so saving works as before. --}}
                                                    <input type="hidden" name="items[{{ $index }}][unit]" value="{{ $item?->unit ?? 'LTRS' }}">
                                                    <select name="items[{{ $index }}][product]" class="form-select form-select-sm" required>
                                                        <option value="Diesel" {{ $item?->product === 'Diesel' ? 'selected' : '' }}>Diesel</option>
                                                        <option value="Premium" {{ $item?->product === 'Premium' ? 'selected' : '' }}>Premium (Mogas 95)</option>
                                                        <option value="Unleaded" {{ $item?->product === 'Unleaded' ? 'selected' : '' }}>Unleaded (Mogas 91)</option>
                                                    </select>
                                                </td>
                                                <td>
                                                    <div class="input-group input-group-sm">
                                                        <input type="number" name="items[{{ $index }}][quantity]" class="form-control form-control-sm qty-input"
                                                               placeholder="e.g. 50000" min="1" value="{{ old("items.$index.quantity", $item?->quantity_ordered) }}" required oninput="calculateTotalVolume()">
                                                        <span class="input-group-text">L</span>
                                                    </div>
                                                </td>
                                                <td>
                                                    <div class="input-group input-group-sm">
                                                        <span class="input-group-text">&#8369;</span>
                                                        <input type="number" step="0.01" name="items[{{ $index }}][unit_price]" class="form-control form-control-sm price-input" placeholder="0.00" value="{{ old("items.$index.unit_price", $item?->unit_price) }}" oninput="calculateTotalAmount()">
                                                    </div>
                                                </td>
                                                <td class="text-end fw-semibold font-monospace">
                                                    <span class="line-amount">0.00</span>
                                                </td>
                                                <td class="pe-3 text-center">
                                                    <button type="button" class="btn btn-sm btn-link text-danger p-0" onclick="removeProductRow(this)" @if ($lineItems->count() < 2) disabled @endif>
                                                        <i class="bi bi-trash"></i>
                                                    </button>
                                                </td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                    <tfoot class="table-light border-top">
                                        <tr>
                                            <td class="ps-3 fw-bold text-dark">Total Quantity:</td>
                                            <td colspan="4" class="fw-bold text-primary fs-6 font-monospace" id="totalVolumeDisplay">
                                                0 L
                                            </td>
                                        </tr>
                                    </tfoot>
                                </table>
                            </div>
                        </div>
                    </div>

                    {{-- Money summary. VAT is entered by hand, matching the printed form. --}}
                    <div class="card border-0 bg-light rounded-3 mb-4">
                        <div class="card-body">
                            <div class="row justify-content-end">
                                <div class="col-md-7">
                                    <div class="d-flex justify-content-between align-items-center border-bottom pb-2 mb-2">
                                        <span class="fw-semibold text-dark">Total Amount</span>
                                        <span class="fw-bold font-monospace" id="totalAmountDisplay">{{ number_format((float) old('total_amount', $po?->total_amount ?? 0), 2) }}</span>
                                    </div>
                                    <div class="mb-2">
                                        <label for="vatable_sales_amount" class="form-label fw-medium text-secondary small mb-1">Vatable Sales Amount</label>
                                        <input type="number" step="0.01" min="0" class="form-control form-control-sm text-end font-monospace @error('vatable_sales_amount') is-invalid @enderror"
                                               id="vatable_sales_amount" name="vatable_sales_amount" value="{{ old('vatable_sales_amount', $po?->vatable_sales_amount) }}" placeholder="0.00">
                                        @error('vatable_sales_amount')
                                            <div class="invalid-feedback">{{ $message }}</div>
                                        @enderror
                                    </div>
                                    <div class="mb-2">
                                        <label for="vat_amount" class="form-label fw-medium text-secondary small mb-1">VAT Amount</label>
                                        <input type="number" step="0.01" min="0" class="form-control form-control-sm text-end font-monospace @error('vat_amount') is-invalid @enderror"
                                               id="vat_amount" name="vat_amount" value="{{ old('vat_amount', $po?->vat_amount) }}" placeholder="0.00">
                                        @error('vat_amount')
                                            <div class="invalid-feedback">{{ $message }}</div>
                                        @enderror
                                    </div>
                                    <div class="mb-2">
                                        <label for="less_w_tax" class="form-label fw-medium text-secondary small mb-1">Less w/ Tax</label>
                                        <input type="number" step="0.01" min="0" class="form-control form-control-sm text-end font-monospace @error('less_w_tax') is-invalid @enderror"
                                               id="less_w_tax" name="less_w_tax" value="{{ old('less_w_tax', $po?->less_w_tax) }}" placeholder="0.00">
                                        @error('less_w_tax')
                                            <div class="invalid-feedback">{{ $message }}</div>
                                        @enderror
                                    </div>
                                    <div class="d-flex justify-content-between align-items-center border-top pt-2">
                                        <label for="net_payable_amount" class="fw-semibold text-dark mb-0">Net Payable Amount</label>
                                        <input type="number" step="0.01" min="0" readonly tabindex="-1"
                                               class="form-control form-control-sm text-end font-monospace fw-bold bg-light @error('net_payable_amount') is-invalid @enderror"
                                               id="net_payable_amount" name="net_payable_amount"
                                               aria-describedby="netPayableHelp"
                                               value="{{ old('net_payable_amount', number_format((float) ($po?->net_payable_amount ?? $po?->total_amount ?? 0), 2, '.', '')) }}">
                                        @error('net_payable_amount')
                                            <div class="invalid-feedback">{{ $message }}</div>
                                        @enderror
                                    </div>
                                    <div class="form-text extra-small" id="netPayableHelp">
                                        Automatically calculated as Total Amount minus Less w/ Tax. This field cannot be edited.
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <input type="hidden" id="computed_total_amount" name="computed_total_amount" value="0.00">

                    <div class="mb-4">
                        <label for="remarks" class="form-label fw-semibold small">Additional Remarks</label>
                        <textarea class="form-control" id="remarks" name="remarks" rows="2" placeholder="e.g. Pre-paid contract, valid for lift at Bataan Limay rack until month-end.">{{ old('remarks', $po?->remarks) }}</textarea>
                    </div>

                    <div class="d-flex justify-content-end gap-2">
                        <a href="{{ route('stock-orders.purchase-orders.index') }}" class="btn btn-light border rounded-pill px-4">Cancel</a>
                        <button type="submit" id="poSubmitBtn" class="btn btn-primary-custom rounded-pill px-4 shadow-sm">
                            <i class="bi bi-check2-circle me-1"></i>
                            {{ $isEdit ? 'Save Changes' : 'Submit' }}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

@unless ($isEdit)
{{-- Shown after a new PO is saved, then returns to the Purchase Orders list. --}}
<div class="po-overlay" id="poSubmittedOverlay" role="dialog" aria-modal="true" aria-labelledby="poSubmittedTitle" aria-hidden="true">
    <div class="po-scene">
        <div class="po-float">
            <div class="po-card" id="poCard">
                <div class="po-badge"><i class="bi bi-check-lg"></i></div>
                <h5 id="poSubmittedTitle">Purchase Order Submitted</h5>
                <p>Your purchase order is now waiting for approval. You can follow its status from the Purchase Orders list.</p>
                <div class="po-ref" id="poSubmittedRef"></div>
                <div>
                    <button type="button" class="btn btn-primary-custom rounded-pill px-5" id="poOkBtn">OK</button>
                </div>
                <div class="po-countdown">Returning to Purchase Orders in <span id="poCountdown">6</span>s</div>
            </div>
        </div>
        <div class="po-ground"></div>
    </div>
</div>
@endunless

<script>
// Continue numbering after the rows already rendered, so an added line does
// not overwrite an existing one when the form posts.
let rowIndex = {{ $lineItems->count() }};

function addProductRow() {
    const tbody = document.getElementById('productRowsBody');
    const tr = document.createElement('tr');
    tr.className = 'product-row';
    tr.innerHTML = `
        <td class="ps-3">
            <input type="hidden" name="items[${rowIndex}][unit]" value="LTRS">
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
                <span class="input-group-text">&#8369;</span>
                <input type="number" step="0.01" name="items[${rowIndex}][unit_price]" class="form-control form-control-sm price-input" placeholder="0.00" oninput="calculateTotalAmount()">
            </div>
        </td>
        <td class="text-end fw-semibold font-monospace">
            <span class="line-amount">0.00</span>
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
    calculateTotalAmount();
}

function removeProductRow(btn) {
    const row = btn.closest('tr');
    row.remove();
    updateRemoveButtons();
    calculateTotalVolume();
    calculateTotalAmount();
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

/**
 * Net Payable is the gross less the creditable withholding tax. VAT already
 * sits inside the line total, so it is not subtracted. The field is readonly,
 * so this is display only -- the server recalculates the same figure on save.
 */
function calculateNetPayable() {
    const netPayable = document.getElementById('net_payable_amount');
    if (!netPayable) {
        return;
    }

    const total = parseFloat(document.getElementById('computed_total_amount').value) || 0;
    const lessWTax = parseFloat(document.getElementById('less_w_tax').value) || 0;

    netPayable.value = Math.max(0, total - lessWTax).toFixed(2);
}

/**
 * Line amounts and the total come from the product rows, so the stored PO
 * totals can never drift from what was actually ordered. VAT rows stay manual.
 */
function calculateTotalAmount() {
    let total = 0;

    document.querySelectorAll('.product-row').forEach(row => {
        const qty = parseFloat(row.querySelector('.qty-input').value) || 0;
        const price = parseFloat(row.querySelector('.price-input').value) || 0;
        const line = qty * price;

        row.querySelector('.line-amount').textContent = line.toLocaleString('en-PH', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        });

        total += line;
    });

    const formatted = total.toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    const totalDisplay = document.getElementById('totalAmountDisplay');
    if (totalDisplay) {
        totalDisplay.textContent = formatted;
    }

    // Mirror into a hidden field so the exact computed total is submitted.
    const hiddenTotal = document.getElementById('computed_total_amount');
    if (hiddenTotal) {
        hiddenTotal.value = total.toFixed(2);
    }

    calculateNetPayable();
}

document.addEventListener('DOMContentLoaded', function () {
    calculateTotalVolume();
    calculateTotalAmount();

    // Withholding tax feeds straight into Net Payable, so it recalculates
    // live rather than waiting for a product row to change.
    const lessWTax = document.getElementById('less_w_tax');
    if (lessWTax) {
        lessWTax.addEventListener('input', calculateNetPayable);
        lessWTax.addEventListener('change', calculateNetPayable);
    }

    // Vendor company -> address dropdown, filtered to that company only.
    const companySelect = document.getElementById('supplier_id');
    const locationSelect = document.getElementById('supplier_location');
    const addressHint = document.getElementById('supplierAddressHint');

    const supplierLocations = @json($suppliers->groupBy('company_name')->map(
        fn ($group) => $group->pluck('location', 'id')
    ));

    function renderLocations() {
        if (!companySelect || !locationSelect) return;

        const companyName = companySelect.options[companySelect.selectedIndex].textContent.trim();
        const locations = supplierLocations[companyName] || {};

        locationSelect.innerHTML = '';
        locationSelect.disabled = true;

        const placeholder = document.createElement('option');
        placeholder.value = '';
        placeholder.textContent = companySelect.value ? '-- Choose Address --' : '-- Choose Vendor Company first --';
        locationSelect.appendChild(placeholder);

        const keys = Object.keys(locations);
        keys.forEach(function (key) {
            const option = document.createElement('option');
            option.value = key;
            const loc = locations[key];
            option.textContent = (loc && loc !== '') ? loc : '(no address recorded)';
            locationSelect.appendChild(option);
        });

        if (keys.length > 0) {
            locationSelect.disabled = false;
            // The chosen vendor row already has its own address, so show it.
            if (companySelect.value && locations[companySelect.value] !== undefined) {
                locationSelect.value = companySelect.value;
            }
        }

        if (addressHint) {
            addressHint.textContent = keys.length === 0
                ? 'No address is recorded for this vendor yet.'
                : '';
        }
    }

    if (companySelect) {
        companySelect.addEventListener('change', renderLocations);
        renderLocations();
    }

    // Picking a different address of the same company selects that vendor row.
    if (locationSelect && companySelect) {
        locationSelect.addEventListener('change', function () {
            if (locationSelect.value) {
                companySelect.value = locationSelect.value;
            }
        });
    }

@unless ($isEdit)
    // ---- Submit a new PO without leaving the page, then confirm with the card ----
    const form = document.getElementById('supplierPoForm');
    const submitBtn = document.getElementById('poSubmitBtn');
    const errorBox = document.getElementById('poFormErrors');
    const errorList = document.getElementById('poFormErrorList');
    const overlay = document.getElementById('poSubmittedOverlay');
    const okBtn = document.getElementById('poOkBtn');
    const card = document.getElementById('poCard');

    function showErrors(messages) {
        errorList.innerHTML = '';
        messages.forEach(function (m) {
            const li = document.createElement('li');
            li.textContent = m;
            errorList.appendChild(li);
        });
        errorBox.classList.remove('d-none');
        errorBox.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }

    function showSubmittedCard(data) {
        document.getElementById('poSubmittedRef').textContent = 'PO# ' + (data.po_number || '');

        const countdown = document.getElementById('poCountdown');
        let left = 6;
        let done = false;
        countdown.textContent = left;

        function goToList() {
            if (done) return;
            done = true;
            clearInterval(timer);
            window.location.href = data.redirect;
        }

        const timer = setInterval(function () {
            left -= 1;
            countdown.textContent = Math.max(left, 0);
            if (left <= 0) goToList();
        }, 1000);

        okBtn.onclick = goToList;
        overlay.classList.add('show');
        overlay.setAttribute('aria-hidden', 'false');
        okBtn.focus();
    }

    // The card tilts gently toward the pointer.
    card.addEventListener('mousemove', function (e) {
        const r = card.getBoundingClientRect();
        const x = (e.clientX - r.left) / r.width - 0.5;
        const y = (e.clientY - r.top) / r.height - 0.5;
        card.style.transform = 'rotateY(' + (x * 14) + 'deg) rotateX(' + (-y * 14) + 'deg)';
    });
    card.addEventListener('mouseleave', function () {
        card.style.transform = '';
    });

    form.addEventListener('submit', async function (e) {
        e.preventDefault();
        errorBox.classList.add('d-none');
        submitBtn.disabled = true;

        try {
            const res = await fetch(form.action, {
                method: 'POST',
                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                body: new FormData(form),
                credentials: 'same-origin'
            });

            let data = {};
            try { data = await res.json(); } catch (_) {}

            if (res.ok && data.redirect) {
                showSubmittedCard(data);
                return; // the button stays disabled so it cannot be sent twice
            }

            if (res.status === 422 && data.errors) {
                showErrors(Object.values(data.errors).flat());
            } else if (res.status === 419) {
                showErrors(['Your session has expired. Refresh the page and submit again.']);
            } else {
                showErrors([data.message || 'The purchase order could not be submitted. Please try again.']);
            }
        } catch (err) {
            showErrors(['Could not reach the server. Check your connection and submit again.']);
        }

        submitBtn.disabled = false;
    });
@endunless
});
</script>
@endsection