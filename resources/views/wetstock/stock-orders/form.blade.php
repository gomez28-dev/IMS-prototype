@extends('layouts.app')

@section('title', $mode === 'fuel_trade' ? 'Authority to Load (ATL) Issuance' : 'Process Stock Order & ATL')

@section('content')
<div class="row justify-content-center">
    <div class="col-md-10 col-lg-9">
        <div class="mb-3">
            <a href="{{ route('stock-orders.index') }}" class="text-decoration-none text-secondary small">
                <i class="bi bi-arrow-left me-1"></i> Back to Stock Orders
            </a>
        </div>

        <div class="card card-custom border-0 shadow-sm">
            <div class="card-header bg-white py-3 border-bottom d-flex justify-content-between align-items-center flex-wrap gap-2">
                <div>
                    <h4 class="fw-bold mb-0 text-dark">
                        @if ($mode === 'fuel_trade')
                            <i class="bi bi-patch-check-fill text-warning me-2"></i>Issue Authority to Load (ATL)
                        @else
                            <i class="bi bi-card-checklist text-primary me-2"></i>Assign PO Number & Prepare ATL / DR
                        @endif
                    </h4>
                    <p class="text-muted small mb-0 mt-1">
                        @if ($mode === 'fuel_trade')
                            Draw down volume from Supplier POs and issue ATL for Sales Order <strong>{{ $order->formatted_so_number }}</strong>
                        @else
                            Stock Request for <strong>{{ optional($purchaseOrder->warehouse)->name }}</strong> (Requested: {{ number_format($purchaseOrder->qty_ordered) }} L)
                        @endif
                    </p>
                </div>
                @if ($mode === 'fuel_trade')
                    <span class="badge bg-success-subtle text-success border border-success-subtle rounded-pill px-3 py-2">
                        <i class="bi bi-check-circle-fill me-1"></i>Accounting Cleared
                    </span>
                @endif
            </div>

            <div class="card-body p-4">
                <form method="POST" action="{{ $mode === 'fuel_trade' ? route('stock-orders.store-fuel-trade-po', $order->id) : route('stock-orders.update-request', $purchaseOrder->id) }}" id="atlForm">
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

                    @if ($mode === 'fuel_trade')
                        @php
                            // Volume this Sales Order requires, per product.
                            $soRequirements = $order->productRequirements();
                            $requiredProducts = array_keys($soRequirements);
                            $defaultReqProduct = $requiredProducts[0] ?? 'Diesel';
                            $productOptions = ['Diesel', 'Premium', 'Unleaded'];
                        @endphp
                        <input type="hidden" name="product" value="Diesel">
                        <input type="hidden" id="targetOrderQty" value="{{ $order->qty_ordered }}">
                        <input type="hidden" id="soRequirements" value="{{ json_encode($soRequirements) }}">

                        {{-- Order Summary Banner --}}
                        <div class="card bg-light border-0 rounded-3 p-3 mb-4">
                            <div class="row g-3 align-items-center">
                                <div class="col-md-3">
                                    <span class="text-muted extra-small uppercase fw-semibold d-block mb-1" style="font-size: 0.7rem;">SALES ORDER #</span>
                                    <span class="badge" style="background-color: #fef08a; color: #854d0e; border: 1px solid #facc15; font-size: 0.95rem;">
                                        <i class="bi bi-fuel-pump me-1"></i>{{ $order->formatted_so_number }}
                                    </span>
                                </div>
                                <div class="col-md-3">
                                    <span class="text-muted extra-small uppercase fw-semibold d-block mb-1" style="font-size: 0.7rem;">CLIENT / ACCOUNT</span>
                                    <span class="fw-bold text-dark fs-6">{{ $order->account }}</span>
                                </div>
                                <div class="col-md-3">
                                    <span class="text-muted extra-small uppercase fw-semibold d-block mb-1" style="font-size: 0.7rem;">PRODUCT</span>
                                    @foreach ($soRequirements as $reqProduct => $reqQty)
                                        <span class="badge bg-secondary-subtle text-secondary border px-2 py-1 me-1">{{ $reqProduct }}</span>
                                    @endforeach
                                </div>
                                <div class="col-md-3 text-md-end">
                                    <span class="text-muted extra-small uppercase fw-semibold d-block mb-1" style="font-size: 0.7rem;">REQUIRED VOLUME</span>
                                    <span class="fw-bold text-primary fs-5 font-monospace">{{ number_format($order->qty_ordered) }} L</span>
                                    @if (count($soRequirements) > 1)
                                        <div class="extra-small text-muted" style="font-size: 0.7rem;">
                                            @foreach ($soRequirements as $reqProduct => $reqQty)
                                                <div>{{ $reqProduct }}: {{ number_format($reqQty) }} L</div>
                                            @endforeach
                                        </div>
                                    @endif
                                </div>
                            </div>
                        </div>

                        {{-- Supplier PO Drawdown Engine --}}
                        <div class="card border border-primary border-opacity-25 rounded-3 mb-4 shadow-none bg-light bg-opacity-25">
                            <div class="card-header bg-white py-3 border-bottom d-flex justify-content-between align-items-center">
                                <div>
                                    <h6 class="fw-bold mb-0 text-dark">
                                        <i class="bi bi-stack text-primary me-2"></i>Supplier PO Allocation Pool (Kabangga ng ATL)
                                    </h6>
                                    <small class="text-muted">Select active Supplier Purchase Order(s) to draw volume from.</small>
                                </div>
                                @if (!empty($availablePos) && count($availablePos) > 0)
                                <button type="button" class="btn btn-sm btn-outline-primary rounded-pill px-3" onclick="addDrawdownRow()">
                                    <i class="bi bi-plus-lg me-1"></i> Split Across Another PO
                                </button>
                                @endif
                            </div>
                            <div class="card-body p-3">
                                @if (!empty($availablePos) && count($availablePos) > 0)
                                    <div class="table-responsive">
                                        <table class="table table-borderless align-middle mb-0" id="drawdownsTable">
                                            <thead class="table-light small">
                                                <tr>
                                                    <th class="ps-2" style="width: 42%;">Supplier PO</th>
                                                    <th style="width: 20%;">Product <span class="text-danger">*</span></th>
                                                    <th style="width: 28%;">Volume to Draw (Liters) <span class="text-danger">*</span></th>
                                                    <th class="pe-2 text-center" style="width: 10%;">Remove</th>
                                                </tr>
                                            </thead>
                                            <tbody id="drawdownRowsBody">
                                                <tr class="drawdown-row">
                                                    <td class="ps-2">
                                                        <select name="drawdowns[0][purchase_order_id]" class="form-select form-select-sm po-select" required onchange="handlePoSelectChange(this)">
                                                            <option value="">-- Choose Supplier PO --</option>
                                                            @foreach ($availablePos as $p)
                                                                @php
                                                                    // Availability per product, so the form can validate the chosen product.
                                                                    $availByProduct = [];
                                                                    foreach ($productOptions as $pp) {
                                                                        $bal = $p->getAvailableBalanceForProduct($pp);
                                                                        if ($bal > 0) { $availByProduct[$pp] = $bal; }
                                                                    }
                                                                    $avail = $availByProduct[$defaultReqProduct] ?? 0;
                                                                @endphp
                                                                <option value="{{ $p->id }}" data-items="{{ json_encode($availByProduct) }}" data-avail="{{ $avail }}" {{ $loop->first ? 'selected' : '' }}>
                                                                    {{ $p->po_number }} — {{ $p->supplier_name }}
                                                                </option>
                                                            @endforeach
                                                        </select>
                                                        <div class="extra-small text-muted mt-1" data-avail-hint></div>
                                                    </td>
                                                    <td>
                                                        <select name="drawdowns[0][product]" class="form-select form-select-sm dd-product-select" required onchange="handleProductChange(this)">
                                                            @foreach ($productOptions as $pp)
                                                                <option value="{{ $pp }}" {{ $pp === $defaultReqProduct ? 'selected' : '' }}>{{ $pp }}</option>
                                                            @endforeach
                                                        </select>
                                                    </td>
                                                    <td>
                                                        <div class="input-group input-group-sm">
                                                            @php
                                                                $firstAvail = $availablePos[0]->getAvailableBalanceForProduct($defaultReqProduct);
                                                                $suggested = min($soRequirements[$defaultReqProduct] ?? $order->qty_ordered, $firstAvail);
                                                            @endphp
                                                            <input type="number" name="drawdowns[0][quantity]" class="form-control form-control-sm dd-qty-input font-monospace"
                                                                   placeholder="Liters" min="1" max="{{ $firstAvail }}" value="{{ old('drawdowns.0.quantity', $suggested) }}" required oninput="calculateTotalDrawn()">
                                                            <span class="input-group-text">L</span>
                                                        </div>
                                                    </td>
                                                    <td class="pe-2 text-center">
                                                        <button type="button" class="btn btn-sm btn-link text-danger p-0" onclick="removeDrawdownRow(this)" disabled>
                                                            <i class="bi bi-trash"></i>
                                                        </button>
                                                    </td>
                                                </tr>
                                            </tbody>
                                            <tfoot class="table-light border-top">
                                                <tr>
                                                    <td class="ps-2 fw-semibold text-dark" colspan="3">
                                                        Total Allocated for this ATL:
                                                        <div class="extra-small fw-normal text-muted mt-1" id="productBreakdown"></div>
                                                    </td>
                                                    <td class="pe-2 d-flex align-items-center justify-content-between">
                                                        <span class="fw-bold fs-6 font-monospace" id="totalDrawnDisplay">0 L</span>
                                                        <span id="matchBadge" class="badge rounded-pill px-3 py-1">Checking...</span>
                                                    </td>
                                                </tr>
                                            </tfoot>
                                        </table>
                                    </div>
                                @else
                                    <div class="alert alert-warning border-0 rounded-3 mb-3 small">
                                        <i class="bi bi-info-circle me-1"></i> No bulk Supplier POs currently have active remaining Diesel allocations. You can enter an ad-hoc Supplier PO number below, or <a href="{{ route('stock-orders.create-supplier-po') }}" target="_blank" class="fw-bold text-dark text-decoration-underline">create a Bulk Supplier PO</a> first.
                                    </div>
                                    <div class="row g-3">
                                        <div class="col-md-6">
                                            <label for="po_number" class="form-label fw-semibold small">Supplier PO Number <span class="text-danger">*</span></label>
                                            <input type="text" class="form-control" id="po_number" name="po_number"
                                                   placeholder="e.g. PO-PETRON-2026-0012" value="{{ old('po_number') }}" required>
                                        </div>
                                        <div class="col-md-6">
                                            <label for="supplier_name" class="form-label fw-semibold small">Supplier / Refinery <span class="text-danger">*</span></label>
                                            <input type="text" class="form-control" id="supplier_name" name="supplier_name"
                                                   placeholder="e.g. Petron Bataan Refinery" value="{{ old('supplier_name') }}" required>
                                        </div>
                                        <input type="hidden" name="qty_to_receive" value="{{ $order->qty_ordered }}">
                                    </div>
                                @endif
                            </div>
                        </div>

                        {{-- Fulfillment Channel & Mobile Tanker Linkage --}}
                        <div class="row g-3 mb-3">
                            <div class="col-md-6">
                                <label for="delivery_channel" class="form-label fw-semibold small">Fulfillment Channel <span class="text-danger">*</span></label>
                                <select class="form-select" id="delivery_channel" name="delivery_channel" required onchange="handleDeliveryChannelChange(this.value)">
                                    <option value="SUPPLIER_CLIENT_PICKUP" selected>Supplier - Client Pick-up at Rack (Bypasses Wet Stocks)</option>
                                    <option value="SUPPLIER_DOYEN_PICKUP">Supplier - DITC Tanker Pick-up (Direct Stock-In to Tanker)</option>
                                    <option value="BUY_BACK_CLIENT_PICKUP">Buy Back - Client Pick-up from Us</option>
                                    <option value="BUY_BACK_DOYEN_PICKUP">Buy Back - DITC Pick-up from Client (Direct Stock-In to Tanker)</option>
                                </select>
                            </div>
                            <div class="col-md-6" id="tankerContainer" style="display: none;">
                                <label for="storage_tank_id" class="form-label fw-semibold small">Assigned DITC Mobile Tanker Truck <span class="text-danger">*</span></label>
                                <select class="form-select" id="storage_tank_id" name="storage_tank_id" onchange="handleTankerSelectChange(this)">
                                    <option value="">-- Choose Mobile Tanker Truck --</option>
                                    @if(isset($mobileTankers))
                                        @foreach($mobileTankers as $tank)
                                            <option value="{{ $tank->id }}" data-capacity="{{ $tank->remaining_capacity }}" data-plate="{{ $tank->name }}">
                                                {{ $tank->name }} ({{ $tank->warehouse->name ?? 'Depot' }}) — Remaining: {{ number_format($tank->remaining_capacity) }} L / Max: {{ number_format($tank->max_capacity) }} L
                                            </option>
                                        @endforeach
                                    @endif
                                </select>
                                <div class="form-text text-primary extra-small" style="font-size: 0.75rem;">
                                    <i class="bi bi-info-circle me-1"></i>Fuel will be automatically stocked into this tanker upon confirming lift.
                                </div>
                            </div>
                        </div>

                        {{-- ATL & Logistics Details --}}
                        <div class="row g-3 mb-3">
                            <div class="col-md-6">
                                <label for="atl_type" class="form-label fw-semibold small">ATL Issuing Party <span class="text-danger">*</span></label>
                                <select class="form-select" id="atl_type" name="atl_type" required>
                                    <option value="DITC_ATL" {{ empty($order->client_atl_number) ? 'selected' : '' }}>DITC Issues ATL (Official Template PDF)</option>
                                    <option value="CLIENT_ATL" {{ !empty($order->client_atl_number) ? 'selected' : '' }}>Client Provided ATL (Direct Refinery Entry)</option>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label for="atl_number" class="form-label fw-semibold small">ATL Number</label>
                                <input type="text" class="form-control" id="atl_number" name="atl_number"
                                       placeholder="e.g. ATL-2026-0099" value="{{ old('atl_number') }}">
                            </div>
                        </div>

                        {{-- Supplier Serial Numbers & Documentation Hub --}}
                        <div class="card border rounded-3 p-3 mb-3 bg-white">
                            <h6 class="fw-bold mb-2 text-dark small">
                                <i class="bi bi-file-earmark-check text-success me-1"></i>Refinery Serial Numbers & Documentation Hub
                            </h6>
                            <div class="row g-3 mb-2">
                                <div class="col-md-6">
                                    <label for="supplier_so_number" class="form-label fw-semibold small">Supplier SO / Contract #</label>
                                    <input type="text" class="form-control" id="supplier_so_number" name="supplier_so_number"
                                           placeholder="e.g. PETRON-SO-8842" value="{{ old('supplier_so_number') }}">
                                </div>
                                <div class="col-md-6">
                                    <label for="supplier_dr_number" class="form-label fw-semibold small">Supplier Rack DR # (if already issued)</label>
                                    <input type="text" class="form-control" id="supplier_dr_number" name="supplier_dr_number"
                                           placeholder="e.g. DR-99014" value="{{ old('supplier_dr_number') }}">
                                </div>
                            </div>
                            <div>
                                <label for="scanned_doc_url" class="form-label fw-semibold small">
                                    <i class="bi bi-google text-danger me-1"></i>Google Drive Scanned Document Link
                                </label>
                                <div class="input-group">
                                    <span class="input-group-text bg-light"><i class="bi bi-link-45deg"></i></span>
                                    <input type="url" class="form-control" id="scanned_doc_url" name="scanned_doc_url"
                                           placeholder="https://drive.google.com/file/d/... or folder link" value="{{ old('scanned_doc_url') }}">
                                </div>
                                <div class="form-text text-muted" style="font-size: 0.75rem;">
                                    Paste shareable link to scanned physical DR, loading tickets, and calibration sheets.
                                </div>
                            </div>
                        </div>

                    @else
                        {{-- Standard Depot Replenishment Mode --}}
                        <div class="row g-3 mb-3">
                            <div class="col-md-6">
                                <label for="po_number" class="form-label fw-semibold small">Official PO Number <span class="text-danger">*</span></label>
                                <input type="text" class="form-control @error('po_number') is-invalid @enderror" id="po_number" name="po_number"
                                       placeholder="e.g. PO-2026-0012" value="{{ old('po_number', $purchaseOrder->po_number ?? '') }}" required>
                            </div>
                            <div class="col-md-6">
                                <label for="supplier_name" class="form-label fw-semibold small">Supplier / Refinery Terminal <span class="text-danger">*</span></label>
                                <input type="text" class="form-control @error('supplier_name') is-invalid @enderror" id="supplier_name" name="supplier_name"
                                       placeholder="e.g. Petron Bataan Refinery" value="{{ old('supplier_name', $purchaseOrder->supplier_name ?? '') }}" required>
                            </div>
                        </div>

                        <div class="row g-3 mb-3">
                            <div class="col-md-6">
                                <label for="delivery_channel" class="form-label fw-semibold small">Fulfillment Channel <span class="text-danger">*</span></label>
                                <select class="form-select" id="delivery_channel" name="delivery_channel" required>
                                    <option value="SUPPLIER_DOYEN_PICKUP" selected>Supplier - DITC Tanker Pick-up (Issue DITC ATL)</option>
                                    <option value="SUPPLIER_STOCKS_DELIVERY">Supplier - Direct Stocks Delivery to Depot (DR)</option>
                                    <option value="BUY_BACK_DOYEN_PICKUP">Buy Back - DITC Pick-up from Client (Issue DITC ATL)</option>
                                    <option value="BUY_BACK_CLIENT_PICKUP">Buy Back - Client Pick-up (Client / DITC ATL)</option>
                                    <option value="BUY_BACK_STOCKS_DELIVERY">Buy Back - Direct Delivery to Depot (DR)</option>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label for="order_type" class="form-label fw-semibold small">Order Type <span class="text-danger">*</span></label>
                                <select class="form-select" id="order_type" name="order_type" required>
                                    <option value="PICK_UP" selected>PICK UP (Requires ATL)</option>
                                    <option value="DELIVERY">DELIVERY (Supplier DR)</option>
                                </select>
                            </div>
                        </div>

                        <div class="row g-3 mb-3">
                            <div class="col-md-6">
                                <label for="product" class="form-label fw-semibold small">Product <span class="text-danger">*</span></label>
                                <select class="form-select" id="product" name="product" required>
                                    <option value="Diesel" selected>Diesel</option>
                                    <option value="Unleaded">Unleaded</option>
                                    <option value="Premium">Premium</option>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label for="qty_to_receive" class="form-label fw-semibold small">Quantity (Liters) <span class="text-danger">*</span></label>
                                <input type="number" class="form-control" id="qty_to_receive" name="qty_to_receive"
                                       value="{{ old('qty_to_receive', $purchaseOrder->qty_ordered ?? 10000) }}" required min="1">
                            </div>
                        </div>
                    @endif

                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label for="receiving_date" class="form-label fw-semibold small">Authorized Pick Up Date <span class="text-danger">*</span></label>
                            <input type="date" class="form-control" id="receiving_date" name="receiving_date"
                                   value="{{ old('receiving_date', date('Y-m-d')) }}" required>
                        </div>
                        <div class="col-md-6">
                            <label for="location" class="form-label fw-semibold small">Refinery Rack / Terminal Location</label>
                            <input type="text" class="form-control" id="location" name="location"
                                   placeholder="e.g. Petron Limay Terminal, Bataan" value="{{ old('location') }}">
                        </div>
                    </div>

                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label for="driver_name" class="form-label fw-semibold small">Authorized Driver Name</label>
                            <input type="text" class="form-control" id="driver_name" name="driver_name"
                                   placeholder="e.g. Juan Dela Cruz" value="{{ old('driver_name') }}">
                        </div>
                        <div class="col-md-6">
                            <label for="plate_number" class="form-label fw-semibold small">Hauler Truck Plate Number</label>
                            <input type="text" class="form-control" id="plate_number" name="plate_number"
                                   placeholder="e.g. NBD-8899" value="{{ old('plate_number') }}">
                        </div>
                    </div>

                    <div class="mb-4">
                        <label for="additional_remarks" class="form-label fw-semibold small">Special Instructions / Remarks</label>
                        <textarea class="form-control" id="additional_remarks" name="additional_remarks" rows="2"
                                  placeholder="Dip requirements, refinery safety passes, calibration notes... ">{{ old('additional_remarks') }}</textarea>
                    </div>

                    <div class="d-flex justify-content-end gap-2">
                        <a href="{{ route('stock-orders.index') }}" class="btn btn-light border rounded-pill px-4">Cancel</a>
                        <button type="submit" class="btn btn-primary-custom rounded-pill px-4 shadow-sm" id="submitBtn">
                            <i class="bi bi-patch-check me-1"></i> Issue Authority to Load (ATL)
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
let ddIndex = 1;
const productOptionsHtml = (function () {
    const sel = document.querySelector('.dd-product-select');
    return sel ? sel.innerHTML : '<option value="Diesel">Diesel</option>';
})();

