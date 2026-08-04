@extends('layouts.app')

@section('title', 'Incoming Supplier Stock')

@section('content')
<div class="d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center mb-4 gap-3">
    <div>
        <h2 class="fw-bold text-dark mb-1">Incoming Supplier Stock</h2>
        <p class="text-muted small mb-0">Track purchase orders from fuel suppliers (Unlifted Pickups & Pending Depot Deliveries).</p>
    </div>
    @if (!Auth::user()->isViewer() && !Auth::user()->isAccounting())
    <div>
        <a href="{{ route('wetstock.supplier-orders.create') }}" class="btn btn-primary-custom shadow-sm d-flex align-items-center">
            <i class="bi bi-plus-circle me-2"></i> Add Incoming Supplier Stock
        </a>
    </div>
    @endif
</div>

<!-- Filters -->
<div class="card card-custom mb-4 border-0 shadow-sm">
    <div class="card-body p-3">
        <form method="GET" action="{{ route('wetstock.supplier-orders.index') }}" class="row g-2 align-items-center">
            <div class="col-md-4">
                <select name="status" class="form-select form-control" onchange="this.form.submit()">
                    <option value="">All Statuses</option>
                    <option value="UNLIFTED_PICKUP" {{ $currentStatus === 'UNLIFTED_PICKUP' ? 'selected' : '' }}>Unlifted Stock Pick Up</option>
                    <option value="PENDING_DELIVERY" {{ $currentStatus === 'PENDING_DELIVERY' ? 'selected' : '' }}>Pending Stock Delivery</option>
                    <option value="COMPLETED" {{ $currentStatus === 'COMPLETED' ? 'selected' : '' }}>Completed / Received</option>
                </select>
            </div>
            <div class="col-md-4">
                <select name="warehouse_id" class="form-select form-control" onchange="this.form.submit()">
                    <option value="">All Locations / Warehouses</option>
                    @foreach ($warehouses as $wh)
                        <option value="{{ $wh->id }}" {{ $currentWarehouse == $wh->id ? 'selected' : '' }}>{{ $wh->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-4 d-flex gap-2">
                <button type="submit" class="btn btn-secondary-custom w-100"><i class="bi bi-filter me-1"></i> Filter</button>
                @if ($currentStatus || $currentWarehouse)
                    <a href="{{ route('wetstock.supplier-orders.index') }}" class="btn btn-outline-secondary"><i class="bi bi-x-circle me-1"></i> Clear</a>
                @endif
            </div>
        </form>
    </div>
</div>

<!-- Supplier Orders Table -->
<div class="card card-custom border-0 shadow-sm">
    <div class="card-body p-4">
        <div class="table-responsive">
            <table class="table table-custom mb-0">
                <thead>
                    <tr>
                        <th>PO #</th>
                        <th>Location</th>
                        <th>Supplier</th>
                        <th class="text-end">Volume</th>
                        <th>Status</th>
                        <th>Remarks</th>
                        <th>Added By</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($supplierOrders as $po)
                        <tr>
                            <td class="fw-bold text-dark">{{ $po->po_number }}</td>
                            <td>
                                <span class="badge bg-light text-dark border">{{ $po->warehouse->name ?? 'N/A' }}</span>
                            </td>
                            <td class="fw-semibold">{{ $po->supplier_name }}</td>
                            <td class="text-end fw-bold">{{ number_format($po->liters) }} L</td>
                            <td>
                                @if ($po->status === 'UNLIFTED_PICKUP')
                                    <span class="badge bg-warning text-dark px-2 py-1"><i class="bi bi-box-arrow-in-down me-1"></i>Unlifted Pick Up</span>
                                @elseif ($po->status === 'PENDING_DELIVERY')
                                    <span class="badge bg-info text-dark px-2 py-1"><i class="bi bi-truck me-1"></i>Pending Delivery</span>
                                @else
                                    <span class="badge bg-success px-2 py-1"><i class="bi bi-check-circle me-1"></i>Completed</span>
                                @endif
                            </td>
                            <td class="small text-muted">{{ $po->remarks ?: '—' }}</td>
                            <td class="small text-muted">{{ $po->creator->name ?? 'System' }}</td>
                            <td class="text-end">
                                @if (!Auth::user()->isViewer() && !Auth::user()->isAccounting())
                                    @if ($po->status !== 'COMPLETED')
                                        <form action="{{ route('wetstock.supplier-orders.complete', $po->id) }}" method="POST" class="d-inline" onsubmit="return confirm('Mark PO #{{ $po->po_number }} as COMPLETED / Received?')">
                                            @csrf
                                            <button type="submit" class="btn btn-sm btn-outline-success me-1" title="Mark Completed">
                                                <i class="bi bi-check-lg me-1"></i> Complete
                                            </button>
                                        </form>
                                    @endif
                                    <a href="{{ route('wetstock.supplier-orders.edit', $po->id) }}" class="btn btn-sm btn-outline-primary" title="Edit PO">
                                        <i class="bi bi-pencil"></i>
                                    </a>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="text-center text-muted py-4">No incoming supplier stock orders found.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="mt-3">
            {{ $supplierOrders->withQueryString()->links() }}
        </div>
    </div>
</div>
@endsection
