@extends('layouts.app')

@section('title', 'Stock Order Details')

@section('content')
<div class="row justify-content-center">
    <div class="col-12">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-4">
            <div>
                <nav aria-label="breadcrumb">
                    <ol class="breadcrumb mb-1 small">
                        <li class="breadcrumb-item"><a href="{{ route('portal') }}" class="text-decoration-none text-secondary">Portal</a></li>
                        <li class="breadcrumb-item"><a href="{{ route('stock-orders.dashboard') }}" class="text-decoration-none text-secondary">Stock Orders</a></li>
                        <li class="breadcrumb-item"><a href="{{ route('stock-orders.index') }}" class="text-decoration-none text-secondary">All Purchase Orders</a></li>
                        <li class="breadcrumb-item active" aria-current="page">{{ $purchaseOrder->po_number ?: 'Pending PO#' }}</li>
                    </ol>
                </nav>
                <h3 class="fw-bold text-dark mb-0">
                    <i class="bi bi-card-checklist text-primary me-2"></i>Stock Order {{ $purchaseOrder->po_number ?: '(Pending PO#)' }}
                </h3>
            </div>
            <div class="d-flex gap-2">
                <a href="{{ url()->previous() }}" class="btn btn-outline-secondary">
                    <i class="bi bi-arrow-left me-1"></i> Back
                </a>
                <a href="{{ route('stock-orders.deliveries') }}" class="btn btn-outline-primary">
                    <i class="bi bi-truck me-1"></i> Deliveries & ATLs
                </a>
            </div>
        </div>

        {{-- PO Summary --}}
        <div class="card card-custom border-0 shadow-sm mb-4">
            <div class="card-body p-4">
                <div class="row g-3">
                    <div class="col-md-3">
                        <span class="text-muted extra-small uppercase fw-semibold d-block mb-1" style="font-size: 0.7rem;">PO NUMBER</span>
                        <span class="fw-bold text-dark fs-5">{{ $purchaseOrder->po_number ?: 'Pending PO#' }}</span>
                        <div class="mt-2">
                            @if ($purchaseOrder->isFuelTrade())
                                <span class="badge bg-warning text-dark border">Fuel Trade</span>
                            @elseif ($purchaseOrder->isBuyBack())
                                <span class="badge bg-info text-dark border">Buy Back</span>
                            @else
                                <span class="badge bg-light text-dark border">Standard Replenishment</span>
                            @endif
                        </div>
                    </div>
                    <div class="col-md-3">
                        <span class="text-muted extra-small uppercase fw-semibold d-block mb-1" style="font-size: 0.7rem;">PIPELINE STATUS</span>
                        @if ($purchaseOrder->request_status === 'REQUESTED')
                            <span class="badge bg-secondary-subtle text-secondary border rounded-pill px-3 py-1">1. REQUESTED</span>
                        @elseif ($purchaseOrder->request_status === 'RECEIVED')
                            <span class="badge bg-warning-subtle text-warning border rounded-pill px-3 py-1">2. RECEIVED (Pending VP)</span>
                        @elseif ($purchaseOrder->request_status === 'CONFIRMED')
                            <span class="badge bg-info-subtle text-info border rounded-pill px-3 py-1">3. CONFIRMED (Approved)</span>
                        @elseif ($purchaseOrder->request_status === 'FOR_DELIVERY')
                            <span class="badge bg-primary rounded-pill px-3 py-1 text-white">4. FOR DELIVERY</span>
                        @elseif ($purchaseOrder->request_status === 'COMPLETED')
                            <span class="badge bg-success-subtle text-success border rounded-pill px-3 py-1">5. COMPLETED</span>
                        @else
                            <span class="badge bg-light text-dark border rounded-pill px-3 py-1">{{ $purchaseOrder->request_status }}</span>
                        @endif
                        <div class="mt-2 small text-muted">Computed: <span class="fw-semibold text-dark">{{ $purchaseOrder->computed_status }}</span></div>
                    </div>
                    <div class="col-md-3">
                        <span class="text-muted extra-small uppercase fw-semibold d-block mb-1" style="font-size: 0.7rem;">SUPPLIER / SOURCE</span>
                        <span class="fw-bold text-dark">{{ $purchaseOrder->supplier_name ?: ($purchaseOrder->client->name ?? '—') }}</span>
                        <div class="small text-muted mt-1">
                            @if ($purchaseOrder->warehouse)
                                <i class="bi bi-geo-alt text-primary me-1"></i>{{ $purchaseOrder->warehouse->name }}
                            @elseif ($purchaseOrder->isFuelTrade())
                                Direct Pick-up
                            @else
                                —
                            @endif
                        </div>
                    </div>
                    <div class="col-md-3">
                        <span class="text-muted extra-small uppercase fw-semibold d-block mb-1" style="font-size: 0.7rem;">VOLUME</span>
                        <span class="fw-bold text-primary fs-5 font-monospace">{{ number_format($purchaseOrder->qty_ordered) }} L</span>
                        <div class="small text-muted mt-1">{{ number_format($purchaseOrder->remaining_balance) }} L remaining</div>
                    </div>
                </div>
                <hr class="my-3">
                <div class="row g-3 small">
                    <div class="col-md-3">
                        <span class="text-muted d-block">Requested By</span>
                        <span class="fw-semibold text-dark">{{ $purchaseOrder->requester->name ?? 'System' }}</span>
                    </div>
                    <div class="col-md-3">
                        <span class="text-muted d-block">Approved By</span>
                        <span class="fw-semibold text-dark">{{ $purchaseOrder->approver->name ?? '—' }}</span>
                        @if ($purchaseOrder->approved_at)
                            <span class="text-muted">({{ $purchaseOrder->approved_at->format('M d, Y h:i A') }})</span>
                        @endif
                    </div>
                    <div class="col-md-3">
                        <span class="text-muted d-block">Linked Sales Order</span>
                        <span class="fw-semibold text-dark">{{ $purchaseOrder->linkedOrder->so_number ?? '—' }}</span>
                    </div>
                    <div class="col-md-3">
                        <span class="text-muted d-block">Date Needed</span>
                        <span class="fw-semibold text-dark">{{ $purchaseOrder->date_needed ? $purchaseOrder->date_needed->format('M d, Y') : '—' }}</span>
                    </div>
                </div>
                @if ($purchaseOrder->remarks)
                    <div class="alert alert-light border small mb-0 mt-3">
                        <span class="text-muted">Remarks:</span> {{ $purchaseOrder->remarks }}
                    </div>
                @endif
                @if ($purchaseOrder->request_status === 'RECEIVED' && Auth::user()->canApproveStockOrders())
                    <div class="d-flex gap-2 mt-3">
                        <a href="{{ route('stock-orders.approvals') }}" class="btn btn-sm btn-outline-success rounded-pill px-3">
                            <i class="bi bi-patch-check me-1"></i> Review in Approvals Queue
                        </a>
                    </div>
                @endif
            </div>
        </div>

        {{-- Items --}}
        @if ($purchaseOrder->items->isNotEmpty())
            <div class="card card-custom border-0 shadow-sm mb-4">
                <div class="card-header bg-white py-3">
                    <h5 class="fw-bold text-dark mb-0">Order Items</h5>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="bg-light">
                                <tr>
                                    <th class="ps-3 py-3">Product</th>
                                    <th class="py-3 text-end">Quantity</th>
                                    <th class="pe-3 py-3 text-end">Remaining</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($purchaseOrder->items as $item)
                                    <tr>
                                        <td class="ps-3 fw-semibold">{{ $item->product }}</td>
                                        <td class="text-end font-monospace">{{ number_format($item->quantity) }} L</td>
                                        <td class="pe-3 text-end font-monospace">{{ number_format($item->remaining_balance) }} L</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        @endif

        {{-- Deliveries / ATLs --}}
        <div class="card card-custom border-0 shadow-sm">
            <div class="card-header bg-white py-3">
                <h5 class="fw-bold text-dark mb-0">Deliveries & ATL Records</h5>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="bg-light">
                            <tr>
                                <th class="ps-3 py-3">ATL / DR #</th>
                                <th class="py-3">Product</th>
                                <th class="py-3 text-end">Volume</th>
                                <th class="py-3">Driver / Plate</th>
                                <th class="py-3">Pickup Date</th>
                                <th class="py-3 text-center">Status</th>
                                <th class="pe-3 py-3 text-end">Action / PDF</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($purchaseOrder->deliveries as $del)
                                <tr>
                                    <td class="ps-3">
                                        @if ($del->atl_number)
                                            <strong class="text-primary">{{ $del->atl_number }}</strong>
                                        @elseif ($del->client_atl_number)
                                            <span class="badge bg-warning-subtle text-dark border">Client: {{ $del->client_atl_number }}</span>
                                        @else
                                            <span class="text-secondary small">DR: {{ $del->dr_number ?: 'Pending' }}</span>
                                        @endif
                                    </td>
                                    <td>{{ $del->product }}</td>
                                    <td class="text-end fw-bold font-monospace">{{ number_format($del->qty_to_receive) }} L</td>
                                    <td class="small">
                                        {{ $del->driver_name ?: '—' }}
                                        @if ($del->plate_number)
                                            <span class="text-muted">({{ $del->plate_number }})</span>
                                        @endif
                                    </td>
                                    <td class="small">{{ $del->receiving_date ? $del->receiving_date->format('M d, Y') : '—' }}</td>
                                    <td class="text-center">
                                        <span class="badge bg-light text-dark border rounded-pill px-3 py-1">{{ $del->status }}</span>
                                    </td>
                                    <td class="pe-3 text-end">
                                        @if ($del->requiresAtl() && $del->status !== 'Pending')
                                            <a href="{{ route('stock-orders.pdf', $del->id) }}" class="btn btn-sm btn-outline-danger rounded-pill px-2 py-1 shadow-sm" title="Download ATL PDF">
                                                <i class="bi bi-file-earmark-pdf me-1"></i> ATL PDF
                                            </a>
                                        @else
                                            <span class="text-muted" title="Awaiting ATL issuance">—</span>
                                            <span class="badge bg-light text-muted border rounded-pill px-2 py-1 ms-1">Pending Issuance</span>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="7" class="text-center py-4 text-muted">No delivery records for this order yet.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
