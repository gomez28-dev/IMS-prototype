@extends('layouts.app')

@section('title', 'Stock Requests')

@section('content')
<div class="container-fluid py-2">
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center mb-4 gap-3">
        <div>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-1 small">
                    <li class="breadcrumb-item"><a href="{{ route('portal') }}" class="text-decoration-none text-secondary">Portal</a></li>
                    <li class="breadcrumb-item active" aria-current="page">Stock Requests</li>
                </ol>
            </nav>
            <h3 class="fw-bold text-dark mb-1">
                <i class="bi bi-file-earmark-text text-primary me-2"></i>Stock Requests
            </h3>
            <p class="text-muted small mb-0">Replenishment requests raised by depots, and depot stock arriving for tank fill.</p>
        </div>
        <div class="d-flex gap-2">
            <a href="{{ route('wetstock.supplier-orders.index') }}" class="btn btn-secondary-custom">
                <i class="bi bi-box-seam me-1"></i> Incoming Stock Log
            </a>
            @if (Auth::user()->canEditModule2())
                <button type="button" class="btn btn-primary-custom shadow-sm" data-bs-toggle="modal" data-bs-target="#requestFormModal" data-request-mode="create">
                    <i class="bi bi-plus-lg me-1"></i> Create Request
                </button>
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

    {{-- Validation failures are raised by the request pop-up, but they are
         reported here rather than only inside it. A send that could not be
         saved used to land on this page with the pop-up shut and no visible
         message at all, which read as though nothing had happened. --}}
    @if ($errors->any())
        <div class="alert alert-danger alert-dismissible fade show rounded-3" role="alert">
            <i class="bi bi-exclamation-triangle-fill me-2"></i>
            <strong class="d-block mb-1">That request was not saved:</strong>
            <ul class="mb-0 ps-3">
                @foreach ($errors->all() as $message)
                    <li>{{ $message }}</li>
                @endforeach
            </ul>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    @endif

    {{-- Stat cards. These total the same product lines the table below shows,
     so the two always reconcile. Routed Buy Back orders are excluded.
     Markup and colours match the Purchase Orders page so all three pages
     read as one set. --}}
    @php
        // Product chip colours: U = Unleaded, D = Diesel, P = Premium.
        // Kept identical to the Orders Dashboard and Purchase Orders.
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
            ['title' => 'Total Qty. Requested', 'icon' => 'bi-boxes', 'tint' => 'background:#ecfeff;color:#0891b2;', 'data' => $stats['requested']],
            ['title' => 'Total Qty. Received', 'icon' => 'bi-truck', 'tint' => 'background:#f0fdf4;color:#16a34a;', 'data' => $stats['received']],
            ['title' => 'Total Qty. Pending', 'icon' => 'bi-clock-history', 'tint' => 'background:#fffbeb;color:#d97706;', 'data' => $stats['pending']],
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

    <div class="row g-4 mb-4">
        <div class="col-xl-3 col-md-6">
            <div class="card card-custom p-3 border-0 h-100">
                <div class="d-flex align-items-center gap-3">
                    <div class="stat-icon" style="background:#fff1ee;color:#f04a1a;"><i class="bi bi-file-earmark-text"></i></div>
                    <span class="stat-title">Total Requests</span>
                </div>
                <div class="d-flex flex-grow-1 align-items-center justify-content-center py-3">
                    <span class="stat-big">{{ number_format($stats['requests']) }}</span>
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

