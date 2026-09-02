@extends('layouts.app')

@section('title', 'Assign Deliveries & Fulfillment')

@section('content')
<style>
    .wetstock-deliveries-table {
        table-layout: auto;
        width: 100%;
    }
    .wetstock-deliveries-table thead th {
        font-size: 0.74rem;
        font-weight: 600;
        color: #6b7280;
        white-space: nowrap;
        border-bottom: 1px solid #e9ecef;
    }
    .wetstock-deliveries-table tbody td {
        font-size: 0.8rem;
        vertical-align: middle;
    }
    .wetstock-deliveries-table th,
    .wetstock-deliveries-table td {
        padding-top: 0.5rem;
        padding-bottom: 0.5rem;
        padding-left: 0.45rem;
        padding-right: 0.45rem;
    }
    .wetstock-deliveries-table tbody tr {
        border-bottom: 1px solid #f1f3f5;
    }
    .wetstock-deliveries-table tbody tr:hover {
        background-color: #fafbfc;
    }
    .wetstock-deliveries-table .client-cell {
        max-width: 170px;
        min-width: 130px;
    }
    .wetstock-deliveries-table .client-name {
        font-size: 0.76rem;
        line-height: 1.3;
        display: -webkit-box;
        -webkit-line-clamp: 2;
        -webkit-box-orient: vertical;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: normal;
        word-break: break-word;
    }
    .wetstock-type-badge {
        font-size: 0.66rem;
        font-weight: 600;
        letter-spacing: 0.02em;
        padding: 0.26rem 0.5rem;
        white-space: nowrap;
    }
    .wetstock-activity-cell {
        font-size: 0.74rem;
        line-height: 1.5;
        max-width: 110px;
    }
    .wetstock-allocate-form {
        display: flex;
        flex-direction: column;
        gap: 0.3rem;
        min-width: 210px;
        max-width: 240px;
    }
    .wetstock-allocate-form .allocate-row {
        display: flex;
        gap: 0.25rem;
    }
    .wetstock-allocate-qty {
        width: 52px !important;
        flex-shrink: 0;
        font-size: 0.78rem;
        padding: 0.25rem 0.35rem !important;
    }
    .wetstock-allocate-tank {
        flex: 1 1 auto;
        min-width: 0 !important;
        font-size: 0.76rem;
        padding: 0.25rem 0.35rem !important;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }
    .wetstock-allocate-form .btn {
        font-size: 0.76rem;
        padding: 0.3rem 0.5rem;
    }
