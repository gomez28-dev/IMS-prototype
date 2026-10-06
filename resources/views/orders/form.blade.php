@extends('layouts.app')

@section('title', $title)

@section('content')
@php
    $blankItem = ['product_type' => '', 'qty' => '', 'price' => ''];

    // Rows to show: previously submitted input (after a validation error),
    // else the order's saved product lines, else one blank row.
    $existingItems = old('items');
    if ($existingItems === null) {
        $existingItems = $order
            ? $order->items->map(fn($i) => [
                'product_type' => $i->product_type ?? '',
                'qty' => $i->qty,
                'price' => number_format((float) $i->price, 2, '.', ''),
            ])->all()
            : [];
    }
    if (empty($existingItems)) {
        $existingItems = [$blankItem];
    }
@endphp
<div class="row justify-content-center">
    <div class="col-md-8 col-lg-6">
        <div class="mb-3">
            <a href="{{ $returnTo ?? route('dashboard') }}" class="text-decoration-none text-secondary small">
                <i class="bi bi-arrow-left me-1"></i> Back
            </a>
        </div>
        <div class="card card-custom p-4 border-0">
            <div class="card-body">
                <h4 class="fw-bold mb-4 text-dark">
                    <i class="bi {{ $order ? 'bi-journal-check' : 'bi-journal-plus' }} text-primary me-2"></i>{{ $title }}
                </h4>
                
                <form method="POST" action="{{ $order ? route('order.update', $order->id) : route('order.store') }}" novalidate>
                    @csrf
                    <input type="hidden" name="return_to" value="{{ old('return_to', $returnTo ?? route('dashboard')) }}">
                    
                    <div class="mb-3">
                        <label for="account" class="form-label fw-medium text-secondary small">Account <span class="text-danger">*</span></label>
                        <select name="account" id="account" class="form-control form-select @error('account') is-invalid @enderror" required>
                            <option value="" disabled {{ old('account', $order ? $order->account : '') === '' ? 'selected' : '' }}>Select a client account...</option>
                            @foreach ($clients as $client)
                                <option value="{{ $client->name }}" {{ old('account', $order ? $order->account : '') === $client->name ? 'selected' : '' }}>{{ $client->name }}</option>
                            @endforeach
                        </select>
                        @error('account')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    <div class="mb-3">
                        <label for="location" class="form-label fw-medium text-secondary small">Location <span class="text-danger">*</span></label>
                        <select name="location" id="location" class="form-control form-select @error('location') is-invalid @enderror" required>
                            <option value="Valenzuela" {{ old('location', $order ? $order->location : 'Valenzuela') === 'Valenzuela' ? 'selected' : '' }}>Valenzuela</option>
                            <option value="San Simon" {{ old('location', $order ? $order->location : '') === 'San Simon' ? 'selected' : '' }}>San Simon</option>
                        </select>
                        @error('location')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label for="fulfillment_type" class="form-label fw-medium text-secondary small">Fulfillment Type <span class="text-danger">*</span></label>
                            <select name="fulfillment_type" id="fulfillment_type" class="form-control form-select @error('fulfillment_type') is-invalid @enderror" required>
                                <option value="DELIVERY" {{ old('fulfillment_type', $order ? $order->fulfillment_type : 'DELIVERY') === 'DELIVERY' ? 'selected' : '' }}>Delivery (Small/Big Tanker)</option>
                                <option value="DEPOT_PICKUP" {{ old('fulfillment_type', $order ? $order->fulfillment_type : '') === 'DEPOT_PICKUP' ? 'selected' : '' }}>Pick Up at Doyen Depot</option>
                                <option value="FUEL_TRADE" {{ old('fulfillment_type', $order ? $order->fulfillment_type : '') === 'FUEL_TRADE' ? 'selected' : '' }}>Fuel Trade: Pick Up at Supplier</option>
                            </select>
                            @error('fulfillment_type')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>
                        <div class="col-md-6">
                            <label for="order_category" class="form-label fw-medium text-secondary small">Order Category <span class="text-danger">*</span></label>
                            <select name="order_category" id="order_category" class="form-control form-select @error('order_category') is-invalid @enderror" required>
                                <option value="CLIENT_ORDER" {{ old('order_category', $order ? $order->order_category : 'CLIENT_ORDER') === 'CLIENT_ORDER' ? 'selected' : '' }}>Client Order</option>
                                <option value="BUY_BACK" {{ old('order_category', $order ? $order->order_category : '') === 'BUY_BACK' ? 'selected' : '' }}>Buy Back</option>
                            </select>
                            @error('order_category')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>
                    </div>

                    <div class="mb-3">
                        <label for="client_atl_number" class="form-label fw-medium text-secondary small">Client ATL Number (Optional / If Client provides ATL)</label>
                        <input type="text" name="client_atl_number" id="client_atl_number" class="form-control @error('client_atl_number') is-invalid @enderror" placeholder="e.g. ATL-CLIENT-12345" value="{{ old('client_atl_number', $order ? $order->client_atl_number : '') }}">
                        @error('client_atl_number')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label for="so_number" class="form-label fw-medium text-secondary small">SO Number <span class="text-danger">*</span></label>
                            <input type="text" name="so_number" id="so_number" class="form-control @error('so_number') is-invalid @enderror" placeholder="e.g. SO-12345" value="{{ old('so_number', $order ? $order->so_number : '') }}" required>
                            @error('so_number')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>
                        <div class="col-md-6">
                            <label for="date" class="form-label fw-medium text-secondary small">Order Date <span class="text-danger">*</span></label>
                            <input type="date" name="date" id="date" class="form-control @error('date') is-invalid @enderror" value="{{ old('date', $order && $order->date ? $order->date->format('Y-m-d') : '') }}" required>
                            @error('date')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>
                    </div>

                    <div class="mb-4">
                        <label for="po_number" class="form-label fw-medium text-secondary small">PO Number</label>
                        <input type="text" name="po_number" id="po_number" class="form-control @error('po_number') is-invalid @enderror" placeholder="e.g. PO-12345 (optional)" value="{{ old('po_number', $order ? $order->po_number : '') }}">
                        @error('po_number')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    {{-- Products: one row per product (type, qty, price) --}}
                    <div class="mb-4">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <label class="form-label fw-medium text-secondary small mb-0">Products <span class="text-danger">*</span></label>
                            <button type="button" id="add-item-btn" class="btn btn-sm btn-outline-primary">
                                <i class="bi bi-plus-lg me-1"></i> Add Product
                            </button>
                        </div>

                        @error('items')
                            <div class="alert alert-danger py-2 small">{{ $message }}</div>
                        @enderror

                        <div id="items-container"></div>

                        <div class="d-flex justify-content-between align-items-center border rounded-3 bg-light px-3 py-2 mt-2">
                            <div class="small text-secondary">Total Qty: <span id="total-qty" class="fw-semibold text-dark">0</span></div>
                            <div class="small text-secondary">Grand Total: <span class="fw-bold text-dark">₱ <span id="grand-total">0.00</span></span></div>
                        </div>
                    </div>

                    <div class="row mb-4">
                        <div class="col-md-6">
                            <label for="status" class="form-label fw-medium text-secondary small">Status <span class="text-danger">*</span></label>
                            <select name="status" id="status" class="form-control form-select @error('status') is-invalid @enderror" required>
                                <option value="Active" {{ old('status', $order ? $order->status : 'Active') === 'Active' ? 'selected' : '' }}>Active</option>
                                <option value="Cancelled" {{ old('status', $order ? $order->status : '') === 'Cancelled' ? 'selected' : '' }}>Cancelled</option>
                            </select>
                            @error('status')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>
                        <div class="col-md-6">
                            <label for="terms" class="form-label fw-medium text-secondary small">Terms</label>
                            <input type="text" name="terms" id="terms" class="form-control @error('terms') is-invalid @enderror" placeholder="e.g. COD, 30 days (optional)" value="{{ old('terms', $order ? $order->terms : '') }}">
                            @error('terms')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>
                    </div>

                    <div class="d-flex justify-content-end gap-2">
                        <a href="{{ $returnTo ?? route('dashboard') }}" class="btn btn-light border">Cancel</a>
                        <button type="submit" class="btn btn-primary-custom">Save Order</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const productOptions = @json(\App\Models\Order::PRODUCT_TYPES);
    const initialItems = @json($existingItems);
    const itemErrors = @json($errors->messages());

    const container = document.getElementById('items-container');
    const addBtn = document.getElementById('add-item-btn');
    const totalQtyEl = document.getElementById('total-qty');
    const grandTotalEl = document.getElementById('grand-total');

    let nextIndex = 0;

    function fmt(n) {
        return n.toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    function showError(row, idx, field, input) {
        const msgs = itemErrors['items.' + idx + '.' + field];
        if (!msgs || !msgs.length) return;
        input.classList.add('is-invalid');
        const fb = document.createElement('div');
        fb.className = 'invalid-feedback d-block';
        fb.textContent = msgs[0];
        input.closest('.field-wrap').appendChild(fb);
    }

    function addRow(data, idx) {
        data = data || { product_type: '', qty: '', price: '' };
        if (idx === undefined) {
            idx = nextIndex;
        }
        idx = parseInt(idx, 10);
        nextIndex = Math.max(nextIndex, idx + 1);

        let optionsHtml = '<option value="" disabled>Select product...</option>';
        Object.keys(productOptions).forEach(function (code) {
            optionsHtml += '<option value="' + code + '">' + code + ' - ' + productOptions[code] + '</option>';
        });

        const row = document.createElement('div');
        row.className = 'item-row border rounded-3 p-3 mb-2';
        row.innerHTML =
            '<div class="row g-2 align-items-start">' +
                '<div class="col-md-4 field-wrap">' +
                    '<label class="form-label text-secondary small mb-1">Type of Product</label>' +
                    '<select name="items[' + idx + '][product_type]" class="form-control form-select item-type">' + optionsHtml + '</select>' +
                '</div>' +
                '<div class="col-md-3 field-wrap">' +
                    '<label class="form-label text-secondary small mb-1">Qty.</label>' +
                    '<input type="number" min="1" step="1" name="items[' + idx + '][qty]" class="form-control item-qty" placeholder="e.g. 1000">' +
                '</div>' +
                '<div class="col-md-3 field-wrap">' +
                    '<label class="form-label text-secondary small mb-1">Price</label>' +
                    '<div class="input-group"><span class="input-group-text">₱</span>' +
                    '<input type="number" min="0" step="0.01" name="items[' + idx + '][price]" class="form-control item-price" placeholder="0.00"></div>' +
                '</div>' +
                '<div class="col-md-2 d-flex align-items-end justify-content-md-end" style="min-height:58px;">' +
                    '<button type="button" class="btn btn-sm btn-outline-danger remove-item-btn" title="Remove this product"><i class="bi bi-trash"></i></button>' +
                '</div>' +
            '</div>' +
            '<div class="text-end small text-secondary mt-2">Amount: <span class="fw-semibold text-dark">₱ <span class="line-amount">0.00</span></span></div>';

        const typeEl = row.querySelector('.item-type');
        const qtyEl = row.querySelector('.item-qty');
        const priceEl = row.querySelector('.item-price');

        typeEl.value = data.product_type || '';
        qtyEl.value = (data.qty === null || data.qty === undefined) ? '' : data.qty;
        priceEl.value = (data.price === null || data.price === undefined) ? '' : data.price;

        showError(row, idx, 'product_type', typeEl);
        showError(row, idx, 'qty', qtyEl);
        showError(row, idx, 'price', priceEl);

        qtyEl.addEventListener('input', recompute);
        priceEl.addEventListener('input', recompute);
        row.querySelector('.remove-item-btn').addEventListener('click', function () {
            row.remove();
            recompute();
        });

        container.appendChild(row);
        recompute();
    }

    function recompute() {
        const rows = container.querySelectorAll('.item-row');
        let totalQty = 0;
        let grand = 0;

        rows.forEach(function (row) {
            const q = parseFloat(row.querySelector('.item-qty').value) || 0;
            const p = parseFloat(row.querySelector('.item-price').value) || 0;
            const line = q * p;
            row.querySelector('.line-amount').textContent = fmt(line);
            totalQty += q;
            grand += line;

            // Can't remove the last remaining row
            row.querySelector('.remove-item-btn').style.visibility = rows.length > 1 ? 'visible' : 'hidden';
        });

        totalQtyEl.textContent = totalQty.toLocaleString('en-PH');
        grandTotalEl.textContent = fmt(grand);
    }

    addBtn.addEventListener('click', function () {
        addRow();
    });

    Object.keys(initialItems).forEach(function (key) {
        addRow(initialItems[key], key);
    });
});
</script>
@endsection
