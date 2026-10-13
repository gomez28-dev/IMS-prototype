@extends('layouts.app')

@section('title', 'Prepare Stock Request')

@section('content')
<div class="container-fluid py-2">
    <div class="row justify-content-center">
        <div class="col-12 col-xl-9">

            {{-- Back to Stock Orders --}}
            <a href="{{ route('stock-orders.wet-stock-requests.index') }}" class="btn btn-link text-decoration-none px-0 mb-2">
                <i class="bi bi-arrow-left me-1"></i> Back to Wet Stock Requests
            </a>

            <div class="card card-custom border-0 shadow-sm">
                <div class="card-body p-4">

                    <div class="d-flex align-items-start gap-3 mb-1">
                        <i class="bi bi-card-checklist text-primary fs-3 me-2"></i>
                        <div>
                            <h3 class="fw-bold text-dark mb-1">Prepare Stock Request</h3>
                            <p class="text-muted small mb-0">
                                Choose a stock request to raise a purchase order against. The PO number is taken
                                from the request, so it can never clash. The delivery is allocated from this PO
                                afterwards.
                            </p>
                        </div>
                    </div>

                    @if (session('success'))
                        <div class="alert alert-success alert-dismissible fade show rounded-3 mt-3" role="alert">
                            <i class="bi bi-check-circle-fill me-2"></i> {{ session('success') }}
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                    @endif

                    @if (session('danger'))
                        <div class="alert alert-danger alert-dismissible fade show rounded-3 mt-3" role="alert">
                            <i class="bi bi-exclamation-triangle-fill me-2"></i> {{ session('danger') }}
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                    @endif

                    @if ($unnumberedRequests->isEmpty())
                        <div class="alert alert-info border-0 rounded-3 mt-4">
                            <i class="bi bi-info-circle me-2"></i>
                            Every stock request already has a purchase order against it. There is nothing to prepare.
                        </div>
                    @else
                        <form method="POST" action="{{ route('stock-orders.store-stock-request-po') }}" class="mt-4">
                            @csrf

                            <div class="row g-3">
                                {{-- The PO number is a dropdown, not free text: it identifies
                                     the request being fulfilled, and cannot be mistyped. --}}
                                <div class="col-md-6">
                                    <label for="stockRequestSelect" class="form-label fw-semibold small">
                                        Official PO Number <span class="text-danger">*</span>
                                    </label>
                                    <select name="stock_request_id" id="stockRequestSelect"
                                            class="form-select @error('stock_request_id') is-invalid @enderror"
                                            required onchange="syncRequestPreview(this)">
                                        <option value="">-- Choose the Stock Request this PO covers --</option>
                                        @foreach ($unnumberedRequests as $option)
                                            <option value="{{ $option->id }}"
                                                data-number="{{ $option->request_number }}"
                                                data-site="{{ $option->warehouse->name ?? '' }}"
                                                data-lines="{{ $option->lines->map(fn ($l) => $l->product.' — '.number_format($l->qty_requested).' L')->join(' ;; ') }}"
                                                data-date="{{ $option->date_needed ? $option->date_needed->format('Y-m-d') : '' }}"
                                                data-remarks="{{ $option->remarks }}"
                                                {{ (int) old('stock_request_id', $selected?->id) === $option->id ? 'selected' : '' }}>
                                            {{ $option->request_number }}
                                            @if ($option->warehouse)
                                                — {{ $option->warehouse->name }}
                                            @endif
                                        </option>
                                        @endforeach
                                    </select>
                                    @error('stock_request_id')
                                        <div class="invalid-feedback">{{ $message }}</div>
                                    @enderror
                                </div>

                                <div class="col-md-6">
                                    <label for="supplierName" class="form-label fw-semibold small">
                                        Supplier / Refinery Terminal <span class="text-danger">*</span>
                                    </label>
                                    <input type="text" name="supplier_name" id="supplierName"
                                           class="form-control @error('supplier_name') is-invalid @enderror"
                                           placeholder="e.g. Petron Bataan Refinery"
                                           value="{{ old('supplier_name') }}" required>
                                    @error('supplier_name')
                                        <div class="invalid-feedback">{{ $message }}</div>
                                    @enderror
                                </div>

                                <div class="col-md-4">
                                    <label for="attention" class="form-label fw-semibold small">Attention</label>
                                    <input type="text" name="attention" id="attention" class="form-control"
                                           placeholder="e.g. Loading Bay 2" value="{{ old('attention') }}">
                                </div>

                                <div class="col-md-4">
                                    <label for="poDate" class="form-label fw-semibold small">PO Date</label>
                                    <input type="date" name="po_date" id="poDate" class="form-control"
                                           value="{{ old('po_date', now()->toDateString()) }}">
                                </div>

                                <div class="col-md-4">
                                    <label for="terms" class="form-label fw-semibold small">Terms</label>
                                    <input type="text" name="terms" id="terms" class="form-control"
                                           placeholder="e.g. 30-day credit" value="{{ old('terms') }}">
                                </div>

                                <div class="col-12">
                                    <div class="p-3 bg-light rounded-3" id="requestPreview">
                                        <div class="text-muted extra-small fw-semibold text-uppercase mb-1">
                                            What this PO will cover
                                        </div>
                                        <div class="fw-semibold text-muted" id="requestPreviewText">
                                            Choose a stock request to see its products and quantities.
                                        </div>
                                    </div>
                                </div>

                                <div class="col-12">
                                    <label for="remarks" class="form-label fw-semibold small">
                                        Remarks / Special Instructions
                                    </label>
                                    <textarea name="remarks" id="remarks" rows="3"
                                              class="form-control @error('remarks') is-invalid @enderror"
                                              placeholder="Dip requirements, delivery window, contact person...">{{ old('remarks') }}</textarea>
                                    @error('remarks')
                                        <div class="invalid-feedback">{{ $message }}</div>
                                    @enderror
                                </div>
                            </div>

                            <div class="d-flex justify-content-end gap-2 mt-4">
                                <a href="{{ route('stock-orders.wet-stock-requests.index') }}"
                                   class="btn btn-outline-secondary rounded-pill px-4">Cancel</a>
                                <button type="submit" class="btn btn-primary-custom rounded-pill px-4">
                                    <i class="bi bi-check-circle me-1"></i> Create Purchase Order
                                </button>
                            </div>
                        </form>
                    @endif

                </div>
            </div>
        </div>
    </div>