function poAvailability(poSelect) {
    const opt = poSelect.options[poSelect.selectedIndex];
    if (!opt) return {};
    try {
        return JSON.parse(opt.getAttribute('data-items') || '{}');
    } catch (e) {
        return {};
    }
}

function handleProductChange(select) {
    const row = select.closest('.drawdown-row');
    const product = select.value;
    const avail = parseInt(poAvailability(row.querySelector('.po-select'))[product] || 0);

    const qtyInput = row.querySelector('.dd-qty-input');
    if (qtyInput) {
        qtyInput.max = avail;
        if (parseInt(qtyInput.value || 0) > avail) {
            qtyInput.value = avail || '';
        }
    }

    calculateTotalDrawn();
}

function updateAvailHint(row) {
    const product = row.querySelector('.dd-product-select').value;
    const avail = parseInt(poAvailability(row.querySelector('.po-select'))[product] || 0);
    const hint = row.querySelector('[data-avail-hint]');
    if (!hint) return avail;
    hint.textContent = avail > 0
        ? 'Available ' + product + ': ' + avail.toLocaleString() + ' L'
        : 'This PO has no remaining ' + product + ' allocation';
    hint.className = 'extra-small mt-1 ' + (avail > 0 ? 'text-muted' : 'text-danger');
    return avail;
}

