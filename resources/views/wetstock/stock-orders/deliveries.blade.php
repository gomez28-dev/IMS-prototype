@extends('layouts.app')

@section('title', 'Deliveries & ATL Tracking')

@section('content')
<div class="row justify-content-center">
    <div class="col-12">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-4">
            <div>
                <nav aria-label="breadcrumb">
                    <ol class="breadcrumb mb-1 small">
                        <li class="breadcrumb-item"><a href="{{ route('portal') }}" class="text-decoration-none text-secondary">Portal</a></li>
                        <li class="breadcrumb-item"><a href="{{ route('stock-orders.dashboard') }}" class="text-decoration-none text-secondary">Stock Orders</a></li>
                        <li class="breadcrumb-item active" aria-current="page">Deliveries & ATLs</li>
                    </ol>
                </nav>
                <h3 class="fw-bold text-dark mb-0">
                    <i class="bi bi-truck text-primary me-2"></i>Deliveries & Authority to Load (ATL) Records
                </h3>
            </div>
            <div>
                <a href="{{ route('stock-orders.index') }}" class="btn btn-outline-secondary">
                    <i class="bi bi-arrow-left me-1"></i> Back to Orders
                </a>
            </div>
        </div>

        <div class="card card-custom border-0 shadow-sm">
            <div class="card-body p-4">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="bg-light">
                            <tr>
                                <th class="ps-3 py-3">PO Number</th>
                                <th class="py-3">Channel / Type</th>
                                <th class="py-3">ATL / DR #</th>
                                <th class="py-3">Product</th>
                                <th class="py-3 text-end">Volume</th>
                                <th class="py-3">Driver / Plate</th>
                                <th class="py-3">Pickup Date</th>
                                <th class="py-3 text-center">Status</th>
                                <th class="pe-3 py-3 text-end">Action / PDF</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($deliveries as $del)
                                <tr>
                                    <td class="ps-3 fw-bold text-dark">
                                        {{ $del->purchaseOrder->po_number ?? '—' }}
                                        @if ($del->so_number)
                                            <div class="small text-muted">SO: {{ $del->so_number }}</div>
                                        @endif
                                    </td>
                                    <td>
                                        <div class="fw-semibold text-dark">{{ str_replace('_', ' ', $del->delivery_channel) }}</div>
                                        @if ($del->order_type === 'PICK_UP')
                                            <span class="badge bg-warning-subtle text-dark border" style="font-size: 0.65rem;">
                                                <i class="bi bi-box-arrow-up-right me-1"></i>Pick Up (Requires ATL)
                                            </span>
                                        @else
                                            <span class="badge bg-info-subtle text-dark border" style="font-size: 0.65rem;">
                                                <i class="bi bi-truck me-1"></i>Direct Delivery (Supplier DR)
                                            </span>
                                        @endif
                                    </td>
                                    <td>
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
                                    <td class="small">
                                        {{ $del->receiving_date ? $del->receiving_date->format('M d, Y') : '—' }}
                                    </td>
                                    <td class="text-center">
                                        @if ($del->status === 'Completed')
                                            <span class="badge bg-success-subtle text-success border rounded-pill px-3 py-1">Completed</span>
                                        @elseif ($del->status === 'Active')
                                            <span class="badge bg-primary rounded-pill px-3 py-1 text-white">For Delivery</span>
                                        @elseif ($del->status === 'Cancelled')
                                            <span class="badge bg-danger-subtle text-danger border rounded-pill px-3 py-1">Cancelled</span>
                                        @else
                                            <span class="badge bg-secondary-subtle text-secondary border rounded-pill px-3 py-1">Pending</span>
                                        @endif
                                    </td>
                                    <td class="pe-3 text-end">
                                        @if ($del->status === 'Pending' && $del->purchaseOrder && $del->purchaseOrder->request_status === 'CONFIRMED' && Auth::user()->canEditStockOrders())
                                            <form method="POST" action="{{ route('stock-orders.dispatch-delivery', $del->id) }}" class="d-inline">
                                                @csrf
                                                <button type="submit" class="btn btn-sm btn-primary rounded-pill px-3 py-1">
                                                    Dispatch
                                                </button>
                                            </form>
                                        @endif

                                        @if ($del->status === 'Active')
                                            @if ($del->requiresAtl() && $del->purchaseOrder && $del->purchaseOrder->request_status !== 'REQUESTED')
                                                <a href="{{ route('stock-orders.pdf', $del->id) }}" class="btn btn-sm btn-outline-danger rounded-pill px-3 py-1 shadow-sm me-1" title="Download signed Authority to Load PDF">
                                                    <i class="bi bi-file-earmark-pdf me-1"></i> Extract ATL PDF
                                                </a>
                                            @endif

                                            @if ($del->purchaseOrder && $del->purchaseOrder->isFuelTrade() && Auth::user()->canEditStockOrders())
                                                <form method="POST" action="{{ route('stock-orders.complete-fuel-trade', $del->id) }}" class="d-inline" onsubmit="return confirm('Confirm that client has picked up this fuel at the refinery? This will auto-fulfill the linked Sales Order.');">
                                                    @csrf
                                                    <button type="submit" class="btn btn-sm btn-success rounded-pill px-3 py-1 shadow-sm">
                                                        <i class="bi bi-check-circle me-1"></i> Mark Picked Up
                                                    </button>
                                                </form>
                                            @elseif (!$del->bypassesDepotTanks() && Auth::user()->canReceiveStockIntoDepot())
                                                <button type="button" class="btn btn-sm btn-primary rounded-pill px-3 py-1 shadow-sm" data-bs-toggle="modal" data-bs-target="#receiveStockModalDel{{ $del->id }}">
                                                    <i class="bi bi-box-arrow-in-down me-1"></i> Receive into Tank
                                                </button>
                                            @endif
                                        @elseif ($del->status === 'Completed')
                                            <span class="badge bg-success-subtle text-success border rounded-pill px-2 py-1"><i class="bi bi-check-circle me-1"></i>Received / Done</span>
                                            @if ($del->requiresAtl())
                                                <a href="{{ route('stock-orders.pdf', $del->id) }}" class="btn btn-sm btn-outline-secondary rounded-pill px-2 py-1 ms-1" title="View ATL PDF">
                                                    <i class="bi bi-file-earmark-pdf"></i>
                                                </a>
                                            @endif
                                        @endif

                                        @if ($del->status === 'Pending' && !($del->purchaseOrder && $del->purchaseOrder->request_status === 'CONFIRMED' && Auth::user()->canEditStockOrders()))
                                            <span class="text-muted" title="Awaiting ATL issuance">—</span>
                                            <span class="badge bg-light text-muted border rounded-pill px-2 py-1 ms-1">Pending Issuance</span>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="9" class="text-center py-5 text-muted">No delivery records logged yet.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                <div class="mt-3">
                    {{ $deliveries->links() }}
                </div>
            </div>
        </div>
    </div>
