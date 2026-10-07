@extends('layouts.app')

@section('title', 'Sales Orders')

@section('content')
<div class="row justify-content-center">
    <div class="col-12">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-4">
            <div>
                <nav aria-label="breadcrumb">
                    <ol class="breadcrumb mb-1 small">
                        <li class="breadcrumb-item"><a href="{{ route('portal') }}" class="text-decoration-none text-secondary">Portal</a></li>
                        <li class="breadcrumb-item"><a href="{{ route('stock-orders.purchase-orders.index') }}" class="text-decoration-none text-secondary">Stock Orders</a></li>
                        <li class="breadcrumb-item active" aria-current="page">Sales Orders</li>
                    </ol>
                </nav>
                <h3 class="fw-bold text-dark mb-0">
                    <i class="bi bi-patch-check text-primary me-2"></i>Sales Orders Needing an ATL
                </h3>
                <p class="text-muted small mb-0 mt-1">Fuel Trade and Buy Back pick-up orders, whatever their clearance status.</p>
            </div>
            <div class="d-flex gap-2">
                <a href="{{ route('stock-orders.purchase-orders.index') }}" class="btn btn-outline-secondary">
                    <i class="bi bi-speedometer2 me-1"></i> Stock Orders Dashboard
                </a>
            </div>
        </div>

        {{-- Summary cards --}}
        <div class="row g-3 mb-4">
            <div class="col-md-3">
                <div class="card card-custom p-3 border-0 shadow-sm">
                    <span class="text-uppercase text-muted fw-bold small">Orders Listed</span>
                    <h3 class="fw-bold text-dark mb-0 mt-1">{{ number_format($stats['orders']) }}</h3>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card card-custom p-3 border-0 shadow-sm">
                    <span class="text-uppercase text-muted fw-bold small">Pending Clearance</span>
                    <h3 class="fw-bold text-warning mb-0 mt-1">{{ number_format($stats['pending_clearance']) }}</h3>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card card-custom p-3 border-0 shadow-sm">
                    <span class="text-uppercase text-muted fw-bold small">Unlifted ATLs</span>
                    <h3 class="fw-bold text-primary mb-0 mt-1">{{ number_format($stats['unlifted']) }}</h3>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card card-custom p-3 border-0 shadow-sm">
                    <span class="text-uppercase text-muted fw-bold small">Lifted ATLs</span>
                    <h3 class="fw-bold text-success mb-0 mt-1">{{ number_format($stats['lifted']) }}</h3>
                </div>
            </div>
        </div>

        {{-- Search --}}
        <div class="card card-custom border-0 shadow-sm mb-4">
            <div class="card-body p-3">
                <form method="GET" action="{{ route('stock-orders.sales-orders.index') }}" class="row g-2 align-items-center">
                    <div class="col-md-6">
                        <input type="text" name="search" class="form-control form-control-sm"
                               placeholder="Search by SO# or Account..." value="{{ $search }}">
                    </div>
                    <div class="col-md-6 d-flex gap-2">
                        <button type="submit" class="btn btn-sm btn-primary">Search</button>
                        <a href="{{ route('stock-orders.sales-orders.index') }}" class="btn btn-sm btn-outline-secondary">Reset</a>
                    </div>
                </form>
            </div>
        </div>

        {{-- Orders --}}
        <div class="card card-custom border-0 shadow-sm">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="bg-light">
                            <tr>
                                <th class="ps-3 py-3">SO Number</th>
                                <th class="py-3">Client / Account</th>
                                <th class="py-3 text-end">Qty Ordered</th>
                                <th class="py-3">Products</th>
                                <th class="py-3 text-center">Clearance</th>
                                <th class="py-3 text-center">ATL Status</th>
                                <th class="pe-3 py-3 text-end">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($orders as $row)
                                @php
                                    $rowAtls = $atlsByOrder[$row->id] ?? [];
                                    $allLifted = count($rowAtls) > 0
                                        && collect($rowAtls)->every(fn($a) => $a->lift_status === \App\Models\PurchaseOrderDelivery::LIFT_LIFTED);
                                @endphp
                                <tr>
                                    <td class="ps-3 fw-bold text-dark">
                                        {{ $row->formatted_so_number }}
                                        <div class="small text-muted">{{ $row->date ? $row->date->format('M d, Y') : '' }}</div>
                                    </td>
                                    <td><strong>{{ $row->account }}</strong></td>
                                    <td class="text-end fw-bold font-monospace">{{ number_format($row->qty_ordered) }} L</td>
                                    <td>
                                        @if ($row->items->isNotEmpty())
                                            @foreach ($row->items->slice(0, 3) as $item)
                                                <span class="badge bg-light text-dark border me-1">{{ $item->product_name }}</span>
                                            @endforeach
                                            @if ($row->items->count() > 3)
                                                <span class="extra-small text-muted">+{{ $row->items->count() - 3 }} more</span>
                                            @endif
                                        @else
                                            <span class="text-muted">—</span>
                                        @endif
                                    </td>
                                    <td class="text-center">
                                        @php
                                            $clearanceClass = match ($row->clearing_status) {
                                                'Approved' => 'bg-success-subtle text-success border border-success-subtle',
                                                'Declined' => 'bg-danger-subtle text-danger border border-danger-subtle',
                                                'Hold' => 'bg-warning-subtle text-warning-emphasis border border-warning-subtle',
                                                default => 'bg-secondary-subtle text-secondary border border-secondary-subtle',
                                            };
                                        @endphp
                                        <span class="badge rounded-pill border px-2 py-1 {{ $clearanceClass }}">
                                            {{ $row->clearing_status ?: 'Pending' }}
                                        </span>
                                    </td>
                                    <td class="text-center">
                                        @if ($rowAtls === [])
                                            <span class="badge bg-light text-muted border rounded-pill px-2 py-1">No ATL Yet</span>
                                        @elseif ($allLifted)
                                            <span class="badge bg-success-subtle text-success border border-success-subtle rounded-pill px-2 py-1">
                                                {{ count($rowAtls) }} Lifted
                                            </span>
                                        @else
                                            <span class="badge bg-primary-subtle text-primary border border-primary-subtle rounded-pill px-2 py-1">
                                                {{ count($rowAtls) }} Unlifted
                                            </span>
                                        @endif
                                    </td>
                                    <td class="pe-3 text-end">
                                        <div class="d-inline-flex gap-1 justify-content-end">
                                            @if ($row->canBeIssuedAtl() && Auth::user()->canEditStockOrders())
                                                <a href="{{ route('stock-orders.create-fuel-trade-po', $row->id) }}"
                                                   class="btn btn-sm btn-primary rounded-pill px-3 py-1">
                                                    <i class="bi bi-plus-circle me-1"></i> Create ATL
                                                </a>
                                            @endif
                                            <a href="{{ route('stock-orders.sales-orders.show', $row->id) }}"
                                               class="btn btn-sm btn-outline-secondary rounded-pill px-3 py-1">
                                                ATL Details
                                            </a>
                                        </div>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="7" class="text-center py-5 text-muted">
                                        <i class="bi bi-inbox fs-1 d-block mb-3 text-secondary"></i>
                                        No Fuel Trade or Buy Back pick-up orders found.
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                @if ($orders->hasPages())
                    <div class="p-3">
                        {{ $orders->links() }}
                    </div>
                @endif
            </div>
        </div>
    </div>
</div>
@endsection
