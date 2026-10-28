@extends('layouts.app')

@section('title', 'Orders Dashboard')

@section('content')
@php
    // Product chip colors: U = Unleaded, D = Diesel, P = Premium
    $chipStyles = [
        'U' => 'background:#dcfce7;color:#166534;border:1px solid #bbf7d0;',
        'D' => 'background:#fef3c7;color:#92400e;border:1px solid #fde68a;',
        'P' => 'background:#fee2e2;color:#991b1b;border:1px solid #fecaca;',
    ];

    $chip = function ($type, $title = '') use ($chipStyles) {
        $style = $chipStyles[$type] ?? 'background:#f3f4f6;color:#6b7280;border:1px solid #e5e7eb;';
        $label = $type ?: '—';
        return '<span class="prod-chip" style="' . $style . '" title="' . e($title) . '">' . e($label) . '</span>';
    };

    // SO# badge: Buy Back = purple, Fuel Trade = amber, regular = gray, cancelled = red
    $soBadge = function ($order) {
        $style = '';
        if ($order->status === 'Cancelled') {
            $cls = 'bg-danger text-white';
        } elseif ($order->so_prefix === 'BB') {
            $cls = '';
            $style = 'background:#ede9fe;color:#5b21b6;border:1px solid #ddd6fe;';
        } elseif ($order->so_prefix === 'FT') {
            $cls = '';
            $style = 'background:#fef3c7;color:#92400e;border:1px solid #fde68a;';
                } elseif ($order->so_prefix === 'CSM') {
            $cls = '';
            $style = 'background:#ccfbf1;color:#115e59;border:1px solid #99f6e4;';
        } else {
            $cls = 'bg-light text-dark border';
        }

        $title = $order->isFuelTrade() ? 'Fuel Trade (Refinery Direct Pick-up)' : ($order->isBuyBack() ? 'Buy Back' : ($order->isConsumo() ? 'Consumo' : ''));
        
        return '<span class="badge ' . $cls . '" style="' . $style . '" title="' . e($title) . '">' . e($order->formatted_so_number) . '</span>';
    };

    // Price shown on mobile cards: one price, or a range when an order has several products
    $priceInfo = function ($order) {
        $items = $order->items;

        if ($items->count() > 1) {
            $prices = $items->pluck('price')->map(fn($p) => (float) $p);
            $min = $prices->min();
            $max = $prices->max();

            if ($max <= 0) {
                $text = null;
            } elseif ($min == $max) {
                $text = '₱' . number_format($min, 2);
            } else {
                $text = '₱' . number_format($min, 2) . ' – ₱' . number_format($max, 2);
            }

            return ['text' => $text, 'sub' => $items->count() . ' products'];
        }

        $p = $items->count() === 1 ? (float) $items->first()->price : (float) $order->price;

        return ['text' => $p > 0 ? '₱' . number_format($p, 2) : null, 'sub' => null];
    };

    // Summary cards: ordered / delivered / undelivered, each split by product
    $prodRows = [['D', 'Diesel'], ['U', 'Unleaded'], ['P', 'Premium']];
    $statCards = [
        ['title' => 'Total Qty. Ordered', 'icon' => 'bi-boxes', 'tint' => 'background:#ecfeff;color:#0891b2;', 'data' => $productStats['ordered']],
        ['title' => 'Total Qty. Delivered', 'icon' => 'bi-truck', 'tint' => 'background:#f0fdf4;color:#16a34a;', 'data' => $productStats['delivered']],
        ['title' => 'Total Qty. Undelivered', 'icon' => 'bi-clock-history', 'tint' => 'background:#fffbeb;color:#d97706;', 'data' => $productStats['undelivered']],
    ];
