@extends('layouts.app')

@section('title', $title)

@section('content')
<div class="row justify-content-center">
    <div class="col-md-8 col-lg-6">
        <div class="mb-3">
            <a href="{{ route('order.deliveries', $order->id) }}" class="text-decoration-none text-secondary small">
                <i class="bi bi-arrow-left me-1"></i> Back to Order Deliveries
            </a>
        </div>
        <div class="card card-custom p-4 border-0 shadow-sm">
            <div class="card-body">
                <div class="mb-3 d-flex flex-wrap align-items-center gap-2">
                    @if ($order && $order->status === 'Cancelled')
                        <span class="badge bg-danger-subtle text-danger border border-danger-subtle rounded-pill"><i class="bi bi-x-circle me-1"></i>CANCELLED ORDER</span>
                    @endif
                    <span class="badge {{ $order && $order->status === 'Cancelled' ? 'bg-danger text-white' : 'bg-light text-dark border' }}">SO# {{ $order->so_number }}</span>
                    <span class="text-muted small ms-2">{{ $order->account }}</span>
                    @php
                        $available = $order->effective_qty_ordered - $order->committed_qty_out;
                        if ($delivery && $delivery->status !== 'CANCELLED') {
                            $available += $delivery->qty_out;
                        }
                        $available = max($available, 0);
                    @endphp
                    <span class="badge bg-success-subtle text-success border ms-2" id="available-badge">Available: {{ number_format($available) }} L</span>
                </div>
                <h4 class="fw-bold mb-4 text-dark">
                    <i class="bi bi-truck text-primary me-2"></i>{{ $title }}
                </h4>
                
                <form method="POST" action="{{ $delivery ? route('delivery.update', $delivery->id) : route('delivery.store', $order->id) }}" novalidate>
                    @csrf
                    
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label for="dr_number" class="form-label fw-medium text-secondary small">DR Number</label>
                            <input type="text" name="dr_number" id="dr_number" class="form-control @error('dr_number') is-invalid @enderror" placeholder="e.g. DR-9876" value="{{ old('dr_number', $delivery ? $delivery->dr_number : '') }}" required>
                            @error('dr_number')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>
                        <div class="col-md-6">
                            <label for="delivery_date" class="form-label fw-medium text-secondary small">Delivery Date</label>
                            <input type="date" name="delivery_date" id="delivery_date" class="form-control @error('delivery_date') is-invalid @enderror" value="{{ old('delivery_date', $delivery && $delivery->delivery_date ? $delivery->delivery_date->format('Y-m-d') : '') }}" required>
                            @error('delivery_date')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>
                    </div>

                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label for="qty_out" class="form-label fw-medium text-secondary small">Qty Out (Liters)</label>
                            <input type="number" name="qty_out" id="qty_out" class="form-control font-monospace @error('qty_out') is-invalid @enderror" placeholder="e.g. 50" value="{{ old('qty_out', $delivery ? $delivery->qty_out : '') }}" min="0" max="{{ $available }}" required>
                            @error('qty_out')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>
                        <div class="col-md-6">
                            <label for="status" class="form-label fw-medium text-secondary small">Status</label>
                            @if ($delivery && $delivery->status === 'FULFILLED' && !Auth::user()->canMarkFulfilled())
                                <div class="p-2 border rounded bg-light">
                                    <span class="badge bg-success-subtle text-success border border-success-subtle rounded-pill px-2 py-1">FULFILLED</span>
                                    <input type="hidden" name="status" value="FULFILLED">
                                    <small class="text-muted d-block mt-1">Managed by Operations</small>
                                </div>
                            @else
                                <select name="status" id="status" class="form-control form-select @error('status') is-invalid @enderror" required>
                                    <option value="PENDING" {{ old('status', $delivery ? $delivery->status : '') === 'PENDING' ? 'selected' : '' }}>PENDING</option>
                                    @if (Auth::user()->canMarkFulfilled())
                                        <option value="FULFILLED" {{ old('status', $delivery ? $delivery->status : '') === 'FULFILLED' ? 'selected' : '' }}>FULFILLED</option>
                                    @endif
                                    <option value="CANCELLED" {{ old('status', $delivery ? $delivery->status : '') === 'CANCELLED' ? 'selected' : '' }}>CANCELLED</option>
                                </select>
                                @error('status')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                                @if (!Auth::user()->canMarkFulfilled())
                                    <small class="text-muted d-block mt-1" style="font-size: 0.75rem;">
                                        <i class="bi bi-info-circle me-1"></i>Delivery fulfillment is executed by Operations via Wet Stock.
                                    </small>
                                @endif
                            @endif
                        </div>
                    </div>

                    <div class="alert alert-warning d-none" id="cancel-warning" role="alert">
                        <i class="bi bi-exclamation-triangle-fill me-2"></i>
                        <strong>Warning:</strong> Cancelling this DR will reduce the SO's remaining ordered quantity and may close the order. Reassigning it to PENDING later will restore the original quantity.
                    </div>

                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label for="type" class="form-label fw-medium text-secondary small">Delivery Type</label>
                            <select name="type" id="type" class="form-control form-select @error('type') is-invalid @enderror" required>
                                <option value="BIG TANKER" {{ old('type', $delivery ? $delivery->type : 'BIG TANKER') === 'BIG TANKER' || (isset($delivery) && $delivery->type === 'DELIVERY') ? 'selected' : '' }}>BIG TANKER</option>
                                <option value="SMALL TANKER" {{ old('type', $delivery ? $delivery->type : '') === 'SMALL TANKER' ? 'selected' : '' }}>SMALL TANKER</option>
                                <option value="PICK-UP" {{ old('type', $delivery ? $delivery->type : '') === 'PICK-UP' ? 'selected' : '' }}>PICK-UP</option>
                            </select>
                            @error('type')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-medium text-secondary small d-block">Parent Order Status</label>
                            @if ($order && $order->status === 'Cancelled')
                                <span class="badge bg-danger-subtle text-danger border fw-semibold">
                                    <i class="bi bi-x-circle me-1"></i>{{ $order->status }}
                                </span>
                            @else
                                <span class="badge bg-success-subtle text-success border fw-semibold">
                                    <i class="bi bi-check-circle me-1"></i>{{ $order ? $order->status : 'Active' }}
                                </span>
                            @endif
                        </div>
                    </div>

                    {{-- NEW: ATL Number ΓÇö only relevant for PICK-UP deliveries. Shown/hidden by JS
                         below based on the Delivery Type selection, and required server-side only
                         when type is PICK-UP (see DeliveryController validation). --}}
                    <div class="row mb-3 d-none" id="atl-number-row">
                        <div class="col-md-6">
                            <label for="atl_number" class="form-label fw-medium text-secondary small">ATL Number</label>
                            <input type="text" name="atl_number" id="atl_number" class="form-control @error('atl_number') is-invalid @enderror" placeholder="e.g. ATL-1234" value="{{ old('atl_number', $delivery ? $delivery->atl_number : '') }}">
                            @error('atl_number')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                            <small class="text-muted d-block mt-1" style="font-size: 0.75rem;">
                                <i class="bi bi-info-circle me-1"></i>Required for Pick-Up deliveries. Must be unique across all deliveries.
                            </small>
                        </div>
                    </div>

                    <div class="mb-4">
                        <label for="remarks" class="form-label fw-medium text-secondary small">Additional Notes</label>
                        <textarea name="remarks" id="remarks" class="form-control @error('remarks') is-invalid @enderror" rows="3" placeholder="Optional additional notes...">{{ old('remarks', $delivery ? $delivery->remarks : '') }}</textarea>
                        @error('remarks')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    <div class="d-flex justify-content-end gap-2">
                        <a href="{{ route('order.deliveries', $order->id) }}" class="btn btn-light border">Cancel</a>
                        <button type="submit" class="btn btn-primary-custom">Save Delivery</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
    (function() {
        var statusSelect = document.getElementById('status');
        var warning = document.getElementById('cancel-warning');
        var form = statusSelect ? statusSelect.closest('form') : null;

        function updateWarning() {
            if (statusSelect && warning) {
                warning.classList.toggle('d-none', statusSelect.value !== 'CANCELLED');
            }
        }

        if (statusSelect) {
            statusSelect.addEventListener('change', updateWarning);
            updateWarning();
        }

        if (form) {
            form.addEventListener('submit', function(e) {
                if (statusSelect && statusSelect.value === 'CANCELLED') {
                    var msg = 'Warning: Cancelling this DR will reduce the SO\'s remaining ordered quantity and may close the order. Continue?';
                    if (!confirm(msg)) {
                        e.preventDefault();
                    }
                }
            });
        }

        // NEW: show/hide ATL Number field based on Delivery Type
        var typeSelect = document.getElementById('type');
        var atlRow = document.getElementById('atl-number-row');
        var atlInput = document.getElementById('atl_number');

        function updateAtlVisibility() {
            if (!typeSelect || !atlRow) return;
            var isPickup = typeSelect.value === 'PICK-UP';
            atlRow.classList.toggle('d-none', !isPickup);
            if (atlInput) {
                atlInput.required = isPickup;
            }
        }

        if (typeSelect) {
            typeSelect.addEventListener('change', updateAtlVisibility);
            updateAtlVisibility();
        }
    })();
</script>
@endsection
