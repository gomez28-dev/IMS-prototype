@extends('layouts.app')

@section('title', 'ATLs for '.$request->request_number)

@section('content')
<div class="container-fluid py-2">

    {{-- Laid out like the M1 Deliveries page: an info card, product-aware
         totals, then the list itself with its action on the heading. --}}
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
            ['title' => 'Total Qty. Requested', 'icon' => 'bi-boxes', 'tint' => 'background:#ecfeff;color:#0891b2;', 'data' => $volumes['requested']],
            ['title' => "Total ATL'd", 'icon' => 'bi-patch-check', 'tint' => 'background:#f0fdf4;color:#16a34a;', 'data' => $volumes['drawn']],
            ['title' => 'Remaining', 'icon' => 'bi-clock-history', 'tint' => 'background:#fffbeb;color:#d97706;', 'data' => $volumes['pending']],
        ];
    @endphp
    <style>
        .stat-card {
            background: #fff; border: 1px solid #e2e8f0; border-radius: 1rem;
            padding: 1.25rem; height: 100%;
        }
        .stat-head { display: flex; align-items: center; gap: .75rem; margin-bottom: 1rem; }
        .stat-icon {
            width: 46px; height: 46px; border-radius: 0.8rem; display: flex;
            align-items: center; justify-content: center; font-size: 1.3rem; flex-shrink: 0;
        }
        .stat-title { font-size: 0.72rem; font-weight: 700; letter-spacing: 0.05em; text-transform: uppercase; color: #374151; }
        .stat-row {
            display: flex; justify-content: space-between; align-items: center;
            border: 1px solid #e5e7eb; border-radius: 0.6rem;
            padding: 0.45rem 0.8rem; margin-bottom: 0.45rem; background: #fff;
        }
        .stat-row .stat-val { font-weight: 700; color: #0f172a; }
        .stat-total { display: flex; justify-content: space-between; padding: 0.25rem 0.3rem 0; color: #6b7280; }
        .stat-total .stat-val { font-weight: 700; color: #0f172a; }
        .prod-chip {
            display: inline-block; font-size: 0.65rem; font-weight: 700; line-height: 1;
            padding: 0.2rem 0.4rem; border-radius: 0.35rem; margin: 0 1px;
        }
    </style>

    <a href="{{ route('stock-orders.wet-stock-requests.index') }}" class="btn btn-link text-decoration-none px-0 mb-3">
        <i class="bi bi-arrow-left me-1"></i> Back to Wet Stock Requests
    </a>

    <div class="row g-3 mb-4">
        <div class="col-md-3">
            <div class="stat-card">
                <span class="badge rounded-pill mb-2" style="background:#fff1e6;color:#c2410c;">{{ $request->request_number }}</span>
                <h5 class="fw-bold text-dark mb-2">{{ $request->warehouse->name ?? '—' }}</h5>
                <div class="text-muted small">
                    <i class="bi bi-calendar3 me-1"></i>
                    Requested {{ $request->request_date?->format('M d, Y') ?? '—' }}
                </div>
                @if ($request->date_needed)
                    <div class="text-muted small mt-1">
                        <i class="bi bi-clock me-1"></i>
                        Needed {{ $request->date_needed->format('M d, Y') }}
                    </div>
                @endif
            </div>
        </div>

        @foreach ($volumeCards as $card)
            <div class="col-md-3">
                <div class="stat-card">
                    <div class="stat-head">
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

    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
        <h5 class="fw-bold text-dark mb-0">
            <i class="bi bi-truck text-primary me-2"></i>ATLs
        </h5>

        {{-- Where the supervisor pointed the Create ATL button: on the list's
             own heading, as Add Delivery sits on M1's. --}}
        @if (Auth::user()->canEditStockOrders())
            <a href="{{ route('stock-orders.wet-stock-requests.atls.create', $request->id) }}"
               class="btn btn-primary-custom">
                <i class="bi bi-plus-lg me-1"></i> Add ATL
            </a>
        @endif
    </div>

    <div class="card card-custom border-0 shadow-sm">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="bg-light">
                        <tr>
                            <th class="ps-3 py-3">ATL # / DR #</th>
                            <th class="py-3">PO #</th>
                            <th class="py-3">Pick Up Date</th>
                            <th class="py-3">Product / Qty</th>
                            <th class="py-3">Status</th>
                            <th class="py-3 text-center">Approval</th>
                            <th class="py-3 text-center">Lift</th>
                            <th class="pe-3 py-3 text-end">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($atls as $atl)
                            @php
                                $poNumbers = $atl->allocations->pluck('purchaseOrder')->filter()
                                    ->pluck('po_number')->unique()->join(', ');
                                $poNumbers = $poNumbers !== '' ? $poNumbers : ($atl->purchaseOrder->po_number ?? '—');
                            @endphp
                            <tr>
                                <td class="ps-3 fw-semibold">{{ $atl->atl_number ?? '—' }}</td>
                                <td class="small">{{ $poNumbers }}</td>
                                <td class="small">{{ $atl->receiving_date?->format('M d, Y') ?? '—' }}</td>
                                <td class="small font-monospace">
                                    @php
                                        // A multi-PO ATL records each product's volume on
                                        // its allocation rows, so the breakdown comes from
                                        // there and falls back to the ATL's own line.
                                        $breakdown = $atl->productBreakdown() ?: [$atl->product => (int) $atl->qty_to_receive];
                                    @endphp
                                    @foreach ($breakdown as $product => $qty)
                                        <span class="d-block">{{ $product }} — {{ number_format($qty) }}</span>
                                    @endforeach
                                </td>
                                <td>
                                    <span class="badge bg-light text-dark border">{{ $atl->status }}</span>
                                </td>
                                <td class="text-center">
                                    <span class="badge rounded-pill {{ $atl->approval_status === \App\Models\PurchaseOrderDelivery::APPROVAL_APPROVED
                                        ? 'bg-success-subtle text-success border'
                                        : 'bg-warning-subtle text-warning-emphasis border' }}">
                                        {{ str_replace('_', ' ', $atl->approval_status ?? 'DRAFT') }}
                                    </span>
                                </td>
                                <td class="text-center small">
                                    {{ str_replace('_', ' ', $atl->lift_status ?? 'UNLIFTED') }}
                                </td>
                                <td class="pe-3 text-end">
                                    <span class="small text-muted">—</span>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="8" class="text-center text-muted py-5">
                                    <i class="bi bi-file-earmark-x fs-1 d-block mb-3 text-secondary"></i>
                                    No ATLs issued for this request yet.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
@endsection
