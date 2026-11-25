@extends('layouts.app')

@section('title', 'ATL Aging')

@section('content')
<div class="row justify-content-center">
    <div class="col-12">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-4">
            <div>
                <h3 class="fw-bold text-dark mb-0">
                    <i class="bi bi-hourglass-split text-warning me-2"></i>ATL Aging
                </h3>
                <p class="text-muted small mb-0 mt-1">
                    Approved ATLs whose pick-up date has passed and which were never lifted.
                    The volume they drew is still held against the order.
                </p>
            </div>
            <a href="{{ route('stock-orders.purchase-orders.index') }}" class="btn btn-outline-secondary">
                <i class="bi bi-arrow-left me-1"></i> Back to Purchase Orders
            </a>
        </div>

        <div class="row g-3 mb-4">
            <div class="col-md-4">
                <div class="card card-custom p-3 border-0 shadow-sm">
                    <span class="text-uppercase text-muted fw-bold small">Stuck ATLs</span>
                    <h3 class="fw-bold text-dark mb-0 mt-1">{{ number_format($summary['count']) }}</h3>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card card-custom p-3 border-0 shadow-sm">
                    <span class="text-uppercase text-muted fw-bold small">Stuck Volume</span>
                    <h3 class="fw-bold text-warning-emphasis mb-0 mt-1">{{ number_format($summary['volume']) }} L</h3>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card card-custom p-3 border-0 shadow-sm">
                    <span class="text-uppercase text-muted fw-bold small">Oldest Pick-up</span>
                    <h3 class="fw-bold text-danger mb-0 mt-1">
                        {{ $summary['count'] ? $summary['oldest'].' days ago' : '—' }}
                    </h3>
                </div>
            </div>
        </div>

        <div class="card card-custom border-0 shadow-sm">
            <div class="card-body border-bottom py-3">
                <form method="GET" action="{{ route('stock-orders.atl-aging') }}" class="row g-2 align-items-end">
                    <div class="col-md-4">
                        <label for="client" class="form-label small fw-semibold mb-1">Client</label>
                        <select name="client" id="client" class="form-select form-select-sm">
                            <option value="">All clients</option>
                            @foreach ($clients as $client)
                                <option value="{{ $client }}" @selected($filters['client'] === $client)>{{ $client }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label for="po" class="form-label small fw-semibold mb-1">PO Number</label>
                        <select name="po" id="po" class="form-select form-select-sm">
                            <option value="">All POs</option>
                            @foreach ($purchaseOrders as $purchaseOrder)
                                <option value="{{ $purchaseOrder->id }}" @selected($filters['po'] === $purchaseOrder->id)>{{ $purchaseOrder->po_number }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label for="from" class="form-label small fw-semibold mb-1">Pick Up From</label>
                        <input type="date" name="from" id="from" class="form-control form-control-sm" value="{{ $filters['from'] }}">
                    </div>
                    <div class="col-md-2">
                        <label for="to" class="form-label small fw-semibold mb-1">Pick Up To</label>
                        <input type="date" name="to" id="to" class="form-control form-control-sm" value="{{ $filters['to'] }}">
                    </div>
                    <div class="col-md-auto d-flex gap-2">
                        <button type="submit" class="btn btn-sm btn-primary">Apply</button>
                        <a href="{{ route('stock-orders.atl-aging') }}" class="btn btn-sm btn-outline-secondary">Reset</a>
                    </div>
                </form>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive d-none d-md-block">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="bg-light">
                            <tr>
                                <th class="ps-3 py-3">ATL #</th>
                                <th class="py-3">Client</th>
                                <th class="py-3">PO #</th>
                                <th class="py-3">Pick Up Date</th>
                                <th class="py-3">Overdue</th>
                                <th class="py-3">Product / Qty</th>
                                <th class="py-3">Driver / Plate</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($atls as $atl)
                                @php
                                    $days = (int) $atl->receiving_date->startOfDay()->diffInDays(today()->startOfDay());
                                    $client = $atl->order->account ?? ($atl->stockRequest->warehouse->name ?? null);
                                    $poNumbers = $atl->allocations->pluck('purchaseOrder')->filter()
                                        ->pluck('po_number')->unique()->join(', ');
                                    $poNumbers = $poNumbers !== '' ? $poNumbers : ($atl->purchaseOrder->po_number ?? '—');
                                    $breakdown = $atl->productBreakdown() ?: [$atl->product => (int) $atl->qty_to_receive];
                                    $label = $atl->atl_number ?: ($atl->client_atl_number ?: '#'.$atl->id);
                                @endphp
                                <tr>
                                    <td class="ps-3 fw-bold text-dark">
                                        @if ($atl->order)
                                            <a href="{{ route('stock-orders.sales-orders.show', $atl->order->id) }}"
                                               class="text-decoration-none">{{ $label }}</a>
                                        @else
                                            {{ $label }}
                                        @endif
                                    </td>
                                    <td class="small">{{ $client ?: '—' }}</td>
                                    <td class="small">{{ $poNumbers }}</td>
                                    <td class="small">{{ $atl->receiving_date->format('M d, Y') }}</td>
                                    <td>
                                        <span class="badge rounded-pill px-2 py-1 {{ $days > 7
                                            ? 'bg-danger-subtle text-danger border border-danger-subtle'
                                            : 'bg-warning-subtle text-warning-emphasis border border-warning-subtle' }}">
                                            {{ $days }} day{{ $days === 1 ? '' : 's' }}
                                        </span>
                                    </td>
                                    <td class="small">
                                        @foreach ($breakdown as $product => $qty)
                                            <div>{{ $product ?: 'Unspecified' }} &mdash; {{ number_format((int) $qty) }} L</div>
                                        @endforeach
                                    </td>
                                    <td class="small">
                                        {{ $atl->driver_name ?: '—' }}
                                        @if ($atl->plate_number)
                                            <div class="text-muted">{{ $atl->plate_number }}</div>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="7" class="text-center py-5 text-muted">
                                        <i class="bi bi-check2-circle fs-1 d-block mb-3 text-success"></i>
                                        No approved ATL is past its pick-up date and unlifted.
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                {{-- Phones: one card per stuck ATL. --}}
                <div class="rec-cards">
                    @foreach ($atls as $atl)
                        @php
                            $days = (int) $atl->receiving_date->startOfDay()->diffInDays(today()->startOfDay());
                            $client = $atl->order->account ?? ($atl->stockRequest->warehouse->name ?? null);
                            $poNumbers = $atl->allocations->pluck('purchaseOrder')->filter()
                                ->pluck('po_number')->unique()->join(', ');
                            $poNumbers = $poNumbers !== '' ? $poNumbers : ($atl->purchaseOrder->po_number ?? '—');
                            $breakdown = $atl->productBreakdown() ?: [$atl->product => (int) $atl->qty_to_receive];
                            $label = $atl->atl_number ?: ($atl->client_atl_number ?: '#'.$atl->id);
                        @endphp
                        <div class="rec-card">
                            <div class="rec-card-head">
                                <div class="rec-card-title">
                                    @if ($atl->order)
                                        <a href="{{ route('stock-orders.sales-orders.show', $atl->order->id) }}" class="text-decoration-none">{{ $label }}</a>
                                    @else
                                        {{ $label }}
                                    @endif
                                </div>
                                <span class="badge rounded-pill px-2 py-1 {{ $days > 7
                                    ? 'bg-danger-subtle text-danger border border-danger-subtle'
                                    : 'bg-warning-subtle text-warning-emphasis border border-warning-subtle' }}">
                                    {{ $days }} day{{ $days === 1 ? '' : 's' }}
                                </span>
                            </div>

                            <div class="rec-meta">
                                <div class="rec-span"><span class="rec-k">Client:</span> <span class="rec-v">{{ $client ?: '—' }}</span></div>
                                <div><span class="rec-k">PO#:</span> <span class="rec-v">{{ $poNumbers }}</span></div>
                                <div class="rec-r"><span class="rec-k">Pick Up:</span> <span class="rec-v">{{ $atl->receiving_date->format('M d, Y') }}</span></div>
                                <div class="rec-span"><span class="rec-k">Driver:</span> <span class="rec-v">{{ $atl->driver_name ?: '—' }}</span>{{ $atl->plate_number ? ' · ' . $atl->plate_number : '' }}</div>
                            </div>

                            <div class="rec-block">
                                @foreach ($breakdown as $product => $qty)
                                    <div class="rec-line">
                                        <span>{{ $product ?: 'Unspecified' }}</span>
                                        <span class="fw-bold text-nowrap">{{ number_format((int) $qty) }} L</span>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @endforeach
                </div>

                @if ($atls->hasPages())
                    <div class="mt-3 px-3 pb-3">
                        {{ $atls->links() }}
                    </div>
                @endif
            </div>
        </div>
    </div>
</div>
@endsection
