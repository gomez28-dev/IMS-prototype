@extends('layouts.app')

@section('title', 'Trip Schedule')

@section('content')
<style>
    .ts-card:hover { transform: none; }
    .ts-warn { border-left: 4px solid #f59e0b !important; }

    /* Tanker icons */
    .ts-pill {
        display: inline-flex; align-items: center; gap: 0.4rem;
        border: 0; border-radius: 999px; padding: 0.28rem 0.7rem;
        font-size: 0.74rem; font-weight: 700; letter-spacing: 0.02em;
        background: rgba(255, 69, 0, 0.12); color: #c2410c;
        box-shadow: 0 1px 2px rgba(15, 23, 42, 0.08);
        cursor: pointer; transition: transform 0.12s, box-shadow 0.12s;
    }
    .ts-pill small { font-weight: 600; opacity: 0.75; }
    .ts-pill:hover { transform: translateY(-1px); box-shadow: 0 4px 10px rgba(15, 23, 42, 0.14); }
    .ts-pill.is-small { background: #e0e7ff; color: #3730a3; }
    .ts-pill.sm { padding: 0.15rem 0.55rem; cursor: default; box-shadow: none; }
    .ts-pill.sm:hover { transform: none; box-shadow: none; }

    /* Live status dots and badges */
    .ts-dot { width: 8px; height: 8px; border-radius: 50%; display: inline-block; flex-shrink: 0; }
    .ts-dot.dot-none { background: #cbd5e1; }
    .ts-dot.dot-idle { background: #94a3b8; }
    .ts-dot.dot-transit { background: #3b82f6; }
    .ts-dot.dot-waiting { background: #f59e0b; }
    .ts-dot.dot-unloading { background: #8b5cf6; }
    .ts-dot.dot-done { background: #22c55e; }
    .ts-badge { font-size: 0.68rem; font-weight: 700; padding: 0.2rem 0.55rem; border-radius: 999px; white-space: nowrap; }
    .ts-badge.dot-none, .ts-badge.dot-idle { background: #f1f5f9; color: #475569; }
    .ts-badge.dot-transit { background: #dbeafe; color: #1d4ed8; }
    .ts-badge.dot-waiting { background: #fef3c7; color: #92400e; }
    .ts-badge.dot-unloading { background: #ede9fe; color: #5b21b6; }
    .ts-badge.dot-done { background: #dcfce7; color: #166534; }

    /* View switcher */
    .ts-seg .btn { padding: 0.35rem 0.95rem; border-radius: 8px; font-size: 0.8rem; }
    .ts-seg .btn-primary-custom:hover { transform: none; }

    /* Month */
    .ts-month-head, .ts-month-grid { display: grid; grid-template-columns: repeat(7, minmax(0, 1fr)); gap: 6px; }
    .ts-month-head div { text-align: center; font-size: 0.7rem; font-weight: 700; text-transform: uppercase; color: var(--text-muted); padding-bottom: 0.4rem; }
    .ts-mday { min-height: 98px; border: 1px solid var(--border-color); border-radius: 10px; padding: 0.4rem 0.45rem; background: #fff; }
    .ts-mday.is-out { background: #f8fafc; opacity: 0.55; }
    .ts-mday.is-today { border-color: var(--brand-color); box-shadow: 0 0 0 1px var(--brand-color) inset; }
    .ts-mnum { font-size: 0.78rem; font-weight: 700; color: var(--text-dark); }
    .ts-mpills { display: flex; flex-wrap: wrap; gap: 4px; margin-top: 0.3rem; }
    .ts-add-mini { border: 0; background: transparent; color: #94a3b8; font-size: 0.95rem; line-height: 1; padding: 0 2px; }
    .ts-add-mini:hover { color: var(--brand-color); }
    @media (max-width: 767px) { .ts-month-wrap { overflow-x: auto; } .ts-month-wrap > div { min-width: 640px; } }

    /* Week */
    .ts-table { min-width: 900px; }
    .ts-table th { text-align: center; }
    .ts-table th:first-child { text-align: left; }
    .ts-table td { vertical-align: middle; text-align: center; }
    .ts-table td:first-child { text-align: left; }
    .ts-today { background: rgba(255, 69, 0, 0.06); }
    .ts-cell { display: flex; align-items: center; justify-content: center; gap: 6px; min-height: 34px; }
    .ts-add { border: 1px dashed #cbd5e1; background: transparent; color: #94a3b8; border-radius: 8px; font-size: 0.8rem; padding: 0.05rem 0.5rem; }
    .ts-add:hover { border-color: var(--brand-color); color: var(--brand-color); }

    /* Side panel */
    .ts-comps { display: flex; flex-wrap: wrap; gap: 6px; }
    .ts-comp { font-size: 0.7rem; font-weight: 600; border: 1px solid transparent; border-radius: 6px; padding: 0.18rem 0.5rem; }
    .ts-comp.pr-d { background: #e0e7ff; color: #3730a3; border-color: #c7d2fe; }
    .ts-comp.pr-u { background: #d1fae5; color: #065f46; border-color: #a7f3d0; }
    .ts-comp.pr-p { background: #fee2e2; color: #991b1b; border-color: #fecaca; }
    .ts-comp.pr-e { background: #f1f5f9; color: #64748b; border-color: #e2e8f0; }
    .ts-comp.is-plan { border-style: dashed; }
    .ts-trip { border-radius: 12px; }
    .ts-stop { display: flex; gap: 0.6rem; padding: 0.55rem 0; border-top: 1px dashed var(--border-color); }
    .ts-stop:first-of-type { border-top: 0; }
    .ts-stop-n { width: 22px; height: 22px; border-radius: 50%; background: var(--brand-color); color: #fff; font-size: 0.68rem; font-weight: 700; display: flex; align-items: center; justify-content: center; flex-shrink: 0; margin-top: 2px; }
    .ts-line .form-select, .ts-line .form-control { min-width: 0; }
</style>

@php
    $go = fn ($v, $d) => route('logistics.schedule', ['view' => $v, 'date' => $d]);
    $today = \Carbon\Carbon::today()->toDateString();
    $dlCount = fn ($tankId, $ds) => $wsDeliveries->where('storage_tank_id', $tankId)->where('sched_date', $ds)->pluck('delivery_id')->unique()->count();
@endphp

{{-- Header --}}
<div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-3">
    <div>
        <h4 class="fw-bold text-dark mb-0">Fuel Trading Operations</h4>
        <div class="text-muted small">Trip schedule per tanker truck</div>
    </div>
    <div class="d-flex flex-wrap align-items-center gap-2">
        <div class="btn-group ts-seg" role="group" aria-label="View">
            <a href="{{ $go('month', $date->toDateString()) }}" class="btn {{ $view === 'month' ? 'btn-primary-custom' : 'btn-secondary-custom' }}">Month</a>
            <a href="{{ $go('week', $date->toDateString()) }}" class="btn {{ $view === 'week' ? 'btn-primary-custom' : 'btn-secondary-custom' }}">Week</a>
            <a href="{{ $go('day', $date->toDateString()) }}" class="btn {{ $view === 'day' ? 'btn-primary-custom' : 'btn-secondary-custom' }}">Day</a>
        </div>
        <div class="d-flex align-items-center gap-2">
            <a href="{{ $go($view, $prev->toDateString()) }}" class="btn btn-secondary-custom btn-sm" aria-label="Previous"><i class="bi bi-chevron-left"></i></a>
            <span class="fw-semibold small px-1">{{ $label }}</span>
            <a href="{{ $go($view, $next->toDateString()) }}" class="btn btn-secondary-custom btn-sm" aria-label="Next"><i class="bi bi-chevron-right"></i></a>
            <a href="{{ $go($view, $today) }}" class="btn btn-secondary-custom btn-sm">Today</a>
        </div>
    </div>
</div>

{{-- Legend --}}
<div class="d-flex flex-wrap gap-3 small text-muted mb-3">
    <span><span class="ts-pill sm">T1</span> Regular tanker</span>
    <span><span class="ts-pill is-small sm">ST1</span> Small tanker</span>
    <span><span class="ts-dot dot-idle"></span> Planned</span>
    <span><span class="ts-dot dot-transit"></span> In transit</span>
    <span><span class="ts-dot dot-waiting"></span> Waiting to unload</span>
    <span><span class="ts-dot dot-unloading"></span> Unloading</span>
    <span><span class="ts-dot dot-done"></span> Completed</span>
</div>

@if ($errors->any())
    <div class="alert alert-danger rounded-4 border-0 shadow-sm" style="background-color: #fee2e2; color: #b91c1c;">
        @foreach ($errors->all() as $error)
            <div>{{ $error }}</div>
        @endforeach
    </div>
@endif

{{-- Deliveries assigned to a tanker in Wet Stock that have no delivery date yet --}}
@if ($unscheduled->isNotEmpty())
    <div class="card card-custom ts-card ts-warn border-0 shadow-sm mb-3">
        <div class="card-body">
            <div class="fw-bold text-dark mb-1">
                <i class="bi bi-exclamation-triangle text-warning me-1"></i>
                Assigned deliveries without a delivery date ({{ $unscheduled->count() }})
            </div>
            <div class="small text-muted mb-2">
                These are assigned to a tanker in Wet Stock but have no date, so they cannot appear on the calendar.
                Setting the date here asks admin or the HOD to move the delivery; the date changes everywhere once it is approved.
            </div>
            @foreach ($unscheduled as $u)
                <div class="d-flex flex-wrap align-items-center gap-3 py-2 border-top">
                    @if ($u->tanker)
                        <span class="ts-pill sm {{ $u->tanker->small ? 'is-small' : '' }}">{{ $u->tanker->code }}</span>
                    @endif
                    <div class="me-auto">
                        <div class="fw-semibold">DR# {{ $u->dr_number ?: '-' }} <span class="text-muted fw-normal">&middot; SO# {{ $u->so_number ?: '-' }}</span></div>
                        <div class="small text-muted">
                            {{ $u->account }}@if ($u->location) &middot; {{ $u->location }}@endif
                            @if ($u->products) &middot; {{ $u->products }}@endif
                            &middot; {{ number_format($u->liters) }} L
                        </div>
                    </div>
                    @if ($canEdit)
                        <input type="date" class="form-control form-control-sm" style="max-width: 160px;" data-undated-date="{{ $u->delivery_id }}">
                        <button type="button" class="btn btn-primary-custom btn-sm" data-set-date="{{ $u->delivery_id }}">Set date</button>
                    @endif
                </div>
            @endforeach
        </div>
    </div>
@endif

@if ($tankers->isEmpty())
    <div class="card card-custom ts-card border-0 shadow-sm">
        <div class="card-body text-center text-muted py-5">
            No tanker trucks found. Tankers are read from the active Wet Stock tanks whose category contains "tanker".
        </div>
    </div>
@else

    {{-- ===================== MONTH ===================== --}}
    @if ($view === 'month')
        <div class="card card-custom ts-card border-0 shadow-sm p-3">
            <div class="ts-month-wrap">
                <div>
                    <div class="ts-month-head">
                        @foreach (['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'] as $dn)
                            <div>{{ $dn }}</div>
                        @endforeach
                    </div>
                    <div class="ts-month-grid">
                        @foreach ($days as $d)
                            @php
                                $ds = $d->toDateString();
                                $tripGroups = $trips->where('sched_date', $ds)->groupBy('storage_tank_id');
                                $dlGroups   = $wsDeliveries->where('sched_date', $ds)->groupBy('storage_tank_id');
                                $dayTankIds = $tripGroups->keys()->merge($dlGroups->keys())->unique();
                            @endphp
                            <div class="ts-mday {{ $d->month !== $date->month ? 'is-out' : '' }} {{ $d->isToday() ? 'is-today' : '' }}">
                                <div class="d-flex justify-content-between align-items-start">
                                    <span class="ts-mnum">{{ $d->day }}</span>
                                    @if ($canEdit)
                                        <button type="button" class="ts-add-mini" data-add data-date="{{ $ds }}" aria-label="Add trip">+</button>
                                    @endif
                                </div>
                                <div class="ts-mpills">
                                    @foreach ($dayTankIds as $tankId)
                                        @php $tk = $tankers->firstWhere('id', $tankId); @endphp
                                        @if ($tk)
                                            @include('logistics._pill', [
                                                'tk'    => $tk,
                                                'group' => $tripGroups->get($tankId, collect()),
                                                'date'  => $ds,
                                            ])
                                        @endif
                                    @endforeach
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>
        </div>

    {{-- ===================== WEEK ===================== --}}
    @elseif ($view === 'week')
        <div class="card card-custom ts-card border-0 shadow-sm">
            <div class="table-responsive">
                <table class="table table-custom ts-table mb-0">
                    <thead>
                        <tr>
                            <th>Tanker</th>
                            @foreach ($days as $d)
                                <th class="{{ $d->isToday() ? 'ts-today' : '' }}">
                                    {{ $d->format('D') }}
                                    <div class="fw-normal text-muted" style="font-size: 0.7rem;">{{ $d->format('M j') }}</div>
                                </th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($tankers as $tk)
                            <tr>
                                <td>
                                    <div class="fw-semibold text-dark">{{ $tk->name }}</div>
                                    <div class="text-muted small">{{ $tk->warehouse }} &middot; {{ number_format((int) $tk->max_capacity) }} L max</div>
                                </td>
                                @foreach ($days as $d)
                                    @php
                                        $ds = $d->toDateString();
                                        $group = $trips->where('storage_tank_id', $tk->id)->where('sched_date', $ds);
                                        $dl = $dlCount($tk->id, $ds);
                                    @endphp
                                    <td class="{{ $d->isToday() ? 'ts-today' : '' }}">
                                        <div class="ts-cell">
                                            @if ($group->isNotEmpty() || $dl > 0)
                                                @include('logistics._pill', ['tk' => $tk, 'group' => $group, 'date' => $ds])
                                            @endif
                                            @if ($canEdit)
                                                <button type="button" class="ts-add" data-add data-tank="{{ $tk->id }}" data-date="{{ $ds }}" aria-label="Add trip">+</button>
                                            @endif
                                        </div>
                                    </td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>

    {{-- ===================== DAY ===================== --}}
    @else
        @php $ds = $date->toDateString(); @endphp
        @foreach ($tankers as $tk)
            @php
                $group = $trips->where('storage_tank_id', $tk->id);
                $dl = $dlCount($tk->id, $ds);
            @endphp
            <div class="card card-custom ts-card border-0 shadow-sm mb-3">
                <div class="card-body">
                    <div class="d-flex flex-wrap align-items-center gap-3">
                        @include('logistics._pill', ['tk' => $tk, 'group' => $group, 'date' => $ds])
                        <div class="me-auto">
                            <div class="fw-bold text-dark">{{ $tk->name }}</div>
                            <div class="text-muted small">{{ $tk->warehouse }} &middot; {{ number_format((int) $tk->max_capacity) }} L max</div>
                        </div>
                        @if ($dl > 0)
                            <button type="button" class="btn btn-outline-primary btn-sm" data-open data-tank="{{ $tk->id }}" data-date="{{ $ds }}">
                                {{ $dl }} Wet Stock {{ $dl === 1 ? 'delivery' : 'deliveries' }}
                            </button>
                        @endif
                        @if ($canEdit)
                            <button type="button" class="btn btn-secondary-custom btn-sm" data-add data-tank="{{ $tk->id }}" data-date="{{ $ds }}">+ Schedule trip</button>
                        @endif
                    </div>

                    @if ($group->isEmpty())
                        <div class="text-muted small mt-3">No trips scheduled today.</div>
                    @else
                        <div class="mt-3">
                            @foreach ($group as $trip)
                                @php
                                    $l = strtolower((string) $trip->live);
                                    $cls = preg_match('/complete|done|delivered/', $l) ? 'dot-done'
                                        : (str_contains($l, 'wait') ? 'dot-waiting'
                                        : (str_contains($l, 'unload') ? 'dot-unloading'
                                        : (str_contains($l, 'transit') ? 'dot-transit' : 'dot-idle')));
                                @endphp
                                <div class="d-flex flex-wrap align-items-center gap-3 py-2 border-top">
                                    <div class="fw-semibold" style="min-width: 150px;">{{ $trip->trip_no }}</div>
                                    <span class="ts-badge {{ $cls }}">{{ \Illuminate\Support\Str::headline((string) $trip->live) ?: 'Planned' }}</span>
                                    <div class="small text-muted"><i class="bi bi-person me-1"></i>{{ $trip->driver ?: '-' }} / {{ $trip->helper ?: '-' }}</div>
                                    @if ($trip->product)<div class="small text-muted"><i class="bi bi-droplet me-1"></i>{{ $trip->product }}</div>@endif
                                    <div class="small text-muted">{{ $trip->stops_done }}/{{ $trip->stops_total }} stops done</div>
                                    <button type="button" class="btn btn-outline-primary btn-sm ms-auto" data-open data-tank="{{ $tk->id }}" data-date="{{ $ds }}">Open line-up</button>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>
            </div>
        @endforeach
    @endif
@endif

{{-- ===================== SIDE PANEL ===================== --}}
<div class="offcanvas offcanvas-end" tabindex="-1" id="tsPanel" style="width: 560px; max-width: 100%;">
    <div class="offcanvas-header border-bottom">
        <h5 class="offcanvas-title fw-bold">Tanker line-up</h5>
        <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Close"></button>
    </div>
    <div class="offcanvas-body" id="tsPanelBody"><div class="text-muted">Loading...</div></div>
</div>

{{-- ===================== TRIP MODAL ===================== --}}
@if ($canEdit)
    <div class="modal fade" id="tripModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content border-0 rounded-4 shadow">
                <form id="tripForm" method="POST" action="{{ route('logistics.schedule.store') }}">
                    @csrf
                    <input type="hidden" name="view" value="{{ $view }}">
                    <div class="modal-header border-0 pb-0">
                        <h5 class="modal-title fw-bold" id="tripModalTitle">Schedule trip</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <div class="row g-3">
                            <div class="col-12">
                                <label class="form-label small fw-semibold">Tanker</label>
                                <select name="storage_tank_id" id="tsTank" class="form-select" required>
                                    @foreach ($tankers as $t)
                                        <option value="{{ $t->id }}">{{ $t->code }} - {{ $t->name }} ({{ $t->warehouse }})</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small fw-semibold">Date</label>
                                <input type="date" name="scheduled_date" id="tsDate" class="form-control" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small fw-semibold">Trip type</label>
                                <select name="trip_type" id="tsType" class="form-select">
                                    @foreach ($tripTypes as $val => $lbl)
                                        <option value="{{ $val }}">{{ $lbl }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-12 d-none" id="tsSupplierWrap">
                                <label class="form-label small fw-semibold">Supplier</label>
                                <input type="text" name="supplier_name" id="tsSupplier" class="form-control" maxlength="150">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small fw-semibold">Driver</label>
                                <select name="driver_id" id="tsDriver" class="form-select">
                                    <option value="">-</option>
                                    @foreach ($drivers as $id => $name)
                                        <option value="{{ $id }}">{{ $name }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small fw-semibold">Helper</label>
                                <select name="helper_id" id="tsHelper" class="form-select">
                                    <option value="">-</option>
                                    @foreach ($helpers as $id => $name)
                                        <option value="{{ $id }}">{{ $name }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-12">
                                <div class="small text-muted" style="margin-top: -0.4rem;">
                                    One driver and helper per tanker per day. Leave blank to use the crew already assigned that day; changing them updates all of the tanker's trips that day.
                                </div>
                            </div>
                            <div class="col-12">
                                <label class="form-label small fw-semibold">Remarks</label>
                                <textarea name="remarks" id="tsRemarks" class="form-control" rows="2" maxlength="500"></textarea>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer border-0 pt-0 justify-content-between">
                        <button type="submit" form="deleteForm" id="tsDelete" class="btn btn-outline-danger btn-sm d-none"
                                onclick="return confirm('Delete this trip?');">
                            <i class="bi bi-trash me-1"></i> Delete
                        </button>
                        <div class="ms-auto d-flex gap-2">
                            <button type="button" class="btn btn-secondary-custom" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" class="btn btn-primary-custom">Save</button>
                        </div>
                    </div>
                </form>
                <form id="deleteForm" method="POST" class="d-none">
                    @csrf
                    <input type="hidden" name="view" value="{{ $view }}">
                </form>
            </div>
        </div>
    </div>
@endif

<script>
document.addEventListener('DOMContentLoaded', function () {
    var CSRF = @json(csrf_token());
    var base = @json(url('/logistics/schedule'));
    var detailUrl = @json(route('logistics.schedule.detail'));
    var storeUrl = @json(route('logistics.schedule.store'));

    var panelEl = document.getElementById('tsPanel');
    var body = document.getElementById('tsPanelBody');
    var panel = new bootstrap.Offcanvas(panelEl);
    var modalEl = document.getElementById('tripModal');
    var modal = modalEl ? new bootstrap.Modal(modalEl) : null;

    var current = null;   // { tank, date }
    var data = null;      // last panel payload
    var dirty = false;
    var openTripModal = function () {};

    // ---------- Small helpers ----------
    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }
    function num(n) { return Number(n || 0).toLocaleString(); }
    function kl(n) { return Number(n || 0).toLocaleString(undefined, { maximumFractionDigits: 3 }); }
    function dotClass(s) {
        s = String(s || '').toLowerCase();
        if (/complete|done|delivered/.test(s)) return 'dot-done';
        if (s.indexOf('wait') > -1) return 'dot-waiting';
        if (s.indexOf('unload') > -1) return 'dot-unloading';
        if (s.indexOf('transit') > -1) return 'dot-transit';
        return s ? 'dot-idle' : 'dot-none';
    }
    function prodClass(p) {
        p = String(p || '').toLowerCase();
        if (p.indexOf('diesel') > -1) return 'pr-d';
        if (p.indexOf('unleaded') > -1) return 'pr-u';
        if (p.indexOf('premium') > -1) return 'pr-p';
        return 'pr-e';
    }
    function compByNo(d, no) {
        return d.compartments.filter(function (c) { return String(c.compartment_no) === String(no); })[0];
    }

    function post(url, formData) {
        formData.append('_token', CSRF);
        return fetch(url, {
            method: 'POST',
            body: formData,
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
        }).then(function (r) {
            return r.json().then(function (j) {
                if (!r.ok) {
                    var m = (j && j.errors) ? Object.values(j.errors)[0][0] : ((j && j.message) || 'Something went wrong.');
                    throw new Error(m);
                }
                return j;
            });
        });
    }

    // ---------- Deliveries without a date (card above the calendar) ----------
    document.querySelectorAll('[data-set-date]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var id = btn.dataset.setDate;
            var inp = document.querySelector('[data-undated-date="' + id + '"]');
            if (!inp || !inp.value) { alert('Pick a date first.'); return; }
            var fd = new FormData();
            fd.append('delivery_date', inp.value);
            post(base + '/deliveries/' + id + '/date', fd).then(function () {
                window.location.reload();
            }).catch(function (err) { alert(err.message); });
        });
    });

    // ---------- Side panel ----------
    function load(tank, date) {
        current = { tank: tank, date: date };
        return fetch(detailUrl + '?tank=' + encodeURIComponent(tank) + '&date=' + encodeURIComponent(date), {
            headers: { 'Accept': 'application/json' }
        }).then(function (r) { return r.json(); }).then(function (d) {
            data = d;
            body.innerHTML = render(d);
        });
    }

    function render(d) {
        var h = '';
        h += '<div class="d-flex align-items-center gap-2 mb-1">';
        h += '<span class="ts-pill ' + (d.tanker.small ? 'is-small' : '') + '" style="cursor:default">' + esc(d.tanker.code) + '</span>';
        h += '<div><div class="fw-bold">' + esc(d.tanker.name) + '</div>';
        h += '<div class="text-muted small">' + esc(d.tanker.warehouse || '') + ' &middot; ' + num(d.tanker.max) + ' L max</div></div></div>';
        h += '<div class="small text-muted">' + esc(d.date_label) + '</div>';
        if (d.day_driver || d.day_helper) {
            h += '<div class="small mt-1"><i class="bi bi-people me-1"></i>Crew today: <b>' + esc(d.day_driver || '-') + '</b> / <b>' + esc(d.day_helper || '-') + '</b></div>';
        }
        h += '<div class="mb-3"></div>';

        if (!d.compartments.length) {
            h += '<div class="alert alert-warning small py-2">This tanker has no compartments set up, so drop-offs cannot be planned for it. Set up its compartments in Wet Stock first.</div>';
        }

        if (d.compartments.length) {
            h += '<div class="small fw-semibold mb-1">Compartments</div><div class="ts-comps mb-3">';
            d.compartments.forEach(function (c) {
                var planOnly = !c.product_type && c.planned_product;
                var prod = c.product_type || c.planned_product || '';
                var txt = 'C' + esc(c.compartment_no) + ' &middot; ' + esc(prod || 'Empty') + ' &middot; ' + num(c.capacity) + ' L';
                if (c.planned_kl > 0) txt += ' &middot; plan ' + kl(c.planned_kl) + ' KL';
                h += '<span class="ts-comp ' + prodClass(prod) + (planOnly ? ' is-plan' : '') + '"' + (planOnly ? ' title="Planned by the day\'s deliveries and trips"' : '') + '>' + txt + '</span>';
            });
            h += '</div>';
        }

        if (d.deliveries.length) {
            h += '<div class="small fw-semibold mb-1"><i class="bi bi-box-seam me-1"></i>Deliveries assigned in Wet Stock</div>';
            d.deliveries.forEach(function (x) { h += deliveryCard(d, x); });
            h += '<div class="mb-3"></div>';
        }

        if (!d.trips.length) {
            h += '<div class="text-muted small mb-3">No trips scheduled for this tanker on this day.</div>';
        }
        d.trips.forEach(function (t) { h += tripCard(d, t); });

        if (d.can_edit) {
            h += '<button type="button" class="btn btn-primary-custom btn-sm w-100" data-panel-add>+ Schedule trip</button>';
        }
        return h;
    }

    // A delivery that Wet Stock assigned to this tanker for this day.
    function deliveryCard(d, x) {
        var h = '<div class="ts-trip card border-0 shadow-sm mb-2"><div class="card-body p-3">';
        h += '<div class="d-flex justify-content-between align-items-start gap-2"><div>';
        h += '<div class="fw-bold">DR# ' + esc(x.dr_number || '-') + ' <span class="text-muted fw-normal">&middot; SO# ' + esc(x.so_number || '-') + '</span></div>';
        h += '<div class="small">' + esc(x.client || '-') + '</div>';
        if (x.location) h += '<div class="small text-muted"><i class="bi bi-geo-alt me-1"></i>' + esc(x.location) + '</div>';
        h += '</div><span class="ts-badge ' + (x.fulfilled ? 'dot-done' : 'dot-idle') + '">' + esc(x.status_label || 'Assigned') + '</span></div>';

        if (x.lines.length) {
            h += '<div class="ts-comps mt-2">';
            x.lines.forEach(function (l) {
                h += '<span class="ts-comp ' + prodClass(l.product) + '">' + (l.compartment_no ? 'C' + esc(l.compartment_no) + ' &middot; ' : '') + esc(l.product || '-') + ' &middot; ' + num(l.liters) + ' L</span>';
            });
            h += '</div>';
        }

        if (d.can_edit && x.can_move) {
            h += '<div class="d-flex gap-2 mt-2"><input type="date" class="form-control form-control-sm ts-del-date" value="' + esc(x.date) + '" style="max-width: 170px">';
            h += '<button type="button" class="btn btn-outline-primary btn-sm" data-move-delivery="' + x.id + '">Request change</button></div>';
            h += '<div class="small text-muted mt-1">Goes to the Approvals Queue; Wet Stock and Sales Documentation follow once admin or the HOD approves.</div>';
        }
        h += '</div></div>';
        return h;
    }

    function tripCard(d, t) {
        var pick = t.is_pickup;
        var h = '<div class="ts-trip card border-0 shadow-sm mb-3"><div class="card-body p-3">';

        h += '<div class="d-flex justify-content-between align-items-start gap-2">';
        h += '<div><div class="fw-bold">' + esc(t.trip_no) + '</div><div class="small text-muted">' + esc(t.type_label) + '</div></div>';
        h += '<span class="ts-badge ' + dotClass(t.live) + '">' + esc(t.live_label || 'Planned') + '</span></div>';

        h += '<div class="small mt-2"><i class="bi bi-person me-1"></i>Driver: <b>' + esc(t.driver || '-') + '</b>';
        h += ' &nbsp;<i class="bi bi-person-plus me-1"></i>Helper: <b>' + esc(t.helper || '-') + '</b></div>';
        if (pick) h += '<div class="small"><i class="bi bi-box-arrow-in-down me-1"></i>From supplier: <b>' + esc(t.supplier || '-') + '</b></div>';
        if (t.product) h += '<div class="small"><i class="bi bi-droplet me-1"></i>Product: <b>' + esc(t.product) + '</b></div>';
        if (t.remarks) h += '<div class="small text-muted mt-1">' + esc(t.remarks) + '</div>';

        h += '<hr class="my-2">';
        h += '<div class="small fw-semibold mb-1">' + (pick ? 'Loading lines (added to compartments)' : 'Drop-off line-up (to clients)');
        h += ' <span class="text-muted fw-normal">&middot; ' + t.stops_done + '/' + t.stops.length + ' done &middot; ' + kl(t.total_kl) + ' KL</span></div>';

        if (!t.stops.length) h += '<div class="small text-muted mb-2">No lines yet.</div>';
        t.stops.forEach(function (s, i) { h += stopRow(d, t, s, i); });

        if (d.can_edit && t.status !== 'cancelled') {
            h += '<div class="d-flex gap-2 mt-2">';
            if (d.compartments.length) {
                h += '<button type="button" class="btn btn-outline-primary btn-sm" data-toggle-form="' + t.id + '"><i class="bi bi-plus-lg me-1"></i>Add ' + (pick ? 'loading line' : 'drop-off') + '</button>';
            }
            h += '<button type="button" class="btn btn-secondary-custom btn-sm" data-edit-trip="' + t.id + '"><i class="bi bi-pencil me-1"></i>Edit trip</button>';
            h += '</div>';
            h += stopForm(d, t, null);
        }
        h += '</div></div>';
        return h;
    }

    function stopRow(d, t, s, i) {
        var pick = t.is_pickup;
        var h = '<div class="ts-stop"><div class="ts-stop-n">' + (i + 1) + '</div><div class="flex-grow-1">';
        h += '<div class="fw-semibold">' + esc(s.client_name) + '</div>';
        if (s.address) h += '<div class="text-muted small">' + esc(s.address) + '</div>';

        var refs = [];
        if (s.so_no) refs.push('SO# ' + esc(s.so_no));
        if (s.dr_no) refs.push('DR# ' + esc(s.dr_no));
        if (s.wb_no) refs.push('WB# ' + esc(s.wb_no));
        if (refs.length) h += '<div class="small">' + refs.join(' &middot; ') + '</div>';

        if (s.lines.length) {
            h += '<div class="small text-muted mt-1">' + (pick ? 'Loaded into' : 'Unloaded from') + '</div><div class="ts-comps mb-1">';
            s.lines.forEach(function (l) {
                h += '<span class="ts-comp ' + prodClass(l.product) + '">C' + esc(l.compartment_no) + ' &middot; ' + esc(l.product) + ' &middot; ' + kl(l.qty_kl) + ' KL</span>';
            });
            h += '</div>';
        }
        if (s.status_eta) h += '<div class="small text-muted">ETA: ' + esc(s.status_eta) + '</div>';
        if (s.date_request) h += '<div class="small mt-1"><span class="ts-badge dot-waiting"><i class="bi bi-hourglass-split me-1"></i>Move to ' + esc(s.date_request) + ' awaiting approval</span></div>';

        if (d.can_edit && t.status !== 'cancelled') {
            h += '<div class="d-flex align-items-center gap-2 mt-1"><select class="form-select form-select-sm ts-stop-status" data-stop="' + s.id + '" style="max-width: 190px;">';
            Object.keys(d.stop_statuses).forEach(function (k) {
                h += '<option value="' + esc(k) + '"' + (k === s.status ? ' selected' : '') + '>' + esc(d.stop_statuses[k]) + '</option>';
            });
            h += '</select>';
            h += '<button type="button" class="btn btn-link btn-sm p-0" data-edit-stop="' + s.id + '" aria-label="Edit line"><i class="bi bi-pencil"></i></button>';
            h += '<button type="button" class="btn btn-link btn-sm text-danger p-0" data-del-stop="' + s.id + '" aria-label="Delete line"><i class="bi bi-trash"></i></button></div>';
            h += stopForm(d, t, s);
        } else {
            h += '<div class="mt-1"><span class="ts-badge ' + dotClass(s.status) + '">' + esc(d.stop_statuses[s.status] || s.status || 'Pending') + '</span></div>';
        }
        h += '</div></div>';
        return h;
    }

    // ---------- Add / edit a drop-off (client + one or more compartments) ----------
    // Products are stored as codes (D/U/P); the labels come from the server.
    function productOptions(d, comp, selected) {
        var locked = comp && comp.product_type ? comp.product_type : '';
        if (locked) {
            return '<option value="' + esc(locked) + '" selected>' + esc(d.products[locked] || locked) + '</option>';
        }
        var h = '<option value="">Product</option>';
        Object.keys(d.products).forEach(function (code) {
            h += '<option value="' + esc(code) + '"' + (code === selected ? ' selected' : '') + '>' + esc(d.products[code]) + '</option>';
        });
        return h;
    }

    function lineRow(d, ln) {
        ln = ln || {};
        var comp = ln.compartment_no ? compByNo(d, ln.compartment_no) : null;
        var h = '<div class="ts-line d-flex gap-2 mb-1">';
        h += '<select class="form-select form-select-sm ts-l-comp" style="flex: 1.3"><option value="">Compartment</option>';
        d.compartments.forEach(function (c) {
            var prod = c.product_type || c.planned_product || 'Empty';
            h += '<option value="' + esc(c.compartment_no) + '"' + (String(ln.compartment_no) === String(c.compartment_no) ? ' selected' : '') + '>C' + esc(c.compartment_no) + ' &middot; ' + esc(prod) + ' &middot; ' + num(c.capacity) + ' L</option>';
        });
        h += '</select>';
        h += '<select class="form-select form-select-sm ts-l-prod" style="flex: 1">' + productOptions(d, comp, ln.product_type) + '</select>';
        h += '<input type="number" class="form-control form-control-sm ts-l-qty" step="1" min="1" placeholder="Litres" value="' + esc(ln.quantity != null && ln.quantity !== '' ? ln.quantity : '') + '" style="max-width: 96px">';
        h += '<button type="button" class="btn btn-link btn-sm text-danger p-0" data-del-line aria-label="Remove compartment"><i class="bi bi-x-lg"></i></button></div>';
        return h;
    }

    function stopForm(d, t, s) {
        var pick = t.is_pickup;
        s = s || {};
        var isEdit = !!s.id;
        var h = '<form class="ts-stop-form d-none mt-3 p-2 rounded-3" style="background:#f8fafc"'
            + ' data-trip="' + t.id + '"' + (isEdit ? ' data-stop="' + s.id + '"' : ' data-new="1"') + '><div class="row g-2">';
        h += '<div class="col-12"><input class="form-control form-control-sm" name="client_name" placeholder="' + (pick ? 'Supplier / source' : 'Client') + '" required maxlength="150" value="' + esc(s.client_name) + '"></div>';
        h += '<div class="col-12"><input class="form-control form-control-sm" name="address" placeholder="Address" maxlength="255" value="' + esc(s.address) + '"></div>';
        h += '<div class="col-4"><input class="form-control form-control-sm" name="so_no" placeholder="SO#" maxlength="50" value="' + esc(s.so_no) + '"></div>';
        h += '<div class="col-4"><input class="form-control form-control-sm" name="dr_no" placeholder="DR#" maxlength="50" value="' + esc(s.dr_no) + '"></div>';
        h += '<div class="col-4"><input class="form-control form-control-sm" name="wb_no" placeholder="WB#" maxlength="50" value="' + esc(s.wb_no) + '"></div>';
        h += '<div class="col-12"><input class="form-control form-control-sm" name="status_eta" placeholder="ETA (e.g. 2:30 PM)" maxlength="100" value="' + esc(s.status_eta) + '"></div>';

        h += '<div class="col-12"><div class="small fw-semibold mb-1">' + (pick ? 'Compartments loaded' : 'Compartments to unload') + '</div>';
        h += '<div class="ts-lines">';
        if (s.lines && s.lines.length) {
            s.lines.forEach(function (l) { h += lineRow(d, l); });
        } else {
            h += lineRow(d, null);
        }
        h += '</div>';
        h += '<button type="button" class="btn btn-link btn-sm p-0" data-add-line>+ Add compartment</button></div>';

        h += '<div class="col-12 text-danger small ts-stop-error"></div>';
        h += '<div class="col-12 d-flex gap-2"><button type="submit" class="btn btn-primary-custom btn-sm">Save line</button>';
        h += '<button type="button" class="btn btn-secondary-custom btn-sm" ' + (isEdit ? 'data-edit-stop="' + s.id + '"' : 'data-toggle-form="' + t.id + '"') + '>Cancel</button></div>';
        h += '</div></form>';
        return h;
    }

    // ---------- Open the panel from any icon ----------
    document.querySelectorAll('[data-open]').forEach(function (el) {
        el.addEventListener('click', function () {
            body.innerHTML = '<div class="text-muted">Loading...</div>';
            panel.show();
            load(el.dataset.tank, el.dataset.date);
        });
    });

    // ---------- Panel actions (event delegation) ----------
    body.addEventListener('click', function (e) {
        var t;

        if ((t = e.target.closest('[data-move-delivery]'))) {
            var inp = t.parentNode.querySelector('.ts-del-date');
            if (!inp || !inp.value) return;
            if (!confirm('Request moving this delivery to ' + inp.value + '? It takes effect once admin or the HOD approves it.')) return;
            var mfd = new FormData();
            mfd.append('delivery_date', inp.value);
            post(base + '/deliveries/' + t.dataset.moveDelivery + '/date', mfd).then(function () {
                dirty = true; load(current.tank, current.date);
            }).catch(function (err) { alert(err.message); });
            return;
        }
        if ((t = e.target.closest('[data-toggle-form]'))) {
            var f = body.querySelector('.ts-stop-form[data-trip="' + t.dataset.toggleForm + '"][data-new]');
            if (f) f.classList.toggle('d-none');
            return;
        }
        if ((t = e.target.closest('[data-edit-stop]'))) {
            var ef = body.querySelector('.ts-stop-form[data-stop="' + t.dataset.editStop + '"]');
            if (ef) ef.classList.toggle('d-none');
            return;
        }
        if ((t = e.target.closest('[data-add-line]'))) {
            t.parentNode.querySelector('.ts-lines').insertAdjacentHTML('beforeend', lineRow(data, null));
            return;
        }
        if ((t = e.target.closest('[data-del-line]'))) {
            var wrap = t.closest('.ts-lines');
            if (wrap.querySelectorAll('.ts-line').length > 1) t.closest('.ts-line').remove();
            return;
        }
        if ((t = e.target.closest('[data-del-stop]'))) {
            if (!confirm('Delete this line?')) return;
            post(base + '/stops/' + t.dataset.delStop + '/delete', new FormData()).then(function () {
                dirty = true; load(current.tank, current.date);
            }).catch(function (err) { alert(err.message); });
            return;
        }
        if ((t = e.target.closest('[data-edit-trip]'))) {
            var trip = data.trips.filter(function (x) { return String(x.id) === t.dataset.editTrip; })[0];
            if (trip) openTripModal(trip);
            return;
        }
        if (e.target.closest('[data-panel-add]')) {
            openTripModal({ tank: current.tank, date: current.date, driver_id: data.day_driver_id, helper_id: data.day_helper_id });
        }
    });

    body.addEventListener('change', function (e) {
        // Status change
        var sel = e.target.closest('.ts-stop-status');
        if (sel) {
            var fd = new FormData();
            fd.append('status', sel.value);
            post(base + '/stops/' + sel.dataset.stop + '/status', fd).then(function () {
                dirty = true; load(current.tank, current.date);
            }).catch(function (err) { alert(err.message); load(current.tank, current.date); });
            return;
        }

        // Compartment chosen: the product follows what the compartment holds.
        var cs = e.target.closest('.ts-l-comp');
        if (cs) {
            var row = cs.closest('.ts-line');
            var prod = row.querySelector('.ts-l-prod');
            var keep = prod.value;
            prod.innerHTML = productOptions(data, cs.value ? compByNo(data, cs.value) : null, keep);
        }
    });

    body.addEventListener('submit', function (e) {
        var form = e.target.closest('.ts-stop-form');
        if (!form) return;
        e.preventDefault();

        var err = form.querySelector('.ts-stop-error');
        err.textContent = '';

        var fd = new FormData();
        ['client_name', 'address', 'so_no', 'dr_no', 'wb_no', 'status_eta'].forEach(function (n) {
            fd.append(n, form.elements[n].value);
        });

        var i = 0;
        form.querySelectorAll('.ts-line').forEach(function (r) {
            var c = r.querySelector('.ts-l-comp').value;
            var p = r.querySelector('.ts-l-prod').value;
            var q = r.querySelector('.ts-l-qty').value;
            if (!c && !p && !q) return; // ignore untouched rows
            fd.append('lines[' + i + '][compartment_no]', c);
            fd.append('lines[' + i + '][product_type]', p);
            fd.append('lines[' + i + '][quantity]', q);
            i++;
        });

        var url;
        if (form.dataset.stop) {
            url = base + '/stops/' + form.dataset.stop;
        } else {
            url = base + '/stops';
            fd.append('trip_id', form.dataset.trip);
        }

        post(url, fd).then(function () {
            dirty = true; load(current.tank, current.date);
        }).catch(function (ex) { err.textContent = ex.message; });
    });

    // Live refresh while the panel is open (skipped while a form is open).
    setInterval(function () {
        if (!current || !panelEl.classList.contains('show')) return;
        if (body.querySelector('.ts-stop-form:not(.d-none)')) return;
        load(current.tank, current.date);
    }, 20000);

    panelEl.addEventListener('hidden.bs.offcanvas', function () {
        if (dirty) window.location.reload();
    });

    // ---------- Trip modal ----------
    if (modal) {
        var form = document.getElementById('tripForm');
        var delForm = document.getElementById('deleteForm');
        var delBtn = document.getElementById('tsDelete');
        var title = document.getElementById('tripModalTitle');
        var typeSel = document.getElementById('tsType');
        var supWrap = document.getElementById('tsSupplierWrap');

        function syncSupplier() {
            supWrap.classList.toggle('d-none', !/pick/i.test(typeSel.value));
        }
        typeSel.addEventListener('change', syncSupplier);

        function fill(d) {
            document.getElementById('tsTank').value = d.tank || document.getElementById('tsTank').options[0].value;
            document.getElementById('tsDate').value = d.date || '';
            if (d.type) typeSel.value = d.type; else typeSel.selectedIndex = 0;
            document.getElementById('tsSupplier').value = d.supplier || '';
            document.getElementById('tsDriver').value = d.driver_id || '';
            document.getElementById('tsHelper').value = d.helper_id || '';
            document.getElementById('tsRemarks').value = d.remarks || '';
            syncSupplier();
        }

        openTripModal = function (d) {
            fill(d);
            if (d.id) {
                form.action = base + '/' + d.id;
                delForm.action = base + '/' + d.id + '/delete';
                title.textContent = 'Edit ' + (d.trip_no || 'trip');
                delBtn.classList.remove('d-none');
            } else {
                form.action = storeUrl;
                title.textContent = 'Schedule trip';
                delBtn.classList.add('d-none');
            }
            panel.hide();
            modal.show();
        };

        document.querySelectorAll('[data-add]').forEach(function (el) {
            el.addEventListener('click', function () {
                openTripModal({ tank: el.dataset.tank, date: el.dataset.date });
            });
        });
    }
});
</script>
@endsection