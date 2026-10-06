@extends('layouts.app')

@section('title', 'Dashboard')

@section('content')
@php
    // Product chip colors: U = Unleaded, D = Diesel, P = Premium
    $chipStyles = [
        'U' => 'background:#dcfce7;color:#166534;border:1px solid #bbf7d0;',
        'D' => 'background:#e0e7ff;color:#3730a3;border:1px solid #c7d2fe;',
        'P' => 'background:#fee2e2;color:#991b1b;border:1px solid #fecaca;',
    ];

    // Small colored letter badge for a product type
    $chip = function ($type, $title = '') use ($chipStyles) {
        $style = $chipStyles[$type] ?? 'background:#f3f4f6;color:#6b7280;border:1px solid #e5e7eb;';
        $label = $type ?: '—';
        return '<span class="prod-chip" style="' . $style . '" title="' . e($title) . '">' . e($label) . '</span>';
    };

    // Price shown in the table: one price, or a range when an order has several products
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
@endphp
<style>
    #ordersTable thead th {
        vertical-align: middle !important;
        text-align: center !important;
    }
    /* Tighter rows */
    #ordersTable tbody td {
        padding-top: 0.5rem !important;
        padding-bottom: 0.5rem !important;
        font-size: 0.875rem;
    }
    #ordersTable tbody tr.expand-row td {
        padding-top: 0.25rem !important;
        padding-bottom: 0.75rem !important;
    }
    .acct-name {
        display: block;
        max-width: 230px;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }
    .terms-cell {
        display: block;
        max-width: 130px;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }
    .prod-chip {
        display: inline-block;
        font-size: 0.65rem;
        font-weight: 700;
        line-height: 1;
        padding: 0.2rem 0.4rem;
        border-radius: 0.35rem;
        margin: 0 1px;
    }
    .exp-item { min-width: 90px; }
    .exp-label {
        font-size: 0.68rem;
        text-transform: uppercase;
        letter-spacing: 0.03em;
        color: #6b7280;
    }
    .exp-value {
        font-size: 0.85rem;
        font-weight: 500;
        color: #111827;
    }
    .prod-table th {
        font-size: 0.68rem;
        text-transform: uppercase;
        letter-spacing: 0.03em;
        color: #6b7280;
        font-weight: 600;
    }
    .prod-table td, .prod-table th { padding: 0.2rem 0.5rem; }
</style>
<!-- Header Section -->
<div class="d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center mb-5 gap-3">
    <div>
        <h2 class="fw-bold text-dark mb-1">Orders Dashboard</h2>
        <p class="text-muted small mb-0">Manage customer accounts, sales orders, and delivery statuses.</p>
    </div>
    <div class="row g-2">
        @if (Auth::user()->canEditModule1())
        <div class="col-12 col-md-auto">
            <a href="{{ route('order.create') }}" class="btn btn-primary-custom shadow-sm d-flex align-items-center justify-content-center w-100">
                <i class="bi bi-plus-lg me-2"></i> New Order
            </a>
        </div>
        @endif
    </div>
</div>

<!-- Stats Overview Cards -->
<div class="d-flex align-items-center gap-2 mb-3">
    <span class="badge rounded-pill px-3 py-2" style="background-color: #eef2ff !important; color: #4338ca !important;">
        <i class="bi bi-calendar3 me-1"></i> Monthly Summary &mdash; {{ $now->format('F Y') }}
    </span>
