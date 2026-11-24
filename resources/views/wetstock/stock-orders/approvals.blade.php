@extends('layouts.app')

@section('title', 'Approval Queue List')

@section('hide-portal-label', true)

@push('page-actions')
    <a href="{{ route('stock-orders.index') }}" class="btn btn-sm btn-outline-secondary">
        <i class="bi bi-arrow-left me-1"></i> Back to Orders
    </a>
@endpush

@section('content')
<style>
    /* Review card for a purchase order. It floats over the page so the
       reviewer can read the whole request before approving or rejecting. */
    .apv-overlay { position: fixed; inset: 0; z-index: 2050; display: none; align-items: center; justify-content: center; padding: 1rem; background: rgba(15, 23, 42, .5); -webkit-backdrop-filter: blur(6px); backdrop-filter: blur(6px); }
    .apv-overlay.rj { z-index: 2060; background: rgba(15, 23, 42, .35); }
    .apv-overlay.show { display: flex; animation: apvFade .22s ease-out both; }
    .apv-scene { perspective: 1100px; max-height: 100%; }
    .apv-float { animation: apvRise .5s cubic-bezier(.2, .9, .3, 1.1) both, apvFloat 6s ease-in-out .5s infinite; }
    .apv-float:hover { animation-play-state: running, paused; }
    .apv-card { width: min(94vw, 660px); max-height: 86vh; display: flex; flex-direction: column; background: #fff; border-radius: 20px; overflow: hidden; border-top: 4px solid #ff5a1f; transform-style: preserve-3d; transition: transform .18s ease-out; box-shadow: 0 1px 0 rgba(255, 255, 255, .9) inset, 0 44px 80px -24px rgba(15, 23, 42, .5), 0 20px 32px -20px rgba(15, 23, 42, .4), 0 0 0 1px rgba(15, 23, 42, .06); }
    .apv-card.sm { width: min(94vw, 460px); }
    .apv-card.danger { border-top-color: #dc3545; }
    .apv-card.success { border-top-color: #198754; }
    .apv-sum { background: #f8fafc; border-radius: 12px; padding: .75rem 1rem; }
    .apv-sum > div { display: flex; justify-content: space-between; gap: 1rem; padding: .25rem 0; font-size: .9rem; color: #475569; }
    .apv-sum > div span:last-child { font-weight: 600; color: #0f172a; text-align: right; }
    .apv-head { display: flex; justify-content: space-between; align-items: flex-start; gap: 1rem; padding: 1.15rem 1.5rem .9rem; border-bottom: 1px solid #e2e8f0; }
    .apv-head h5 { margin: .35rem 0 0; font-size: 1.35rem; font-weight: 700; color: #0f172a; }
    .apv-chip { display: inline-block; padding: .15rem .65rem; border-radius: 999px; font-size: .75rem; font-weight: 600; margin-right: .35rem; }
    .apv-chip.type { background: #f1f5f9; color: #334155; }
    .apv-chip.wait { background: #fff1ea; color: #c2410c; }
    .apv-chip.wait.returned { background: #fef3c7; color: #92400e; }
    .apv-x { border: 0; background: transparent; color: #64748b; font-size: 1.1rem; line-height: 1; padding: .35rem; border-radius: 8px; }
    .apv-x:hover { background: #f1f5f9; color: #0f172a; }
    .apv-body { flex: 1 1 auto; min-height: 0; overflow-y: auto; padding: 1.15rem 1.5rem 1.25rem; }
    .apv-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: .95rem 1.5rem; }
    .apv-l { font-size: .75rem; color: #64748b; margin-bottom: .1rem; }
    .apv-v { font-size: .95rem; font-weight: 600; color: #0f172a; word-break: break-word; }
    .apv-sec { margin: 1.35rem 0 .5rem; font-size: .85rem; font-weight: 700; color: #0f172a; }
    .apv-tbl { width: 100%; border-collapse: collapse; font-size: .9rem; font-variant-numeric: tabular-nums; }
    .apv-tbl th { font-size: .75rem; font-weight: 600; color: #64748b; padding: .4rem .5rem; border-bottom: 1px solid #e2e8f0; text-align: left; }
    .apv-tbl td { padding: .55rem .5rem; border-bottom: 1px solid #f1f5f9; color: #0f172a; }
    .apv-tbl .r { text-align: right; }
    .apv-tbl tfoot td { font-weight: 700; border-bottom: 0; background: #f8fafc; }
    .apv-tot { margin-top: 1rem; margin-left: auto; width: min(100%, 320px); font-variant-numeric: tabular-nums; }
    .apv-tot > div { display: flex; justify-content: space-between; padding: .25rem 0; font-size: .9rem; color: #475569; }
    .apv-tot > div.main { margin-top: .35rem; padding-top: .55rem; border-top: 1px solid #e2e8f0; font-size: 1rem; font-weight: 700; color: #0f172a; }
    .apv-dl > div { padding: .3rem 0; font-size: .9rem; color: #0f172a; }
    .apv-rem { white-space: pre-wrap; font-size: .9rem; color: #334155; background: #f8fafc; border-radius: 10px; padding: .7rem .85rem; }
    .apv-rem.returned { background: #fffbeb; border: 1px solid #fde68a; color: #92400e; }
    .apv-foot { display: flex; justify-content: space-between; align-items: center; gap: .75rem; flex-wrap: wrap; padding: .85rem 1.5rem; border-top: 1px solid #e2e8f0; background: #f8fafc; }
    @keyframes apvFade { from { opacity: 0; } to { opacity: 1; } }
    @keyframes apvRise { from { opacity: 0; transform: translateY(28px) scale(.94); } to { opacity: 1; transform: none; } }
    @keyframes apvFloat { 0%, 100% { transform: translateY(0); } 50% { transform: translateY(-5px); } }
    @media (max-width: 575px) { .apv-grid { grid-template-columns: minmax(0, 1fr); } }
    @media (prefers-reduced-motion: reduce) {
        .apv-float { animation: none; }
        .apv-card { transition: none; }
    }
</style>

<div class="row justify-content-center">
    <div class="col-12">
        {{-- No breadcrumb, badge or heading: the page name is in the top bar,
             and the two queue tabs open the page. --}}

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
                    <table class="table table-center table-hover align-middle mb-0">
                        <thead class="bg-light">
                            <tr>
                                <th class="py-3">ATL Number</th>
                                <th class="py-3">Sales Order</th>
                                <th class="py-3">PO #</th>
                                <th class="py-3">Pick Up Date</th>
                                <th class="py-3">Volume</th>
                                <th class="py-3">Prepared By</th>
                                <th class="py-3">Driver / Plate</th>
                                <th class="py-3 table-actions">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($pendingAtls as $atl)
                                @php
                                    // Everything the review card shows, formatted here so the
                                    // browser only has to display it. Same shape the PO rows
                                    // use (a data-* payload read by the shared card), plus a
                                    // kind so one card can render either document.
                                    $atlPoNumbers = $atl->allocations->pluck('purchaseOrder')->filter()
                                        ->pluck('po_number')->unique();

                                    $atlBreakdown = collect($atl->productBreakdown());
                                    if ($atlBreakdown->isEmpty() && $atl->product) {
                                        $atlBreakdown = collect([$atl->product => (int) $atl->qty_to_receive]);
                                    }

                                    $atlPayload = [
                                        'kind' => 'atl',
                                        'id' => $atl->id,
                                        'atlNumber' => $atl->atl_number ?: ($atl->client_atl_number ?: null),
                                        'salesOrder' => $atl->order?->formatted_so_number ?? $atl->refNumber(),
                                        'account' => $atl->order?->account ?? ($atl->stockRequest->warehouse->name ?? null),
                                        'poNumbers' => $atlPoNumbers->isNotEmpty()
                                            ? $atlPoNumbers->join(', ')
                                            : ($atl->purchaseOrder->po_number ?? null),
                                        'pickUpDate' => $atl->receiving_date?->format('M d, Y'),
                                        'volume' => number_format((int) $atl->qty_to_receive),
                                        'preparedBy' => $atl->preparer->name ?? 'Purchasing',
                                        'driver' => $atl->driver_name,
                                        'plate' => $atl->plate_number,
                                        'products' => $atlBreakdown->map(fn ($qty, $product) => [
                                            'product' => $product,
                                            'qty' => number_format((int) $qty),
                                        ])->values(),
                                        'remarks' => $atl->additional_remarks,
                                    ];
                                @endphp
                                <tr data-atl="{{ json_encode($atlPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}">
                                    <td class="fw-bold text-dark">
                                        {{ $atl->atl_number ?: '—' }}
                                        <div class="small text-muted">
                                            {{-- A row that exists was issued, whether or not the
                                                 legacy path stamped a date on it. Saying "Not yet
                                                 issued" on an ATL with a number was reading as a
                                                 duplicate of the record beside it. --}}
                                            @if ($atl->issued_at)
                                                Issued {{ $atl->issued_at->format('M d, Y') }}
                                            @elseif ($atl->atl_number)
                                                Issued {{ ($atl->created_at ?? now())->format('M d, Y') }}
                                            @else
                                                Not yet issued
                                            @endif
                                        </div>
                                    </td>
                                    <td>
                                        @if ($atl->order)
                                            <a href="{{ route('stock-orders.atls.show', $atl->order->id) }}" class="text-decoration-none">
                                                {{ $atl->order->formatted_so_number }}
                                            </a>
                                            <div class="small text-muted">{{ $atl->order->account }}</div>
                                        @elseif ($atl->stockRequest)
                                            {{-- A stock-request ATL has no sales order and no
                                                 so_number, so it used to show a dash where
                                                 the reference belongs. --}}
                                            <a href="{{ route('stock-orders.wet-stock-requests.atls', $atl->stockRequest->id) }}"
                                               class="text-decoration-none">
                                                {{ $atl->refNumber() }}
                                            </a>
                                            <div class="small text-muted">{{ $atl->stockRequest->warehouse->name ?? '' }}</div>
                                        @else
                                            <span class="text-muted">{{ $atl->so_number ?: '—' }}</span>
                                        @endif
                                    </td>
                                    <td class="small">
                                        {{ $atlPoNumbers->isNotEmpty() ? $atlPoNumbers->join(', ') : ($atl->purchaseOrder->po_number ?? '—') }}
                                    </td>
                                    <td class="small">{{ $atl->receiving_date ? $atl->receiving_date->format('M d, Y') : '—' }}</td>
                                    <td class="fw-bold">{{ number_format($atl->qty_to_receive) }} L</td>
                                    <td class="small">{{ $atl->preparer->name ?? 'Purchasing' }}</td>
                                    <td class="small">
                                        {{ $atl->driver_name ?: '—' }}
                                        @if ($atl->plate_number)
                                            <div class="text-muted">{{ $atl->plate_number }}</div>
                                        @endif
                                    </td>
                                    <td class="table-actions">
                                        {{-- The row offers View only. Approving and rejecting happen
                                             inside the review card, which drives the hidden forms
                                             below, so the VP reads the whole ATL first. The forms
                                             are not drawn for roles that can only get a 403. --}}
                                        <button type="button" class="btn btn-sm btn-action btn-outline-secondary rounded-pill px-3 shadow-sm" onclick="openAtlCard(this.closest('tr'))">
                                            <i class="bi bi-eye me-1"></i> View
                                        </button>
                                        @if (Auth::user()->canApproveStockOrders())
                                            <form method="POST" id="atl-approve-{{ $atl->id }}" action="{{ route('stock-orders.atl-approve', $atl->id) }}" class="d-none">
                                                @csrf
                                                <button type="submit">Approve</button>
                                            </form>
                                            <form method="POST" id="atl-reject-{{ $atl->id }}" action="{{ route('stock-orders.atl-reject', $atl->id) }}" class="d-none">
                                                @csrf
                                                <input type="hidden" name="rejection_reason" value="">
                                            </form>
                                        @endif
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
                    <table class="table table-center table-hover align-middle mb-0">
                        <thead class="bg-light">
                            <tr>
                                <th class="py-3">PO Number</th>
                                <th class="py-3">Type</th>
                                <th class="py-3">Supplier / Source</th>
                                <th class="py-3">Site / Channel</th>
                                <th class="py-3">Volume</th>
                                <th class="py-3">Prepared By</th>
                                <th class="py-3">Delivery / ATL Breakdown</th>
                                <th class="py-3 table-actions">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($pendingOrders as $order)
                                @php
                                    // Where this PO stands in the approval flow:
                                    //  returned - the VP sent it back and Purchasing has not resubmitted it
                                    //  revised  - Purchasing resubmitted it after a rejection
                                    //  pending  - waiting on the VP for the first time
                                    $state = $order->request_status === 'REQUESTED'
                                        ? 'returned'
                                        : ($order->rejected_at ? 'revised' : 'pending');

                                    // Everything the review card shows, formatted here so the
                                    // browser only has to display it.
                                    $reviewItems = $order->items->map(function ($i) {
                                        $price = (float) $i->unit_price;
                                        $qty = (int) $i->quantity_ordered;
                                        return [
                                            'product' => $i->product,
                                            'qty' => number_format($qty),
                                            'price' => $price > 0 ? number_format($price, 2) : null,
                                            'amount' => $price > 0 ? number_format($qty * $price, 2) : null,
                                        ];
                                    })->values();

                                    $reviewDeliveries = $order->deliveries->map(fn ($del) => [
                                        'product' => $del->product,
                                        'qty' => number_format((int) $del->qty_to_receive),
                                        'ref' => $del->atl_number ?: ($del->client_atl_number ?: $del->dr_number),
                                    ])->values();

                                    $reviewPayload = [
                                        'id' => $order->id,
                                        'po' => $order->po_number,
                                        'state' => $state,
                                        'lastReason' => $state !== 'pending' ? $order->rejection_reason : null,
                                        'rejectedAt' => $state !== 'pending' ? $order->rejected_at?->format('M d, Y g:i A') : null,
                                        // A standard PO carries no type label in the card; only Fuel Trade and Buy Back are named.
                                        'type' => ($order->isFuelTrade() || $order->isBuyBack()) ? $order->typeLabel() : null,
                                        'vendorLabel' => $order->supplier_name ? 'Vendor Company' : 'Client',
                                        'vendor' => $order->supplier_name ?: ($order->client->name ?? null),
                                        'address' => $order->supplier->location ?? null,
                                        'attention' => $order->attention,
                                        'terms' => $order->terms,
                                        'date' => ($order->po_date ?? $order->created_at)?->format('M d, Y'),
                                        'site' => $order->warehouse->name ?? ($order->isFuelTrade() ? 'Direct to Client' : null),
                                        'preparedBy' => $order->requester->name ?? 'Purchasing',
                                        'items' => $reviewItems,
                                        'deliveries' => $reviewDeliveries,
                                        'totalQty' => number_format((int) $order->qty_ordered),
                                        'hasAmounts' => (float) $order->total_amount > 0,
                                        'total' => number_format((float) $order->total_amount, 2),
                                        'vatable' => number_format((float) $order->vatable_sales_amount, 2),
                                        'vat' => number_format((float) $order->vat_amount, 2),
                                        'lessTax' => number_format((float) $order->less_w_tax, 2),
                                        'net' => number_format((float) $order->net_payable_amount, 2),
                                        'remarks' => $order->remarks,
                                    ];
                                @endphp
                                <tr data-po="{{ json_encode($reviewPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}">
                                    <td class="ps-3 fw-bold text-dark">
                                        {{ $order->po_number }}
                                        @if ($state === 'returned')
                                            <div><span class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle rounded-pill">Returned to Purchasing</span></div>
                                        @elseif ($state === 'revised')
                                            <div><span class="badge bg-primary-subtle text-primary border border-primary-subtle rounded-pill">Revised</span></div>
                                        @endif
                                    </td>
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
                                    <td class="fw-bold">{{ number_format($order->qty_ordered) }} L</td>
                                    <td class="small">{{ $order->requester->name ?? 'Purchasing' }}</td>
                                    <td class="small">
                                        @foreach ($order->deliveries as $del)
                                            <div>
                                                <i class="bi bi-truck me-1"></i>{{ $del->product }}: {{ number_format($del->qty_to_receive) }}L
                                                ({{ $del->atl_number ?: ($del->client_atl_number ?: $del->dr_number) }})
                                            </div>
                                        @endforeach
                                    </td>
                                    <td class="table-actions">
                                        {{-- The row offers View only. Approving and rejecting happen inside the
                                             review card, which drives these hidden forms so the confirmation
                                             prompt and permissions stay as they were. A returned PO has no
                                             forms at all: nobody rules on it until Purchasing resubmits it. --}}
                                        <button type="button" class="btn btn-sm btn-action btn-outline-secondary rounded-pill px-3 shadow-sm" onclick="openPoCard(this.closest('tr'))">
                                            <i class="bi bi-eye me-1"></i> View
                                        </button>
                                        @if (Auth::user()->canApproveStockOrders() && $state !== 'returned')
                                            <form method="POST" id="po-approve-{{ $order->id }}" action="{{ route('stock-orders.approve', $order->id) }}" class="d-none">
                                                @csrf
                                                <button type="submit">Approve</button>
                                            </form>
                                            <form method="POST" id="po-reject-{{ $order->id }}" action="{{ route('stock-orders.reject', $order->id) }}" class="d-none">
                                                @csrf
                                                <input type="hidden" name="rejection_reason" value="">
                                            </form>
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

{{-- Review card, shared by both queues. The row's data-* payload says whether it is
     a PO or an ATL, and the card renders the fields that document needs while
     keeping one set of styles, one footer and one set of handlers. Filled from
     the clicked row, so it never needs a page load. --}}
<div class="apv-overlay" id="apvOverlay" role="dialog" aria-modal="true" aria-labelledby="apvTitle" aria-hidden="true">
    <div class="apv-scene">
        <div class="apv-float">
            <div class="apv-card" id="apvCard">
                <div class="apv-head">
                    <div>
                        <span class="apv-chip type" id="apvType"></span><span class="apv-chip wait" id="apvWait">Waiting for VP approval</span>
                        <h5 id="apvTitle"></h5>
                    </div>
                    <button type="button" class="apv-x" id="apvX" aria-label="Close"><i class="bi bi-x-lg"></i></button>
                </div>
                <div class="apv-body" id="apvBody"></div>
                <div class="apv-foot">
                    <button type="button" class="btn btn-light border rounded-pill px-4" id="apvClose">Close</button>
                    <div class="d-inline-flex gap-2 align-items-center" id="apvActions"></div>
                </div>
            </div>
        </div>
    </div>
</div>

{{-- Reason popup. It opens on top of the review card. --}}
<div class="apv-overlay rj" id="apvRejectOverlay" role="dialog" aria-modal="true" aria-labelledby="apvRejectTitle" aria-hidden="true">
    <div class="apv-scene">
        <div class="apv-float">
            <div class="apv-card sm danger" id="apvRejectCard">
                <div class="apv-head">
                    <div>
                        <h5 id="apvRejectTitle">Reject PO</h5>
                        <div class="text-muted small mt-1" id="apvRejectNote">Tell Purchasing what needs to change. Only the person who raised this PO will see your note.</div>
                    </div>
                </div>
                <div class="apv-body">
                    <label for="apvReason" class="form-label fw-semibold small">Reason</label>
                    <textarea id="apvReason" class="form-control" rows="4" maxlength="1000" placeholder="e.g. The price per liter does not match the quotation."></textarea>
                    <div class="text-end text-muted small mt-1"><span id="apvReasonCount">0</span>/1000</div>
                </div>
                <div class="apv-foot">
                    <button type="button" class="btn btn-light border rounded-pill px-4" id="apvRejectCancel">Cancel</button>
                    <button type="button" class="btn btn-danger rounded-pill px-4" id="apvRejectConfirm" disabled>Confirm rejection</button>
                </div>
            </div>
        </div>
    </div>
</div>

{{-- Approval confirmation. It opens on top of the review card in place of the browser's confirm box. --}}
<div class="apv-overlay rj" id="apvApproveOverlay" role="dialog" aria-modal="true" aria-labelledby="apvApproveTitle" aria-hidden="true">
    <div class="apv-scene">
        <div class="apv-float">
            <div class="apv-card sm success" id="apvApproveCard">
                <div class="apv-head">
                    <div>
                        <h5 id="apvApproveTitle">Approve PO</h5>
                        <div class="text-muted small mt-1" id="apvApproveNote">Once approved, this purchase order can no longer be edited.</div>
                    </div>
                </div>
                <div class="apv-body">
                    <div class="apv-sum" id="apvApproveSummary"></div>
                </div>
                <div class="apv-foot">
                    <button type="button" class="btn btn-light border rounded-pill px-4" id="apvApproveCancel">Cancel</button>
                    <button type="button" class="btn btn-success rounded-pill px-4" id="apvApproveConfirm"><i class="bi bi-check-lg me-1"></i> Confirm approval</button>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
(function () {
    const overlay = document.getElementById('apvOverlay');
    if (!overlay) return;

    const card = document.getElementById('apvCard');
    const body = document.getElementById('apvBody');
    const actions = document.getElementById('apvActions');
    const rejectOverlay = document.getElementById('apvRejectOverlay');
    const rejectCard = document.getElementById('apvRejectCard');
    const reasonBox = document.getElementById('apvReason');
    const reasonCount = document.getElementById('apvReasonCount');
    const rejectConfirm = document.getElementById('apvRejectConfirm');
    const approveOverlay = document.getElementById('apvApproveOverlay');
    const approveCard = document.getElementById('apvApproveCard');
    const approveConfirm = document.getElementById('apvApproveConfirm');
    const peso = '\u20B1';
    let currentRow = null;
    let rejectData = null;
    let lastFocus = null;
    let rejectLastFocus = null;
    let approveData = null;
    let approveLastFocus = null;

    // Every value shown comes from the database, so it is escaped before it is placed in the card.
    function esc(value) {
        return String(value === null || value === undefined ? '' : value).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    // A row carries either a PO payload or an ATL payload; both are shown by the
    // same card, which asks the payload which document it is holding.
    function readRow(row) {
        try { return JSON.parse(row.dataset.po || row.dataset.atl); } catch (e) { return null; }
    }

    // The page does not scroll behind an open popup.
    function syncScroll() {
        const open = overlay.classList.contains('show') || rejectOverlay.classList.contains('show') || approveOverlay.classList.contains('show');
        document.body.style.overflow = open ? 'hidden' : '';
    }

    function render(d) {
        const isAtl = d.kind === 'atl';
        const typeChip = document.getElementById('apvType');
        typeChip.textContent = isAtl ? '' : (d.type || '');
        typeChip.style.display = (!isAtl && d.type) ? '' : 'none';
        document.getElementById('apvTitle').textContent = isAtl
            ? (d.atlNumber || 'Not yet issued')
            : (d.po || '');

        const fields = (isAtl ? [
            ['ATL Number', d.atlNumber || 'Not yet issued'],
            ['Sales Order', d.salesOrder],
            ['Account / Depot', d.account],
            ['PO #', d.poNumbers],
            ['Pick Up Date', d.pickUpDate],
            ['Volume', d.volume ? d.volume + ' L' : null],
            ['Prepared by', d.preparedBy],
            ['Driver', d.driver],
            ['Plate Number', d.plate]
        ] : [
            [d.vendorLabel, d.vendor],
            ['Address', d.address],
            ['Attention', d.attention],
            ['Terms', d.terms],
            ['Date', d.date],
            ['Site / Channel', d.site],
            ['Prepared by', d.preparedBy]
        ]).filter(function (f) { return f[1]; });

        let html = '';

        // A returned or revised PO carries the VP's last note, so the reviewer
        // can see what was asked to change.
        if (d.state !== 'pending' && d.lastReason) {
            html += '<div class="apv-l mb-1">' + (d.state === 'returned' ? 'Returned by the VP' : 'Previously returned by the VP')
                + (d.rejectedAt ? ' \u00B7 ' + esc(d.rejectedAt) : '') + '</div>'
                + '<div class="apv-rem returned mb-3">' + esc(d.lastReason) + '</div>';
        }

        html += '<div class="apv-grid">' + fields.map(function (f) {
            return '<div><div class="apv-l">' + esc(f[0]) + '</div><div class="apv-v">' + esc(f[1]) + '</div></div>';
        }).join('') + '</div>';

        if (isAtl) {
            // The ATL card carries no prices: an ATL authorises a lift, it does not
            // commit an amount. Products and quantities are the whole table.
            if (d.products.length) {
                html += '<div class="apv-sec">Products</div>'
                    + '<table class="apv-tbl"><thead><tr><th>Product</th><th class="r">Quantity</th></tr></thead><tbody>'
                    + d.products.map(function (p) {
                        return '<tr><td>' + esc(p.product) + '</td>'
                            + '<td class="r">' + esc(p.qty) + ' L</td></tr>';
                    }).join('')
                    + '</tbody><tfoot><tr><td>Total Quantity</td><td class="r">' + esc(d.volume) + ' L</td></tr></tfoot></table>';
            } else {
                html += '<div class="apv-sec">Total Quantity</div><div class="apv-v">' + esc(d.volume) + ' L</div>';
            }

            if (!d.atlNumber) {
                html += '<div class="apv-rem returned mt-3"><i class="bi bi-info-circle me-1"></i>'
                    + 'DITC has not issued an ATL number yet. Record it on the ATL before approving.</div>';
            }
        } else if (d.items.length) {
            html += '<div class="apv-sec">Products</div>'
                + '<table class="apv-tbl"><thead><tr><th>Product</th><th class="r">Quantity</th><th class="r">Price</th><th class="r">Amount</th></tr></thead><tbody>'
                + d.items.map(function (i) {
                    return '<tr><td>' + esc(i.product) + '</td>'
                        + '<td class="r">' + esc(i.qty) + ' L</td>'
                        + '<td class="r">' + (i.price ? peso + esc(i.price) : '\u2014') + '</td>'
                        + '<td class="r">' + (i.amount ? peso + esc(i.amount) : '\u2014') + '</td></tr>';
                }).join('')
                + '</tbody><tfoot><tr><td>Total Quantity</td><td class="r" colspan="3">' + esc(d.totalQty) + ' L</td></tr></tfoot></table>';
        } else {
            html += '<div class="apv-sec">Total Quantity</div><div class="apv-v">' + esc(d.totalQty) + ' L</div>';
        }

        if (!isAtl) {
        if (d.hasAmounts) {
            html += '<div class="apv-tot"><div><span>Total Amount</span><span>' + peso + esc(d.total) + '</span></div>';
            if (d.vatable !== '0.00') html += '<div><span>Vatable Sales Amount</span><span>' + peso + esc(d.vatable) + '</span></div>';
            if (d.vat !== '0.00') html += '<div><span>VAT Amount</span><span>' + peso + esc(d.vat) + '</span></div>';
            if (d.lessTax !== '0.00') html += '<div><span>Less w/ Tax</span><span>' + peso + esc(d.lessTax) + '</span></div>';
            html += '<div class="main"><span>Net Payable Amount</span><span>' + peso + esc(d.net) + '</span></div></div>';
        }

        if (d.deliveries.length) {
            html += '<div class="apv-sec">Delivery / ATL breakdown</div><div class="apv-dl">'
                + d.deliveries.map(function (x) {
                    return '<div><i class="bi bi-truck me-1"></i>' + esc(x.product) + ': ' + esc(x.qty) + ' L' + (x.ref ? ' (' + esc(x.ref) + ')' : '') + '</div>';
                }).join('') + '</div>';
        }
        }

        if (d.remarks) {
            html += '<div class="apv-sec">Additional Remarks</div><div class="apv-rem">' + esc(d.remarks) + '</div>';
        }

        body.innerHTML = html;
        body.scrollTop = 0;

        // Approve reuses the form already on the row, so its confirmation prompt
        // and permissions stay exactly the same. Reject opens the reason popup.
        // A returned PO has no forms, so it can never be ruled on from here.
        actions.innerHTML = '';
        // Both queues drive the hidden forms already on the row, so permissions
        // and the confirmation prompts stay exactly where they were.
        const prefix = isAtl ? 'atl' : 'po';
        const approveForm = document.getElementById(prefix + '-approve-' + d.id);
        const rejectForm = document.getElementById(prefix + '-reject-' + d.id);
        const canRule = !!(approveForm && rejectForm);
        const wait = document.getElementById('apvWait');

        if (isAtl) {
            wait.classList.remove('returned');
            wait.textContent = canRule ? 'Waiting for your approval' : 'Waiting for VP approval';
        } else {
            const revised = d.state === 'revised' ? 'Revised \u00B7 ' : '';

            wait.classList.toggle('returned', d.state === 'returned');
            if (d.state === 'returned') {
                wait.textContent = 'Returned to Purchasing';
            } else {
                wait.textContent = revised + (canRule ? 'Waiting for your approval' : 'Waiting for VP approval');
            }
        }

        if (!isAtl && d.state === 'returned') {
            actions.innerHTML = '<span class="text-muted small">Waiting for Purchasing to revise and resubmit</span>';
        } else if (canRule) {
            const reject = document.createElement('button');
            reject.type = 'button';
            reject.className = 'btn btn-outline-danger rounded-pill px-4';
            reject.innerHTML = '<i class="bi bi-x-lg me-1"></i> Reject' + (isAtl ? ' ATL' : '');
            reject.onclick = function () { window.openReject(currentRow); };

            const approve = document.createElement('button');
            approve.type = 'button';
            approve.className = 'btn btn-success rounded-pill px-4';
            approve.innerHTML = '<i class="bi bi-check-lg me-1"></i> Approve ' + (isAtl ? 'ATL' : 'PO');
            approve.onclick = function () { window.openApprove(currentRow); };

            actions.appendChild(reject);
            actions.appendChild(approve);
        } else {
            actions.innerHTML = '<span class="text-muted small">Awaiting VP decision</span>';
        }
    }

    function openCard(row) {
        const data = readRow(row);
        if (!data) return;

        currentRow = row;
        lastFocus = document.activeElement;
        render(data);
        overlay.classList.add('show');
        overlay.setAttribute('aria-hidden', 'false');
        syncScroll();
        document.getElementById('apvClose').focus();
    }

    // Named per queue so each table keeps the onclick it already had.
    window.openPoCard = openCard;
    window.openAtlCard = openCard;

    function closePoCard() {
        overlay.classList.remove('show');
        overlay.setAttribute('aria-hidden', 'true');
        card.style.transform = '';
        syncScroll();
        if (lastFocus && lastFocus.focus) lastFocus.focus();
    }

    window.openReject = function (row) {
        const data = row ? readRow(row) : null;
        if (!data) return;

        const isAtl = data.kind === 'atl';
        rejectData = data;
        rejectLastFocus = document.activeElement;
        document.getElementById('apvRejectTitle').textContent = isAtl
            ? 'Reject ATL ' + (data.atlNumber || '#' + data.id)
            : 'Reject ' + data.po;
        document.getElementById('apvRejectNote').textContent = isAtl
            ? 'Tell Purchasing what needs to change on this ATL. The reason is kept in the audit log.'
            : 'Tell Purchasing what needs to change. Only the person who raised this PO will see your note.';
        reasonBox.value = '';
        reasonCount.textContent = '0';
        rejectConfirm.disabled = true;
        rejectConfirm.textContent = 'Confirm rejection';
        rejectOverlay.classList.add('show');
        rejectOverlay.setAttribute('aria-hidden', 'false');
        syncScroll();
        reasonBox.focus();
    };

    function closeReject() {
        rejectOverlay.classList.remove('show');
        rejectOverlay.setAttribute('aria-hidden', 'true');
        rejectCard.style.transform = '';
        syncScroll();
        if (rejectLastFocus && rejectLastFocus.focus) rejectLastFocus.focus();
    }

    window.openApprove = function (row) {
        const data = row ? readRow(row) : null;
        if (!data) return;

        const isAtl = data.kind === 'atl';
        approveData = data;
        approveLastFocus = document.activeElement;
        document.getElementById('apvApproveTitle').textContent = isAtl
            ? 'Approve ATL ' + (data.atlNumber || '#' + data.id)
            : 'Approve ' + data.po;
        document.getElementById('apvApproveNote').textContent = isAtl
            ? 'Once approved, this ATL can be lifted and its PDF downloaded.'
            : 'Once approved, this purchase order can no longer be edited.';

        let sum;

        if (isAtl) {
            sum = '<div><span>ATL Number</span><span>' + esc(data.atlNumber || 'Not yet issued') + '</span></div>';
            if (data.salesOrder) sum += '<div><span>Sales Order</span><span>' + esc(data.salesOrder) + '</span></div>';
            if (data.poNumbers) sum += '<div><span>PO #</span><span>' + esc(data.poNumbers) + '</span></div>';
            sum += '<div><span>Total Quantity</span><span>' + esc(data.volume) + ' L</span></div>';
        } else {
            sum = '<div><span>PO Number</span><span>' + esc(data.po) + '</span></div>';
            if (data.vendor) sum += '<div><span>' + esc(data.vendorLabel) + '</span><span>' + esc(data.vendor) + '</span></div>';
            sum += '<div><span>Total Quantity</span><span>' + esc(data.totalQty) + ' L</span></div>';
            if (data.hasAmounts) sum += '<div><span>Net Payable Amount</span><span>' + peso + esc(data.net) + '</span></div>';
        }

        document.getElementById('apvApproveSummary').innerHTML = sum;

        approveConfirm.disabled = false;
        approveConfirm.innerHTML = '<i class="bi bi-check-lg me-1"></i> Confirm approval';
        approveOverlay.classList.add('show');
        approveOverlay.setAttribute('aria-hidden', 'false');
        syncScroll();
        approveConfirm.focus();
    };

    function closeApprove() {
        approveOverlay.classList.remove('show');
        approveOverlay.setAttribute('aria-hidden', 'true');
        approveCard.style.transform = '';
        syncScroll();
        if (approveLastFocus && approveLastFocus.focus) approveLastFocus.focus();
    }

    approveConfirm.addEventListener('click', function () {
        if (!approveData) return;

        const prefix = approveData.kind === 'atl' ? 'atl' : 'po';
        const form = document.getElementById(prefix + '-approve-' + approveData.id);
        if (!form) return;

        approveConfirm.disabled = true;
        approveConfirm.textContent = 'Approving\u2026';
        form.submit();
    });

    document.getElementById('apvApproveCancel').addEventListener('click', closeApprove);

    reasonBox.addEventListener('input', function () {
        reasonCount.textContent = reasonBox.value.length;
        rejectConfirm.disabled = reasonBox.value.trim() === '';
    });

    rejectConfirm.addEventListener('click', function () {
        const reason = reasonBox.value.trim();
        if (!reason || !rejectData) return;

        const prefix = rejectData.kind === 'atl' ? 'atl' : 'po';
        const form = document.getElementById(prefix + '-reject-' + rejectData.id);
        if (!form) return;

        form.querySelector('input[name="rejection_reason"]').value = reason;
        rejectConfirm.disabled = true;
        rejectConfirm.textContent = 'Rejecting\u2026';
        form.submit();
    });

    document.getElementById('apvRejectCancel').addEventListener('click', closeReject);
    document.getElementById('apvClose').addEventListener('click', closePoCard);
    document.getElementById('apvX').addEventListener('click', closePoCard);

    // Clicking outside closes the review card. The reason popup ignores it so a
    // typed note is not lost by accident; Cancel and Escape close it.
    overlay.addEventListener('mousedown', function (e) {
        if (e.target === overlay || e.target.classList.contains('apv-scene')) closePoCard();
    });

    document.addEventListener('keydown', function (e) {
        if (e.key !== 'Escape') return;
        if (approveOverlay.classList.contains('show')) { closeApprove(); return; }
        if (rejectOverlay.classList.contains('show')) { closeReject(); return; }
        if (overlay.classList.contains('show')) closePoCard();
    });

    // The cards tilt slightly toward the pointer.
    function tilt(el, max) {
        el.addEventListener('mousemove', function (e) {
            const r = el.getBoundingClientRect();
            const x = (e.clientX - r.left) / r.width - 0.5;
            const y = (e.clientY - r.top) / r.height - 0.5;
            el.style.transform = 'rotateY(' + (x * max) + 'deg) rotateX(' + (-y * max) + 'deg)';
        });
        el.addEventListener('mouseleave', function () { el.style.transform = ''; });
    }
    tilt(card, 5);
    tilt(rejectCard, 5);
    tilt(approveCard, 5);
})();
</script>
@endsection