function addDrawdownRow() {
    const tbody = document.getElementById('drawdownRowsBody');
    if (!tbody) return;

    const firstSelect = tbody.querySelector('.po-select');
    if (!firstSelect) return;

    const optionsHtml = firstSelect.innerHTML;
    const tr = document.createElement('tr');
    tr.className = 'drawdown-row';
    tr.innerHTML = `
        <td class="ps-2">
            <select name="drawdowns[${ddIndex}][purchase_order_id]" class="form-select form-select-sm po-select" required onchange="handlePoSelectChange(this)">
                ${optionsHtml}
            </select>
            <div class="extra-small text-muted mt-1" data-avail-hint></div>
        </td>
        <td>
            <select name="drawdowns[${ddIndex}][product]" class="form-select form-select-sm dd-product-select" required onchange="handleProductChange(this)">
                ${productOptionsHtml}
            </select>
        </td>
        <td>
            <div class="input-group input-group-sm">
                <input type="number" name="drawdowns[${ddIndex}][quantity]" class="form-control form-control-sm dd-qty-input font-monospace"
                       placeholder="Liters" min="1" required oninput="calculateTotalDrawn()">
                <span class="input-group-text">L</span>
            </div>
        </td>
        <td class="pe-2 text-center">
            <button type="button" class="btn btn-sm btn-link text-danger p-0" onclick="removeDrawdownRow(this)">
                <i class="bi bi-trash"></i>
            </button>
        </td>
    `;
    tbody.appendChild(tr);
    ddIndex++;
    updateDrawdownRemoveButtons();
    calculateTotalDrawn();
}

