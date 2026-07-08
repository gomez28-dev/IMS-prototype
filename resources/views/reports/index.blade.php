@extends('layouts.app')

@section('title', 'Reports')

@section('content')
<div class="d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center mb-4 gap-3">
    <div>
        <h2 class="fw-bold text-dark mb-1">Reports</h2>
        <p class="text-muted small mb-0">Filter and export order and delivery data.</p>
    </div>
</div>

<div class="card card-custom p-4 mb-4 border-0">
    <form method="GET" action="{{ route('reports.index') }}" class="row g-3 align-items-end">
        <div class="col-md-3">
            <label for="from" class="form-label fw-medium text-secondary small">From Date</label>
            <input type="date" name="from" id="from" class="form-control @error('from') is-invalid @enderror" value="{{ old('from', $from ?? '') }}">
        </div>
        <div class="col-md-3">
            <label for="to" class="form-label fw-medium text-secondary small">To Date</label>
            <input type="date" name="to" id="to" class="form-control @error('to') is-invalid @enderror" value="{{ old('to', $to ?? '') }}">
        </div>
        <div class="col-md-2">
            <label for="month" class="form-label fw-medium text-secondary small">Month</label>
            <select name="month" id="month" class="form-control form-select">
                <option value="">All Months</option>
                @foreach (range(1, 12) as $m)
                    <option value="{{ $m }}" {{ ($month ?? '') == $m ? 'selected' : '' }}>{{ \Carbon\Carbon::create()->month($m)->format('F') }}</option>
                @endforeach
            </select>
        </div>
        <div class="col-md-2">
            <label for="year" class="form-label fw-medium text-secondary small">Year</label>
            <select name="year" id="year" class="form-control form-select">
                <option value="">All Years</option>
                @foreach (range(now()->year, 2020) as $y)
                    <option value="{{ $y }}" {{ ($year ?? '') == $y ? 'selected' : '' }}>{{ $y }}</option>
                @endforeach
            </select>
        </div>
        <div class="col-md-2">
            <label for="type" class="form-label fw-medium text-secondary small">Type</label>
            <select name="type" id="reports_type" class="form-control form-select">
                <option value="">All Types</option>
                <option value="PICK-UP" {{ ($type ?? '') === 'PICK-UP' ? 'selected' : '' }}>PICK-UP</option>
                <option value="DELIVERY" {{ ($type ?? '') === 'DELIVERY' ? 'selected' : '' }}>DELIVERY</option>
            </select>
        </div>
        <div class="col-md-2">
            <label for="account" class="form-label fw-medium text-secondary small">Company</label>
            <select name="account" id="account" class="form-control form-select">
                <option value="">All Companies</option>
                @foreach ($clients as $client)
                    <option value="{{ $client->name }}" {{ ($account ?? '') === $client->name ? 'selected' : '' }}>{{ $client->name }}</option>
                @endforeach
            </select>
        </div>
        <div class="col-md-2 d-flex gap-2">
            <button type="submit" class="btn btn-primary-custom flex-fill">
                <i class="bi bi-funnel me-1"></i> Filter
            </button>
            <a href="{{ route('reports.index') }}" class="btn btn-secondary-custom flex-fill">
                <i class="bi bi-x-circle me-1"></i> Clear
            </a>
        </div>
    </form>
</div>

