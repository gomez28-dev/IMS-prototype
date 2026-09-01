@extends('layouts.app')

@section('title', 'Stock Transfers, Borrows & Returns')

@section('content')
<div class="container-fluid px-0">
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
            <a href="{{ route('wetstock.transfers.create', ['type' => 'transfer']) }}" class="btn btn-primary-custom shadow-sm d-flex align-items-center">
                <i class="bi bi-arrow-left-right me-1"></i> Intra-Site Transfer
            </a>
            <a href="{{ route('wetstock.transfers.create', ['type' => 'borrow']) }}" class="btn btn-secondary-custom shadow-sm d-flex align-items-center">
                <i class="bi bi-box-arrow-in-up-right me-1 text-primary"></i> Borrow Stock
            </a>
            <a href="{{ route('wetstock.transfers.create', ['type' => 'return']) }}" class="btn btn-outline-success d-flex align-items-center fw-semibold">
                <i class="bi bi-arrow-counterclockwise me-1"></i> Return Stock
            </a>
        </div>
        @endif
    </div>

    <div class="d-flex justify-content-end mb-3">
        <a href="{{ route('wetstock.dashboard') }}" class="btn btn-outline-secondary btn-sm rounded-pill px-3">
            <i class="bi bi-arrow-left me-1"></i> Back to Dashboard
        </a>
    </div>

    {{-- Tabs --}}
    <ul class="nav nav-pills mb-4 gap-2 bg-white p-2 rounded-4 shadow-sm border justify-content-center">
        <li class="nav-item">
            <a class="nav-link rounded-3 {{ $activeType === 'transfer' ? 'active bg-primary text-white fw-semibold' : 'text-dark' }}" href="{{ route('wetstock.transfers.index', ['type' => 'transfer']) }}">
                <i class="bi bi-arrow-left-right me-1"></i> Intra-Site Transfers
                @if ($transferCount > 0)
                    <span class="badge rounded-pill ms-2 {{ $activeType === 'transfer' ? 'bg-white text-primary' : 'bg-light text-dark border' }}">{{ $transferCount }}</span>
                @endif
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link rounded-3 {{ $activeType === 'borrow' ? 'active bg-primary text-white fw-semibold' : 'text-dark' }}" href="{{ route('wetstock.transfers.index', ['type' => 'borrow']) }}">
                <i class="bi bi-box-arrow-in-up-right me-1"></i> Cross-Site Borrows
                @if ($borrowCount > 0)
                    <span class="badge rounded-pill ms-2 {{ $activeType === 'borrow' ? 'bg-white text-primary' : 'bg-light text-dark border' }}">{{ $borrowCount }}</span>
                @endif
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link rounded-3 {{ $activeType === 'return' ? 'active bg-primary text-white fw-semibold' : 'text-dark' }}" href="{{ route('wetstock.transfers.index', ['type' => 'return']) }}">
                <i class="bi bi-arrow-counterclockwise me-1"></i> Cross-Site Returns
                @if ($returnCount > 0)
                    <span class="badge rounded-pill ms-2 {{ $activeType === 'return' ? 'bg-white text-primary' : 'bg-light text-dark border' }}">{{ $returnCount }}</span>
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
                        <span class="text-muted small d-block">(Gross Borrowed: {{ number_format($bal['borrowed_total']) }}L · Returned: {{ number_format($bal['returned_total']) }}L)</span>
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

    <!-- Filters Card -->
    <div class="card card-custom border-0 shadow-sm mb-4">
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

    <!-- Transfers Table -->
    <div class="card card-custom border-0 shadow-sm overflow-hidden">
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
                                {{ $tx->notes ?: '—' }}
                            </td>
                            @if (Auth::user()->canEditModule2())
                            <td class="pe-4 text-end">
                                @php
                                    $pendingRequest = $tx->modificationRequests->first();
                                @endphp
                                @if ($pendingRequest)
                                    <div class="d-inline-flex align-items-center gap-2">
                                        <span class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle rounded-pill px-2 py-1"
                                              title="Requested by {{ $pendingRequest->requestedBy->name ?? 'User' }} — {{ $pendingRequest->reason }}">
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