{{-- Search engine --}}
<div class="card card-custom border-0 shadow-sm mb-4">
    <div class="card-body p-3">
        <form method="GET" action="{{ route('wetstock.stock-requests.index') }}" class="row g-2 align-items-center">
            <div class="col-md-6">
                <input type="text" name="search" class="form-control form-control-sm"
                       placeholder="Search REQ #, Remarks or Requested By..." value="{{ $search }}">
            </div>
            <div class="col-md-3">
                <select name="warehouse_id" class="form-select form-select-sm">
                    <option value="">-- All Locations / Warehouses --</option>
                    @foreach ($warehouses as $wh)
                        <option value="{{ $wh->id }}" {{ (string) $warehouseId === (string) $wh->id ? 'selected' : '' }}>
                            {{ $wh->name }}
                        </option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-3 d-flex gap-2">
                <button type="submit" class="btn btn-sm btn-primary w-100">
                    <i class="bi bi-search me-1"></i> Search
                </button>
                <a href="{{ route('wetstock.stock-requests.index') }}" class="btn btn-sm btn-outline-secondary">
                    <i class="bi bi-arrow-counterclockwise me-1"></i> Reset
                </a>
            </div>
        </form>
    </div>
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
                            <th>REQ #</th>
                            <th>Site</th>
                            <th>Type</th>
                            <th class="text-end">Qty. Requested</th>
                            <th class="text-end">Qty. Received</th>
                            <th class="text-end">Qty. Pending</th>
                            <th>Purchasing</th>
                            <th>Date Needed</th>
                            <th>Status</th>
                            <th>Requested By</th>
                            <th>Remarks</th>
                            <th class="pe-3 text-end">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($stockRequests as $req)
                            <tr>
                                <td class="fw-bold text-dark">{{ $req->request_number }}</td>
                                <td>{{ $req->warehouse->name ?? 'All Depots' }}</td>
                                <td><span class="badge bg-light text-dark border">{{ $req->type_label }}</span></td>
                                {{-- Volumes break out per product, because a request can
                                     cover more than one and Pending is per product:
                                     Requested minus Received. --}}
                                <td class="text-end fw-bold font-monospace">
                                    @if ($req->lines->isEmpty())
                                        <span class="text-muted">&mdash;</span>
                                    @else
                                        @foreach ($req->lines as $line)
                                            <div>{!! $chip(\Illuminate\Support\Str::substr((string) $line->product, 0, 1), $line->product) !!} <span class="ms-2">{{ number_format($line->qty_requested) }}</span></div>
                                        @endforeach
                                    @endif
                                </td>
                                <td class="text-end font-monospace">
                                    @if ($req->lines->isEmpty())
                                        <span class="text-muted">&mdash;</span>
                                    @else
                                        @foreach ($req->lines as $line)
                                            <div>{!! $chip(\Illuminate\Support\Str::substr((string) $line->product, 0, 1), $line->product) !!} <span class="ms-2">{{ number_format($line->qty_received) }}</span></div>
                                        @endforeach
                                    @endif
                                </td>
                                <td class="text-end font-monospace">
                                    @if ($req->lines->isEmpty())
                                        <span class="text-muted">&mdash;</span>
                                    @else
                                        @foreach ($req->lines as $line)
                                            <div class="{{ $line->qty_pending > 0 ? 'text-warning-emphasis fw-semibold' : 'text-success' }}">{!! $chip(\Illuminate\Support\Str::substr((string) $line->product, 0, 1), $line->product) !!} <span class="ms-2">{{ number_format($line->qty_pending) }}</span></div>
                                        @endforeach
                                    @endif
                                </td>
                                {{-- Purchasing picking the request up is reported on its
                                     own, separately from the fuel actually arriving. --}}
                                <td class="small">
                                    @if ($req->hasBeenPickedUpByPurchasing())
                                        <span class="badge bg-success-subtle text-success border rounded-pill px-2 py-1">
                                            <i class="bi bi-check2 me-1"></i>{{ $req->purchasingReceiver?->name ?? 'Purchasing' }}
                                        </span>
                                        <div class="extra-small text-muted mt-1">{{ $req->purchasing_received_at?->format('M d, Y') }}</div>
                                    @else
                                        <span class="badge bg-warning-subtle text-warning-emphasis border rounded-pill px-2 py-1">Awaiting</span>
                                    @endif
                                </td>
                                <td class="small">{{ $req->date_needed ? $req->date_needed->format('M d, Y') : '—' }}</td>
                                <td>
                                    <span class="badge bg-warning text-dark px-2 py-1">{{ $req->status }}</span>
                                </td>
                                <td class="small text-muted">{{ $req->requester->name ?? 'Depot' }}</td>
                                <td class="small text-muted">{{ $req->remarks ?: '—' }}</td>
                            <td class="pe-3 text-end">
                                    <div class="d-inline-flex gap-1 justify-content-end flex-wrap">
                                        {{-- Picking up and receiving are different things, so
                                             they are separate actions and separate columns. --}}
                                        @if (! $req->hasBeenPickedUpByPurchasing())
                                            <form method="POST" action="{{ route('wetstock.stock-requests.picked-up', $req->id) }}">
                                                @csrf
                                                <button type="submit" class="btn btn-sm btn-outline-success rounded-pill px-2 py-1" title="Mark picked up by Purchasing">
                                                    <i class="bi bi-check2-circle me-1"></i> Pick Up
                                                </button>
                                            </form>
                                        @endif

                                        @if (Auth::user()->canReceiveStockIntoDepot() && $req->lines->isNotEmpty())
                                            <button type="button" class="btn btn-sm btn-light border rounded-pill px-2 py-1 text-secondary"
                                                    data-bs-toggle="modal" data-bs-target="#receiveQuantitiesModal{{ $req->id }}"
                                                    title="Record quantities received">
                                                <i class="bi bi-box-arrow-in-down me-1"></i> Received
                                            </button>
                                        @endif

                                        {{-- VIEW opens the read-only pop-up, PEN the same
                                             form pre-filled for editing. --}}
                                        <button type="button" class="btn btn-sm btn-light border rounded-pill px-2 py-1 text-secondary"
                                                data-bs-toggle="modal" data-bs-target="#requestViewModal{{ $req->id }}"
                                                title="View request">
                                            <i class="bi bi-eye me-1"></i> View
                                        </button>

                                        @if (Auth::user()->canEditModule2())
                                            <button type="button" class="btn btn-sm btn-light border rounded-pill px-2 py-1 text-secondary"
                                                    data-bs-toggle="modal" data-bs-target="#requestFormModal"
                                                    data-request-mode="edit"
                                                    data-request-id="{{ $req->id }}"
                                                    data-request-number="{{ $req->request_number }}"
                                                    data-request-warehouse="{{ $req->warehouse_id }}"
                                                    data-request-type="{{ $req->request_type }}"
                                                    data-request-date-needed="{{ $req->date_needed ? $req->date_needed->format('Y-m-d') : '' }}"
                                                    data-request-remarks="{{ $req->remarks }}"
                                                    data-request-lines="{{ $req->lines->map(fn ($l) => $l->id.'|'.$l->product.'|'.$l->qty_requested)->implode(';;') }}"
                                                    title="Edit request">
                                                <i class="bi bi-pencil me-1"></i> Pen
                                            </button>
                                        @endif

                                        {{-- ATLs are raised here rather than on the PO's own
                                             page, which no longer offers the button. Both the
                                             request and its PO have to be in place: createAtl
                                             refuses a PO the VP has not approved. --}}
                                        @if (Auth::user()->canEditStockOrders()
                                            && $req->isPrepared()
                                            && $req->purchaseOrder?->isVpApproved())
                                            <a href="{{ route('stock-orders.add-atl', $req->purchase_order_id) }}"
                                               class="btn btn-sm btn-primary-custom rounded-pill px-2 py-1"
                                               title="Raise an ATL against PO #{{ $req->purchaseOrder->po_number }}">
                                                <i class="bi bi-plus-lg me-1"></i> Add ATL
                                            </a>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="12" class="text-center text-muted py-4">No replenishment requests found.</td>
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

