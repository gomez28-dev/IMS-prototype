@extends('layouts.app')

@section('title', 'Deliveries for ' . $order->formatted_so_number)

@section('content')
<div class="mb-3">
    @if (str_contains(url()->previous(), '/reports'))
    @php $filters = session('report_filters', []); @endphp
    <a href="{{ route('reports.index') }}{{ $filters ? '?' . http_build_query($filters) : '' }}" class="text-decoration-none text-secondary small">
        <i class="bi bi-arrow-left me-1"></i> Back to Reports
    </a>
    @else
    <a href="{{ route('dashboard') }}" class="text-decoration-none text-secondary small">
        <i class="bi bi-arrow-left me-1"></i> Back to Dashboard
    </a>
    @endif
</div>

@php
    // Per-product lines only make sense when the SO has more than one product
    $productSummary = $productSummary ?? [];
    $showBreakdown = count($productSummary) > 1;
@endphp

<!-- Order Summary Header Card -->
<div class="card card-custom mb-5 border-0 border-start border-5 {{ $order->isFuelTrade() ? 'border-warning' : 'border-primary' }}">
    <div class="card-body p-4">
        <div class="row align-items-center">
            <div class="col-md-6 mb-3 mb-md-0">
                <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
                    @if ($order->status === 'Cancelled')
                        <span class="badge bg-danger-subtle text-danger border border-danger-subtle rounded-pill fw-semibold px-3 py-2"><i class="bi bi-x-circle me-1"></i>CANCELLED ORDER</span>
                    @endif
                    @if ($order->isFuelTrade())
                        <span class="badge fw-semibold px-3 py-2" style="background-color: #fef08a; color: #854d0e; border: 1px solid #facc15;">
                            <i class="bi bi-fuel-pump me-1"></i>SO# {{ $order->formatted_so_number }}
                        </span>
                        <span class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle rounded-pill px-3 py-2">
                            FUEL TRADE
                        </span>
                    @else
                        <span class="badge {{ $order->status === 'Cancelled' ? 'bg-danger text-white' : 'bg-primary bg-opacity-10 text-primary' }} fw-semibold px-3 py-2">
                            SO# {{ $order->formatted_so_number }}
                        </span>
                    @endif
                </div>
                <h3 class="fw-bold mb-1 text-dark">{{ $order->account }}</h3>
                <p class="text-muted small mb-0">
                    <i class="bi bi-calendar3 me-1"></i> Order Date: {{ $order->date ? $order->date->format('F d, Y') : '' }}
                </p>
            </div>
            <div class="col-md-6">
                <div class="row text-center g-2">
                    <div class="col-4">
                        <div class="p-3 bg-light rounded border border-light-subtle h-100">
                            <div class="text-muted extra-small uppercase fw-semibold mb-1" style="font-size: 0.7rem; letter-spacing: 0.05em;">QTY ORDERED</div>
                            <div class="fs-5 fw-bold text-dark">{{ number_format($order->effective_qty_ordered) }}</div>
                            @if ($showBreakdown)
                                <div class="small text-muted mt-1">
                                    @foreach ($productSummary as $code => $p)
                                        <div title="{{ $p['name'] }}">{{ $code }} &middot; {{ number_format($p['ordered'] - $p['cancelled']) }} L</div>
                                    @endforeach
                                </div>
                            @endif
                        </div>
                    </div>
                    <div class="col-4">
                        <div class="p-3 bg-light rounded border border-light-subtle h-100">
                            <div class="text-muted extra-small uppercase fw-semibold mb-1" style="font-size: 0.7rem; letter-spacing: 0.05em;">TOTAL OUT</div>
                            <div class="fs-5 fw-bold text-primary">{{ number_format($order->total_qty_out) }}</div>
                            @if ($showBreakdown && !$order->isFuelTrade())
                                <div class="small text-muted mt-1">
                                    @foreach ($productSummary as $code => $p)
                                        <div title="{{ $p['name'] }}">{{ $code }} &middot; {{ number_format($p['fulfilled']) }} L</div>
                                    @endforeach
                                </div>
                            @endif
                        </div>
                    </div>
                    <div class="col-4">
                        <div class="p-3 bg-light rounded border border-light-subtle h-100">
                            <div class="text-muted extra-small uppercase fw-semibold mb-1" style="font-size: 0.7rem; letter-spacing: 0.05em;">REMAINING</div>
                            <div class="fs-5 fw-bold {{ $order->remaining_balance == 0 ? 'text-success' : 'text-warning-emphasis' }}">
                                {{ number_format($order->remaining_balance) }}
                            </div>
                            @if ($showBreakdown && !$order->isFuelTrade())
                                <div class="small text-muted mt-1">
                                    @foreach ($productSummary as $code => $p)
                                        <div title="{{ $p['name'] }}">{{ $code }} &middot; {{ number_format($p['remaining']) }} L</div>
                                    @endforeach
                                </div>
                            @endif
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