@endphp
<style>
    /* ---- Summary cards ---- */
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

    /* ---- Compact orders table (fits without side scrolling) ---- */
    #ordersTable { width: 100%; }
    #ordersTable thead th {
        vertical-align: middle !important;
        text-align: center !important;
        font-size: 0.7rem !important;
        padding: 0.85rem 0.5rem !important;
        line-height: 1.2;
    }
    #ordersTable tbody td {
        padding: 0.75rem 0.5rem !important;
        font-size: 0.82rem;
        font-weight: 400;
        text-align: center;
        vertical-align: middle;
    }
    #ordersTable tbody tr.expand-row td {
        padding-top: 0.25rem !important;
        padding-bottom: 0.9rem !important;
    }
    #ordersTable .badge {
        font-size: 0.7rem;
        font-weight: 500;
    }
    #ordersTable .form-select-sm {
        font-size: 0.78rem;
        padding: 0.25rem 1.5rem 0.25rem 0.5rem;
    }
    #ordersTable .btn-sm {
        font-size: 0.75rem;
    }

    /* Account / Terms: centered, at most 2 lines, regular weight */
    .acct-name, .terms-cell {
        display: -webkit-box;
        -webkit-box-orient: vertical;
        -webkit-line-clamp: 2;
        overflow: hidden;
        white-space: normal;
        word-break: break-word;
        line-height: 1.35;
        margin: 0 auto;
        text-align: center;
        font-weight: 400;
    }
    .acct-name { max-width: 170px; }
    .terms-cell { max-width: 100px; }

    /* Per-product lines (.pl-block / .pl-row / .pl-key / .pl-num) are shared:
       see public/css/ims-ui.css. */
    .exp-item { text-align: center; }

    /* Expanded details strip */
    .exp-item { min-width: 90px; }
    .exp-label {
        font-size: 0.66rem;
        text-transform: uppercase;
        letter-spacing: 0.03em;
        color: #6b7280;
    }
    .exp-value {
        font-size: 0.8rem;
        font-weight: 500;
        color: #111827;
    }

    /* Product chips */
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

<!-- Stats Overview Cards -->
<div class="d-flex align-items-center gap-2 mb-3">
    <span class="badge rounded-pill px-3 py-2" style="background-color: #eef2ff !important; color: #4338ca !important;">
        <i class="bi bi-calendar3 me-1"></i> Monthly Summary &mdash; {{ $now->format('F Y') }}
    </span>
</div>
<div class="row g-4 mb-4">
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
            <div class="stat-row">
                <span>{!! $chip($code, $name) !!} <span class="ms-2">{{ $name }}</span></span>
                <span class="stat-val">{{ number_format($card['data'][$code]) }}</span>
            </div>
            @endforeach
            <div class="stat-total">
                <span>Total</span>
                <span class="stat-val">{{ number_format(array_sum($card['data'])) }}</span>
            </div>
        </div>
    </div>
    @endforeach
</div>

<!-- Search & Filtering -->
<div class="mb-4">
    <form method="GET" action="{{ route('dashboard') }}" class="row g-2 align-items-center">
        <input type="hidden" name="tab" value="{{ $activeTab }}">
        <div class="col-md-7 col-sm-8 col-12">
            <div class="input-group">
                <span class="input-group-text bg-white border-end-0">
                    <i class="bi bi-search text-muted"></i>
                </span>
                <input type="text" name="search" class="form-control border-start-0" placeholder="Search by Account or SO#..." value="{{ $searchQuery }}">
            </div>
        </div>
        <div class="col-auto">
            <button type="submit" class="btn btn-primary-custom">Search</button>
            @if ($searchQuery !== '')
                <a href="{{ route('dashboard', ['clear' => 1]) }}" class="btn btn-secondary-custom">Clear</a>
            @endif
        </div>
        @if (Auth::user()->canEditModule1())
        <div class="col-auto ms-md-auto">
            <a href="{{ route('order.create') }}" class="btn btn-primary-custom shadow-sm d-flex align-items-center justify-content-center">
                <i class="bi bi-plus-lg me-2"></i> New Order
            </a>
        </div>
        @endif
    </form>
</div>

