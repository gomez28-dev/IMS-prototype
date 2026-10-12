{{-- "Manage Tanks & Tankers" pop-up for one warehouse. Variables: $warehouse, $units (all units, active or not), $canEdit --}}
@php
    $rows = $units->sortBy([['tanker', 'asc'], ['name', 'asc']])->values();
    $groups = [
        'depot' => ['Depot tanks', $rows->where('tanker', false)],
        'tanker' => ['Tanker trucks', $rows->where('tanker', true)],
    ];
    $pcm = ['U' => '#22a45d', 'P' => '#e5383b', 'D' => '#f2a900'];

    // Floating add / edit card: products + values to restore after a failed save
    $prods = $products ?? \App\Models\Order::PRODUCT_TYPES;
    $editId = old('_edit_tank');
    $reAdd = (string) old('_add_tank') === (string) $warehouse->id;
    $reEdit = $editId && $units->contains('id', (int) $editId);
    $re = $reAdd || $reEdit;
    $ov = fn ($k, $d = '') => $re ? old($k, $d) : $d;
    $er = fn ($k) => $re ? $errors->first($k) : '';
    $atRows = $re ? (array) old('compartments', []) : [];
    if (empty($atRows)) { $atRows = [['id' => '', 'capacity' => '', 'product_type' => '']]; }
@endphp