@if ($order->clearing_status !== 'Approved')
<div class="alert alert-warning d-flex align-items-center rounded-4 shadow-sm mb-4 p-3 border-0" style="background-color: #fef3c7; color: #92400e;">
    <i class="bi bi-clock-history me-2 fs-5"></i>
    <div>
        <strong>Waiting for Accounting clearance.</strong> This order's clearance status is <span class="badge rounded-pill px-2 py-0" style="background-color: #d97706; color: #fff;">{{ $order->clearing_status }}</span>. 
        @if ($order->isFuelTrade())
            Purchasing cannot issue the Authority to Load (ATL) until the status is set to <strong>Approved</strong>.
        @else
            Deliveries cannot be added until the status is set to <strong>Approved</strong>.
        @endif
    </div>
</div>
@endif

<!-- Deliveries List Header -->
@php
    $ftDelivery = $order->linkedPurchaseOrder?->deliveries?->first();
@endphp
<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="fw-bold mb-0 text-dark">
        <i class="bi {{ $order->isFuelTrade() ? 'bi-fuel-pump' : 'bi-truck' }} me-2 text-primary"></i>
        {{ $order->isFuelTrade() ? 'Fuel Trade Pick Up & ATL' : 'Deliveries' }}
    </h4>
    @if ($order->isFuelTrade())
        <div class="d-flex gap-2">
            @if ($ftDelivery)
                <button type="button" class="btn btn-outline-primary shadow-sm d-flex align-items-center" data-bs-toggle="modal" data-bs-target="#atlDetailModal">
                    <i class="bi bi-eye me-2"></i> View ATL Details
                </button>
                <a href="{{ route('stock-orders.atl-pdf', $ftDelivery->id) }}" class="btn btn-primary-custom shadow-sm d-flex align-items-center" target="_blank">
                    <i class="bi bi-file-earmark-pdf me-2"></i> Download ATL PDF
                </a>
            @else
                @if ($order->canBeIssuedAtl())
                    {{-- ATL issuance lives in Module 3 now; Module 1 only hands off. --}}
                    @if (Auth::user()->isAdmin() || Auth::user()->isPurchasing())
                        <a href="{{ route('stock-orders.atls.show', $order->id) }}" class="btn btn-warning shadow-sm d-flex align-items-center fw-semibold">
                            <i class="bi bi-box-arrow-up-right me-2"></i> Open in Module 3
                        </a>
                    @else
                        <span class="badge bg-light text-muted border p-2">
                            <i class="bi bi-info-circle me-1"></i> For Purchasing input
                        </span>
                    @endif
                @else
                    <button type="button" class="btn btn-secondary shadow-sm d-flex align-items-center opacity-75" disabled title="Awaiting Accounting clearance ({{ $order->clearing_status }})">
                        <i class="bi bi-lock-fill me-2"></i> Locked: Pending Accounting Clearance
                    </button>
                @endif
            @endif
        </div>
    @else
        @if (Auth::user()->canEditModule1())
        <a href="{{ route('delivery.create', $order->id) }}" class="btn btn-primary-custom shadow-sm d-flex align-items-center">
            <i class="bi bi-plus-lg me-2"></i> Add Delivery
        </a>
        @endif
    @endif
