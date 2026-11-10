@extends('layouts.app')

@section('title', 'Job Orders')

@section('content')
<style>
    .jo-card:hover { transform: none; }
    .jo-pill { display: inline-flex; align-items: center; border-radius: 999px; padding: 0.2rem 0.6rem; font-size: 0.72rem; font-weight: 700; background: rgba(255, 69, 0, 0.12); color: #c2410c; }
    .jo-pill.is-small { background: #e0e7ff; color: #3730a3; }
    .jo-tag { font-size: 0.68rem; font-weight: 700; padding: 0.2rem 0.55rem; border-radius: 999px; white-space: nowrap; }
    .jo-tag.late { background: #fee2e2; color: #991b1b; }
    .jo-tag.today { background: #ffedd5; color: #c2410c; }
    .jo-tag.soon { background: #fef3c7; color: #92400e; }
    .jo-tag.ok { background: #f1f5f9; color: #475569; }
    .jo-tag.nodate { background: #e0f2fe; color: #075985; }
    .jo-table th { font-size: 0.7rem; text-transform: uppercase; color: var(--text-muted); font-weight: 700; white-space: nowrap; }
    .jo-table td { vertical-align: middle; }
</style>

@php
    $tabs = [
        'schedule'  => ['To schedule', $toSchedule->total()],
        'scheduled' => ['Scheduled', $scheduled->total()],
        'waiting'   => ['Waiting for a tanker', $waiting->total()],
    ];
    $pager = $tab === 'scheduled' ? $scheduled : ($tab === 'waiting' ? $waiting : $toSchedule);

    // Tag for the required date.
    $tag = function ($job) use ($today) {
        if (! $job->required) {
            return ['nodate', 'No date'];
        }
        $diff = $today->diffInDays($job->required, false);
        if ($diff < 0)  { return ['late', 'Overdue ' . abs($diff) . 'd']; }
        if ($diff === 0) { return ['today', 'Today']; }
        if ($diff <= 2) { return ['soon', $job->required->format('M j') . ' (' . ($diff === 1 ? 'tomorrow' : 'in ' . $diff . 'd') . ')']; }

        return ['ok', $job->required->format('M j, Y')];
    };
@endphp

<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <div>
        <h4 class="fw-bold text-dark mb-0">Job Orders</h4>
        <div class="text-muted small">Deliveries waiting to be put on a trip, by the date the client needs them</div>
    </div>
</div>

@if ($errors->any())
    <div class="alert alert-danger rounded-4 border-0 shadow-sm" style="background-color: #fee2e2; color: #b91c1c;">
        @foreach ($errors->all() as $error)<div>{{ $error }}</div>@endforeach
    </div>
@endif

<ul class="nav nav-pills gap-2 mb-3">
    @foreach ($tabs as $key => [$label, $n])
        <li class="nav-item">
            <a class="nav-link {{ $tab === $key ? 'active' : '' }}" style="{{ $tab === $key ? 'background: var(--brand-color);' : 'color: var(--text-dark);' }} border-radius: 10px; font-size: 0.85rem;"
               href="{{ route('logistics.job-orders.index', ['tab' => $key]) }}">{{ $label }} <span class="badge text-bg-light ms-1">{{ $n }}</span></a>
        </li>
    @endforeach
</ul>

<div class="card card-custom jo-card border-0 shadow-sm">
    <div class="table-responsive">
        <table class="table table-custom jo-table mb-0">

            {{-- ===== To schedule ===== --}}
            @if ($tab === 'schedule')
                <thead>
                    <tr><th>Needed</th><th>DR# / SO#</th><th>Client</th><th>Product</th><th>Tanker</th><th></th></tr>
                </thead>
                <tbody>
                    @forelse ($toSchedule as $j)
                        @php [$cls, $txt] = $tag($j); @endphp
                        <tr>
                            <td>
                                <span class="jo-tag {{ $cls }}">{{ $txt }}</span>
                                <div class="text-muted" style="font-size: 0.68rem;">{{ $j->has_date ? 'Delivery date' : 'Date needed (no delivery date yet)' }}</div>
                            </td>
                            <td><div class="fw-semibold">DR# {{ $j->dr ?: '-' }}</div><div class="small text-muted">SO# {{ $j->so ?: '-' }}</div></td>
                            <td><div class="fw-semibold">{{ $j->client }}</div><div class="small text-muted">{{ $j->location }}</div></td>
                            <td><div>{{ $j->products ?: '-' }}</div><div class="small text-muted">{{ number_format($j->liters) }} L</div></td>
                            <td>@if ($j->tank)<span class="jo-pill {{ $j->tank->small ? 'is-small' : '' }}">{{ $j->tank->code }}</span> <span class="small text-muted">{{ $j->tank->name }}</span>@endif</td>
                            <td class="text-end">
                                @if ($canEdit)
                                    <button type="button" class="btn btn-primary-custom btn-sm"
                                            data-schedule
                                            data-delivery="{{ $j->delivery_id }}"
                                            data-tank="{{ $j->tank->id ?? '' }}"
                                            data-date="{{ ($j->required && $j->required->gte($today)) ? $j->required->toDateString() : $today->toDateString() }}"
                                            data-label="DR# {{ $j->dr ?: '-' }} &middot; {{ $j->client }} &middot; {{ number_format($j->liters) }} L"
                                            data-tanker="{{ $j->tank ? $j->tank->code . ' - ' . $j->tank->name : '' }}">Schedule</button>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="text-center text-muted py-5">Nothing waiting. Every delivery loaded to a tanker is already on a trip.</td></tr>
                    @endforelse
                </tbody>

            {{-- ===== Scheduled ===== --}}
            @elseif ($tab === 'scheduled')
                <thead>
                    <tr><th>Needed</th><th>DR# / SO#</th><th>Client</th><th>Product</th><th>Tanker</th><th>Trip</th></tr>
                </thead>
                <tbody>
                    @forelse ($scheduled as $j)
                        @php [$cls, $txt] = $tag($j); @endphp
                        <tr>
                            <td><span class="jo-tag ok">{{ $j->required ? $j->required->format('M j, Y') : '-' }}</span></td>
                            <td><div class="fw-semibold">DR# {{ $j->dr ?: '-' }}</div><div class="small text-muted">SO# {{ $j->so ?: '-' }}</div></td>
                            <td><div class="fw-semibold">{{ $j->client }}</div><div class="small text-muted">{{ $j->location }}</div></td>
                            <td><div>{{ $j->products ?: '-' }}</div><div class="small text-muted">{{ number_format($j->liters) }} L</div></td>
                            <td>@if ($j->tank)<span class="jo-pill {{ $j->tank->small ? 'is-small' : '' }}">{{ $j->tank->code }}</span> <span class="small text-muted">{{ $j->tank->name }}</span>@endif</td>
                            <td>
                                <a href="{{ route('logistics.schedule', ['view' => 'day', 'date' => $j->trip->trip_date]) }}" class="fw-semibold text-decoration-none">{{ $j->trip->trip_no }}</a>
                                <div class="small text-muted">{{ \Carbon\Carbon::parse($j->trip->trip_date)->format('M j, Y') }}</div>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="text-center text-muted py-5">No deliveries are on a trip yet.</td></tr>
                    @endforelse
                </tbody>

            {{-- ===== Waiting for a tanker ===== --}}
            @else
                <thead>
                    <tr><th>Needed</th><th>DR# / SO#</th><th>Client</th><th>Product</th><th></th></tr>
                </thead>
                <tbody>
                    @forelse ($waiting as $j)
                        @php [$cls, $txt] = $tag($j); @endphp
                        <tr>
                            <td>
                                <span class="jo-tag {{ $cls }}">{{ $txt }}</span>
                                <div class="text-muted" style="font-size: 0.68rem;">{{ $j->has_date ? 'Delivery date' : 'Date needed' }}</div>
                            </td>
                            <td><div class="fw-semibold">DR# {{ $j->dr ?: '-' }}</div><div class="small text-muted">SO# {{ $j->so ?: '-' }}</div></td>
                            <td><div class="fw-semibold">{{ $j->client }}</div><div class="small text-muted">{{ $j->location }}</div></td>
                            <td><div>{{ $j->products ?: '-' }}</div><div class="small text-muted">{{ number_format($j->liters) }} L</div></td>
                            <td class="text-end"><a href="{{ route('wetstock.deliveries.index', ['tab' => 'unassigned']) }}" class="btn btn-outline-primary btn-sm">Assign in Wet Stock</a></td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="text-center text-muted py-5">Every open delivery is already assigned to a tanker.</td></tr>
                    @endforelse
                </tbody>
            @endif
        </table>
    </div>
    @if ($pager->hasPages())
        <div class="p-3 border-top">{{ $pager->links() }}</div>
    @endif
</div>

@if ($tab === 'waiting')
    <div class="text-muted small mt-2">These still have to be loaded to a tanker in Wet Stock before they can go on a trip.</div>
@endif

@if ($canEdit)
    <div class="modal fade" id="schedModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content border-0 rounded-4 shadow">
                <form method="POST" action="{{ route('logistics.job-orders.schedule') }}">
                    @csrf
                    <input type="hidden" name="delivery_id" id="sDelivery">
                    <input type="hidden" name="storage_tank_id" id="sTank">
                    <div class="modal-header border-0 pb-0">
                        <h5 class="modal-title fw-bold">Schedule delivery</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <div class="fw-semibold" id="sLabel"></div>
                        <div class="small text-muted mb-3" id="sTanker"></div>
                        <div class="row g-3">
                            <div class="col-12">
                                <label class="form-label small fw-semibold">Trip date</label>
                                <input type="date" name="scheduled_date" id="sDate" class="form-control" required>
                                <div class="small text-muted mt-1">If this differs from the delivery date, a date change is requested for admin or the HOD to approve.</div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small fw-semibold">Driver</label>
                                <select name="driver_id" class="form-select">
                                    <option value="">Use the day's crew</option>
                                    @foreach ($drivers as $id => $name)
                                        <option value="{{ $id }}">{{ $name }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small fw-semibold">Helper</label>
                                <select name="helper_id" class="form-select">
                                    <option value="">Use the day's crew</option>
                                    @foreach ($helpers as $id => $name)
                                        <option value="{{ $id }}">{{ $name }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-12 small text-muted" style="margin-top: -0.4rem;">
                                Leave blank to use the crew already assigned to this tanker that day. The delivery joins that day's trip as a drop-off, with its compartments from Wet Stock.
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer border-0 pt-0">
                        <button type="button" class="btn btn-secondary-custom" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary-custom">Schedule</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
    document.addEventListener('DOMContentLoaded', function () {
        var modal = new bootstrap.Modal(document.getElementById('schedModal'));

        document.querySelectorAll('[data-schedule]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                document.getElementById('sDelivery').value = btn.dataset.delivery;
                document.getElementById('sTank').value = btn.dataset.tank;
                document.getElementById('sDate').value = btn.dataset.date;
                document.getElementById('sLabel').textContent = btn.dataset.label;
                document.getElementById('sTanker').textContent = btn.dataset.tanker ? 'Loaded on ' + btn.dataset.tanker : '';
                modal.show();
            });
        });
    });
    </script>
@endif
@endsection