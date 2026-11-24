@extends('layouts.app')

@section('title', 'Purchase Orders Dashboard')

@push('page-actions')
    {{-- Both icons are always drawn. The bell never hides when there is nothing
         to read; the red badge only appears while feedback is unread. --}}
    <button type="button" class="btn btn-sm btn-light border position-relative pn-icon" onclick="openPn('pnHistory')" title="History" aria-label="History">
        <i class="bi bi-clock-history"></i>
    </button>
    <button type="button" class="btn btn-sm btn-light border position-relative pn-icon" onclick="openPn('pnBell')" title="Notifications" aria-label="Notifications">
        <i class="bi bi-bell"></i>
        @if (($rejectionCount ?? 0) > 0)
            <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger">{{ $rejectionCount }}</span>
        @endif
    </button>
@endpush

@section('content')
<div class="row justify-content-center">
    <div class="col-12">
        {{-- No breadcrumb and no page heading: the page name lives in the top
             bar, and the stat cards open the page the way the Orders Dashboard
             does. --}}

        @php
            // Product chip colours: U = Unleaded, D = Diesel, P = Premium.
            // Kept identical to the Orders Dashboard so the two read as one set.
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

            $statCards = [
                ['title' => 'Total Qty. Ordered', 'icon' => 'bi-boxes', 'tint' => 'background:#ecfeff;color:#0891b2;', 'data' => $stats['volumes']['ordered']],
                ['title' => 'Total Qty. Lifted', 'icon' => 'bi-truck', 'tint' => 'background:#f0fdf4;color:#16a34a;', 'data' => $stats['volumes']['lifted']],
                ['title' => 'Total Qty. Unlifted', 'icon' => 'bi-clock-history', 'tint' => 'background:#fffbeb;color:#d97706;', 'data' => $stats['volumes']['unlifted']],
            ];

            // The same soft badge colours the Orders Dashboard uses for its status badges.
            $badgeWait = 'bg-warning-subtle text-warning-emphasis border border-warning-subtle';
            $badgeOk = 'bg-success-subtle text-success border border-success-subtle';
            $badgeRet = 'bg-danger-subtle text-danger border border-danger-subtle';
            $badgeNone = 'bg-secondary-subtle text-secondary border border-secondary-subtle';

            $returnedPos = $returnedPos ?? collect();
            $historyEvents = $historyEvents ?? collect();
        @endphp
        <style>
            .stat-title {
                font-size: 0.72rem;
                font-weight: 700;
                letter-spacing: 0.05em;
                text-transform: uppercase;
                color: #374151;
            }
            .stat-icon {
                width: 46px;
                height: 46px;
                border-radius: 0.8rem;
                display: flex;
                align-items: center;
                justify-content: center;
                font-size: 1.3rem;
                flex-shrink: 0;
            }
            .stat-row {
                display: flex;
                justify-content: space-between;
                align-items: center;
                border: 1px solid #e5e7eb;
                border-radius: 0.6rem;
                padding: 0.45rem 0.8rem;
                margin-bottom: 0.45rem;
                background: #fff;
            }
            .stat-row .stat-val { font-weight: 700; color: #111827; }
            .stat-total {
                display: flex;
                justify-content: space-between;
                padding: 0.25rem 0.3rem 0;
                color: #6b7280;
            }
            .stat-total .stat-val { font-weight: 600; color: #111827; }
            .stat-big { font-size: 3.6rem; font-weight: 800; line-height: 1; color: #111827; }
            .prod-chip {
                display: inline-block;
                font-size: 0.65rem;
                font-weight: 700;
                line-height: 1;
                padding: 0.2rem 0.4rem;
                border-radius: 0.35rem;
                margin: 0 1px;
            }

            /* Top-right icons and the floating cards they open. */
            .pn-icon { width: 36px; height: 32px; display: inline-flex; align-items: center; justify-content: center; padding: 0; color: #475569; }
            .po-table th, .po-table td { text-align: center; }
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
            .pn-item[hidden], .pn-ev[hidden], .pn-pager[hidden] { display: none !important; }
            .pn-pager { display: flex; align-items: center; gap: .6rem; }
            .pn-pg-btn { border: 1px solid #e2e8f0; background: #fff; color: #334155; border-radius: 999px; padding: .25rem .75rem; font-size: .8rem; line-height: 1.4; }
            .pn-pg-btn:hover:not(:disabled) { background: #f1f5f9; }
            .pn-pg-btn:disabled { opacity: .4; cursor: default; }
            .pn-dots { display: flex; align-items: center; gap: .4rem; }
            .pn-dot { width: 8px; height: 8px; padding: 0; border: 0; border-radius: 999px; background: #cbd5e1; transition: width .18s ease, background-color .18s ease; }
            .pn-dot:hover { background: #94a3b8; }
            .pn-dot.active { width: 20px; background: #ff5a1f; }
            .pn-empty { text-align: center; color: #64748b; padding: 2rem 0; font-size: .92rem; }
            .pn-item { display: block; padding: .8rem .2rem; border-bottom: 1px solid #eef2f7; text-decoration: none; color: inherit; }
            .pn-item:last-child { border-bottom: 0; }
            .pn-item:hover .pn-po { text-decoration: underline; }
            .pn-po { font-weight: 700; color: #0f172a; }
            .pn-new { display: inline-block; width: 8px; height: 8px; border-radius: 50%; background: #dc3545; margin-left: .4rem; vertical-align: middle; }
            .pn-meta { font-size: .78rem; color: #64748b; margin-top: .1rem; }
            .pn-reason { margin-top: .45rem; white-space: pre-wrap; font-size: .9rem; color: #334155; background: #f8fafc; border-radius: 10px; padding: .6rem .75rem; }
            .pn-ev { display: flex; gap: .75rem; padding: .7rem 0; border-bottom: 1px solid #eef2f7; }
            .pn-ev:last-child { border-bottom: 0; }
            .pn-ev-dot { flex: 0 0 auto; width: 10px; height: 10px; border-radius: 50%; margin-top: .4rem; background: #94a3b8; }
            .pn-ev-dot.approved { background: #16a34a; }
            .pn-ev-dot.returned { background: #dc3545; }
            @keyframes pnFade { from { opacity: 0; } to { opacity: 1; } }
            @keyframes pnRise { from { opacity: 0; transform: translateY(28px) scale(.94); } to { opacity: 1; transform: none; } }
            @keyframes pnFloat { 0%, 100% { transform: translateY(0); } 50% { transform: translateY(-5px); } }
            @media (prefers-reduced-motion: reduce) {
                .pn-float { animation: none; }
                .pn-card { transition: none; }
            }
        </style>

        {{-- Stat cards: one headline count, then volume split by product. --}}
        <div class="row g-4 mb-4">
            <div class="col-xl-3 col-md-6">
                <div class="card card-custom p-3 border-0 h-100">
                    <div class="d-flex align-items-center gap-3">
                        <div class="stat-icon" style="background:#fff1ee;color:#f04a1a;"><i class="bi bi-journal-text"></i></div>
                        <span class="stat-title">Total Orders</span>
                    </div>
                    <div class="d-flex flex-grow-1 align-items-center justify-content-center py-3">
                        <span class="stat-big">{{ number_format($stats['total']) }}</span>
                    </div>
                </div>
            </div>

            @foreach ($statCards as $card)
                <div class="col-xl-3 col-md-6">
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

        {{-- The search and Create P.O. sit directly above the table rather than
             in the page header, so each reads as a control for the columns it
             affects: the search over PO # and Supplier, the button over Action.
             Approvals and Deliveries/ATLs used to sit here too; both are in the
             left navigation now, which is where they are role-gated.

             One row, deliberately not wrapping. Wrapping is what pushed the
             Search button under the input and dropped Create P.O. onto a line
             of its own, so the input group is given an explicit width and keeps
             the button beside it. --}}
        <div class="d-flex justify-content-between align-items-center gap-2 mb-3">
            <form method="GET" action="{{ route('stock-orders.purchase-orders.index') }}"
                  class="d-flex gap-2 align-items-center">
                <div class="input-group flex-shrink-0" style="width: 320px;">
                    <span class="input-group-text bg-white border-end-0">
                        <i class="bi bi-search text-muted"></i>
                    </span>
                    <input type="text" name="search" class="form-control border-start-0"
                           placeholder="Search PO # or Supplier..." value="{{ request('search') }}">
                </div>
                <button type="submit" class="btn btn-primary-custom">Search</button>
                @if (request('search'))
                    <a href="{{ route('stock-orders.purchase-orders.index') }}"
                       class="btn btn-outline-secondary">Reset</a>
                @endif
            </form>

            @if (Auth::user()->canEditStockOrders())
                <a href="{{ route('stock-orders.create-supplier-po') }}" class="btn btn-primary-custom shadow-sm">
                    <i class="bi bi-plus-lg me-1"></i> Create P.O.
                </a>
            @endif
        </div>

        {{-- Purchase Orders list. It uses the same table-custom class, cell markup and
             badge colours as the Orders Dashboard in Module 1, so the shared stylesheet
             gives both lists the same type scale. --}}
        <div class="card card-custom border-0 shadow-sm">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-custom table-hover align-middle mb-0 po-table">
                        <thead>
                            <tr>
                                <th>PO #</th>
                                <th>Supplier</th>
                                <th>Qty.</th>
                                <th>Price</th>
                                <th>Amount</th>
                                <th>Terms</th>
                                <th>Status</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($purchaseOrders as $po)
                                @php
                                    $approved = $po->isVpApproved();
                                    $cancelled = $po->isCancelled();

                                    // Status comes from the approver's side of the workflow:
                                    // a PO sent to the VP waits as "For Approval", becomes
                                    // "Approved" once the VP signs it, and is "Returned" if
                                    // the VP sent it back for changes. RECEIVED is this
                                    // codebase's pending-VP-approval state. A cancelled PO
                                    // is checked first so it never reads as approved.
                                    if ($cancelled) {
                                        $stLabel = 'Cancelled';
                                        $stClass = $badgeNone;
                                    } elseif ($approved) {
                                        $stLabel = 'Approved';
                                        $stClass = $badgeOk;
                                    } elseif ($po->request_status === 'REQUESTED' && ! empty($po->rejection_reason)) {
                                        $stLabel = 'Returned';
                                        $stClass = $badgeRet;
                                    } elseif ($po->request_status === 'RECEIVED') {
                                        $stLabel = 'For Approval';
                                        $stClass = $badgeWait;
                                    } else {
                                        $stLabel = $po->computed_status ?: 'Draft';
                                        $stClass = $badgeNone;
                                    }

                                    $termText = trim((string) $po->terms);

                                    // Legacy POs predate the stored total, so fall back to
                                    // summing their own line items.
                                    $amount = (float) ($po->total_amount
                                        ?: $po->items->sum(fn ($i) => $i->quantity_ordered * (float) $i->unit_price));
                                @endphp
                                <tr>
                                    <td class="fw-semibold">
                                        {{ $po->po_number ?: 'Pending PO#' }}
                                        @if ($po->linkedOrder)
                                            <div class="small text-muted fw-normal">SO: {{ $po->linkedOrder->so_number }}</div>
                                        @endif
                                    </td>
                                    <td>{{ $po->supplier_name ?: ($po->client->name ?? '—') }}</td>

                                    {{-- Qty: one line per product, the way the Orders Dashboard
                                         marks up its own. Legacy POs with no item rows fall back
                                         to the PO's single ordered volume. --}}
                                    <td class="text-center">
                                        <div class="pl-block">
                                            @forelse ($po->items as $it)
                                                <div class="pl-row" title="{{ $it->product }}">
                                                    <span class="pl-key">{!! $chip(\Illuminate\Support\Str::substr($it->product, 0, 1), $it->product) !!}</span>
                                                    <span class="pl-num">{{ number_format($it->quantity_ordered) }}</span>
                                                </div>
                                            @empty
                                                <div class="pl-row"><span class="pl-num">{{ number_format($po->qty_ordered) }}</span></div>
                                            @endforelse
                                        </div>
                                    </td>

                                    {{-- Price: one line per product --}}
                                    <td class="text-center">
                                        <div class="pl-block">
                                            @forelse ($po->items as $it)
                                                <div class="pl-row" title="{{ $it->product }}">
                                                    <span class="pl-key">{!! $chip(\Illuminate\Support\Str::substr($it->product, 0, 1), $it->product) !!}</span>
                                                    <span class="pl-num">{{ (float) $it->unit_price > 0 ? number_format((float) $it->unit_price, 2) : '—' }}</span>
                                                </div>
                                            @empty
                                                <div class="pl-row"><span class="pl-num">&mdash;</span></div>
                                            @endforelse
                                        </div>
                                    </td>

                                    {{-- Amount: total of the whole PO --}}
                                    <td class="text-nowrap">
                                        {{ $amount > 0 ? number_format($amount, 2) : '—' }}
                                    </td>

                                    {{-- Terms: text only, as on the Orders Dashboard --}}
                                    <td>
                                        <span class="terms-cell" title="{{ $termText }}">{{ $termText !== '' ? $termText : '—' }}</span>
                                    </td>

                                    <td class="text-center">
                                        <span class="badge rounded-pill px-2 py-1 {{ $stClass }}">{{ $stLabel }}</span>
                                    </td>

                                    <td>
                                        {{-- Exactly two actions besides View Detailed, and which two
                                             depends on where the PO is in its life: Print is
                                             released only once the VP has approved, and an
                                             approved PO can no longer be edited. So each state
                                             offers Print XOR Edit. A cancelled PO offers neither.
                                             The routes enforce the same thing, so this is a
                                             convenience rather than the gate. --}}
                                        <div class="d-flex justify-content-center gap-1 flex-wrap">
                                            @if ($cancelled)
                                                {{-- cancelled: View Detailed only --}}
                                            @elseif ($approved)
                                                <a href="{{ route('stock-orders.po-pdf', $po->id) }}"
                                                   class="btn btn-sm btn-outline-danger rounded-3 px-2 py-1 text-nowrap" title="Print Purchase Order">
                                                    <i class="bi bi-printer me-1"></i> Print
                                                </a>
                                            @elseif (Auth::user()->canEditStockOrders())
                                                <a href="{{ route('stock-orders.edit-supplier-po', $po->id) }}"
                                                   class="btn btn-sm btn-outline-secondary rounded-3 px-2 py-1 text-nowrap" title="Edit purchase order">
                                                    <i class="bi bi-pencil me-1"></i> Edit
                                                </a>
                                            @endif

                                            <a href="{{ route('stock-orders.purchase-orders.show', $po->id) }}"
                                               class="btn btn-sm btn-outline-primary rounded-3 px-2 py-1 text-nowrap" title="View detailed purchase order">
                                                <i class="bi bi-eye me-1"></i> View Detailed
                                            </a>

                                            @if (! $cancelled && Auth::user()->canApproveStockOrders())
                                                @if ($po->hasActiveAtl())
                                                    <span title="This purchase order has an active ATL. Cancel the ATL first.">
                                                        <button type="button" class="btn btn-sm btn-outline-secondary rounded-3 px-2 py-1 text-nowrap" disabled style="pointer-events:none;">
                                                            <i class="bi bi-x-circle me-1"></i> Cancel
                                                        </button>
                                                    </span>
                                                @else
                                                    <button type="button" class="btn btn-sm btn-outline-danger rounded-3 px-2 py-1 text-nowrap" title="Cancel purchase order"
                                                            data-action="{{ route('stock-orders.cancel', $po->id) }}"
                                                            data-po="{{ $po->po_number ?: 'Pending PO#' }}"
                                                            onclick="openCancelPn(this)">
                                                        <i class="bi bi-x-circle me-1"></i> Cancel
                                                    </button>
                                                @endif
                                            @endif
                                        </div>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="8" class="text-center py-5 text-muted">No stock orders found matching criteria.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                <div class="p-3">
                    {{ $purchaseOrders->links() }}
                </div>
            </div>
        </div>
    </div>
</div>

{{-- Notifications: purchase orders returned to the signed-in requester, with the reason.
     The list only ever holds the viewer's own POs, so nobody else sees a reason. --}}
<div class="pn-overlay" id="pnBell" role="dialog" aria-modal="true" aria-labelledby="pnBellTitle" aria-hidden="true">
    <div class="pn-scene">
        <div class="pn-float">
            <div class="pn-card">
                <div class="pn-head">
                    <h5 id="pnBellTitle">Notifications</h5>
                    <button type="button" class="pn-x" data-pn-close aria-label="Close"><i class="bi bi-x-lg"></i></button>
                </div>
                <div class="pn-body" data-pn-per="3">
                    @forelse ($returnedPos as $r)
                        <a href="{{ route('stock-orders.purchase-orders.show', $r->id) }}" class="pn-item">
                            <div>
                                <span class="pn-po">{{ $r->po_number ?: 'Pending PO#' }}</span>
                                <span class="text-muted small">was returned for changes</span>
                                @if (! $r->rejection_seen_at)<span class="pn-new" title="Not read yet"></span>@endif
                            </div>
                            <div class="pn-meta">
                                {{ $r->rejecter_name ? 'By '.$r->rejecter_name : 'By the approver' }}@if ($r->rejected_at) on {{ \Illuminate\Support\Carbon::parse($r->rejected_at)->format('M d, Y g:i A') }}@endif
                            </div>
                            <div class="pn-reason">{{ $r->rejection_reason }}</div>
                        </a>
                    @empty
                        <div class="pn-empty">
                            <i class="bi bi-bell fs-3 d-block mb-2"></i>
                            No notifications. When one of your purchase orders is returned, the reason shows here.
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

{{-- History: recent created, approved and returned events across purchase orders. Reasons are never shown here. --}}
<div class="pn-overlay" id="pnHistory" role="dialog" aria-modal="true" aria-labelledby="pnHistoryTitle" aria-hidden="true">
    <div class="pn-scene">
        <div class="pn-float">
            <div class="pn-card">
                <div class="pn-head">
                    <h5 id="pnHistoryTitle">History</h5>
                    <button type="button" class="pn-x" data-pn-close aria-label="Close"><i class="bi bi-x-lg"></i></button>
                </div>
                <div class="pn-body" data-pn-per="6">
                    @php
                        $evText = ['created' => 'was created', 'approved' => 'was approved', 'returned' => 'was returned for changes'];
                    @endphp
                    @forelse ($historyEvents as $e)
                        <div class="pn-ev">
                            <span class="pn-ev-dot {{ $e['type'] }}"></span>
                            <div>
                                <div>
                                    <a href="{{ route('stock-orders.purchase-orders.show', $e['id']) }}" class="pn-po text-decoration-none">{{ $e['po'] ?: 'Pending PO#' }}</a>
                                    <span class="text-muted">{{ $e['label'] ?? ($evText[$e['type']] ?? $e['type']) }}</span>
                                </div>
                                <div class="pn-meta">{{ $e['by'] ? 'By '.$e['by'].' on ' : 'On ' }}{{ $e['at']->format('M d, Y g:i A') }}</div>
                                @if (!empty($e['note']))
                                    <div class="small mt-1" style="white-space: pre-wrap;">{{ $e['note'] }}</div>
                                @endif
                            </div>
                        </div>
                    @empty
                        <div class="pn-empty">
                            <i class="bi bi-clock-history fs-3 d-block mb-2"></i>
                            Nothing has happened on purchase orders yet.
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

{{-- Cancel confirmation. One shared card; the Cancel button that opens it supplies the PO and the action URL. --}}
<div class="pn-overlay" id="pnCancel" role="dialog" aria-modal="true" aria-labelledby="pnCancelTitle" aria-hidden="true">
    <div class="pn-scene">
        <div class="pn-float">
            <div class="pn-card">
                <div class="pn-head">
                    <h5 id="pnCancelTitle">Cancel purchase order?</h5>
                    <button type="button" class="pn-x" data-pn-close aria-label="Close"><i class="bi bi-x-lg"></i></button>
                </div>
                <form method="POST" action="" id="pnCancelForm" onsubmit="this.querySelector('button[type=submit]').disabled = true;">
                    @csrf
                    <div class="pn-body">
                        <p class="mb-2">You are about to cancel purchase order <strong id="pnCancelPo"></strong>.</p>
                        <p class="text-muted small mb-0">It will no longer count as approved, it cannot be printed or edited, and it is left out of the totals.</p>
                    </div>
                    <div class="pn-foot">
                        <button type="submit" class="btn btn-danger rounded-pill px-4">Yes, cancel PO</button>
                        <button type="button" class="btn btn-light border rounded-pill px-4" data-pn-close>Keep PO</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
(function () {
    // ---- History and notification cards ----
    let openId = null;
    let lastFocus = null;

    // Pages the items of a card: Prev / Next buttons and one dot per page.
    // The card keeps the height of its tallest page so it does not jump.
    function setupPager(el) {
        const body = el.querySelector('.pn-body');
        const pager = el.querySelector('[data-pn-pager]');
        if (!body || !pager) return;

        const items = Array.from(body.querySelectorAll('.pn-item, .pn-ev'));
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

    window.openPn = function (id) {
        const el = document.getElementById(id);
        if (!el) return;

        lastFocus = document.activeElement;
        openId = id;
        el.classList.add('show');
        el.setAttribute('aria-hidden', 'false');
        document.body.style.overflow = 'hidden';
        setupPager(el);
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
<script>
window.openCancelPn = function (btn) {
    document.getElementById('pnCancelForm').action = btn.dataset.action;
    document.getElementById('pnCancelPo').textContent = btn.dataset.po;
    window.openPn('pnCancel');
};
</script>
@endsection