<!-- Order Status Tabs -->
<ul class="nav nav-tabs nav-fill border-bottom mb-4" id="orderTabs" role="tablist">
    <li class="nav-item" role="presentation">
        <a class="nav-link d-flex align-items-center justify-content-center gap-2 py-2 small fw-medium {{ $activeTab === 'pending' ? 'active text-primary' : 'text-secondary' }}" href="{{ route('dashboard', ['tab' => 'pending', 'search' => $searchQuery ?: null]) }}">
            <i class="bi bi-hourglass-split text-warning"></i>
            <span>Pending</span>
            @if ($pendingCount > 0)
                <span class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle rounded-pill" style="font-size: 0.7rem;">{{ $pendingCount }}</span>
            @endif
        </a>
    </li>
    <li class="nav-item" role="presentation">
        <a class="nav-link d-flex align-items-center justify-content-center gap-2 py-2 small fw-medium {{ $activeTab === 'hold' ? 'active text-primary' : 'text-secondary' }}" href="{{ route('dashboard', ['tab' => 'hold', 'search' => $searchQuery ?: null]) }}">
            <i class="bi bi-pause-circle text-warning"></i>
            <span>Hold</span>
            @if ($holdCount > 0)
                <span class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle rounded-pill" style="font-size: 0.7rem;">{{ $holdCount }}</span>
            @endif
        </a>
    </li>
    <li class="nav-item" role="presentation">
        <a class="nav-link d-flex align-items-center justify-content-center gap-2 py-2 small fw-medium {{ $activeTab === 'approved' ? 'active text-primary' : 'text-secondary' }}" href="{{ route('dashboard', ['tab' => 'approved', 'search' => $searchQuery ?: null]) }}">
            <i class="bi bi-check2-circle text-success"></i>
            <span>Approved</span>
            @if ($approvedCount > 0)
                <span class="badge bg-success-subtle text-success border border-success-subtle rounded-pill" style="font-size: 0.7rem;">{{ $approvedCount }}</span>
            @endif
        </a>
    </li>
    <li class="nav-item" role="presentation">
        <a class="nav-link d-flex align-items-center justify-content-center gap-2 py-2 small fw-medium {{ $activeTab === 'declined' ? 'active text-primary' : 'text-secondary' }}" href="{{ route('dashboard', ['tab' => 'declined', 'search' => $searchQuery ?: null]) }}">
            <i class="bi bi-x-circle text-danger"></i>
            <span>Declined</span>
            @if ($declinedCount > 0)
                <span class="badge bg-danger-subtle text-danger border border-danger-subtle rounded-pill" style="font-size: 0.7rem;">{{ $declinedCount }}</span>
            @endif
        </a>
    </li>
    <li class="nav-item" role="presentation">
        <a class="nav-link d-flex align-items-center justify-content-center gap-2 py-2 small fw-medium {{ $activeTab === 'cancelled' ? 'active text-primary' : 'text-secondary' }}" href="{{ route('dashboard', ['tab' => 'cancelled', 'search' => $searchQuery ?: null]) }}">
            <i class="bi bi-slash-circle text-secondary"></i>
            <span>Cancelled</span>
            @if ($cancelledCount > 0)
                <span class="badge bg-secondary-subtle text-secondary border border-secondary-subtle rounded-pill" style="font-size: 0.7rem;">{{ $cancelledCount }}</span>
            @endif
        </a>
    </li>
</ul>

<!-- Bulk Clearance Toolbar -->
@if (auth()->user()->canClearOrders())
<div id="bulkClearanceToolbar" class="card card-custom border-0 d-none mb-3 p-3">
    <div class="d-flex flex-column flex-md-row align-items-start align-items-md-center gap-2">
        <span class="fw-semibold text-dark me-2">
            <i class="bi bi-check2-square me-1 text-primary"></i><span id="selectedCount">0</span> selected
        </span>
        <select id="bulkStatusSelect" class="form-select form-select-sm w-auto">
            <option value="">-- Set clearance to --</option>
            <option value="Pending">Pending</option>
            <option value="Declined">Declined</option>
            <option value="Hold">Hold</option>
            <option value="Approved">Approved</option>
        </select>
        <button type="button" id="bulkApplyBtn" class="btn btn-primary-custom btn-sm" disabled>
            <i class="bi bi-check2-all me-1"></i> Apply to selected
        </button>
    </div>