</div>

@if (! $unnumberedRequests->isEmpty())
<script>
document.addEventListener('DOMContentLoaded', function () {
    const select = document.getElementById('stockRequestSelect');
    const preview = document.getElementById('requestPreviewText');
    const remarks = document.getElementById('remarks');

    function syncRequestPreview(target) {
        const option = target.options[target.selectedIndex];
        if (!option || !option.value) {
            preview.textContent = 'Choose a stock request to see its products and quantities.';
            preview.className = 'fw-semibold text-muted';
            return;
        }

        const lines = (option.dataset.lines || '').split(';;').filter(Boolean);
        const site = option.dataset.site ? ' — ' + option.dataset.site : '';
        const date = option.dataset.date ? ' · needed ' + option.dataset.date : '';

        preview.innerHTML = '<div class="mb-1">' + option.dataset.number + site + date + '</div>'
            + lines.map(function (line) {
                return '<div class="small">' + line + '</div>';
            }).join('');
        preview.className = 'fw-semibold text-dark';

        // Carry the request's own remarks across unless one was already typed.
        if (remarks && remarks.value.trim() === '' && option.dataset.remarks) {
            remarks.value = option.dataset.remarks;
        }
    }

    select.addEventListener('change', function () { syncRequestPreview(select); });
    syncRequestPreview(select);
});
</script>
@endif
@endsection