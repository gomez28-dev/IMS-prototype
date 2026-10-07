@extends('layouts.app')

@section('title', 'Purchase Orders')

@section('content')
<div class="row justify-content-center">
    <div class="col-12">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-4">
            <div>
                <nav aria-label="breadcrumb">
                    <ol class="breadcrumb mb-1 small">
                        <li class="breadcrumb-item"><a href="{{ route('portal') }}" class="text-decoration-none text-secondary">Portal</a></li>
                        <li class="breadcrumb-item active" aria-current="page">Purchase Orders</li>
                    </ol>
                </nav>
                <h3 class="fw-bold text-dark mb-0">
                    <i class="bi bi-card-checklist text-primary me-2"></i>Purchase Orders
                </h3>
            </div>
            <div class="d-flex gap-2">
                @if (Auth::user()->canEditStockOrders())
                <a href="{{ route('stock-orders.create-supplier-po') }}" class="btn btn-primary-custom shadow-sm">
                    <i class="bi bi-plus-lg me-1"></i> New Supplier PO
                </a>
                @endif
                <a href="{{ route('stock-orders.approvals') }}" class="btn btn-outline-success">
                    <i class="bi bi-patch-check me-1"></i> Approvals Queue
                </a>
                <a href="{{ route('stock-orders.wet-stock-requests.index') }}" class="btn btn-outline-primary">
                    <i class="bi bi-truck me-1"></i> Deliveries & ATLs
                </a>
            </div>
        </div>

        {{-- Fuel Trade Queue Card (if pending SOs exist) --}}

        {{-- Stat cards: this page stands on its own, so there is no separate Dashboard. --}}
        <div class="row g-3 mb-4">
            <div class="col-6 col-xl-3">
                <div class="card card-custom border-0 shadow-sm h-100">
                    <div class="card-body p-3">
                        <div class="text-muted small text-uppercase fw-semibold">Total POs</div>
                        <div class="fs-4 fw-bold text-dark">{{ number_format($stats['total']) }}</div>
                    </div>
                </div>
            </div>
            <div class="col-6 col-xl-3">
                <div class="card card-custom border-0 shadow-sm h-100">
                    <div class="card-body p-3">
                        <div class="text-muted small text-uppercase fw-semibold">Awaiting Action</div>
                        <div class="fs-4 fw-bold text-warning">{{ number_format($stats['pending']) }}</div>
                    </div>
                </div>
            </div>
            <div class="col-6 col-xl-3">
                <div class="card card-custom border-0 shadow-sm h-100">
                    <div class="card-body p-3">
                        <div class="text-muted small text-uppercase fw-semibold">For Delivery</div>
                        <div class="fs-4 fw-bold text-primary">{{ number_format($stats['for_delivery']) }}</div>
                    </div>
                </div>
            </div>
            <div class="col-6 col-xl-3">
                <div class="card card-custom border-0 shadow-sm h-100">
                    <div class="card-body p-3">
                        <div class="text-muted small text-uppercase fw-semibold">Completed</div>
                        <div class="fs-4 fw-bold text-success">{{ number_format($stats['fulfilled']) }}</div>
                    </div>
                </div>
            </div>
        </div>

        {{-- Filter Bar --}}
        <div class="card card-custom border-0 shadow-sm mb-4">
            <div class="card-body p-3">
                <form method="GET" action="{{ route('stock-orders.purchase-orders.index') }}" class="row g-2 align-items-center">
                    <div class="col-md-3">
                        <input type="text" name="search" class="form-control form-control-sm" placeholder="Search PO # or Supplier..." value="{{ request('search') }}">
                    </div>
                    <div class="col-md-3">
                        <select name="po_type" class="form-select form-select-sm">
                            <option value="">-- All PO Types --</option>
                            <option value="STANDARD_REPLENISHMENT" {{ request('po_type') === 'STANDARD_REPLENISHMENT' ? 'selected' : '' }}>Standard Replenishment</option>
                            <option value="FUEL_TRADE" {{ request('po_type') === 'FUEL_TRADE' ? 'selected' : '' }}>Fuel Trade (Direct to Client)</option>
                            <option value="BUY_BACK" {{ request('po_type') === 'BUY_BACK' ? 'selected' : '' }}>PO Buy Back</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <select name="status" class="form-select form-select-sm">
                            <option value="">-- All Statuses --</option>
                            <option value="REQUESTED" {{ request('status') === 'REQUESTED' ? 'selected' : '' }}>1. REQUESTED</option>
                            <option value="RECEIVED" {{ request('status') === 'RECEIVED' ? 'selected' : '' }}>2. RECEIVED (Pending VP)</option>
                            <option value="CONFIRMED" {{ request('status') === 'CONFIRMED' ? 'selected' : '' }}>3. CONFIRMED (Approved)</option>
                            <option value="FOR_DELIVERY" {{ request('status') === 'FOR_DELIVERY' ? 'selected' : '' }}>4. FOR DELIVERY</option>
                            <option value="COMPLETED" {{ request('status') === 'COMPLETED' ? 'selected' : '' }}>5. COMPLETED</option>
                        </select>
                    </div>
                    <div class="col-md-3 d-flex gap-2">
                        <button type="submit" class="btn btn-sm btn-primary w-100">Filter</button>
                        <a href="{{ route('stock-orders.purchase-orders.index') }}" class="btn btn-sm btn-outline-secondary">Reset</a>
                    </div>
                </form>
            </div>
        </div>

        {{-- Purchase Orders Master Table --}}
        <div class="card card-custom border-0 shadow-sm">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="bg-light">
                            <tr>
                                <th class="ps-3 py-3">PO Number</th>
                                <th class="py-3">Type</th>
                                <th class="py-3">Supplier / Source</th>
                                <th class="py-3">Site / Channel</th>
                                <th class="py-3 text-end">Volume</th>
                                <th class="py-3 text-center">Pipeline Status</th>
                                <th class="py-3 text-center">Computed Status</th>
                                <th class="py-3">Requester</th>
                                <th class="pe-3 py-3 text-end">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($purchaseOrders as $po)
                                <tr>
                                    <td class="ps-3 fw-bold text-dark">
                                        {{ $po->po_number ?: 'Pending PO#' }}
                                        @if ($po->linkedOrder)
                                            <div class="small text-muted">SO: {{ $po->linkedOrder->so_number }}</div>
                                        @endif
                                    </td>
                                    <td>
                                        @if ($po->isFuelTrade())
                                            <span class="badge bg-warning text-dark border">Fuel Trade</span>
                                        @elseif ($po->isBuyBack())
                                            <span class="badge bg-info text-dark border">Buy Back</span>
                                        @else
                                            <span class="badge bg-light text-dark border">Standard Replenishment</span>
                                        @endif
                                    </td>
                                    <td>
                                        <strong>{{ $po->supplier_name ?: ($po->client->name ?? '—') }}</strong>
                                    </td>
                                    <td>
                                        @if ($po->warehouse)
                                            <i class="bi bi-geo-alt text-primary me-1"></i>{{ $po->warehouse->name }}
                                        @elseif ($po->isFuelTrade())
                                            <span class="badge bg-light text-dark border">Direct Pick-up</span>
                                        @else
                                            —
                                        @endif
                                    </td>
                                    <td class="text-end">
                                        <div class="fw-bold font-monospace">{{ number_format($po->qty_ordered) }} L</div>
                                        @if ($po->items->isNotEmpty())
                                            <div class="extra-small mt-1">
                                                @foreach ($po->items as $it)
                                                    <span class="badge bg-light text-dark border me-1" style="font-size: 0.65rem;" title="{{ $it->product }}: {{ number_format($it->remaining_balance) }}L remaining">
                                                        {{ $it->product }}: {{ number_format($it->remaining_balance) }}L
                                                    </span>
                                                @endforeach
                                            </div>
                                        @else
                                            <div class="extra-small text-muted">{{ number_format($po->remaining_balance) }} L remaining</div>
                                        @endif
                                    </td>
                                    <td class="text-center">
                                        @if ($po->request_status === 'REQUESTED')
                                            <span class="badge bg-secondary-subtle text-secondary border rounded-pill px-3 py-1">1. REQUESTED</span>
                                        @elseif ($po->request_status === 'RECEIVED')
                                            <span class="badge bg-warning-subtle text-warning border rounded-pill px-3 py-1">2. RECEIVED (Pending VP)</span>
                                        @elseif ($po->request_status === 'CONFIRMED')
                                            <span class="badge bg-info-subtle text-info border rounded-pill px-3 py-1">3. CONFIRMED (Approved)</span>
                                        @elseif ($po->request_status === 'FOR_DELIVERY')
                                            <span class="badge bg-primary rounded-pill px-3 py-1 text-white">4. FOR DELIVERY</span>
                                        @elseif ($po->request_status === 'COMPLETED')
                                            <span class="badge bg-success-subtle text-success border rounded-pill px-3 py-1">5. COMPLETED</span>
                                        @endif
                                    </td>
                                    <td class="text-center">
                                        <span class="badge bg-light text-dark border rounded-pill px-3 py-1">{{ $po->computed_status }}</span>
                                    </td>
                                    <td class="small text-muted">{{ $po->requester->name ?? 'System' }}</td>
                                    <td class="pe-3 text-end">
                                        @if ($po->request_status === 'REQUESTED' && Auth::user()->canEditStockOrders())
                                            <a href="{{ route('stock-orders.edit-request', $po->id) }}" class="btn btn-sm btn-primary rounded-pill px-3 py-1">
                                                Process / ATL
                                            </a>
                                        @elseif ($po->request_status === 'RECEIVED')
                                            @if (Auth::user()->canApproveStockOrders())
                                                <a href="{{ route('stock-orders.approvals') }}" class="btn btn-sm btn-outline-success rounded-pill px-3 py-1" title="Review in Approvals Queue">
                                                    <i class="bi bi-patch-check me-1"></i> Review for Approval
                                                </a>
                                            @else
                                                <span class="badge bg-warning-subtle text-warning border rounded-pill px-3 py-1">Pending VP</span>
                                            @endif
                                        @elseif ($po->request_status === 'FOR_DELIVERY')
                                            @php
                                                $atlDel = $po->deliveries->first(fn($d) => $d->requiresAtl());
                                                $activeDel = $po->deliveries->firstWhere('status', 'Active');
                                            @endphp
                                            <div class="d-inline-flex gap-1 align-items-center">
                                                @if ($po->isFuelTrade() && $activeDel && Auth::user()->canEditStockOrders())
                                                    <button type="button" class="btn btn-sm btn-success rounded-pill px-3 py-1 shadow-sm" data-bs-toggle="modal" data-bs-target="#confirmLiftModal{{ $activeDel->id }}">
                                                        <i class="bi bi-check-circle me-1"></i> Mark Picked Up
                                                    </button>
                                                @endif
                                                @if ($activeDel && $activeDel->hasScannedDocs())
                                                    <a href="{{ $activeDel->scanned_doc_url }}" target="_blank" class="btn btn-sm btn-outline-success rounded-pill px-2 py-1 shadow-sm" title="View Scanned Documents on Google Drive">
                                                        <i class="bi bi-google me-1"></i> Docs
                                                    </a>
                                                @endif
                                                @if ($atlDel)
                                                    <a href="{{ route('stock-orders.pdf', $atlDel->id) }}" class="btn btn-sm btn-outline-danger rounded-pill px-2 py-1 shadow-sm" title="Extract Authority to Load PDF">
                                                        <i class="bi bi-file-earmark-pdf me-1"></i> ATL PDF
                                                    </a>
                                                @endif
                                                <a href="{{ route('stock-orders.wet-stock-requests.show', $po->id) }}" class="btn btn-sm btn-light border rounded-pill px-2 py-1 text-secondary" title="Open request">
                                                    <i class="bi bi-truck me-1"></i> Track
                                                </a>
                                            </div>
                                        @elseif ($po->request_status === 'COMPLETED')
                                            <a href="{{ route('stock-orders.purchase-orders.show', $po->id) }}" class="btn btn-sm btn-light border rounded-pill px-3 py-1 text-muted">
                                                <i class="bi bi-eye me-1"></i> View Record
                                            </a>
                                        @else
                                            <div class="d-inline-flex gap-1 align-items-center">
                                                <a href="{{ route('stock-orders.po-pdf', $po->id) }}"
                                                   class="btn btn-sm btn-outline-danger rounded-pill px-2 py-1" title="Download Purchase Order PDF">
                                                    <i class="bi bi-file-earmark-pdf me-1"></i> PO PDF
                                                </a>
                                                <a href="{{ route('stock-orders.purchase-orders.show', $po->id) }}" class="btn btn-sm btn-light border rounded-pill px-2 py-1 text-muted">
                                                    <i class="bi bi-arrow-right-circle me-1"></i> Details
                                                </a>
                                            </div>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="9" class="text-center py-5 text-muted">No stock orders found matching criteria.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                <div class="p-3">
                    {{ $purchaseOrders->links() }}
                </div>
            </div>
        </div>
    </div>
