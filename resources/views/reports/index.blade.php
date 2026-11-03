@extends('layouts.app')

@section('title', 'Reports')

@section('content')
@php
    // SO# tag (same as the Orders Dashboard): regular = 6203, Buy Back = BB-6203 (violet),
    // Fuel Trade = FT-6203 (yellow). The prefix comes from Order::formatted_so_number.
    $soBadge = function ($order) {
        $prefix = $order->so_prefix;
        $cancelled = $order->status === 'Cancelled';

        if ($prefix === 'FT') {
            $class = 'badge so-pill';
            $style = 'background-color:#fef08a;color:#854d0e;border:1px solid #facc15;';
            $title = 'Fuel Trade (Refinery Direct Pick-up)';
        } elseif ($cancelled) {
            $class = 'badge so-pill bg-danger text-white';
            $style = '';
            $title = $prefix === 'BB' ? 'Buy Back (Cancelled)' : 'Cancelled';
        } elseif ($prefix === 'BB') {
            $class = 'badge so-pill';
            $style = 'background-color:#ede9fe;color:#5b21b6;border:1px solid #ddd6fe;';
            $title = 'Buy Back';
        } else {
            $class = 'badge so-pill bg-light text-dark border';
            $style = '';
            $title = '';
        }

        return '<span class="' . $class . '"' . ($style ? ' style="' . $style . '"' : '') . ($title ? ' title="' . e($title) . '"' : '') . '>' . e($order->formatted_so_number) . '</span>';
    };

    // Product chips, identical to the Orders Dashboard so the two read as one set.
    $chipStyles = [
        'U' => 'background:#dcfce7;color:#166534;border:1px solid #bbf7d0;',
        'D' => 'background:#fef3c7;color:#92400e;border:1px solid #fde68a;',
        'P' => 'background:#fee2e2;color:#991b1b;border:1px solid #fecaca;',
    ];

    $chip = function ($code, $title = '') use ($chipStyles) {
        $style = $chipStyles[$code] ?? 'background:#f3f4f6;color:#6b7280;border:1px solid #e5e7eb;';

        return '<span class="prod-chip" style="' . $style . '" title="' . e($title) . '">' . e($code ?: '—') . '</span>';
    };

    $prodRows = [['D', 'Diesel'], ['U', 'Unleaded'], ['P', 'Premium']];

    // One card per volume metric; each lists its litres per product.
    $statCards = [
        ['title' => 'Qty Ordered', 'icon' => 'bi-boxes', 'tint' => 'background:#ecfeff;color:#0891b2;', 'key' => 'ordered'],
        ['title' => 'Qty Delivered', 'icon' => 'bi-truck', 'tint' => 'background:#f0fdf4;color:#16a34a;', 'key' => 'delivered'],
        ['title' => 'Remaining Bal', 'icon' => 'bi-clock-history', 'tint' => 'background:#fffbeb;color:#d97706;', 'key' => 'balance'],
    ];
