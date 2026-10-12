@extends('layouts.app')

@section('title', 'Incoming Supplier Stock')

@section('content')
<div class="container-fluid py-2">
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center mb-4 gap-3">
        <div>
            <h2 class="fw-bold text-dark mb-1">Incoming Supplier Stock</h2>
            <p class="text-muted small mb-0">Track purchase orders from fuel suppliers (Unlifted Pickups &amp; Pending Depot Deliveries).</p>
        </div>
        <div class="d-flex gap-2">
            <a href="{{ route('wetstock.supplier-orders.index') }}" class="btn btn-secondary-custom">
                <i class="bi bi-box-seam me-1"></i> Incoming Stock Log
            </a>
            @if (Auth::user()->canEditModule2())
                <a href="{{ route('wetstock.stock-requests.create') }}" class="btn btn-primary-custom shadow-sm">
                    <i class="bi bi-plus-circle me-1"></i> Request Replenishment
                </a>
            @endif
        </div>
    </div>

    @if (session('success'))
        <div class="alert alert-success alert-dismissible fade show rounded-3" role="alert">
            <i class="bi bi-check-circle-fill me-2"></i> {{ session('success') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    @endif

    @if (session('danger'))
        <div class="alert alert-danger alert-dismissible fade show rounded-3" role="alert">
            <i class="bi bi-exclamation-triangle-fill me-2"></i> {{ session('danger') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    @endif

    <!-- Incoming Depot Deliveries Ready to Receive -->
    <div class="card card-custom border-0 shadow-sm mb-4">
        <div class="card-header bg-white border-0 py-3">
            <h5 class="fw-bold text-dark mb-0"><i class="bi bi-truck text-primary me-2"></i>Depot Inbound Deliveries</h5>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-custom mb-0">
                    <thead>
                        <tr>
                            <th>PO #</th>
                            <th>Channel</th>
                            <th>Product</th>
                            <th class="text-end">Liters</th>
                            <th>Supplier DR / ATL #</th>
                            <th>Status</th>
                            <th>Depot / Target</th>
                            <th class="text-end">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        @php
                            $activeDeliveries = $incomingDeliveries->whereIn('status', ['Pending', 'Active']);
                        @endphp
                        @forelse ($activeDeliveries as $del)
                            <tr>
                                <td class="fw-bold text-dark">{{ $del->purchaseOrder->po_number ?? 'PO #' . $del->purchase_order_id }}</td>
                                <td>
                                    <span class="badge bg-light text-dark border">{{ $del->channel_label }}</span>
                                </td>
                                <td><span class="badge bg-secondary-subtle text-secondary border">{{ $del->product }}</span></td>
                                <td class="text-end fw-bold text-primary">{{ number_format($del->qty_to_receive) }} L</td>
                                <td class="small">{{ $del->dr_number ?: ($del->atl_number ?: 'Pending') }}</td>
                                <td>
                                    <span class="badge {{ $del->status === 'Active' ? 'bg-info' : 'bg-warning text-dark' }} px-2 py-1">
                                        {{ $del->status === 'Active' ? 'Dispatched' : 'Pending' }}
                                    </span>
                                </td>
                                <td>{{ $del->purchaseOrder->warehouse->name ?? 'Any Depot' }}</td>
                                <td class="text-end">
                                    @if (Auth::user()->canReceiveStockIntoDepot())
                                        <button type="button" class="btn btn-sm btn-primary-custom" data-bs-toggle="modal" data-bs-target="#receiveStockModal{{ $del->id }}">
                                            <i class="bi bi-box-arrow-in-down me-1"></i> Receive Stock
                                        </button>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="8" class="text-center text-muted py-4">No incoming depot deliveries currently awaiting tank fill.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Replenishment Requests Table -->
    <div class="card card-custom border-0 shadow-sm">
        <div class="card-header bg-white border-0 py-3">
            <h5 class="fw-bold text-dark mb-0"><i class="bi bi-file-earmark-text text-secondary me-2"></i>Submitted Replenishment Requests</h5>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-custom mb-0">
                    <thead>
                        <tr>
                            <th>REQ #</th>
                            <th>Site</th>
                            <th>Type</th>
                            <th class="text-end">Qty. Requested</th>
                            <th class="text-end">Qty. Received</th>
                            <th class="text-end">Qty. Pending</th>
                            <th>Purchasing</th>
                            <th>Date Needed</th>
                            <th>Status</th>
                            <th>Requested By</th>
                            <th>Remarks</th>
                            <th class="pe-3 text-end">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($stockRequests as $req)
                            <tr>
                                <td class="fw-bold text-dark">{{ $req->request_number }}</td>
                                <td>{{ $req->warehouse->name ?? 'All Depots' }}</td>
                                <td><span class="badge bg-light text-dark border">{{ $req->type_label }}</span></td>
                                {{-- Volumes break out per product, because a request can
                                     cover more than one and Pending is per product:
                                     Requested minus Received. --}}
                                <td class="text-end fw-bold font-monospace">
                                    @if ($req->lines->isEmpty())
                                        <span class="text-muted">&mdash;</span>
                                    @else
                                        @foreach ($req->lines as $line)
                                            <div>{{ \Illuminate\Support\Str::substr((string) $line->product, 0, 1) }} &mdash; {{ number_format($line->qty_requested) }}</div>
                                        @endforeach
                                    @endif
                                </td>
                                <td class="text-end font-monospace">
                                    @if ($req->lines->isEmpty())
                                        <span class="text-muted">&mdash;</span>
                                    @else
                                        @foreach ($req->lines as $line)
                                            <div>{{ \Illuminate\Support\Str::substr((string) $line->product, 0, 1) }} &mdash; {{ number_format($line->qty_received) }}</div>
                                        @endforeach
                                    @endif
                                </td>
                                <td class="text-end font-monospace">
                                    @if ($req->lines->isEmpty())
                                        <span class="text-muted">&mdash;</span>
                                    @else
                                        @foreach ($req->lines as $line)
                                            <div class="{{ $line->qty_pending > 0 ? 'text-warning-emphasis fw-semibold' : 'text-success' }}">{{ \Illuminate\Support\Str::substr((string) $line->product, 0, 1) }} &mdash; {{ number_format($line->qty_pending) }}</div>
                                        @endforeach
                                    @endif
                                </td>
                                {{-- Purchasing picking the request up is reported on its
                                     own, separately from the fuel actually arriving. --}}
                                <td class="small">
                                    @if ($req->hasBeenPickedUpByPurchasing())
                                        <span class="badge bg-success-subtle text-success border rounded-pill px-2 py-1">
                                            <i class="bi bi-check2 me-1"></i>{{ $req->purchasingReceiver?->name ?? 'Purchasing' }}
                                        </span>
                                        <div class="extra-small text-muted mt-1">{{ $req->purchasing_received_at?->format('M d, Y') }}</div>
                                    @else
                                        <span class="badge bg-warning-subtle text-warning-emphasis border rounded-pill px-2 py-1">Awaiting</span>
                                    @endif
                                </td>
                                <td class="small">{{ $req->date_needed ? $req->date_needed->format('M d, Y') : '—' }}</td>
                                <td>
                                    <span class="badge bg-warning text-dark px-2 py-1">{{ $req->status }}</span>
                                </td>
                                <td class="small text-muted">{{ $req->requester->name ?? 'Depot' }}</td>
                                <td class="small text-muted">{{ $req->remarks ?: '—' }}</td>
                            <td class="pe-3 text-end">
                                    <div class="d-inline-flex gap-1 justify-content-end flex-wrap">
                                        {{-- Picking up and receiving are different things, so
                                             they are separate actions and separate columns. --}}
                                        @if (! $req->hasBeenPickedUpByPurchasing())
                                            <form method="POST" action="{{ route('wetstock.stock-requests.picked-up', $req->id) }}">
                                                @csrf
                                                <button type="submit" class="btn btn-sm btn-outline-success rounded-pill px-2 py-1" title="Mark picked up by Purchasing">
                                                    <i class="bi bi-check2-circle me-1"></i> Pick Up
                                                </button>
                                            </form>
                                        @endif

                                        @if (Auth::user()->canReceiveStockIntoDepot() && $req->lines->isNotEmpty())
                                            <button type="button" class="btn btn-sm btn-light border rounded-pill px-2 py-1 text-secondary"
                                                    data-bs-toggle="modal" data-bs-target="#receiveQuantitiesModal{{ $req->id }}"
                                                    title="Record quantities received">
                                                <i class="bi bi-box-arrow-in-down me-1"></i> Received
                                            </button>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="12" class="text-center text-muted py-4">No replenishment requests found.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="p-3">
                {{ $stockRequests->links() }}
            </div>
        </div>

        {{-- Buy Back orders from Sales Documentation that are delivered rather
             than collected. These route to Module 2 instead of Module 3, so they
             are surfaced here rather than disappearing from the system. --}}
        <div class="card card-custom border-0 shadow-sm mt-4">
            <div class="card-header bg-white border-0 py-3">
                <h5 class="fw-bold text-dark mb-0"><i class="bi bi-arrow-left-right text-warning me-2"></i>Buy Back Orders Routed to Module 2</h5>
                <small class="text-muted">Sales Documentation orders marked Buy Back + Delivery.</small>
            </div>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="bg-light">
                        <tr>
                            <th class="ps-3">SO Number</th>
                            <th>Account</th>
                            <th>Site</th>
                            <th class="text-end">Qty. Requested</th>
                            <th>Date Needed</th>
                            <th>Status</th>
                            <th>Requested By</th>
                            <th>Remarks</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($buyBackDeliveries as $bb)
                            <tr>
                                <td class="ps-3 fw-bold text-dark">{{ $bb->formatted_so_number }}</td>
                                <td class="small">{{ $bb->account }}</td>
                                <td class="small">{{ $bb->location }}</td>
                                <td class="text-end fw-bold">{{ number_format($bb->qty_ordered) }} L</td>
                                <td class="small">{{ $bb->date_needed ? $bb->date_needed->format('M d, Y') : '—' }}</td>
                                <td>
                                    <span class="badge bg-warning text-dark px-2 py-1">{{ $bb->status }}</span>
                                </td>
                                <td class="small text-muted">{{ $bb->creator?->name ?? 'Sales' }}</td>
                                <td class="small text-muted">{{ $bb->remarks ?? '—' }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="8" class="text-center text-muted py-4">No Buy Back delivery orders yet.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @if ($buyBackDeliveries->hasPages())
                <div class="p-3">
                    {{ $buyBackDeliveries->links() }}
                </div>
            @endif
        </div>
    </div>
</div>

{{-- Record what actually arrived on a request, per product line. --}}
@foreach ($stockRequests as $req)
    @if (Auth::user()->canReceiveStockIntoDepot() && $req->lines->isNotEmpty())
        <div class="modal fade" id="receiveQuantitiesModal{{ $req->id }}" tabindex="-1" aria-labelledby="receiveQuantitiesModalLabel{{ $req->id }}" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content rounded-4 border-0 shadow">
                    <div class="modal-header border-0 pb-0">
                        <h5 class="modal-title fw-bold text-dark" id="receiveQuantitiesModalLabel{{ $req->id }}">
                            <i class="bi bi-box-arrow-in-down text-success me-1"></i> Record Received &mdash; {{ $req->request_number }}
                        </h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <form method="POST" action="{{ route('wetstock.stock-requests.received', $req->id) }}">
                        @csrf
                        <div class="modal-body pt-3">
                            <table class="table table-sm align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th>Product</th>
                                        <th class="text-end">Requested</th>
                                        <th class="text-end" style="width: 130px;">Received</th>
                                        <th class="text-end">Pending</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($req->lines as $line)
                                        <tr>
                                            <td class="fw-semibold">{{ $line->product }}</td>
                                            <td class="text-end font-monospace">{{ number_format($line->qty_requested) }}</td>
                                            <td>
                                                <input type="number" name="quantities[{{ $line->id }}]" min="0" step="1"
                                                       class="form-control form-control-sm text-end"
                                                       value="{{ $line->qty_received }}">
                                            </td>
                                            <td class="text-end font-monospace">{{ number_format($line->qty_pending) }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                        <div class="modal-footer border-0 pt-0">
                            <button type="button" class="btn btn-secondary rounded-pill px-4" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" class="btn btn-success rounded-pill px-4">
                                <i class="bi bi-check2-circle me-1"></i> Save Received
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    @endif
@endforeach

{{-- Modals container outside table (prevents layout shift and flickering) --}}
@foreach ($incomingDeliveries->whereIn('status', ['Pending', 'Active']) as $del)
    @if (Auth::user()->canReceiveStockIntoDepot())
        <div class="modal fade" id="receiveStockModal{{ $del->id }}" tabindex="-1" aria-labelledby="receiveStockModalLabel{{ $del->id }}" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered text-start">
                <div class="modal-content rounded-4 border-0 shadow">
                    <div class="modal-header border-0 pb-0">
                        <h5 class="modal-title fw-bold" id="receiveStockModalLabel{{ $del->id }}">Receive Stock into Depot Tank</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <form action="{{ route('wetstock.deliveries.receive-stock', $del->id) }}" method="POST">
                        @csrf
                        <div class="modal-body pt-3">
                            <div class="p-3 bg-light rounded-3 mb-3">
                                <div class="d-flex justify-content-between small text-muted mb-1">
                                    <span>PO Delivery #{{ $del->id }}</span>
                                    <span>{{ $del->channel_label }}</span>
                                </div>
                                <div class="fw-bold fs-5 text-primary">{{ number_format($del->qty_to_receive) }} L of {{ $del->product }}</div>
                            </div>

                            <div class="mb-3">
                                <label class="form-label fw-semibold">Target Storage Tank <span class="text-danger">*</span></label>
                                <select name="storage_tank_id" class="form-select" required>
                                    <option value="">Select tank to fill...</option>
                                    @php
                                        $whTanks = $del->purchaseOrder && $del->purchaseOrder->warehouse 
                                            ? $del->purchaseOrder->warehouse->tanks->where('is_active', true)
                                            : $warehouses->pluck('tanks')->flatten()->where('is_active', true);
                                    @endphp
                                    @foreach ($whTanks as $tank)
                                        <option value="{{ $tank->id }}">
                                            {{ $tank->warehouse->name ?? '' }} - {{ $tank->name }} ({{ $tank->fuel_type }} | Remaining: {{ number_format($tank->remaining_capacity) }}L / Max: {{ number_format($tank->max_capacity) }}L)
                                        </option>
                                    @endforeach
                                </select>
                            </div>

                            <div class="mb-3">
                                <label class="form-label fw-semibold">Receiving Date <span class="text-danger">*</span></label>
                                <input type="date" name="date" class="form-control" value="{{ now()->format('Y-m-d') }}" required>
                            </div>
                        </div>
                        <div class="modal-footer border-0 pt-0">
                            <button type="button" class="btn btn-secondary-custom" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" class="btn btn-primary-custom px-4">Confirm Stock IN</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    @endif
@endforeach
@endsection
