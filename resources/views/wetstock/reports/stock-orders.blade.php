@extends('layouts.app')

@section('title', 'Stock Orders Report')

@section('content')
@php
    // Product chips and the paid / lifted colouring, the same vocabulary the
    // Purchase Orders page and the Sales report use.
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

    // The date the report counted this PO under: the PO date, or the request /
    // creation date for rows raised before the PO date was captured.
    $poDate = fn ($po) => $po->po_date ?? $po->request_date ?? $po->created_at;

    // One PO's product lines for one column, so a row reads like the cards.
    $cell = function (array $volumes = []) use ($chip) {
        $parts = [];
        foreach ($volumes as $code => $qty) {
            if ($qty === 0) {
                continue;
            }
            $parts[] = '<div class="pl-row"><span class="pl-key">' . $chip($code) . '</span>'
                . '<span class="pl-num">' . number_format($qty) . '</span></div>';
        }

        return $parts === [] ? '<span class="text-muted small">—</span>' : implode('', $parts);
    };
@endphp

<style>
    /* Summary cards: the same card set as the Sales report. */
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

    .prod-chip {
        display: inline-block;
        font-size: 0.65rem;
        font-weight: 700;
        line-height: 1;
        padding: 0.2rem 0.4rem;
        border-radius: 0.35rem;
        margin: 0 1px;
    }
    .pl-row {
        display: flex;
        align-items: center;
        gap: 0.4rem;
        line-height: 1.6;
    }
    .pl-key { flex: 0 0 auto; }
    .pl-num { font-variant-numeric: tabular-nums; }
    #stockOrdersReportTable .badge { font-size: 0.7rem; font-weight: 500; }
</style>

<div class="mb-3">
    <a href="{{ route('stock-orders.purchase-orders.index') }}" class="text-decoration-none text-secondary small">
        <i class="bi bi-arrow-left me-1"></i> Back to Purchase Orders
    </a>
</div>

<div class="d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center mb-4 gap-3">
    <div>
        <h2 class="fw-bold text-dark mb-1">Stock Orders Report</h2>
        <p class="text-muted small mb-0">Filter purchase orders by period and supplier, compare what was ordered with what has been lifted, and export the figures.</p>
    </div>
</div>

<div class="card card-custom p-4 mb-4 border-0">
    <form method="GET" action="{{ route('stock-orders.reports.index') }}" class="row g-3 align-items-end" id="filter-form">
        <div class="col-12 mb-2">
            <div class="d-flex flex-wrap gap-2">
                <button type="button" class="btn btn-mode-tab rounded-3 px-3 py-2 {{ $filters['mode'] === 'range' ? 'btn-primary-custom' : 'btn-secondary-custom' }}" data-mode="range">Date range</button>
                <button type="button" class="btn btn-mode-tab rounded-3 px-3 py-2 {{ $filters['mode'] === 'month_year' ? 'btn-primary-custom' : 'btn-secondary-custom' }}" data-mode="month_year">Month/Year</button>
                <button type="button" class="btn btn-mode-tab rounded-3 px-3 py-2 {{ $filters['mode'] === 'year' ? 'btn-primary-custom' : 'btn-secondary-custom' }}" data-mode="year">Year only</button>
            </div>
            <input type="hidden" name="filter_mode" id="filter_mode" value="{{ $filters['mode'] }}">
        </div>

        <div class="col-md-3 filter-field" data-mode="range">
            <label for="from" class="form-label fw-medium text-secondary small">From Date</label>
            <input type="date" data-name="from" id="from" class="form-control" value="{{ old('from', $filters['from'] ?? '') }}">
        </div>
        <div class="col-md-3 filter-field" data-mode="range">
            <label for="to" class="form-label fw-medium text-secondary small">To Date</label>
            <input type="date" data-name="to" id="to" class="form-control" value="{{ old('to', $filters['to'] ?? '') }}">
        </div>

        <div class="col-md-3 filter-field" data-mode="month_year">
            <label for="month" class="form-label fw-medium text-secondary small">Month</label>
            <select data-name="month" id="month" class="form-control form-select">
                <option value="">All Months</option>
                @foreach (range(1, 12) as $m)
                    <option value="{{ $m }}" {{ ($filters['month'] ?? '') == $m ? 'selected' : '' }}>{{ \Carbon\Carbon::create()->month($m)->format('F') }}</option>
                @endforeach
            </select>
        </div>
        <div class="col-md-3 filter-field" data-mode="month_year">
            <label for="year_month_year" class="form-label fw-medium text-secondary small">Year</label>
            <select data-name="year" id="year_month_year" class="form-control form-select">
                <option value="">All Years</option>
                @foreach (range(now()->year, 2020) as $y)
                    <option value="{{ $y }}" {{ ($filters['year'] ?? '') == $y ? 'selected' : '' }}>{{ $y }}</option>
                @endforeach
            </select>
        </div>

        <div class="col-md-3 filter-field" data-mode="year">
            <label for="year_only" class="form-label fw-medium text-secondary small">Year</label>
            <select data-name="year" id="year_only" class="form-control form-select">
                <option value="">All Years</option>
                @foreach (range(now()->year, 2020) as $y)
                    <option value="{{ $y }}" {{ ($filters['year'] ?? '') == $y ? 'selected' : '' }}>{{ $y }}</option>
                @endforeach
            </select>
        </div>

        <div class="col-md-3">
            <label for="supplier_id" class="form-label fw-medium text-secondary small">Supplier</label>
            <select name="supplier_id" id="supplier_id" class="form-control form-select">
                <option value="">All Suppliers</option>
                @foreach ($suppliers as $supplier)
                    <option value="{{ $supplier->id }}" {{ (string) ($filters['supplier_id'] ?? '') === (string) $supplier->id ? 'selected' : '' }}>{{ $supplier->company_name }}</option>
                @endforeach
            </select>
        </div>

        <div class="col-md-4 d-flex gap-2">
            <button type="submit" class="btn btn-primary-custom flex-fill">
                <i class="bi bi-funnel me-1"></i> Filter
            </button>
            <a href="{{ route('stock-orders.reports.index') }}" class="btn btn-secondary-custom flex-fill">
                <i class="bi bi-x-circle me-1"></i> Clear
            </a>
        </div>
    </form>
