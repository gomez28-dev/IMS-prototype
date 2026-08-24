@extends('layouts.app')

@section('title', 'Stock Transfers')

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
                <i class="bi bi-arrow-left-right text-primary me-2"></i>Stock Transfers
            </h3>
            <p class="text-muted small mb-0">Record and track fuel movements between Depot Tanks and Tanker Trucks.</p>
        </div>
        @if (Auth::user()->canEditModule2())
        <div class="d-flex gap-2">
            <a href="{{ route('wetstock.transfers.create', ['mode' => 'depot_to_tanker']) }}" class="btn btn-primary-custom shadow-sm d-flex align-items-center">
                <i class="bi bi-truck me-2"></i> Load to Tanker
            </a>
            <a href="{{ route('wetstock.transfers.create', ['mode' => 'tanker_to_depot']) }}" class="btn btn-secondary-custom shadow-sm d-flex align-items-center">
                <i class="bi bi-fuel-pump me-2"></i> Offload to Depot
            </a>
            <a href="{{ route('wetstock.transfers.create') }}" class="btn btn-outline-secondary d-flex align-items-center">
                <i class="bi bi-plus-circle me-1"></i> Custom Transfer
            </a>
        </div>
        @endif
    </div>

    <!-- Filters Card -->
    <div class="card card-custom border-0 shadow-sm mb-4">
        <div class="card-body p-3">
            <form method="GET" action="{{ route('wetstock.transfers.index') }}" class="row g-2 align-items-end">
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
                        <a href="{{ route('wetstock.transfers.index') }}" class="btn btn-sm btn-outline-secondary" title="Reset Filters"><i class="bi bi-x-lg"></i></a>
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
                            <th class="py-3 text-muted small fw-bold">Source Tank / Depot</th>
                            <th class="py-3 text-center text-muted small fw-bold" style="width: 40px;"></th>
                            <th class="py-3 text-muted small fw-bold">Destination Tank / Tanker</th>
                            <th class="py-3 text-end text-muted small fw-bold">Volume</th>
                            <th class="py-3 text-muted small fw-bold">Operator</th>
                            <th class="pe-4 py-3 text-muted small fw-bold">Notes</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($transfers as $tx)
                        <tr>
                            <td class="ps-4 font-monospace fw-bold text-dark">{{ $tx->transfer_number }}</td>
                            <td class="small">{{ $tx->transfer_date->format('M d, Y') }}</td>
                            <td>
                                <div class="fw-semibold text-dark">{{ $tx->sourceTank->name }}</div>
                                <div class="text-muted small">
                                    <span class="badge {{ $tx->sourceTank->isTanker() ? 'badge-type-small-tanker' : 'badge-type-delivery' }} px-2 py-0">
                                        {{ $tx->sourceTank->category === 'tanker' ? 'Tanker' : 'Depot' }}
                                    </span>
                                    {{ $tx->sourceWarehouse->name }}
                                </div>
                            </td>
                            <td class="text-center text-muted">
                                <i class="bi bi-arrow-right text-primary fs-5"></i>
                            </td>
                            <td>
                                <div class="fw-semibold text-dark">{{ $tx->destinationTank->name }}</div>
                                <div class="text-muted small">
                                    <span class="badge {{ $tx->destinationTank->isTanker() ? 'badge-type-small-tanker' : 'badge-type-delivery' }} px-2 py-0">
                                        {{ $tx->destinationTank->category === 'tanker' ? 'Tanker' : 'Depot' }}
                                    </span>
                                    {{ $tx->destinationWarehouse->name }}
                                </div>
                            </td>
                            <td class="text-end fw-bold text-primary font-monospace">
                                {{ number_format($tx->quantity) }} L
                            </td>
                            <td class="small text-muted">
                                <i class="bi bi-person me-1"></i>{{ $tx->transferredBy->name ?? 'System' }}
                            </td>
                            <td class="pe-4 small text-muted text-truncate" style="max-width: 200px;">
                                {{ $tx->notes ?: '—' }}
                            </td>
                        </tr>
                        @empty
                        <tr>
                            <td colspan="8" class="text-center py-5 text-muted">
                                <i class="bi bi-arrow-left-right fs-1 d-block mb-3 text-secondary"></i>
                                No stock transfers recorded.
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
