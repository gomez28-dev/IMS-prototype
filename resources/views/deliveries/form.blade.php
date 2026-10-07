@extends('layouts.app')

@section('title', $title)

@section('content')
@php
    // Product rows: previous input, else the delivery's lines, else one blank row
    if (is_array(old('items'))) {
        $initialItems = array_values(old('items'));
    } elseif ($delivery && $delivery->items->isNotEmpty()) {
        $initialItems = $delivery->items->map(fn ($i) => [
            'product_type' => $i->product_type ?: '-',
            'qty_out' => $i->qty_out,
        ])->values()->all();
    } elseif ($delivery) {
        $initialItems = [['product_type' => $delivery->product_type ?: '-', 'qty_out' => $delivery->qty_out]];
    } else {
        $initialItems = [[
            'product_type' => count($products) === 1 ? array_key_first($products) : '',
            'qty_out' => '',
        ]];
    }

    $productData = collect($products)->map(fn ($p) => [
        'name' => $p['name'],
        'available' => $p['available'],
    ]);

    $itemErrors = [];
    foreach ($errors->getBag('default')->messages() as $field => $msgs) {
        if ($field === 'items' || str_starts_with($field, 'items.')) {
            foreach ($msgs as $m) { $itemErrors[$m] = $m; }
        }
    }
@endphp
<style>
    .btn-add-product {
        border: 1px solid #ff4d00;
        color: #ff4d00;
        background: #fff;
    }
    .btn-add-product:hover {
        background: #ff4d00;
        color: #fff;
    }
