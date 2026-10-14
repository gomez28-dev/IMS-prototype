@extends('layouts.app')

@section('title', 'Raise an ATL for '.$request->request_number)

@section('content')
<div class="container-fluid py-2">

    <a href="{{ route('stock-orders.wet-stock-requests.atls', $request->id) }}" class="btn btn-link text-decoration-none px-0 mb-3">
        <i class="bi bi-arrow-left me-1"></i> Back to ATLs
    </a>

    <h4 class="fw-bold text-dark mb-1">Raise an ATL</h4>
    <p class="text-muted small mb-4">
        {{ $request->request_number }} &middot; {{ $request->warehouse->name ?? '—' }}.
        Draw on whichever purchase order still has the volume outstanding.
    </p>

    @if ($errors->any())
        <div class="alert alert-danger alert-dismissible fade show rounded-3" role="alert">
            <i class="bi bi-exclamation-triangle-fill me-2"></i>
            <strong class="d-block mb-1">That ATL was not saved:</strong>
            <ul class="mb-0 ps-3">
                @foreach ($errors->all() as $message)
                    <li>{{ $message }}</li>
                @endforeach
            </ul>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    @endif

    @if ($availablePos->isEmpty())
        <div class="alert alert-warning rounded-3">
            <i class="bi bi-exclamation-triangle-fill me-2"></i>
            No purchase order currently has volume left to draw on. A PO with
            remaining stock has to be created or approved first.
        </div>
    @else
        <form method="POST" action="{{ route('stock-orders.wet-stock-requests.atls.store', $request->id) }}">
            @csrf

            <div class="card card-custom border-0 shadow-sm mb-4">
                <div class="card-body p-4">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label for="purchase_order_id" class="form-label fw-semibold small">
                                Purchase Order <span class="text-danger">*</span>
                            </label>
                            <select class="form-select @error('purchase_order_id') is-invalid @enderror"
                                    id="purchase_order_id" name="purchase_order_id" required
                                    onchange="refreshAvailability()">
                                <option value="">-- Choose a Purchase Order --</option>
                                @foreach ($availablePos as $po)
                                    <option value="{{ $po->id }}"
                                            data-products="{{ json_encode($po->items->pluck('product', 'product')->map(fn ($p) => [$p, $po->getAvailableBalanceForProduct($p)])->values()) }}"
                                            {{ (int) old('purchase_order_id') === $po->id ? 'selected' : '' }}>
                                        {{ $po->po_number }} — {{ $po->supplier_name }}
                                    </option>
                                @endforeach
                            </select>
                            <div class="form-text extra-small">
                                Only purchase orders with volume left are offered.
                            </div>
                            @error('purchase_order_id')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>

                        <div class="col-md-6">
                            <label for="product" class="form-label fw-semibold small">
                                Product <span class="text-danger">*</span>
                            </label>
                            <select class="form-select @error('product') is-invalid @enderror"
                                    id="product" name="product" required onchange="refreshAvailability()">
                                <option value="">-- Choose a Product --</option>
                                @foreach (['Diesel', 'Premium', 'Unleaded'] as $product)
                                    {{-- Only products this request actually asked for. --}}
                                    @if (array_key_exists($product, $pending))
                                        <option value="{{ $product }}" {{ old('product') === $product ? 'selected' : '' }}>
                                            {{ $product }}
                                        </option>
                                    @endif
                                @endforeach
                            </select>
                            <div class="form-text extra-small" id="availabilityHint"></div>
                            @error('product')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>

                        <div class="col-md-6">
                            <label for="qty_to_receive" class="form-label fw-semibold small">
                                Volume (Liters) <span class="text-danger">*</span>
                            </label>
                            <input type="number" min="1" step="1" required
                                   class="form-control @error('qty_to_receive') is-invalid @enderror"
                                   id="qty_to_receive" name="qty_to_receive"
                                   value="{{ old('qty_to_receive') }}">
                            <div class="form-text extra-small" id="outstandingHint">
                                @foreach ($pending as $product => $qty)
                                    Outstanding on {{ $product }}: {{ number_format($qty) }} L
                                @endforeach
                            </div>
                            @error('qty_to_receive')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>

                        <div class="col-md-6">
                            <label for="receiving_date" class="form-label fw-semibold small">
                                Receiving Date <span class="text-danger">*</span>
                            </label>
                            <input type="date" required
                                   class="form-control @error('receiving_date') is-invalid @enderror"
                                   id="receiving_date" name="receiving_date"
                                   value="{{ old('receiving_date', now()->addDays(3)->format('Y-m-d')) }}">
                            @error('receiving_date')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>

                        <div class="col-md-6">
                            <label for="delivery_channel" class="form-label fw-semibold small">
                                Channel <span class="text-danger">*</span>
                            </label>
                            <select class="form-select" id="delivery_channel" name="delivery_channel" required>
                                <option value="SUPPLIER_STOCKS_DELIVERY">Supplier Stocks Delivery</option>
                                <option value="BUY_BACK_STOCKS_DELIVERY">Buy Back — Stocks Delivery</option>
                            </select>
                        </div>

                        <div class="col-md-6">
                            <label for="order_type" class="form-label fw-semibold small">
                                Order Type <span class="text-danger">*</span>
                            </label>
                            <select class="form-select" id="order_type" name="order_type" required>
                                <option value="DELIVERY">Delivery</option>
                                <option value="PICK_UP">Pick-up</option>
                            </select>
                        </div>

                        <div class="col-md-6">
                            <label for="atl_type" class="form-label fw-semibold small">
                                ATL Type <span class="text-danger">*</span>
                            </label>
                            <select class="form-select" id="atl_type" name="atl_type" required>
                                <option value="DITC_ATL">DITC ATL</option>
                                <option value="CLIENT_ATL">Client ATL</option>
                                <option value="NONE">None</option>
                            </select>
                        </div>

                        <div class="col-md-6">
                            <label for="storage_tank_id" class="form-label fw-semibold small">
                                Depot Tank (Optional)
                            </label>
                            <select class="form-select" id="storage_tank_id" name="storage_tank_id">
                                <option value="">-- Decide on receipt --</option>
                                @foreach ($tanks as $tank)
                                    <option value="{{ $tank->id }}" {{ (int) old('storage_tank_id') === $tank->id ? 'selected' : '' }}>
                                        {{ $tank->name }} ({{ $tank->warehouse->name ?? 'Depot' }})
                                    </option>
                                @endforeach
                            </select>
                        </div>

                        <div class="col-md-6">
                            <label for="atl_number" class="form-label fw-semibold small">ATL Number</label>
                            <input type="text" class="form-control" id="atl_number" name="atl_number" value="{{ old('atl_number') }}">
                        </div>

                        <div class="col-md-6">
                            <label for="dr_number" class="form-label fw-semibold small">DR Number</label>
                            <input type="text" class="form-control" id="dr_number" name="dr_number" value="{{ old('dr_number') }}">
                        </div>

                        <div class="col-md-6">
                            <label for="driver_name" class="form-label fw-semibold small">Driver</label>
                            <input type="text" class="form-control" id="driver_name" name="driver_name" value="{{ old('driver_name') }}">
                        </div>

                        <div class="col-md-6">
                            <label for="plate_number" class="form-label fw-semibold small">Plate Number</label>
                            <input type="text" class="form-control" id="plate_number" name="plate_number" value="{{ old('plate_number') }}">
                        </div>

                        <div class="col-12">
                            <label for="additional_remarks" class="form-label fw-semibold small">Remarks</label>
                            <textarea class="form-control" id="additional_remarks" name="additional_remarks" rows="2">{{ old('additional_remarks') }}</textarea>
                        </div>

                        <div class="col-12">
                            <div class="form-check">
                                <input class="form-check-input @error('submit_for_approval') is-invalid @enderror"
                                       type="checkbox" value="1" id="submit_for_approval"
                                       name="submit_for_approval" checked>
                                <label class="form-check-label small" for="submit_for_approval">
                                    Submit to the VP for approval
                                </label>
                                @error('submit_for_approval')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>
                        </div>
                    </div>
                </div>
                <div class="card-footer bg-white border-0 py-3 d-flex justify-content-end gap-2">
                    <a href="{{ route('stock-orders.wet-stock-requests.atls', $request->id) }}"
                       class="btn btn-outline-secondary rounded-pill px-4">Cancel</a>
                    <button type="submit" class="btn btn-primary-custom rounded-pill px-4">
                        <i class="bi bi-send me-1"></i> Save ATL
                    </button>
                </div>
            </div>
        </form>
    @endif
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const poSelect = document.getElementById('purchase_order_id');
    const productSelect = document.getElementById('product');
    const hint = document.getElementById('availabilityHint');

    // States what is available under the chosen PO, for the chosen product.
    // The server checks the same thing on save.
    function refreshAvailability() {
        if (!poSelect || !productSelect || !hint) return;

        const option = poSelect.options[poSelect.selectedIndex];
        const products = option && option.dataset.products ? JSON.parse(option.dataset.products) : [];
        const product = productSelect.value;

        const match = products.find(function (row) { return row[0] === product; });

        hint.textContent = match
            ? match[1].toLocaleString() + ' L of ' + product + ' available on this PO'
            : '';
    }

    if (poSelect) poSelect.addEventListener('change', refreshAvailability);
    if (productSelect) productSelect.addEventListener('change', refreshAvailability);
    refreshAvailability();
});
</script>
@endsection
