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
            <div class="d-flex gap-2">
                @if (Auth::user()->canEditStockOrders())
                <a href="{{ route('stock-orders.create-supplier-po') }}" class="btn btn-primary-custom shadow-sm">
                    <i class="bi bi-plus-lg me-1"></i> New Supplier PO
                </a>
                @endif
                <a href="{{ route('stock-orders.approvals') }}" class="btn btn-outline-success">
                    <i class="bi bi-patch-check me-1"></i> Approvals Queue
                </a>
                <a href="{{ route('stock-orders.wet-stock-requests.index') }}" class="btn btn-outline-primary">
                    <i class="bi bi-truck me-1"></i> Deliveries & ATLs
                </a>
            </div>
        </div>

        {{-- Fuel Trade Queue Card (if pending SOs exist) --}}

        @php
            // Product chip colours: U = Unleaded, D = Diesel, P = Premium.
            // Kept identical to the Orders Dashboard so the two read as one set.
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

        {{-- Filter Bar --}}
        <div class="card card-custom border-0 shadow-sm mb-4">
            <div class="card-body p-3">
                <form method="GET" action="{{ route('stock-orders.purchase-orders.index') }}" class="row g-2 align-items-center">
                    <div class="col-md-3">
                        <input type="text" name="search" class="form-control form-control-sm" placeholder="Search PO # or Supplier..." value="{{ request('search') }}">
                    </div>
                    <div class="col-md-3">
                        <select name="po_type" class="form-select form-select-sm">
                            <option value="">-- All PO Types --</option>
                            @foreach (\App\Models\PurchaseOrder::TYPE_LABELS as $value => $label)
                                <option value="{{ $value }}" {{ request('po_type') === $value ? 'selected' : '' }}>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-3">
                        <select name="status" class="form-select form-select-sm">
                            <option value="">-- All Statuses --</option>
                            <option value="REQUESTED" {{ request('status') === 'REQUESTED' ? 'selected' : '' }}>1. REQUESTED</option>
                            <option value="RECEIVED" {{ request('status') === 'RECEIVED' ? 'selected' : '' }}>2. RECEIVED (Pending VP)</option>
                            <option value="CONFIRMED" {{ request('status') === 'CONFIRMED' ? 'selected' : '' }}>3. CONFIRMED (Approved)</option>
                            <option value="FOR_DELIVERY" {{ request('status') === 'FOR_DELIVERY' ? 'selected' : '' }}>4. FOR DELIVERY</option>
                            <option value="COMPLETED" {{ request('status') === 'COMPLETED' ? 'selected' : '' }}>5. COMPLETED</option>
                        </select>
                    </div>
                    <div class="col-md-3 d-flex gap-2">
                        <button type="submit" class="btn btn-sm btn-primary w-100">Filter</button>
                        <a href="{{ route('stock-orders.purchase-orders.index') }}" class="btn btn-sm btn-outline-secondary">Reset</a>
                    </div>
                </form>
            </div>
        </div>

        {{-- Purchase Orders Master Table --}}
        <div class="card card-custom border-0 shadow-sm">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="bg-light">
                            <tr>
                                <th class="ps-3 py-3">PO #</th>
                                <th class="py-3">Type</th>
                                <th class="py-3">Supplier</th>
                                <th class="py-3">Site / Channel</th>
                                <th class="py-3 text-end">Qty.</th>
                                <th class="py-3 text-end">Price</th>
                                <th class="py-3 text-end">Amount</th>
                                <th class="py-3 text-center">Status</th>
                                <th class="pe-3 py-3 text-end">Action</th>
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
                                        <span class="badge {{ $po->typeBadgeClass() }}">{{ $po->typeLabel() }}</span>
                                    </td>
                                    <td>
                                        <strong>{{ $po->supplier_name ?: ($po->client->name ?? '—') }}</strong>
                                    </td>
                                    <td>
                                        @if ($po->warehouse)
                                            <i class="bi bi-geo-alt text-primary me-1"></i>{{ $po->warehouse->name }}
                                        @elseif ($po->isFuelTrade())
                                            <span class="badge bg-light text-dark border">Direct Pick-up</span>
                                        @else
                                            —
                                        @endif
                                    </td>
                                    {{-- Qty / Price / Amount, broken out per product. Amount is the stored
                                             total; the line columns are the per-product detail
                                             behind it. Legacy POs with no item rows fall back
                                             to the PO's single ordered volume. --}}
                                    <td class="text-end">
                                        @if ($po->items->isNotEmpty())
                                            @foreach ($po->items as $it)
                                                <div class="small font-monospace">
                                                    {{ \Illuminate\Support\Str::substr($it->product, 0, 1) }} &mdash; {{ number_format($it->quantity_ordered) }}
                                                </div>
                                            @endforeach
                                        @else
                                            <div class="small font-monospace">{{ number_format($po->qty_ordered) }}</div>
                                        @endif
                                    </td>
                                    <td class="text-end">
                                        @if ($po->items->isNotEmpty())
                                            @foreach ($po->items as $it)
                                                <div class="small font-monospace">
                                                    {{ \Illuminate\Support\Str::substr($it->product, 0, 1) }} &mdash; {{ number_format((float) $it->unit_price, 2) }}
                                                </div>
                                            @endforeach
                                        @else
                                            <div class="small text-muted">&mdash;</div>
                                        @endif
                                    </td>
                                    <td class="text-end">
                                        @php
                                            // Legacy POs predate the stored total, so fall
                                            // back to summing their own line items.
                                            $amount = (float) ($po->total_amount
                                                ?: $po->items->sum(fn ($i) => $i->quantity_ordered * (float) $i->unit_price));
                                        @endphp
                                        <div class="fw-bold font-monospace">{{ number_format($amount, 2) }}</div>
                                    </td>
                                    <td class="text-center">
                                        @if ($po->request_status === 'REQUESTED')
                                            <span class="badge bg-secondary-subtle text-secondary border rounded-pill px-3 py-1">1. REQUESTED</span>
                                        @elseif ($po->request_status === 'RECEIVED')
                                            <span class="badge bg-warning-subtle text-warning border rounded-pill px-3 py-1">2. RECEIVED (Pending VP)</span>
                                        @elseif ($po->request_status === 'CONFIRMED')
                                            <span class="badge bg-info-subtle text-info border rounded-pill px-3 py-1">3. CONFIRMED (Approved)</span>
                                        @elseif ($po->request_status === 'FOR_DELIVERY')
                                            <span class="badge bg-primary rounded-pill px-3 py-1 text-white">4. FOR DELIVERY</span>
                                        @elseif ($po->request_status === 'COMPLETED')
                                            <span class="badge bg-success-subtle text-success border rounded-pill px-3 py-1">5. COMPLETED</span>
                                        @endif
                                        <div class="extra-small text-muted mt-1">{{ $po->computed_status }}</div>
                                    </td>
                                    <td class="pe-3 text-end">
                                        @php
                                            // Every row leads with View. ATL processing and
                                            // approval both start from the PO detail page now,
                                            // so the list is read-only navigation.
                                            $canApprove = Auth::user()->canApproveStockOrders();
                                            $activeDel = $po->deliveries->firstWhere('status', 'Active');
                                            $atlDel = $po->deliveries->first(fn($d) => $d->requiresAtl());
                                        @endphp
                                        <div class="d-inline-flex gap-1 align-items-center flex-wrap justify-content-end">
                                            <a href="{{ route('stock-orders.purchase-orders.show', $po->id) }}" class="btn btn-sm btn-light border rounded-pill px-2 py-1 text-secondary" title="Open purchase order">
                                                <i class="bi bi-eye me-1"></i> View
                                            </a>

                                            @if (Auth::user()->canEditStockOrders())
                                                <a href="{{ route('stock-orders.edit-supplier-po', $po->id) }}" class="btn btn-sm btn-light border rounded-pill px-2 py-1 text-secondary" title="Edit purchase order">
                                                    <i class="bi bi-pencil me-1"></i> Edit
                                                </a>
                                            @endif

                                            {{-- Only released once the VP has approved. --}}
                                            @if ($po->isVpApproved())
                                                <a href="{{ route('stock-orders.po-pdf', $po->id) }}"
                                                   class="btn btn-sm btn-outline-danger rounded-pill px-2 py-1" title="Download Purchase Order PDF">
                                                    <i class="bi bi-file-earmark-pdf me-1"></i> PO PDF
                                                </a>
                                            @endif

                                            @if ($po->request_status === 'REQUESTED' || $po->request_status === 'RECEIVED')
                                                @if ($canApprove)
                                                    <a href="{{ route('stock-orders.approvals') }}" class="btn btn-sm btn-outline-success rounded-pill px-3 py-1" title="Review in Approvals Queue">
                                                        <i class="bi bi-patch-check me-1"></i> Review for Approval
                                                    </a>
                                                @else
                                                    <span class="badge bg-warning-subtle text-warning border rounded-pill px-3 py-1">Pending VP</span>
                                                @endif
                                            @endif

                                            @if ($po->request_status === 'FOR_DELIVERY')
                                                @if ($po->isFuelTrade() && $activeDel && Auth::user()->canEditStockOrders())
                                                    <button type="button" class="btn btn-sm btn-success rounded-pill px-3 py-1 shadow-sm" data-bs-toggle="modal" data-bs-target="#confirmLiftModal{{ $activeDel->id }}">
                                                        <i class="bi bi-check-circle me-1"></i> Mark Picked Up
                                                    </button>
                                                @endif
                                                @if ($activeDel && $activeDel->hasScannedDocs())
                                                    <a href="{{ $activeDel->scanned_doc_url }}" target="_blank" class="btn btn-sm btn-outline-success rounded-pill px-2 py-1 shadow-sm" title="View Scanned Documents on Google Drive">
                                                        <i class="bi bi-google me-1"></i> Docs
                                                    </a>
                                                @endif
                                                @if ($atlDel)
                                                    <a href="{{ route('stock-orders.pdf', $atlDel->id) }}" class="btn btn-sm btn-outline-danger rounded-pill px-2 py-1 shadow-sm" title="Extract Authority to Load PDF">
                                                        <i class="bi bi-file-earmark-pdf me-1"></i> ATL PDF
                                                    </a>
                                                @endif
                                                <a href="{{ route('stock-orders.wet-stock-requests.show', $po->id) }}" class="btn btn-sm btn-light border rounded-pill px-2 py-1 text-secondary" title="Open request">
                                                    <i class="bi bi-truck me-1"></i> Track
                                                </a>
                                            @endif
                                        </div>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="9" class="text-center py-5 text-muted">No stock orders found matching criteria.</td>
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

{{-- Modals for Confirming Lift & Auto-Stocking --}}
@foreach ($purchaseOrders as $po)
    @php
        $activeDel = $po->deliveries->firstWhere('status', 'Active');
    @endphp
    @if ($po->isFuelTrade() && $activeDel)
        <div class="modal fade" id="confirmLiftModal{{ $activeDel->id }}" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content rounded-4 border-0 shadow">
                    <div class="modal-header border-0 pb-0">
                        <h5 class="modal-title fw-bold text-dark">
                            <i class="bi bi-patch-check-fill text-success me-1"></i> Confirm Refinery Pick Up / Lift
                        </h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <form method="POST" action="{{ route('stock-orders.complete-fuel-trade', $activeDel->id) }}">
                        @csrf
                        <div class="modal-body pt-3">
                            <div class="p-3 bg-light rounded-3 mb-3">
                                <div class="d-flex justify-content-between small text-muted mb-1">
                                    <span>PO #{{ $po->po_number }}</span>
                                    <span class="badge bg-secondary-subtle text-secondary">{{ $activeDel->channel_label }}</span>
                                </div>
                                <div class="fw-bold fs-5 text-primary mb-1">{{ number_format($activeDel->qty_to_receive) }} L of {{ $activeDel->product }}</div>
                                <div class="small text-muted">
                                    Client: <span class="fw-semibold text-dark">{{ $po->linkedOrder?->account ?? $po->client?->name ?? '—' }}</span> (SO: {{ $po->linkedOrder?->formatted_so_number ?? '—' }})
                                </div>
                            </div>

                            @if ($activeDel->isTankerPickup())
                                <div class="mb-3">
                                    <label class="form-label small fw-semibold">Target Mobile Tanker Truck <span class="text-danger">*</span></label>
                                    <select name="storage_tank_id" class="form-select" required>
                                        <option value="">Select DITC Tanker to receive fuel...</option>
                                        @foreach($mobileTankers ?? [] as $tank)
                                            <option value="{{ $tank->id }}" {{ $activeDel->storage_tank_id == $tank->id ? 'selected' : '' }}>
                                                {{ $tank->name }} ({{ $tank->warehouse->name ?? 'Depot' }}) — Remaining: {{ number_format($tank->remaining_capacity) }}L / Max: {{ number_format($tank->max_capacity) }}L
                                            </option>
                                        @endforeach
                                    </select>
                                    <div class="form-text text-primary extra-small" style="font-size: 0.75rem;">
                                        <i class="bi bi-info-circle me-1"></i>Volume will automatically stock into this tanker in Module 2.
                                    </div>
                                </div>
                            @else
                                <div class="alert alert-info border-0 rounded-3 small py-2 mb-3">
                                    <i class="bi bi-info-circle me-1"></i><strong>Direct Client Rack Lift:</strong> This order will be marked as fulfilled directly. No wet stock movements required.
                                </div>
                            @endif

                            <div class="row g-2 mb-3">
                                <div class="col-6">
                                    <label class="form-label small fw-semibold">Supplier SO #</label>
                                    <input type="text" name="supplier_so_number" class="form-control form-control-sm" placeholder="e.g. SO-8842" value="{{ $activeDel->supplier_so_number }}">
                                </div>
                                <div class="col-6">
                                    <label class="form-label small fw-semibold">Supplier Rack DR #</label>
                                    <input type="text" name="supplier_dr_number" class="form-control form-control-sm" placeholder="e.g. DR-9901" value="{{ $activeDel->supplier_dr_number }}">
                                </div>
                            </div>

                            <div class="mb-2">
                                <label class="form-label small fw-semibold">
                                    <i class="bi bi-google text-danger me-1"></i>Google Drive Scanned Document Link
                                </label>
                                <input type="url" name="scanned_doc_url" class="form-control form-control-sm" placeholder="https://drive.google.com/..." value="{{ $activeDel->scanned_doc_url }}">
                                <div class="form-text text-muted extra-small" style="font-size: 0.75rem;">Shareable link to signed DR and dip inspection tickets.</div>
                            </div>
                        </div>
                        <div class="modal-footer border-0 pt-0">
                            <button type="button" class="btn btn-secondary rounded-pill px-4" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" class="btn btn-success rounded-pill px-4">
                                <i class="bi bi-check-circle me-1"></i> Confirm Lift
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    @endif
@endforeach
@endsection