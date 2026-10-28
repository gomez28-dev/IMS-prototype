@extends('layouts.app')

@section('title', 'ATLs for '.$request->request_number)

@section('content')
<div class="container-fluid py-2">

    {{-- Laid out as Module 1's Deliveries page, with the same stylesheets
         copied across rather than approximated: an info card, three
         product-aware total cards, then the list itself with its action on the
         heading. A request's ATLs are the same document as M1's deliveries, and
         the supervisor asked for the two to look like one set. --}}
    @php
        $chipStyles = [
            'U' => 'background:#dcfce7;color:#166534;border:1px solid #bbf7d0;',
            'D' => 'background:#fef3c7;color:#92400e;border:1px solid #fde68a;',
            'P' => 'background:#fee2e2;color:#991b1b;border:1px solid #fecaca;',
        ];
        $chip = function ($code, $title = '') use ($chipStyles) {
            $style = $chipStyles[$code] ?? 'background:#f3f4f6;color:#6b7280;border:1px solid #e5e7eb;';

            return '<span class="prod-chip" style="'.$style.'" title="'.e($title).'">'.e($code ?: '—').'</span>';
        };
        $prodRows = [['D', 'Diesel'], ['U', 'Unleaded'], ['P', 'Premium']];

        $volumeCards = [
            ['title' => 'Qty Requested', 'icon' => 'bi-boxes', 'tint' => 'background:#ecfeff;color:#0891b2;', 'data' => $volumes['requested'], 'class' => 'text-dark'],
            ['title' => "Total ATL'd", 'icon' => 'bi-patch-check', 'tint' => 'background:#f0fdf4;color:#16a34a;', 'data' => $volumes['drawn'], 'class' => 'text-primary'],
            ['title' => 'Remaining', 'icon' => 'bi-clock-history', 'tint' => 'background:#fffbeb;color:#d97706;', 'data' => $volumes['pending'], 'class' => 'text-warning'],
        ];

        // Pick-up or delivery, as the request's own type says. M1 flips its date
        // column heading the same way.
        $isPickup = ! $request->isDelivered();
    @endphp
    <style>
        /* ---- Table (aligned with Module 1's Deliveries page) ---- */
        #atlsTable { width: 100%; }
        #atlsTable thead th {
            vertical-align: middle !important;
            text-align: center !important;
            font-size: 0.7rem !important;
            font-weight: 700 !important;
            letter-spacing: 0.06em;
            text-transform: uppercase;
            color: #475569;
            background-color: #f8fafc !important;
            padding: 0.85rem 0.5rem !important;
            line-height: 1.2;
            border-bottom: 1px solid #e5e7eb;
        }
        #atlsTable tbody td {
            padding: 0.75rem 0.5rem !important;
            font-size: 0.82rem;
            font-weight: 400 !important;
            text-align: center;
            vertical-align: middle;
        }
        #atlsTable tbody td[colspan] { padding: 3rem 0.5rem !important; }
        #atlsTable .badge { font-size: 0.7rem; font-weight: 500; }
        #atlsTable .btn-sm { font-size: 0.75rem; }

        /* DR# / PO# pill (same look as M1's) */
        .id-pill {
            display: inline-block;
            border: 1px solid #d1d5db;
            background: #f8fafc;
            color: #1f2937;
            border-radius: 0.4rem;
            padding: 0.15rem 0.6rem;
            font-size: 0.78rem;
            font-weight: 500;
            letter-spacing: 0.01em;
        }

        /* Icon-only square action buttons (same as M1's) */
        .action-icon {
            width: 2rem;
            height: 2rem;
            padding: 0 !important;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 0.5rem !important;
        }

        /* Per-product lines: "D - 30,000" */
        .pl-block { display: inline-block; text-align: left; }
        .pl-block .prod-chip { margin-right: 0.35rem; }

        /* ---- Stat cards (same pattern as M1's) ---- */
        .stat-card {
            background: #fff;
            border-radius: 1rem;
            box-shadow: 0 1px 3px rgba(15, 23, 42, 0.06);
            padding: 1rem 1.1rem;
            height: 100%;
        }
        .stat-head {
            display: flex;
            align-items: center;
            gap: 0.6rem;
            margin-bottom: 0.8rem;
        }
        .stat-icon {
            width: 2.25rem;
            height: 2.25rem;
            border-radius: 0.65rem;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.05rem;
            flex-shrink: 0;
        }
        .stat-title {
            font-size: 0.72rem;
            font-weight: 700;
            letter-spacing: 0.06em;
            text-transform: uppercase;
            color: #334155;
        }
        .stat-row {
            display: flex;
            align-items: center;
            gap: 0.6rem;
            border: 1px solid #e5e7eb;
            border-radius: 0.6rem;
            padding: 0.4rem 0.7rem;
            margin-bottom: 0.4rem;
            font-size: 0.85rem;
        }
        .stat-row .stat-name { flex: 1; color: #475569; }
        .stat-row .stat-val { font-weight: 700; color: #0f172a; }
        .stat-total {
            display: flex;
            justify-content: space-between;
            padding: 0.25rem 0.2rem 0;
            font-size: 0.85rem;
            color: #475569;
        }
        .stat-total .stat-val { font-weight: 700; color: #0f172a; }
        .prod-chip {
            display: inline-block; font-size: 0.65rem; font-weight: 700; line-height: 1;
            padding: 0.2rem 0.4rem; border-radius: 0.35rem;
        }
    </style>

    <a href="{{ route('stock-orders.wet-stock-requests.index') }}" class="btn btn-link text-decoration-none px-0 mb-3">
        <i class="bi bi-arrow-left me-1"></i> Back to Wet Stock Requests
    </a>

    <div class="row g-3 mb-4">
        {{-- Card 1: which request this is --}}
        <div class="col-md-3">
            <div class="stat-card">
                <div class="d-flex align-items-center gap-2 mb-2 flex-wrap">
                    <span class="badge rounded-pill" style="background:#fff1e6;color:#c2410c;">REQ# {{ $request->request_number }}</span>
                    <span class="badge rounded-pill" style="background:#eef2ff;color:#4338ca;">
                        {{ $isPickup ? 'Pick-up' : 'Delivery' }}
                    </span>
                </div>
                <h4 class="fw-bold text-dark mb-2">{{ $request->warehouse->name ?? '—' }}</h4>
                <div class="text-muted small">
                    <i class="bi bi-calendar3 me-1"></i>
                    Requested {{ $request->request_date?->format('M d, Y') ?? '—' }}
                </div>
                <div class="text-muted small mt-1">
                    <i class="bi bi-person me-1"></i>
                    {{ $request->requester->name ?? '—' }}
                </div>
                @if ($request->date_needed)
                    <div class="text-muted small mt-1">
                        <i class="bi bi-clock me-1"></i>
                        Needed {{ $request->date_needed->format('M d, Y') }}
                    </div>
                @endif
            </div>
        </div>

        {{-- Cards 2-4: the volumes, per product --}}
        @foreach ($volumeCards as $card)
            <div class="col-md-3">
                <div class="stat-card">
                    <div class="stat-head">
                        <div class="stat-icon" style="{{ $card['tint'] }}"><i class="bi {{ $card['icon'] }}"></i></div>
                        <span class="stat-title">{{ $card['title'] }}</span>
                    </div>
                    @foreach ($prodRows as [$code, $name])
                        <div class="stat-row" title="{{ $name }}">
                            {!! $chip($code, $name) !!}
                            <span class="stat-name">{{ $name }}</span>
                            <span class="stat-val">{{ number_format($card['data'][$name] ?? 0) }}</span>
                        </div>
                    @endforeach
                    <div class="stat-total">
                        <span>Total</span>
                        <span class="stat-val {{ $card['class'] }}">{{ number_format(array_sum($card['data'])) }}</span>
                    </div>
                </div>
            </div>
        @endforeach
    </div>

    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
        <h5 class="fw-bold text-dark mb-0">
            <i class="bi bi-truck text-primary me-2"></i>ATLs
        </h5>

        {{-- Where the supervisor pointed it: on the list's own heading, exactly
             where Add Delivery sits on M1's. It opens the multi-PO drawdown
             form right here instead of leaving the page; the standalone Raise
             an ATL page keeps the same partial for a bookmarked way in. --}}
        @if (Auth::user()->canEditStockOrders())
            <button type="button" class="btn btn-primary-custom" data-bs-toggle="collapse"
                    data-bs-target="#createAtlCollapse" aria-expanded="false" aria-controls="createAtlCollapse">
                <i class="bi bi-plus-lg me-1"></i> Add ATL
            </button>
        @endif
    </div>

    {{-- The Add ATL form, embedded: same partial, same field names, same
         store route as the standalone page - either way in posts the same. --}}
    @if (Auth::user()->canEditStockOrders())
        <div class="collapse mb-3" id="createAtlCollapse">
            @include('wetstock.wet-stock-requests.partials.create-atl-form')
        </div>
    @endif

    <div class="card card-custom border-0 shadow-sm">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table id="atlsTable" class="table table-custom table-hover align-middle mb-0">
                    <thead>
                        <tr>
                            <th>DR#</th>
                            <th>ATL#</th>
                            <th>PO#</th>
                            <th>{{ $isPickup ? 'Pick Up Date' : 'Delivery Date' }}</th>
                            <th>Qty Out</th>
                            <th>Approval</th>
                            <th>Lift Status</th>
                            <th>Received</th>
                            <th>Type</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($atls as $atl)
                            @php
                                // Every PO this ATL drew on, not just the primary one it
                                // is filed under, so a split drawdown is legible here.
                                $poNumbers = $atl->allocations->pluck('purchaseOrder')->filter()
                                    ->pluck('po_number')->unique()->join(', ');
                                $poNumbers = $poNumbers !== '' ? $poNumbers : ($atl->purchaseOrder->po_number ?? '—');

                                // A multi-PO ATL records each product's volume on its
                                // allocation rows, so the breakdown comes from there and
                                // falls back to the ATL's own line.
                                $breakdown = $atl->productBreakdown() ?: [$atl->product => (int) $atl->qty_to_receive];

                                $codes = ['Diesel' => 'D', 'Unleaded' => 'U', 'Premium' => 'P'];
                                $approved = $atl->isApproved();
                                 $lifted = $atl->lift_status === \App\Models\PurchaseOrderDelivery::LIFT_LIFTED;
                                 $canEditLift = Auth::user()->canMarkAtlLifted();
                                 $canEditReceived = Auth::user()->canReceiveStockIntoDepot();
                                 $canUnlift = $lifted && ! $atl->received_at;
                                 $canLift = $approved
                                     && in_array($atl->status, ['Pending', 'Active', 'Cancelled'], true)
                                     && ! $lifted
                                     && ! $atl->received_at;
                            @endphp
                            <tr>
                                <td><span class="id-pill">{{ $atl->dr_number ?: '—' }}</span></td>
                                <td class="text-dark">{{ $atl->atl_number ?: ($atl->client_atl_number ?: 'Pending') }}</td>
                                <td><span class="id-pill">{{ $poNumbers }}</span></td>
                                <td>{{ $atl->receiving_date?->format('Y-m-d') ?? '—' }}</td>
                                <td>
                                    <span class="pl-block font-monospace">
                                        @foreach ($breakdown as $product => $qty)
                                            <span class="d-block">
                                                {!! $chip($codes[$product] ?? '', $product) !!} {{ number_format($qty) }}
                                            </span>
                                        @endforeach
                                    </span>
                                </td>
                                <td>
                                    <span class="badge rounded-pill px-2 py-1 {{ $approved
                                        ? 'bg-success-subtle text-success border'
                                        : ($atl->approval_status === \App\Models\PurchaseOrderDelivery::APPROVAL_REJECTED
                                            ? 'bg-danger-subtle text-danger border'
                                            : 'bg-warning-subtle text-warning-emphasis border') }}">
                                        {{ $approved ? 'APPROVED' : strtoupper(str_replace('_', ' ', $atl->approval_status ?? 'DRAFT')) }}
                                    </span>
                                </td>
                                <td>
                                    @if ($canEditLift)
                                        <form method="POST" action="{{ route('stock-orders.lift-status', $atl->id) }}" class="lift-status-form">
                                            @csrf
                                            <select name="lift_status" class="form-select form-select-sm lift-status-select"
                                                    data-current="{{ $atl->lift_status }}"
                                                    aria-label="Lift status of ATL {{ $atl->atl_number ?: $atl->id }}">
                                                <option value="{{ \App\Models\PurchaseOrderDelivery::LIFT_UNLIFTED }}"
                                                        @selected($atl->lift_status === \App\Models\PurchaseOrderDelivery::LIFT_UNLIFTED)
                                                        @disabled(! $canUnlift && $atl->lift_status !== \App\Models\PurchaseOrderDelivery::LIFT_UNLIFTED && $atl->lift_status !== \App\Models\PurchaseOrderDelivery::LIFT_CANCELLED)>Unlifted</option>
                                                <option value="{{ \App\Models\PurchaseOrderDelivery::LIFT_LIFTED }}"
                                                        @selected($lifted)
                                                        @disabled(! $canLift && ! $lifted)>Lifted</option>
                                                <option value="{{ \App\Models\PurchaseOrderDelivery::LIFT_CANCELLED }}"
                                                        @selected($atl->lift_status === \App\Models\PurchaseOrderDelivery::LIFT_CANCELLED)
                                                        @disabled($atl->lift_status !== \App\Models\PurchaseOrderDelivery::LIFT_UNLIFTED && $atl->lift_status !== \App\Models\PurchaseOrderDelivery::LIFT_CANCELLED)>Cancelled</option>
                                            </select>
                                        </form>
                                    @elseif ($lifted)
                                        <span class="badge bg-success-subtle text-success border rounded-pill">Lifted</span>
                                    @elseif ($atl->lift_status === \App\Models\PurchaseOrderDelivery::LIFT_CANCELLED)
                                        <span class="badge bg-danger-subtle text-danger border rounded-pill">Cancelled</span>
                                    @else
                                        <span class="badge bg-light text-dark border rounded-pill">Unlifted</span>
                                    @endif
                                </td>
                                <td>
                                    @if ($canEditReceived)
                                        <form method="POST" action="{{ route('stock-orders.received-status', $atl->id) }}" class="received-status-form">
                                            @csrf
                                            <select name="received_status" class="form-select form-select-sm received-status-select"
                                                    aria-label="Received status of ATL {{ $atl->atl_number ?: $atl->id }}">
                                                <option value="NOT_RECEIVED" @selected(! $atl->received_at)>Not yet received</option>
                                                <option value="RECEIVED" @selected((bool) $atl->received_at)>Received</option>
                                            </select>
                                        </form>
                                    @elseif ($atl->received_at)
                                        <span class="badge bg-success-subtle text-success border rounded-pill">Received</span>
                                    @else
                                        <span class="badge bg-light text-dark border rounded-pill">Not yet received</span>
                                    @endif
                                </td>
                                <td class="text-dark">{{ $atl->typeLabel() }}</td>
                                <td>
                                    <div class="d-inline-flex gap-1 align-items-center justify-content-center">
                                        {{-- The PDF is released only once the VP has approved
                                             this ATL; the route enforces the same thing. --}}
                                        @if ($approved)
                                            <a href="{{ route('stock-orders.atl-pdf', $atl->id) }}"
                                               class="btn btn-sm btn-outline-secondary action-icon" target="_blank"
                                               title="Download ATL PDF">
                                                <i class="bi bi-file-earmark-pdf"></i>
                                            </a>
                                        @endif
                                         @unless ($approved)
                                             <span class="small text-muted">—</span>
                                         @endunless
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="10" class="text-center text-muted">
                                    <i class="bi bi-file-earmark-x fs-1 d-block mb-3 text-warning"></i>
                                    No ATLs issued for this request yet.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function () {
            document.querySelectorAll('.lift-status-select, .received-status-select').forEach(function (select) {
                select.addEventListener('change', function () {
                    select.closest('form').submit();
                });
            });
        });
    </script>
</div>
@endsection
