@extends('layouts.app')

@section('title', 'Purchase Order Details')

@section('content')
<div class="row justify-content-center">
    <div class="col-12">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-4">
            <div>
                <nav aria-label="breadcrumb">
                    <ol class="breadcrumb mb-1 small">
                        <li class="breadcrumb-item"><a href="{{ route('portal') }}" class="text-decoration-none text-secondary">Portal</a></li>
                        <li class="breadcrumb-item"><a href="{{ route('stock-orders.purchase-orders.index') }}" class="text-decoration-none text-secondary">Purchase Orders</a></li>
                        <li class="breadcrumb-item active" aria-current="page">{{ $purchaseOrder->po_number ?: 'Pending PO#' }}</li>
                    </ol>
                </nav>
                <h3 class="fw-bold text-dark mb-0">
                    <i class="bi bi-card-checklist text-primary me-2"></i>Purchase Order &mdash; {{ $purchaseOrder->po_number ?: '(Pending PO#)' }}
                </h3>
                <p class="text-muted small mb-0 mt-1">
                    {{ $purchaseOrder->supplier_name ?: ($purchaseOrder->client->name ?? '—') }}
                    @if ($purchaseOrder->po_date)
                        &middot; Order Date {{ $purchaseOrder->po_date->format('M d, Y') }}
                    @endif
                </p>
            </div>
            <div class="d-flex gap-2 flex-wrap">
                @if ($purchaseOrder->request_status === 'REQUESTED' && Auth::user()->canEditStockOrders())
                    <a href="{{ route('stock-orders.edit-request', $purchaseOrder->id) }}" class="btn btn-primary-custom">
                        <i class="bi bi-pencil-square me-1"></i> Prepare &amp; Submit
                    </a>
                @endif
                @if (Auth::user()->canEditStockOrders())
                    <a href="{{ route('stock-orders.edit-supplier-po', $purchaseOrder->id) }}" class="btn btn-outline-secondary">
                        <i class="bi bi-pencil me-1"></i> Edit PO
                    </a>
                @endif
                @if ($purchaseOrder->isVpApproved())
                    <a href="{{ route('stock-orders.po-pdf', $purchaseOrder->id) }}" class="btn btn-outline-danger">
                        <i class="bi bi-file-earmark-pdf me-1"></i> PO PDF
                    </a>
                @endif
                <a href="{{ route('stock-orders.purchase-orders.index') }}" class="btn btn-outline-secondary">
                    <i class="bi bi-arrow-left me-1"></i> Back to Purchase Orders
                </a>
            </div>
        </div>

        {{-- PO-specific metadata: supplier, type, pipeline position and who
             touched it. The ATL story lives in the sections below. --}}
        <div class="card card-custom border-0 shadow-sm mb-4">
            <div class="card-body p-4">
                <div class="row g-3">
                    <div class="col-md-3">
                        <span class="text-muted extra-small uppercase fw-semibold d-block mb-1" style="font-size: 0.7rem;">PO TYPE</span>
                        <span class="badge {{ $purchaseOrder->typeBadgeClass() }}">{{ $purchaseOrder->typeLabel() }}</span>
                        <div class="small text-muted mt-2">
                            <span class="text-muted d-block">Order Date</span>
                            <span class="fw-semibold text-dark">{{ $purchaseOrder->po_date ? $purchaseOrder->po_date->format('M d, Y') : '—' }}</span>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <span class="text-muted extra-small uppercase fw-semibold d-block mb-1" style="font-size: 0.7rem;">PIPELINE STATUS</span>
                        @if ($purchaseOrder->request_status === 'REQUESTED')
                            <span class="badge bg-secondary-subtle text-secondary border rounded-pill px-3 py-1">1. REQUESTED</span>
                        @elseif ($purchaseOrder->request_status === 'RECEIVED')
                            <span class="badge bg-warning-subtle text-warning border rounded-pill px-3 py-1">2. RECEIVED (Pending VP)</span>
                        @elseif ($purchaseOrder->request_status === 'CONFIRMED')
                            <span class="badge bg-info-subtle text-info border rounded-pill px-3 py-1">3. CONFIRMED (Approved)</span>
                        @elseif ($purchaseOrder->request_status === 'FOR_DELIVERY')
                            <span class="badge bg-primary rounded-pill px-3 py-1 text-white">4. FOR DELIVERY</span>
                        @elseif ($purchaseOrder->request_status === 'COMPLETED')
                            <span class="badge bg-success-subtle text-success border rounded-pill px-3 py-1">5. COMPLETED</span>
                        @else
                            <span class="badge bg-light text-dark border rounded-pill px-3 py-1">{{ $purchaseOrder->request_status }}</span>
                        @endif
                        <div class="mt-2 small text-muted">Computed: <span class="fw-semibold text-dark">{{ $purchaseOrder->computed_status }}</span></div>
                    </div>
                    <div class="col-md-3">
                        <span class="text-muted extra-small uppercase fw-semibold d-block mb-1" style="font-size: 0.7rem;">SITE / CHANNEL</span>
                        <span class="fw-semibold text-dark">
                            @if ($purchaseOrder->warehouse)
                                <i class="bi bi-geo-alt text-primary me-1"></i>{{ $purchaseOrder->warehouse->name }}
                            @elseif ($purchaseOrder->isFuelTrade())
                                Direct Pick-up
                            @else
                                &mdash;
                            @endif
                        </span>
                        @if ($purchaseOrder->linkedOrder)
                            <div class="small text-muted mt-1">Sales Order: <span class="fw-semibold text-dark">{{ $purchaseOrder->linkedOrder->so_number ?? '—' }}</span></div>
                        @endif
                    </div>
                    <div class="col-md-3">
                        <span class="text-muted extra-small uppercase fw-semibold d-block mb-1" style="font-size: 0.7rem;">DATES</span>
                        <div class="small">
                            <span class="text-muted d-block">Requested By</span>
                            <span class="fw-semibold text-dark">{{ $purchaseOrder->requester->name ?? 'System' }}</span>
                        </div>
                        <div class="small mt-1">
                            <span class="text-muted d-block">Approved By</span>
                            <span class="fw-semibold text-dark">{{ $purchaseOrder->approver->name ?? '—' }}</span>
                            @if ($purchaseOrder->approved_at)
                                <span class="text-muted">({{ $purchaseOrder->approved_at->format('M d, Y h:i A') }})</span>
                            @endif
                        </div>
                        @if ($purchaseOrder->date_needed)
                            <div class="small mt-1">
                                <span class="text-muted d-block">Date Needed</span>
                                <span class="fw-semibold text-dark">{{ $purchaseOrder->date_needed->format('M d, Y') }}</span>
                            </div>
                        @endif
                    </div>
                </div>
                @if ($purchaseOrder->remarks)
                    <div class="alert alert-light border small mb-0 mt-3">
                        <span class="text-muted">Remarks:</span> {{ $purchaseOrder->remarks }}
                    </div>
                @endif
                @if ($purchaseOrder->request_status === 'RECEIVED' && Auth::user()->canApproveStockOrders())
                    <div class="d-flex gap-2 mt-3">
                        <a href="{{ route('stock-orders.approvals') }}" class="btn btn-sm btn-outline-success rounded-pill px-3">
                            <i class="bi bi-patch-check me-1"></i> Review in Approvals Queue
                        </a>
                    </div>
                @endif
            </div>
        </div>

        @php
            // Same card set and styling as the Orders Dashboard and the
            // Purchase Orders list, so all three pages read as one system.
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
                display: inline-block; font-size: 0.65rem; font-weight: 700; line-height: 1;
                padding: 0.2rem 0.4rem; border-radius: 0.35rem; margin: 0 1px;
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

        {{-- Single-figure view of the same PO: what has been committed to an
             ATL, what has actually left, and what is still outstanding. --}}
        <div class="row g-4 mb-4">
            <div class="col-md-4">
                <div class="card card-custom p-3 border-0 h-100">
                    <div class="d-flex align-items-center gap-3">
                        <div class="stat-icon" style="background:#ecfeff;color:#0891b2;"><i class="bi bi-journal-text"></i></div>
                        <span class="stat-title">Qty Ordered</span>
                    </div>
                    <div class="d-flex flex-grow-1 align-items-center justify-content-center py-3">
                        <span class="stat-big">{{ number_format($totals['ordered']) }}</span>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card card-custom p-3 border-0 h-100">
                    <div class="d-flex align-items-center gap-3">
                        <div class="stat-icon" style="background:#eef2ff;color:#4338ca;"><i class="bi bi-patch-check"></i></div>
                        <span class="stat-title">Total ATL'd</span>
                    </div>
                    <div class="d-flex flex-grow-1 align-items-center justify-content-center py-3">
                        <span class="stat-big">{{ number_format($totals['atl_issued']) }}</span>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card card-custom p-3 border-0 h-100">
                    <div class="d-flex align-items-center gap-3">
                        <div class="stat-icon" style="background:#fffbeb;color:#d97706;"><i class="bi bi-clock-history"></i></div>
                        <span class="stat-title">Remaining</span>
                    </div>
                    <div class="d-flex flex-grow-1 align-items-center justify-content-center py-3">
                        <span class="stat-big">{{ number_format($totals['remaining']) }}</span>
                    </div>
                </div>
            </div>
        </div>

        {{-- Per-product requirement --}}
        @if ($purchaseOrder->items->isNotEmpty())
            <div class="card card-custom border-0 shadow-sm mb-4">
                <div class="card-header bg-white py-3">
                    <h6 class="fw-bold text-dark mb-0">Products Required by this Order</h6>
                </div>
                <div class="card-body py-3">
                    <div class="row g-3">
                        @foreach ($purchaseOrder->items as $item)
                            @php
                                $required = (int) $item->quantity_ordered;
                                $atlQty = $atlByProduct[$item->product] ?? 0;
                            @endphp
                            <div class="col-md-4">
                                <div class="border rounded-3 p-3 h-100">
                                    <div class="fw-bold text-dark">{{ $item->product }}</div>
                                    <div class="small text-muted">
                                        Required <strong class="text-dark">{{ number_format($required) }} L</strong>
                                    </div>
                                    <div class="small text-muted">
                                        ATL'd <strong class="text-primary">{{ number_format($atlQty) }} L</strong>
                                    </div>
                                    <div class="small mt-1">
                                        @if ($atlQty === 0)
                                            <span class="badge bg-light text-muted border">Not ATL'd</span>
                                        @elseif ($atlQty === $required)
                                            <span class="badge bg-success-subtle text-success border border-success-subtle">Fully ATL'd</span>
                                        @elseif ($atlQty > $required)
                                            <span class="badge bg-danger-subtle text-danger border border-danger-subtle">Over by {{ number_format($atlQty - $required) }} L</span>
                                        @else
                                            <span class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle">Short by {{ number_format($required - $atlQty) }} L</span>
                                        @endif
                                    </div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>
        @endif

        {{-- ATLs --}}
        <div class="card card-custom border-0 shadow-sm">
            <div class="card-header bg-white py-3">
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                    <h6 class="fw-bold text-dark mb-0">ATL Records</h6>
                    @if ($purchaseOrder->isVpApproved() && Auth::user()->canEditStockOrders())
                        <a href="{{ route('stock-orders.add-atl', $purchaseOrder->id) }}" class="btn btn-sm btn-primary-custom rounded-pill px-3">
                            <i class="bi bi-plus-lg me-1"></i> Create ATL
                        </a>
                    @endif
                </div>
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
                                        <span class="badge {{ $purchaseOrder->typeBadgeClass() }}">{{ $purchaseOrder->typeLabel() }}</span>
                                    </td>
                                    <td class="text-end small font-monospace">
                                        @foreach ($rates as $product => $qty)
                                            <div>{{ \Illuminate\Support\Str::substr((string) $product, 0, 1) }} &mdash; {{ number_format($qty) }}</div>
                                        @endforeach
                                    </td>
                                    <td class="text-end small font-monospace">
                                        @foreach ($rates as $product => $qty)
                                            <div>{{ \Illuminate\Support\Str::substr((string) $product, 0, 1) }} &mdash; {{ number_format((float) ($priceByProduct[$product] ?? 0), 2) }}</div>
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
                                            @if ($delivery->requiresAtl() && $delivery->atl_number
                                                && $delivery->status !== 'Pending'
                                                && $delivery->approval_status !== \App\Models\PurchaseOrderDelivery::APPROVAL_REJECTED)
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
            </div>
        </div>
    </div>
</div>
@endsection