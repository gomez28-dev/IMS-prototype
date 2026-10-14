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

    <!-- Replenishment Requests Table -->
    <div class="card card-custom border-0 shadow-sm">
        <div class="card-header bg-white border-0 py-3">
            {{-- The search sits on the section it filters rather than in the page
                 header, so it is obvious what it narrows. The controller has always
                 honoured ?search= here; only its placement is new. --}}
            <div class="d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center gap-2">
                <h5 class="fw-bold text-dark mb-0"><i class="bi bi-file-earmark-text text-secondary me-2"></i>Submitted Replenishment Requests</h5>
                <form method="GET" action="{{ route('stock-orders.wet-stock-requests.index') }}"
                      class="d-flex gap-2 align-items-center flex-wrap">
                    @if (request('warehouse_id'))
                        <input type="hidden" name="warehouse_id" value="{{ request('warehouse_id') }}">
                    @endif
                    <div class="input-group">
                        <span class="input-group-text bg-white border-end-0">
                            <i class="bi bi-search text-muted"></i>
                        </span>
                        <input type="text" name="search" class="form-control border-start-0"
                               placeholder="Search REQ #, site, or remarks..." value="{{ request('search') }}">
                    </div>
                    <button type="submit" class="btn btn-primary-custom">Search</button>
                    {{-- Always present rather than only after a search, so the pair
                         reads as one control the way M1's does. --}}
                    <a href="{{ route('stock-orders.wet-stock-requests.index') }}"
                       class="btn btn-outline-secondary">Clear</a>
                </form>
            </div>
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
                                        {{-- The only action left on a request row. It leads to
                                             the request's own ATL page, which lists what has been
                                             issued and adds more, and which chooses the PO to
                                             draw on. A request does not need a PO of its own
                                             first, so there is no separate Prepare step here. --}}
                                        <a href="{{ route('stock-orders.wet-stock-requests.atls', $req->id) }}"
                                           class="btn btn-sm btn-primary rounded-pill px-3 py-1 d-block w-100" title="Issue an ATL against this request">
                                            <i class="bi bi-plus-lg me-1"></i> Create ATL
                                        </a>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="10" class="text-center text-muted py-4">No new replenishment requests.</td>
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
@endsection
