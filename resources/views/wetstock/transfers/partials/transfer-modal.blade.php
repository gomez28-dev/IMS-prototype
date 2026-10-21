{{-- Transfer / Borrow / Return pop-up. Variables: $formWarehouses, $tankMeta, $compData, $editConfig --}}
@php
    // Computed here so the script below only needs plain variables (Blade mis-parses nested brackets).
    $tmOld = collect(old())->only(['_modal_mode', '_modal_transfer', 'type', 'source_tank_id', 'source_compartment_id', 'destination_tank_id', 'destination_compartment_id', 'quantity', 'transfer_date', 'notes', 'modification_reason'])->all();
    $tmPrefill = ['source_tank_id' => request('source_tank_id'), 'destination_tank_id' => request('destination_tank_id')];
    $tmOpen = request('open');
    $tmToday = now('Asia/Manila')->format('Y-m-d');
    $tmProducts = \App\Models\Order::PRODUCT_TYPES;
    $tmStoreUrl = route('wetstock.transfers.store');
    $tmUpdateUrl = route('wetstock.transfers.update', '__ID__');
@endphp
<style>
    .tm-ico { width: 42px; height: 42px; border-radius: 12px; display: flex; align-items: center; justify-content: center; background: #fff1e8; color: #e8590c; font-size: 1.2rem; flex: none; }
    .tm-panel { border: 1px solid #e3e8ef; border-radius: 12px; padding: 14px; background: #fafbfc; height: 100%; }
    .tm-panel-title { font-size: 0.72rem; font-weight: 700; letter-spacing: 0.05em; text-transform: uppercase; color: #6b7686; margin-bottom: 8px; }
    #transferModal .form-label { font-size: 0.78rem; }
</style>

<div class="modal fade" id="transferModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg modal-dialog-scrollable">
        <div class="modal-content border-0 shadow-lg rounded-4">
            <form method="POST" action="{{ route('wetstock.transfers.store') }}" id="transferForm">
                @csrf
                <input type="hidden" name="_modal_mode" id="tm_mode" value="create">
                <input type="hidden" name="_modal_transfer" id="tm_transfer_id" value="">
                <input type="hidden" name="type" id="transfer_type" value="transfer">

                <div class="modal-header border-0 pb-2 align-items-start">
                    <div class="d-flex align-items-center gap-3">
                        <span class="tm-ico"><i class="bi bi-arrow-left-right"></i></span>
                        <div>
                            <h5 class="modal-title fw-bold mb-0" id="tm_title">Record Intra-Site Transfer</h5>
                            <div class="text-muted small" id="tm_hint"></div>
                        </div>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>

                <div class="modal-body pt-2">
                    <div class="btn-group btn-group-sm w-100 mb-3" role="group" id="tm_types">
                        <button type="button" class="btn btn-outline-primary" data-type="transfer">Transfer</button>
                        <button type="button" class="btn btn-outline-primary" data-type="borrow">Borrow</button>
                        <button type="button" class="btn btn-outline-success" data-type="return">Return</button>
                    </div>

                    <div class="alert alert-warning border-0 rounded-3 py-2 px-3 small mb-3 d-none" id="tm_edit_notice">
                        <i class="bi bi-info-circle-fill me-1"></i> <strong>Approval Notice:</strong> modifying an existing transfer creates a <strong>Modification Request</strong> for Operations Manager review. The record is not changed until it is approved.
                    </div>

                    <div class="row g-3">
                        {{-- ===== FROM ===== --}}
                        <div class="col-md-6">
                            <div class="tm-panel">
                                <div class="tm-panel-title"><i class="bi bi-box-arrow-up-right text-danger me-1"></i>From</div>
                                <select name="source_tank_id" id="source_tank_id" class="form-select" required>
                                    <option value="">Select source tank or truck...</option>
                                    @foreach ($formWarehouses as $wh)
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
                                                    data-blocked="{{ $m['blocked'] ? 1 : 0 }}">
                                                    {{ $tank->name }} ({{ ucfirst($tank->category) }}) — @if ($m['truck'])pick a compartment @else{{ number_format($m['avail']) }}L {{ $m['product_name'] }} avail @endif
                                                    {{ $m['bad'] > 0 ? ' [' . number_format($m['bad']) . 'L contaminated' . ($m['blocked'] ? ' - BLOCKED' : '') . ']' : '' }}
                                                </option>
                                            @endforeach
                                        </optgroup>
                                    @endforeach
                                </select>

                                <div id="source_comp_wrap" class="mt-2 d-none">
                                    <label for="source_compartment_id" class="form-label fw-semibold text-dark mb-1">Source compartment</label>
                                    <select name="source_compartment_id" id="source_compartment_id" class="form-select form-select-sm" data-selected="" disabled></select>
                                </div>
                                <div id="source_tank_info" class="mt-2 small text-muted"></div>
                            </div>
                        </div>

                        {{-- ===== TO ===== --}}
                        <div class="col-md-6">
                            <div class="tm-panel">
                                <div class="tm-panel-title"><i class="bi bi-box-arrow-in-down-left text-success me-1"></i>To</div>
                                <select name="destination_tank_id" id="destination_tank_id" class="form-select" required>
                                    <option value="">Select destination tank or truck...</option>
                                    @foreach ($formWarehouses as $wh)
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
                                                    data-max-capacity="{{ $tank->max_capacity }}">
                                                    {{ $tank->name }} ({{ ucfirst($tank->category) }}) — @if ($m['truck'])pick a compartment @else{{ number_format($m['room']) }}L room @if ($m['product_name'])· {{ $m['product_name'] }}@endif @endif
                                                </option>
                                            @endforeach
                                        </optgroup>
                                    @endforeach
                                </select>

                                <div id="dest_comp_wrap" class="mt-2 d-none">
                                    <label for="destination_compartment_id" class="form-label fw-semibold text-dark mb-1">Destination compartment</label>
                                    <select name="destination_compartment_id" id="destination_compartment_id" class="form-select form-select-sm" data-selected="" disabled></select>
                                </div>
                                <div id="dest_tank_info" class="mt-2 small text-muted"></div>
                            </div>
                        </div>
                    </div>

                    <div id="product_banner" class="d-none mt-3 small">
                        <span class="text-muted me-1">Product being moved:</span>
                        <span id="product_chip" class="badge rounded-pill text-white"></span>
                        <span id="product_note" class="text-muted ms-2"></span>
                    </div>

                    <div class="row g-3 mt-1">
                        <div class="col-md-6">
                            <label for="transfer_date" class="form-label fw-semibold text-secondary">Transaction date</label>
                            <input type="date" name="transfer_date" id="transfer_date" class="form-control" required>
                        </div>
                        <div class="col-md-6">
                            <label for="quantity" class="form-label fw-semibold text-secondary">Volume (liters)</label>
                            <div class="input-group">
                                <input type="number" name="quantity" id="quantity" class="form-control font-monospace" min="1" placeholder="e.g. 10000" required>
                                <span class="input-group-text">L</span>
                            </div>
                        </div>
                        <div class="col-12">
                            <label for="notes" class="form-label fw-semibold text-secondary">Notes / trip ticket / reference</label>
                            <textarea name="notes" id="notes" class="form-control" rows="2" placeholder="Optional notes, driver name, trip ticket #..."></textarea>
                        </div>
                        <div class="col-12 d-none" id="tm_reason_wrap">
                            <label for="modification_reason" class="form-label fw-semibold text-dark">Reason for modification</label>
                            <textarea name="modification_reason" id="modification_reason" class="form-control" rows="2" placeholder="e.g. Quantity correction per actual tank dip" disabled></textarea>
                        </div>
                    </div>

                    <div id="transfer_alert" class="alert alert-warning d-none small mt-3 mb-0">
                        <i class="bi bi-exclamation-triangle-fill me-2"></i><span id="transfer_alert_text"></span>
                    </div>
                </div>

                <div class="modal-footer border-0 pt-0">
                    <button type="button" class="btn btn-light border" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary-custom px-4" id="submitBtn">
                        <i class="bi bi-check2-circle me-1"></i><span id="tm_submit_label">Save Record</span>
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const COMPS = @json($compData);
    const PRODUCTS = @json($tmProducts);
    const COLORS = { U: '#22a45d', P: '#e5383b', D: '#f2a900' };
    const STORE_URL = @json($tmStoreUrl);
    const UPDATE_URL = @json($tmUpdateUrl);
    const TODAY = @json($tmToday);
    const EDIT = @json($editConfig);
    const OPEN = @json($tmOpen);
    const PREFILL = @json($tmPrefill);
    const OLD = @json($tmOld);

    const TYPES = {
        transfer: { title: 'Record Intra-Site Transfer', hint: 'Source and destination must be in the same warehouse.' },
        borrow: { title: 'Record Cross-Site Borrowing', hint: 'Source and destination must be in different warehouses.' },
        return: { title: 'Record Cross-Site Stock Return', hint: 'Source and destination must be in different warehouses.' }
    };

    const modalEl = document.getElementById('transferModal');
    const form = document.getElementById('transferForm');
    const modeInput = document.getElementById('tm_mode');
    const idInput = document.getElementById('tm_transfer_id');
    const typeInput = document.getElementById('transfer_type');
    const titleEl = document.getElementById('tm_title');
    const hintEl = document.getElementById('tm_hint');
    const typesBox = document.getElementById('tm_types');
    const editNotice = document.getElementById('tm_edit_notice');
    const reasonWrap = document.getElementById('tm_reason_wrap');
    const reasonInput = document.getElementById('modification_reason');
    const submitLabel = document.getElementById('tm_submit_label');
    const sourceSelect = document.getElementById('source_tank_id');
    const destSelect = document.getElementById('destination_tank_id');
    const srcComp = document.getElementById('source_compartment_id');
    const dstComp = document.getElementById('destination_compartment_id');
    const srcCompWrap = document.getElementById('source_comp_wrap');
    const dstCompWrap = document.getElementById('dest_comp_wrap');
    const qtyInput = document.getElementById('quantity');
    const dateInput = document.getElementById('transfer_date');
    const notesInput = document.getElementById('notes');
    const alertBox = document.getElementById('transfer_alert');
    const alertText = document.getElementById('transfer_alert_text');
    const submitBtn = document.getElementById('submitBtn');
    const sourceInfo = document.getElementById('source_tank_info');
    const destInfo = document.getElementById('dest_tank_info');
    const banner = document.getElementById('product_banner');
    const chip = document.getElementById('product_chip');
    const note = document.getElementById('product_note');

    let currentType = 'transfer';
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
                : `<span class="badge bg-primary-subtle text-primary">Available: ${fmt(sourceAvail)} L</span> <span class="text-muted">${sourceOpt.dataset.warehouseName}</span>`;
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
                : `<span class="badge bg-success-subtle text-success">Room: ${fmt(destRemaining)} L</span> <span class="text-muted">${destOpt.dataset.warehouseName} · ${fmt(maxCap)} L max</span>`;
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
                return block('Source and destination cannot be the same tank or truck.');
            }
            if (currentType === 'transfer' && sourceWhId !== destWhId) {
                return block(`Intra-Site Transfer Error: Source (${sourceOpt.dataset.warehouseName}) and Destination (${destOpt.dataset.warehouseName}) must be in the SAME warehouse. For cross-site fuel movement, use Borrow or Return.`);
            }
            if ((currentType === 'borrow' || currentType === 'return') && sourceWhId === destWhId) {
                return block(`Cross-Site ${currentType.toUpperCase()} Error: Source and Destination must be in DIFFERENT warehouses. For movements within the same site, switch to Transfer.`);
            }
        }

        if (sourceOpt && sourceOpt.value && sourceOpt.dataset.blocked === '1') {
            return block('The source is fully contaminated and cannot be transferred.');
        }
        if (isTruck(sourceOpt) && !(srcCO && srcCO.value)) {
            return block('Select the source compartment.');
        }
        if (isTruck(destOpt) && !(dstCO && dstCO.value)) {
            return block('Select the destination compartment.');
        }
        if (qty > 0 && sourceOpt && sourceOpt.value && qty > sourceAvail) {
            return block(`Volume (${fmt(qty)} L) is more than the source has available (${fmt(sourceAvail)} L).`);
        }
        if (qty > 0 && destOpt && destOpt.value && qty > destRemaining) {
            return block(`Volume (${fmt(qty)} L) is more than the destination has room for (${fmt(destRemaining)} L).`);
        }
    }

    function refresh() {
        const product = currentProduct();
        filterDestTanks(product);
        fillComps(destSelect, dstComp, dstCompWrap, product, 'dest');
        showProduct(product);
        updateLiveValidation();
    }

    function setType(t) {
        if (!TYPES[t]) { t = 'transfer'; }
        currentType = t;
        typeInput.value = t;
        titleEl.textContent = TYPES[t].title;
        hintEl.textContent = TYPES[t].hint;
        typesBox.querySelectorAll('button').forEach(b => b.classList.toggle('active', b.dataset.type === t));
        updateLiveValidation();
    }

    function openTransfer(cfg) {
        const edit = cfg.mode === 'edit';
        form.action = edit ? UPDATE_URL.replace('__ID__', cfg.id) : STORE_URL;
        modeInput.value = edit ? 'edit' : 'create';
        idInput.value = edit ? cfg.id : '';
        typesBox.classList.toggle('d-none', edit);
        editNotice.classList.toggle('d-none', !edit);
        reasonWrap.classList.toggle('d-none', !edit);
        reasonInput.disabled = !edit;
        submitLabel.textContent = edit ? 'Submit Modification Request' : 'Save Record';

        Array.from(destSelect.options).forEach(o => { o.disabled = false; });
        sourceSelect.value = cfg.source_tank_id || '';
        srcComp.innerHTML = '';
        srcComp.dataset.selected = cfg.source_comp || '';
        dstComp.innerHTML = '';
        dstComp.dataset.selected = cfg.dest_comp || '';
        destSelect.value = cfg.destination_tank_id || '';
        qtyInput.value = cfg.quantity || '';
        dateInput.value = cfg.date || TODAY;
        notesInput.value = cfg.notes || '';
        reasonInput.value = cfg.reason || '';

        setType(cfg.type || 'transfer');
        if (edit) { hintEl.textContent = 'Modifying ' + cfg.number + '. ' + TYPES[currentType].hint; }

        fillComps(sourceSelect, srcComp, srcCompWrap, '', 'source');
        refresh();
        bootstrap.Modal.getOrCreateInstance(modalEl).show();
    }

    // ---- wiring
    typesBox.querySelectorAll('button').forEach(b => b.addEventListener('click', () => setType(b.dataset.type)));

    sourceSelect.addEventListener('change', function () {
        srcComp.dataset.selected = '';
        srcComp.value = '';
        fillComps(sourceSelect, srcComp, srcCompWrap, '', 'source');
        refresh();
    });
    srcComp.addEventListener('change', refresh);
    destSelect.addEventListener('change', function () {
        dstComp.dataset.selected = '';
        dstComp.value = '';
        refresh();
    });
    dstComp.addEventListener('change', function () {
        showProduct(currentProduct());
        updateLiveValidation();
    });
    qtyInput.addEventListener('input', updateLiveValidation);

    // stop a double-click from recording the transfer twice
    form.addEventListener('submit', function () {
        setTimeout(function () { submitBtn.disabled = true; }, 0);
    });

    document.addEventListener('click', function (e) {
        const b = e.target.closest('.js-open-transfer');
        if (!b) { return; }
        if (b.dataset.mode === 'edit') {
            openTransfer({
                mode: 'edit',
                id: b.dataset.id,
                number: b.dataset.number,
                type: b.dataset.type,
                source_tank_id: b.dataset.sourceTank,
                source_comp: b.dataset.sourceComp,
                destination_tank_id: b.dataset.destTank,
                dest_comp: b.dataset.destComp,
                quantity: b.dataset.quantity,
                date: b.dataset.date,
                notes: b.dataset.notes
            });
        } else {
            openTransfer({ mode: 'create', type: b.dataset.type });
        }
    });

    // ---- open on load: after a rejected save, from an old /create or /edit link
    if (OLD && OLD._modal_mode) {
        openTransfer({
            mode: OLD._modal_mode,
            id: OLD._modal_transfer,
            number: '',
            type: OLD.type,
            source_tank_id: OLD.source_tank_id,
            source_comp: OLD.source_compartment_id,
            destination_tank_id: OLD.destination_tank_id,
            dest_comp: OLD.destination_compartment_id,
            quantity: OLD.quantity,
            date: OLD.transfer_date,
            notes: OLD.notes,
            reason: OLD.modification_reason
        });
    } else if (EDIT) {
        openTransfer(EDIT);
    } else if (OPEN) {
        openTransfer({ mode: 'create', type: OPEN, source_tank_id: PREFILL.source_tank_id, destination_tank_id: PREFILL.destination_tank_id });
    }
});
</script>