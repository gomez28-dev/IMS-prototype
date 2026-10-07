@extends('layouts.app')

@section('title', 'Wet Stock Request')

@section('content')
<div class="container-fluid py-2">

    {{-- Stat cards: the list page carries these, and the detail page repeats
         the ones that matter for a single request. --}}
    <div class="row g-3 mb-4">
        <div class="col-6 col-xl-3">
            <div class="card card-custom border-0 shadow-sm h-100">
                <div class="card-body p-3">
                    <div class="text-muted small text-uppercase fw-semibold">Liters Requested</div>
                    <div class="fs-4 fw-bold text-dark">{{ number_format($totals['requested']) }} L</div>
                </div>
            </div>
        </div>
        <div class="col-6 col-xl-3">
            <div class="card card-custom border-0 shadow-sm h-100">
                <div class="card-body p-3">
                    <div class="text-muted small text-uppercase fw-semibold">ATLs Raised</div>
                    <div class="fs-4 fw-bold text-primary">{{ number_format($totals['count']) }}</div>
                </div>
            </div>
        </div>
        <div class="col-6 col-xl-3">
            <div class="card card-custom border-0 shadow-sm h-100">
                <div class="card-body p-3">
                    <div class="text-muted small text-uppercase fw-semibold">Liters Covered</div>
                    <div class="fs-4 fw-bold text-success">{{ number_format($totals['issued']) }} L</div>
                </div>
            </div>
        </div>
        <div class="col-6 col-xl-3">
            <div class="card card-custom border-0 shadow-sm h-100">
                <div class="card-body p-3">
                    <div class="text-muted small text-uppercase fw-semibold">Pipeline Status</div>
                    <div class="fs-6 fw-bold text-dark mt-1">{{ $request->request_status }}</div>
                </div>
            </div>
        </div>
    </div>

    <div class="d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center mb-4 gap-3">
        <div>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-1 small">
                    <li class="breadcrumb-item"><a href="{{ route('portal') }}" class="text-decoration-none text-secondary">Portal</a></li>
                    <li class="breadcrumb-item"><a href="{{ route('stock-orders.wet-stock-requests.index') }}" class="text-decoration-none text-secondary">Wet Stock Requests</a></li>
                    <li class="breadcrumb-item active" aria-current="page">
                        {{ $request->po_number ?: ('REQ-' . str_pad((string) $request->id, 4, '0', STR_PAD_LEFT)) }}
                    </li>
                </ol>
            </nav>
            <h3 class="fw-bold text-dark mb-0">
                <i class="bi bi-droplet-half text-primary me-2"></i>Wet Stock Request
            </h3>
        </div>
        <div class="d-flex gap-2 flex-wrap">
            <a href="{{ route('stock-orders.wet-stock-requests.index') }}" class="btn btn-outline-secondary">
                <i class="bi bi-arrow-left me-1"></i> Back to Wet Stock Requests
            </a>
            @if (Auth::user()->canEditStockOrders())
                <a href="{{ route('stock-orders.edit-request', $request->id) }}" class="btn btn-primary-custom shadow-sm">
                    <i class="bi bi-patch-plus me-1"></i> Prepare / Issue ATL
                </a>
            @endif
        </div>
    </div>

    <div class="row g-4">
        {{-- Request header --}}
        <div class="col-12 col-lg-5">
            <div class="card card-custom border-0 shadow-sm h-100">
                <div class="card-header bg-white border-0 py-3">
                    <h5 class="fw-bold text-dark mb-0"><i class="bi bi-info-circle text-primary me-2"></i>Request Details</h5>
                </div>
                <div class="card-body">
                    <dl class="row mb-0 small">
                        <dt class="col-5 text-muted fw-semibold">PO Number</dt>
                        <dd class="col-7">{{ $request->po_number ?: 'Not yet issued' }}</dd>

                        <dt class="col-5 text-muted fw-semibold">Site</dt>
                        <dd class="col-7">{{ $request->warehouse->name ?? 'All Depots' }}</dd>

                        <dt class="col-5 text-muted fw-semibold">Request Type</dt>
                        <dd class="col-7"><span class="badge bg-light text-dark border">{{ $request->po_type }}</span></dd>

                        <dt class="col-5 text-muted fw-semibold">Pipeline Status</dt>
                        <dd class="col-7"><span class="badge bg-warning text-dark px-2 py-1">{{ $request->request_status }}</span></dd>

                        <dt class="col-5 text-muted fw-semibold">Requested By</dt>
                        <dd class="col-7">{{ $request->requester->name ?? 'Depot' }}</dd>

                        <dt class="col-5 text-muted fw-semibold">Request Date</dt>
                        <dd class="col-7">{{ $request->request_date ? $request->request_date->format('M d, Y') : '—' }}</dd>

                        <dt class="col-5 text-muted fw-semibold">Date Needed</dt>
                        <dd class="col-7">{{ $request->date_needed ? $request->date_needed->format('M d, Y') : '—' }}</dd>

                        <dt class="col-5 text-muted fw-semibold">Remarks</dt>
                        <dd class="col-7">{{ $request->remarks ?: '—' }}</dd>
                    </dl>
                </div>
            </div>
        </div>

        {{-- Requested products --}}
        <div class="col-12 col-lg-7">
            <div class="card card-custom border-0 shadow-sm h-100">
                <div class="card-header bg-white border-0 py-3">
                    <h5 class="fw-bold text-dark mb-0"><i class="bi bi-fuel-pump text-primary me-2"></i>Requested Products</h5>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="bg-light">
                                <tr>
                                    <th class="ps-3 py-2">Product</th>
                                    <th class="py-2 text-end">Liters Requested</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($products as $line)
                                    <tr>
                                        <td class="ps-3 fw-semibold">{{ $line['product'] ?? '—' }}</td>
                                        <td class="text-end fw-bold">{{ number_format($line['quantity'] ?? 0) }} L</td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="2" class="text-center text-muted py-4">No product lines recorded.</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- ATLs raised against this request --}}
    <div class="card card-custom border-0 shadow-sm mt-4">
        <div class="card-header bg-white border-0 py-3">
            <h5 class="fw-bold text-dark mb-0"><i class="bi bi-patch-check text-primary me-2"></i>ATLs Raised Against This Request</h5>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="bg-light">
                        <tr>
                            <th class="ps-3 py-2">ATL / DR #</th>
                            <th class="py-2">Product</th>
                            <th class="py-2 text-end">Liters</th>
                            <th class="py-2">Channel</th>
                            <th class="py-2 text-center">Lift Status</th>
                            <th class="pe-3 py-2 text-end">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($atls as $atl)
                            <tr>
                                <td class="ps-3 fw-bold text-dark">
                                    @if ($atl->atl_number)
                                        {{ $atl->atl_number }}
                                    @elseif ($atl->client_atl_number)
                                        <span class="badge bg-warning-subtle text-dark border">Client: {{ $atl->client_atl_number }}</span>
                                    @else
                                        <span class="text-muted">Awaiting ATL</span>
                                    @endif
                                    @if ($atl->dr_number)
                                        <div class="small text-muted">DR: {{ $atl->dr_number }}</div>
                                    @endif
                                </td>
                                <td><span class="badge bg-secondary-subtle text-secondary border">{{ $atl->product }}</span></td>
                                <td class="text-end fw-bold">{{ number_format($atl->qty_to_receive) }} L</td>
                                <td class="small">{{ $atl->channel_label }}</td>
                                <td class="text-center">
                                    <span class="badge {{ $atl->lift_status === 'LIFTED' ? 'bg-success' : 'bg-warning text-dark' }} px-2 py-1">
                                        {{ $atl->lift_status === 'LIFTED' ? 'Lifted' : 'Unlifted' }}
                                    </span>
                                </td>
                                <td class="pe-3 text-end">
                                    @if ($atl->requiresAtl() && $atl->status !== 'Pending')
                                        <a href="{{ route('stock-orders.pdf', $atl->id) }}" class="btn btn-sm btn-outline-danger rounded-pill px-2 py-1" title="Download ATL PDF">
                                            <i class="bi bi-file-earmark-pdf me-1"></i> ATL PDF
                                        </a>
                                    @else
                                        <span class="text-muted">—</span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="text-center text-muted py-4">
                                    No ATL has been issued against this request yet.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

</div>
@endsection