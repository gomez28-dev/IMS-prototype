@extends('layouts.app')

@section('title', 'Stock Orders & ATL Dashboard')

@section('content')
<div class="row justify-content-center">
    <div class="col-12">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-4">
            <div>
                <nav aria-label="breadcrumb">
                    <ol class="breadcrumb mb-1 small">
                        <li class="breadcrumb-item"><a href="{{ route('portal') }}" class="text-decoration-none text-secondary">Portal</a></li>
                        <li class="breadcrumb-item active" aria-current="page">Stock Orders</li>
                    </ol>
                </nav>
                <h3 class="fw-bold text-dark mb-0">
                    <i class="bi bi-cart-check text-primary me-2"></i>Stock Orders & ATL Issuance
                </h3>
            </div>
            <div class="d-flex gap-2">
                <a href="{{ route('stock-orders.index') }}" class="btn btn-outline-secondary">
                    <i class="bi bi-list-ul me-1"></i> All Purchase Orders
                </a>
                <a href="{{ route('stock-orders.approvals') }}" class="btn btn-outline-success position-relative">
                    <i class="bi bi-patch-check me-1"></i> Approvals
                    @if ($stats['pending_approval'] > 0)
                        <span class="badge bg-danger rounded-pill ms-1">{{ $stats['pending_approval'] }}</span>
                    @endif
                </a>
                <a href="{{ route('stock-orders.deliveries') }}" class="btn btn-outline-primary position-relative">
                    <i class="bi bi-truck me-1"></i> Deliveries & ATLs
                    @if ($stats['for_delivery'] > 0)
                        <span class="badge bg-info text-dark rounded-pill ms-1">{{ $stats['for_delivery'] }}</span>
                    @endif
                </a>
            </div>
        </div>

        {{-- Pending Fuel Trade Orders Alert Queue --}}
        @if ($pendingFuelTradeOrders->isNotEmpty())
            <div class="card border-warning shadow-sm mb-4" style="background-color: #fffdf5;">
                <div class="card-header bg-warning-subtle border-warning d-flex justify-content-between align-items-center py-2">
                    <span class="fw-bold text-dark">
                        <i class="bi bi-lightning-charge-fill text-warning me-1"></i> Fuel Trade Orders Queue (Direct Pick-up at Supplier)
                    </span>
                    <span class="badge bg-warning text-dark">{{ $stats['fuel_trade_queue'] }} Pending Supplier PO</span>
                </div>
                <div class="card-body p-3">
                    <div class="table-responsive">
                        <table class="table table-sm table-hover align-middle mb-0">
                            <thead>
                                <tr class="text-muted small">
                                    <th>SO Number</th>
                                    <th>Client / Account</th>
                                    <th>Volume</th>
                                    <th>Date</th>
                                    <th>Client ATL #</th>
                                    <th>Accounting Clearance</th>
                                    <th class="text-end">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($pendingFuelTradeOrders as $ftOrder)
                                    <tr>
                                        <td class="fw-bold text-primary">{{ $ftOrder->so_number }}</td>
                                        <td><strong>{{ $ftOrder->account }}</strong></td>
                                        <td class="font-monospace fw-bold">{{ number_format($ftOrder->qty_ordered) }} L</td>
                                        <td>{{ $ftOrder->date ? $ftOrder->date->format('M d, Y') : '—' }}</td>
                                        <td>
                                            @if ($ftOrder->client_atl_number)
                                                <span class="badge bg-light text-dark border">{{ $ftOrder->client_atl_number }}</span>
                                            @else
                                                <span class="text-muted small">DITC will issue</span>
                                            @endif
                                        </td>
                                        @php
                                            $ftBadgeClass = match($ftOrder->clearing_status) {
                                                'Approved' => 'bg-success-subtle text-success border-success-subtle',
                                                'Declined' => 'bg-danger-subtle text-danger border-danger-subtle',
                                                'Hold' => 'bg-warning-subtle text-warning-emphasis border-warning-subtle',
                                                default => 'bg-secondary-subtle text-secondary border-secondary-subtle',
                                            };
                                        @endphp
                                        <td>
                                            <span class="badge rounded-pill border px-2 py-1 {{ $ftBadgeClass }}">
                                                {{ $ftOrder->clearing_status ?: 'Pending' }}
                                            </span>
                                        </td>
                                        <td class="text-end">
                                            @if ($ftOrder->canBeIssuedAtl())
                                                <a href="{{ route('stock-orders.create-fuel-trade-po', $ftOrder->id) }}" class="btn btn-sm btn-primary rounded-pill px-3 shadow-sm">
                                                    <i class="bi bi-plus-circle me-1"></i> Prepare Supplier PO & ATL
                                                </a>
                                            @else
                                                <button type="button" class="btn btn-sm btn-secondary rounded-pill px-3 shadow-sm opacity-75" disabled title="Awaiting Accounting clearance ({{ $ftOrder->clearing_status }})">
                                                    <i class="bi bi-lock-fill me-1"></i> Locked: Pending Clearance
                                                </button>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        @endif

        {{-- 4 Metric Cards --}}
        <div class="row g-3 mb-4">
            <div class="col-md-3">
                <div class="card card-custom p-3 border-0 shadow-sm">
                    <span class="text-uppercase text-muted fw-bold small">Total Stock Orders</span>
                    <h3 class="fw-bold text-dark mb-0 mt-1">{{ number_format($stats['total_pos']) }}</h3>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card card-custom p-3 border-0 shadow-sm">
                    <span class="text-uppercase text-muted fw-bold small">Pending VP Approval</span>
                    <h3 class="fw-bold text-warning mb-0 mt-1">{{ number_format($stats['pending_approval']) }}</h3>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card card-custom p-3 border-0 shadow-sm">
                    <span class="text-uppercase text-muted fw-bold small">Active / For Delivery</span>
                    <h3 class="fw-bold text-primary mb-0 mt-1">{{ number_format($stats['for_delivery']) }}</h3>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card card-custom p-3 border-0 shadow-sm">
                    <span class="text-uppercase text-muted fw-bold small">Completed POs</span>
                    <h3 class="fw-bold text-success mb-0 mt-1">{{ number_format($stats['completed']) }}</h3>
                </div>
            </div>
        </div>

        {{-- Recent Orders Table --}}
        <div class="card card-custom border-0 shadow-sm">
            <div class="card-header bg-white py-3">
                <h5 class="fw-bold text-dark mb-0">Recent Purchase Orders & Replenishment Pipeline</h5>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="bg-light">
                            <tr>
                                <th class="ps-3 py-3">PO #</th>
                                <th class="py-3">Type</th>
                                <th class="py-3">Supplier / Source</th>
                                <th class="py-3">Site / Channel</th>
                                <th class="py-3 text-end">Volume</th>
                                <th class="py-3 text-center">Pipeline Status</th>
                                <th class="pe-3 py-3 text-end">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($recentOrders as $order)
                                <tr>
                                    <td class="ps-3 fw-bold text-dark">{{ $order->po_number ?: 'Pending PO#' }}</td>
                                    <td>
                                        @if ($order->isFuelTrade())
                                            <span class="badge bg-warning text-dark border">Fuel Trade</span>
                                        @elseif ($order->isBuyBack())
                                            <span class="badge bg-info text-dark border">Buy Back</span>
                                        @else
                                            <span class="badge bg-light text-dark border">Standard Replenishment</span>
                                        @endif
                                    </td>
                                    <td>{{ $order->supplier_name ?: '—' }}</td>
                                    <td>
                                        @if ($order->warehouse)
                                            <i class="bi bi-geo-alt text-primary me-1"></i>{{ $order->warehouse->name }}
                                        @elseif ($order->isFuelTrade())
                                            <span class="text-muted small">Direct to Client</span>
                                        @else
                                            —
                                        @endif
                                    </td>
                                    <td class="text-end fw-bold font-monospace">{{ number_format($order->qty_ordered) }} L</td>
                                    <td class="text-center">
                                        <span class="badge bg-light text-dark border rounded-pill px-3 py-1">{{ $order->request_status }}</span>
                                    </td>
                                    <td class="pe-3 text-end">
                                        @if ($order->request_status === 'REQUESTED' && Auth::user()->canEditStockOrders())
                                            <a href="{{ route('stock-orders.edit-request', $order->id) }}" class="btn btn-sm btn-primary rounded-pill px-3 py-1">
                                                Process / ATL
                                            </a>
                                        @elseif ($order->request_status === 'RECEIVED')
                                            @if (Auth::user()->canApproveStockOrders())
                                                <a href="{{ route('stock-orders.approvals') }}" class="btn btn-sm btn-success rounded-pill px-3 py-1" title="Review in Approvals Queue">
                                                    Review for Approval
                                                </a>
                                            @else
                                                <span class="badge bg-warning-subtle text-warning border rounded-pill px-3 py-1">Pending VP</span>
                                            @endif
                                        @elseif ($order->request_status === 'FOR_DELIVERY')
                                            @php
                                                $atlDel = $order->deliveries->first(fn($d) => $d->requiresAtl());
                                                $activeDel = $order->deliveries->firstWhere('status', 'Active');
                                            @endphp
                                            <div class="d-inline-flex gap-1 align-items-center">
                                                @if ($order->isFuelTrade() && $activeDel && Auth::user()->canEditStockOrders())
                                                    <form method="POST" action="{{ route('stock-orders.complete-fuel-trade', $activeDel->id) }}" class="d-inline" onsubmit="return confirm('Confirm that client has picked up this fuel?');">
                                                        @csrf
                                                        <button type="submit" class="btn btn-sm btn-success rounded-pill px-2 py-1 shadow-sm">
                                                            <i class="bi bi-check-circle me-1"></i> Lifted
                                                        </button>
                                                    </form>
                                                @endif
                                                @if ($atlDel)
                                                    <a href="{{ route('stock-orders.pdf', $atlDel->id) }}" class="btn btn-sm btn-outline-danger rounded-pill px-2 py-1 shadow-sm" title="Extract ATL PDF">
                                                        <i class="bi bi-file-earmark-pdf me-1"></i> ATL PDF
                                                    </a>
                                                @endif
                                                <a href="{{ route('stock-orders.deliveries') }}" class="btn btn-sm btn-light border rounded-pill px-2 py-1 text-secondary" title="View Deliveries">
                                                    <i class="bi bi-truck me-1"></i> Track
                                                </a>
                                            </div>
                                        @elseif ($order->request_status === 'COMPLETED')
                                            <a href="{{ route('stock-orders.show', $order->id) }}" class="btn btn-sm btn-light border rounded-pill px-3 py-1 text-muted">
                                                <i class="bi bi-eye me-1"></i> View
                                            </a>
                                        @else
                                            <a href="{{ route('stock-orders.show', $order->id) }}" class="btn btn-sm btn-light border rounded-pill px-2 py-1 text-muted">
                                                <i class="bi bi-arrow-right-circle me-1"></i> Details
                                            </a>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="7" class="text-center py-4 text-muted">No stock orders logged yet.</td>
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