@endphp
<style>
    /* Labels/badges sized like the Orders Dashboard list */
    #reportsTable .badge {
        font-size: 0.7rem;
        font-weight: 500;
    }
    .so-pill {
        white-space: nowrap;
        letter-spacing: 0.01em;
    }

    /* ---- Summary cards: the Orders Dashboard's card set ---- */
    .stat-title {
        font-size: 0.72rem;
        font-weight: 700;
        letter-spacing: 0.05em;
        text-transform: uppercase;
        color: #374151;
    }
    .stat-icon {
        width: 46px;
        height: 46px;
        border-radius: 0.8rem;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.3rem;
        flex-shrink: 0;
    }
    .stat-row {
        display: flex;
        justify-content: space-between;
        align-items: center;
        border: 1px solid #e5e7eb;
        border-radius: 0.6rem;
        padding: 0.45rem 0.8rem;
        margin-bottom: 0.45rem;
        background: #fff;
    }
    .stat-row .stat-val { font-weight: 700; color: #111827; }
    .stat-total {
        display: flex;
        justify-content: space-between;
        padding: 0.25rem 0.3rem 0;
        color: #6b7280;
    }
    .stat-total .stat-val { font-weight: 600; color: #111827; }
    .stat-big { font-size: 3.6rem; font-weight: 800; line-height: 1; color: #111827; }

    /* Product chips, the same shape the dashboard and Module 3 use */
    .prod-chip {
        display: inline-block;
        font-size: 0.65rem;
        font-weight: 700;
        line-height: 1;
        padding: 0.2rem 0.4rem;
        border-radius: 0.35rem;
        margin: 0 1px;
    }
</style>
<div class="mb-3">
    <a href="{{ route('dashboard') }}" class="text-decoration-none text-secondary small">
        <i class="bi bi-arrow-left me-1"></i> Back to Dashboard
    </a>
</div>
<div class="d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center mb-4 gap-3">
    <div>
        <h2 class="fw-bold text-dark mb-1">Reports</h2>
        <p class="text-muted small mb-0">Filter and export order and delivery data.</p>
    </div>
</div>

<div class="card card-custom p-4 mb-4 border-0">
    <form method="GET" action="{{ route('reports.index') }}" class="row g-3 align-items-end" id="filter-form">
        <div class="col-12 mb-2">
            <div class="d-flex flex-wrap gap-2">
                <button type="button" class="btn btn-mode-tab rounded-3 px-3 py-2 {{ ($filterMode ?? 'range') === 'range' ? 'btn-primary-custom' : 'btn-secondary-custom' }}" data-mode="range">Date range</button>
                <button type="button" class="btn btn-mode-tab rounded-3 px-3 py-2 {{ ($filterMode ?? 'range') === 'month_year' ? 'btn-primary-custom' : 'btn-secondary-custom' }}" data-mode="month_year">Month/Year</button>
                <button type="button" class="btn btn-mode-tab rounded-3 px-3 py-2 {{ ($filterMode ?? 'range') === 'year' ? 'btn-primary-custom' : 'btn-secondary-custom' }}" data-mode="year">Year only</button>
            </div>
            <input type="hidden" name="filter_mode" id="filter_mode" value="{{ $filterMode ?? 'range' }}">
        </div>

        <!-- Date Range Fields -->
        <div class="col-md-3 filter-field" data-mode="range">
            <label for="from" class="form-label fw-medium text-secondary small">From Date</label>
            <input type="date" data-name="from" id="from" class="form-control @error('from') is-invalid @enderror" value="{{ old('from', $from ?? '') }}">
        </div>
        <div class="col-md-3 filter-field" data-mode="range">
            <label for="to" class="form-label fw-medium text-secondary small">To Date</label>
            <input type="date" data-name="to" id="to" class="form-control @error('to') is-invalid @enderror" value="{{ old('to', $to ?? '') }}">
        </div>

        <!-- Month/Year Fields -->
        <div class="col-md-3 filter-field" data-mode="month_year">
            <label for="month" class="form-label fw-medium text-secondary small">Month</label>
            <select data-name="month" id="month" class="form-control form-select">
                <option value="">All Months</option>
                @foreach (range(1, 12) as $m)
                    <option value="{{ $m }}" {{ ($month ?? '') == $m ? 'selected' : '' }}>{{ \Carbon\Carbon::create()->month($m)->format('F') }}</option>
                @endforeach
            </select>
        </div>
        <div class="col-md-3 filter-field" data-mode="month_year">
            <label for="year_month_year" class="form-label fw-medium text-secondary small">Year</label>
            <select data-name="year" id="year_month_year" class="form-control form-select">
                <option value="">All Years</option>
                @foreach (range(now()->year, 2020) as $y)
                    <option value="{{ $y }}" {{ ($year ?? '') == $y ? 'selected' : '' }}>{{ $y }}</option>
                @endforeach
            </select>
        </div>

        <!-- Year Only Field -->
        <div class="col-md-3 filter-field" data-mode="year">
            <label for="year_only" class="form-label fw-medium text-secondary small">Year</label>
            <select data-name="year" id="year_only" class="form-control form-select">
                <option value="">All Years</option>
                @foreach (range(now()->year, 2020) as $y)
                    <option value="{{ $y }}" {{ ($year ?? '') == $y ? 'selected' : '' }}>{{ $y }}</option>
                @endforeach
            </select>
        </div>

        <div class="col-md-2">
            <label for="reports_type" class="form-label fw-medium text-secondary small">Type</label>
            <select name="type" id="reports_type" class="form-control form-select">
                <option value="">All Types</option>
                <option value="BIG TANKER" {{ ($type ?? '') === 'BIG TANKER' ? 'selected' : '' }}>BIG TANKER</option>
                <option value="SMALL TANKER" {{ ($type ?? '') === 'SMALL TANKER' ? 'selected' : '' }}>SMALL TANKER</option>
                <option value="PICK-UP" {{ ($type ?? '') === 'PICK-UP' ? 'selected' : '' }}>PICK-UP</option>
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
{{-- Product-aware summary cards. The figures are read off every order the
     list can show, so "Total Orders" and "Showing N order(s)" are the same
     number, and each card's rows add up to its own total. --}}
<div class="row g-4 mb-5">
    <div class="col-xl-3 col-md-6">
        <div class="card card-custom p-3 border-0 h-100">
            <div class="d-flex align-items-center gap-3">
                <div class="stat-icon" style="background:#fff1ee;color:#f04a1a;"><i class="bi bi-journal-text"></i></div>
                <span class="stat-title">Total Orders</span>
            </div>
            <div class="d-flex flex-grow-1 align-items-center justify-content-center py-3">
                <span class="stat-big">{{ number_format($totalOrders) }}</span>
            </div>
        </div>
    </div>

    @foreach ($statCards as $card)
    <div class="col-xl-3 col-md-6">
        <div class="card card-custom p-3 border-0 h-100">
            <div class="d-flex align-items-center gap-3 mb-3">
                <div class="stat-icon" style="{{ $card['tint'] }}"><i class="bi {{ $card['icon'] }}"></i></div>
                <span class="stat-title">{{ $card['title'] }}</span>
            </div>
            @foreach ($prodRows as [$code, $name])
            @php $value = $productStats[$code][$card['key']] ?? 0; @endphp
            <div class="stat-row">
                <span>{!! $chip($code, $name) !!} <span class="ms-2">{{ $name }}</span></span>
                <span class="stat-val {{ $value < 0 ? 'text-danger' : '' }}">{{ number_format($value) }}</span>
            </div>
            @endforeach
            @php $cardTotal = array_sum(array_column($productStats, $card['key'])); @endphp
            <div class="stat-total">
                <span>Total</span>
                <span class="stat-val {{ $cardTotal < 0 ? 'text-danger' : '' }}">{{ number_format($cardTotal) }}</span>
            </div>
        </div>
    </div>
    @endforeach
</div>

<div class="d-flex flex-column gap-2 mb-3">
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center gap-2">
        <p class="text-muted small mb-0">
            Showing {{ $orders->total() }} order(s)
            @if ($activeFilter === 'range')
                from <strong>{{ $from }}</strong> to <strong>{{ $to }}</strong>
            @elseif ($activeFilter === 'month')
                for <strong>{{ \Carbon\Carbon::create()->month((int) $month)->format('F') }} {{ $year }}</strong>
            @elseif ($activeFilter === 'year')
                for year <strong>{{ $year }}</strong>
            @endif
        </p>
        <form method="GET" action="{{ route('reports.export') }}">
            @if ($filterMode)
                <input type="hidden" name="filter_mode" value="{{ $filterMode }}">
                @if ($filterMode === 'range')
                    @if ($from) <input type="hidden" name="from" value="{{ $from }}"> @endif
                    @if ($to) <input type="hidden" name="to" value="{{ $to }}"> @endif
                @elseif ($filterMode === 'month_year')
                    @if ($month) <input type="hidden" name="month" value="{{ $month }}"> @endif
                    @if ($year) <input type="hidden" name="year" value="{{ $year }}"> @endif
                @elseif ($filterMode === 'year')
                    @if ($year) <input type="hidden" name="year" value="{{ $year }}"> @endif
                @endif
            @endif
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
                <table id="reportsTable" class="table table-custom table-hover align-middle mb-0">
                    <thead>
                        <tr>
                            <th class="ps-4">Account</th>
                            <th>Order Date</th>
                            <th>SO#</th>
                            <th class="text-center">Qty Ordered</th>
                            <th class="text-center">Qty Out</th>
                            <th class="text-center">Balance</th>
                            <th class="text-end pe-4">Deliveries</th>
                        </tr>
                    </thead>
                    <tbody>
                        @if ($orders->isNotEmpty())
                            @foreach ($orders as $order)
                            <tr class="{{ $order->status === 'Cancelled' ? 'order-cancelled' : '' }}">
                                <td class="ps-4 fw-semibold text-dark">{{ $order->account }}</td>
                                <td>{{ $order->date ? $order->date->format('Y-m-d') : '' }}</td>
                                <td>
                                    @if ($order->status === 'Cancelled')
                                        <span class="badge bg-danger-subtle text-danger border border-danger-subtle rounded-pill me-1"><i class="bi bi-x-circle me-1"></i>CANCELLED</span>
                                    @endif
                                    {!! $soBadge($order) !!}
                                </td>
                                {{-- One line per product, keyed by its initial: the same
                                     breakdown the cards sum up. --}}
                                <td>
                                    <div class="pl-block">
                                        @foreach ($rowLines[$order->id] ?? [] as $code => $line)
                                            <div class="pl-row" title="{{ \App\Models\Order::PRODUCT_TYPES[$code] ?? 'Unspecified' }}">
                                                <span class="pl-key">{!! $chip($code) !!}</span>
                                                <span class="pl-num">{{ number_format($line['ordered']) }}</span>
                                            </div>
                                        @endforeach
                                    </div>
                                </td>
                                <td>
                                    <div class="pl-block">
                                        @foreach ($rowLines[$order->id] ?? [] as $code => $line)
                                            <div class="pl-row" title="{{ \App\Models\Order::PRODUCT_TYPES[$code] ?? 'Unspecified' }}">
                                                <span class="pl-key">{!! $chip($code) !!}</span>
                                                <span class="pl-num text-secondary">{{ number_format($line['delivered']) }}</span>
                                            </div>
                                        @endforeach
                                    </div>
                                </td>
                                <td>
                                    <div class="pl-block">
                                        @foreach ($rowLines[$order->id] ?? [] as $code => $line)
                                            <div class="pl-row" title="{{ \App\Models\Order::PRODUCT_TYPES[$code] ?? 'Unspecified' }}">
                                                <span class="pl-key">{!! $chip($code) !!}</span>
                                                <span class="pl-num {{ $line['balance'] < 0 ? 'text-danger' : ($line['balance'] === 0 ? 'text-success' : '') }}">{{ number_format($line['balance']) }}</span>
                                            </div>
                                        @endforeach
                                    </div>
                                </td>
                                <td class="text-end pe-4">
                                    <a href="{{ route('order.deliveries', $order->id) }}?{{ http_build_query(array_filter(['from' => $from, 'to' => $to, 'month' => $month, 'year' => $year, 'type' => $type, 'account' => $account])) }}" class="btn btn-sm btn-outline-primary rounded-3 px-2 py-1">
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
                    <div class="card border-0 bg-light mb-3 rounded-4 shadow-sm {{ $order->status === 'Cancelled' ? 'order-cancelled' : '' }}">
                        <div class="card-body p-4">
                            <div class="d-flex justify-content-between align-items-start mb-2">
                                <h5 class="fw-bold text-dark mb-0">{{ $order->account }}</h5>
                                <span>
                                    @if ($order->status === 'Cancelled')
                                        <span class="badge bg-danger-subtle text-danger border border-danger-subtle rounded-pill me-1"><i class="bi bi-x-circle me-1"></i>CANCELLED</span>
                                    @endif
                                    {!! $soBadge($order) !!}
                                </span>
                            </div>
                            <div class="row mb-3 small text-muted">
                                <div class="col-6">
                                    <span class="fw-medium">Date:</span> {{ $order->date ? $order->date->format('Y-m-d') : '' }}
                                </div>
                                <div class="col-6 text-end">
                                    <span class="fw-medium">Qty Ordered:</span> {{ number_format($order->effective_qty_ordered) }}
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
                                <a href="{{ route('order.deliveries', $order->id) }}?{{ http_build_query(array_filter(['from' => $from, 'to' => $to, 'month' => $month, 'year' => $year, 'type' => $type, 'account' => $account])) }}" class="btn btn-sm btn-outline-primary rounded-3 px-3 py-2 flex-fill text-center">
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

<script>
document.addEventListener('DOMContentLoaded', function () {
    const tabs = document.querySelectorAll('.btn-mode-tab');
    const filterModeInput = document.getElementById('filter_mode');

    function setFilterMode(selectedMode) {
        // Update hidden input value
        filterModeInput.value = selectedMode;

        // Toggle tab button active state classes
        tabs.forEach(tab => {
            if (tab.getAttribute('data-mode') === selectedMode) {
                tab.classList.remove('btn-secondary-custom');
                tab.classList.add('btn-primary-custom');
            } else {
                tab.classList.remove('btn-primary-custom');
                tab.classList.add('btn-secondary-custom');
            }
        });

        // Show/hide relevant filter fields and adjust name attribute
        document.querySelectorAll('.filter-field').forEach(field => {
            const isVisible = field.getAttribute('data-mode') === selectedMode;
            if (isVisible) {
                field.style.setProperty('display', '', 'important');
                field.querySelectorAll('input, select').forEach(input => {
                    input.setAttribute('name', input.getAttribute('data-name'));
                });
            } else {
                field.style.setProperty('display', 'none', 'important');
                field.querySelectorAll('input, select').forEach(input => {
                    input.removeAttribute('name');
                });
            }
        });
    }

    tabs.forEach(tab => {
        tab.addEventListener('click', function () {
            setFilterMode(this.getAttribute('data-mode'));
        });
    });

    // Initialize filter mode on page load
    setFilterMode(filterModeInput.value || 'range');
});
</script>
@endsection