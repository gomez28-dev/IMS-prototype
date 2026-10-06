@extends('layouts.app')

@section('title', 'ATL Details')

@section('content')
<div class="row justify-content-center">
    <div class="col-12">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-4">
            <div>
                <nav aria-label="breadcrumb">
                    <ol class="breadcrumb mb-1 small">
                        <li class="breadcrumb-item"><a href="{{ route('portal') }}" class="text-decoration-none text-secondary">Portal</a></li>
                        <li class="breadcrumb-item"><a href="{{ route('stock-orders.atls.index') }}" class="text-decoration-none text-secondary">ATL Queue</a></li>
                        <li class="breadcrumb-item active" aria-current="page">{{ $order->formatted_so_number }}</li>
                    </ol>
                </nav>
                <h3 class="fw-bold text-dark mb-0">
                    <i class="bi bi-patch-check text-primary me-2"></i>ATL Details &mdash; {{ $order->formatted_so_number }}
                </h3>
                <p class="text-muted small mb-0 mt-1">{{ $order->account }}</p>
            </div>
            <div class="d-flex gap-2">
                @if ($order->canBeIssuedAtl() && Auth::user()->canEditStockOrders())
                    <a href="{{ route('stock-orders.create-fuel-trade-po', $order->id) }}" class="btn btn-primary-custom">
                        <i class="bi bi-plus-lg me-1"></i> Create ATL
                    </a>
                @endif
                <a href="{{ route('stock-orders.atls.index') }}" class="btn btn-outline-secondary">
                    <i class="bi bi-arrow-left me-1"></i> Back to ATL Queue
                </a>
            </div>
        </div>

        {{-- Totals --}}
        <div class="row g-3 mb-4">
            <div class="col-md-3">
                <div class="card card-custom p-3 border-0 shadow-sm">
                    <span class="text-uppercase text-muted fw-bold small">Qty Ordered</span>
                    <h3 class="fw-bold text-dark mb-0 mt-1 font-monospace">{{ number_format($totals['ordered']) }} L</h3>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card card-custom p-3 border-0 shadow-sm">
                    <span class="text-uppercase text-muted fw-bold small">Total ATL'd</span>
                    <h3 class="fw-bold text-primary mb-0 mt-1 font-monospace">{{ number_format($totals['atl_issued']) }} L</h3>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card card-custom p-3 border-0 shadow-sm">
                    <span class="text-uppercase text-muted fw-bold small">Total Lifted</span>
                    <h3 class="fw-bold text-success mb-0 mt-1 font-monospace">{{ number_format($totals['lifted']) }} L</h3>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card card-custom p-3 border-0 shadow-sm">
                    <span class="text-uppercase text-muted fw-bold small">Remaining</span>
                    <h3 class="fw-bold text-warning mb-0 mt-1 font-monospace">{{ number_format($totals['remaining']) }} L</h3>
                </div>
            </div>
        </div>

        {{-- Per-product requirement --}}
        @if ($order->items->isNotEmpty())
            <div class="card card-custom border-0 shadow-sm mb-4">
                <div class="card-header bg-white py-3">
                    <h6 class="fw-bold text-dark mb-0">Products Required by this Order</h6>
                </div>
                <div class="card-body py-3">
                    <div class="row g-3">
                        @foreach ($order->items as $item)
                            @php $atlQty = $atlByProduct[$item->product_name] ?? 0; @endphp
                            <div class="col-md-4">
                                <div class="border rounded-3 p-3 h-100">
                                    <div class="fw-bold text-dark">{{ $item->product_name }}</div>
                                    <div class="small text-muted">
                                        Required <strong class="text-dark">{{ number_format($item->qty) }} L</strong>
                                    </div>
                                    <div class="small text-muted">
                                        ATL'd <strong class="text-primary">{{ number_format($atlQty) }} L</strong>
                                    </div>
                                    <div class="small mt-1">
                                        @if ($atlQty === 0)
                                            <span class="badge bg-light text-muted border">Not ATL'd</span>
                                        @elseif ($atlQty === (int) $item->qty)
                                            <span class="badge bg-success-subtle text-success border border-success-subtle">Fully ATL'd</span>
                                        @elseif ($atlQty > (int) $item->qty)
                                            <span class="badge bg-danger-subtle text-danger border border-danger-subtle">Over by {{ number_format($atlQty - $item->qty) }} L</span>
                                        @else
                                            <span class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle">Short by {{ number_format($item->qty - $atlQty) }} L</span>
                                        @endif
                                    </div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>
        @endif

        {{-- ATLs --}}
        <div class="card card-custom border-0 shadow-sm">
            <div class="card-header bg-white py-3">
                <h6 class="fw-bold text-dark mb-0">ATL Records</h6>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="bg-light">
                            <tr>
                                <th class="ps-3 py-3">ATL #</th>
                                <th class="py-3">PO #</th>
                                <th class="py-3">Pick Up Date</th>
                                <th class="py-3">Product / Qty</th>
                                <th class="py-3">Status</th>
                                <th class="py-3 text-center">Approval</th>
                                <th class="pe-3 py-3 text-end">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($atls as $atl)
                                @php
                                    $breakdown = $atl->productBreakdown();
                                    $poNumbers = $atl->allocations
                                        ->pluck('purchaseOrder')
                                        ->filter()
                                        ->pluck('po_number')
                                        ->unique()
                                        ->join(', ');
                                    $poNumbers = $poNumbers !== '' ? $poNumbers : ($atl->purchaseOrder->po_number ?? '—');
                                @endphp
                                <tr>
                                    <td class="ps-3 fw-bold text-dark">
                                        {{ $atl->atl_number ?: ($atl->client_atl_number ?: 'Pending') }}
                                        @if ($atl->client_atl_number && $atl->atl_number)
                                            <div class="small text-muted">Client: {{ $atl->client_atl_number }}</div>
                                        @endif
                                    </td>
                                    <td class="small">{{ $poNumbers }}</td>
                                    <td class="small">{{ $atl->receiving_date ? $atl->receiving_date->format('M d, Y') : '—' }}</td>
                                    <td class="small">
                                        @if ($breakdown === [])
                                            <span class="text-muted">{{ $atl->product ?: '—' }} &mdash; {{ number_format($atl->qty_to_receive) }} L</span>
                                        @else
                                            @foreach ($breakdown as $product => $qty)
                                                <div><strong>{{ $product }}</strong> &mdash; {{ number_format($qty) }} L</div>
                                            @endforeach
                                        @endif
                                    </td>
                                    <td>
                                        @if ($atl->lift_status === \App\Models\PurchaseOrderDelivery::LIFT_LIFTED)
                                            <span class="badge bg-success-subtle text-success border border-success-subtle rounded-pill px-2 py-1">Lifted</span>
                                        @elseif ($atl->lift_status === \App\Models\PurchaseOrderDelivery::LIFT_CANCELLED)
                                            <span class="badge bg-danger-subtle text-danger border border-danger-subtle rounded-pill px-2 py-1">Cancelled</span>
                                        @else
                                            <span class="badge bg-primary-subtle text-primary border border-primary-subtle rounded-pill px-2 py-1">Unlifted</span>
                                        @endif
                                        @if ($atl->driver_name)
                                            <div class="extra-small text-muted mt-1">{{ $atl->driver_name }} @if ($atl->plate_number)({{ $atl->plate_number }})@endif</div>
                                        @endif
                                    </td>
                                    <td class="text-center">
                                        @if ($atl->isClientProvided())
                                            <span class="badge bg-light text-muted border rounded-pill px-2 py-1" title="Client-provided ATLs are recorded only">Client</span>
                                        @elseif ($atl->isApproved())
                                            <span class="badge bg-success-subtle text-success border border-success-subtle rounded-pill px-2 py-1">Approved</span>
                                        @elseif ($atl->approval_status === \App\Models\PurchaseOrderDelivery::APPROVAL_REJECTED)
                                            <span class="badge bg-danger-subtle text-danger border border-danger-subtle rounded-pill px-2 py-1">Rejected</span>
                                        @elseif ($atl->approval_status === \App\Models\PurchaseOrderDelivery::APPROVAL_FOR_APPROVAL)
                                            <span class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle rounded-pill px-2 py-1">For Approval</span>
                                        @else
                                            <span class="badge bg-light text-muted border rounded-pill px-2 py-1">Draft</span>
                                        @endif
                                    </td>
                                    <td class="pe-3 text-end">
                                        <div class="d-inline-flex gap-1 justify-content-end">
                                            @if ($atl->hasScannedDocs())
                                                <a href="{{ $atl->scanned_doc_url }}" target="_blank"
                                                   class="btn btn-sm btn-outline-success rounded-pill px-2 py-1" title="View scanned ATL">
                                                    <i class="bi bi-google me-1"></i> Scanned
                                                </a>
                                            @endif
                                            @if ($atl->requiresAtl() && $atl->atl_number && $atl->status !== 'Pending')
                                                <a href="{{ route('stock-orders.pdf', $atl->id) }}"
                                                   class="btn btn-sm btn-outline-danger rounded-pill px-2 py-1" title="Download ATL PDF">
                                                    <i class="bi bi-file-earmark-pdf me-1"></i> ATL PDF
                                                </a>
                                            @else
                                                <span class="text-muted" title="Awaiting ATL issuance">&mdash;</span>
                                                <span class="badge bg-light text-muted border rounded-pill px-2 py-1 ms-1">Pending Issuance</span>
                                            @endif
                                        </div>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="7" class="text-center py-5 text-muted">
                                        <i class="bi bi-file-earmark-x fs-1 d-block mb-3 text-secondary"></i>
                                        No ATLs issued for this order yet.
                                    </td>
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