</div>

@if ($activeFilter)
<div class="row g-4 mb-5">
    <div class="col-xl-3 col-md-6">
        <div class="card card-custom p-3 border-0 h-100">
            <div class="d-flex align-items-center gap-3">
                <div class="stat-icon" style="background:#fff1ee;color:#f04a1a;"><i class="bi bi-card-checklist"></i></div>
                <span class="stat-title">Purchase Orders</span>
            </div>
            <div class="d-flex flex-grow-1 align-items-center justify-content-center py-3">
                <span class="stat-big">{{ number_format($summary['count']) }}</span>
            </div>
            <div class="stat-total">
                <span>Committed</span>
                <span class="stat-val">₱{{ number_format($summary['value'], 2) }}</span>
            </div>
        </div>
    </div>

    @foreach ([
        ['key' => 'ordered', 'title' => 'Litres Ordered', 'icon' => 'bi-cart-check', 'tint' => 'background:#eff6ff;color:#1d4ed8;'],
        ['key' => 'lifted', 'title' => 'Lifted', 'icon' => 'bi-truck', 'tint' => 'background:#ecfdf5;color:#047857;'],
        ['key' => 'unlifted', 'title' => 'Still Unlifted', 'icon' => 'bi-hourglass-split', 'tint' => 'background:#fef2f2;color:#b91c1c;'],
    ] as $card)
    <div class="col-xl-3 col-md-6">
        <div class="card card-custom p-3 border-0 h-100">
            <div class="d-flex align-items-center gap-3 mb-3">
                <div class="stat-icon" style="{{ $card['tint'] }}"><i class="bi {{ $card['icon'] }}"></i></div>
                <span class="stat-title">{{ $card['title'] }}</span>
            </div>
            @foreach ($prodRows as [$code, $name])
            <div class="stat-row">
                <span>{!! $chip($code, $name) !!} <span class="ms-2">{{ $name }}</span></span>
                <span class="stat-val">{{ number_format($summary[$card['key']][$code] ?? 0) }}</span>
            </div>
            @endforeach
            <div class="stat-total">
                <span>Total</span>
                <span class="stat-val">{{ number_format(array_sum($summary[$card['key']])) }}</span>
            </div>
        </div>
    </div>
    @endforeach
</div>

<div class="d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center gap-2 mb-3">
    <p class="text-muted small mb-0">
        Showing {{ $purchaseOrders->total() }} purchase order(s)
        @if ($activeFilter === 'range')
            from <strong>{{ $filters['from'] }}</strong> to <strong>{{ $filters['to'] }}</strong>
        @elseif ($activeFilter === 'month')
            for <strong>{{ \Carbon\Carbon::create()->month((int) $filters['month'])->format('F') }} {{ $filters['year'] }}</strong>
        @elseif ($activeFilter === 'year')
            for year <strong>{{ $filters['year'] }}</strong>
        @endif
    </p>
    <form method="GET" action="{{ route('stock-orders.reports.export') }}">
        <input type="hidden" name="filter_mode" value="{{ $filters['mode'] }}">
        @if ($filters['from']) <input type="hidden" name="from" value="{{ $filters['from'] }}"> @endif
        @if ($filters['to']) <input type="hidden" name="to" value="{{ $filters['to'] }}"> @endif
        @if ($filters['month']) <input type="hidden" name="month" value="{{ $filters['month'] }}"> @endif
        @if ($filters['year']) <input type="hidden" name="year" value="{{ $filters['year'] }}"> @endif
        @if ($filters['supplier_id']) <input type="hidden" name="supplier_id" value="{{ $filters['supplier_id'] }}"> @endif
        <button type="submit" class="btn btn-secondary-custom shadow-sm d-flex align-items-center">
            <i class="bi bi-download me-2 text-primary"></i> Download Excel
        </button>
    </form>
