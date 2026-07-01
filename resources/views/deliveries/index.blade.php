@extends('layouts.app')

@section('title', 'Deliveries for ' . $order->so_number)

@section('content')
<div class="mb-3">
    <a href="{{ route('dashboard') }}" class="text-decoration-none text-secondary small">
        <i class="bi bi-arrow-left me-1"></i> Back to Dashboard
    </a>
</div>

<!-- Order Summary Header Card -->
<div class="card card-custom mb-5 border-0 border-start border-5 border-primary">
    <div class="card-body p-4">
        <div class="row align-items-center">
            <div class="col-md-6 mb-3 mb-md-0">
                    <span class="badge bg-primary bg-opacity-10 text-primary mb-2 fw-semibold px-3 py-2">
                    SO# {{ $order->so_number }}
                </span>
                <h3 class="fw-bold mb-1 text-dark">{{ $order->account }}</h3>
                <p class="text-muted small mb-0">
                    <i class="bi bi-calendar3 me-1"></i> Order Date: {{ $order->date ? $order->date->format('F d, Y') : '' }}
                </p>
            </div>
            <div class="col-md-6">
                <div class="row text-center g-2">
                    <div class="col-4">
                        <div class="p-3 bg-light rounded border border-light-subtle">
                            <div class="text-muted extra-small uppercase fw-semibold mb-1" style="font-size: 0.7rem; letter-spacing: 0.05em;">QTY ORDERED</div>
                            <div class="fs-5 fw-bold text-dark">{{ number_format($order->qty_ordered) }}</div>
                        </div>
                    </div>
                    <div class="col-4">
                        <div class="p-3 bg-light rounded border border-light-subtle">
                            <div class="text-muted extra-small uppercase fw-semibold mb-1" style="font-size: 0.7rem; letter-spacing: 0.05em;">TOTAL OUT</div>
                            <div class="fs-5 fw-bold text-primary">{{ number_format($order->total_qty_out) }}</div>
                        </div>
                    </div>
                    <div class="col-4">
                        <div class="p-3 bg-light rounded border border-light-subtle">
                            <div class="text-muted extra-small uppercase fw-semibold mb-1" style="font-size: 0.7rem; letter-spacing: 0.05em;">REMAINING</div>
                            <div class="fs-5 fw-bold {{ $order->remaining_balance == 0 ? 'text-success' : 'text-warning-emphasis' }}">
                                {{ number_format($order->remaining_balance) }}
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Deliveries List Header -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h4 class="fw-bold mb-0 text-dark"><i class="bi bi-truck me-2 text-primary"></i>Deliveries</h4>
        @if (!Auth::user()->isViewer())
        <a href="{{ route('delivery.create', $order->id) }}" class="btn btn-primary-custom shadow-sm d-flex align-items-center">
            <i class="bi bi-plus-lg me-2"></i> Add Delivery
        </a>
        @endif
    </div>

<!-- Deliveries Table -->
<div class="card card-custom border-0 overflow-hidden">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-custom table-hover align-middle mb-0">
                <thead>
                    <tr>
                        <th class="ps-4">DR#</th>
                        <th>Delivery Date</th>
                        <th class="text-end">Qty Out</th>
                        <th class="text-center">Status</th>
                        <th class="text-center">Type</th>
                        <th class="text-end pe-4">Action</th>
                    </tr>
                </thead>
                <tbody>
                    @if ($deliveries->isNotEmpty())
                        @foreach ($deliveries as $delivery)
                        <tr class="{{ $delivery->status == 'CANCELLED' ? 'delivery-cancelled' : '' }}">
                            <td class="ps-4 fw-semibold">{{ $delivery->dr_number }}</td>
                            <td>{{ $delivery->delivery_date ? $delivery->delivery_date->format('Y-m-d') : '' }}</td>
                            <td class="text-end fw-medium">{{ number_format($delivery->qty_out) }}</td>
                            <td class="text-center">
                                @if ($delivery->status == 'FULFILLED')
                                    <span class="badge bg-success-subtle text-success border border-success-subtle rounded-pill px-3 py-1">FULFILLED</span>
                                @elseif ($delivery->status == 'PENDING')
                                    <span class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle rounded-pill px-3 py-1">PENDING</span>
                                @elseif ($delivery->status == 'CANCELLED')
                                    <span class="badge bg-danger-subtle text-danger border border-danger-subtle rounded-pill px-3 py-1">CANCELLED</span>
                                @else
                                    <span class="badge bg-secondary rounded-pill">{{ $delivery->status }}</span>
                                @endif
                            </td>
                            <td class="text-center">
                                @if ($delivery->type === 'PICK-UP')
                                    <span class="badge badge-type-pickup rounded-pill px-3 py-1">PICK-UP</span>
                                @else
                                    <span class="badge bg-success-subtle text-success border border-success-subtle rounded-pill px-3 py-1">DELIVERY</span>
                                @endif
                            </td>
                            <td class="text-end pe-4">
                                <div class="d-flex justify-content-end gap-1">
                                    @if (!Auth::user()->isViewer())
                                    <a href="{{ route('delivery.edit', $delivery->id) }}" class="btn btn-sm btn-outline-secondary rounded-3 px-2 py-1">
                                        <i class="bi bi-pencil me-1"></i> Edit
                                    </a>
                                    @endif
                                    @if (Auth::user()->isAdmin())
                                    <form method="POST" action="{{ route('delivery.delete', $delivery->id) }}" class="d-inline" onsubmit="return confirm('Are you sure you want to delete this delivery?');">
                                        @csrf
                                        <button type="submit" class="btn btn-sm btn-outline-danger rounded-3 px-2 py-1" title="Delete Delivery">
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
                            <td colspan="6" class="text-center py-5 text-muted">
                                <i class="bi bi-box-seam fs-1 d-block mb-3 text-secondary"></i>
                                No deliveries recorded yet for this order.
                            </td>
                        </tr>
                    @endif
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection
