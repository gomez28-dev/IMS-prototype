@extends('layouts.app')

{{-- The top bar is this page's heading (the in-content heading and breadcrumb
     were dropped), so it has to carry the PO number: without it the page never
     says which purchase order you are looking at. --}}
@section('title', 'Purchase Order ' . ($purchaseOrder->po_number ?: '(Pending PO#)'))

@php
    // Feedback on a returned PO is for the person who raised it, nobody else.
    $isRequester = (int) $purchaseOrder->requested_by === (int) Auth::id();
    $rejFeedback = $isRequester
        && $purchaseOrder->request_status === 'REQUESTED'
        && filled($purchaseOrder->rejection_reason);
    $rejUnseen = $rejFeedback && is_null($purchaseOrder->rejection_seen_at);

    $histLabels = [
        'submitted' => ['Submitted for VP approval', 'bi-send', '#2563eb'],
        'edited' => ['Edited', 'bi-pencil', '#6b7280'],
        'resubmitted' => ['Resubmitted for VP approval', 'bi-arrow-repeat', '#2563eb'],
        'rejected' => ['Rejected', 'bi-x-circle', '#dc2626'],
        'approved' => ['Approved by VP', 'bi-check-circle', '#16a34a'],
        'cancelled' => ['Cancelled', 'bi-x-octagon', '#6b7280'],
    ];
@endphp

@push('page-actions')
    <div class="d-flex gap-2 flex-wrap">
        {{-- One action at a time. Editing an approved PO is not offered,
             so the figures the VP signed off cannot drift afterwards. --}}
        @if ($purchaseOrder->isCancelled())
            {{-- cancelled: no Print or Edit --}}
        @elseif ($purchaseOrder->isVpApproved())
            <a href="{{ route('stock-orders.po-pdf', $purchaseOrder->id) }}"
               class="btn btn-sm btn-outline-danger" title="PO PDF" aria-label="Download PO PDF">
                <i class="bi bi-file-earmark-pdf"></i>
            </a>
        @elseif (Auth::user()->canEditStockOrders())
            <a href="{{ route('stock-orders.edit-supplier-po', $purchaseOrder->id) }}" class="btn btn-sm btn-primary-custom">
                <i class="bi bi-pencil me-1"></i> Edit PO
            </a>
        @endif

        {{-- Same two icons, in the same order and look, as the Purchase Orders list.
             The bell is always drawn; only its red dot goes away once the feedback is read. --}}
        <button type="button" class="btn btn-sm btn-light border position-relative pn-icon" onclick="openPn('pnHistory')" title="Activity history" aria-label="Activity history">
            <i class="bi bi-clock-history"></i>
        </button>
        <button type="button" class="btn btn-sm btn-light border position-relative pn-icon" onclick="openPn('pnBell')" title="Notifications" aria-label="Notifications">
            <i class="bi bi-bell"></i>
            @if ($rejUnseen)
                <span id="rejBellDot" class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger">1</span>
            @endif
        </button>

        <a href="{{ route('stock-orders.purchase-orders.index') }}" class="btn btn-sm btn-outline-secondary">
            <i class="bi bi-arrow-left me-1"></i> Back to Purchase Orders
        </a>
    </div>
@endpush

@section('content')
<div class="row justify-content-center">
    <div class="col-12">
        {{-- No breadcrumb and no page heading: the PO number is the page name
             in the top bar, and the volume cards open the page. --}}

        @php
            // Same card set and styling as the Orders Dashboard and the
            // Purchase Orders list, so all three pages read as one system.
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

            // A product reaches these pages either as a code (D) or a name
            // (Diesel); the chip only ever shows the code, so both resolve here.
            $productCodes = \App\Models\Order::PRODUCT_TYPES;
            $toCode = function ($product) use ($productCodes) {
                if (isset($productCodes[$product])) {
                    return $product;
                }

                $code = array_search($product, $productCodes, true);

                return $code !== false ? $code : \Illuminate\Support\Str::substr((string) $product, 0, 1);
            };

            $volumeCards = [
                ['title' => 'Total Qty. Ordered', 'icon' => 'bi-boxes', 'tint' => 'background:#ecfeff;color:#0891b2;', 'data' => $volumes['ordered']],
                ['title' => 'Total Qty. Lifted', 'icon' => 'bi-truck', 'tint' => 'background:#f0fdf4;color:#16a34a;', 'data' => $volumes['lifted']],
                ['title' => 'Total Qty. Unlifted', 'icon' => 'bi-clock-history', 'tint' => 'background:#fffbeb;color:#d97706;', 'data' => $volumes['unlifted']],
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
            .stat-total .stat-val { font-weight: 600; color: #111827; }
            .stat-big { font-size: 3.6rem; font-weight: 800; line-height: 1; color: #111827; }
            .prod-chip {
                display: inline-block; vertical-align: middle; font-size: 0.65rem; font-weight: 700; line-height: 1;
                padding: 0.2rem 0.4rem; border-radius: 0.35rem; margin: 0 1px;
            }

            /* Top-right icons and the floating cards they open (same as the Purchase Orders list). */
            .pn-icon { width: 36px; height: 32px; display: inline-flex; align-items: center; justify-content: center; padding: 0; color: #475569; }
            .pn-overlay { position: fixed; inset: 0; z-index: 2050; display: none; align-items: center; justify-content: center; padding: 1rem; background: rgba(15, 23, 42, .5); -webkit-backdrop-filter: blur(6px); backdrop-filter: blur(6px); }
            .pn-overlay.show { display: flex; animation: pnFade .22s ease-out both; }
            .pn-scene { perspective: 1100px; max-height: 100%; }
            .pn-float { animation: pnRise .5s cubic-bezier(.2, .9, .3, 1.1) both, pnFloat 6s ease-in-out .5s infinite; }
            .pn-float:hover { animation-play-state: running, paused; }
            .pn-card { width: min(94vw, 520px); max-height: 82vh; display: flex; flex-direction: column; background: #fff; border-radius: 20px; overflow: hidden; border-top: 4px solid #ff5a1f; transition: transform .18s ease-out; box-shadow: 0 1px 0 rgba(255, 255, 255, .9) inset, 0 44px 80px -24px rgba(15, 23, 42, .5), 0 20px 32px -20px rgba(15, 23, 42, .4), 0 0 0 1px rgba(15, 23, 42, .06); }
            .pn-head { display: flex; justify-content: space-between; align-items: center; padding: 1.1rem 1.4rem .85rem; border-bottom: 1px solid #e2e8f0; }
            .pn-head h5 { margin: 0; font-size: 1.2rem; font-weight: 700; color: #0f172a; }
            .pn-x { border: 0; background: transparent; color: #64748b; font-size: 1.05rem; line-height: 1; padding: .35rem; border-radius: 8px; }
            .pn-x:hover { background: #f1f5f9; color: #0f172a; }
            .pn-body { flex: 1 1 auto; min-height: 0; overflow-y: auto; padding: .6rem 1.4rem 1rem; }
            .pn-foot { display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: .6rem; padding: .8rem 1.4rem; border-top: 1px solid #e2e8f0; background: #f8fafc; }
            .pn-foot > [data-pn-close] { margin-left: auto; }
            .pn-empty { text-align: center; color: #64748b; padding: 2rem 0; font-size: .92rem; }
            .pn-po { font-weight: 700; color: #0f172a; }
            .pn-meta { font-size: .78rem; color: #64748b; margin-top: .1rem; }
            .pn-reason { margin-top: .45rem; white-space: pre-wrap; font-size: .9rem; color: #334155; background: #f8fafc; border-radius: 10px; padding: .6rem .75rem; }
            .pn-ev { display: flex; gap: .75rem; padding: .7rem 0; border-bottom: 1px solid #eef2f7; }
            .pn-ev:last-child { border-bottom: 0; }
            .pn-ev-ico { flex: 0 0 auto; width: 34px; height: 34px; border-radius: .6rem; display: flex; align-items: center; justify-content: center; background: #f8fafc; }
            .pn-ev[hidden], .pn-pager[hidden] { display: none !important; }
            .pn-pager { display: flex; align-items: center; gap: .6rem; }
            .pn-pg-btn { border: 1px solid #e2e8f0; background: #fff; color: #334155; border-radius: 999px; padding: .25rem .75rem; font-size: .8rem; line-height: 1.4; }
            .pn-pg-btn:hover:not(:disabled) { background: #f1f5f9; }
            .pn-pg-btn:disabled { opacity: .4; cursor: default; }
            .pn-dots { display: flex; align-items: center; gap: .4rem; }
            .pn-dot { width: 8px; height: 8px; padding: 0; border: 0; border-radius: 999px; background: #cbd5e1; transition: width .18s ease, background-color .18s ease; }
            .pn-dot:hover { background: #94a3b8; }
            .pn-dot.active { width: 20px; background: #ff5a1f; }
            @keyframes pnFade { from { opacity: 0; } to { opacity: 1; } }
            @keyframes pnRise { from { opacity: 0; transform: translateY(28px) scale(.94); } to { opacity: 1; transform: none; } }
            @keyframes pnFloat { 0%, 100% { transform: translateY(0); } 50% { transform: translateY(-5px); } }
            @media (prefers-reduced-motion: reduce) {
                .pn-float { animation: none; }
                .pn-card { transition: none; }
            }
        </style>

        {{-- Per-product volume, mirroring the Purchase Orders list cards. --}}
        <div class="row g-4 mb-4">
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

        {{-- The per-product requirement block that used to sit here is gone: the
             three volume cards above already state Ordered, Lifted and Unlifted
             for every product on this PO. --}}

        {{-- ATLs. Raising a new one is done from Sales Orders or Stock Requests,
             which know which PO the volume is coming from. --}}
        <div class="card card-custom border-0 shadow-sm">
            <div class="card-header bg-white py-3">
                <h6 class="fw-bold text-dark mb-0">ATL Records</h6>
            </div>
            <div class="card-body border-bottom py-2">
                <form method="GET" action="{{ route('stock-orders.purchase-orders.show', $purchaseOrder->id) }}" class="row g-2 align-items-center">
                    <div class="col-md-4">
                        <input type="text" name="atl_search" class="form-control form-control-sm" placeholder="Search ATL #, DR #, driver or product..." value="{{ $atlSearch }}">
                    </div>
                    <div class="col-auto d-flex gap-2">
                        <button type="submit" class="btn btn-sm btn-primary">Search</button>
                        @if ($atlSearch)
                            <a href="{{ route('stock-orders.purchase-orders.show', $purchaseOrder->id) }}" class="btn btn-sm btn-outline-secondary">Reset</a>
                        @endif
                    </div>
                </form>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="bg-light">
                            <tr>
                                <th class="ps-3 py-3">ATL #</th>
                                <th class="py-3">Supplier</th>
                                <th class="py-3">Type</th>
                                <th class="py-3 text-end">Qty.</th>
                                <th class="py-3 text-end">Price</th>
                                <th class="py-3 text-end">Amount</th>
                                <th class="py-3 text-center">Status</th>
                                <th class="pe-3 py-3 text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($deliveries as $delivery)
                                @php
                                    // An ATL stores no price of its own, so each product's
                                    // rate comes from the PO line it drew against. Legacy
                                    // ATLs without allocations fall back to their own
                                    // single product column.
                                    $rates = $delivery->productBreakdown() ?: [$delivery->product => (int) $delivery->qty_to_receive];
                                    $amount = 0;
                                    foreach ($rates as $product => $qty) {
                                        $amount += $qty * (float) ($priceByProduct[$product] ?? 0);
                                    }
                                @endphp
                                <tr>
                                    <td class="ps-3 fw-bold text-dark">
                                        {{-- The REF# names what the ATL serves: SO-FT, SO-BB or REQ. --}}
                                        <div>{{ $delivery->refNumber() }}</div>
                                        {{ $delivery->atl_number ?: ($delivery->client_atl_number ?: 'Pending') }}
                                        @if ($delivery->client_atl_number && $delivery->atl_number)
                                            <div class="small text-muted">Client: {{ $delivery->client_atl_number }}</div>
                                        @endif
                                        @if ($delivery->dr_number)
                                            <div class="small text-muted">DR: {{ $delivery->dr_number }}</div>
                                        @endif
                                        <div class="small text-muted">{{ $delivery->receiving_date ? $delivery->receiving_date->format('M d, Y') : '—' }}</div>
                                    </td>
                                    <td class="small">{{ $purchaseOrder->supplier_name ?: ($purchaseOrder->client->name ?? '—') }}</td>
                                    <td>
                                        <span class="badge {{ $purchaseOrder->typeBadgeClass() }}">{{ $delivery->typeLabel() }}</span>
                                    </td>
                                    <td class="text-end small font-monospace">
                                        @foreach ($rates as $product => $qty)
                                            <div>{!! $chip($toCode($product), $product) !!} <span class="ms-1">{{ number_format($qty) }}</span></div>
                                        @endforeach
                                    </td>
                                    <td class="text-end small font-monospace">
                                        @foreach ($rates as $product => $qty)
                                            <div>{!! $chip($toCode($product), $product) !!} <span class="ms-1">{{ number_format((float) ($priceByProduct[$product] ?? 0), 2) }}</span></div>
                                        @endforeach
                                    </td>
                                    <td class="text-end small fw-semibold font-monospace">{{ number_format($amount, 2) }}</td>
                                    <td class="text-center">
                                        @if ($delivery->lift_status === \App\Models\PurchaseOrderDelivery::LIFT_LIFTED)
                                            <span class="badge bg-success-subtle text-success border border-success-subtle rounded-pill px-2 py-1">Lifted</span>
                                        @elseif ($delivery->lift_status === \App\Models\PurchaseOrderDelivery::LIFT_CANCELLED)
                                            <span class="badge bg-danger-subtle text-danger border border-danger-subtle rounded-pill px-2 py-1">Cancelled</span>
                                        @else
                                            <span class="badge bg-primary-subtle text-primary border border-primary-subtle rounded-pill px-2 py-1">Unlifted</span>
                                        @endif
                                        @if ($delivery->driver_name)
                                            <div class="extra-small text-muted mt-1">{{ $delivery->driver_name }} @if ($delivery->plate_number)({{ $delivery->plate_number }})@endif</div>
                                        @endif
                                    </td>
                                    <td class="pe-3 text-end">
                                        <div class="d-inline-flex gap-1 justify-content-end">
                                            @if ($delivery->hasScannedDocs())
                                                <a href="{{ $delivery->scanned_doc_url }}" target="_blank"
                                                   class="btn btn-sm btn-outline-success rounded-pill px-2 py-1" title="View scanned ATL">
                                                    <i class="bi bi-google me-1"></i> Scanned
                                                </a>
                                            @endif
                                            {{-- The ATL document is only released once the VP has approved, which
                                                 the route enforces as well as this link. --}}
                                            @if ($delivery->requiresAtl() && $delivery->atl_number
                                                && $delivery->status !== 'Pending'
                                                && $delivery->approval_status !== \App\Models\PurchaseOrderDelivery::APPROVAL_REJECTED
                                                && $purchaseOrder->isVpApproved())
                                                <a href="{{ route('stock-orders.pdf', $delivery->id) }}"
                                                   class="btn btn-sm btn-outline-danger rounded-pill px-2 py-1" title="Download ATL PDF">
                                                    <i class="bi bi-file-earmark-pdf me-1"></i> ATL PDF
                                                </a>
                                            @elseif ($delivery->approval_status === \App\Models\PurchaseOrderDelivery::APPROVAL_REJECTED)
                                                <span class="badge bg-danger-subtle text-danger border border-danger-subtle rounded-pill px-2 py-1"
                                                      title="This ATL was rejected, so no PDF was issued">Rejected</span>
                                            @else
                                                <span class="badge bg-light text-muted border rounded-pill px-2 py-1" title="Awaiting ATL issuance">Pending Issuance</span>
                                            @endif

                                            {{-- A date or driver fixed after this ATL was saved
                                                 belongs to this row: correct it rather than
                                                 filing the same load again. --}}
                                            @if (Auth::user()->canEditStockOrders() && $delivery->canBeCorrected())
                                                <a href="{{ route('stock-orders.atls.edit', $delivery->id) }}"
                                                   class="btn btn-sm btn-outline-primary rounded-pill px-2 py-1"
                                                   title="Correct this ATL's date, driver or documents">
                                                    <i class="bi bi-pencil-square me-1"></i> Correct
                                                </a>
                                            @endif
                                        </div>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="8" class="text-center py-5 text-muted">
                                        <i class="bi bi-file-earmark-x fs-1 d-block mb-3 text-secondary"></i>
                                        {{ $atlSearch ? 'No ATLs match your search.' : 'No ATLs issued for this PO yet.' }}
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                <style>
                    .atl-pager { display: flex; align-items: center; justify-content: center; gap: .75rem; padding: .75rem 1rem; border-top: 1px solid #e5e7eb; }
                    .atl-pager[hidden] { display: none; }
                    .atl-pg-btn { border: 1px solid #e5e7eb; background: #fff; border-radius: 999px; padding: .25rem .8rem; font-size: .8rem; color: #374151; }
                    .atl-pg-btn:disabled { opacity: .4; }
                    .atl-dots { display: flex; gap: .4rem; }
                    .atl-dot { width: 8px; height: 8px; border-radius: 50%; background: #d1d5db; border: 0; padding: 0; }
                    .atl-dot.on { background: #f04a1a; }
                </style>
                <div class="atl-pager" id="atlPager" hidden>
                    <button type="button" class="atl-pg-btn" data-atl-prev aria-label="Previous page"><i class="bi bi-chevron-left"></i> Prev</button>
                    <div class="atl-dots"></div>
                    <button type="button" class="atl-pg-btn" data-atl-next aria-label="Next page">Next <i class="bi bi-chevron-right"></i></button>
                </div>
                <script>
                (function () {
                    var pager = document.getElementById('atlPager');
                    if (!pager) return;
                    var wrap = pager.previousElementSibling;
                    var table = wrap && wrap.querySelector('table');
                    if (!table || !table.tBodies.length) return;
                    var rows = Array.prototype.filter.call(table.tBodies[0].rows, function (r) {
                        return !r.querySelector('td[colspan]');
                    });
                    var per = 5, page = 0, pages = Math.ceil(rows.length / per);
                    if (pages <= 1) return;
                    var prev = pager.querySelector('[data-atl-prev]');
                    var next = pager.querySelector('[data-atl-next]');
                    var dots = pager.querySelector('.atl-dots');
                    function show(p) {
                        page = p;
                        rows.forEach(function (r, i) {
                            r.style.display = (i >= p * per && i < (p + 1) * per) ? '' : 'none';
                        });
                        Array.prototype.forEach.call(dots.children, function (d, i) {
                            d.classList.toggle('on', i === p);
                        });
                        prev.disabled = p === 0;
                        next.disabled = p === pages - 1;
                    }
                    for (var i = 0; i < pages; i++) {
                        (function (i) {
                            var d = document.createElement('button');
                            d.type = 'button';
                            d.className = 'atl-dot';
                            d.setAttribute('aria-label', 'Page ' + (i + 1));
                            d.addEventListener('click', function () { show(i); });
                            dots.appendChild(d);
                        })(i);
                    }
                    prev.addEventListener('click', function () { if (page > 0) show(page - 1); });
                    next.addEventListener('click', function () { if (page < pages - 1) show(page + 1); });
                    pager.hidden = false;
                    show(0);
                })();
                </script>
            </div>
        </div>
    </div>
</div>

{{-- Notifications for this PO. The only notification a PO can carry is its
     return feedback, and that is shown to the person who raised it and nobody else. --}}
<div class="pn-overlay" id="pnBell" role="dialog" aria-modal="true" aria-labelledby="pnBellTitle" aria-hidden="true">
    <div class="pn-scene">
        <div class="pn-float">
            <div class="pn-card">
                <div class="pn-head">
                    <h5 id="pnBellTitle">Notifications</h5>
                    <button type="button" class="pn-x" data-pn-close aria-label="Close"><i class="bi bi-x-lg"></i></button>
                </div>
                <div class="pn-body">
                    @if ($rejFeedback)
                        <div class="py-3">
                            <div>
                                <span class="pn-po">{{ $purchaseOrder->po_number ?: '(Pending PO#)' }}</span>
                                <span class="text-muted small">was returned for changes</span>
                            </div>
                            <div class="pn-meta">
                                By {{ optional($purchaseOrder->rejecter)->name ?? 'the approver' }}@if ($purchaseOrder->rejected_at) on {{ \Illuminate\Support\Carbon::parse($purchaseOrder->rejected_at)->format('M d, Y g:i A') }}@endif
                            </div>
                            <div class="pn-reason">{{ $purchaseOrder->rejection_reason }}</div>
                        </div>
                    @else
                        <div class="pn-empty">
                            <i class="bi bi-bell fs-3 d-block mb-2"></i>
                            No notifications for this purchase order. If it is returned to you, the reason shows here.
                        </div>
                    @endif
                </div>
                <div class="pn-foot">
                    @if ($rejFeedback && Auth::user()->canEditStockOrders())
                        <a href="{{ route('stock-orders.edit-supplier-po', $purchaseOrder->id) }}" class="btn btn-sm btn-outline-primary rounded-pill px-3">
                            <i class="bi bi-pencil me-1"></i> Edit PO
                        </a>
                    @endif
                    <button type="button" class="btn btn-light border rounded-pill px-4" data-pn-close>Close</button>
                </div>
            </div>
        </div>
    </div>
</div>

{{-- Activity history of this PO, paged. --}}
<div class="pn-overlay" id="pnHistory" role="dialog" aria-modal="true" aria-labelledby="pnHistoryTitle" aria-hidden="true">
    <div class="pn-scene">
        <div class="pn-float">
            <div class="pn-card">
                <div class="pn-head">
                    <div>
                        <h5 id="pnHistoryTitle">Activity history</h5>
                        <div class="pn-meta">{{ $purchaseOrder->po_number ?: '(Pending PO#)' }}</div>
                    </div>
                    <button type="button" class="pn-x" data-pn-close aria-label="Close"><i class="bi bi-x-lg"></i></button>
                </div>
                <div class="pn-body" data-pn-per="5">
                    @forelse ($purchaseOrder->activities as $act)
                        @php $meta = $histLabels[$act->action] ?? [ucfirst($act->action), 'bi-dot', '#6b7280']; @endphp
                        <div class="pn-ev">
                            <span class="pn-ev-ico" style="color: {{ $meta[2] }};"><i class="bi {{ $meta[1] }}"></i></span>
                            <div>
                                <div class="fw-semibold">{{ $meta[0] }}</div>
                                <div class="pn-meta">
                                    {{ optional($act->actor)->name ? 'By '.$act->actor->name : '' }}@if ($act->created_at) {{ optional($act->actor)->name ? 'on' : 'On' }} {{ $act->created_at->format('M d, Y g:i A') }}@endif
                                </div>
                                {{-- A rejection's note is the reason, which only the requester may read. --}}
                                @if ($act->note && ($act->action !== 'rejected' || $isRequester))
                                    <div class="small mt-1" style="white-space: pre-wrap;">{{ $act->note }}</div>
                                @endif
                            </div>
                        </div>
                    @empty
                        <div class="pn-empty">
                            <i class="bi bi-clock-history fs-3 d-block mb-2"></i>
                            No activity recorded yet.
                        </div>
                    @endforelse
                </div>
                <div class="pn-foot">
                    <div class="pn-pager" data-pn-pager hidden>
                        <button type="button" class="pn-pg-btn" data-pn-prev aria-label="Previous page"><i class="bi bi-chevron-left"></i> Prev</button>
                        <div class="pn-dots"></div>
                        <button type="button" class="pn-pg-btn" data-pn-next aria-label="Next page">Next <i class="bi bi-chevron-right"></i></button>
                    </div>
                    <button type="button" class="btn btn-light border rounded-pill px-4" data-pn-close>Close</button>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
(function () {
    let openId = null;
    let lastFocus = null;

    // Pages the items of a card: Prev / Next buttons and one dot per page.
    // The card keeps the height of its tallest page so it does not jump.
    function setupPager(el) {
        const body = el.querySelector('.pn-body');
        const pager = el.querySelector('[data-pn-pager]');
        if (!body || !pager) return;

        const items = Array.from(body.querySelectorAll('.pn-ev'));
        const per = parseInt(body.dataset.pnPer || '5', 10);
        const pages = Math.ceil(items.length / per);
        const dots = pager.querySelector('.pn-dots');
        const prev = pager.querySelector('[data-pn-prev]');
        const next = pager.querySelector('[data-pn-next]');
        let page = 0;

        body.style.minHeight = '';
        items.forEach(function (it) { it.hidden = false; });

        if (pages <= 1) {
            pager.hidden = true;
            return;
        }

        pager.hidden = false;
        dots.innerHTML = '';
        for (let i = 0; i < pages; i++) {
            const dot = document.createElement('button');
            dot.type = 'button';
            dot.className = 'pn-dot';
            dot.setAttribute('aria-label', 'Page ' + (i + 1));
            dot.addEventListener('click', function () { show(i); });
            dots.appendChild(dot);
        }

        function show(n) {
            page = Math.max(0, Math.min(pages - 1, n));
            items.forEach(function (it, idx) { it.hidden = Math.floor(idx / per) !== page; });
            Array.from(dots.children).forEach(function (d, i) {
                d.classList.toggle('active', i === page);
                d.setAttribute('aria-current', i === page ? 'true' : 'false');
            });
            prev.disabled = page === 0;
            next.disabled = page === pages - 1;
            body.scrollTop = 0;
        }

        prev.onclick = function () { show(page - 1); };
        next.onclick = function () { show(page + 1); };

        let tallest = 0;
        for (let i = 0; i < pages; i++) {
            show(i);
            tallest = Math.max(tallest, body.scrollHeight);
        }
        body.style.minHeight = Math.min(tallest, Math.round(window.innerHeight * 0.55)) + 'px';
        show(0);
    }

    // Opening the bell marks the feedback as read, which only clears the red dot.
    // The bell itself stays where it is.
    function markSeen() {
        const dot = document.getElementById('rejBellDot');
        if (!dot) return;

        fetch(@json(route('stock-orders.purchase-orders.rejection-seen', $purchaseOrder->id)), {
            method: 'POST',
            headers: { 'X-CSRF-TOKEN': @json(csrf_token()), 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
        }).then(function (r) { if (r.ok) dot.remove(); }).catch(function () {});
    }

    window.openPn = function (id) {
        const el = document.getElementById(id);
        if (!el) return;

        lastFocus = document.activeElement;
        openId = id;
        el.classList.add('show');
        el.setAttribute('aria-hidden', 'false');
        document.body.style.overflow = 'hidden';
        setupPager(el);
        if (id === 'pnBell') markSeen();
        const close = el.querySelector('[data-pn-close]');
        if (close) close.focus();
    };

    function closePn() {
        if (!openId) return;
        const el = document.getElementById(openId);
        el.classList.remove('show');
        el.setAttribute('aria-hidden', 'true');
        const card = el.querySelector('.pn-card');
        if (card) card.style.transform = '';
        document.body.style.overflow = '';
        openId = null;
        if (lastFocus && lastFocus.focus) lastFocus.focus();
    }

    document.querySelectorAll('[data-pn-close]').forEach(function (b) { b.addEventListener('click', closePn); });
    document.querySelectorAll('.pn-overlay').forEach(function (el) {
        el.addEventListener('mousedown', function (e) {
            if (e.target === el || e.target.classList.contains('pn-scene')) closePn();
        });

        // The card tilts slightly toward the pointer.
        const card = el.querySelector('.pn-card');
        card.addEventListener('mousemove', function (e) {
            const r = card.getBoundingClientRect();
            const x = (e.clientX - r.left) / r.width - 0.5;
            const y = (e.clientY - r.top) / r.height - 0.5;
            card.style.transform = 'rotateY(' + (x * 5) + 'deg) rotateX(' + (-y * 5) + 'deg)';
        });
        card.addEventListener('mouseleave', function () { card.style.transform = ''; });
    });

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') { closePn(); return; }
        if (!openId) return;
        if (e.key === 'ArrowLeft' || e.key === 'ArrowRight') {
            const btn = document.getElementById(openId).querySelector(e.key === 'ArrowLeft' ? '[data-pn-prev]' : '[data-pn-next]');
            if (btn && !btn.disabled) btn.click();
        }
    });
})();
</script>
@endsection