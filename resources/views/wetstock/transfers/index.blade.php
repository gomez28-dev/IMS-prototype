
@extends('layouts.app')

@section('title', 'Stock Transfers, Borrows & Returns')

@section('content')
<style>
    .wetstock-transfers-table {
        table-layout: auto;
        width: 100%;
    }
    .wetstock-transfers-table thead th {
        font-size: 0.74rem;
        font-weight: 600;
        color: #6b7280;
        white-space: nowrap;
        border-bottom: 1px solid #e9ecef;
    }
    .wetstock-transfers-table tbody td {
        font-size: 0.8rem;
        vertical-align: middle;
    }
    .wetstock-transfers-table th,
    .wetstock-transfers-table td {
        padding-top: 0.5rem;
        padding-bottom: 0.5rem;
        padding-left: 0.45rem;
        padding-right: 0.45rem;
    }
    .wetstock-transfers-table tbody tr {
        border-bottom: 1px solid #f1f3f5;
    }
    .wetstock-transfers-table tbody tr:hover {
        background-color: #fafbfc;
    }
    .wetstock-transfers-table .tank-cell .badge {
        font-size: 0.66rem;
    }
    .wetstock-transfers-table .notes-cell {
        max-width: 180px;
        font-size: 0.78rem;
    }
</style>
<div class="row justify-content-center">
    <div class="col-12">
        <div class="mb-4">
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-1 small">
                    <li class="breadcrumb-item"><a href="{{ route('wetstock.dashboard') }}" class="text-decoration-none text-secondary">Wet Stock</a></li>
                    <li class="breadcrumb-item active" aria-current="page">Stock Transfers</li>
                </ol>
            </nav>
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-1">
                <h3 class="fw-bold text-dark mb-0">
                    <i class="bi bi-arrow-left-right text-primary me-2"></i>Stock Transfers & Cross-Site Logistics
                </h3>
                @if (Auth::user()->canEditModule2())
                    <div class="d-flex flex-wrap gap-2">
                        <a href="{{ route('wetstock.transfers.create', ['type' => 'transfer']) }}" class="btn btn-primary-custom btn-sm rounded-pill px-3 shadow-sm d-flex align-items-center">
                            <i class="bi bi-arrow-left-right me-1"></i> Intra-Site Transfer
                        </a>
                        <a href="{{ route('wetstock.transfers.create', ['type' => 'borrow']) }}" class="btn btn-secondary-custom btn-sm rounded-pill px-3 shadow-sm d-flex align-items-center">
                            <i class="bi bi-box-arrow-in-up-right me-1 text-primary"></i> Borrow Stock
                        </a>
                        <a href="{{ route('wetstock.transfers.create', ['type' => 'return']) }}" class="btn btn-outline-success btn-sm rounded-pill px-3 d-flex align-items-center fw-semibold">
                            <i class="bi bi-arrow-counterclockwise me-1"></i> Return Stock
                        </a>
                    </div>
                @endif
            </div>
            <p class="text-muted small mb-3">Record intra-site depot/tanker transfers, cross-site borrowings, and stock returns.</p>

            <div class="d-flex justify-content-end">
                <a href="{{ route('wetstock.dashboard') }}" class="btn btn-light border shadow-sm rounded-pill px-3 py-1 d-flex align-items-center flex-shrink-0">
                    <i class="bi bi-arrow-left-circle me-2 text-primary"></i><span class="fw-medium text-dark small">Back to Dashboard</span>
                </a>
            </div>
        </div>

        <!-- 3 Nav Tabs -->
        <ul class="nav nav-tabs nav-fill border-bottom mb-4" id="transferTabs" role="tablist">
            <li class="nav-item" role="presentation">
                <a class="nav-link d-flex align-items-center justify-content-center gap-2 py-2 small fw-medium {{ $activeType === 'transfer' ? 'active text-primary' : 'text-secondary' }}" href="{{ route('wetstock.transfers.index', ['type' => 'transfer']) }}">
                    <i class="bi bi-arrow-left-right text-primary"></i>
                    <span>Intra-Site Transfers</span>
                    @if ($transferCount > 0)
                        <span class="badge bg-primary text-white rounded-pill" style="font-size: 0.7rem;">{{ $transferCount }}</span>
                    @endif
                </a>
            </li>
            <li class="nav-item" role="presentation">
                <a class="nav-link d-flex align-items-center justify-content-center gap-2 py-2 small fw-medium {{ $activeType === 'borrow' ? 'active text-primary' : 'text-secondary' }}" href="{{ route('wetstock.transfers.index', ['type' => 'borrow']) }}">
                    <i class="bi bi-box-arrow-in-up-right text-warning"></i>
                    <span>Cross-Site Borrows</span>
                    @if ($borrowCount > 0)
                        <span class="badge bg-warning text-dark rounded-pill" style="font-size: 0.7rem;">{{ $borrowCount }}</span>
                    @endif
                </a>
            </li>
            <li class="nav-item" role="presentation">
                <a class="nav-link d-flex align-items-center justify-content-center gap-2 py-2 small fw-medium {{ $activeType === 'return' ? 'active text-primary' : 'text-secondary' }}" href="{{ route('wetstock.transfers.index', ['type' => 'return']) }}">
                    <i class="bi bi-arrow-counterclockwise text-success"></i>
                    <span>Cross-Site Returns</span>
                    @if ($returnCount > 0)
                        <span class="badge bg-success-subtle text-success border border-success-subtle rounded-pill" style="font-size: 0.7rem;">{{ $returnCount }}</span>
                    @endif
                </a>
            </li>
        </ul>

        {{-- Contextual Note Boxes for Borrow & Return --}}
        @if ($activeType === 'borrow')
            <div class="alert alert-info border-0 rounded-4 p-3 mb-4 shadow-sm">
                <div class="d-flex align-items-center gap-2 mb-2">
                    <i class="bi bi-info-circle-fill fs-5 text-primary"></i>
                    <h6 class="mb-0 fw-bold text-dark">Outstanding Borrowed Fuel Summary (Net Still Owed Back)</h6>
                </div>
                <p class="mb-2 text-muted small">Tracking net fuel volumes borrowed across sites:</p>
                <div class="d-flex flex-wrap gap-3">
                    @foreach ($outstandingBalances as $bal)
                        <div class="bg-white rounded-3 px-3 py-2 border shadow-sm flex-fill">
                            <span class="text-muted small d-block">Borrowed from <strong class="text-dark">{{ $bal['warehouse_name'] }}</strong>:</span>
                            <span class="fw-bold text-primary fs-5 font-monospace">{{ number_format($bal['outstanding']) }} L</span>
                            <span class="text-muted small d-block">(Gross Borrowed: {{ number_format($bal['borrowed_total']) }}L Â· Returned: {{ number_format($bal['returned_total']) }}L)</span>
                        </div>
                    @endforeach
                </div>
            </div>
        @elseif ($activeType === 'return')
            <div class="alert alert-success border-0 rounded-4 p-3 mb-4 shadow-sm">
                <div class="d-flex align-items-center gap-2 mb-2">
                    <i class="bi bi-arrow-counterclockwise fs-5 text-success"></i>
                    <h6 class="mb-0 fw-bold text-dark">Total Return Stocks Needed per Site</h6>
                </div>
                <p class="mb-2 text-muted small">Volume remaining to return to each source depot:</p>
                <div class="d-flex flex-wrap gap-3">
                    @foreach ($outstandingBalances as $bal)
                        <div class="bg-white rounded-3 px-3 py-2 border shadow-sm flex-fill">
                            <span class="text-muted small d-block">Return Stock for <strong class="text-dark">{{ $bal['warehouse_name'] }}</strong>:</span>
                            <span class="fw-bold text-success fs-5 font-monospace">{{ number_format($bal['outstanding']) }} L</span>
                            <span class="text-muted small d-block">remaining to return</span>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif

        <div class="d-flex flex-wrap gap-2 align-items-center justify-content-between mb-3">
            <span class="badge rounded-pill px-3 py-2" style="background-color: #eef2ff !important; color: #4338ca !important;">
                <i class="bi bi-calendar3 me-1"></i> Monthly — {{ \Carbon\Carbon::create($filterYear, $filterMonth, 1)->format('F Y') }}
                @if ($showAll)
                    <span class="ms-1 badge bg-dark text-white">All Records</span>
                @endif
            </span>
            <form method="GET" action="{{ route('wetstock.transfers.index') }}" class="d-flex gap-2 align-items-center">
                <input type="hidden" name="type" value="{{ $activeType }}">
                @if ($searchQuery)
                    <input type="hidden" name="search" value="{{ $searchQuery }}">
                @endif
                @if ($currentWarehouse)
                    <input type="hidden" name="warehouse_id" value="{{ $currentWarehouse }}">
                @endif
                <select name="filter_month" class="form-select form-select-sm" style="width:auto;">
                    @for ($m = 1; $m <= 12; $m++)
                        <option value="{{ $m }}" {{ $m == $filterMonth ? 'selected' : '' }}>{{ \Carbon\Carbon::create(2000, $m, 1)->format('M') }}</option>
                    @endfor
                </select>
                <select name="filter_year" class="form-select form-select-sm" style="width:auto;">
                    @for ($y = (int) $now->format('Y'); $y >= 2024; $y--)
                        <option value="{{ $y }}" {{ $y == $filterYear ? 'selected' : '' }}>{{ $y }}</option>
                    @endfor
                </select>
                <button type="submit" class="btn btn-sm btn-primary-custom">Go</button>
                @if (!$showAll)
                    <a href="{{ route('wetstock.transfers.index', ['type' => $activeType, 'show_all' => 1]) }}" class="btn btn-sm btn-outline-secondary">Show All</a>
                @else
                    <a href="{{ route('wetstock.transfers.index', ['type' => $activeType]) }}" class="btn btn-sm btn-outline-secondary">Current Month</a>
                @endif
            </form>
        </div>
        @if ($availableMonths->isNotEmpty())
            <div class="d-flex flex-wrap gap-2 mb-3 align-items-center">
                <span class="text-muted small">Past months:</span>
                @foreach ($availableMonths as $ym)
                    @php [$yy,$mm] = explode('-', $ym); @endphp
                    <a href="{{ route('wetstock.transfers.index', ['type' => $activeType, 'filter_year' => $yy, 'filter_month' => $mm]) }}" class="badge rounded-pill border text-decoration-none {{ $yy == $filterYear && $mm == $filterMonth ? 'bg-primary text-white' : 'bg-light text-dark' }}">{{ \Carbon\Carbon::create($yy, $mm, 1)->format('M Y') }}</a>
                @endforeach
            </div>
        @endif

        <!-- Filters Card -->
        <div class="card card-custom border-0 shadow-sm mb-4">
            <div class="card-body p-3">
                <form method="GET" action="{{ route('wetstock.transfers.index') }}" class="row g-2 align-items-end">
                    <input type="hidden" name="type" value="{{ $activeType }}">
                    <input type="hidden" name="filter_month" value="{{ $filterMonth }}">
                    <input type="hidden" name="filter_year" value="{{ $filterYear }}">
                    @if ($showAll)
                        <input type="hidden" name="show_all" value="1">
                    @endif
                    <div class="col-md-3">
                        <label class="form-label small text-muted mb-1">Search</label>
                        <div class="input-group input-group-sm">
                            <span class="input-group-text bg-light"><i class="bi bi-search"></i></span>
                            <input type="text" name="search" class="form-control" placeholder="Transfer #, Tank name..." value="{{ $searchQuery }}">
                        </div>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small text-muted mb-1">Warehouse</label>
                        <select name="warehouse_id" class="form-select form-select-sm">
                            <option value="">All Warehouses</option>
                            @foreach ($warehouses as $wh)
                                <option value="{{ $wh->id }}" {{ $currentWarehouse == $wh->id ? 'selected' : '' }}>{{ $wh->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label small text-muted mb-1">From Date</label>
                        <input type="date" name="from" class="form-control form-control-sm" value="{{ $from }}">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label small text-muted mb-1">To Date</label>
                        <input type="date" name="to" class="form-control form-control-sm" value="{{ $to }}">
                    </div>
                    <div class="col-md-2 d-flex gap-2">
                        <button type="submit" class="btn btn-sm btn-primary-custom w-100">Filter</button>
                        @if ($searchQuery || $currentWarehouse || $from || $to)
                            <a href="{{ route('wetstock.transfers.index', ['type' => $activeType, 'filter_month' => $filterMonth, 'filter_year' => $filterYear]) }}" class="btn btn-sm btn-outline-secondary" title="Reset Filters"><i class="bi bi-x-lg"></i></a>
                        @endif
                    </div>
                </form>
            </div>
        </div>

        <!-- Transfers Table -->
        <div class="card card-custom p-3 border-0 shadow-sm">
            <div class="card-body p-0">
                @if ($transfers->isEmpty())
                    <div class="text-center py-5">
                        <i class="bi bi-arrow-left-right display-4 text-muted mb-3 d-block"></i>
                        <h5 class="fw-bold text-dark">No {{ ucfirst($activeType) }} Records Found</h5>
                        <p class="text-muted">Try adjusting your filters, or create a new record using the buttons above.</p>
                    </div>
                @else
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0 wetstock-transfers-table">
                            <thead class="bg-light">
                                <tr>
                                    <th class="ps-3 py-3">Transfer #</th>
                                    <th class="py-3">Date</th>
                                    <th class="py-3">Source Tank</th>
                                    <th class="py-3 text-center" style="width: 40px;"></th>
                                    <th class="py-3">Destination Tank</th>
                                    <th class="py-3 text-end">Volume</th>
                                    <th class="py-3">Operator</th>
                                    <th class="py-3">Notes</th>
                                    @if (Auth::user()->canEditModule2())
                                        <th class="pe-3 py-3 text-end">Action</th>
                                    @endif
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($transfers as $tx)
                                <tr>
                                    <td class="ps-3 fw-semibold text-dark font-monospace">{{ $tx->transfer_number }}</td>
                                    <td class="text-muted small">{{ $tx->transfer_date ? $tx->transfer_date->format('M d, Y') : '-' }}</td>
                                    <td class="tank-cell">
                                        <div class="fw-semibold text-dark">{{ $tx->sourceTank->name }}</div>
                                        <div class="text-muted small d-flex align-items-center gap-1 mt-1">
                                            <span class="badge {{ $tx->sourceTank->isTanker() ? 'badge-type-small-tanker' : 'badge-type-pickup' }} wetstock-type-badge rounded-pill px-2 py-0">
                                                {{ $tx->sourceTank->isTanker() ? 'Tanker' : 'Depot' }}
                                            </span>
                                            <span>{{ $tx->sourceWarehouse->name }}</span>
                                        </div>
                                    </td>
                                    <td class="text-center text-muted">
                                        <i class="bi bi-arrow-right text-primary fs-5"></i>
                                    </td>
                                    <td class="tank-cell">
                                        <div class="fw-semibold text-dark">{{ $tx->destinationTank->name }}</div>
                                        <div class="text-muted small d-flex align-items-center gap-1 mt-1">
                                            <span class="badge {{ $tx->destinationTank->isTanker() ? 'badge-type-small-tanker' : 'badge-type-pickup' }} wetstock-type-badge rounded-pill px-2 py-0">
                                                {{ $tx->destinationTank->isTanker() ? 'Tanker' : 'Depot' }}
                                            </span>
                                            <span>{{ $tx->destinationWarehouse->name }}</span>
                                        </div>
                                    </td>
                                    <td class="text-end fw-bold text-primary font-monospace">
                                        {{ number_format($tx->quantity) }} L
                                    </td>
                                    <td class="small text-muted">
                                        <i class="bi bi-person me-1"></i>{{ $tx->transferredBy->name ?? 'System' }}
                                    </td>
                                    <td class="notes-cell text-muted text-truncate">
                                        {{ $tx->notes ?: 'â€”' }}
                                    </td>
                                    @if (Auth::user()->canEditModule2())
                                    <td class="pe-3 text-end">
                                        @php
                                            $pendingRequest = $tx->modificationRequests->first();
                                        @endphp
                                        @if ($pendingRequest)
                                            <div class="d-inline-flex align-items-center gap-2">
                                                <span class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle rounded-pill px-2 py-1"
                                                      title="Requested by {{ $pendingRequest->requestedBy->name ?? 'User' }} â€” {{ $pendingRequest->reason }}">
                                                    <i class="bi bi-hourglass-split me-1"></i>Pending Approval
                                                </span>
                                                @if (Auth::user()->canApproveModule2Modification())
                                                    <form method="POST" action="{{ route('approvals.approve', $pendingRequest->id) }}" class="d-inline"
                                                          onsubmit="return confirm('Approve Modification Request #{{ $pendingRequest->id }} for {{ $tx->transfer_number }}? This will immediately update the live inventory record.');">
                                                        @csrf
                                                        <button type="submit" class="btn btn-sm btn-success rounded-3 px-2 py-1" title="Approve & Apply">
                                                            <i class="bi bi-check-lg"></i>
                                                        </button>
                                                    </form>
                                                    <form method="POST" action="{{ route('approvals.reject', $pendingRequest->id) }}" class="d-inline"
                                                          onsubmit="return confirm('Reject Modification Request #{{ $pendingRequest->id }} for {{ $tx->transfer_number }}?');">
                                                        @csrf
                                                        <button type="submit" class="btn btn-sm btn-outline-danger rounded-3 px-2 py-1" title="Reject Request">
                                                            <i class="bi bi-x-lg"></i>
                                                        </button>
                                                    </form>
                                                @else
                                                    <span class="text-muted small" title="Only Operations Admin, Operations Manager, or Portal Admin can approve"><i class="bi bi-lock me-1"></i>Awaiting Approval</span>
                                                @endif
                                            </div>
                                        @else
                                            <a href="{{ route('wetstock.transfers.edit', $tx->id) }}" class="btn btn-sm btn-outline-secondary rounded-3 px-2 py-1" title="Request Modification">
                                                <i class="bi bi-pencil me-1"></i> Modify
                                            </a>
                                        @endif
                                    </td>
                                    @endif
                                </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    <div class="p-3">
                        {{ $transfers->appends(['type' => $activeType, 'search' => $searchQuery, 'warehouse_id' => $currentWarehouse, 'from' => $from, 'to' => $to])->links() }}
                    </div>
                @endif
            </div>
        </div>
    </div>
</div>
@endsection