</div>

{{-- Modals for Confirming Lift & Auto-Stocking --}}
@foreach ($purchaseOrders as $po)
    @php
        $activeDel = $po->deliveries->firstWhere('status', 'Active');
    @endphp
    @if ($po->isFuelTrade() && $activeDel)
        <div class="modal fade" id="confirmLiftModal{{ $activeDel->id }}" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content rounded-4 border-0 shadow">
                    <div class="modal-header border-0 pb-0">
                        <h5 class="modal-title fw-bold text-dark">
                            <i class="bi bi-patch-check-fill text-success me-1"></i> Confirm Refinery Pick Up / Lift
                        </h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <form method="POST" action="{{ route('stock-orders.complete-fuel-trade', $activeDel->id) }}">
                        @csrf
                        <div class="modal-body pt-3">
                            <div class="p-3 bg-light rounded-3 mb-3">
                                <div class="d-flex justify-content-between small text-muted mb-1">
                                    <span>PO #{{ $po->po_number }}</span>
                                    <span class="badge bg-secondary-subtle text-secondary">{{ $activeDel->channel_label }}</span>
                                </div>
                                <div class="fw-bold fs-5 text-primary mb-1">{{ number_format($activeDel->qty_to_receive) }} L of {{ $activeDel->product }}</div>
                                <div class="small text-muted">
                                    Client: <span class="fw-semibold text-dark">{{ $po->linkedOrder?->account ?? $po->client?->name ?? '—' }}</span> (SO: {{ $po->linkedOrder?->formatted_so_number ?? '—' }})
                                </div>
                            </div>

                            @if ($activeDel->isTankerPickup())
                                <div class="mb-3">
                                    <label class="form-label small fw-semibold">Target Mobile Tanker Truck <span class="text-danger">*</span></label>
                                    <select name="storage_tank_id" class="form-select" required>
                                        <option value="">Select DITC Tanker to receive fuel...</option>
                                        @foreach($mobileTankers ?? [] as $tank)
                                            <option value="{{ $tank->id }}" {{ $activeDel->storage_tank_id == $tank->id ? 'selected' : '' }}>
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
                                    <input type="text" name="supplier_so_number" class="form-control form-control-sm" placeholder="e.g. SO-8842" value="{{ $activeDel->supplier_so_number }}">
                                </div>
                                <div class="col-6">
                                    <label class="form-label small fw-semibold">Supplier Rack DR #</label>
                                    <input type="text" name="supplier_dr_number" class="form-control form-control-sm" placeholder="e.g. DR-9901" value="{{ $activeDel->supplier_dr_number }}">
                                </div>
                            </div>

                            <div class="mb-2">
                                <label class="form-label small fw-semibold">
                                    <i class="bi bi-google text-danger me-1"></i>Google Drive Scanned Document Link
                                </label>
                                <input type="url" name="scanned_doc_url" class="form-control form-control-sm" placeholder="https://drive.google.com/..." value="{{ $activeDel->scanned_doc_url }}">
                                <div class="form-text text-muted extra-small" style="font-size: 0.75rem;">Shareable link to signed DR and dip inspection tickets.</div>
                            </div>
                        </div>
                        <div class="modal-footer border-0 pt-0">
                            <button type="button" class="btn btn-secondary rounded-pill px-4" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" class="btn btn-success rounded-pill px-4">
                                <i class="bi bi-check-circle me-1"></i> Confirm Lift
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    @endif
@endforeach
@endsection