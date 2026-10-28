@extends('layouts.app')

@section('title', 'Purchase Orders')

@section('content')
<div class="row justify-content-center">
    <div class="col-12">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-4">
            <div>
                <nav aria-label="breadcrumb">
                    <ol class="breadcrumb mb-1 small">
                        <li class="breadcrumb-item"><a href="{{ route('portal') }}" class="text-decoration-none text-secondary">Portal</a></li>
                        <li class="breadcrumb-item active" aria-current="page">Purchase Orders</li>
                    </ol>
                </nav>
                <h3 class="fw-bold text-dark mb-0">
                    <i class="bi bi-card-checklist text-primary me-2"></i>Purchase Orders
                </h3>
            </div>
        </div>

        {{-- Fuel Trade Queue Card (if pending SOs exist) --}}

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

        {{-- Purchase Orders Master Table --}}
        <div class="card card-custom border-0 shadow-sm">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-center table-hover align-middle mb-0">
                        <thead class="bg-light">
                            <tr>
                                <th class="ps-3 py-3">PO #</th>
                                <th class="py-3">Supplier</th>
                                <th class="py-3">Qty.</th>
                                <th class="py-3">Price</th>
                                <th class="py-3">Amount</th>
                                <th class="py-3">Status</th>
                                <th class="py-3 table-actions">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($purchaseOrders as $po)
                                <tr>
                                    <td class="ps-3 fw-bold text-dark">
                                        {{ $po->po_number ?: 'Pending PO#' }}
                                        @if ($po->linkedOrder)
                                            <div class="small text-muted">SO: {{ $po->linkedOrder->so_number }}</div>
                                        @endif
                                    </td>
                                    <td>
                                        <strong>{{ $po->supplier_name ?: ($po->client->name ?? '—') }}</strong>
                                    </td>
                                        {{-- Qty / Price / Amount, broken out per product. Amount is the
                                             stored total; the line columns are the per-product detail
                                             behind it. Legacy POs with no item rows fall back to the
                                             PO's single ordered volume.

                                             Qty and Price are marked up the way the Orders Dashboard
                                             marks up its own: one centred line per product, keyed by
                                             its initial. These were monospace - a face used nowhere else
                                             in the system - which made these three columns read as a
                                             different table from the rest. Amount follows the Dashboard's
                                             text-nowrap cell. --}}
                                    <td>
                                        @if ($po->items->isNotEmpty())
                                            <div class="pl-block">
                                                @foreach ($po->items as $it)
                                                    <div class="pl-row" title="{{ $it->product }}">
                                                        <span class="pl-key">{!! $chip(\Illuminate\Support\Str::substr($it->product, 0, 1), $it->product) !!}</span>
                                                        <span class="pl-num">{{ number_format($it->quantity_ordered) }}</span>
                                                    </div>
                                                @endforeach
                                            </div>
                                        @else
                                            <div class="pl-row"><span class="pl-num">{{ number_format($po->qty_ordered) }}</span></div>
                                        @endif
                                    </td>
                                    <td>
                                        @if ($po->items->isNotEmpty())
                                            <div class="pl-block">
                                                @foreach ($po->items as $it)
                                                    <div class="pl-row" title="{{ $it->product }}">
                                                        <span class="pl-key">{!! $chip(\Illuminate\Support\Str::substr($it->product, 0, 1), $it->product) !!}</span>
                                                        <span class="pl-num">{{ number_format((float) $it->unit_price, 2) }}</span>
                                                    </div>
                                                @endforeach
                                            </div>
                                        @else
                                            <div class="pl-row text-muted"><span class="pl-num">&mdash;</span></div>
                                        @endif
                                    </td>
                                    <td class="text-nowrap">
                                        @php
                                            // Legacy POs predate the stored total, so fall
                                            // back to summing their own line items.
                                            $amount = (float) ($po->total_amount
                                                ?: $po->items->sum(fn ($i) => $i->quantity_ordered * (float) $i->unit_price));
                                        @endphp
                                        <div class="fw-bold">{{ number_format($amount, 2) }}</div>
                                    </td>
                                    <td>
                                        {{-- One label, not two. This cell previously stacked a
                                             numbered request_status badge over computed_status,
                                             which read as two answers to the same question - "3.
                                             CONFIRMED (Approved)" over "Pending", "4. FOR
                                             DELIVERY" over "Fulfilled".

                                             The numbered badge is gone, at the user's direction.
                                             Note what that costs: computed_status reads the
                                             lifecycle column and never mentions approval, so an
                                             approved PO now shows only "Pending" here. The one
                                             remaining approval signal in this row is the Print
                                             button replacing Edit in the Action cell. --}}
                                        <span class="badge rounded-pill px-3 py-1 {{ $po->computed_status_badge_class }}">
                                            {{ $po->computed_status }}
                                        </span>
                                    </td>
                                    <td class="table-actions">
                                        {{-- Exactly two actions besides View, and which two
                                             depends on where the PO is in its life: Print is
                                             released only once the VP has approved, and an
                                             approved PO can no longer be edited. So each state
                                             offers Print XOR Edit. The routes enforce the same
                                             thing, so this is a convenience rather than the
                                             gate. --}}
                                        <div class="d-inline-flex gap-1 align-items-center justify-content-center">
                                            @if ($po->isVpApproved())
                                                <a href="{{ route('stock-orders.po-pdf', $po->id) }}"
                                                   class="btn btn-sm btn-action btn-outline-danger rounded-pill px-2 py-1" title="Print Purchase Order">
                                                    <i class="bi bi-printer me-1"></i> Print
                                                </a>
                                            @elseif (Auth::user()->canEditStockOrders())
                                                <a href="{{ route('stock-orders.edit-supplier-po', $po->id) }}" class="btn btn-sm btn-action btn-light border rounded-pill px-2 py-1 text-secondary" title="Edit purchase order">
                                                    <i class="bi bi-pencil me-1"></i> Edit
                                                </a>
                                            @endif

                                            <a href="{{ route('stock-orders.purchase-orders.show', $po->id) }}" class="btn btn-sm btn-action btn-light border rounded-pill px-2 py-1 text-secondary" title="Open purchase order">
                                                <i class="bi bi-eye me-1"></i> View
                                            </a>
                                        </div>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="7" class="text-center py-5 text-muted">No stock orders found matching criteria.</td>
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
@endsection