</div>
<div class="row g-4 mb-5">

    <div class="col-md-3 col-sm-6">
        <div class="card card-custom p-3 border-0">
            <div class="d-flex align-items-center">
                    <div class="rounded-3 p-3 bg-primary bg-opacity-10 text-primary me-3">
                    <i class="bi bi-journal-text fs-4"></i>
                </div>
                <div>
                    <span class="text-muted small d-block">Total Orders</span>
                    <h4 class="fw-bold mb-0 text-dark">{{ number_format($totalOrders) }}</h4>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-3 col-sm-6">
        <div class="card card-custom p-3 border-0">
            <div class="d-flex align-items-center">
                <div class="rounded-3 p-3 bg-info bg-opacity-10 text-info me-3" style="background-color: #ecfeff !important; color: #0891b2 !important;">
                    <i class="bi bi-boxes fs-4"></i>
                </div>
                <div>
                    <span class="text-muted small d-block">Qty Ordered</span>
                    <h4 class="fw-bold mb-0 text-dark">{{ number_format($totalQtyOrdered) }}</h4>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-3 col-sm-6">
        <div class="card card-custom p-3 border-0">
            <div class="d-flex align-items-center">
                <div class="rounded-3 p-3 bg-success bg-opacity-10 text-success me-3" style="background-color: #f0fdf4 !important; color: #16a34a !important;">
                    <i class="bi bi-truck fs-4"></i>
                </div>
                <div>
                    <span class="text-muted small d-block">Qty Delivered</span>
                    <h4 class="fw-bold mb-0 text-dark">{{ number_format($totalQtyDelivered) }}</h4>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-3 col-sm-6">
        <div class="card card-custom p-3 border-0">
            <div class="d-flex align-items-center">
                <div class="rounded-3 p-3 bg-warning bg-opacity-10 text-warning me-3" style="background-color: #fffbeb !important; color: #d97706 !important;">
                    <i class="bi bi-clock-history fs-4"></i>
                </div>
                <div>
                    <span class="text-muted small d-block">Remaining Bal</span>
                    <h4 class="fw-bold mb-0 text-dark">{{ number_format($totalRemaining) }}</h4>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Search & Filtering -->
