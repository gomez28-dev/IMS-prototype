@extends('layouts.app')

@section('title', 'Depot Replenishment Requests')

@section('content')
<div class="container-fluid py-2">
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center mb-4 gap-3">
        <div>
            <h2 class="fw-bold text-dark mb-1">Depot Replenishment Requests</h2>
            <p class="text-muted small mb-0">Track fuel requests submitted to Purchasing (Module 3) and incoming depot-bound deliveries.</p>
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
            <h5 class="fw-bold text-dark mb-0"><i class="bi bi-truck text-primary me-2"></i>Incoming Depot Deliveries (Awaiting Tank Fill)</h5>
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
                            <th>Request #</th>
                            <th>Depot</th>
                            <th>Type</th>
                            <th class="text-end">Volume</th>
                            <th>Date Needed</th>
                            <th>Status</th>
                            <th>Requested By</th>
                            <th>Remarks</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($stockRequests as $req)
                            <tr>
                                <td class="fw-bold text-dark">#{{ $req->id }}</td>
                                <td>{{ $req->warehouse->name ?? 'All Depots' }}</td>
                                <td><span class="badge bg-light text-dark border">{{ $req->po_type }}</span></td>
                                <td class="text-end fw-bold">{{ number_format($req->qty_ordered) }} L</td>
                                <td class="small">{{ $req->date_needed ? $req->date_needed->format('M d, Y') : 'â€”' }}</td>
                                <td>
                                    <span class="badge bg-warning text-dark px-2 py-1">{{ $req->request_status }}</span>
                                </td>
                                <td class="small text-muted">{{ $req->requester->name ?? 'Depot' }}</td>
                                <td class="small text-muted">{{ $req->remarks ?: 'â€”' }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="8" class="text-center text-muted py-4">No replenishment requests found.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="p-3">
                {{ $stockRequests->links() }}
            </div>
        </div>
    </div>
</div>

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