@once
<style>
    .ws-list .modal-header { padding:14px 18px 8px; }
    .ws-list .modal-body { padding:0 18px 14px; }
    .ws-list .modal-content { min-height:min(560px, calc(100vh - 3.5rem)); }
    .ws-list-table { width:100%; border-collapse:separate; border-spacing:0; font-size:.82rem; }
    .ws-list-table th { font-size:.68rem; font-weight:600; color:#6b7686; text-transform:none; padding:6px 10px; border-bottom:1px solid #e3e8ef; background:#fff; position:sticky; top:0; z-index:1; white-space:nowrap; }
    .ws-list-table td { padding:8px 10px; border-bottom:1px solid #f0f3f7; vertical-align:middle; }
    .ws-list-table tr.ws-grp td { background:#f6f8fb; font-size:.7rem; font-weight:700; color:#4b5563; padding:5px 10px; border-bottom:1px solid #e3e8ef; }
    .ws-list-table tbody tr:not(.ws-grp):hover td { background:#fafbfd; }
    .ws-list-table tr.is-off td { opacity:.55; }
    .ws-list-table tr.is-off td:last-child { opacity:1; }
    .ws-list-table tr.is-bad td:first-child { box-shadow:inset 3px 0 0 #d62839; }
    .ws-list-table tr.is-warn td:first-child { box-shadow:inset 3px 0 0 #f0b429; }
    .ws-u-ico { width:28px; height:28px; border-radius:8px; display:inline-flex; align-items:center; justify-content:center; background:#eef3ff; color:#2a5bd7; font-size:.85rem; flex:none; }
    .ws-u-ico.t { background:#e8f6fb; color:#0b7a99; }
    .ws-u-name { font-weight:600; color:#14213d; line-height:1.2; }
    .ws-u-sub { font-size:.68rem; color:#6b7686; }
    .ws-u-bar { height:5px; border-radius:99px; background:#eef1f5; overflow:hidden; min-width:70px; }
    .ws-u-bar > span { display:block; height:100%; border-radius:99px; }
    .ws-st { display:inline-flex; align-items:center; gap:5px; font-size:.72rem; color:#4b5563; white-space:nowrap; }
    .ws-st i { width:7px; height:7px; border-radius:50%; background:#22a45d; display:inline-block; }
    .ws-st.off i { background:#9aa5b4; }
    .ws-list .btn-sm { font-size:.74rem; padding:.2rem .6rem; }
    .ws-list .btn-icon { padding:.2rem .45rem; }

    /* floating cards (confirmation + add/edit tank) sit on top of this pop-up */
    .ws-confirm { position:absolute; inset:0; z-index:5; display:flex; align-items:center; justify-content:center; background:rgba(20,33,61,.38); border-radius:inherit; animation:wsFade .15s ease; }
    .ws-confirm.d-none { display:none !important; }
    .ws-confirm-card { background:#fff; border-radius:14px; padding:20px 22px 16px; width:min(340px, 88%); text-align:center; box-shadow:0 12px 36px rgba(20,33,61,.28); animation:wsPop .18s ease; }
    .ws-confirm-ico { width:42px; height:42px; border-radius:50%; margin:0 auto 10px; display:flex; align-items:center; justify-content:center; background:#fff3cd; color:#a16207; font-size:1.2rem; }
    .ws-confirm-ico.is-danger { background:#fde8ea; color:#b4232f; }
    .ws-confirm-card .wc-title { color:#14213d; font-size:.95rem; }

    .ws-addtank { position:absolute; inset:0; z-index:6; display:flex; align-items:center; justify-content:center; padding:14px; background:rgba(20,33,61,.42); border-radius:inherit; animation:wsFade .18s ease; }
    .ws-addtank.d-none { display:none !important; }
    .ws-addtank-card { background:#fff; border-radius:16px; width:min(520px, 100%); max-height:100%; overflow-y:auto; padding:20px 22px 18px; box-shadow:0 14px 40px rgba(20,33,61,.3); animation:wsPop .2s ease; }
    .ws-addtank.is-closing { animation:wsFadeOut .17s ease forwards; }
    .ws-addtank.is-closing .ws-addtank-card { animation:wsPopOut .17s ease forwards; }
    .ws-addtank .form-label { font-size:.78rem; margin-bottom:3px; }
    .ws-addtank .form-control, .ws-addtank .form-select { font-size:.86rem; }
    .ws-addtank .at-no { min-width:38px; justify-content:center; font-size:.8rem; }

    @keyframes wsFade { from { opacity:0; } to { opacity:1; } }
    @keyframes wsFadeOut { to { opacity:0; } }
    @keyframes wsPop { from { opacity:0; transform:translateY(8px) scale(.97); } to { opacity:1; transform:none; } }
    @keyframes wsPopOut { to { opacity:0; transform:translateY(8px) scale(.97); } }
</style>
<script>
    /* ---------- confirmation card ---------- */
    document.addEventListener('click', function (e) {
        var box;
        var t = e.target.closest('.js-confirm');
        if (t) {
            box = t.closest('.ws-list').querySelector('.ws-confirm');
            box.querySelector('.wc-title').textContent = t.dataset.title;
            box.querySelector('.wc-text').textContent = t.dataset.text;
            var ok = box.querySelector('.wc-ok');
            ok.disabled = false;
            ok.textContent = t.dataset.ok;
            ok.className = 'btn btn-sm wc-ok ' + (t.dataset.tone === 'danger' ? 'btn-danger' : 'btn-success');
            var ico = box.querySelector('.ws-confirm-ico');
            ico.classList.toggle('is-danger', t.dataset.icon === 'trash');
            ico.innerHTML = '<i class="bi ' + (t.dataset.icon === 'trash' ? 'bi-trash3' : 'bi-exclamation-triangle') + '"></i>';
            box._form = t.closest('form');
            box.classList.remove('d-none');
            ok.focus();
            return;
        }
        box = e.target.closest('.ws-confirm');
        if (!box) { return; }
        if (e.target.closest('.wc-ok')) {
            e.target.closest('.wc-ok').disabled = true;
            if (box._form) { if (box._form.requestSubmit) { box._form.requestSubmit(); } else { box._form.submit(); } }
        } else if (e.target.closest('.wc-cancel') || e.target === box) {
            box.classList.add('d-none');
        }
    });

    /* ---------- floating add / edit tank card ---------- */
    function wsAtToggle(card) {
        var tanker = card.querySelector('.at-cat').value === 'tanker';
        var d = card.querySelector('.at-depot'), k = card.querySelector('.at-tanker');
        d.style.display = tanker ? 'none' : '';
        k.style.display = tanker ? '' : 'none';
        d.querySelectorAll('input,select').forEach(function (el) { el.disabled = tanker; });
        k.querySelectorAll('input,select').forEach(function (el) { el.disabled = !tanker; });
        wsAtRenumber(card);
    }
    function wsAtRenumber(card) {
        var total = 0;
        card.querySelectorAll('.at-row').forEach(function (row, i) {
            row.querySelector('.at-no').textContent = 'C' + (i + 1);
            total += parseInt(row.querySelector('.at-cap').value || 0, 10);
        });
        card.querySelector('.at-sum').textContent = total.toLocaleString('en-US');
    }
    // 'add' posts to the store route; 'edit' posts to the update route of one unit
    function wsAtMode(card, mode) {
        var edit = mode === 'edit';
        var form = card.querySelector('form');
        card.dataset.mode = mode;
        form.action = edit ? card.dataset.updateUrl.replace('__ID__', card._editId || '') : card.dataset.storeUrl;
        form.querySelector('[name=_add_tank]').disabled = edit;
        var et = form.querySelector('[name=_edit_tank]');
        et.disabled = !edit;
        if (!edit) { et.value = ''; }
        card.querySelector('.at-title').textContent = edit ? 'Edit Tank / Tanker' : 'Add Tank / Tanker';
        card.querySelector('.at-catwrap').classList.toggle('d-none', edit);
        var btn = form.querySelector('button[type=submit]');
        if (btn) { btn.disabled = false; btn.textContent = edit ? 'Save changes' : 'Save Tank'; }
    }
    function wsAtClear(card) {
        var form = card.querySelector('form');
        form.querySelectorAll('input[type=text],input[type=number],textarea').forEach(function (el) { el.value = ''; });
        form.querySelectorAll('.at-rows input[type=hidden]').forEach(function (el) { el.value = ''; });
        form.querySelectorAll('select').forEach(function (el) { el.selectedIndex = 0; });
        form.querySelectorAll('.is-invalid').forEach(function (el) { el.classList.remove('is-invalid'); });
        form.querySelectorAll('.invalid-feedback, .at-err').forEach(function (el) { el.remove(); });
        var rows = card.querySelectorAll('.at-row');
        for (var i = 1; i < rows.length; i++) { rows[i].remove(); }
        card._editId = '';
        wsAtMode(card, 'add');
        wsAtToggle(card);
    }
    function wsShowCard(card) {
        card.classList.remove('d-none', 'is-closing');
        wsAtToggle(card);
        var first = card.querySelector('[name=name]');
        if (first) { setTimeout(function () { first.focus(); }, 60); }
    }
    function wsOpenAdd(list) {
        var card = list.querySelector('.ws-addtank');
        if (card) { wsShowCard(card); }
    }
    function wsOpenEdit(btn) {
        var list = btn.closest('.ws-list');
        var card = list && list.querySelector('.ws-addtank');
        if (!card) { return; }
        wsAtClear(card);
        card._editId = btn.dataset.id;
        wsAtMode(card, 'edit');
        var form = card.querySelector('form');
        form.querySelector('[name=_edit_tank]').value = btn.dataset.id;
        form.querySelector('[name=name]').value = btn.dataset.name || '';
        form.querySelector('.at-cat').value = btn.dataset.cat;
        form.querySelector('.at-depot [name=product_type]').value = btn.dataset.product || '';
        form.querySelector('[name=max_capacity]').value = btn.dataset.cat === 'depot' ? (btn.dataset.cap || '') : '';
        form.querySelector('[name=remarks]').value = btn.dataset.note || '';
        if (btn.dataset.cat === 'tanker') {
            var comps = [];
            try { comps = JSON.parse(btn.dataset.comps || '[]'); } catch (er) {}
            if (!comps.length) { comps = [{ id: '', capacity: '', product_type: '' }]; }
            var rows = card.querySelector('.at-rows');
            var tpl = card.querySelector('.at-tpl').innerHTML;
            rows.innerHTML = '';
            comps.forEach(function (c, i) {
                rows.insertAdjacentHTML('beforeend', tpl.replace(/__i__/g, i));
                var row = rows.lastElementChild;
                row.querySelector('input[type=hidden]').value = c.id || '';
                row.querySelector('.at-cap').value = c.capacity || '';
                row.querySelector('select').value = c.product_type || '';
            });
            card._next = 1000 + comps.length;
        }
        wsShowCard(card);
    }
    function wsCloseAdd(card) {
        if (!card || card.classList.contains('d-none') || card.classList.contains('is-closing')) { return; }
        card.classList.add('is-closing');
        setTimeout(function () {
            card.classList.add('d-none');
            card.classList.remove('is-closing');
            wsAtClear(card);
        }, 170);
    }

    document.addEventListener('click', function (e) {
        // open from any "Add tank / tanker" trigger (also works while the list pop-up is still closed)
        var o = e.target.closest('.js-open-add');
        if (o) {
            e.preventDefault();
            var list = document.getElementById('units-' + o.dataset.wh);
            if (!list) { return; }
            if (list.classList.contains('show')) { wsOpenAdd(list); }
            else {
                list.addEventListener('shown.bs.modal', function () { wsOpenAdd(list); }, { once: true });
                bootstrap.Modal.getOrCreateInstance(list).show();
            }
            return;
        }
        var ed = e.target.closest('.js-edit-unit');
        if (ed) { e.preventDefault(); wsOpenEdit(ed); return; }

        var card = e.target.closest('.ws-addtank');
        if (!card) { return; }
        if (e.target.closest('.at-close') || e.target === card) { wsCloseAdd(card); return; }
        if (e.target.closest('.at-add')) {
            var tpl = card.querySelector('.at-tpl');
            var rows = card.querySelector('.at-rows');
            card._next = (card._next || 1000) + 1;
            rows.insertAdjacentHTML('beforeend', tpl.innerHTML.replace(/__i__/g, card._next));
            wsAtRenumber(card);
            return;
        }
        var del = e.target.closest('.at-del');
        if (del) {
            var rws = card.querySelector('.at-rows');
            if (rws.children.length > 1) { del.closest('.at-row').remove(); wsAtRenumber(card); }
        }
    });
    document.addEventListener('change', function (e) {
        if (e.target.classList.contains('at-cat')) { wsAtToggle(e.target.closest('.ws-addtank')); }
    });
    document.addEventListener('input', function (e) {
        if (e.target.classList.contains('at-cap')) { wsAtRenumber(e.target.closest('.ws-addtank')); }
    });
    document.addEventListener('submit', function (e) {
        if (!e.target.classList.contains('at-form')) { return; }
        var btn = e.target.querySelector('button[type=submit]');
        setTimeout(function () { if (btn) { btn.disabled = true; btn.textContent = 'Saving...'; } }, 0);
    });
    // Esc closes only the floating card (or confirmation), not the pop-up behind it
    document.addEventListener('keydown', function (e) {
        if (e.key !== 'Escape') { return; }
        var open = document.querySelector('.ws-list.show .ws-addtank:not(.d-none):not(.is-closing)');
        if (open) { e.stopPropagation(); e.preventDefault(); wsCloseAdd(open); return; }
        var conf = document.querySelector('.ws-list.show .ws-confirm:not(.d-none)');
        if (conf) { e.stopPropagation(); e.preventDefault(); conf.classList.add('d-none'); }
    }, true);

    // reopen the card with the typed values when a save failed
    document.addEventListener('shown.bs.modal', function (e) {
        var c = e.target.querySelector && e.target.querySelector('.ws-addtank[data-reopen="1"]');
        if (c) { c.dataset.reopen = '0'; wsOpenAdd(e.target); }
    });

    // never leave a floating card open when the pop-up closes
    document.addEventListener('hidden.bs.modal', function (e) {
        if (!e.target.querySelector) { return; }
        var b = e.target.querySelector('.ws-confirm');
        if (b) { b.classList.add('d-none'); }
        var a = e.target.querySelector('.ws-addtank');
        if (a && !a.classList.contains('d-none')) { a.classList.add('d-none'); a.classList.remove('is-closing'); wsAtClear(a); }
    });
</script>
@endonce

<div class="modal fade ws-list" id="units-{{ $warehouse->id }}" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg modal-dialog-scrollable">
        <div class="modal-content border-0 shadow-lg rounded-4">
            <div class="modal-header border-0 align-items-center gap-2">
                <div class="flex-grow-1">
                    <h5 class="modal-title fw-bold mb-0" style="font-size:1rem;">Tanks &amp; tankers</h5>
                    <div class="text-muted" style="font-size:.74rem;">{{ $warehouse->name }} Warehouse · {{ $rows->count() }} {{ \Illuminate\Support\Str::plural('unit', $rows->count()) }}</div>
                </div>
                @if ($canEdit)
                    <button type="button" class="btn btn-primary-custom btn-sm js-open-add" data-wh="{{ $warehouse->id }}"><i class="bi bi-plus-lg me-1"></i>Add tank / tanker</button>
                @endif
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>

            <div class="modal-body">
                @if ($rows->isEmpty())
                    <div class="text-center py-5">
                        <i class="bi bi-box-seam display-6 text-muted d-block mb-2"></i>
                        <p class="text-muted small mb-3">No tanks or tankers in {{ $warehouse->name }} yet.</p>
                        @if ($canEdit)
                            <button type="button" class="btn btn-primary-custom btn-sm js-open-add" data-wh="{{ $warehouse->id }}"><i class="bi bi-plus-lg me-1"></i>Add the first one</button>
                        @endif
                    </div>
                @else
                    <div class="table-responsive">
                        <table class="ws-list-table">
                            <thead>
                                <tr>
                                    <th>Unit</th>
                                    <th style="min-width:130px;">Stock</th>
                                    <th class="text-end">Sellable</th>
                                    <th>Status</th>
                                    @if ($canEdit)<th class="text-end"></th>@endif
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($groups as [$label, $list])
                                    @if ($list->isNotEmpty())
                                        <tr class="ws-grp"><td colspan="{{ $canEdit ? 5 : 4 }}">{{ $label }} · {{ $list->count() }}</td></tr>
                                        @foreach ($list as $u)
                                            @php
                                                $compJson = json_encode($u->comps->map(fn ($c) => ['id' => $c->id, 'capacity' => $c->cap, 'product_type' => $c->product])->values()->all());
                                                $holdsStock = $u->stock > 0 || $u->hold > 0;
                                            @endphp
                                            <tr class="{{ !$u->active ? 'is-off' : ($u->fully_bad ? 'is-bad' : ($u->bad ? 'is-warn' : '')) }}">
                                                <td>
                                                    <div class="d-flex align-items-center gap-2">
                                                        <span class="ws-u-ico {{ $u->tanker ? 't' : '' }}"><i class="bi {{ $u->tanker ? 'bi-truck' : 'bi-box-seam' }}"></i></span>
                                                        <div>
                                                            <div class="ws-u-name">{{ $u->name }}</div>
                                                            <div class="ws-u-sub">
                                                                @if ($u->tanker)
                                                                    {{ $u->comps->count() }} {{ \Illuminate\Support\Str::plural('compartment', $u->comps->count()) }}
                                                                @else
                                                                    {{ $u->product_name ?: 'No product set' }}
                                                                @endif
                                                            </div>
                                                        </div>
                                                    </div>
                                                </td>
                                                <td>
                                                    <div class="d-flex justify-content-between ws-u-sub mb-1">
                                                        <span class="fw-semibold text-dark">{{ number_format($u->stock) }} L</span>
                                                        <span>of {{ number_format($u->cap) }}</span>
                                                    </div>
                                                    <div class="ws-u-bar"><span style="width:{{ $u->pct }}%;background:{{ $u->tanker ? '#64748b' : ($pcm[$u->product] ?? '#9aa5b4') }};"></span></div>
                                                </td>
                                                <td class="text-end">
                                                    <div class="fw-semibold {{ $u->bad ? 'text-danger' : 'text-success' }}">{{ number_format($u->sellable) }} L</div>
                                                    @if ($u->bad)<div class="ws-u-sub text-danger">⚠ {{ number_format($u->bad) }} L contaminated</div>@endif
                                                </td>
                                                <td><span class="ws-st {{ $u->active ? '' : 'off' }}"><i></i>{{ $u->active ? 'Active' : 'Deactivated' }}</span></td>
                                                @if ($canEdit)
                                                    <td class="text-end">
                                                        <div class="d-flex justify-content-end gap-1">
                                                            @if ($u->active)
                                                                {{-- Manage includes the Edit tab --}}
                                                                <button type="button" class="btn btn-sm btn-outline-primary js-open-unit" data-unit="unit-{{ $u->id }}">Manage</button>
                                                            @else
                                                                {{-- deactivated units have no Manage pop-up: Edit opens the floating card --}}
                                                                <button type="button" class="btn btn-sm btn-outline-secondary js-edit-unit"
                                                                    data-id="{{ $u->id }}"
                                                                    data-name="{{ $u->name }}"
                                                                    data-cat="{{ $u->tanker ? 'tanker' : 'depot' }}"
                                                                    data-product="{{ $u->product }}"
                                                                    data-cap="{{ $u->cap }}"
                                                                    data-note="{{ $u->note }}"
                                                                    data-comps="{{ $compJson }}">Edit</button>
                                                            @endif
                                                            <form method="POST" action="{{ route('wetstock.tanks.toggle-active', $u->id) }}" class="d-inline">
                                                                @csrf
                                                                <button type="button" class="btn btn-sm btn-icon js-confirm {{ $u->active ? 'btn-outline-secondary' : 'btn-outline-success' }}" title="{{ $u->active ? 'Deactivate' : 'Reactivate' }}"
                                                                    data-title="{{ $u->active ? 'Deactivate' : 'Reactivate' }} {{ $u->name }}?"
                                                                    data-text="{{ $u->active ? 'It will no longer appear on the dashboard until you reactivate it.' : 'It will appear on the dashboard again.' }}"
                                                                    data-ok="{{ $u->active ? 'Deactivate' : 'Reactivate' }}"
                                                                    data-tone="{{ $u->active ? 'danger' : 'success' }}">
                                                                    <i class="bi {{ $u->active ? 'bi-power' : 'bi-arrow-counterclockwise' }}"></i>
                                                                </button>
                                                            </form>
                                                            <form method="POST" action="{{ route('wetstock.tanks.destroy', $u->id) }}" class="d-inline">
                                                                @csrf
                                                                @if ($holdsStock)
                                                                    <button type="button" class="btn btn-sm btn-icon btn-outline-secondary" disabled style="opacity:.45;" title="Empty this unit before deleting it"><i class="bi bi-trash3"></i></button>
                                                                @else
                                                                    <button type="button" class="btn btn-sm btn-icon btn-outline-danger js-confirm" title="Delete"
                                                                        data-title="Delete {{ $u->name }}?"
                                                                        data-text="This permanently removes it from {{ $warehouse->name }}. This can't be undone."
                                                                        data-ok="Delete"
                                                                        data-tone="danger"
                                                                        data-icon="trash">
                                                                        <i class="bi bi-trash3"></i>
                                                                    </button>
                                                                @endif
                                                            </form>
                                                        </div>
                                                    </td>
                                                @endif
                                            </tr>
                                        @endforeach
                                    @endif
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>

            {{-- floating confirmation card --}}
            <div class="ws-confirm d-none" role="alertdialog" aria-modal="true">
                <div class="ws-confirm-card">
                    <div class="ws-confirm-ico"><i class="bi bi-exclamation-triangle"></i></div>
                    <div class="wc-title fw-bold mb-1"></div>
                    <div class="wc-text small text-muted mb-3"></div>
                    <div class="d-flex justify-content-center gap-2">
                        <button type="button" class="btn btn-sm btn-light border wc-cancel">Cancel</button>
                        <button type="button" class="btn btn-sm wc-ok"></button>
                    </div>
                </div>
            </div>

            {{-- floating "Add / Edit tank / tanker" card: opens above this pop-up, closing returns here --}}
            @if ($canEdit)
                <div class="ws-addtank d-none" role="dialog" aria-modal="true" aria-label="Add or edit tank / tanker" data-reopen="{{ $re ? 1 : 0 }}"
                     data-store-url="{{ route('wetstock.tanks.store', $warehouse->id) }}"
                     data-update-url="{{ route('wetstock.tanks.update', '__ID__') }}">
                    <div class="ws-addtank-card">
                        <div class="d-flex justify-content-between align-items-start mb-3">
                            <div>
                                <span class="badge bg-light text-dark border mb-2">Warehouse: {{ $warehouse->name }}</span>
                                <h5 class="fw-bold mb-0 text-dark"><i class="bi bi-box-seam text-primary me-2"></i><span class="at-title">{{ $reEdit ? 'Edit Tank / Tanker' : 'Add Tank / Tanker' }}</span></h5>
                            </div>
                            <button type="button" class="btn-close at-close" aria-label="Close"></button>
                        </div>

                        <form method="POST" action="{{ $reEdit ? route('wetstock.tanks.update', $editId) : route('wetstock.tanks.store', $warehouse->id) }}" class="at-form" novalidate>
                            @csrf
                            <input type="hidden" name="_add_tank" value="{{ $warehouse->id }}" {{ $reEdit ? 'disabled' : '' }}>
                            <input type="hidden" name="_edit_tank" value="{{ $reEdit ? $editId : '' }}" {{ $reEdit ? '' : 'disabled' }}>

                            <div class="mb-3">
                                <label class="form-label fw-medium text-secondary">Tank / Tanker Identifier</label>
                                <input type="text" name="name" class="form-control {{ $er('name') ? 'is-invalid' : '' }}" placeholder="e.g. Depot 1 or T1 - NBF 9098" value="{{ $ov('name') }}" required>
                                @if ($er('name'))<div class="invalid-feedback">{{ $er('name') }}</div>@endif
                            </div>

                            <div class="mb-3 at-catwrap {{ $reEdit ? 'd-none' : '' }}">
                                <label class="form-label fw-medium text-secondary">Type / Classification</label>
                                @php $atCat = $ov('category', 'depot'); @endphp
                                <select name="category" class="form-select at-cat {{ $er('category') ? 'is-invalid' : '' }}" required>
                                    <option value="depot" {{ $atCat === 'depot' ? 'selected' : '' }}>Warehouse (Depot) Tank</option>
                                    <option value="tanker" {{ $atCat === 'tanker' ? 'selected' : '' }}>Mobile Tanker Truck</option>
                                </select>
                                @if ($er('category'))<div class="invalid-feedback">{{ $er('category') }}</div>@endif
                            </div>

                            {{-- DEPOT: one product, one capacity --}}
                            <div class="at-depot">
                                <div class="mb-3">
                                    <label class="form-label fw-medium text-secondary">Product stored in this tank</label>
                                    <select name="product_type" class="form-select {{ $er('product_type') ? 'is-invalid' : '' }}">
                                        <option value="">-- Select product --</option>
                                        @foreach ($prods as $code => $plabel)
                                            <option value="{{ $code }}" {{ $ov('product_type') === $code ? 'selected' : '' }}>{{ $plabel }}</option>
                                        @endforeach
                                    </select>
                                    @if ($er('product_type'))<div class="invalid-feedback">{{ $er('product_type') }}</div>@endif
                                </div>
                                <div class="mb-3">
                                    <label class="form-label fw-medium text-secondary">Max Capacity (Liters)</label>
                                    <div class="input-group has-validation">
                                        <input type="number" name="max_capacity" class="form-control {{ $er('max_capacity') ? 'is-invalid' : '' }}" placeholder="e.g. 50000" value="{{ $ov('max_capacity') }}" min="1">
                                        <span class="input-group-text bg-light text-muted">Liters</span>
                                        @if ($er('max_capacity'))<div class="invalid-feedback">{{ $er('max_capacity') }}</div>@endif
                                    </div>
                                </div>
                            </div>

                            {{-- TANKER: compartments --}}
                            <div class="at-tanker mb-3">
                                <label class="form-label fw-medium text-secondary">Compartments</label>
                                @if ($er('compartments'))<div class="text-danger small mb-2 at-err">{{ $er('compartments') }}</div>@endif
                                <div class="at-rows d-flex flex-column gap-2">
                                    @foreach ($atRows as $i => $r)
                                        <div class="input-group at-row">
                                            <span class="input-group-text at-no fw-semibold">C{{ $loop->iteration }}</span>
                                            <input type="hidden" name="compartments[{{ $i }}][id]" value="{{ $reEdit ? ($r['id'] ?? '') : '' }}">
                                            <input type="number" name="compartments[{{ $i }}][capacity]" class="form-control at-cap" min="1" placeholder="Liters" value="{{ $r['capacity'] ?? '' }}">
                                            <select name="compartments[{{ $i }}][product_type]" class="form-select">
                                                <option value="">Any / empty</option>
                                                @foreach ($prods as $code => $plabel)
                                                    <option value="{{ $code }}" {{ ($r['product_type'] ?? '') === $code ? 'selected' : '' }}>{{ $plabel }}</option>
                                                @endforeach
                                            </select>
                                            <button type="button" class="btn btn-outline-danger at-del" title="Remove"><i class="bi bi-x-lg"></i></button>
                                        </div>
                                    @endforeach
                                </div>
                                <div class="d-flex justify-content-between align-items-center mt-2">
                                    <button type="button" class="btn btn-sm btn-outline-primary at-add"><i class="bi bi-plus-lg me-1"></i>Add compartment</button>
                                    <span class="small text-muted">Total capacity: <strong class="at-sum">0</strong> L</span>
                                </div>
                                <template class="at-tpl">
                                    <div class="input-group at-row">
                                        <span class="input-group-text at-no fw-semibold"></span>
                                        <input type="hidden" name="compartments[__i__][id]" value="">
                                        <input type="number" name="compartments[__i__][capacity]" class="form-control at-cap" min="1" placeholder="Liters">
                                        <select name="compartments[__i__][product_type]" class="form-select">
                                            <option value="">Any / empty</option>
                                            @foreach ($prods as $code => $plabel)
                                                <option value="{{ $code }}">{{ $plabel }}</option>
                                            @endforeach
                                        </select>
                                        <button type="button" class="btn btn-outline-danger at-del" title="Remove"><i class="bi bi-x-lg"></i></button>
                                    </div>
                                </template>
                            </div>

                            <div class="mb-4">
                                <label class="form-label fw-medium text-secondary">Remarks (Optional)</label>
                                <textarea name="remarks" rows="2" class="form-control" placeholder="Add optional notes...">{{ $ov('remarks') }}</textarea>
                            </div>

                            <div class="d-flex justify-content-end gap-2">
                                <button type="button" class="btn btn-light border at-close">Cancel</button>
                                <button type="submit" class="btn btn-primary-custom">{{ $reEdit ? 'Save changes' : 'Save Tank' }}</button>
                            </div>
                        </form>
                    </div>
                </div>
            @endif
        </div>
    </div>
</div>