<div class="mb-4">
    <form method="GET" action="{{ route('dashboard') }}" class="row g-2 align-items-center">
        <input type="hidden" name="tab" value="{{ $activeTab }}">
        <div class="col-md-6 col-sm-8 col-10">
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
                        <th style="width: 40px;"></th>
                        @if (auth()->user()->canClearOrders())
                        <th style="width: 40px;">
                            <input type="checkbox" id="checkAllDesktop" class="form-check-input order-check-all" title="Select all on this page">
                        </th>
                        @endif
                        <th class="ps-4">Account</th>
                        <th>Location</th>
                        <th>SO#</th>
                        <th>Qty Ordered</th>
                        <th>Price</th>
                        <th>Terms</th>
                        <th>Status</th>
                        <th>Clearance</th>
                        <th class="pe-4">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @if ($orders->isNotEmpty())
                        @php $colCount = auth()->user()->canClearOrders() ? 11 : 10; @endphp
                        @foreach ($orders as $order)
                        @php
                            $drList = $order->deliveries->pluck('dr_number')->filter()->unique()->values();
                            $atlList = $order->deliveries->pluck('atl_number')->filter()->unique()->values();
                            $price = $priceInfo($order);

                            // Product lines for the expanded row (falls back to the order's own totals)
                            $lines = $order->items->isNotEmpty()
                                ? $order->items
                                : collect([(object) [
                                    'product_type' => null,
                                    'product_name' => '—',
                                    'qty' => $order->qty_ordered,
                                    'price' => $order->price,
                                    'amount' => $order->amount,
                                ]]);
                        @endphp
                        <tr class="main-row {{ $order->status === 'Cancelled' ? 'order-cancelled' : '' }}" style="cursor: pointer;">
                            <td class="text-center toggle-expand ps-3">
                                <i class="bi bi-chevron-down text-secondary fs-6 toggle-icon"></i>
                            </td>
                            @if (auth()->user()->canClearOrders())
                            <td class="text-center">
                                <input type="checkbox" class="form-check-input order-check" value="{{ $order->id }}">
                            </td>
                            @endif
                            <td class="ps-4 fw-semibold text-dark">
                                <span class="acct-name" title="{{ $order->account }}">{{ $order->account }}</span>
                            </td>
                            <td>
                                @if ($order->location === 'San Simon')
                                    <span class="badge" style="background-color: #fef3c7; color: #92400e; border: 1px solid #fde68a;">San Simon</span>
                                @else
                                    <span class="badge" style="background-color: #dbeafe; color: #1e40af; border: 1px solid #bfdbfe;">Valenzuela</span>
                                @endif
                            </td>
                            <td>
                                @if ($order->isFuelTrade())
                                    <span class="badge" style="background-color: #fef08a; color: #854d0e; border: 1px solid #facc15;" title="Fuel Trade (Refinery Direct Pick-up)">
                                        <i class="bi bi-fuel-pump me-1"></i>{{ $order->formatted_so_number }}
                                    </span>
                                @else
                                    <span class="badge {{ $order->status === 'Cancelled' ? 'bg-danger text-white' : 'bg-light text-dark border' }}">{{ $order->formatted_so_number }}</span>
                                @endif
                                @if ($order->isCarryOver($now))
                                    <span class="badge bg-secondary-subtle text-secondary border rounded-pill ms-1" style="font-size: 0.65rem;" title="Unfulfilled order from previous month">
                                        <i class="bi bi-arrow-return-right me-1"></i>Carry-Over
                                    </span>
                                @endif
                            </td>
                            <td class="text-center">
                                <div class="fw-medium">{{ number_format($order->effective_qty_ordered) }}</div>
                                @if ($order->items->isNotEmpty())
                                <div class="mt-1">
                                    @foreach ($order->items as $item)
                                        {!! $chip($item->product_type, $item->product_name . ': ' . number_format($item->qty) . ' @ ₱' . number_format((float) $item->price, 2)) !!}
                                    @endforeach
                                </div>
                                @endif
                            </td>
                            <td class="text-end fw-medium text-nowrap">
                                @if ($price['text'])
                                    <div>{{ $price['text'] }}</div>
                                    @if ($price['sub'])
                                        <div class="text-muted" style="font-size: 0.7rem; font-weight: 400;">{{ $price['sub'] }}</div>
                                    @endif
                                @else
                                    <span class="text-muted">—</span>
                                @endif
                            </td>
                            <td class="text-muted small">
                                <span class="terms-cell" title="{{ $order->terms }}">{{ $order->terms ?: '—' }}</span>
                            </td>
                            <td class="text-center">
                                <span class="badge rounded-pill px-3 py-1 {{ $order->computed_status_badge_class }}">
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
                                <span class="badge rounded-pill px-3 py-1 border {{ $badgeClass }}">{{ $cls }}</span>
                                @endif
                            </td>
                            <td class="text-end pe-4">
                                <div class="d-flex justify-content-end gap-1">
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
                                    {{-- Slim details strip --}}
                                    <div class="d-flex flex-wrap gap-4">
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

                                    {{-- Products --}}
                                    <div class="bg-white border rounded-3 px-3 py-2 mt-3" style="max-width: 540px;">
                                        <table class="table table-sm table-borderless mb-0 prod-table">
                                            <thead>
                                                <tr>
                                                    <th>Product</th>
                                                    <th class="text-end">Qty</th>
                                                    <th class="text-end">Price</th>
                                                    <th class="text-end">Amount</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                @foreach ($lines as $line)
                                                <tr>
                                                    <td>{!! $chip($line->product_type) !!} <span class="ms-1">{{ $line->product_name }}</span></td>
                                                    <td class="text-end">{{ number_format($line->qty) }}</td>
                                                    <td class="text-end">{{ (float) $line->price > 0 ? '₱' . number_format((float) $line->price, 2) : '—' }}</td>
                                                    <td class="text-end">{{ (float) $line->amount > 0 ? '₱' . number_format((float) $line->amount, 2) : '—' }}</td>
                                                </tr>
                                                @endforeach
                                            </tbody>
                                            @if ($lines->count() > 1)
                                            <tfoot>
                                                <tr class="fw-bold border-top">
                                                    <td>Total</td>
                                                    <td class="text-end">{{ number_format($lines->sum('qty')) }}</td>
                                                    <td></td>
                                                    <td class="text-end">₱{{ number_format((float) $lines->sum('amount'), 2) }}</td>
                                                </tr>
                                            </tfoot>
                                            @endif
                                        </table>
                                    </div>
                                </div>
                            </td>
                        </tr>
                        @endforeach
                    @else
                        <tr>
                            <td colspan="{{ auth()->user()->canClearOrders() ? 11 : 10 }}" class="text-center py-5 text-muted">
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
                            @if ($order->isFuelTrade())
                                <span class="badge" style="background-color: #fef08a; color: #854d0e; border: 1px solid #facc15;" title="Fuel Trade">
                                    <i class="bi bi-fuel-pump me-1"></i>{{ $order->formatted_so_number }}
                                </span>
                            @else
                                <span class="badge {{ $order->status === 'Cancelled' ? 'bg-danger text-white' : 'bg-light text-dark border' }}">{{ $order->formatted_so_number }}</span>
                            @endif
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
