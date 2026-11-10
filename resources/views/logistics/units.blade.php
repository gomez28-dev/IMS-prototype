@extends('layouts.app')

@section('title', 'Manage Units')

@section('content')
<style>
    .ut-card:hover { transform: none; }
    .ut-pill {
        display: inline-flex; align-items: center; border-radius: 999px; padding: 0.25rem 0.7rem;
        font-size: 0.74rem; font-weight: 700; background: rgba(255, 69, 0, 0.12); color: #c2410c;
    }
    .ut-pill.is-small { background: #e0e7ff; color: #3730a3; }

    .u-card { border-left: 5px solid #cbd5e1 !important; }
    .u-card.u-garage { border-left-color: #94a3b8 !important; }
    .u-card.u-maint { border-left-color: #ef4444 !important; }
    .u-card.u-transit { border-left-color: #3b82f6 !important; }
    .u-card.u-wait { border-left-color: #f59e0b !important; }
    .u-card.u-unload { border-left-color: #8b5cf6 !important; }

    .u-badge { display: inline-flex; align-items: center; gap: 0.45rem; padding: 0.35rem 0.8rem; border-radius: 999px; font-size: 0.8rem; font-weight: 700; }
    .u-badge.u-none, .u-badge.u-garage { background: #f1f5f9; color: #475569; }
    .u-badge.u-maint { background: #fee2e2; color: #991b1b; }
    .u-badge.u-transit { background: #dbeafe; color: #1d4ed8; }
    .u-badge.u-wait { background: #fef3c7; color: #92400e; }
    .u-badge.u-unload { background: #ede9fe; color: #5b21b6; }

    .u-stat { border-radius: 14px; padding: 0.75rem 1rem; background: #fff; border: 1px solid var(--border-color); min-width: 120px; }
    .u-stat .n { font-size: 1.6rem; font-weight: 700; line-height: 1; color: var(--primary-navy); }
    .u-stat .l { font-size: 0.7rem; font-weight: 700; text-transform: uppercase; color: var(--text-muted); margin-top: 0.3rem; }
</style>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <div>
        <h4 class="fw-bold text-dark mb-0">Manage Units</h4>
        <div class="text-muted small">Where every tanker is right now, from the dispatcher's latest update</div>
    </div>
    <span class="text-muted small"><i class="bi bi-arrow-repeat me-1"></i>Refreshes every minute</span>
</div>

@if ($errors->any())
    <div class="alert alert-danger rounded-4 border-0 shadow-sm" style="background-color: #fee2e2; color: #b91c1c;">
        @foreach ($errors->all() as $error)<div>{{ $error }}</div>@endforeach
    </div>
@endif

@if ($cards->isEmpty())
    <div class="card card-custom ut-card border-0 shadow-sm">
        <div class="card-body text-center text-muted py-5">
            No tanker trucks found. Tankers are read from the active Wet Stock tanks whose category contains "tanker".
        </div>
    </div>
@else
    {{-- Summary --}}
    <div class="d-flex flex-wrap gap-3 mb-4">
        <div class="u-stat"><div class="n">{{ $summary['total'] }}</div><div class="l">Units</div></div>
        <div class="u-stat"><div class="n">{{ $summary['garage'] }}</div><div class="l">At garage</div></div>
        <div class="u-stat"><div class="n">{{ $summary['maint'] }}</div><div class="l">Under maintenance</div></div>
        <div class="u-stat"><div class="n">{{ $summary['road'] }}</div><div class="l">On the road</div></div>
        <div class="u-stat"><div class="n">{{ $summary['client'] }}</div><div class="l">At a client</div></div>
        <div class="u-stat"><div class="n">{{ $summary['no_update'] }}</div><div class="l">No update today</div></div>
    </div>

    {{-- Unit cards --}}
    <div class="row g-4 mb-4">
        @foreach ($cards as $c)
            <div class="col-md-6 col-xl-4">
                <div class="card card-custom ut-card u-card {{ $c['cls'] }} h-100 border-0 shadow-sm">
                    <div class="card-body">
                        <div class="d-flex align-items-start justify-content-between gap-2">
                            <div class="d-flex align-items-center gap-2">
                                <span class="ut-pill {{ $c['small'] ? 'is-small' : '' }}">{{ $c['code'] }}</span>
                                <div>
                                    <div class="fw-bold text-dark">{{ $c['name'] }}</div>
                                    <div class="text-muted small">{{ $c['warehouse'] }}</div>
                                </div>
                            </div>
                            @if ($canEdit)
                                <button type="button" class="btn btn-outline-primary btn-sm" data-update="{{ $c['id'] }}">Update</button>
                            @endif
                        </div>

                        <div class="mt-3">
                            <span class="u-badge {{ $c['cls'] }}"><i class="bi {{ $c['icon'] }}"></i>{{ $c['label'] }}</span>
                        </div>

                        @if ($c['target_name'])
                            <div class="mt-2">
                                <div class="small text-muted">{{ $c['target_type'] === 'supplier' ? 'Supplier' : 'Client' }}</div>
                                <div class="fw-semibold">{{ $c['target_name'] }}</div>
                            </div>
                        @endif
                        @if ($c['note'])
                            <div class="small text-muted mt-2"><i class="bi bi-chat-left-text me-1"></i>{{ $c['note'] }}</div>
                        @endif

                        <div class="small mt-3 {{ $c['stale'] ? 'text-warning' : 'text-muted' }}">
                            @if ($c['updated_label'])
                                <i class="bi bi-clock me-1"></i>{{ $c['updated_label'] }} ({{ $c['updated_ago'] }})@if ($c['updated_by']) &middot; {{ $c['updated_by'] }}@endif
                                @if ($c['stale']) <b>&middot; no update today</b>@endif
                            @else
                                <i class="bi bi-clock me-1"></i>No update yet
                            @endif
                        </div>

                        <hr class="my-3">
                        <div class="small">
                            <div><i class="bi bi-person me-1"></i>Driver: <b>{{ $c['driver'] ?: '-' }}</b> &nbsp; Helper: <b>{{ $c['helper'] ?: '-' }}</b></div>
                            @if (count($c['trips']))
                                <div class="mt-1"><i class="bi bi-calendar-check me-1"></i>Today: {{ $c['trips']->implode(', ') }}
                                    &middot; {{ $c['stops_done'] }}/{{ $c['stops']->count() }} drop-offs done</div>
                                @if ($c['next_stop'])
                                    <div class="mt-1 text-muted">Next drop: <b class="text-dark">{{ $c['next_stop'] }}</b></div>
                                @endif
                            @else
                                <div class="mt-1 text-muted">No trip scheduled today.</div>
                            @endif
                        </div>
                    </div>
                </div>
            </div>
        @endforeach
    </div>

    {{-- Today's updates --}}
    <div class="card card-custom ut-card border-0 shadow-sm">
        <div class="card-body">
            <div class="fw-bold text-dark mb-2">Today's updates</div>
            @forelse ($logs as $l)
                <div class="d-flex flex-wrap align-items-center gap-3 py-2 border-top">
                    <span class="text-muted small" style="min-width: 70px;">{{ $l['time'] }}</span>
                    <span class="ut-pill {{ $l['small'] ? 'is-small' : '' }}">{{ $l['code'] }}</span>
                    <span class="fw-semibold small">{{ $l['label'] }}</span>
                    @if ($l['target'])<span class="small text-muted">{{ $l['target'] }}</span>@endif
                    @if ($l['note'])<span class="small text-muted">&ldquo;{{ $l['note'] }}&rdquo;</span>@endif
                    @if ($l['by'])<span class="small text-muted ms-auto">{{ $l['by'] }}</span>@endif
                </div>
            @empty
                <div class="text-muted small">No updates yet today.</div>
            @endforelse
        </div>
    </div>
@endif

@if ($canEdit)
    <div class="modal fade" id="unitModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content border-0 rounded-4 shadow">
                <form id="unitForm">
                    <div class="modal-header border-0 pb-0">
                        <h5 class="modal-title fw-bold" id="unitTitle">Update unit</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <div class="row g-3">
                            <div class="col-12">
                                <label class="form-label small fw-semibold">Status</label>
                                <select name="status" id="uStatus" class="form-select" required>
                                    @foreach ($statuses as $key => $s)
                                        <option value="{{ $key }}" data-target="{{ $s['target'] }}">{{ $s['label'] }}</option>
                                    @endforeach
                                </select>
                            </div>

                            <div class="col-12 d-none" id="uStopWrap">
                                <label class="form-label small fw-semibold">Pick from today's line-up</label>
                                <select name="stop_id" id="uStop" class="form-select"></select>
                            </div>

                            <div class="col-12 d-none" id="uTargetWrap">
                                <label class="form-label small fw-semibold" id="uNameLabel">Client</label>
                                <input type="text" name="target_name" id="uName" class="form-control" maxlength="150">
                            </div>

                            <div class="col-12">
                                <label class="form-label small fw-semibold">Note (optional)</label>
                                <input type="text" name="note" id="uNote" class="form-control" maxlength="255" placeholder="e.g. queueing at gate, 3 trucks ahead">
                            </div>
                            <div class="col-12 text-danger small" id="uError"></div>
                        </div>
                    </div>
                    <div class="modal-footer border-0 pt-0">
                        <button type="button" class="btn btn-secondary-custom" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary-custom">Save update</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
    document.addEventListener('DOMContentLoaded', function () {
        var CSRF = @json(csrf_token());
        var base = @json(url('/logistics/units'));
        var UNITS = @json($cards->keyBy('id'));

        var modalEl = document.getElementById('unitModal');
        var modal = new bootstrap.Modal(modalEl);
        var form = document.getElementById('unitForm');
        var sel = document.getElementById('uStatus');
        var stopWrap = document.getElementById('uStopWrap');
        var stopSel = document.getElementById('uStop');
        var targetWrap = document.getElementById('uTargetWrap');
        var nameLabel = document.getElementById('uNameLabel');
        var nameIn = document.getElementById('uName');
        var errEl = document.getElementById('uError');
        var unit = null;

        function esc(s) {
            return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
                return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
            });
        }

        function targetType() {
            return sel.options[sel.selectedIndex].dataset.target || '';
        }

        function sync() {
            var t = targetType();
            targetWrap.classList.toggle('d-none', !t);
            nameLabel.textContent = t === 'supplier' ? 'Supplier' : 'Client';

            var showStops = t === 'client' && unit && unit.stops.length > 0;
            stopWrap.classList.toggle('d-none', !showStops);
            if (!showStops) stopSel.value = '';
        }

        function fillStops(selectedName) {
            var h = '<option value="">Other / not on the line-up</option>';
            unit.stops.forEach(function (s) {
                h += '<option value="' + esc(s.id) + '"' + (s.done ? ' disabled' : '') + '>' + esc(s.name) + (s.done ? ' (done)' : '') + ' - ' + esc(s.trip_no) + '</option>';
            });
            stopSel.innerHTML = h;

            // Preselect the next drop-off that is not done yet.
            var next = unit.stops.filter(function (s) { return !s.done; })[0];
            if (next && !selectedName) stopSel.value = next.id;
        }

        stopSel.addEventListener('change', function () {
            var s = unit.stops.filter(function (x) { return String(x.id) === stopSel.value; })[0];
            if (s) { nameIn.value = s.name || ''; }
        });
        sel.addEventListener('change', function () {
            sync();
            var t = targetType();
            if (t === 'client' && stopSel.value) stopSel.dispatchEvent(new Event('change'));
        });

        document.querySelectorAll('[data-update]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                unit = UNITS[btn.dataset.update];
                document.getElementById('unitTitle').textContent = 'Update ' + unit.code + ' - ' + unit.name;
                errEl.textContent = '';
                sel.value = unit.status || 'garage';
                nameIn.value = unit.target_name || '';
                document.getElementById('uNote').value = unit.note || '';
                fillStops(unit.target_name);
                sync();
                if (targetType() === 'client' && !unit.target_name && stopSel.value) stopSel.dispatchEvent(new Event('change'));
                modal.show();
            });
        });

        form.addEventListener('submit', function (e) {
            e.preventDefault();
            errEl.textContent = '';

            var fd = new FormData(form);
            fd.append('_token', CSRF);

            fetch(base + '/' + unit.id + '/status', {
                method: 'POST',
                body: fd,
                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
            }).then(function (r) {
                return r.json().then(function (j) {
                    if (!r.ok) {
                        throw new Error((j && j.errors) ? Object.values(j.errors)[0][0] : ((j && j.message) || 'Something went wrong.'));
                    }
                    window.location.reload();
                });
            }).catch(function (ex) { errEl.textContent = ex.message; });
        });

        // Keep the board fresh unless someone is in the middle of an update.
        setInterval(function () {
            if (!modalEl.classList.contains('show')) window.location.reload();
        }, 60000);
    });
    </script>
@else
    <script>
        setInterval(function () { window.location.reload(); }, 60000);
    </script>
@endif
@endsection