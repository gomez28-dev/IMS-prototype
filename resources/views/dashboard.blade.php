@extends('layouts.app')

@section('title', 'Dashboard')

@section('content')
<!-- Header Section -->
<div class="d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center mb-5 gap-3">
    <div>
        <h2 class="fw-bold text-dark mb-1">Orders Dashboard</h2>
        <p class="text-muted small mb-0">Manage customer accounts, sales orders, and delivery statuses.</p>
    </div>
    <div class="d-flex flex-wrap gap-2">
        @if (!Auth::user()->isViewer())
        <a href="{{ route('import.form') }}" class="btn btn-secondary-custom shadow-sm d-flex align-items-center">
            <i class="bi bi-file-earmark-excel me-2 text-success"></i> Import Excel
        </a>
        @endif
        <a href="{{ route('export') }}" class="btn btn-secondary-custom shadow-sm d-flex align-items-center">
            <i class="bi bi-download me-2 text-primary"></i> Download Excel
        </a>
        @if (!Auth::user()->isViewer())
        <a href="{{ route('order.create') }}" class="btn btn-primary-custom shadow-sm d-flex align-items-center">
            <i class="bi bi-plus-lg me-2"></i> New Order
        </a>
        @endif
    </div>
</div>

<!-- Stats Overview Cards -->
<div class="row g-4 mb-5">
    @php
        $totalOrders = $orders->count();
        $totalQtyOrdered = $orders->sum('qty_ordered');
        $totalQtyDelivered = $orders->sum(fn($o) => $o->total_qty_out);
        $totalRemaining = $orders->sum(fn($o) => $o->remaining_balance);
    @endphp
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
                <a href="{{ route('dashboard') }}" class="btn btn-secondary-custom">Clear</a>
            @endif
        </div>
    </form>
</div>

<!-- Orders Table -->
<div class="card card-custom border-0 overflow-hidden">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-custom table-hover align-middle mb-0">
                <thead>
                    <tr>
                        <th class="ps-4">Account</th>
                        <th>Order Date</th>
                        <th>SO#</th>
                        <th class="text-end">Qty Ordered</th>
                        <th class="text-end">Qty Out</th>
                        <th class="text-center">Remaining Balance</th>
                        <th class="text-end pe-4">Actions</th>
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
                                <div class="d-flex justify-content-end gap-1">
                                    <a href="{{ route('order.deliveries', $order->id) }}" class="btn btn-sm btn-outline-primary rounded-3 px-2 py-1" title="View Deliveries">
                                        <i class="bi bi-truck me-1"></i> Deliveries
                                    </a>
                                    @if (!Auth::user()->isViewer())
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
                        @endforeach
                    @else
                        <tr>
                            <td colspan="7" class="text-center py-5 text-muted">
                                <i class="bi bi-inbox fs-1 d-block mb-3 text-secondary"></i>
                                No orders found. Click "New Order" to create one.
                            </td>
                        </tr>
                    @endif
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection
