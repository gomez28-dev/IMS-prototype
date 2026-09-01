@extends('layouts.app')

@section('title', 'Stock Transfers, Borrows & Returns')

@section('content')
<style>
    /* ---- Segmented tab bar (matches Delivery Allocations page) ---- */
    .nav-tabs-flat {
        display: flex;
        background: #fff;
        border-radius: 1rem;
        box-shadow: 0 1px 3px rgba(16, 24, 40, 0.06);
        overflow: hidden;
        margin-bottom: 1.5rem;
    }
    .nav-tabs-flat .tab-item {
        flex: 1 1 0;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: .55rem;
        padding: 1.1rem 1.25rem;
        color: #6c757d;
        font-weight: 600;
        font-size: .95rem;
        text-decoration: none;
        border-right: 1px solid #eef0f2;
        background: #fff;
        transition: background .15s ease, color .15s ease;
        white-space: nowrap;
    }
    .nav-tabs-flat .tab-item:last-child { border-right: none; }
    .nav-tabs-flat .tab-item:hover { background: #f8f9fa; color: #495057; }
    .nav-tabs-flat .tab-item.active { color: #fd7e14; background: #fff6ee; }
    .nav-tabs-flat .tab-item i { font-size: 1.05rem; }
    .nav-tabs-flat .tab-badge {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-width: 24px;
        height: 22px;
        padding: 0 7px;
        border-radius: 999px;
        font-size: .75rem;
        font-weight: 700;
        background: #eef0f2;
        color: #6c757d;
    }
    .nav-tabs-flat .tab-item.active .tab-badge { background: #fd7e14; color: #fff; }

    /* ---- Page action buttons, lighter / consistent radius ---- */
    .btn-page-action {
        border-radius: .65rem;
        padding: .55rem 1.1rem;
        font-weight: 600;
        font-size: .9rem;
    }

    /* ---- Summary info cards (replace heavy alert blocks) ---- */
    .summary-panel {
        background: #fff;
        border: 1px solid #eef0f2;
        border-radius: 1rem;
        box-shadow: 0 1px 3px rgba(16, 24, 40, 0.06);
        padding: 1.1rem 1.25rem;
        margin-bottom: 1.5rem;
    }
    .summary-panel .summary-heading {
        display: flex;
        align-items: center;
        gap: .5rem;
        font-weight: 700;
        color: #212529;
        margin-bottom: .75rem;
    }
    .summary-stat {
        background: #f8f9fa;
        border-radius: .75rem;
        padding: .75rem 1rem;
        flex: 1 1 220px;
    }
    .summary-stat .stat-value { font-size: 1.15rem; font-weight: 700; font-family: var(--bs-font-monospace, monospace); }

    /* ---- Table / filter cards ---- */
    .card-flat {
        border: 1px solid #eef0f2;
        border-radius: 1rem;
        box-shadow: 0 1px 3px rgba(16, 24, 40, 0.06);
    }
</style>

<div class="container-fluid px-0">
    {{-- Header --}}
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center mb-4 gap-3">
        <div>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-1 small">
                    <li class="breadcrumb-item"><a href="{{ route('wetstock.dashboard') }}" class="text-decoration-none">Wet Stock</a></li>
                    <li class="breadcrumb-item active" aria-current="page">Stock Transfers</li>
                </ol>
            </nav>
            <h3 class="fw-bold text-dark mb-0">
                <i class="bi bi-arrow-left-right text-primary me-2"></i>Stock Transfers & Cross-Site Logistics
            </h3>
            <p class="text-muted small mb-0">Record intra-site depot/tanker transfers, cross-site borrowings, and stock returns.</p>
        </div>
        @if (Auth::user()->canEditModule2())
        <div class="d-flex flex-wrap gap-2">
            <a href="{{ route('wetstock.transfers.create', ['type' => 'transfer']) }}" class="btn btn-primary-custom btn-page-action d-flex align-items-center">
                <i class="bi bi-arrow-left-right me-1"></i> Intra-Site Transfer
            </a>
            <a href="{{ route('wetstock.transfers.create', ['type' => 'borrow']) }}" class="btn btn-outline-secondary btn-page-action d-flex align-items-center">
                <i class="bi bi-box-arrow-in-up-right me-1 text-primary"></i> Borrow Stock
            </a>
            <a href="{{ route('wetstock.transfers.create', ['type' => 'return']) }}" class="btn btn-outline-success btn-page-action d-flex align-items-center fw-semibold">
                <i class="bi bi-arrow-counterclockwise me-1"></i> Return Stock
            </a>
        </div>
        @endif
    </div>

    {{-- Segmented Tabs (matches Delivery Allocations page style) --}}
    <div class="nav-tabs-flat">
        <a class="tab-item {{ $activeType === 'transfer' ? 'active' : '' }}" href="{{ route('wetstock.transfers.index', ['type' => 'transfer']) }}">
            <i class="bi bi-arrow-left-right"></i>
            <span>Intra-Site Transfers</span>
            @if ($transferCount > 0)
                <span class="tab-badge {{ $activeType === 'transfer' ? 'active' : '' }}">{{ $transferCount }}</span>
            @endif
        </a>
        <a class="tab-item {{ $activeType === 'borrow' ? 'active' : '' }}" href="{{ route('wetstock.transfers.index', ['type' => 'borrow']) }}">
            <i class="bi bi-box-arrow-in-up-right"></i>
            <span>Cross-Site Borrows</span>
            @if ($borrowCount > 0)
                <span class="tab-badge {{ $activeType === 'borrow' ? 'active' : '' }}">{{ $borrowCount }}</span>
            @endif
        </a>
        <a class="tab-item {{ $activeType === 'return' ? 'active' : '' }}" href="{{ route('wetstock.transfers.index', ['type' => 'return']) }}">
            <i class="bi bi-arrow-counterclockwise"></i>
            <span>Cross-Site Returns</span>
            @if ($returnCount > 0)
                <span class="tab-badge {{ $activeType === 'return' ? 'active' : '' }}">{{ $returnCount }}</span>
            @endif
        </a>
    </div>

    {{-- Contextual Summary Panels for Borrow & Return (flat card style, no heavy alert coloring) --}}
    @if ($activeType === 'borrow')
        <div class="summary-panel">
            <div class="summary-heading">
                <i class="bi bi-info-circle-fill text-primary"></i>
                <span>Outstanding Borrowed Fuel Summary (Net Still Owed Back)</span>
            </div>
            <p class="mb-3 text-muted small">Tracking net fuel volumes borrowed across sites:</p>
            <div class="d-flex flex-wrap gap-3">
                @foreach ($outstandingBalances as $bal)
                    <div class="summary-stat">
                        <span class="text-muted small d-block">Borrowed from <strong class="text-dark">{{ $bal['warehouse_name'] }}</strong>:</span>
                        <span class="stat-value text-primary">{{ number_format($bal['outstanding']) }} L</span>
                        <span class="text-muted small d-block">(Gross Borrowed: {{ number_format($bal['borrowed_total']) }}L ┬╖ Returned: {{ number_format($bal['returned_total']) }}L)</span>
                    </div>
                @endforeach
            </div>
        </div>
    @elseif ($activeType === 'return')
        <div class="summary-panel">
            <div class="summary-heading">
                <i class="bi bi-arrow-counterclockwise text-success"></i>
                <span>Total Return Stocks Needed per Site</span>
            </div>
            <p class="mb-3 text-muted small">Volume remaining to return to each source depot:</p>
            <div class="d-flex flex-wrap gap-3">
                @foreach ($outstandingBalances as $bal)
                    <div class="summary-stat">
                        <span class="text-muted small d-block">Return Stock for <strong class="text-dark">{{ $bal['warehouse_name'] }}</strong>:</span>
                        <span class="stat-value text-success">{{ number_format($bal['outstanding']) }} L</span>
                        <span class="text-muted small d-block">remaining to return</span>
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    {{-- Filters Card --}}
    <div class="card card-flat mb-4">
        <div class="card-body p-3">
            <form method="GET" action="{{ route('wetstock.transfers.index') }}" class="row g-2 align-items-end">
                <input type="hidden" name="type" value="{{ $activeType }}">
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
                        <a href="{{ route('wetstock.transfers.index', ['type' => $activeType]) }}" class="btn btn-sm btn-outline-secondary" title="Reset Filters"><i class="bi bi-x-lg"></i></a>
                    @endif
                </div>
            </form>
        </div>
    </div>

    {{-- Transfers Table --}}
    <div class="card card-flat overflow-hidden">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="bg-light">
                        <tr>
                            <th class="ps-4 py-3 text-muted small fw-bold">Transfer #</th>
                            <th class="py-3 text-muted small fw-bold">Date</th>
                            <th class="py-3 text-muted small fw-bold">Source Tank</th>
                            <th class="py-3 text-center text-muted small fw-bold" style="width: 40px;"></th>
                            <th class="py-3 text-muted small fw-bold">Destination Tank</th>
                            <th class="py-3 text-end text-muted small fw-bold">Volume</th>
                            <th class="py-3 text-muted small fw-bold">Operator</th>
                            <th class="py-3 text-muted small fw-bold">Notes</th>
                            @if (Auth::user()->canEditModule2())
                                <th class="pe-4 py-3 text-end text-muted small fw-bold">Action</th>
                            @endif
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($transfers as $tx)
                        <tr>
                            <td class="ps-4 font-monospace fw-bold text-dark">{{ $tx->transfer_number }}</td>
                            <td class="small">{{ $tx->transfer_date ? $tx->transfer_date->format('M d, Y') : '-' }}</td>
                            <td>
                                <div class="fw-semibold text-dark">{{ $tx->sourceTank->name }}</div>
                                <div class="text-muted small d-flex align-items-center gap-1 mt-0.5">
                                    <span class="badge {{ $tx->sourceTank->isTanker() ? 'badge-type-small-tanker' : 'badge-type-pickup' }} px-2 py-0">
                                        {{ $tx->sourceTank->isTanker() ? 'Tanker' : 'Depot' }}
                                    </span>
                                    <span>{{ $tx->sourceWarehouse->name }}</span>
                                </div>
                            </td>
                            <td class="text-center text-muted">
                                <i class="bi bi-arrow-right text-primary fs-5"></i>
                            </td>
                            <td>
                                <div class="fw-semibold text-dark">{{ $tx->destinationTank->name }}</div>
                                <div class="text-muted small d-flex align-items-center gap-1 mt-0.5">
                                    <span class="badge {{ $tx->destinationTank->isTanker() ? 'badge-type-small-tanker' : 'badge-type-pickup' }} px-2 py-0">
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
                            <td class="small text-muted text-truncate" style="max-width: 180px;">
                                {{ $tx->notes ?: 'ΓÇö' }}
                            </td>
                            @if (Auth::user()->canEditModule2())
                            <td class="pe-4 text-end">
                                @php
                                    $pendingRequest = $tx->modificationRequests->first();
                                @endphp
                                @if ($pendingRequest)
                                    <div class="d-inline-flex align-items-center gap-2">
                                        <span class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle rounded-pill px-2 py-1"
                                              title="Requested by {{ $pendingRequest->requestedBy->name ?? 'User' }} ΓÇö {{ $pendingRequest->reason }}">
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
                        @empty
                        <tr>
                            <td colspan="{{ Auth::user()->canEditModule2() ? 9 : 8 }}" class="text-center py-5 text-muted">
                                <i class="bi bi-arrow-left-right fs-1 d-block mb-3 text-secondary"></i>
                                No {{ $activeType }} records found.
                            </td>
                        </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="d-flex justify-content-center mt-4">
        {{ $transfers->links() }}
    </div>
</div>
@endsection