</style>
<div class="row justify-content-center">
    <div class="col-12">
        <div class="mb-4">
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-1 small">
                    <li class="breadcrumb-item"><a href="{{ route('wetstock.dashboard') }}" class="text-decoration-none text-secondary">Wet Stock</a></li>
                    <li class="breadcrumb-item active" aria-current="page">Assign Deliveries</li>
                </ol>
            </nav>
            <h3 class="fw-bold text-dark mb-1">
                <i class="bi bi-truck text-primary me-2"></i>Delivery Allocations and Fulfillment
            </h3>
            <p class="text-muted small mb-3">Allocate sales deliveries to tanks (hold stock) and mark as fulfilled (dispatched fuel).</p>

            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                {{-- Search by DR Number or ATL Number — applies to whichever tab is active --}}
                <form method="GET" action="{{ route('wetstock.deliveries.index') }}" class="mb-0 flex-grow-1" style="max-width: 600px;">
                    <input type="hidden" name="tab" value="{{ $activeTab }}">
                    <div class="d-flex align-items-center bg-white border shadow-sm rounded-pill px-3 py-1">
                        <i class="bi bi-search text-muted me-2"></i>
                        <input type="text" name="search" value="{{ $search ?? '' }}" class="form-control form-control-sm border-0 shadow-none p-0" placeholder="Search DR # or ATL #...">
                        @if (!empty($search))
                            <a href="{{ route('wetstock.deliveries.index', ['tab' => $activeTab]) }}" class="text-muted mx-2" title="Clear search">
                                <i class="bi bi-x-lg"></i>
                            </a>
                        @endif
                        <button type="submit" class="btn btn-primary-custom btn-sm rounded-pill px-3 py-1 ms-2">Search</button>
                    </div>
                </form>

                <a href="{{ route('wetstock.dashboard') }}" class="btn btn-light border shadow-sm rounded-pill px-3 py-1 d-flex align-items-center flex-shrink-0">
                    <i class="bi bi-arrow-left-circle me-2 text-primary"></i><span class="fw-medium text-dark small">Back to Dashboard</span>
                </a>
            </div>
        </div>

        <!-- 3 Nav Tabs -->
        <ul class="nav nav-tabs nav-fill border-bottom mb-4" id="assignTabs" role="tablist">
            <li class="nav-item" role="presentation">
                <a class="nav-link d-flex align-items-center justify-content-center gap-2 py-2 small fw-medium {{ $activeTab === 'unassigned' ? 'active text-primary' : 'text-secondary' }}" href="{{ route('wetstock.deliveries.index', ['tab' => 'unassigned', 'search' => $search ?? null]) }}">
                    <i class="bi bi-inbox text-warning"></i>
                    <span>Unassigned Deliveries</span>
                    @if ($unassignedCount > 0)
                        <span class="badge bg-warning text-dark rounded-pill" style="font-size: 0.7rem;">{{ $unassignedCount }}</span>
                    @endif
                </a>
            </li>
            <li class="nav-item" role="presentation">
                <a class="nav-link d-flex align-items-center justify-content-center gap-2 py-2 small fw-medium {{ $activeTab === 'assigned' ? 'active text-primary' : 'text-secondary' }}" href="{{ route('wetstock.deliveries.index', ['tab' => 'assigned', 'search' => $search ?? null]) }}">
                    <i class="bi bi-check2-circle text-primary"></i>
                    <span>Assigned Deliveries</span>
                    @if ($assignedCount > 0)
                        <span class="badge bg-primary text-white rounded-pill" style="font-size: 0.7rem;">{{ $assignedCount }}</span>
                    @endif
                </a>
            </li>
            <li class="nav-item" role="presentation">
                <a class="nav-link d-flex align-items-center justify-content-center gap-2 py-2 small fw-medium {{ $activeTab === 'history' ? 'active text-primary' : 'text-secondary' }}" href="{{ route('wetstock.deliveries.index', ['tab' => 'history', 'search' => $search ?? null]) }}">
                    <i class="bi bi-clock-history text-success"></i>
                    <span>Fulfillment History</span>
                    @if ($historyCount > 0)
                        <span class="badge bg-success-subtle text-success border border-success-subtle rounded-pill" style="font-size: 0.7rem;">{{ $historyCount }}</span>
                    @endif
                </a>
            </li>
        </ul>

        {{-- ==================== TAB 1: UNASSIGNED ==================== --}}
        @if ($activeTab === 'unassigned')
            <div class="card card-custom p-3 border-0 shadow-sm">
                <div class="card-body p-0">
                    @if ($unassignedDeliveries->isEmpty())
                        <div class="text-center py-5">
                            <i class="bi bi-check-circle display-4 text-success mb-3 d-block"></i>
                            <h5 class="fw-bold text-dark">All Deliveries Allocated!</h5>
                            <p class="text-muted">There are currently no deliveries awaiting tank assignment.</p>
                        </div>
                    @else
                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0 wetstock-deliveries-table">
                                <thead class="bg-light">
                                    <tr>
                                        <th class="ps-3 py-3">DR# <i class="bi bi-arrow-down text-muted" title="Sorted highest → lowest"></i></th>
                                        <th class="py-3">ATL#</th>
                                        <th class="py-3">Client</th>
                                        <th class="py-3 text-center">Type</th>
                                        <th class="py-3">Date</th>
                                        <th class="py-3">Qty. Out</th>
                                        <th class="py-3">Allocated / Remaining</th>
                                        <th class="py-3">Activity</th>
                                        @if (Auth::user()->canEditModule2())
                                            <th class="py-3 pe-3">Allocate Tank</th>
                                        @endif
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($unassignedDeliveries as $delivery)
                                        <tr>
                                            <td class="ps-3 fw-semibold text-dark">{{ $delivery->dr_number }}</td>
                                            <td class="small">
                                                @if (!empty($delivery->atl_number))
                                                    {{ $delivery->atl_number }}
                                                @else
                                                    <span class="text-muted">—</span>
                                                @endif
                                            </td>
                                            <td class="client-cell" title="{{ $delivery->order->account ?? '-' }}">
                                                <div class="client-name">{{ $delivery->order->account ?? '-' }}</div>
                                            </td>
                                            <td class="text-center">
                                                @if ($delivery->type === 'PICK-UP')
                                                    <span class="badge badge-type-pickup rounded-pill wetstock-type-badge">PICK-UP</span>
                                                @elseif ($delivery->type === 'SMALL TANKER')
                                                    <span class="badge badge-type-small-tanker rounded-pill wetstock-type-badge">SMALL TANKER</span>
                                                @else
                                                    <span class="badge badge-type-big-tanker rounded-pill wetstock-type-badge">BIG TANKER</span>
                                                @endif
                                            </td>
                                            <td class="text-muted small">
                                                {{ $delivery->delivery_date ? $delivery->delivery_date->format('M d, Y') : '-' }}
                                            </td>
                                            <td class="fw-bold text-dark">{{ number_format($delivery->qty_out) }} L</td>
                                            <td>
                                                @if ($delivery->allocations->isNotEmpty())
                                                    <span class="text-muted small">{{ number_format($delivery->allocated_quantity) }} L allocated</span>
                                                    <span class="badge bg-warning-subtle text-warning border border-warning-subtle rounded-pill d-block mt-1">
                                                        {{ number_format($delivery->remaining_to_allocate) }} L remaining
                                                    </span>
                                                @else
                                                    <span class="badge bg-secondary-subtle text-secondary rounded-pill">0 L / {{ number_format($delivery->qty_out) }} L</span>
                                                @endif
                                            </td>
                                            <td class="wetstock-activity-cell">
                                                <div class="text-dark">{{ $delivery->createdBy->name ?? 'Legacy Data' }}</div>
                                                @php $latestApprovedMod = $delivery->modificationRequests->where('status', 'APPROVED')->sortByDesc('created_at')->first(); @endphp
                                                @if ($latestApprovedMod)
                                                    <div class="text-warning"><i class="bi bi-pencil me-1"></i>{{ $latestApprovedMod->requestedBy->name ?? '—' }}</div>
                                                @endif
                                            </td>
                                            @if (Auth::user()->canEditModule2())
                                                <td class="pe-3">
                                                    <form method="POST" action="{{ route('wetstock.deliveries.allocate', $delivery->id) }}" class="wetstock-allocate-form">
                                                        @csrf
                                                        <div class="allocate-row">
                                                            <input type="number" name="quantity" id="allocate-qty-{{ $delivery->id }}" class="form-control form-control-sm allocate-qty-input wetstock-allocate-qty" min="1" max="{{ $delivery->remaining_to_allocate }}" value="{{ $delivery->remaining_to_allocate }}" title="Quantity to allocate from this DR" required>
                                                            @php
                                                                $siteWarehouse = $warehouses->firstWhere('name', $delivery->order->location ?? null);
                                                            @endphp
                                                            <select name="storage_tank_id" id="allocate-tank-{{ $delivery->id }}" class="form-select form-select-sm allocate-tank-select wetstock-allocate-tank" required>
                                                                <option value="">Select Tank ({{ $delivery->order->location ?? 'No Site' }})</option>
                                                                @if (!$siteWarehouse)
                                                                    <option value="" disabled>No warehouse matches this order's site ({{ $delivery->order->location ?? '-' }})</option>
                                                                @else
                                                                    @foreach ($siteWarehouse->activeTanks as $t)
                                                                        <option value="{{ $t->id }}" data-available="{{ $t->effective_available }}" {{ $t->isFullyContaminated() ? 'disabled' : '' }}>
                                                                            {{ $t->name }} ({{ ucfirst($t->category) }}) — {{ number_format($t->effective_available) }}L Avail
                                                                            {{ $t->hasContamination() ? ' [' . number_format($t->contaminated_liters) . 'L Contaminated' . ($t->isFullyContaminated() ? ' - BLOCKED' : '') . ']' : '' }}
                                                                        </option>
                                                                    @endforeach
                                                                @endif
                                                            </select>
                                                        </div>
                                                        <button type="submit" class="btn btn-sm btn-primary-custom w-100">Allocate</button>
                                                    </form>
                                                </td>
                                            @endif
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>

                        <div class="p-3">
                            {{ $unassignedDeliveries->appends(['tab' => 'unassigned', 'search' => $search ?? null])->links() }}
                        </div>
                    @endif
                </div>
            </div>

        {{-- ==================== TAB 2: ASSIGNED (PENDING FULFILLMENT) ==================== --}}
        @elseif ($activeTab === 'assigned')
            <div class="card card-custom p-3 border-0 shadow-sm">
                <div class="card-body p-0">
                    @if ($assignedDeliveries->isEmpty())
                        <div class="text-center py-5">
                            <i class="bi bi-inbox display-4 text-muted mb-3 d-block"></i>
                            <h5 class="fw-bold text-dark">No Deliveries Pending Fulfillment</h5>
                            <p class="text-muted">Allocate tanks in the Unassigned tab first. Once allocated, deliveries will appear here awaiting fulfillment.</p>
                        </div>
                    @else
                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0 wetstock-deliveries-table">
                                <thead class="bg-light">
                                    <tr>
                                        <th class="ps-3 py-3">DR# <i class="bi bi-arrow-down text-muted" title="Sorted highest → lowest"></i></th>
                                        <th class="py-3">ATL#</th>
                                        <th class="py-3">Client</th>
                                        <th class="py-3 text-center">Type</th>
                                        <th class="py-3">Volume</th>
                                        <th class="py-3">Assigned Tanks Breakdown</th>
                                        <th class="py-3">Status</th>
                                        <th class="py-3">Activity</th>
                                        <th class="pe-3 py-3 text-end">Fulfillment Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($assignedDeliveries as $delivery)
                                        <tr>
                                            <td class="ps-3 fw-semibold text-dark">{{ $delivery->dr_number }}</td>
                                            <td class="small">
                                                @if (!empty($delivery->atl_number))
                                                    {{ $delivery->atl_number }}
                                                @else
                                                    <span class="text-muted">—</span>
                                                @endif
                                            </td>
                                            <td class="client-cell" title="{{ $delivery->order->account ?? '-' }}">
                                                <div class="client-name">{{ $delivery->order->account ?? '-' }}</div>
                                            </td>
                                            <td class="text-center">
                                                @if ($delivery->type === 'PICK-UP')
                                                    <span class="badge badge-type-pickup rounded-pill wetstock-type-badge">PICK-UP</span>
                                                @elseif ($delivery->type === 'SMALL TANKER')
                                                    <span class="badge badge-type-small-tanker rounded-pill wetstock-type-badge">SMALL TANKER</span>
                                                @else
                                                    <span class="badge badge-type-big-tanker rounded-pill wetstock-type-badge">BIG TANKER</span>
                                                @endif
                                            </td>
                                            <td class="fw-bold text-dark font-monospace">{{ number_format($delivery->qty_out) }} L</td>
                                            <td>
                                                <div class="d-flex flex-column gap-1">
                                                    @foreach ($delivery->allocations as $alloc)
                                                        <div class="d-flex align-items-center justify-content-between bg-light rounded px-2 py-1 border small">
                                                            <div>
                                                                <i class="bi bi-fuel-pump text-primary me-1"></i>
                                                                <strong>{{ $alloc->tank->name ?? '—' }}</strong>
                                                                <span class="text-muted">({{ $alloc->tank->warehouse->name ?? '—' }})</span>
                                                            </div>
                                                            <div class="d-flex align-items-center gap-2">
                                                                <span class="font-monospace fw-bold">{{ number_format($alloc->quantity) }} L</span>
                                                                @if (Auth::user()->canEditModule2())
                                                                    <form method="POST" action="{{ route('wetstock.deliveries.unassign', $alloc->id) }}" class="d-inline" onsubmit="return confirm('Remove {{ number_format($alloc->quantity) }}L allocation from {{ $alloc->tank->name }}?');">
                                                                        @csrf
                                                                        <button type="submit" class="btn btn-sm btn-link text-danger p-0" title="Remove tank allocation">
                                                                            <i class="bi bi-x-circle"></i>
                                                                        </button>
                                                                    </form>
                                                                @endif
                                                            </div>
                                                        </div>
                                                    @endforeach
                                                </div>
                                            </td>
                                            <td>
                                                <span class="badge bg-warning-subtle text-warning border border-warning-subtle rounded-pill px-2 py-1" style="color: #a16207 !important;">
                                                    HOLD (Pending)
                                                </span>
                                            </td>
                                            <td class="wetstock-activity-cell">
                                                <div class="text-dark">{{ $delivery->createdBy->name ?? 'Legacy Data' }}</div>
                                                @php $latestApprovedMod = $delivery->modificationRequests->where('status', 'APPROVED')->sortByDesc('created_at')->first(); @endphp
                                                @if ($latestApprovedMod)
                                                    <div class="text-warning"><i class="bi bi-pencil me-1"></i>{{ $latestApprovedMod->requestedBy->name ?? '—' }}</div>
                                                @endif
                                            </td>
                                            <td class="pe-3 text-end">
                                                @if (Auth::user()->canMarkFulfilled())
                                                    <form method="POST" action="{{ route('wetstock.deliveries.fulfill', $delivery->id) }}" class="d-inline" onsubmit="return confirm('Mark DR #{{ $delivery->dr_number }} as FULFILLED? This will officially dispatch fuel from the assigned tanks.');">
                                                        @csrf
                                                        <button type="submit" class="btn btn-sm btn-success rounded-pill px-3 shadow-sm">
                                                            <i class="bi bi-check-lg me-1"></i> Mark as Fulfilled
                                                        </button>
                                                    </form>
                                                @else
                                                    <span class="text-muted small"><i class="bi bi-lock me-1"></i>Awaiting Ops Mgr</span>
                                                @endif
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>

                        <div class="p-3">
                            {{ $assignedDeliveries->appends(['tab' => 'assigned', 'search' => $search ?? null])->links() }}
                        </div>
                    @endif
                </div>
            </div>

        {{-- ==================== TAB 3: HISTORY (FULFILLED) ==================== --}}
        @else
            <div class="d-flex flex-wrap gap-2 align-items-center justify-content-between mb-3">
                <span class="badge rounded-pill px-3 py-2" style="background-color: #eef2ff !important; color: #4338ca !important;">
                    <i class="bi bi-calendar3 me-1"></i> Monthly Summary — {{ \Carbon\Carbon::create($historyYear, $historyMonth, 1)->format('F Y') }}
                    @if ($historyShowAll)
                        <span class="ms-1 badge bg-dark text-white">All Records</span>
                    @endif
                </span>
                <form method="GET" action="{{ route('wetstock.deliveries.index') }}" class="d-flex gap-2 align-items-center">
                    <input type="hidden" name="tab" value="history">
                    @if (!empty($search))
                        <input type="hidden" name="search" value="{{ $search }}">
                    @endif
                    <select name="history_month" class="form-select form-select-sm" style="width:auto;">
                        @for ($m = 1; $m <= 12; $m++)
                            <option value="{{ $m }}" {{ $m == $historyMonth ? 'selected' : '' }}>{{ \Carbon\Carbon::create(2000, $m, 1)->format('M') }}</option>
                        @endfor
                    </select>
                    <select name="history_year" class="form-select form-select-sm" style="width:auto;">
                        @for ($y = (int) $now->format('Y'); $y >= 2024; $y--)
                            <option value="{{ $y }}" {{ $y == $historyYear ? 'selected' : '' }}>{{ $y }}</option>
                        @endfor
                    </select>
                    <button type="submit" class="btn btn-sm btn-primary-custom">Go</button>
                    @if (!$historyShowAll)
                        <a href="{{ route('wetstock.deliveries.index', ['tab' => 'history', 'history_all' => 1]) }}" class="btn btn-sm btn-outline-secondary">Show All</a>
                    @else
                        <a href="{{ route('wetstock.deliveries.index', ['tab' => 'history']) }}" class="btn btn-sm btn-outline-secondary">Current Month</a>
                    @endif
                </form>
            </div>
            @if ($historyMonths->isNotEmpty())
                <div class="d-flex flex-wrap gap-2 mb-3">
                    <span class="text-muted small">Past months:</span>
                    @foreach ($historyMonths as $ym)
                        @php [$yy,$mm] = explode('-', $ym); @endphp
                        <a href="{{ route('wetstock.deliveries.index', ['tab' => 'history', 'history_year' => $yy, 'history_month' => $mm]) }}" class="badge rounded-pill border text-decoration-none {{ $yy == $historyYear && $mm == $historyMonth ? 'bg-primary text-white' : 'bg-light text-dark' }}">{{ \Carbon\Carbon::create($yy, $mm, 1)->format('M Y') }}</a>
                    @endforeach
                </div>
            @endif
            <div class="card card-custom p-3 border-0 shadow-sm">
                <div class="card-body p-0">
                    @if ($historyDeliveries->isEmpty())
                        <div class="text-center py-5">
                            <i class="bi bi-clock-history display-4 text-muted mb-3 d-block"></i>
                            <h5 class="fw-bold text-dark">No Fulfilled Deliveries for {{ \Carbon\Carbon::create($historyYear, $historyMonth, 1)->format('F Y') }}</h5>
                            <p class="text-muted">Try another month or <a href="{{ route('wetstock.deliveries.index', ['tab' => 'history', 'history_all' => 1]) }}">show all records</a>.</p>
                        </div>
                    @else
                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0 wetstock-deliveries-table">
                                <thead class="bg-light">
                                    <tr>
                                        <th class="ps-3 py-3">DR# <i class="bi bi-arrow-down text-muted" title="Sorted highest → lowest"></i></th>
                                        <th class="py-3">ATL#</th>
                                        <th class="py-3">Client</th>
                                        <th class="py-3 text-center">Type</th>
                                        <th class="py-3">Volume</th>
                                        <th class="py-3">Tanks Dispatched From</th>
                                        <th class="py-3">Status</th>
                                        <th class="py-3">Activity</th>
                                        <th class="py-3">Fulfillment Time</th>
                                        @if (Auth::user()->canMarkFulfilled())
                                            <th class="pe-3 py-3 text-end">Action</th>
                                        @endif
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($historyDeliveries as $delivery)
                                        <tr>
                                            <td class="ps-3 fw-semibold text-dark">{{ $delivery->dr_number }}</td>
                                            <td class="small">
                                                @if (!empty($delivery->atl_number))
                                                    {{ $delivery->atl_number }}
                                                @else
                                                    <span class="text-muted">—</span>
                                                @endif
                                            </td>
                                            <td class="client-cell" title="{{ $delivery->order->account ?? '-' }}">
                                                <div class="client-name">{{ $delivery->order->account ?? '-' }}</div>
                                            </td>
                                            <td class="text-center">
                                                @if ($delivery->type === 'PICK-UP')
                                                    <span class="badge badge-type-pickup rounded-pill wetstock-type-badge">PICK-UP</span>
                                                @elseif ($delivery->type === 'SMALL TANKER')
                                                    <span class="badge badge-type-small-tanker rounded-pill wetstock-type-badge">SMALL TANKER</span>
                                                @else
                                                    <span class="badge badge-type-big-tanker rounded-pill wetstock-type-badge">BIG TANKER</span>
                                                @endif
                                            </td>
                                            <td class="fw-bold text-dark font-monospace">{{ number_format($delivery->qty_out) }} L</td>
                                            <td>
                                                <div class="d-flex flex-wrap gap-1">
                                                    @foreach ($delivery->allocations as $alloc)
                                                        <span class="badge bg-light text-dark border px-2 py-1 small">
                                                            {{ $alloc->tank->name ?? '—' }} ({{ number_format($alloc->quantity) }}L)
                                                        </span>
                                                    @endforeach
                                                </div>
                                            </td>
                                            <td>
                                                <span class="badge bg-success-subtle text-success border border-success-subtle rounded-pill px-2 py-1">
                                                    FULFILLED
                                                </span>
                                            </td>
                                            <td class="wetstock-activity-cell">
                                                <div class="text-dark">{{ $delivery->createdBy->name ?? 'Legacy Data' }}</div>
                                                @php $latestApprovedMod = $delivery->modificationRequests->where('status', 'APPROVED')->sortByDesc('created_at')->first(); @endphp
                                                @if ($latestApprovedMod)
                                                    <div class="text-warning"><i class="bi bi-pencil me-1"></i>{{ $latestApprovedMod->requestedBy->name ?? '—' }}</div>
                                                @endif
                                            </td>
                                            <td class="text-muted small">
                                                {{ $delivery->fulfilled_at ? $delivery->fulfilled_at->timezone('Asia/Manila')->format('M d, Y h:i A') : ($delivery->updated_at ? $delivery->updated_at->timezone('Asia/Manila')->format('M d, Y h:i A') : '—') }}
                                            </td>
                                            @if (Auth::user()->canMarkFulfilled())
                                                <td class="pe-3 text-end">
                                                    <form method="POST" action="{{ route('wetstock.deliveries.revert-fulfillment', $delivery->id) }}" class="d-inline" onsubmit="return confirm('Revert DR #{{ $delivery->dr_number }} back to PENDING? This will place the fuel volume back on hold for adjustment.');">
                                                        @csrf
                                                        <button type="submit" class="btn btn-sm btn-outline-warning rounded-pill px-2 py-1 small" title="Revert to Pending">
                                                            <i class="bi bi-arrow-counterclockwise me-1"></i> Revert to Pending
                                                        </button>
                                                    </form>
                                                </td>
                                            @endif
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>

                        <div class="p-3">
                            {{ $historyDeliveries->appends(['tab' => 'history', 'search' => $search ?? null, 'history_month' => $historyMonth, 'history_year' => $historyYear, 'history_all' => $historyShowAll ? 1 : null])->links() }}
                        </div>
                    @endif
                </div>
            </div>
        @endif
    </div>
</div>
@endsection