@if ($activeFilter)
<div class="d-flex flex-column gap-2 mb-3">
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center gap-2">
        <p class="text-muted small mb-0">
            Showing {{ $orders->total() }} order(s)
            @if ($activeFilter === 'range')
                from <strong>{{ $from }}</strong> to <strong>{{ $to }}</strong>
            @elseif ($activeFilter === 'month')
                for <strong>{{ \Carbon\Carbon::create()->month($month)->format('F') }} {{ $year }}</strong>
            @elseif ($activeFilter === 'year')
                for year <strong>{{ $year }}</strong>
            @endif
        </p>
        <form method="GET" action="{{ route('reports.export') }}">
            @if ($from) <input type="hidden" name="from" value="{{ $from }}"> @endif
            @if ($to) <input type="hidden" name="to" value="{{ $to }}"> @endif
            @if ($month) <input type="hidden" name="month" value="{{ $month }}"> @endif
            @if ($year) <input type="hidden" name="year" value="{{ $year }}"> @endif
            @if ($type) <input type="hidden" name="type" value="{{ $type }}"> @endif
            @if ($account) <input type="hidden" name="account" value="{{ $account }}"> @endif
            <button type="submit" class="btn btn-secondary-custom shadow-sm d-flex align-items-center">
                <i class="bi bi-download me-2 text-primary"></i> Download Excel
            </button>
        </form>
    </div>

    <div class="card card-custom border-0 overflow-hidden">
        <div class="card-body p-0">
            <!-- Desktop table -->
            <div class="table-responsive d-none d-md-block">
                <table class="table table-custom table-hover align-middle mb-0">
                    <thead>
                        <tr>
                            <th class="ps-4">Account</th>
                            <th>Order Date</th>
                            <th>SO#</th>
                            <th class="text-end">Qty Ordered</th>
                            <th class="text-end">Qty Out</th>
                            <th class="text-center">Balance</th>
                            <th class="text-end pe-4">Deliveries</th>
                        </tr>
                    </thead>
                    <tbody>
                        @if ($orders->isNotEmpty())
                            @foreach ($orders as $order)
                            <tr>
                                <td class="ps-4 fw-semibold text-dark">{{ $order->account }}</td>
                                <td>{{ $order->date ? $order->date->format('Y-m-d') : '' }}</td>
                                <td><span class="badge bg-light text-dark border">{{ $order->so_number }}</span></td>
                                <td class="text-end fw-medium">{{ number_format($order->qty_ordered) }}</td>
                                <td class="text-end fw-medium text-secondary">{{ number_format($order->total_qty_out) }}</td>
                                <td class="text-center">
                                    @if ($order->remaining_balance == 0)
                                        <span class="badge badge-balance-zero rounded-pill">
                                            <i class="bi bi-check-circle-fill me-1"></i> 0
                                        </span>
                                    @else
                                        <span class="badge badge-balance-positive rounded-pill">
                                            <i class="bi bi-clock-history me-1"></i> {{ number_format($order->remaining_balance) }}
                                        </span>
                                    @endif
                                </td>
                                <td class="text-end pe-4">
                                    <a href="{{ route('order.deliveries', $order->id) }}" class="btn btn-sm btn-outline-primary rounded-3 px-2 py-1">
                                        <i class="bi bi-truck me-1"></i> {{ $order->deliveries->count() }}
                                    </a>
                                </td>
                            </tr>
                            @endforeach
                        @else
                            <tr>
                                <td colspan="7" class="text-center py-5 text-muted">
                                    <i class="bi bi-inbox fs-1 d-block mb-3 text-secondary"></i>
                                    No orders match the selected filters.
                                </td>
                            </tr>
                        @endif
                    </tbody>
                </table>
            </div>

            <!-- Mobile cards -->
            <div class="d-md-none p-3">
                @if ($orders->isNotEmpty())
                    @foreach ($orders as $order)
                    <div class="card border-0 bg-light mb-3 rounded-4 shadow-sm">
                        <div class="card-body p-4">
                            <div class="d-flex justify-content-between align-items-start mb-2">
                                <h5 class="fw-bold text-dark mb-0">{{ $order->account }}</h5>
                                <span class="badge bg-light text-dark border">{{ $order->so_number }}</span>
                            </div>
                            <div class="row mb-3 small text-muted">
                                <div class="col-6">
                                    <span class="fw-medium">Date:</span> {{ $order->date ? $order->date->format('Y-m-d') : '' }}
                                </div>
                                <div class="col-6 text-end">
                                    <span class="fw-medium">Qty Ordered:</span> {{ number_format($order->qty_ordered) }}
                                </div>
                            </div>
                            <div class="d-flex justify-content-between align-items-center mb-3">
                                <div>
                                    <span class="text-muted small d-block">Qty Out</span>
                                    <span class="fw-semibold">{{ number_format($order->total_qty_out) }}</span>
                                </div>
                                <div class="text-end">
                                    <span class="text-muted small d-block">Balance</span>
                                    @if ($order->remaining_balance == 0)
                                        <span class="badge badge-balance-zero rounded-pill">
                                            <i class="bi bi-check-circle-fill me-1"></i> 0
                                        </span>
                                    @else
                                        <span class="badge badge-balance-positive rounded-pill">
                                            <i class="bi bi-clock-history me-1"></i> {{ number_format($order->remaining_balance) }}
                                        </span>
                                    @endif
                                </div>
                            </div>
                            <div class="d-flex gap-2">
                                <a href="{{ route('order.deliveries', $order->id) }}" class="btn btn-sm btn-outline-primary rounded-3 px-3 py-2 flex-fill text-center">
                                    <i class="bi bi-truck me-1"></i> Deliveries ({{ $order->deliveries->count() }})
                                </a>
                            </div>
                        </div>
                    </div>
                    @endforeach
                @else
                    <div class="text-center py-5 text-muted">
                        <i class="bi bi-inbox fs-1 d-block mb-3 text-secondary"></i>
                        No orders match the selected filters.
                    </div>
                @endif
            </div>
        </div>
    </div>
    <!-- Pagination -->
    <div class="d-flex justify-content-center mt-4">
        {{ $orders->links() }}
    </div>
</div>
@else
<div class="card card-custom border-0">
    <div class="card-body text-center py-5">
        <i class="bi bi-bar-chart-line fs-1 d-block mb-3 text-secondary"></i>
        <h5 class="text-muted">Select a date range, month, or year above and click <strong>Filter</strong> to generate a report.</h5>
    </div>
</div>
@endif
@endsection