{{-- The request form, a floating pop-up used by both Create Request and Pen. --}}
<div class="modal fade" id="requestFormModal" tabindex="-1" aria-labelledby="requestFormModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        {{-- The action is rewritten by script when the pop-up opens in edit
             mode. Stating the create route here means the form still submits
             if that script never runs, rather than posting to the current
             GET-only URL and drawing a 405. --}}
        <form method="POST" action="{{ route('wetstock.stock-requests.store') }}" id="requestForm" class="modal-content rounded-4 border-0 shadow">
            @csrf
            <div class="modal-header border-0 pb-0">
                <h5 class="modal-title fw-bold text-dark" id="requestFormModalLabel">
                    <i class="bi bi-file-earmark-plus text-primary me-1"></i>
                    <span id="requestFormTitle">Create Request</span>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body pt-3">
                <input type="hidden" name="_method_field" id="requestFormMethod">
                <div id="requestFormErrors"></div>

                <div class="row g-3 mb-3">
                    <div class="col-md-6">
                        <label for="requestWarehouse" class="form-label small fw-semibold">Warehouse Location <span class="text-danger">*</span></label>
                        <select name="warehouse_id" id="requestWarehouse" class="form-select form-select-sm" required>
                            <option value="">Select site...</option>
                            @foreach ($warehouses as $wh)
                                <option value="{{ $wh->id }}">{{ $wh->name }}</option>
                            @endforeach
                        </select>
                        @error('warehouse_id')
                            <div class="invalid-feedback d-block">{{ $message }}</div>
                        @enderror
                    </div>
                    <div class="col-md-3">
                        <label for="requestType" class="form-label small fw-semibold">Type <span class="text-danger">*</span></label>
                        <select name="request_type" id="requestType" class="form-select form-select-sm" required>
                            <option value="PICKUP" selected>Pick-up</option>
                            <option value="DELIVERY">Delivery</option>
                        </select>
                        @error('request_type')
                            <div class="invalid-feedback d-block">{{ $message }}</div>
                        @enderror
                    </div>
                    <div class="col-md-3">
                        <label for="requestDateNeeded" class="form-label small fw-semibold">Date Needed</label>
                        <input type="date" name="date_needed" id="requestDateNeeded" class="form-control form-control-sm"
                               value="{{ old('date_needed') }}">
                        @error('date_needed')
                            <div class="invalid-feedback d-block">{{ $message }}</div>
                        @enderror
                    </div>
                </div>

                <div class="mb-3">
                    <label class="form-label small fw-semibold">Product / Quantity <span class="text-danger">*</span></label>
                    <div id="requestProductRows">
                        {{-- Rows are cloned from this template by the add-row script below. --}}
                        <template id="requestProductRowTemplate">
                            <div class="row g-2 mb-2 request-product-row">
                                <div class="col-7">
                                    <select name="lines[__INDEX__][product]" class="form-select form-select-sm request-product">
                                        <option value="">Select product...</option>
                                        <option value="Unleaded">Unleaded</option>
                                        <option value="Diesel">Diesel</option>
                                        <option value="Premium">Premium</option>
                                    </select>
                                </div>
                                <div class="col-4">
                                    <input type="number" name="lines[__INDEX__][qty]" min="1" step="1"
                                           class="form-control form-control-sm text-end request-qty" placeholder="0">
                                </div>
                                <div class="col-1 d-grid">
                                    <button type="button" class="btn btn-sm btn-outline-danger request-remove-row" title="Remove product">
                                        <i class="bi bi-x-lg"></i>
                                    </button>
                                </div>
                                <input type="hidden" name="lines[__INDEX__][id]" value="" class="request-line-id">
                            </div>
                        </template>
                    </div>
                    <button type="button" class="btn btn-sm btn-outline-primary rounded-pill px-3" id="requestAddProduct">
                        <i class="bi bi-plus-lg me-1"></i> Add Product
                    </button>
                    @error('lines')
                        <div class="invalid-feedback d-block">{{ $message }}</div>
                    @enderror
                </div>

                <div class="mb-2">
                    <label for="requestRemarks" class="form-label small fw-semibold">Additional Remarks</label>
                    <textarea name="remarks" id="requestRemarks" rows="3" class="form-control form-control-sm"
                              placeholder="Justification or operational notes">{{ old('remarks') }}</textarea>
                    @error('remarks')
                        <div class="invalid-feedback d-block">{{ $message }}</div>
                    @enderror
                </div>
            </div>
            <div class="modal-footer border-0 pt-0">
                <button type="button" class="btn btn-secondary rounded-pill px-4" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary-custom rounded-pill px-4">
                    <i class="bi bi-send me-1"></i> Send
                </button>
            </div>
        </form>
    </div>
