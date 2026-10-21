@extends('layouts.app')

@section('title', $title)

@section('content')
<div class="row justify-content-center">
    <div class="col-lg-8">
        <div class="mb-3">
            <a href="{{ route('wetstock.transfers.index', ['type' => $type]) }}" class="text-decoration-none text-secondary small">
                <i class="bi bi-arrow-left me-1"></i> Back to Stock Transfers Log
            </a>
        </div>

        <div class="card card-custom border-0 shadow-sm">
            <div class="card-body p-4">
                <div class="d-flex align-items-center justify-content-between mb-4 border-bottom pb-3 flex-wrap gap-2">
                    <div>
                        <h4 class="fw-bold text-dark mb-1">
                            <i class="bi bi-arrow-left-right text-primary me-2"></i>{{ $title }}
                        </h4>
                        <p class="text-muted small mb-0">
                            @if ($type === 'transfer')
                                Intra-Site Movement: Source and destination tanks must belong to the <strong>same warehouse</strong>.
                            @elseif ($type === 'borrow')
                                Cross-Site Borrowing: Source and destination tanks must belong to <strong>different warehouses</strong>.
                            @else
                                Cross-Site Return: Source and destination tanks must belong to <strong>different warehouses</strong>.
                            @endif
                        </p>
                    </div>

                    @if (!$transfer)
                    <div class="btn-group btn-group-sm" role="group">
                        <a href="{{ route('wetstock.transfers.create', ['type' => 'transfer']) }}" class="btn btn-outline-primary {{ $type === 'transfer' ? 'active' : '' }}">
                            Transfer
                        </a>
                        <a href="{{ route('wetstock.transfers.create', ['type' => 'borrow']) }}" class="btn btn-outline-primary {{ $type === 'borrow' ? 'active' : '' }}">
                            Borrow
                        </a>
                        <a href="{{ route('wetstock.transfers.create', ['type' => 'return']) }}" class="btn btn-outline-success {{ $type === 'return' ? 'active' : '' }}">
                            Return
                        </a>
                    </div>
                    @endif
                </div>

                @if ($transfer)
                    <div class="alert alert-warning border-0 rounded-3 py-2 px-3 small mb-4">
                        <i class="bi bi-info-circle-fill me-1"></i> <strong>Approval Notice:</strong> Modifying an existing transfer creates a <strong>Modification Request</strong> for Operations Manager review before updating the live inventory balances.
                    </div>
                @endif

                <form method="POST" action="{{ $transfer ? route('wetstock.transfers.update', $transfer->id) : route('wetstock.transfers.store') }}" id="transferForm">
                    @csrf
                    <input type="hidden" name="type" id="transfer_type" value="{{ $type }}">

                    <div class="row g-3 mb-3">
                        <!-- Source Tank -->
                        <div class="col-md-6">
                            <label for="source_tank_id" class="form-label fw-bold text-dark small">
                                <i class="bi bi-box-arrow-up-right text-danger me-1"></i>Source Tank (From)
                            </label>
                            <select name="source_tank_id" id="source_tank_id" class="form-select @error('source_tank_id') is-invalid @enderror" required>
                                <option value="">Select Source Tank...</option>
                                @foreach ($warehouses as $wh)
                                    <optgroup label="{{ $wh->name }}">
                                        @foreach ($wh->activeTanks as $tank)
                                            @php $m = $tankMeta[$tank->id]; @endphp
                                            <option value="{{ $tank->id }}"
                                                data-warehouse-id="{{ $wh->id }}"
                                                data-warehouse-name="{{ $wh->name }}"
                                                data-category="{{ $tank->category }}"
                                                data-truck="{{ $m['truck'] ? 1 : 0 }}"
                                                data-product="{{ $m['product'] }}"
                                                data-available="{{ $m['avail'] }}"
                                                data-contaminated="{{ $m['bad'] }}"
                                                {{ (old('source_tank_id', $transfer ? $transfer->source_tank_id : $sourceTankId) == $tank->id) ? 'selected' : '' }}
                                                {{ ($m['blocked'] && (!$transfer || $transfer->source_tank_id != $tank->id)) ? 'disabled' : '' }}>
                                                {{ $tank->name }} ({{ ucfirst($tank->category) }}) — @if ($m['truck'])pick a compartment @else{{ number_format($m['avail']) }}L {{ $m['product_name'] }} Avail @endif
                                                {{ $m['bad'] > 0 ? ' [' . number_format($m['bad']) . 'L Contaminated' . ($m['blocked'] ? ' - BLOCKED' : '') . ']' : '' }}
                                            </option>
                                        @endforeach
                                    </optgroup>
                                @endforeach
                            </select>
                            @error('source_tank_id')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror

                            <div id="source_comp_wrap" class="mt-2 d-none">
                                <label for="source_compartment_id" class="form-label small fw-semibold text-dark mb-1">Source compartment</label>
                                <select name="source_compartment_id" id="source_compartment_id" class="form-select form-select-sm @error('source_compartment_id') is-invalid @enderror" data-selected="{{ old('source_compartment_id', $transfer ? $transfer->source_compartment_id : '') }}" disabled></select>
                            </div>
                            <div id="source_tank_info" class="mt-2 small text-muted"></div>
                        </div>

                        <!-- Destination Tank -->
                        <div class="col-md-6">
                            <label for="destination_tank_id" class="form-label fw-bold text-dark small">
                                <i class="bi bi-box-arrow-in-down-left text-success me-1"></i>Destination Tank (To)
                            </label>
                            <select name="destination_tank_id" id="destination_tank_id" class="form-select @error('destination_tank_id') is-invalid @enderror" required>
                                <option value="">Select Destination Tank...</option>
                                @foreach ($warehouses as $wh)
                                    <optgroup label="{{ $wh->name }}">
                                        @foreach ($wh->activeTanks as $tank)
                                            @php $m = $tankMeta[$tank->id]; @endphp
                                            <option value="{{ $tank->id }}"
                                                data-warehouse-id="{{ $wh->id }}"
                                                data-warehouse-name="{{ $wh->name }}"
                                                data-category="{{ $tank->category }}"
                                                data-truck="{{ $m['truck'] ? 1 : 0 }}"
                                                data-product="{{ $m['product'] }}"
                                                data-empty="{{ $m['stock'] < 1 ? 1 : 0 }}"
                                                data-capacity-remaining="{{ $m['room'] }}"
                                                data-max-capacity="{{ $tank->max_capacity }}"
                                                {{ (old('destination_tank_id', $transfer ? $transfer->destination_tank_id : $destinationTankId) == $tank->id) ? 'selected' : '' }}>
                                                {{ $tank->name }} ({{ ucfirst($tank->category) }}) — @if ($m['truck'])pick a compartment @else{{ number_format($m['room']) }}L Room @if ($m['product_name'])· {{ $m['product_name'] }}@endif @endif
                                            </option>
                                        @endforeach
                                    </optgroup>
                                @endforeach
                            </select>
                            @error('destination_tank_id')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror

                            <div id="dest_comp_wrap" class="mt-2 d-none">
                                <label for="destination_compartment_id" class="form-label small fw-semibold text-dark mb-1">Destination compartment</label>
                                <select name="destination_compartment_id" id="destination_compartment_id" class="form-select form-select-sm @error('destination_compartment_id') is-invalid @enderror" data-selected="{{ old('destination_compartment_id', $transfer ? $transfer->destination_compartment_id : '') }}" disabled></select>
                            </div>
                            <div id="dest_tank_info" class="mt-2 small text-muted"></div>
                        </div>
                    </div>

                    <div id="product_banner" class="d-none mb-4 small">
                        <span class="text-muted me-1">Product being moved:</span>
                        <span id="product_chip" class="badge rounded-pill text-white"></span>
                        <span id="product_note" class="text-muted ms-2"></span>
                    </div>

                    <div class="row g-3 mb-4">
                        <!-- Transfer Date -->
                        <div class="col-md-6">
                            <label for="transfer_date" class="form-label fw-medium text-secondary small">Transaction Date</label>
                            <input type="date" name="transfer_date" id="transfer_date" class="form-control @error('transfer_date') is-invalid @enderror" value="{{ old('transfer_date', $transfer ? ($transfer->transfer_date ? $transfer->transfer_date->format('Y-m-d') : '') : date('Y-m-d')) }}" required>
                            @error('transfer_date')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>

                        <!-- Quantity -->
                        <div class="col-md-6">
                            <label for="quantity" class="form-label fw-medium text-secondary small">Volume (Liters)</label>
                            <div class="input-group">
                                <input type="number" name="quantity" id="quantity" class="form-control font-monospace @error('quantity') is-invalid @enderror" placeholder="e.g. 10000" value="{{ old('quantity', $transfer ? $transfer->quantity : '') }}" min="1" step="1" required>
                                <span class="input-group-text">Liters</span>
                            </div>
                            @error('quantity')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                            <div id="qty_validation_feedback" class="small mt-1"></div>
                        </div>
                    </div>

                    <!-- Notes -->
                    <div class="mb-4">
                        <label for="notes" class="form-label fw-medium text-secondary small">Notes / Trip Ticket / Reference</label>
                        <textarea name="notes" id="notes" class="form-control @error('notes') is-invalid @enderror" rows="3" placeholder="Optional notes, driver name, trip ticket #...">{{ old('notes', $transfer ? $transfer->notes : '') }}</textarea>
                        @error('notes')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    @if ($transfer)
                    <!-- Modification Reason -->
                    <div class="mb-4">
                        <label for="modification_reason" class="form-label fw-bold text-dark small">Reason for Modification</label>
                        <textarea name="modification_reason" id="modification_reason" class="form-control @error('modification_reason') is-invalid @enderror" rows="2" placeholder="e.g. Quantity correction per actual tank dipping..." required>{{ old('modification_reason') }}</textarea>
                        @error('modification_reason')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>
                    @endif

                    <!-- Live Validation Alert Box -->
                    <div id="transfer_alert" class="alert alert-warning d-none small mb-4">
                        <i class="bi bi-exclamation-triangle-fill me-2"></i><span id="transfer_alert_text"></span>
                    </div>

                    <div class="d-flex justify-content-end gap-2">
                        <a href="{{ route('wetstock.transfers.index', ['type' => $type]) }}" class="btn btn-light border">Cancel</a>
                        <button type="submit" class="btn btn-primary-custom px-4" id="submitBtn">
                            <i class="bi bi-check2-circle me-1"></i> {{ $transfer ? 'Submit Modification Request' : 'Save Record' }}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function() {
    const COMPS = @json($compData);
    const PRODUCTS = @json(\App\Models\Order::PRODUCT_TYPES);
    const COLORS = { U: '#22a45d', P: '#e5383b', D: '#f2a900' };

    const sourceSelect = document.getElementById('source_tank_id');
    const destSelect = document.getElementById('destination_tank_id');
    const srcComp = document.getElementById('source_compartment_id');
    const dstComp = document.getElementById('destination_compartment_id');
    const srcCompWrap = document.getElementById('source_comp_wrap');
    const dstCompWrap = document.getElementById('dest_comp_wrap');
    const qtyInput = document.getElementById('quantity');
    const typeInput = document.getElementById('transfer_type');
    const alertBox = document.getElementById('transfer_alert');
    const alertText = document.getElementById('transfer_alert_text');
    const submitBtn = document.getElementById('submitBtn');
    const sourceInfo = document.getElementById('source_tank_info');
    const destInfo = document.getElementById('dest_tank_info');
    const banner = document.getElementById('product_banner');
    const chip = document.getElementById('product_chip');
    const note = document.getElementById('product_note');

    const currentType = typeInput ? typeInput.value : 'transfer';
    const fmt = n => Number(n || 0).toLocaleString('en-US');
    const pname = c => (c && PRODUCTS[c]) ? PRODUCTS[c] : 'No product';
    const sel = s => s.options[s.selectedIndex];
    const isTruck = o => !!(o && o.value && o.dataset.truck === '1');

    // Fill a compartment picker for the truck chosen in tankSelect (hidden for depots).
    function fillComps(tankSelect, compSelect, wrap, product, mode) {
        const opt = sel(tankSelect);
        const comps = isTruck(opt) ? (COMPS[opt.value] || []) : [];
        const keep = compSelect.value || compSelect.dataset.selected || '';
        compSelect.dataset.selected = '';
        compSelect.innerHTML = '';

        if (!comps.length) {
            wrap.classList.add('d-none');
            compSelect.disabled = true;
            return;
        }

        wrap.classList.remove('d-none');
        compSelect.disabled = false;
        compSelect.add(new Option('Select compartment...', ''));

        comps.forEach(function (c) {
            let usable, text;
            if (mode === 'source') {
                usable = !!c.product && c.avail > 0;
                text = 'C' + c.no + ' · ' + pname(c.product) + ' · ' + fmt(c.avail) + ' L available' + (c.bad > 0 ? ' [' + fmt(c.bad) + ' L contaminated]' : '');
            } else {
                usable = c.room > 0 && (!product || !c.product || c.product === product || c.stock === 0);
                text = 'C' + c.no + ' · ' + pname(c.product) + ' · ' + fmt(c.room) + ' L room';
                if (product && c.product && c.product !== product) {
                    text += c.stock === 0 ? ' (empty: will switch to ' + pname(product) + ')' : ' — holds ' + pname(c.product);
                }
            }
            const o = new Option(text, c.id);
            o.dataset.product = c.product || '';
            o.dataset.available = c.avail;
            o.dataset.room = c.room;
            o.dataset.empty = c.stock === 0 ? '1' : '0';
            o.disabled = !usable && String(c.id) !== String(keep);
            compSelect.add(o);
        });

        if (keep && Array.from(compSelect.options).some(o => o.value === String(keep))) {
            compSelect.value = String(keep);
        }
    }

    function currentProduct() {
        const so = sel(sourceSelect);
        if (!so || !so.value) { return ''; }
        if (isTruck(so)) {
            const co = sel(srcComp);
            return co && co.value ? (co.dataset.product || '') : '';
        }
        return so.dataset.product || '';
    }

    function destCompatible(o, product) {
        if (!o.value || !product) { return true; }
        if (isTruck(o)) {
            return (COMPS[o.value] || []).some(c => c.room > 0 && (!c.product || c.product === product || c.stock === 0));
        }
        const p = o.dataset.product;
        return !p || p === product || o.dataset.empty === '1';
    }

    // Only offer destinations that can take the product being moved.
    function filterDestTanks(product) {
        Array.from(destSelect.options).forEach(function (o) {
            if (!o.value) { return; }
            if (!o.dataset.base) { o.dataset.base = o.textContent.replace(/\s+/g, ' ').trim(); }
            const ok = destCompatible(o, product);
            o.disabled = !ok;
            o.textContent = ok ? o.dataset.base : o.dataset.base + ' — different product';
        });
        const cur = sel(destSelect);
        if (cur && cur.value && cur.disabled) { destSelect.value = ''; }
    }

    function showProduct(product) {
        if (!product) { banner.classList.add('d-none'); return; }
        banner.classList.remove('d-none');
        chip.textContent = pname(product);
        chip.style.background = COLORS[product] || '#6c757d';

        let msg = '';
        const dOpt = sel(destSelect);
        if (isTruck(dOpt)) {
            const dc = sel(dstComp);
            if (dc && dc.value && dc.dataset.product && dc.dataset.product !== product && dc.dataset.empty === '1') {
                msg = 'The destination compartment is empty and will switch to ' + pname(product) + '.';
            }
        } else if (dOpt && dOpt.value && dOpt.dataset.product && dOpt.dataset.product !== product && dOpt.dataset.empty === '1') {
            msg = 'The destination is empty and will switch to ' + pname(product) + '.';
        }
        note.textContent = msg;
    }

    function updateLiveValidation() {
        alertBox.classList.add('d-none');
        submitBtn.disabled = false;

        const sourceOpt = sel(sourceSelect);
        const destOpt = sel(destSelect);
        const srcCO = srcComp.disabled ? null : sel(srcComp);
        const dstCO = dstComp.disabled ? null : sel(dstComp);
        const qty = parseInt(qtyInput.value) || 0;

        let sourceAvail = 0;
        let destRemaining = 0;

        if (sourceOpt && sourceOpt.value) {
            if (isTruck(sourceOpt)) {
                sourceAvail = srcCO && srcCO.value ? (parseInt(srcCO.dataset.available) || 0) : 0;
            } else {
                sourceAvail = parseInt(sourceOpt.dataset.available) || 0;
            }
            sourceInfo.innerHTML = (isTruck(sourceOpt) && !(srcCO && srcCO.value))
                ? ''
                : `<span class="badge bg-primary-subtle text-primary">Available for transfer: ${fmt(sourceAvail)} L (${sourceOpt.dataset.warehouseName})</span>`;
        } else {
            sourceInfo.innerHTML = '';
        }

        if (destOpt && destOpt.value) {
            if (isTruck(destOpt)) {
                destRemaining = dstCO && dstCO.value ? (parseInt(dstCO.dataset.room) || 0) : 0;
            } else {
                destRemaining = parseInt(destOpt.dataset.capacityRemaining) || 0;
            }
            const maxCap = parseInt(destOpt.dataset.maxCapacity) || 0;
            destInfo.innerHTML = (isTruck(destOpt) && !(dstCO && dstCO.value))
                ? ''
                : `<span class="badge bg-success-subtle text-success">Remaining Room: ${fmt(destRemaining)} L</span> <span class="text-muted">(${fmt(maxCap)}L Max, ${destOpt.dataset.warehouseName})</span>`;
        } else {
            destInfo.innerHTML = '';
        }

        function block(msg) {
            alertText.textContent = msg;
            alertBox.classList.remove('d-none');
            submitBtn.disabled = true;
        }

        if (sourceOpt && destOpt && sourceOpt.value && destOpt.value) {
            const sourceWhId = sourceOpt.dataset.warehouseId;
            const destWhId = destOpt.dataset.warehouseId;

            if (sourceOpt.value === destOpt.value) {
                return block("Source tank and Destination tank cannot be the same.");
            }

            if (currentType === 'transfer' && sourceWhId !== destWhId) {
                return block(`Intra-Site Transfer Error: Source (${sourceOpt.dataset.warehouseName}) and Destination (${destOpt.dataset.warehouseName}) must be in the SAME warehouse. For cross-site fuel movement, use Borrow or Return.`);
            }

            if ((currentType === 'borrow' || currentType === 'return') && sourceWhId === destWhId) {
                return block(`Cross-Site ${currentType.toUpperCase()} Error: Source and Destination must be in DIFFERENT warehouses. For movements within the same site, switch to Transfer.`);
            }
        }

        if (isTruck(sourceOpt) && !(srcCO && srcCO.value)) {
            return block('Select the source compartment.');
        }
        if (isTruck(destOpt) && !(dstCO && dstCO.value)) {
            return block('Select the destination compartment.');
        }

        if (qty > 0 && sourceOpt && sourceOpt.value && qty > sourceAvail) {
            return block(`Transfer volume (${fmt(qty)}L) exceeds available fuel in the source (${fmt(sourceAvail)}L).`);
        }

        if (qty > 0 && destOpt && destOpt.value && qty > destRemaining) {
            return block(`Transfer volume (${fmt(qty)}L) exceeds remaining capacity of the destination (${fmt(destRemaining)}L).`);
        }
    }

    function refresh() {
        const product = currentProduct();
        filterDestTanks(product);
        fillComps(destSelect, dstComp, dstCompWrap, product, 'dest');
        showProduct(product);
        updateLiveValidation();
    }

    sourceSelect.addEventListener('change', function () {
        srcComp.value = '';
        fillComps(sourceSelect, srcComp, srcCompWrap, '', 'source');
        refresh();
    });
    srcComp.addEventListener('change', refresh);
    destSelect.addEventListener('change', function () {
        dstComp.value = '';
        refresh();
    });
    dstComp.addEventListener('change', function () {
        showProduct(currentProduct());
        updateLiveValidation();
    });
    qtyInput.addEventListener('input', updateLiveValidation);

    fillComps(sourceSelect, srcComp, srcCompWrap, '', 'source');
    refresh();
});
</script>
@endpush
@endsection