</div>

<!-- Deliveries Table -->
<div class="card card-custom border-0 overflow-hidden">
    <div class="card-body p-0">
        <!-- Desktop table -->
        <div class="table-responsive d-none d-md-block">
            <table class="table table-custom table-hover align-middle mb-0">
                <thead>
                    <tr>
                        <th class="ps-4">{{ $order->isFuelTrade() ? 'PO#' : 'DR#' }}</th>
                        <th>ATL#</th>
                        <th>{{ $order->isFuelTrade() ? 'Pick Up Date' : 'Delivery Date' }}</th>
                        <th class="text-end">Qty Out</th>
                        <th class="text-center">Status</th>
                        <th class="text-center">Type</th>
                        <th class="text-end pe-4">Action</th>
                    </tr>
                </thead>
                <tbody>
                    @if ($order->isFuelTrade())
                        @if ($order->linkedPurchaseOrder && $order->linkedPurchaseOrder->deliveries->isNotEmpty())
                            @foreach ($order->linkedPurchaseOrder->deliveries as $delivery)
                            <tr>
                                <td class="ps-4 fw-bold text-primary">{{ $order->linkedPurchaseOrder->po_number }}</td>
                                <td class="fw-semibold text-dark">{{ $delivery->atl_number ?: ($delivery->client_atl_number ?: 'Pending') }}</td>
                                <td>{{ $delivery->receiving_date ? $delivery->receiving_date->format('Y-m-d') : '—' }}</td>
                                <td class="text-end fw-medium">{{ number_format($delivery->qty_to_receive) }}</td>
                                <td class="text-center">
                                    @if ($delivery->status === 'Completed')
                                        <span class="badge bg-success-subtle text-success border border-success-subtle rounded-pill px-3 py-1">FULFILLED / LIFTED</span>
                                    @elseif ($delivery->status === 'Active')
                                        <span class="badge bg-info-subtle text-info-emphasis border border-info-subtle rounded-pill px-3 py-1">FOR PICK UP</span>
                                    @else
                                        <span class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle rounded-pill px-3 py-1">PENDING PICK UP</span>
                                    @endif
                                </td>
                                <td class="text-center">
                                    <span class="badge rounded-pill px-3 py-1" style="background-color: #fef08a; color: #854d0e; border: 1px solid #facc15;">FT</span>
                                </td>
                                <td class="text-end pe-4">
                                    <div class="d-flex justify-content-end gap-2">
                                        @if ($delivery->hasScannedDocs())
                                            <a href="{{ $delivery->scanned_doc_url }}" class="btn btn-sm btn-outline-success rounded-3 px-2 py-1" target="_blank" title="View Scanned Documents on Google Drive">
                                                <i class="bi bi-google me-1"></i> Docs
                                            </a>
                                        @endif
                                        <button type="button" class="btn btn-sm btn-outline-primary rounded-3 px-3 py-1" data-bs-toggle="modal" data-bs-target="#atlDetailModal">
                                            <i class="bi bi-eye me-1"></i> View ATL
                                        </button>
                                        <a href="{{ route('stock-orders.atl-pdf', $delivery->id) }}" class="btn btn-sm btn-outline-secondary rounded-3 px-3 py-1" target="_blank" title="Download ATL PDF">
                                            <i class="bi bi-file-earmark-pdf"></i>
                                        </a>
                                    </div>
                                </td>
                            </tr>
                            @endforeach
                        @else
                            <tr>
                                <td colspan="7" class="text-center py-5 text-muted">
                                    <i class="bi bi-fuel-pump fs-1 d-block mb-3 text-warning"></i>
                                    @if ($order->clearing_status !== 'Approved')
                                        <div class="fw-semibold text-dark fs-6 mb-1">Awaiting Accounting Clearance</div>
                                        <p class="small text-muted mb-0">Clearance status is currently <strong>{{ $order->clearing_status }}</strong>. Purchasing will issue the ATL once cleared.</p>
                                    @else
                                        <div class="fw-semibold text-dark fs-6 mb-1">Cleared for ATL Issuance</div>
                                        <p class="small text-muted mb-2">Accounting clearance is approved. The ATL is now issued in Module 3.</p>
                                        {{-- ATL issuance moved to Module 3; Module 1 only hands off. --}}
                                        @if (Auth::user()->isAdmin() || Auth::user()->isPurchasing())
                                            <a href="{{ route('stock-orders.atls.show', $order->id) }}" class="btn btn-sm btn-warning rounded-pill px-3 py-1 fw-semibold">
                                                <i class="bi bi-box-arrow-up-right me-1"></i> Open in Module 3
                                            </a>
                                        @else
                                            <span class="badge bg-light text-muted border">
                                                <i class="bi bi-info-circle me-1"></i> For Purchasing input
                                            </span>
                                        @endif
                                    @endif
                                </td>
                            </tr>
                        @endif
                    @else
                        @if ($deliveries->isNotEmpty())
                            @foreach ($deliveries as $delivery)
                            <tr class="{{ $delivery->status == 'CANCELLED' ? 'delivery-cancelled' : '' }}">
                                <td class="ps-4 fw-semibold">{{ $delivery->dr_number }}</td>
                                <td class="text-muted small">{{ $delivery->atl_number ?: '—' }}</td>
                                <td>{{ $delivery->delivery_date ? $delivery->delivery_date->format('Y-m-d') : '' }}</td>
                                <td class="text-end fw-medium">
                                    {{ number_format($delivery->qty_out) }}
                                    @if ($delivery->items->isNotEmpty())
                                        <div class="small text-muted fw-normal">
                                            @foreach ($delivery->items as $line)
                                                <div title="{{ $line->product_name }}">{{ $line->product_type ?: '-' }} &middot; {{ number_format($line->qty_out) }} L</div>
                                            @endforeach
                                        </div>
                                    @endif
                                </td>
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
                                    @elseif ($delivery->type === 'SMALL TANKER')
                                        <span class="badge badge-type-small-tanker rounded-pill px-3 py-1">SMALL TANKER</span>
                                    @else
                                        <span class="badge badge-type-big-tanker rounded-pill px-3 py-1">BIG TANKER</span>
                                    @endif
                                </td>
                                @if (Auth::user()->canEditModule1())
                                <td class="text-end pe-4">
                                    <div class="d-flex justify-content-end gap-2">
                                        <a href="{{ route('delivery.edit', $delivery->id) }}" class="btn btn-sm btn-outline-secondary rounded-3 px-3 py-1">
                                            <i class="bi bi-pencil me-1"></i> Edit
                                        </a>
                                        @if (Auth::user()->isAdmin())
                                        <form method="POST" action="{{ route('delivery.delete', $delivery->id) }}" class="d-inline" onsubmit="return confirm('Are you sure you want to delete this delivery?');">
                                            @csrf
                                            <button type="submit" class="btn btn-sm btn-outline-danger rounded-3 px-3 py-1" title="Delete Delivery">
                                                <i class="bi bi-trash"></i>
                                            </button>
                                        </form>
                                        @endif
                                    </div>
                                </td>
                                @else
                                <td class="text-end pe-4">—</td>
                                @endif
                            </tr>
                            @endforeach
                        @else
                            <tr>
                                <td colspan="7" class="text-center py-5 text-muted">
                                    <i class="bi bi-box-seam fs-1 d-block mb-3 text-secondary"></i>
                                    No deliveries recorded yet for this order.
                                </td>
                            </tr>
                        @endif
                    @endif
                </tbody>
            </table>
        </div>

        <!-- Mobile cards -->
        <div class="d-md-none p-3">
            @if ($order->isFuelTrade())
                @if ($order->linkedPurchaseOrder && $order->linkedPurchaseOrder->deliveries->isNotEmpty())
                    @foreach ($order->linkedPurchaseOrder->deliveries as $delivery)
                    <div class="card border-0 bg-light mb-3 rounded-4 shadow-sm">
                        <div class="card-body p-4">
                            <div class="d-flex justify-content-between align-items-start mb-2">
                                <h5 class="fw-bold text-dark mb-0">{{ $order->linkedPurchaseOrder->po_number }}</h5>
                                <span class="badge rounded-pill px-3 py-1" style="background-color: #fef08a; color: #854d0e; border: 1px solid #facc15;">FT</span>
                            </div>
                            <div class="small text-muted mb-1">ATL#: <span class="fw-medium text-dark">{{ $delivery->atl_number ?: ($delivery->client_atl_number ?: 'Pending') }}</span></div>
                            <div class="row mb-2 small text-muted">
                                <div class="col-6">
                                    <span class="fw-medium">Pick Up Date:</span> {{ $delivery->receiving_date ? $delivery->receiving_date->format('Y-m-d') : '—' }}
                                </div>
                                <div class="col-6 text-end">
                                    <span class="fw-medium">Qty:</span> {{ number_format($delivery->qty_to_receive) }}
                                </div>
                            </div>
                            <div class="mb-3">
                                @if ($delivery->status === 'Completed')
                                    <span class="badge bg-success-subtle text-success border border-success-subtle rounded-pill px-3 py-1">FULFILLED / LIFTED</span>
                                @elseif ($delivery->status === 'Active')
                                    <span class="badge bg-info-subtle text-info-emphasis border border-info-subtle rounded-pill px-3 py-1">FOR PICK UP</span>
                                @else
                                    <span class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle rounded-pill px-3 py-1">PENDING PICK UP</span>
                                @endif
                            </div>
                            <div class="d-flex gap-2">
                                @if ($delivery->hasScannedDocs())
                                    <a href="{{ $delivery->scanned_doc_url }}" class="btn btn-sm btn-outline-success rounded-3 px-3 py-2" target="_blank" title="View Docs">
                                        <i class="bi bi-google"></i> Docs
                                    </a>
                                @endif
                                <button type="button" class="btn btn-sm btn-outline-primary rounded-3 px-3 py-2 flex-fill text-center" data-bs-toggle="modal" data-bs-target="#atlDetailModal">
                                    <i class="bi bi-eye me-1"></i> View ATL
                                </button>
                                <a href="{{ route('stock-orders.atl-pdf', $delivery->id) }}" class="btn btn-sm btn-outline-secondary rounded-3 px-3 py-2" target="_blank">
                                    <i class="bi bi-file-earmark-pdf"></i> PDF
                                </a>
                            </div>
                        </div>
                    </div>
                    @endforeach
                @else
                    <div class="text-center py-5 text-muted">
                        <i class="bi bi-fuel-pump fs-1 d-block mb-3 text-warning"></i>
                        No ATL issued yet for this Fuel Trade order.
                    </div>
                @endif
            @else
                @if ($deliveries->isNotEmpty())
                    @foreach ($deliveries as $delivery)
                    <div class="card border-0 bg-light mb-3 rounded-4 shadow-sm {{ $delivery->status == 'CANCELLED' ? 'delivery-cancelled' : '' }}">
                        <div class="card-body p-4">
                            <div class="d-flex justify-content-between align-items-start mb-2">
                                <h5 class="fw-bold text-dark mb-0">{{ $delivery->dr_number }}</h5>
                                <div class="d-flex gap-1">
                                    @if ($delivery->type === 'PICK-UP')
                                        <span class="badge badge-type-pickup rounded-pill px-3 py-1">PICK-UP</span>
                                    @elseif ($delivery->type === 'SMALL TANKER')
                                        <span class="badge badge-type-small-tanker rounded-pill px-3 py-1">SMALL TANKER</span>
                                    @else
                                        <span class="badge badge-type-big-tanker rounded-pill px-3 py-1">BIG TANKER</span>
                                    @endif
                                </div>
                            </div>
                            <div class="small text-muted mb-1">ATL#: <span class="fw-medium">{{ $delivery->atl_number ?: '—' }}</span></div>
                            <div class="row mb-2 small text-muted">
                                <div class="col-6">
                                    <span class="fw-medium">Date:</span> {{ $delivery->delivery_date ? $delivery->delivery_date->format('Y-m-d') : '' }}
                                </div>
                                <div class="col-6 text-end">
                                    <span class="fw-medium">Qty:</span> {{ number_format($delivery->qty_out) }}
                                </div>
                            </div>
                            @if ($delivery->items->isNotEmpty())
                                <div class="small text-muted mb-2">
                                    <span class="fw-medium">Products:</span> {{ $delivery->items_summary }}
                                </div>
                            @endif
                            <div class="mb-3">
                                @if ($delivery->status == 'FULFILLED')
                                    <span class="badge bg-success-subtle text-success border border-success-subtle rounded-pill px-3 py-1">FULFILLED</span>
                                @elseif ($delivery->status == 'PENDING')
                                    <span class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle rounded-pill px-3 py-1">PENDING</span>
                                @elseif ($delivery->status == 'CANCELLED')
                                    <span class="badge bg-danger-subtle text-danger border border-danger-subtle rounded-pill px-3 py-1">CANCELLED</span>
                                @else
                                    <span class="badge bg-secondary rounded-pill">{{ $delivery->status }}</span>
                                @endif
                            </div>
                            @if (Auth::user()->canEditModule1())
                            <div class="d-flex gap-2">
                                <a href="{{ route('delivery.edit', $delivery->id) }}" class="btn btn-sm btn-outline-secondary rounded-3 px-3 py-2 flex-fill text-center">
                                    <i class="bi bi-pencil me-1"></i> Edit
                                </a>
                                @if (Auth::user()->isAdmin())
                                <form method="POST" action="{{ route('delivery.delete', $delivery->id) }}" class="d-inline" onsubmit="return confirm('Are you sure you want to delete this delivery?');">
                                    @csrf
                                    <button type="submit" class="btn btn-sm btn-outline-danger rounded-3 px-3 py-2">
                                        <i class="bi bi-trash"></i> Delete
                                    </button>
                                </form>
                                @endif
                            </div>
                            @endif
                        </div>
                    </div>
                    @endforeach
                @else
                    <div class="text-center py-5 text-muted">
                        <i class="bi bi-box-seam fs-1 d-block mb-3 text-secondary"></i>
                        No deliveries recorded yet for this order.
                    </div>
                @endif
            @endif
        </div>
    </div>
