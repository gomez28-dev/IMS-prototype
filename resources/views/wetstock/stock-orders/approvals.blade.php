@extends('layouts.app')

@section('title', 'VP Approval Queue - Stock Orders')

@section('content')
<div class="row justify-content-center">
    <div class="col-12">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-4">
            <div>
                <nav aria-label="breadcrumb">
                    <ol class="breadcrumb mb-1 small">
                        <li class="breadcrumb-item"><a href="{{ route('portal') }}" class="text-decoration-none text-secondary">Portal</a></li>
                        <li class="breadcrumb-item"><a href="{{ route('stock-orders.dashboard') }}" class="text-decoration-none text-secondary">Stock Orders</a></li>
                        <li class="breadcrumb-item active" aria-current="page">VP Approval Queue</li>
                    </ol>
                </nav>
                <h3 class="fw-bold text-dark mb-0">
                    <i class="bi bi-patch-check text-success me-2"></i>Executive Approval Queue (Vice President)
                </h3>
            </div>
            <div>
                <a href="{{ route('stock-orders.index') }}" class="btn btn-outline-secondary">
                    <i class="bi bi-arrow-left me-1"></i> Back to Orders
                </a>
            </div>
        </div>

        {{-- Two queues: Purchase Orders and ATLs, both awaiting VP approval --}}
        <div class="d-flex flex-row align-items-center gap-4 mb-4 border-bottom pb-2">
            <a class="fw-semibold text-decoration-none pb-1 {{ $tab === 'pos' ? 'text-dark border-bottom border-2 border-primary' : 'text-muted' }}"
               href="{{ route('stock-orders.approvals', ['tab' => 'pos']) }}">
                <i class="bi bi-card-checklist me-1"></i> PO Approvals
                @if ($counts['pos'] > 0)
                    <span class="badge rounded-pill bg-danger text-white ms-1">{{ $counts['pos'] }}</span>
                @endif
            </a>
            <a class="fw-semibold text-decoration-none pb-1 {{ $tab === 'atls' ? 'text-dark border-bottom border-2 border-primary' : 'text-muted' }}"
               href="{{ route('stock-orders.approvals', ['tab' => 'atls']) }}">
                <i class="bi bi-patch-check me-1"></i> ATL Approvals
                @if ($counts['atls'] > 0)
                    <span class="badge rounded-pill bg-danger text-white ms-1">{{ $counts['atls'] }}</span>
                @endif
            </a>
        </div>

        @if ($tab === 'atls')
        <div class="card card-custom border-0 shadow-sm">
            <div class="card-body p-4">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="bg-light">
                            <tr>
                                <th class="ps-3 py-3">ATL Number</th>
                                <th class="py-3">Sales Order</th>
                                <th class="py-3">PO #</th>
                                <th class="py-3">Pick Up Date</th>
                                <th class="py-3 text-end">Volume</th>
                                <th class="py-3">Prepared By</th>
                                <th class="py-3">Driver / Plate</th>
                                <th class="pe-3 py-3 text-end">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($pendingAtls as $atl)
                                <tr>
                                    <td class="ps-3 fw-bold text-dark">
                                        {{ $atl->atl_number ?: '—' }}
                                        <div class="small text-muted">Issued {{ $atl->issued_at?->format('M d, Y') }}</div>
                                    </td>
                                    <td>
                                        @if ($atl->order)
                                            <a href="{{ route('stock-orders.atls.show', $atl->order->id) }}" class="text-decoration-none">
                                                {{ $atl->order->formatted_so_number }}
                                            </a>
                                            <div class="small text-muted">{{ $atl->order->account }}</div>
                                        @else
                                            <span class="text-muted">{{ $atl->so_number ?: '—' }}</span>
                                        @endif
                                    </td>
                                    <td class="small">
                                        @php
                                            $atlPoNumbers = $atl->allocations->pluck('purchaseOrder')->filter()->pluck('po_number')->unique();
                                        @endphp
                                        {{ $atlPoNumbers->isNotEmpty() ? $atlPoNumbers->join(', ') : ($atl->purchaseOrder->po_number ?? '—') }}
                                    </td>
                                    <td class="small">{{ $atl->receiving_date ? $atl->receiving_date->format('M d, Y') : '—' }}</td>
                                    <td class="text-end fw-bold font-monospace">{{ number_format($atl->qty_to_receive) }} L</td>
                                    <td class="small">{{ $atl->preparer->name ?? 'Purchasing' }}</td>
                                    <td class="small">
                                        {{ $atl->driver_name ?: '—' }}
                                        @if ($atl->plate_number)
                                            <div class="text-muted">{{ $atl->plate_number }}</div>
                                        @endif
                                    </td>
                                    <td class="pe-3 text-end">
                                        <div class="d-inline-flex gap-1 justify-content-end">
                                            <form method="POST" action="{{ route('stock-orders.atl-reject', $atl->id) }}" class="d-inline"
                                                  onsubmit="return confirm('Reject this ATL and return it to Purchasing?');">
                                                @csrf
                                                <input type="hidden" name="rejection_reason" value="">
                                                <button type="submit" class="btn btn-sm btn-outline-danger rounded-pill px-3 py-1">
                                                    <i class="bi bi-x-lg me-1"></i> Reject
                                                </button>
                                            </form>
                                            <form method="POST" action="{{ route('stock-orders.atl-approve', $atl->id) }}" class="d-inline"
                                                  onsubmit="return confirm('Approve this ATL?');">
                                                @csrf
                                                <button type="submit" class="btn btn-sm btn-success rounded-pill px-3 py-1">
                                                    <i class="bi bi-check-lg me-1"></i> Approve
                                                </button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="8" class="text-center py-5 text-muted">
                                        <i class="bi bi-check2-circle fs-1 d-block mb-2 text-success"></i>
                                        No ATLs are waiting for approval.
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                @if ($pendingAtls->hasPages())
                    <div class="mt-3">
                        {{ $pendingAtls->links() }}
                    </div>
                @endif
            </div>
        </div>
        @else
        <div class="card card-custom border-0 shadow-sm">
            <div class="card-body p-4">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="bg-light">
                            <tr>
                                <th class="ps-3 py-3">PO Number</th>
                                <th class="py-3">Type</th>
                                <th class="py-3">Supplier / Source</th>
                                <th class="py-3">Site / Channel</th>
                                <th class="py-3 text-end">Volume</th>
                                <th class="py-3">Prepared By</th>
                                <th class="py-3">Delivery / ATL Breakdown</th>
                                <th class="pe-3 py-3 text-end">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($pendingOrders as $order)
                                <tr>
                                    <td class="ps-3 fw-bold text-dark">{{ $order->po_number }}</td>
                                    <td>
                                        @if ($order->isFuelTrade())
                                            <span class="badge bg-warning text-dark border">Fuel Trade</span>
                                        @elseif ($order->isBuyBack())
                                            <span class="badge bg-info text-dark border">Buy Back</span>
                                        @else
                                            <span class="badge bg-light text-dark border">Replenishment</span>
                                        @endif
                                    </td>
                                    <td>{{ $order->supplier_name ?: ($order->client->name ?? '—') }}</td>
                                    <td>
                                        @if ($order->warehouse)
                                            {{ $order->warehouse->name }}
                                        @elseif ($order->isFuelTrade())
                                            Direct to Client
                                        @endif
                                    </td>
                                    <td class="text-end fw-bold font-monospace">{{ number_format($order->qty_ordered) }} L</td>
                                    <td class="small">{{ $order->requester->name ?? 'Purchasing' }}</td>
                                    <td class="small">
                                        @foreach ($order->deliveries as $del)
                                            <div>
                                                <i class="bi bi-truck me-1"></i>{{ $del->product }}: {{ number_format($del->qty_to_receive) }}L
                                                ({{ $del->atl_number ?: ($del->client_atl_number ?: $del->dr_number) }})
                                            </div>
                                        @endforeach
                                    </td>
                                    <td class="pe-3 text-end">
                                        @if (Auth::user()->canApproveStockOrders())
                                            <div class="d-inline-flex gap-1 align-items-center justify-content-end flex-wrap">
                                                <form method="POST" action="{{ route('stock-orders.approve', $order->id) }}" class="d-inline" onsubmit="return confirm('Confirm approval for Stock Order #{{ $order->po_number }}?');">
                                                    @csrf
                                                    <button type="submit" class="btn btn-sm btn-success rounded-pill px-3 shadow-sm">
                                                        <i class="bi bi-check-lg me-1"></i> Approve PO
                                                    </button>
                                                </form>
                                                <form method="POST" action="{{ route('stock-orders.reject', $order->id) }}" class="d-inline" onsubmit="return confirm('Reject Stock Order #{{ $order->po_number }} and return it to purchasing for rework?');">
                                                    @csrf
                                                    <input type="hidden" name="rejection_reason" value="">
                                                    <button type="submit" class="btn btn-sm btn-outline-danger rounded-pill px-3 shadow-sm">
                                                        <i class="bi bi-x-lg me-1"></i> Reject
                                                    </button>
                                                </form>
                                            </div>
                                        @else
                                            <span class="badge bg-light text-muted border">Pending VP</span>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="8" class="text-center py-5 text-muted">
                                        <i class="bi bi-check2-circle fs-1 d-block mb-2 text-success"></i>
                                        All stock orders are approved! No pending requests.
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                <div class="mt-3">
                    {{ $pendingOrders->links() }}
                </div>
            </div>
        </div>
        @endif
    </div>
</div>
@endsection