</div>

<div class="card card-custom border-0 overflow-hidden">
    <div class="card-body p-0">
        {{-- Desktop table --}}
        <div class="table-responsive d-none d-md-block">
            <table id="stockOrdersReportTable" class="table table-custom table-hover align-middle mb-0">
                <thead>
                    <tr>
                        <th class="ps-4">PO#</th>
                        <th>PO Date</th>
                        <th>Supplier</th>
                        <th>Status</th>
                        <th class="text-end">Amount</th>
                        <th class="text-center">Qty Ordered</th>
                        <th class="text-center">Qty Lifted</th>
                        <th class="text-center">Still Unlifted</th>
                        <th class="text-end pe-4">Open</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($purchaseOrders as $po)
                    @php $poLines = $lines[$po->id] ?? ['ordered' => [], 'lifted' => [], 'unlifted' => []]; @endphp
                    <tr>
                        <td class="ps-4 fw-semibold text-dark">{{ $po->po_number }}</td>
                        <td>{{ $poDate($po) ? \Carbon\Carbon::parse($poDate($po))->format('Y-m-d') : '—' }}</td>
                        <td>{{ $po->supplier->name ?? $po->supplier_name ?? '—' }}</td>
                        <td>
                            <span class="badge rounded-pill {{ $po->status === 'Fulfilled' ? 'bg-success-subtle text-success border border-success-subtle' : 'bg-warning-subtle text-warning-emphasis border border-warning-subtle' }}">
                                {{ $po->status ?: 'Pending' }}
                            </span>
                        </td>
                        <td class="text-end fw-semibold">₱{{ number_format((float) $po->total_amount, 2) }}</td>
                        <td class="text-center"><div class="pl-block">{!! $cell($poLines['ordered']) !!}</div></td>
                        <td class="text-center"><div class="pl-block">{!! $cell($poLines['lifted']) !!}</div></td>
                        <td class="text-center"><div class="pl-block">{!! $cell($poLines['unlifted']) !!}</div></td>
                        <td class="text-end pe-4">
                            <a href="{{ route('stock-orders.purchase-orders.show', $po->id) }}" class="btn btn-sm btn-outline-primary rounded-3 px-2 py-1">
                                <i class="bi bi-box-arrow-up-right"></i>
                            </a>
                        </td>
                    </tr>
                    @empty
                    <tr>
                        <td colspan="9" class="text-center py-5 text-muted">
                            <i class="bi bi-inbox fs-1 d-block mb-3 text-secondary"></i>
                            No purchase orders match the selected filters.
                        </td>
                    </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        {{-- Mobile cards --}}
        <div class="d-md-none">
            @forelse ($purchaseOrders as $po)
            @php $poLines = $lines[$po->id] ?? ['ordered' => [], 'lifted' => [], 'unlifted' => []]; @endphp
            <div class="border-bottom p-3">
                <div class="d-flex justify-content-between align-items-start mb-2">
                    <div>
                        <div class="fw-semibold text-dark">{{ $po->po_number }}</div>
                        <div class="text-muted small">{{ $po->supplier->name ?? $po->supplier_name ?? '—' }}</div>
                    </div>
                    <span class="badge rounded-pill {{ $po->status === 'Fulfilled' ? 'bg-success-subtle text-success' : 'bg-warning-subtle text-warning-emphasis' }}">{{ $po->status ?: 'Pending' }}</span>
                </div>
                <div class="small text-muted mb-2">
                    {{ $poDate($po) ? \Carbon\Carbon::parse($poDate($po))->format('M j, Y') : '—' }}
                    · ₱{{ number_format((float) $po->total_amount, 2) }}
                </div>
                <div class="row g-2 small">
                    <div class="col-4"><span class="text-muted d-block">Ordered</span>{!! $cell($poLines['ordered']) !!}</div>
                    <div class="col-4"><span class="text-muted d-block">Lifted</span>{!! $cell($poLines['lifted']) !!}</div>
                    <div class="col-4"><span class="text-muted d-block">Unlifted</span>{!! $cell($poLines['unlifted']) !!}</div>
                </div>
            </div>
            @empty
            <div class="text-center py-5 text-muted">No purchase orders match the selected filters.</div>
            @endforelse
        </div>
    </div>
</div>

<div class="mt-3">{{ $purchaseOrders->links() }}</div>
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
        filterModeInput.value = selectedMode;

        tabs.forEach(tab => {
            if (tab.getAttribute('data-mode') === selectedMode) {
                tab.classList.remove('btn-secondary-custom');
                tab.classList.add('btn-primary-custom');
            } else {
                tab.classList.remove('btn-primary-custom');
                tab.classList.add('btn-secondary-custom');
            }
        });

        // Only the visible period fields carry a name, so switching modes never
        // submits a stale date alongside the new one.
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

    setFilterMode(filterModeInput.value || 'range');
});
</script>
@endsection