</style>
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
                    @if ($order && $order->status === 'Cancelled')
                        <span class="badge bg-danger-subtle text-danger border fw-semibold"><i class="bi bi-x-circle me-1"></i>{{ $order->status }}</span>
                    @else
                        <span class="badge bg-success-subtle text-success border fw-semibold"><i class="bi bi-check-circle me-1"></i>{{ $order ? $order->status : 'Active' }}</span>
                    @endif
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

                {{-- Remaining per product on this SO --}}
                <div class="small text-muted mb-3">
                    Remaining on this SO:
                    @foreach ($products as $code => $p)
                        <span class="me-2">{{ $code === '-' ? 'Unspecified' : $code . ' - ' . $p['name'] }}: <span class="fw-medium text-dark">{{ number_format($p['available']) }} L</span></span>
                    @endforeach
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

                    {{-- Products --}}
                    <div class="mb-3">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <label class="form-label fw-medium text-secondary small mb-0">Products <span class="text-danger">*</span></label>
                            <button type="button" class="btn btn-sm btn-add-product" id="add-item"><i class="bi bi-plus-lg me-1"></i>Add Product</button>
                        </div>

                        <div class="border rounded p-3" id="items-container"></div>

                        @if (!empty($itemErrors))
                            <div class="text-danger small mt-2">
                                @foreach ($itemErrors as $msg)
                                    <div>{{ $msg }}</div>
                                @endforeach
                            </div>
                        @endif
                        <div class="text-danger small mt-2 d-none" id="items-error"></div>

                        <div class="d-flex justify-content-between align-items-center mt-2 px-3 py-2 bg-light border rounded small">
                            <span class="text-secondary">Total Qty: <span class="fw-bold text-dark font-monospace" id="items-total">0</span> L</span>
                        </div>
                    </div>

                    <div class="row mb-3">
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
                    </div>

                    <div class="alert alert-warning d-none" id="cancel-warning" role="alert">
                        <i class="bi bi-exclamation-triangle-fill me-2"></i>
                        <strong>Warning:</strong> Cancelling this DR will reduce the SO's remaining ordered quantity and may close the order. Reassigning it to PENDING later will restore the original quantity.
                    </div>

                    <div class="row mb-3" id="atl-number-group" style="display:none;">
                        <div class="col-md-6">
                            <label for="atl_number" class="form-label fw-medium text-secondary small">ATL #</label>
                            <input type="text" name="atl_number" id="atl_number" class="form-control @error('atl_number') is-invalid @enderror" placeholder="e.g. ATL-00123" value="{{ old('atl_number', $delivery ? $delivery->atl_number : '') }}">
                            @error('atl_number')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
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
        var products = @json($productData);
        var initialItems = @json($initialItems);

        var container = document.getElementById('items-container');
        var addBtn = document.getElementById('add-item');
        var totalEl = document.getElementById('items-total');
        var itemsError = document.getElementById('items-error');
        var statusSelect = document.getElementById('status');
        var warning = document.getElementById('cancel-warning');
        var typeSelect = document.getElementById('type');
        var atlGroup = document.getElementById('atl-number-group');
        var form = container.closest('form');
        var rowIndex = 0;

        function fmt(n) {
            return Number(n).toLocaleString('en-US');
        }

        function esc(s) {
            return String(s).replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;');
        }

        function optionsHtml(selected) {
            var known = selected !== '' && selected !== null && typeof products[selected] !== 'undefined';
            var html = '<option value=""' + (known ? '' : ' selected') + ' disabled>Select product...</option>';
            Object.keys(products).forEach(function(code) {
                var label = code === '-' ? 'Unspecified' : code + ' - ' + products[code].name;
                html += '<option value="' + esc(code) + '"' + (String(selected) === String(code) ? ' selected' : '') + '>' + esc(label) + '</option>';
            });
            return html;
        }

        function addRow(item) {
            item = item || { product_type: '', qty_out: '' };
            var i = rowIndex++;
            var row = document.createElement('div');
            row.className = 'row g-2 align-items-end item-row';
            row.innerHTML =
                '<div class="col-7 col-md-6">' +
                    '<label class="form-label text-secondary small mb-1">Type of Product</label>' +
                    '<select name="items[' + i + '][product_type]" class="form-control form-select item-product" required>' + optionsHtml(item.product_type) + '</select>' +
                '</div>' +
                '<div class="col-5 col-md-4">' +
                    '<label class="form-label text-secondary small mb-1">Qty.</label>' +
                    '<input type="number" name="items[' + i + '][qty_out]" class="form-control font-monospace item-qty" placeholder="e.g. 1000" min="1" step="1" value="' + esc(item.qty_out === null ? '' : item.qty_out) + '" required>' +
                '</div>' +
                '<div class="col-12 col-md-2 text-end">' +
                    '<button type="button" class="btn btn-sm btn-outline-danger item-remove" title="Remove product"><i class="bi bi-trash"></i></button>' +
                '</div>' +
                '<div class="col-12 small text-muted item-help"></div>';
            container.appendChild(row);
            refresh();
        }

        function rows() {
            return Array.prototype.slice.call(container.querySelectorAll('.item-row'));
        }

        // Returns true when valid; shows messages otherwise
        function refresh() {
            var all = rows();
            var total = 0;
            var perProduct = {};
            var message = '';

            all.forEach(function(row, idx) {
                // separator between rows
                row.style.borderTop = idx === 0 ? '' : '1px solid #dee2e6';
                row.style.marginTop = idx === 0 ? '' : '0.75rem';
                row.style.paddingTop = idx === 0 ? '' : '0.75rem';

                row.querySelector('.item-remove').style.visibility = all.length === 1 ? 'hidden' : 'visible';

                var code = row.querySelector('.item-product').value;
                var qtyRaw = row.querySelector('.item-qty').value;
                var qty = parseInt(qtyRaw, 10);
                var help = row.querySelector('.item-help');

                help.textContent = (code && products[code]) ? 'Max for ' + products[code].name + ': ' + fmt(products[code].available) + ' L' : '';

                if (!isNaN(qty) && qty > 0) {
                    total += qty;
                    if (code) { perProduct[code] = (perProduct[code] || 0) + qty; }
                } else if (qtyRaw !== '') {
                    message = 'Each quantity must be a whole number of liters (at least 1).';
                }
            });

            Object.keys(perProduct).forEach(function(code) {
                var p = products[code];
                if (p && perProduct[code] > p.available && !message) {
                    message = 'Total ' + p.name + ' (' + fmt(perProduct[code]) + ' L) exceeds the remaining ' + fmt(p.available) + ' L on this SO.';
                }
            });

            totalEl.textContent = fmt(total);

            if (message) {
                itemsError.textContent = message;
                itemsError.classList.remove('d-none');
                return false;
            }
            itemsError.classList.add('d-none');
            return true;
        }

        container.addEventListener('input', refresh);
        container.addEventListener('change', refresh);
        container.addEventListener('click', function(e) {
            var btn = e.target.closest('.item-remove');
            if (btn && rows().length > 1) {
                btn.closest('.item-row').remove();
                refresh();
            }
        });
        addBtn.addEventListener('click', function() { addRow(); });

        (initialItems.length ? initialItems : [null]).forEach(function(it) { addRow(it); });

        function updateWarning() {
            if (statusSelect && warning) {
                warning.classList.toggle('d-none', statusSelect.value !== 'CANCELLED');
            }
        }

        function updateAtlVisibility() {
            if (typeSelect && atlGroup) {
                atlGroup.style.display = (typeSelect.value === 'PICK-UP') ? '' : 'none';
            }
        }

        if (statusSelect) {
            statusSelect.addEventListener('change', updateWarning);
            updateWarning();
        }
        if (typeSelect) {
            typeSelect.addEventListener('change', updateAtlVisibility);
            updateAtlVisibility();
        }

        form.addEventListener('submit', function(e) {
            // The server re-checks everything; this just saves a round trip.
            if (!refresh()) {
                e.preventDefault();
                return;
            }

            if (statusSelect && statusSelect.value === 'CANCELLED') {
                var msg = 'Warning: Cancelling this DR will reduce the SO\'s remaining ordered quantity and may close the order. Continue?';
                if (!confirm(msg)) {
                    e.preventDefault();
                }
            }
        });
    })();
</script>
@endsection