</div>

{{-- Modals container outside table (prevents layout shift, bouncing and flickering) --}}
@foreach ($deliveries as $del)
    @if (!$del->bypassesDepotTanks() && $del->status === 'Active' && Auth::user()->canReceiveStockIntoDepot())
        <div class="modal fade" id="receiveStockModalDel{{ $del->id }}" tabindex="-1" aria-labelledby="receiveStockModalDelLabel{{ $del->id }}" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered text-start">
                <div class="modal-content rounded-4 border-0 shadow">
                    <div class="modal-header border-0 pb-0">
                        <h5 class="modal-title fw-bold" id="receiveStockModalDelLabel{{ $del->id }}">Receive Fuel into Storage Tank</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <form action="{{ route('wetstock.deliveries.receive-stock', $del->id) }}" method="POST">
                        @csrf
                        <div class="modal-body pt-3">
                            <div class="p-3 bg-light rounded-3 mb-3">
                                <div class="d-flex justify-content-between small text-muted mb-1">
                                    <span>PO #{{ $del->purchaseOrder->po_number ?? $del->purchase_order_id }}</span>
                                    <span>{{ str_replace('_', ' ', $del->delivery_channel) }}</span>
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
                                            : ($warehouses ?? collect())->pluck('tanks')->flatten()->where('is_active', true);
                                    @endphp
                                    @foreach ($whTanks as $tank)
                                        <option value="{{ $tank->id }}">
                                            {{ $tank->warehouse->name ?? '' }} - {{ $tank->name }} ({{ $tank->fuel_type }} | Rem: {{ number_format($tank->remaining_capacity) }}L / Max: {{ number_format($tank->max_capacity) }}L)
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