</div>

{{-- Read-only view of a request. --}}
@foreach ($stockRequests as $req)
    <div class="modal fade" id="requestViewModal{{ $req->id }}" tabindex="-1" aria-labelledby="requestViewModalLabel{{ $req->id }}" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content rounded-4 border-0 shadow">
                <div class="modal-header border-0 pb-0">
                    <h5 class="modal-title fw-bold text-dark" id="requestViewModalLabel{{ $req->id }}">
                        <i class="bi bi-file-earmark-text text-primary me-1"></i> Request {{ $req->request_number }}
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body pt-3">
                    <div class="row g-3 mb-3">
                        <div class="col-md-3">
                            <div class="text-muted extra-small fw-semibold text-uppercase">Type</div>
                            <div class="fw-bold">{{ $req->type_label }}</div>
                        </div>
                        <div class="col-md-3">
                            <div class="text-muted extra-small fw-semibold text-uppercase">Site</div>
                            <div class="fw-semibold">{{ $req->warehouse->name ?? '—' }}</div>
                        </div>
                        <div class="col-md-3">
                            <div class="text-muted extra-small fw-semibold text-uppercase">Date Needed</div>
                            <div class="fw-semibold">{{ $req->date_needed ? $req->date_needed->format('M d, Y') : '—' }}</div>
                        </div>
                        <div class="col-md-3">
                            <div class="text-muted extra-small fw-semibold text-uppercase">Status</div>
                            <div class="fw-semibold">{{ $req->status }}</div>
                        </div>
                        <div class="col-md-4">
                            <div class="text-muted extra-small fw-semibold text-uppercase">Requested By</div>
                            <div class="fw-semibold">{{ $req->requester->name ?? 'Depot' }}</div>
                        </div>
                        <div class="col-md-4">
                            <div class="text-muted extra-small fw-semibold text-uppercase">Purchasing</div>
                            <div class="fw-semibold">
                                @if ($req->hasBeenPickedUpByPurchasing())
                                    {{ $req->purchasingReceiver?->name ?? 'Purchasing' }}
                                    <span class="text-muted extra-small">({{ $req->purchasing_received_at?->format('M d, Y') }})</span>
                                @else
                                    <span class="badge bg-warning-subtle text-warning-emphasis border rounded-pill px-2 py-1">Awaiting</span>
                                @endif
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="text-muted extra-small fw-semibold text-uppercase">Raised</div>
                            <div class="fw-semibold">{{ $req->request_date?->format('M d, Y') }}</div>
                        </div>
                        @if ($req->remarks)
                            <div class="col-12">
                                <div class="text-muted extra-small fw-semibold text-uppercase mb-1">Additional Remarks</div>
                                <div class="small">{{ $req->remarks }}</div>
                            </div>
                        @endif
                    </div>

                    <table class="table table-sm align-middle mb-0">
                        <thead>
                            <tr>
                                <th>Product</th>
                                <th class="text-end">Qty. Requested</th>
                                <th class="text-end">Qty. Received</th>
                                <th class="text-end">Qty. Pending</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($req->lines as $line)
                                <tr>
                                    <td class="fw-semibold">{{ $line->product }}</td>
                                    <td class="text-end font-monospace">{{ number_format($line->qty_requested) }}</td>
                                    <td class="text-end font-monospace">{{ number_format($line->qty_received) }}</td>
                                    <td class="text-end font-monospace {{ $line->qty_pending > 0 ? 'text-warning-emphasis fw-semibold' : 'text-success' }}">
                                        {{ number_format($line->qty_pending) }}
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <div class="modal-footer border-0 pt-0">
                    <a href="{{ route('wetstock.stock-requests.index') }}" class="btn btn-outline-secondary rounded-pill px-4">
                        <i class="bi bi-arrow-left me-1"></i> Back to Requests
                    </a>
                    <button type="button" class="btn btn-primary-custom rounded-pill px-4" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>