function removeDrawdownRow(btn) {
    const row = btn.closest('tr');
    row.remove();
    updateDrawdownRemoveButtons();
    calculateTotalDrawn();
}

function updateDrawdownRemoveButtons() {
    const rows = document.querySelectorAll('.drawdown-row');
    rows.forEach(r => {
        const removeBtn = r.querySelector('button');
        if (removeBtn) {
            removeBtn.disabled = rows.length === 1;
        }
    });
}

function handlePoSelectChange(select) {
    handleProductChange(select.closest('.drawdown-row').querySelector('.dd-product-select'));
}

function calculateTotalDrawn() {
    let requirements = {};
    const reqEl = document.getElementById('soRequirements');
    if (reqEl) {
        try { requirements = JSON.parse(reqEl.value || '{}'); } catch (e) { requirements = {}; }
    }

    const drawnByProduct = {};
    let total = 0;
    let overAllocated = false;

    document.querySelectorAll('.drawdown-row').forEach(row => {
        const product = row.querySelector('.dd-product-select').value;
        const qty = parseInt(row.querySelector('.dd-qty-input').value) || 0;

        drawnByProduct[product] = (drawnByProduct[product] || 0) + qty;
        total += qty;

        if (qty > updateAvailHint(row)) {
            overAllocated = true;
        }
    });

    const display = document.getElementById('totalDrawnDisplay');
    if (display) {
        display.textContent = total.toLocaleString() + ' L';
    }

    // Per-product breakdown so the user can see each requirement being met.
    const breakdown = document.getElementById('productBreakdown');
    if (breakdown) {
        let html = '';
        Object.keys(drawnByProduct).forEach(product => {
            const drawn = drawnByProduct[product];
            const required = requirements[product];
            let note;
            if (required === undefined) {
                note = '<span class="text-danger">not required by this SO</span>';
            } else if (drawn === required) {
                note = '<span class="text-success">&#10003; matched</span>';
            } else if (drawn > required) {
                note = '<span class="text-danger">over by ' + (drawn - required).toLocaleString() + ' L</span>';
            } else {
                note = '<span class="text-warning-emphasis">short by ' + (required - drawn).toLocaleString() + ' L</span>';
            }
            html += '<div>' + product + ': <strong>' + drawn.toLocaleString() + ' L</strong> of '
                + (required === undefined ? '0 L' : required.toLocaleString() + ' L') + ' &mdash; ' + note + '</div>';
        });
        breakdown.innerHTML = html;
    }

    const badge = document.getElementById('matchBadge');
    if (badge) {
        const products = Object.keys(requirements);
        const allMatched = products.length > 0 && products.every(
            product => (drawnByProduct[product] || 0) === requirements[product]
        );

        if (overAllocated) {
            badge.className = 'badge bg-danger-subtle text-danger border border-danger-subtle rounded-pill px-3 py-1';
            badge.innerHTML = '<i class="bi bi-exclamation-triangle-fill me-1"></i> Exceeds PO availability';
        } else if (allMatched) {
            badge.className = 'badge bg-success-subtle text-success border border-success-subtle rounded-pill px-3 py-1';
            badge.innerHTML = '<i class="bi bi-check-circle-fill me-1"></i> 100% Matched';
        } else {
            badge.className = 'badge bg-warning-subtle text-warning-emphasis border border-warning-subtle rounded-pill px-3 py-1';
            badge.innerHTML = '<i class="bi bi-clock-history me-1"></i> Check per product';
        }
    }
}

function handleDeliveryChannelChange(channel) {
    const container = document.getElementById('tankerContainer');
    const isTanker = channel === 'SUPPLIER_DOYEN_PICKUP' || channel === 'BUY_BACK_DOYEN_PICKUP';
    if (container) {
        container.style.display = isTanker ? 'block' : 'none';
        const tankerSelect = document.getElementById('storage_tank_id');
        if (tankerSelect) {
            tankerSelect.required = isTanker;
        }
    }
}

function handleTankerSelectChange(select) {
    const selectedOption = select.options[select.selectedIndex];
    if (selectedOption && selectedOption.dataset.plate) {
        const plateInput = document.getElementById('plate_number');
        if (plateInput && !plateInput.value) {
            plateInput.value = selectedOption.dataset.plate;
        }
    }
}

document.addEventListener('DOMContentLoaded', function() {
    calculateTotalDrawn();
    const channelSelect = document.getElementById('delivery_channel');
    if (channelSelect) {
        handleDeliveryChannelChange(channelSelect.value);
    }
});
</script>
@endsection
