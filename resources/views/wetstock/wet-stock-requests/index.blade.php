@extends('layouts.app')

@section('title', 'Wet Stock Requests')

@section('content')
<div class="container-fluid py-2">
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center mb-4 gap-3">
        <div>
            <h2 class="fw-bold text-dark mb-1">Wet Stock Requests</h2>
            <p class="text-muted small mb-0">Incoming wet stock replenishment requests and the depot deliveries raised against them.</p>
        </div>
        <div class="d-flex gap-2 align-items-center flex-wrap">
            {{-- The controller has always honoured ?search= on this page; only
                 the field was missing. Styled like M1 so the two read as one set. --}}
            <form method="GET" action="{{ route('stock-orders.wet-stock-requests.index') }}"
                  class="d-flex gap-2 align-items-center">
                @if (request('warehouse_id'))
                    <input type="hidden" name="warehouse_id" value="{{ request('warehouse_id') }}">
                @endif
                <div class="input-group">
                    <span class="input-group-text bg-white border-end-0">
                        <i class="bi bi-search text-muted"></i>
                    </span>
                    <input type="text" name="search" class="form-control border-start-0"
                           placeholder="Search REQ#, PO# or remarks..." value="{{ request('search') }}">
                </div>
                <button type="submit" class="btn btn-primary-custom">Search</button>
                @if (request('search'))
                    <a href="{{ route('stock-orders.wet-stock-requests.index') }}"
                       class="btn btn-outline-secondary">Reset</a>
                @endif
            </form>

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

    {{-- Product-aware cards. Same card system as the M1 Deliveries page and the
         Module 3 lists, so all of them read as one set. The figures come from
         the request's own builder, so the cards always total exactly what the
         table below lists under the current filters. Legacy PurchaseOrder rows
         are not counted: they are being retired and their volume lives on the
         stock_requests lines. --}}
    @php
        $chipStyles = [
            'U' => 'background:#dcfce7;color:#166534;border:1px solid #bbf7d0;',
            'D' => 'background:#e0e7ff;color:#3730a3;border:1px solid #c7d2fe;',
            'P' => 'background:#fee2e2;color:#991b1b;border:1px solid #fecaca;',
        ];
        $chip = function ($code, $title = '') use ($chipStyles) {
            $style = $chipStyles[$code] ?? 'background:#f3f4f6;color:#6b7280;border:1px solid #e5e7eb;';

            return '<span class="prod-chip" style="'.$style.'" title="'.e($title).'">'.e($code ?: '—').'</span>';
        };
        $prodRows = [['D', 'Diesel'], ['U', 'Unleaded'], ['P', 'Premium']];

        $volumeCards = [
            ['title' => 'Total Requested', 'icon' => 'bi-boxes', 'tint' => 'background:#ecfeff;color:#0891b2;', 'data' => $volumes['requested']],
            ['title' => 'Total Received', 'icon' => 'bi-truck', 'tint' => 'background:#f0fdf4;color:#16a34a;', 'data' => $volumes['received']],
            ['title' => 'Remaining', 'icon' => 'bi-clock-history', 'tint' => 'background:#fffbeb;color:#d97706;', 'data' => $volumes['pending']],
        ];
    @endphp
    <style>
        .stat-title { font-size: 0.72rem; font-weight: 700; letter-spacing: 0.05em; text-transform: uppercase; color: #374151; }
        .stat-icon {
            width: 46px; height: 46px; border-radius: 0.8rem; display: flex;
            align-items: center; justify-content: center; font-size: 1.3rem; flex-shrink: 0;
        }
        .stat-row {
            display: flex; justify-content: space-between; align-items: center;
            border: 1px solid #e5e7eb; border-radius: 0.6rem;
            padding: 0.45rem 0.8rem; margin-bottom: 0.45rem; background: #fff;
        }
        .stat-row .stat-val { font-weight: 700; color: #111827; }
        .stat-total { display: flex; justify-content: space-between; padding: 0.25rem 0.3rem 0; color: #6b7280; }
        .stat-total .stat-val { font-weight: 700; color: #111827; }
        .prod-chip {
            display: inline-block; font-size: 0.65rem; font-weight: 700; line-height: 1;
            padding: 0.2rem 0.4rem; border-radius: 0.35rem; margin: 0 1px;
        }
    </style>

    <div class="row g-3 mb-4">
        @foreach ($volumeCards as $card)
            <div class="col-md-4">
                <div class="card card-custom p-3 border-0 h-100">
                    <div class="d-flex align-items-center gap-3 mb-3">
                        <div class="stat-icon" style="{{ $card['tint'] }}"><i class="bi {{ $card['icon'] }}"></i></div>
                        <span class="stat-title">{{ $card['title'] }}</span>
                    </div>
                    @foreach ($prodRows as [$code, $name])
                        <div class="stat-row">
                            <span>{!! $chip($code, $name) !!} <span class="ms-2">{{ $name }}</span></span>
                            <span class="stat-val">{{ number_format($card['data'][$name] ?? 0) }}</span>
                        </div>
                    @endforeach
                    <div class="stat-total">
                        <span>Total</span>
                        <span class="stat-val">{{ number_format(array_sum($card['data'])) }}</span>
                    </div>
                </div>
            </div>
        @endforeach
    </div>

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
                            <th>PO #</th>
                            <th>ATL / DR #</th>
                            <th>Site</th>
                            <th>Type</th>
                            <th class="text-end">Volume</th>
                            <th>Date Needed</th>
                            <th>Status</th>
                            <th>Requested By</th>
                            <th>Remarks</th>
                            <th class="pe-3 text-end">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        {{-- Requests raised in Module 2, which Purchasing prepares. --}}
                        @forelse ($stockRequests as $req)
                            <tr>
                                <td class="fw-bold text-dark">{{ $req->request_number }}</td>
                                <td class="small">Pending</td>
                                <td>{{ $req->warehouse->name ?? 'All Depots' }}</td>
                                <td><span class="badge bg-light text-dark border">{{ $req->type_label }}</span></td>
                                <td class="text-end fw-bold font-monospace">
                                    @foreach ($req->lines as $line)
                                        <div>{{ \Illuminate\Support\Str::substr((string) $line->product, 0, 1) }} &mdash; {{ number_format($line->qty_requested) }}</div>
                                    @endforeach
                                </td>
                                <td class="small">{{ $req->date_needed ? $req->date_needed->format('M d, Y') : '—' }}</td>
                                <td>
                                    <span class="badge bg-warning text-dark px-2 py-1">{{ $req->status }}</span>
                                </td>
                                <td class="small text-muted">{{ $req->requester->name ?? 'Depot' }}</td>
                                <td class="small text-muted">{{ $req->remarks ?: '—' }}</td>
                                <td class="pe-3 text-end">
                                    @if (Auth::user()->canEditStockOrders())
                                        {{-- Create ATL leads to the request's own ATL page, which
     lists what has been issued and adds more. The PO to draw
     on is chosen there, so a request does not need a PO of its
     own first. --}}
<a href="{{ route('stock-orders.wet-stock-requests.atls', $req->id) }}"
   class="btn btn-sm btn-primary rounded-pill px-3 py-1 d-block w-100" title="Issue an ATL against this request">
    <i class="bi bi-plus-lg me-1"></i> Create ATL
</a>

{{-- Prepare raises the PO from the request. The PO number
                                             is chosen there, so nothing is typed here. --}}
                                        <a href="{{ route('stock-orders.prepare-stock-request', ['stock_request' => $req->id]) }}"
                                           class="btn btn-sm btn-outline-primary rounded-pill px-3 py-1 d-block w-100 mt-1" title="Raise a purchase order against this request">
                                            <i class="bi bi-pencil-square me-1"></i> Prepare &amp; Submit
                                        </a>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="10" class="text-center text-muted py-4">No new replenishment requests.</td>
                            </tr>
                        @endforelse

                        {{-- Requests that predate the dedicated table and still live as
                             purchase_orders rows. --}}
                        @forelse ($legacyRequests as $req)
                            @php
                                // The ATL is raised as a delivery on this PO; show whichever
                                // identifier has been issued, else Pending.
                                $atlDelivery = $req->deliveries
                                    ->first(fn ($d) => $d->atl_number || $d->client_atl_number);
                            @endphp
                            <tr>
                                {{-- Real requests carry a REQ number of their own. These
                                     rows predate that, so they fall back to the PO number
                                     once issued, then to a placeholder. --}}
                                <td class="fw-bold text-dark">
                                    {{ $req->po_number ?: 'Pending PO#' }}
                                </td>
                                <td class="small">
                                    {{ $atlDelivery?->atl_number ?? $atlDelivery?->client_atl_number ?? 'Pending' }}
                                </td>
                                <td>{{ $req->warehouse->name ?? 'All Depots' }}</td>
                                <td><span class="badge bg-light text-dark border">{{ $req->po_type }}</span></td>
                                <td class="text-end fw-bold">{{ number_format($req->qty_ordered) }} L</td>
                                <td class="small">{{ $req->date_needed ? $req->date_needed->format('M d, Y') : '—' }}</td>
                                <td>
                                    <span class="badge bg-warning text-dark px-2 py-1">{{ $req->request_status }}</span>
                                </td>
                                <td class="small text-muted">{{ $req->requester->name ?? 'Depot' }}</td>
                                <td class="small text-muted">{{ $req->remarks ?: '—' }}</td>
                                <td class="pe-3 text-end">
                                    <a href="{{ route('stock-orders.wet-stock-requests.show', $req->id) }}"
                                       class="btn btn-sm btn-outline-primary rounded-pill px-3 py-1">
                                        <i class="bi bi-eye me-1"></i> View Request
                                    </a>
                                </td>
                            </tr>
                        @empty
                            {{-- Nothing legacy to show. --}}
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