</div>
@endif

<!-- Orders Table -->
<div class="card card-custom border-0 overflow-hidden">
    <div class="card-body p-0">
        <!-- Desktop table -->
        <div class="table-responsive d-none d-md-block">
            <table id="ordersTable" class="table table-custom table-hover align-middle mb-0">
                <thead>
                    <tr>
                        <th style="width: 28px;"></th>
                        @if (auth()->user()->canClearOrders())
                        <th style="width: 28px;">
                            <input type="checkbox" id="checkAllDesktop" class="form-check-input order-check-all" title="Select all on this page">
                        </th>
                        @endif
                        <th>Account</th>
                        <th>Location</th>
                        <th>SO#</th>
                        <th>Qty Ordered</th>
                        <th>Price (₱)</th>
                        <th>Amount (₱)</th>
                        <th>Terms</th>
                        <th>Status</th>
                        <th>Clearance</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @if ($orders->isNotEmpty())
                        @php $colCount = auth()->user()->canClearOrders() ? 12 : 11; @endphp
                        @foreach ($orders as $order)
                        @php
                            $drList = $order->deliveries->pluck('dr_number')->filter()->unique()->values();
                            $atlList = $order->deliveries->pluck('atl_number')->filter()->unique()->values();
                        @endphp
                        <tr class="main-row {{ $order->status === 'Cancelled' ? 'order-cancelled' : '' }}" style="cursor: pointer;">
                            <td class="text-center toggle-expand ps-2">
                                <i class="bi bi-chevron-down text-secondary fs-6 toggle-icon"></i>
                            </td>
                            @if (auth()->user()->canClearOrders())
                            <td class="text-center">
                                <input type="checkbox" class="form-check-input order-check" value="{{ $order->id }}">
                            </td>
                            @endif
                            <td>
                                <span class="acct-name" title="{{ $order->account }}">{{ $order->account }}</span>
                            </td>
                            <td class="text-center">
                                @if ($order->location === 'San Simon')
                                    <span class="badge" style="background-color: #fef3c7; color: #92400e; border: 1px solid #fde68a;">San Simon</span>
                                @else
                                    <span class="badge" style="background-color: #dbeafe; color: #1e40af; border: 1px solid #bfdbfe;">Valenzuela</span>
                                @endif
                            </td>
                            <td class="text-center">
                                {!! $soBadge($order) !!}
                                @if ($order->isCarryOver($now))
                                    <div class="mt-1">
                                        <span class="badge bg-secondary-subtle text-secondary border rounded-pill" style="font-size: 0.6rem;" title="Unfulfilled order from previous month">
                                            <i class="bi bi-arrow-return-right me-1"></i>Carry-Over
                                        </span>
                                    </div>
                                @endif
                            </td>

                            {{-- Qty: one line per product, e.g. "U - 3,000" --}}
                            <td class="text-center">
                                <div class="pl-block">
                                    @forelse ($order->items as $item)
                                        <div class="pl-row" title="{{ $item->product_name }}">
                                            <span class="pl-key">{!! $chip($item->product_type, $item->product_name) !!}</span>
                                            <span class="pl-num">{{ number_format($item->qty) }}</span>
                                        </div>
                                    @empty
                                        <div class="pl-row"><span class="pl-num">{{ number_format($order->effective_qty_ordered) }}</span></div>
                                    @endforelse
                                </div>
                            </td>

                            {{-- Price: one line per product, e.g. "U - 78.00" --}}
                            <td class="text-center">
                                <div class="pl-block">
                                    @forelse ($order->items as $item)
                                        <div class="pl-row" title="{{ $item->product_name }}">
                                            <span class="pl-key">{!! $chip($item->product_type, $item->product_name) !!}</span>
                                            <span class="pl-num">{{ (float) $item->price > 0 ? number_format((float) $item->price, 2) : '—' }}</span>
                                        </div>
                                    @empty
                                        <div class="pl-row"><span class="pl-num">{{ (float) $order->price > 0 ? number_format((float) $order->price, 2) : '—' }}</span></div>
                                    @endforelse
                                </div>
                            </td>

                            {{-- Amount: total of the whole order --}}
                            <td class="text-nowrap">
                                {{ (float) $order->amount > 0 ? number_format((float) $order->amount, 2) : '—' }}
                            </td>

                            <td>
                                <span class="terms-cell" title="{{ $order->terms }}">{{ $order->terms ?: '—' }}</span>
                            </td>
                            <td class="text-center">
                                <span class="badge rounded-pill px-2 py-1 {{ $order->computed_status_badge_class }}">
                                    {{ $order->computed_status }}
                                </span>
                            </td>
                            <td class="text-center">
                                @php
                                    $cls = $order->clearing_status;
                                    $badgeClass = match($cls) {
                                        'Approved' => 'bg-success-subtle text-success border-success-subtle',
                                        'Declined' => 'bg-danger-subtle text-danger border-danger-subtle',
                                        'Hold' => 'bg-warning-subtle text-warning-emphasis border-warning-subtle',
                                        default => 'bg-secondary-subtle text-secondary border-secondary-subtle',
                                    };
                                @endphp
                                @if (auth()->user()->canClearOrders())
                                <form method="POST" action="{{ route('order.clearance', $order->id) }}" class="d-inline">
                                    @csrf
                                    <select name="clearing_status" class="form-select form-select-sm d-inline-block w-auto" onchange="this.form.submit()">
                                        <option value="Pending" {{ $cls === 'Pending' ? 'selected' : '' }}>Pending</option>
                                        <option value="Declined" {{ $cls === 'Declined' ? 'selected' : '' }}>Declined</option>
                                        <option value="Hold" {{ $cls === 'Hold' ? 'selected' : '' }}>Hold</option>
                                        <option value="Approved" {{ $cls === 'Approved' ? 'selected' : '' }}>Approved</option>
                                    </select>
                                </form>
                                @else
                                <span class="badge rounded-pill px-2 py-1 border {{ $badgeClass }}">{{ $cls }}</span>
                                @endif
                            </td>
                            <td>
                                <div class="d-flex justify-content-center gap-1">
                                    <a href="{{ route('order.deliveries', $order->id) }}" class="btn btn-sm btn-outline-primary rounded-3 px-2 py-1" title="View Deliveries">
                                        <i class="bi bi-truck me-1"></i> Deliveries
                                    </a>
                                    @if (Auth::user()->canEditModule1())
                                    <a href="{{ route('order.edit', $order->id) }}" class="btn btn-sm btn-outline-secondary rounded-3 px-2 py-1" title="Edit Order">
                                        <i class="bi bi-pencil"></i>
                                    </a>
                                    @endif
                                    @if (Auth::user()->isAdmin())
                                    <form method="POST" action="{{ route('order.delete', $order->id) }}" class="d-inline" onsubmit="return confirm('Are you sure you want to delete this order? This will also delete all associated deliveries.');">
                                        @csrf
                                        <button type="submit" class="btn btn-sm btn-outline-danger rounded-3 px-2 py-1" title="Delete Order">
                                            <i class="bi bi-trash"></i>
                                        </button>
                                    </form>
                                    @endif
                                </div>
                            </td>
                        </tr>
                        <tr class="expand-row" style="display: none; background-color: #fafafa;">
                            <td colspan="{{ $colCount }}" class="border-top-0">
                                <div class="px-4">
                                    <div class="d-flex flex-wrap justify-content-center gap-4">
                                        <div class="exp-item">
                                            <div class="exp-label">Order Date</div>
                                            <div class="exp-value">{{ $order->date ? $order->date->format('Y-m-d') : '—' }}</div>
                                        </div>
                                        <div class="exp-item">
                                            <div class="exp-label">{{ $order->isFuelTrade() ? 'Supplier PO#' : 'PO#' }}</div>
                                            <div class="exp-value">{{ $order->isFuelTrade() && $order->linkedPurchaseOrder ? $order->linkedPurchaseOrder->po_number : ($order->po_number ?: '—') }}</div>
                                        </div>
                                        <div class="exp-item">
                                            <div class="exp-label">{{ $order->isFuelTrade() ? 'Pick Up Date' : 'DR#' }}</div>
                                            @if ($order->isFuelTrade())
                                                <div class="exp-value">{{ $order->linkedPurchaseOrder?->deliveries?->first()?->receiving_date ? $order->linkedPurchaseOrder->deliveries->first()->receiving_date->format('Y-m-d') : '—' }}</div>
                                            @else
                                                <div class="exp-value" title="{{ $drList->implode(', ') }}">{{ $drList->isNotEmpty() ? $drList->implode(', ') : '—' }}</div>
                                            @endif
                                        </div>
                                        <div class="exp-item">
                                            <div class="exp-label">ATL#</div>
                                            @if ($order->isFuelTrade())
                                                @php
                                                    $ftAtl = $order->linkedPurchaseOrder?->deliveries?->first()?->atl_number ?: ($order->client_atl_number ?: '—');
                                                @endphp
                                                <div class="exp-value">{{ $ftAtl }}</div>
                                            @else
                                                <div class="exp-value" title="{{ $atlList->implode(', ') }}">{{ $atlList->isNotEmpty() ? $atlList->implode(', ') : '—' }}</div>
                                            @endif
                                        </div>
                                        <div class="exp-item">
                                            <div class="exp-label">Qty. Out</div>
                                            <div class="exp-value">{{ number_format($order->total_qty_out) }}</div>
                                        </div>
                                        <div class="exp-item">
                                            <div class="exp-label">Remaining Balance</div>
                                            <div class="exp-value">{{ number_format($order->remaining_balance) }}</div>
                                        </div>
                                    </div>
                                </div>
                            </td>
                        </tr>
                        @endforeach
                    @else
                        <tr>
                            <td colspan="{{ auth()->user()->canClearOrders() ? 12 : 11 }}" class="text-center py-5 text-muted">
                                <i class="bi bi-inbox fs-1 d-block mb-3 text-secondary"></i>
                                No orders found in this view.
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
                @php $price = $priceInfo($order); @endphp
                <div class="card border-0 bg-light mb-3 rounded-4 shadow-sm {{ $order->status === 'Cancelled' ? 'order-cancelled' : '' }}">
                    <div class="card-body p-4">
                        <div class="d-flex justify-content-between align-items-start mb-2">
                            @if (auth()->user()->canClearOrders())
                            <div class="d-flex align-items-center gap-2">
                                <input type="checkbox" class="form-check-input order-check" value="{{ $order->id }}">
                                <h5 class="fw-bold text-dark mb-0">
                                {{ $order->account }}
                                @if ($order->location === 'San Simon')
                                    <span class="badge ms-1" style="background-color: #fef3c7; color: #92400e; border: 1px solid #fde68a; font-size: 0.6rem;">San Simon</span>
                                @else
                                    <span class="badge ms-1" style="background-color: #dbeafe; color: #1e40af; border: 1px solid #bfdbfe; font-size: 0.6rem;">Valenzuela</span>
                                @endif
                                </h5>
                            </div>
                            @else
                            <h5 class="fw-bold text-dark mb-0">
                            {{ $order->account }}
                            @if ($order->location === 'San Simon')
                                <span class="badge ms-1" style="background-color: #fef3c7; color: #92400e; border: 1px solid #fde68a; font-size: 0.6rem;">San Simon</span>
                            @else
                                <span class="badge ms-1" style="background-color: #dbeafe; color: #1e40af; border: 1px solid #bfdbfe; font-size: 0.6rem;">Valenzuela</span>
                            @endif
                            </h5>
                            @endif
                            {!! $soBadge($order) !!}
                        </div>
                        <p class="text-muted small mb-1"><span class="fw-medium">PO#:</span> {{ $order->po_number }}</p>
                        <div class="row mb-3 small text-muted">
                            <div class="col-6">
                                <span class="fw-medium">Date:</span> {{ $order->date ? $order->date->format('Y-m-d') : '' }}
                            </div>
                            <div class="col-6 text-end">
                                <span class="fw-medium">Qty Ordered:</span> {{ number_format($order->effective_qty_ordered) }}
                            </div>
                        </div>
                        <div class="row mb-3 small text-muted">
                            <div class="col-6">
                                <span class="fw-medium">Price:</span> {{ $price['text'] ?? '—' }}
                                @if ($price['sub'])
                                    <span class="d-block" style="font-size: 0.7rem;">{{ $price['sub'] }}</span>
                                @endif
                            </div>
                            <div class="col-6 text-end">
                                <span class="fw-medium">Terms:</span> {{ $order->terms ?: '—' }}
                            </div>
                        </div>
                        @if ($order->items->count() > 0)
                        <div class="bg-white border rounded-3 px-3 py-2 mb-3 small">
                            @foreach ($order->items as $item)
                            <div class="d-flex justify-content-between align-items-center {{ !$loop->last ? 'mb-1' : '' }}">
                                <span>{!! $chip($item->product_type) !!} <span class="ms-1">{{ $item->product_name }}</span></span>
                                <span class="text-muted">{{ number_format($item->qty) }} @ {{ (float) $item->price > 0 ? '₱' . number_format((float) $item->price, 2) : '—' }}</span>
                            </div>
                            @endforeach
                            <div class="d-flex justify-content-between border-top mt-2 pt-1 fw-semibold">
                                <span>Amount</span>
                                <span>₱{{ number_format((float) $order->amount, 2) }}</span>
                            </div>
                        </div>
                        @endif
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <div>
                                <span class="text-muted small d-block">Qty Out</span>
                                <span class="fw-semibold">{{ number_format($order->total_qty_out) }}</span>
                            </div>
                            <div class="text-end">
                                <span class="text-muted small d-block">Remaining</span>
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
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <span class="text-muted small">Status:</span>
                            <span class="badge rounded-pill px-3 py-1 {{ $order->computed_status_badge_class }}">
                                {{ $order->computed_status }}
                            </span>
                        </div>
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <span class="text-muted small">Clearance:</span>
                            @php
                                $cls = $order->clearing_status;
                                $badgeClass = match($cls) {
                                    'Approved' => 'bg-success-subtle text-success border-success-subtle',
                                    'Declined' => 'bg-danger-subtle text-danger border-danger-subtle',
                                    'Hold' => 'bg-warning-subtle text-warning-emphasis border-warning-subtle',
                                    default => 'bg-secondary-subtle text-secondary border-secondary-subtle',
                                };
                            @endphp
                            @if (auth()->user()->canClearOrders())
                                <form method="POST" action="{{ route('order.clearance', $order->id) }}" class="d-inline">
                                    @csrf
                                    <select name="clearing_status" class="form-select form-select-sm d-inline-block w-auto" onchange="this.form.submit()">
                                        <option value="Pending" {{ $cls === 'Pending' ? 'selected' : '' }}>Pending</option>
                                        <option value="Declined" {{ $cls === 'Declined' ? 'selected' : '' }}>Declined</option>
                                        <option value="Hold" {{ $cls === 'Hold' ? 'selected' : '' }}>Hold</option>
                                        <option value="Approved" {{ $cls === 'Approved' ? 'selected' : '' }}>Approved</option>
                                    </select>
                                </form>
                            @else
                                <span class="badge rounded-pill px-3 py-1 border {{ $badgeClass }}">{{ $cls }}</span>
                            @endif
                        </div>
                        <div class="d-flex gap-2">
                            <a href="{{ route('order.deliveries', $order->id) }}" class="btn btn-sm btn-outline-primary rounded-3 px-3 py-2 flex-fill text-center">
                                <i class="bi bi-truck me-1"></i> Deliveries
                            </a>
                            @if (Auth::user()->canEditModule1())
                            <a href="{{ route('order.edit', $order->id) }}" class="btn btn-sm btn-outline-secondary rounded-3 px-3 py-2 flex-fill text-center">
                                <i class="bi bi-pencil me-1"></i> Edit
                            </a>
                            @endif
                            @if (Auth::user()->isAdmin())
                            <form method="POST" action="{{ route('order.delete', $order->id) }}" class="d-inline" onsubmit="return confirm('Are you sure you want to delete this order? This will also delete all associated deliveries.');">
                                @csrf
                                <button type="submit" class="btn btn-sm btn-outline-danger rounded-3 px-3 py-2">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </form>
                            @endif
                        </div>
                    </div>
                </div>
                @endforeach
            @else
                <div class="text-center py-5 text-muted">
                    <i class="bi bi-inbox fs-1 d-block mb-3 text-secondary"></i>
                    No orders found in this view.
                </div>
            @endif
        </div>
    </div>
