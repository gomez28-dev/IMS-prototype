@extends('layouts.app')

@section('title', 'Sales Order')

@section('content')
<div class="row justify-content-center">
    <div class="col-12">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-4">
            <div>
                <nav aria-label="breadcrumb">
                    <ol class="breadcrumb mb-1 small">
                        <li class="breadcrumb-item"><a href="{{ route('portal') }}" class="text-decoration-none text-secondary">Portal</a></li>
                        <li class="breadcrumb-item"><a href="{{ route('stock-orders.sales-orders.index') }}" class="text-decoration-none text-secondary">Sales Orders</a></li>
                        <li class="breadcrumb-item active" aria-current="page">{{ $order->formatted_so_number }}</li>
                    </ol>
                </nav>
                <h3 class="fw-bold text-dark mb-0">
                    <i class="bi bi-patch-check text-primary me-2"></i>ATL Details &mdash; {{ $order->formatted_so_number }}
                </h3>
                <p class="text-muted small mb-0 mt-1">{{ $order->account }}</p>
            </div>
            <div class="d-flex gap-2">
                {{-- Mark Picked Up moved here from the Purchase Orders list,
                     which now shows created POs only and no longer carries the
                     lift action. --}}
                @if ($liftableDelivery && Auth::user()->canMarkAtlLifted())
                    <button type="button" class="btn btn-success" data-bs-toggle="modal" data-bs-target="#confirmLiftModal{{ $liftableDelivery->id }}">
                        <i class="bi bi-check-circle me-1"></i> Mark Picked Up
                    </button>
                @endif
                @if ($order->canBeIssuedAtl() && Auth::user()->canEditStockOrders())
                    <a href="{{ route('stock-orders.create-fuel-trade-po', $order->id) }}" class="btn btn-primary-custom">
                        <i class="bi bi-plus-lg me-1"></i> Create ATL
                    </a>
                @endif
                <a href="{{ route('stock-orders.sales-orders.index') }}" class="btn btn-outline-secondary">
                    <i class="bi bi-arrow-left me-1"></i> Back to Sales Orders
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
                                <th class="ps-3 py-3">REF #</th>
                                <th class="py-3">Type</th>
                                <th class="py-3">ATL #</th>
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
                                        {{-- REF# says what the ATL serves: SO-FT or SO-BB. --}}
                                        {{ $atl->refNumber() }}
                                    </td>
                                    <td>
                                        <span class="badge bg-light text-dark border">{{ $atl->typeLabel() }}</span>
                                    </td>
                                    <td class="small">
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
                                        {{-- Exactly two actions: View opens the ATL pop-up,
                                             Pen opens the ATL form. The PDF download lives
                                             inside the pop-up and is VP gated there. --}}
                                        <div class="d-inline-flex gap-1 justify-content-end">
                                            <button type="button" class="btn btn-sm btn-light border rounded-pill px-2 py-1 text-secondary"
                                                    data-bs-toggle="modal" data-bs-target="#atlDetailModal{{ $atl->id }}"
                                                    title="View ATL">
                                                <i class="bi bi-eye me-1"></i> View
                                            </button>
                                            @if (Auth::user()->canEditStockOrders())
                                                <a href="{{ route('stock-orders.add-atl', $order->id) }}"
                                                   class="btn btn-sm btn-light border rounded-pill px-2 py-1 text-secondary" title="Edit ATL">
                                                    <i class="bi bi-pencil me-1"></i> Pen
                                                </a>
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

{{-- ATL detail pop-up, opened by the View action on each ATL row. --}}
@foreach ($atls as $atl)
    <div class="modal fade" id="atlDetailModal{{ $atl->id }}" tabindex="-1" aria-labelledby="atlDetailModalLabel{{ $atl->id }}" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content rounded-4 border-0 shadow">
                <div class="modal-header border-0 pb-0">
                    <h5 class="modal-title fw-bold text-dark" id="atlDetailModalLabel{{ $atl->id }}">
                        <i class="bi bi-file-earmark-text text-primary me-1"></i> ATL {{ $atl->atl_number ?: ($atl->client_atl_number ?: 'Pending') }}
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body pt-3">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <div class="text-muted extra-small fw-semibold text-uppercase">REF #</div>
                            <div class="fw-bold">{{ $atl->refNumber() }}</div>
                        </div>
                        <div class="col-md-6">
                            <div class="text-muted extra-small fw-semibold text-uppercase">Type</div>
                            <div class="fw-bold">{{ $atl->typeLabel() }}</div>
                        </div>
                        <div class="col-md-6">
                            <div class="text-muted extra-small fw-semibold text-uppercase">Client</div>
                            <div class="fw-semibold">{{ $order->account }}</div>
                        </div>
                        <div class="col-md-6">
                            <div class="text-muted extra-small fw-semibold text-uppercase">Pick Up Date</div>
                            <div class="fw-semibold">{{ $atl->receiving_date ? $atl->receiving_date->format('M d, Y') : '—' }}</div>
                        </div>
                        <div class="col-md-6">
                            <div class="text-muted extra-small fw-semibold text-uppercase">Channel</div>
                            <div class="fw-semibold">{{ $atl->channel_label ?: '—' }}</div>
                        </div>
                        <div class="col-md-6">
                            <div class="text-muted extra-small fw-semibold text-uppercase">Driver</div>
                            <div class="fw-semibold">
                                {{ $atl->driver_name ?: '—' }}
                                @if ($atl->plate_number)
                                    <span class="text-muted">({{ $atl->plate_number }})</span>
                                @endif
                            </div>
                        </div>
                        <div class="col-12">
                            <div class="text-muted extra-small fw-semibold text-uppercase mb-1">Product / Qty</div>
                            @php
                                $modalBreakdown = $atl->productBreakdown();
                            @endphp
                            @if ($modalBreakdown === [])
                                <div class="fw-semibold">{{ $atl->product ?: '—' }} — {{ number_format($atl->qty_to_receive) }} L</div>
                            @else
                                @foreach ($modalBreakdown as $product => $qty)
                                    <div class="fw-semibold">{{ $product }} — {{ number_format($qty) }} L</div>
                                @endforeach
                            @endif
                        </div>
                        @if ($atl->dr_number)
                            <div class="col-md-6">
                                <div class="text-muted extra-small fw-semibold text-uppercase">DR #</div>
                                <div class="fw-semibold">{{ $atl->dr_number }}</div>
                            </div>
                        @endif
                        @if ($atl->additional_remarks)
                            <div class="col-12">
                                <div class="text-muted extra-small fw-semibold text-uppercase mb-1">Remarks</div>
                                <div class="small">{{ $atl->additional_remarks }}</div>
                            </div>
                        @endif
                    </div>
                </div>
                <div class="modal-footer border-0 pt-0">
                    @if ($atl->hasScannedDocs())
                        <a href="{{ $atl->scanned_doc_url }}" target="_blank" class="btn btn-outline-success rounded-pill px-3">
                            <i class="bi bi-google me-1"></i> Scanned Docs
                        </a>
                    @endif
                    {{-- The ATL document is only released once the VP has approved,
                         which the route enforces as well as this link. --}}
                    @if ($atl->requiresAtl() && $atl->atl_number
                        && $atl->status !== 'Pending'
                        && $atl->approval_status !== \App\Models\PurchaseOrderDelivery::APPROVAL_REJECTED
                        && ($atl->purchaseOrder?->isVpApproved() ?? $atl->approval_status === \App\Models\PurchaseOrderDelivery::APPROVAL_APPROVED))
                        <a href="{{ route('stock-orders.pdf', $atl->id) }}" class="btn btn-outline-danger rounded-pill px-3">
                            <i class="bi bi-file-earmark-pdf me-1"></i> ATL PDF
                        </a>
                    @else
                        <span class="badge bg-light text-muted border rounded-pill px-3 py-2"
                              title="The ATL document is released once the VP has approved">
                            @if ($atl->approval_status === \App\Models\PurchaseOrderDelivery::APPROVAL_REJECTED)
                                Rejected
                            @else
                                Awaiting VP Approval
                            @endif
                        </span>
                    @endif
                    <button type="button" class="btn btn-secondary rounded-pill px-4" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>
@endforeach

@if ($liftableDelivery)
    <div class="modal fade" id="confirmLiftModal{{ $liftableDelivery->id }}" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content rounded-4 border-0 shadow">
                <div class="modal-header border-0 pb-0">
                    <h5 class="modal-title fw-bold text-dark">
                        <i class="bi bi-patch-check-fill text-success me-1"></i> Confirm Pick Up / Lift
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="POST" action="{{ route('stock-orders.complete-fuel-trade', $liftableDelivery->id) }}">
                    @csrf
                    <div class="modal-body pt-3">
                        <div class="p-3 bg-light rounded-3 mb-3">
                            <div class="d-flex justify-content-between small text-muted mb-1">
                                <span>SO {{ $order->formatted_so_number }}</span>
                                <span class="badge bg-secondary-subtle text-secondary">{{ $liftableDelivery->channel_label }}</span>
                            </div>
                            <div class="fw-bold fs-5 text-primary mb-1">{{ number_format($liftableDelivery->qty_to_receive) }} L of {{ $liftableDelivery->product }}</div>
                            <div class="small text-muted">
                                Client: <span class="fw-semibold text-dark">{{ $order->account }}</span>
                            </div>
                        </div>

                        @if ($liftableDelivery->isTankerPickup())
                            <div class="mb-3">
                                <label class="form-label small fw-semibold">Target Mobile Tanker Truck <span class="text-danger">*</span></label>
                                <select name="storage_tank_id" class="form-select" required>
                                    <option value="">Select DITC Tanker to receive fuel...</option>
                                    @foreach ($mobileTankers as $tank)
                                        <option value="{{ $tank->id }}" {{ $liftableDelivery->storage_tank_id == $tank->id ? 'selected' : '' }}>
                                            {{ $tank->name }} ({{ $tank->warehouse->name ?? 'Depot' }}) — Remaining: {{ number_format($tank->remaining_capacity) }}L / Max: {{ number_format($tank->max_capacity) }}L
                                        </option>
                                    @endforeach
                                </select>
                                <div class="form-text text-primary extra-small" style="font-size: 0.75rem;">
                                    <i class="bi bi-info-circle me-1"></i>Volume will automatically stock into this tanker in Module 2.
                                </div>
                            </div>
                        @else
                            <div class="alert alert-info border-0 rounded-3 small py-2 mb-3">
                                <i class="bi bi-info-circle me-1"></i><strong>Direct Client Rack Lift:</strong> This order will be marked as fulfilled directly. No wet stock movements required.
                            </div>
                        @endif

                        <div class="row g-2 mb-3">
                            <div class="col-6">
                                <label class="form-label small fw-semibold">Supplier SO #</label>
                                <input type="text" name="supplier_so_number" class="form-control form-control-sm" placeholder="e.g. SO-8842" value="{{ $liftableDelivery->supplier_so_number }}">
                            </div>
                            <div class="col-6">
                                <label class="form-label small fw-semibold">Supplier Rack DR #</label>
                                <input type="text" name="supplier_dr_number" class="form-control form-control-sm" placeholder="e.g. DR-9901" value="{{ $liftableDelivery->supplier_dr_number }}">
                            </div>
                        </div>

                        <div class="mb-2">
                            <label class="form-label small fw-semibold">
                                <i class="bi bi-google text-danger me-1"></i>Google Drive Scanned Document Link
                            </label>
                            <input type="url" name="scanned_doc_url" class="form-control form-control-sm" placeholder="https://drive.google.com/..." value="{{ $liftableDelivery->scanned_doc_url }}">
                            <div class="form-text text-muted extra-small" style="font-size: 0.75rem;">Shareable link to signed DR and dip inspection tickets.</div>
                        </div>
                    </div>
                    <div class="modal-footer border-0 pt-0">
                        <button type="button" class="btn btn-secondary rounded-pill px-4" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-success rounded-pill px-4">
                            <i class="bi bi-check-circle me-1"></i> Confirm Pick Up
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
@endif
@endsection
