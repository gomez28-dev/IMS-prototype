{{-- The Raise-an-ATL drawdown form, shared by the standalone page
     (wet-stock-requests/create-atl.blade.php) and the collapsible embed on
     the request's ATL page (atls.blade.php). Expects $request, $availablePos,
     $pending and $tanks; every field name and the script below are identical
     wherever it renders, so either path posts the same payload. --}}
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
                    {{-- One ATL may be split across several purchase orders, for
                         when no single PO holds enough of the product. Each row
                         is its own PO and volume, and each becomes its own
                         allocation linked to that PO's own product line. --}}
                    <div class="col-12">
                        <label class="form-label fw-semibold small">
                            Purchase Order Drawdown <span class="text-danger">*</span>
                        </label>
                        <div id="drawdownRows">
                            @php
                                // Re-render what was rejected, so a refusal does not
                                // lose the rows the user built up.
                                $oldRows = old('drawdowns');
                                $rows = is_array($oldRows) && $oldRows
                                    ? $oldRows
                                    : [null];
                            @endphp
                            @foreach ($rows as $index => $row)
                                <div class="drawdown-row row g-2 align-items-end mb-2">
                                    <div class="col-md-5">
                                        <select class="form-select @error("drawdowns.{$index}.purchase_order_id") is-invalid @enderror"
                                                name="drawdowns[{{ $index }}][purchase_order_id]" required
                                                data-po-select>
                                            <option value="">-- Choose a Purchase Order --</option>
                                            @foreach ($availablePos as $po)
                                                <option value="{{ $po->id }}"
                                                        data-products="{{ json_encode($po->items->pluck('product', 'product')->map(fn ($p) => [$p, $po->getAvailableBalanceForProduct($p)])->values()) }}"
                                                        data-alloc-key="{{ $po->allocationKey() }}"
                                                        {{ (int) ($row['purchase_order_id'] ?? 0) === $po->id ? 'selected' : '' }}>
                                                    {{ $po->po_number }} — {{ $po->supplier_name }}
                                                </option>
                                            @endforeach
                                        </select>
                                        @error("drawdowns.{$index}.purchase_order_id")
                                            <div class="invalid-feedback">{{ $message }}</div>
                                        @enderror
                                    </div>
                                    <div class="col-md-3">
                                        <select class="form-select @error("drawdowns.{$index}.product") is-invalid @enderror"
                                                name="drawdowns[{{ $index }}][product]" required data-product-select>
                                            <option value="">-- Product --</option>
                                            @foreach (['Diesel', 'Premium', 'Unleaded'] as $product)
                                                {{-- Only products this request actually asked for. --}}
                                                @if (array_key_exists($product, $pending))
                                                    <option value="{{ $product }}" {{ ($row['product'] ?? '') === $product ? 'selected' : '' }}>
                                                        {{ $product }}
                                                    </option>
                                                @endif
                                            @endforeach
                                        </select>
                                        @error("drawdowns.{$index}.product")
                                            <div class="invalid-feedback">{{ $message }}</div>
                                        @enderror
                                    </div>
                                    <div class="col-md-3">
                                        <input type="number" min="1" step="1" required
                                               class="form-control @error("drawdowns.{$index}.quantity") is-invalid @enderror"
                                               name="drawdowns[{{ $index }}][quantity]"
                                               value="{{ $row['quantity'] ?? '' }}"
                                               placeholder="Liters">
                                        @error("drawdowns.{$index}.quantity")
                                            <div class="invalid-feedback">{{ $message }}</div>
                                        @enderror
                                    </div>
                                    <div class="col-md-1 text-end">
                                        <button type="button" class="btn btn-outline-danger btn-sm remove-row" title="Remove this PO row">
                                            <i class="bi bi-x-lg"></i>
                                        </button>
                                    </div>
                                    <div class="col-12">
                                        <div class="form-text extra-small availability-hint"></div>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                        <button type="button" id="addPoRow" class="btn btn-outline-primary btn-sm mt-1">
                            <i class="bi bi-plus-lg me-1"></i> Add PO
                        </button>
                        <div class="form-text extra-small mt-2">
                            Only VP-approved purchase orders with volume left are offered. Add a
                            second row when one PO does not hold enough on its own.
                        </div>
                        <div id="outstandingHint" class="form-text extra-small mt-1">
                            @foreach ($pending as $product => $qty)
                                Outstanding on {{ $product }}: {{ number_format($qty) }} L
                            @endforeach
                        </div>
                        @error('drawdowns')
                            <div class="text-danger extra-small mt-1">{{ $message }}</div>
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
                        <div class="alert alert-info py-2 px-3 small mb-0">
                            <i class="bi bi-info-circle me-1"></i>
                            This ATL goes straight to the VP approval queue. Nothing lifts
                            until it is approved.
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

<script>
document.addEventListener('DOMContentLoaded', function () {
    const container = document.getElementById('drawdownRows');
    const addButton = document.getElementById('addPoRow');

    if (!container) return;

    // What each PO still holds, per product. The server checks the same thing on
    // save; this is so the user is not told only after submitting.
    function poProducts(poSelect) {
        const option = poSelect.options[poSelect.selectedIndex];
        return option && option.dataset.products ? JSON.parse(option.dataset.products) : [];
    }

    function refreshRow(row) {
        const poSelect = row.querySelector('[data-po-select]');
        const productSelect = row.querySelector('[data-product-select]');
        const hint = row.querySelector('.availability-hint');
        if (!poSelect || !productSelect || !hint) return;

        const match = poProducts(poSelect).find(function (r) { return r[0] === productSelect.value; });

        hint.textContent = match
            ? match[1].toLocaleString() + ' L of ' + productSelect.value + ' available on this PO'
            : '';
    }

    function refreshAll() {
        container.querySelectorAll('.drawdown-row').forEach(refreshRow);
    }

    // Removing the last row would leave nothing to submit, so the final one keeps
    // its button hidden rather than letting the form post an empty array.
    function refreshRemoveButtons() {
        const rows = container.querySelectorAll('.drawdown-row');
        rows.forEach(function (row) {
            const button = row.querySelector('.remove-row');
            if (button) button.style.visibility = rows.length > 1 ? 'visible' : 'hidden';
        });
    }

    container.addEventListener('change', function (event) {
        if (event.target.matches('[data-po-select], [data-product-select]')) {
            refreshRow(event.target.closest('.drawdown-row'));
        }
    });

    container.addEventListener('click', function (event) {
        const button = event.target.closest('.remove-row');
        if (!button) return;

        const rows = container.querySelectorAll('.drawdown-row');
        if (rows.length <= 1) return;

        button.closest('.drawdown-row').remove();
        reindex();
        refreshRemoveButtons();
    });

    // Field names are indexed, so after a removal the surviving rows are renumbered
    // to keep the array gapless.
    function reindex() {
        container.querySelectorAll('.drawdown-row').forEach(function (row, index) {
            row.querySelectorAll('[name]').forEach(function (field) {
                field.name = field.name.replace(/drawdowns\[\d*\]/, 'drawdowns[' + index + ']');
            });
        });
    }

    if (addButton) {
        addButton.addEventListener('click', function () {
            const template = container.querySelector('.drawdown-row');
            if (!template) return;

            // One lift, one rack: a new row may only reach POs of the same
            // supplier at the same rack as the first row - the same rule the
            // save enforces. With no PO chosen yet the full pool stays on
            // offer; the save refuses a mixed split either way.
            const keyOption = template.querySelector('[data-po-select]').selectedOptions[0];
            const rackKey = keyOption ? keyOption.getAttribute('data-alloc-key') : null;

            const clone = template.cloneNode(true);
            clone.querySelectorAll('input, select').forEach(function (field) {
                if (field.tagName === 'INPUT') field.value = '';
                else field.selectedIndex = 0;
                field.classList.remove('is-invalid');
            });

            if (rackKey) {
                const poSelect = clone.querySelector('[data-po-select]');
                Array.from(poSelect.options).forEach(function (option) {
                    if (option.value && option.getAttribute('data-alloc-key') !== rackKey) {
                        option.remove();
                    }
                });
            }
            const hint = clone.querySelector('.availability-hint');
            if (hint) hint.textContent = '';

            container.appendChild(clone);
            reindex();
            refreshRemoveButtons();
        });
    }

    refreshAll();
    refreshRemoveButtons();
});
</script>