</div>

{{-- Modal for Fuel Trade ATL Details (outside table to avoid DOM layout glitches) --}}
@if ($order->isFuelTrade() && $ftDelivery)
<div class="modal fade" id="atlDetailModal" tabindex="-1" aria-labelledby="atlDetailModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content border-0 shadow-lg rounded-4">
            <div class="modal-header border-bottom py-3" style="background-color: #fef9c3;">
                <div class="d-flex align-items-center">
                    <div class="rounded-circle p-2 me-2" style="background-color: #fef08a;">
                        <i class="bi bi-patch-check-fill text-warning fs-5"></i>
                    </div>
                    <div>
                        <h5 class="modal-title fw-bold text-dark mb-0" id="atlDetailModalLabel">
                            Authority to Load (ATL) Details
                        </h5>
                        <small class="text-muted">Issued by Purchasing / Module 3</small>
                    </div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-4">
                <div class="row g-3">
                    <div class="col-md-6">
                        <div class="p-3 bg-light rounded-3">
                            <span class="text-muted small d-block mb-1">Sales Order #</span>
                            <span class="fw-bold text-dark fs-5">{{ $order->formatted_so_number }}</span>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="p-3 bg-light rounded-3">
                            <span class="text-muted small d-block mb-1">Client / Account</span>
                            <span class="fw-bold text-dark fs-5">{{ $order->account }}</span>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="p-3 bg-light rounded-3">
                            <span class="text-muted small d-block mb-1">Supplier PO Allocation (Kabangga ng ATL)</span>
                            <span class="fw-bold text-primary fs-6">{{ $ftDelivery->allocations_summary }}</span>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="p-3 bg-light rounded-3">
                            <span class="text-muted small d-block mb-1">Refinery / Supplier</span>
                            <span class="fw-bold text-dark fs-5">{{ $order->linkedPurchaseOrder?->supplier_name ?: '—' }}</span>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="p-3 bg-light rounded-3">
                            <span class="text-muted small d-block mb-1">Fulfillment Channel</span>
                            <span class="fw-bold text-dark fs-6">{{ $ftDelivery->channel_label }}</span>
                        </div>
                    </div>
                    @if ($ftDelivery->storage_tank_id)
                    <div class="col-md-6">
                        <div class="p-3 bg-light rounded-3">
                            <span class="text-muted small d-block mb-1">Assigned Mobile Tanker Truck</span>
                            <span class="fw-bold text-primary fs-6">
                                <i class="bi bi-truck me-1"></i>{{ $ftDelivery->storageTank?->name ?? 'Tanker' }}
                                <span class="badge bg-secondary-subtle text-secondary small fw-normal ms-1">{{ $ftDelivery->storageTank?->warehouse?->name ?? 'Depot' }}</span>
                            </span>
                        </div>
                    </div>
                    @endif
                    <div class="col-md-3">
                        <div class="p-3 bg-light rounded-3">
                            <span class="text-muted small d-block mb-1">Supplier SO / Contract #</span>
                            <span class="fw-bold text-dark">{{ $ftDelivery->supplier_so_number ?: '—' }}</span>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="p-3 bg-light rounded-3">
                            <span class="text-muted small d-block mb-1">Supplier Rack DR #</span>
                            <span class="fw-bold text-dark">{{ $ftDelivery->supplier_dr_number ?: 'Pending Rack Lift' }}</span>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="p-3 bg-light rounded-3">
                            <span class="text-muted small d-block mb-1">ATL #</span>
                            <span class="fw-bold text-success fs-5">{{ $ftDelivery->atl_number ?: ($ftDelivery->client_atl_number ?: 'DITC-ATL') }}</span>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="p-3 bg-light rounded-3">
                            <span class="text-muted small d-block mb-1">Product</span>
                            <span class="fw-bold text-dark fs-5">{{ $ftDelivery->product }}</span>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="p-3 bg-light rounded-3">
                            <span class="text-muted small d-block mb-1">Volume to Lift</span>
                            <span class="fw-bold text-dark fs-5">{{ number_format($ftDelivery->qty_to_receive) }} L</span>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="p-3 bg-light rounded-3">
                            <span class="text-muted small d-block mb-1">Pick Up Date</span>
                            <span class="fw-bold text-dark">{{ $ftDelivery->receiving_date ? $ftDelivery->receiving_date->format('M d, Y') : '—' }}</span>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="p-3 bg-light rounded-3">
                            <span class="text-muted small d-block mb-1">Authorized Driver</span>
                            <span class="fw-bold text-dark">{{ $ftDelivery->driver_name ?: '—' }}</span>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="p-3 bg-light rounded-3">
                            <span class="text-muted small d-block mb-1">Plate Number</span>
                            <span class="fw-bold text-dark">{{ $ftDelivery->plate_number ?: '—' }}</span>
                        </div>
                    </div>
                    @if ($ftDelivery->location)
                    <div class="col-md-8">
                        <div class="p-3 bg-light rounded-3">
                            <span class="text-muted small d-block mb-1">Pickup Depot Location</span>
                            <span class="fw-medium text-dark">{{ $ftDelivery->location }}</span>
                        </div>
                    </div>
                    @endif
                    @if ($ftDelivery->additional_remarks)
                    <div class="col-12">
                        <div class="p-3 bg-light rounded-3">
                            <span class="text-muted small d-block mb-1">Special Instructions / Remarks</span>
                            <span class="text-dark">{{ $ftDelivery->additional_remarks }}</span>
                        </div>
                    </div>
                    @endif

                    {{-- Documentation Hub (Google Drive) --}}
                    <div class="col-12">
                        @if ($ftDelivery->hasScannedDocs())
                            <div class="p-3 bg-success bg-opacity-10 border border-success border-opacity-25 rounded-3 d-flex flex-wrap justify-content-between align-items-center gap-2">
                                <div>
                                    <span class="text-success small fw-bold d-block"><i class="bi bi-google me-1"></i>Verified Scanned Documents Attached</span>
                                    <span class="small text-muted">Signed refinery DR, loading tickets, and calibration dip sheets.</span>
                                </div>
                                <div class="d-flex gap-2">
                                    <a href="{{ $ftDelivery->scanned_doc_url }}" target="_blank" class="btn btn-sm btn-success rounded-pill px-3 shadow-sm">
                                        <i class="bi bi-box-arrow-up-right me-1"></i> Open Google Drive
                                    </a>
                                    <button type="button" class="btn btn-sm btn-outline-secondary rounded-pill px-3" data-bs-toggle="collapse" data-bs-target="#editDocCollapse">
                                        <i class="bi bi-pencil me-1"></i> Edit Link
                                    </button>
                                </div>
                            </div>
                        @else
                            <div class="p-3 bg-light border rounded-3 d-flex flex-wrap justify-content-between align-items-center gap-2">
                                <div>
                                    <span class="text-muted small fw-semibold d-block"><i class="bi bi-file-earmark-arrow-up me-1"></i>Scanned Physical Documents</span>
                                    <span class="extra-small text-muted" style="font-size: 0.8rem;">No Google Drive link attached yet for stamped DR and dip sheet.</span>
                                </div>
                                <button type="button" class="btn btn-sm btn-outline-primary rounded-pill px-3" data-bs-toggle="collapse" data-bs-target="#editDocCollapse">
                                    <i class="bi bi-plus-lg me-1"></i> Attach Google Drive Link
                                </button>
                            </div>
                        @endif

                        <div class="collapse mt-2" id="editDocCollapse">
                            <form method="POST" action="{{ route('stock-orders.update-docs', $ftDelivery->id) }}" class="card card-body border rounded-3 p-3 bg-white shadow-sm">
                                @csrf
                                <h6 class="fw-bold small text-dark mb-2">Update Refinery Document Serials & Drive Link</h6>
                                <div class="row g-2 mb-2">
                                    <div class="col-md-6">
                                        <label class="form-label extra-small text-muted mb-1" style="font-size: 0.75rem;">Supplier SO / Contract #</label>
                                        <input type="text" name="supplier_so_number" class="form-control form-control-sm" placeholder="e.g. PETRON-SO-8842" value="{{ $ftDelivery->supplier_so_number }}">
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label extra-small text-muted mb-1" style="font-size: 0.75rem;">Supplier Rack DR #</label>
                                        <input type="text" name="supplier_dr_number" class="form-control form-control-sm" placeholder="e.g. DR-99014" value="{{ $ftDelivery->supplier_dr_number }}">
                                    </div>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label extra-small text-muted mb-1" style="font-size: 0.75rem;">Google Drive Share Link</label>
                                    <div class="input-group input-group-sm">
                                        <span class="input-group-text"><i class="bi bi-google text-danger"></i></span>
                                        <input type="url" name="scanned_doc_url" class="form-control" placeholder="https://drive.google.com/file/d/..." value="{{ $ftDelivery->scanned_doc_url }}" required>
                                    </div>
                                </div>
                                <div class="d-flex justify-content-end gap-2">
                                    <button type="button" class="btn btn-sm btn-light border rounded-pill px-3" data-bs-toggle="collapse" data-bs-target="#editDocCollapse">Cancel</button>
                                    <button type="submit" class="btn btn-sm btn-primary-custom rounded-pill px-3">Save Changes</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer border-top bg-light py-2">
                <button type="button" class="btn btn-secondary rounded-pill px-4" data-bs-dismiss="modal">Close</button>
                <a href="{{ route('stock-orders.atl-pdf', $ftDelivery->id) }}" class="btn btn-primary-custom rounded-pill px-4" target="_blank">
                    <i class="bi bi-file-earmark-pdf me-1"></i> Download ATL PDF
                </a>
            </div>
        </div>
    </div>
</div>
@endif
@endsection