@endforeach

{{-- Record what actually arrived on a request, per product line. --}}
@foreach ($stockRequests as $req)
    @if (Auth::user()->canReceiveStockIntoDepot() && $req->lines->isNotEmpty())
        <div class="modal fade" id="receiveQuantitiesModal{{ $req->id }}" tabindex="-1" aria-labelledby="receiveQuantitiesModalLabel{{ $req->id }}" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content rounded-4 border-0 shadow">
                    <div class="modal-header border-0 pb-0">
                        <h5 class="modal-title fw-bold text-dark" id="receiveQuantitiesModalLabel{{ $req->id }}">
                            <i class="bi bi-box-arrow-in-down text-success me-1"></i> Record Received &mdash; {{ $req->request_number }}
                        </h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <form method="POST" action="{{ route('wetstock.stock-requests.received', $req->id) }}">
                        @csrf
                        <div class="modal-body pt-3">
                            <table class="table table-sm align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th>Product</th>
                                        <th class="text-end">Requested</th>
                                        <th class="text-end" style="width: 130px;">Received</th>
                                        <th class="text-end">Pending</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($req->lines as $line)
                                        <tr>
                                            <td class="fw-semibold">{{ $line->product }}</td>
                                            <td class="text-end font-monospace">{{ number_format($line->qty_requested) }}</td>
                                            <td>
                                                <input type="number" name="quantities[{{ $line->id }}]" min="0" step="1"
                                                       class="form-control form-control-sm text-end"
                                                       value="{{ $line->qty_received }}">
                                            </td>
                                            <td class="text-end font-monospace">{{ number_format($line->qty_pending) }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                        <div class="modal-footer border-0 pt-0">
                            <button type="button" class="btn btn-secondary rounded-pill px-4" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" class="btn btn-success rounded-pill px-4">
                                <i class="bi bi-check2-circle me-1"></i> Save Received
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    @endif
@endforeach

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

<script>
document.addEventListener('DOMContentLoaded', function () {
    const form = document.getElementById('requestForm');
    const title = document.getElementById('requestFormTitle');
    const rowsHost = document.getElementById('requestProductRows');
    const template = document.getElementById('requestProductRowTemplate');
    const addBtn = document.getElementById('requestAddProduct');
    const storeUrl = @json(route('wetstock.stock-requests.store'));
    const updateUrl = @json(route('wetstock.stock-requests.update', ['stockRequest' => 0]));

    let rowCount = 0;

    // Renumber every lines[__INDEX__] input so the array always submits as a
    // clean list, whatever order rows were added or removed in.
    function reindex() {
        rowsHost.querySelectorAll('.request-product-row').forEach(function (row, index) {
            row.querySelectorAll('[name]').forEach(function (input) {
                input.name = input.name.replace(/lines\[\d+\]/, 'lines[' + index + ']');
            });
        });
    }

    function addRow(line) {
        const html = template.innerHTML.replace(/__INDEX__/g, String(rowCount++));
        rowsHost.insertAdjacentHTML('beforeend', html);

        const row = rowsHost.lastElementChild;
        if (line) {
            row.querySelector('.request-line-id').value = line.id || '';
            row.querySelector('.request-product').value = line.product || '';
            row.querySelector('.request-qty').value = line.qty || '';
        }
        reindex();
    }

    function removeRow(row) {
        if (rowsHost.querySelectorAll('.request-product-row').length > 1) {
            row.remove();
        } else {
            row.querySelector('.request-product').value = '';
            row.querySelector('.request-qty').value = '';
            row.querySelector('.request-line-id').value = '';
        }
        reindex();
    }

    addBtn.addEventListener('click', function () {
        addRow();
    });

    rowsHost.addEventListener('click', function (event) {
        const button = event.target.closest('.request-remove-row');
        if (button) {
            removeRow(button.closest('.request-product-row'));
        }
    });

    function resetForm() {
        form.reset();
        form.action = storeUrl;
        title.textContent = 'Create Request';
        rowsHost.querySelectorAll('.request-product-row').forEach(function (row) { row.remove(); });
        rowCount = 0;
        addRow();
        document.getElementById('requestType').value = 'PICKUP';
    }

    document.querySelectorAll('[data-request-mode]').forEach(function (trigger) {
        trigger.addEventListener('click', function () {
            if (trigger.dataset.requestMode !== 'edit') {
                resetForm();
                return;
            }

            form.action = updateUrl.replace('/0', '/' + trigger.dataset.requestId);
            title.textContent = 'Edit Request ' + trigger.dataset.requestNumber;

            document.getElementById('requestWarehouse').value = trigger.dataset.requestWarehouse || '';
            document.getElementById('requestType').value = trigger.dataset.requestType || 'PICKUP';
            document.getElementById('requestDateNeeded').value = trigger.dataset.requestDateNeeded || '';
            document.getElementById('requestRemarks').value = trigger.dataset.requestRemarks || '';

            rowsHost.querySelectorAll('.request-product-row').forEach(function (row) { row.remove(); });
            rowCount = 0;

            (trigger.dataset.requestLines || '').split(';;').filter(Boolean).forEach(function (raw) {
                const parts = raw.split('|');
                addRow({ id: parts[0], product: parts[1], qty: parts[2] });
            });

            if (rowCount === 0) {
                addRow();
            }
        });
    });

    resetForm();

    // Arriving with ?create=1 from the Incoming Stock page or the dashboard
    // opens the pop-up straight away.
    @if ($openCreate)
        const modal = document.getElementById('requestFormModal');
        if (modal && window.bootstrap) {
            window.bootstrap.Modal.getOrCreateInstance(modal).show();
        }
    @endif

    // Arriving with ?edit={id} is how a rejected edit comes back: the pop-up
    // reopens on that request rather than resetting to a blank create form.
    // Clicking its Pen button reuses the populate logic above.
    @if ($openEdit)
        const penForOpenEdit = document.querySelector(
            '[data-request-mode="edit"][data-request-id="{{ (int) $openEdit }}"]'
        );
        if (penForOpenEdit) {
            penForOpenEdit.click();
        }
        const editModal = document.getElementById('requestFormModal');
        if (editModal && window.bootstrap) {
            window.bootstrap.Modal.getOrCreateInstance(editModal).show();
        }
    @endif
});
</script>
@endsection