</div>

<!-- Pagination -->
<div class="d-flex justify-content-center mt-4">
    {{ $orders->links() }}
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const mainRows = document.querySelectorAll('tr.main-row');
    mainRows.forEach(row => {
        row.addEventListener('click', function (e) {
            // Do not toggle if the user clicks an interactive element
            if (e.target.closest('a, button, select, input, form, label')) {
                return;
            }

            const nextRow = this.nextElementSibling;
            if (nextRow && nextRow.classList.contains('expand-row')) {
                const icon = this.querySelector('.toggle-icon');
                const isCollapsed = window.getComputedStyle(nextRow).display === 'none';

                if (isCollapsed) {
                    nextRow.style.display = 'table-row';
                    if (icon) {
                        icon.classList.remove('bi-chevron-down');
                        icon.classList.add('bi-chevron-up');
                    }
                } else {
                    nextRow.style.display = 'none';
                    if (icon) {
                        icon.classList.remove('bi-chevron-up');
                        icon.classList.add('bi-chevron-down');
                    }
                }
            }
        });
    });

    // Bulk clearance selection handling
    const toolbar = document.getElementById('bulkClearanceToolbar');
    const checkAllDesktop = document.getElementById('checkAllDesktop');
    const selectedCount = document.getElementById('selectedCount');
    const bulkStatusSelect = document.getElementById('bulkStatusSelect');
    const bulkApplyBtn = document.getElementById('bulkApplyBtn');

    if (toolbar) {
        const orderChecks = () => Array.from(document.querySelectorAll('.order-check'));

        function updateSelectionUI() {
            const checks = orderChecks();
            const checked = checks.filter(c => c.checked);
            selectedCount.textContent = checked.length;
            bulkApplyBtn.disabled = checked.length === 0;

            if (checkAllDesktop) {
                checkAllDesktop.checked = checked.length > 0 && checked.length === checks.length;
                checkAllDesktop.indeterminate = checked.length > 0 && checked.length < checks.length;
            }

            toolbar.classList.toggle('d-none', checked.length === 0);
        }

        if (checkAllDesktop) {
            checkAllDesktop.addEventListener('change', function () {
                orderChecks().forEach(c => { c.checked = this.checked; });
                updateSelectionUI();
            });
        }

        orderChecks().forEach(c => c.addEventListener('change', updateSelectionUI));

        bulkApplyBtn.addEventListener('click', function () {
            const status = bulkStatusSelect.value;
            if (!status) {
                alert('Please select a clearance status first.');
                return;
            }

            const ids = orderChecks().filter(c => c.checked).map(c => c.value);
            if (ids.length === 0) return;

            if (!confirm('Set clearance status to "' + status + '" for ' + ids.length + ' selected order(s)?')) {
                return;
            }

            const form = document.createElement('form');
            form.method = 'POST';
            form.action = '{{ route('orders.bulk-clearance') }}';

            const csrf = document.createElement('input');
            csrf.type = 'hidden';
            csrf.name = '_token';
            csrf.value = '{{ csrf_token() }}';
            form.appendChild(csrf);

            ids.forEach(id => {
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'order_ids[]';
                input.value = id;
                form.appendChild(input);
            });

            const statusInput = document.createElement('input');
            statusInput.type = 'hidden';
            statusInput.name = 'clearing_status';
            statusInput.value = status;
            form.appendChild(statusInput);

            document.body.appendChild(form);
            form.submit();
        });
    }
});
</script>
@endsection