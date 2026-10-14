@extends('layouts.app')

@section('title', $mode === 'fuel_trade' ? 'Authority to Load (ATL) Issuance' : ($mode === 'add_atl' ? 'Add ATL to Purchase Order' : 'Process Stock Order & ATL'))

@php $isAddAtl = $mode === 'add_atl'; @endphp

@section('content')
<div class="row justify-content-center">
    <div class="col-md-10 col-lg-9">
        <div class="mb-3">
            <a href="{{ route('stock-orders.index') }}" class="text-decoration-none text-secondary small">
                <i class="bi bi-arrow-left me-1"></i> Back to Stock Orders
            </a>
        </div>

        <div class="card card-custom border-0 shadow-sm">
            <div class="card-header bg-white py-3 border-bottom d-flex justify-content-between align-items-center flex-wrap gap-2">
                <div>
                    <h4 class="fw-bold mb-0 text-dark">
                        @if ($mode === 'fuel_trade')
                            <i class="bi bi-patch-check-fill text-warning me-2"></i>Issue Authority to Load (ATL)
                        @elseif ($isAddAtl)
                            <i class="bi bi-plus-circle text-primary me-2"></i>Add ATL to PO {{ $purchaseOrder->po_number }}
                        @else
                            <i class="bi bi-card-checklist text-primary me-2"></i>Assign PO Number & Prepare ATL / DR
                        @endif
                    </h4>
                    <p class="text-muted small mb-0 mt-1">
                        @if ($mode === 'fuel_trade')
                            Draw down volume from Supplier POs and issue ATL for Sales Order <strong>{{ $order->formatted_so_number }}</strong>
                        @elseif ($isAddAtl)
                            Approved purchase order for <strong>{{ $purchaseOrder->supplier_name ?: ($purchaseOrder->client->name ?? '—') }}</strong>
                            ({{ number_format($purchaseOrder->remaining_balance) }} L remaining)
                        @else
                            Stock Request for <strong>{{ optional($purchaseOrder->warehouse)->name }}</strong> (Requested: {{ number_format($purchaseOrder->qty_ordered) }} L)
                        @endif
                    </p>
                </div>
                @if ($mode === 'fuel_trade')
                    <span class="badge bg-success-subtle text-success border border-success-subtle rounded-pill px-3 py-2">
                        <i class="bi bi-check-circle-fill me-1"></i>Accounting Cleared
                    </span>
                @endif
            </div>

            <div class="card-body p-4">
                {{--
                    Submit eligibility is decided once, here, for both modes.

                    Only Fuel Trade orders are gated on Accounting clearance
                    (they are drawn against a Sales Order). A Depot
                    Replenishment PO has no Sales Order and no clearance
                    step, so it is never blocked.

                    This must be assigned OUTSIDE the mode branch below: the
                    submit button reads it after that branch ends, so
                    assigning it inside the fuel_trade branch left it
                    undefined in edit_request mode and 500'd the page.
                --}}
                @php
                    $isCleared = $mode === 'fuel_trade'
                        ? ($order && $order->clearing_status === 'Approved')
                        : true;
                @endphp
                <form method="POST" action="{{ $mode === 'fuel_trade' ? route('stock-orders.store-fuel-trade-po', $order->id) : ($isAddAtl ? route('stock-orders.store-atl', $purchaseOrder->id) : route('stock-orders.update-request', $purchaseOrder->id)) }}" id="atlForm">
                    @csrf

                    @if ($errors->any())
                        <div class="alert alert-danger border-0 rounded-3 shadow-sm mb-4">
                            <ul class="mb-0 small ps-3">
                                @foreach ($errors->all() as $err)
                                    <li>{{ $err }}</li>
                                @endforeach
                            </ul>
                        </div>
                    @endif

                    @if ($mode === 'fuel_trade')
@php
                                // Volume this Sales Order requires, per product.
                                $soRequirements = $order->productRequirements();
                                $requiredProducts = array_keys($soRequirements);
                                $defaultReqProduct = $requiredProducts[0] ?? 'Diesel';
                                $productOptions = ['Diesel', 'Premium', 'Unleaded'];
                            @endphp

                        {{-- ATL source: Doyen issues the ATL, or the client provides it --}}
                        <div class="card border-0 bg-light rounded-3 mb-4">
                            <div class="card-body p-3">
                                <label class="form-label fw-semibold small mb-2">Who Issues the ATL? <span class="text-danger">*</span></label>
                                <div class="d-flex gap-4 flex-wrap">
                                    <div class="form-check">
                                        <input class="form-check-input atl-source-input" type="radio" name="atl_source"
                                               id="atlSourceDoyen" value="DOYEN_ISSUED"
                                               {{ old('atl_source', 'DOYEN_ISSUED') === 'DOYEN_ISSUED' ? 'checked' : '' }}>
                                        <label class="form-check-label small" for="atlSourceDoyen">
                                            <strong>Doyen issues</strong> &mdash; needs VP approval
                                        </label>
                                    </div>
                                    <div class="form-check">
                                        <input class="form-check-input atl-source-input" type="radio" name="atl_source"
                                               id="atlSourceClient" value="CLIENT_PROVIDED"
                                               {{ old('atl_source') === 'CLIENT_PROVIDED' ? 'checked' : '' }}>
                                        <label class="form-check-label small" for="atlSourceClient">
                                            <strong>Client provides</strong> &mdash; recorded only, no approval
                                        </label>
                                    </div>
                                </div>
                            </div>
                        </div>

                        {{-- Client-provided ATL details --}}
                        <div id="clientAtlFields" class="mb-4" style="display: {{ old('atl_source') === 'CLIENT_PROVIDED' ? 'block' : 'none' }};">
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label for="client_atl_number" class="form-label fw-semibold small">Client ATL Number <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control @error('client_atl_number') is-invalid @enderror" id="client_atl_number"
                                           name="client_atl_number" placeholder="e.g. ATL-2026-0099"
                                           value="{{ old('client_atl_number', $order->client_atl_number) }}">
                                    <div class="form-text">Required when the client issues the ATL.</div>
                                    @error('client_atl_number')
                                        <div class="invalid-feedback">{{ $message }}</div>
                                    @enderror
                                </div>
                                <div class="col-md-6">
                                    <label for="scanned_doc_url" class="form-label fw-semibold small">Scanned ATL (Google Drive link)</label>
                                    <input type="url" class="form-control @error('scanned_doc_url') is-invalid @enderror" id="scanned_doc_url"
                                           name="scanned_doc_url" placeholder="https://drive.google.com/file/d/..."
                                           value="{{ old('scanned_doc_url') }}">
                                    @error('scanned_doc_url')
                                        <div class="invalid-feedback">{{ $message }}</div>
                                    @enderror
                                </div>
                            </div>
                        </div>

                        @if (! $isCleared)
                            <div class="alert alert-warning border-0 rounded-3 mb-4 small">
                                <i class="bi bi-exclamation-triangle me-1"></i>
                                Accounting clearance is <strong>{{ $order->clearing_status ?: 'Pending' }}</strong> for this order.
                                Every ATL goes to the VP for approval, so this one cannot be created until
                                Accounting clears the order.
                            </div>
                        @endif

                        @error('atl_source')
                            <div class="alert alert-danger border-0 rounded-3 mb-4 small">{{ $message }}</div>
                        @enderror

                        {{-- Carries the product the order is for, so the server
                             reads the same requirement map the page rendered.
                             Hard-coding Diesel here made the two disagree: the
                             form would pre-select the order's real product and
                             the save would reject it as "not required". --}}
                        <input type="hidden" name="product" value="{{ $defaultReqProduct }}">
                        <input type="hidden" id="targetOrderQty" value="{{ $order->qty_ordered }}">
                        <input type="hidden" id="soRequirements" value="{{ json_encode($soRequirements) }}">

                        {{-- Order Summary Banner --}}
                        <div class="card bg-light border-0 rounded-3 p-3 mb-4">
                            <div class="row g-3 align-items-center">
                                <div class="col-md-3">
                                    <span class="text-muted extra-small uppercase fw-semibold d-block mb-1" style="font-size: 0.7rem;">SALES ORDER #</span>
                                    <span class="badge" style="background-color: #fef08a; color: #854d0e; border: 1px solid #facc15; font-size: 0.95rem;">
                                        <i class="bi bi-fuel-pump me-1"></i>{{ $order->formatted_so_number }}
                                    </span>
                                </div>
                                <div class="col-md-3">
                                    <span class="text-muted extra-small uppercase fw-semibold d-block mb-1" style="font-size: 0.7rem;">CLIENT / ACCOUNT</span>
                                    <span class="fw-bold text-dark fs-6">{{ $order->account }}</span>
                                </div>
                                <div class="col-md-3">
                                    <span class="text-muted extra-small uppercase fw-semibold d-block mb-1" style="font-size: 0.7rem;">PRODUCT</span>
                                    @foreach ($soRequirements as $reqProduct => $reqQty)
                                        <span class="badge bg-secondary-subtle text-secondary border px-2 py-1 me-1">{{ $reqProduct }}</span>
                                    @endforeach
                                </div>
                                <div class="col-md-3 text-md-end">
                                    <span class="text-muted extra-small uppercase fw-semibold d-block mb-1" style="font-size: 0.7rem;">REQUIRED VOLUME</span>
                                    <span class="fw-bold text-primary fs-5 font-monospace">{{ number_format($order->qty_ordered) }} L</span>
                                    @if (count($soRequirements) > 1)
                                        <div class="extra-small text-muted" style="font-size: 0.7rem;">
                                            @foreach ($soRequirements as $reqProduct => $reqQty)
                                                <div>{{ $reqProduct }}: {{ number_format($reqQty) }} L</div>
                                            @endforeach
                                        </div>
                                    @endif
                                </div>
                            </div>
                        </div>

                        {{-- Supplier PO Drawdown Engine --}}
                        <div class="card border border-primary border-opacity-25 rounded-3 mb-4 shadow-none bg-light bg-opacity-25">
                            <div class="card-header bg-white py-3 border-bottom d-flex justify-content-between align-items-center">
                                <div>
                                    <h6 class="fw-bold mb-0 text-dark">
                                        <i class="bi bi-stack text-primary me-2"></i>Supplier PO Allocation Pool (Kabangga ng ATL)
                                    </h6>
                                    <small class="text-muted">Select active Supplier Purchase Order(s) to draw volume from.</small>
                                </div>
                                @if (!empty($availablePos) && count($availablePos) > 0)
                                <button type="button" class="btn btn-sm btn-outline-primary rounded-pill px-3" onclick="addDrawdownRow()">
                                    <i class="bi bi-plus-lg me-1"></i> Split Across Another PO
                                </button>
                                @endif
                            </div>
                            <div class="card-body p-3">
                                @if (!empty($availablePos) && count($availablePos) > 0)
                                    <div class="table-responsive">
                                        <table class="table table-borderless align-middle mb-0" id="drawdownsTable">
                                            <thead class="table-light small">
                                                <tr>
                                                    <th class="ps-2" style="width: 38%;">
                                                            <span class="text-uppercase" style="letter-spacing:0.06em;">Allocate PO#</span>
                                                            <span class="text-danger">*</span>
                                                        </th>
                                                        <th style="width: 22%;">
                                                            <span class="text-uppercase" style="letter-spacing:0.06em;">Allocate Product</span>
                                                            <span class="text-danger">*</span>
                                                        </th>
                                                        <th style="width: 30%;">
                                                            <span class="text-uppercase" style="letter-spacing:0.06em;">Allocate Quantity</span>
                                                            <span class="text-danger">*</span>
                                                        </th>
                                                        <th class="pe-2 text-center" style="width: 10%;">Remove</th>
                                                </tr>
                                            </thead>
                                            <tbody id="drawdownRowsBody">
                                                <tr class="drawdown-row">
                                                    <td class="ps-2">
                                                        <select name="drawdowns[0][purchase_order_id]" class="form-select form-select-sm po-select" required onchange="handlePoSelectChange(this)">
                                                            <option value="">-- Choose Supplier PO --</option>
                                                            @foreach ($availablePos as $p)
                                                                @php
                                                                    // Availability per product, so the form can offer only the
                                                                    // products this PO still has, and validate the choice.
                                                                    $availByProduct = [];
                                                                    foreach ($productOptions as $pp) {
                                                                        $bal = $p->getAvailableBalanceForProduct($pp);
                                                                        if ($bal > 0) { $availByProduct[$pp] = $bal; }
                                                                    }
                                                                    $avail = $availByProduct[$defaultReqProduct] ?? 0;
                                                                @endphp
                                                                <option value="{{ $p->id }}" data-items="{{ json_encode($availByProduct) }}" data-avail="{{ $avail }}" {{ $loop->first ? 'selected' : '' }}>
                                                                    {{ $p->po_number }} — {{ $p->supplier_name }}
                                                                </option>
                                                            @endforeach
                                                        </select>
                                                    </td>
                                                    <td>
                                                        <select name="drawdowns[0][product]" class="form-select form-select-sm dd-product-select" required onchange="handleProductChange(this)">
                                                            @foreach ($productOptions as $pp)
                                                                <option value="{{ $pp }}" {{ $pp === $defaultReqProduct ? 'selected' : '' }}>{{ $pp }}</option>
                                                            @endforeach
                                                        </select>
                                                    </td>
                                                    <td>
                                                        {{-- The system states what is available under the chosen
                                                             PO and product; the user then enters the volume. --}}
                                                        <div class="mb-1">
                                                            <span class="badge rounded-pill px-2 py-1 border" data-avail-hint>—</span>
                                                        </div>
                                                        <div class="input-group input-group-sm">
                                                            @php
                                                                $firstAvail = $availablePos[0]->getAvailableBalanceForProduct($defaultReqProduct);
                                                                $suggested = min($soRequirements[$defaultReqProduct] ?? $order->qty_ordered, $firstAvail);
                                                            @endphp
                                                            <input type="number" name="drawdowns[0][quantity]" class="form-control form-control-sm dd-qty-input font-monospace"
                                                                   placeholder="Liters" min="1" step="1" max="{{ $firstAvail }}" value="{{ old('drawdowns.0.quantity', $suggested) }}" required oninput="calculateTotalDrawn()">
                                                            <span class="input-group-text">L</span>
                                                        </div>
                                                    </td>
                                                    <td class="pe-2 text-center">
                                                        <button type="button" class="btn btn-sm btn-link text-danger p-0" onclick="removeDrawdownRow(this)" disabled>
                                                            <i class="bi bi-trash"></i>
                                                        </button>
                                                    </td>
                                                </tr>
                                            </tbody>
                                            <tfoot class="table-light border-top">
                                                <tr>
                                                    <td class="ps-2 fw-semibold text-dark" colspan="3">
                                                        Total Allocated for this ATL:
                                                        <div class="extra-small fw-normal text-muted mt-1" id="productBreakdown"></div>
                                                    </td>
                                                    <td class="pe-2 d-flex align-items-center justify-content-between">
                                                        <span class="fw-bold fs-6 font-monospace" id="totalDrawnDisplay">0 L</span>
                                                        <span id="matchBadge" class="badge rounded-pill px-3 py-1">Checking...</span>
                                                    </td>
                                                </tr>
                                            </tfoot>
                                        </table>
                                    </div>
                                @else
                                    <div class="alert alert-warning border-0 rounded-3 mb-3 small">
                                        <i class="bi bi-info-circle me-1"></i> No bulk Supplier POs currently have active remaining Diesel allocations. You can enter an ad-hoc Supplier PO number below, or <a href="{{ route('stock-orders.create-supplier-po') }}" target="_blank" class="fw-bold text-dark text-decoration-underline">create a Bulk Supplier PO</a> first.
                                    </div>
                                    <div class="row g-3">
                                        <div class="col-md-6">
                                            <label for="po_number" class="form-label fw-semibold small">Supplier PO Number <span class="text-danger">*</span></label>
                                            <input type="text" class="form-control" id="po_number" name="po_number"
                                                   placeholder="e.g. PO-PETRON-2026-0012" value="{{ old('po_number') }}" required>
                                        </div>
                                        <div class="col-md-6">
                                            <label for="supplier_name" class="form-label fw-semibold small">Supplier / Refinery <span class="text-danger">*</span></label>
                                            <input type="text" class="form-control" id="supplier_name" name="supplier_name"
                                                   placeholder="e.g. Petron Bataan Refinery" value="{{ old('supplier_name') }}" required>
                                        </div>
                                        <input type="hidden" name="qty_to_receive" value="{{ $order->qty_ordered }}">
                                    </div>
                                @endif
                            </div>
                        </div>

                        {{-- Fulfillment Channel & Mobile Tanker Linkage --}}
                        <div class="row g-3 mb-3">
                            <div class="col-md-6">
                                <label for="delivery_channel" class="form-label fw-semibold small">Fulfillment Channel <span class="text-danger">*</span></label>
                                <select class="form-select" id="delivery_channel" name="delivery_channel" required onchange="handleDeliveryChannelChange(this.value)">
                                    <option value="SUPPLIER_CLIENT_PICKUP" selected>Supplier - Client Pick-up at Rack (Bypasses Wet Stocks)</option>
                                    <option value="SUPPLIER_DOYEN_PICKUP">Supplier - DITC Tanker Pick-up (Direct Stock-In to Tanker)</option>
                                    <option value="BUY_BACK_CLIENT_PICKUP">Buy Back - Client Pick-up from Us</option>
                                    <option value="BUY_BACK_DOYEN_PICKUP">Buy Back - DITC Pick-up from Client (Direct Stock-In to Tanker)</option>
                                </select>
                            </div>
                            <div class="col-md-6" id="tankerContainer" style="display: none;">
                                <label for="storage_tank_id" class="form-label fw-semibold small">Assigned DITC Mobile Tanker Truck <span class="text-danger">*</span></label>
                                <select class="form-select" id="storage_tank_id" name="storage_tank_id" onchange="handleTankerSelectChange(this)">
                                    <option value="">-- Choose Mobile Tanker Truck --</option>
                                    @if(isset($mobileTankers))
                                        @foreach($mobileTankers as $tank)
                                            <option value="{{ $tank->id }}" data-capacity="{{ $tank->remaining_capacity }}" data-plate="{{ $tank->name }}">
                                                {{ $tank->name }} ({{ $tank->warehouse->name ?? 'Depot' }}) — Remaining: {{ number_format($tank->remaining_capacity) }} L / Max: {{ number_format($tank->max_capacity) }} L
                                            </option>
                                        @endforeach
                                    @endif
                                </select>
                                <div class="form-text text-primary extra-small" style="font-size: 0.75rem;">
                                    <i class="bi bi-info-circle me-1"></i>Fuel will be automatically stocked into this tanker upon confirming lift.
                                </div>
                            </div>
                        </div>

                        {{-- ATL & Logistics Details --}}
                        <div class="row g-3 mb-3">
                            <div class="col-md-6">
                                <label for="atl_type" class="form-label fw-semibold small">ATL Issuing Party <span class="text-danger">*</span></label>
                                <select class="form-select" id="atl_type" name="atl_type" required>
                                    <option value="DITC_ATL" {{ empty($order->client_atl_number) ? 'selected' : '' }}>DITC Issues ATL (Official Template PDF)</option>
                                    <option value="CLIENT_ATL" {{ !empty($order->client_atl_number) ? 'selected' : '' }}>Client Provided ATL (Direct Refinery Entry)</option>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label for="atl_number" class="form-label fw-semibold small">ATL Number</label>
                                <input type="text" class="form-control" id="atl_number" name="atl_number"
                                       placeholder="e.g. ATL-2026-0099" value="{{ old('atl_number') }}">
                            </div>
                        </div>

                        {{-- Supplier Serial Numbers & Documentation Hub --}}
                        <div class="card border rounded-3 p-3 mb-3 bg-white">
                            <h6 class="fw-bold mb-2 text-dark small">
                                <i class="bi bi-file-earmark-check text-success me-1"></i>Refinery Serial Numbers & Documentation Hub
                            </h6>
                            <div class="row g-3 mb-2">
                                <div class="col-md-6">
                                    <label for="supplier_so_number" class="form-label fw-semibold small">Supplier SO / Contract #</label>
                                    <input type="text" class="form-control" id="supplier_so_number" name="supplier_so_number"
                                           placeholder="e.g. PETRON-SO-8842" value="{{ old('supplier_so_number') }}">
                                </div>
                                <div class="col-md-6">
                                    <label for="supplier_dr_number" class="form-label fw-semibold small">Supplier Rack DR # (if already issued)</label>
                                    <input type="text" class="form-control" id="supplier_dr_number" name="supplier_dr_number"
                                           placeholder="e.g. DR-99014" value="{{ old('supplier_dr_number') }}">
                                </div>
                            </div>
                            <div>
                                <label for="scanned_doc_url" class="form-label fw-semibold small">
                                    <i class="bi bi-google text-danger me-1"></i>Google Drive Scanned Document Link
                                </label>
                                <div class="input-group">
                                    <span class="input-group-text bg-light"><i class="bi bi-link-45deg"></i></span>
                                    <input type="url" class="form-control" id="scanned_doc_url" name="scanned_doc_url"
                                           placeholder="https://drive.google.com/file/d/... or folder link" value="{{ old('scanned_doc_url') }}">
                                </div>
                                <div class="form-text text-muted" style="font-size: 0.75rem;">
                                    Paste shareable link to scanned physical DR, loading tickets, and calibration sheets.
                                </div>
                            </div>
                        </div>

                    @else
                        {{-- Standard Depot Replenishment Mode --}}
                        <div class="row g-3 mb-3">
                            <div class="col-md-6">
                                <label for="po_number" class="form-label fw-semibold small">
                                    Official PO Number
                                    @unless ($isAddAtl)<span class="text-danger">*</span>@endunless
                                </label>
                                <input type="text" class="form-control @error('po_number') is-invalid @enderror @if ($isAddAtl) bg-light @endif" id="po_number" name="po_number"
                                       @if ($isAddAtl) readonly @endif
                                       placeholder="e.g. PO-2026-0012" value="{{ old('po_number', $purchaseOrder->po_number ?? '') }}" required>
                                @if ($isAddAtl)
                                    <div class="form-text extra-small">Already approved, so the PO number is fixed.</div>
                                @endif
                            </div>
                            <div class="col-md-6">
                                <label for="supplier_name" class="form-label fw-semibold small">
                                    Supplier / Refinery Terminal
                                    @unless ($isAddAtl)<span class="text-danger">*</span>@endunless
                                </label>
                                <input type="text" class="form-control @error('supplier_name') is-invalid @enderror @if ($isAddAtl) bg-light @endif" id="supplier_name" name="supplier_name"
                                       @if ($isAddAtl) readonly @endif
                                       placeholder="e.g. Petron Bataan Refinery" value="{{ old('supplier_name', $purchaseOrder->supplier_name ?? '') }}" required>
                            </div>
                        </div>

                        <div class="row g-3 mb-3">
                            <div class="col-md-6">
                                <label for="delivery_channel" class="form-label fw-semibold small">Fulfillment Channel <span class="text-danger">*</span></label>
                                <select class="form-select" id="delivery_channel" name="delivery_channel" required>
                                    <option value="SUPPLIER_DOYEN_PICKUP" selected>Supplier - DITC Tanker Pick-up (Issue DITC ATL)</option>
                                    <option value="SUPPLIER_STOCKS_DELIVERY">Supplier - Direct Stocks Delivery to Depot (DR)</option>
                                    <option value="BUY_BACK_DOYEN_PICKUP">Buy Back - DITC Pick-up from Client (Issue DITC ATL)</option>
                                    <option value="BUY_BACK_CLIENT_PICKUP">Buy Back - Client Pick-up (Client / DITC ATL)</option>
                                    <option value="BUY_BACK_STOCKS_DELIVERY">Buy Back - Direct Delivery to Depot (DR)</option>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label for="order_type" class="form-label fw-semibold small">Order Type <span class="text-danger">*</span></label>
                                <select class="form-select" id="order_type" name="order_type" required>
                                    <option value="PICK_UP" selected>PICK UP (Requires ATL)</option>
                                    <option value="DELIVERY">DELIVERY (Supplier DR)</option>
                                </select>
                            </div>
                        </div>

                        <div class="row g-3 mb-3">
                            <div class="col-md-6">
                                <label for="product" class="form-label fw-semibold small">Product <span class="text-danger">*</span></label>
                                <select class="form-select" id="product" name="product" required>
                                    <option value="Diesel" selected>Diesel</option>
                                    <option value="Unleaded">Unleaded</option>
                                    <option value="Premium">Premium</option>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label for="qty_to_receive" class="form-label fw-semibold small">Quantity (Liters) <span class="text-danger">*</span></label>
                                <input type="number" class="form-control" id="qty_to_receive" name="qty_to_receive"
                                       value="{{ old('qty_to_receive', $purchaseOrder->qty_ordered ?? 10000) }}" required min="1">
                            </div>
                        </div>
                    @endif

                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label for="receiving_date" class="form-label fw-semibold small">Authorized Pick Up Date <span class="text-danger">*</span></label>
                            <input type="date" class="form-control" id="receiving_date" name="receiving_date"
                                   value="{{ old('receiving_date', date('Y-m-d')) }}" required>
                        </div>
                        <div class="col-md-6">
                            <label for="location" class="form-label fw-semibold small">Refinery Rack / Terminal Location</label>
                            <input type="text" class="form-control" id="location" name="location"
                                   placeholder="e.g. Petron Limay Terminal, Bataan" value="{{ old('location') }}">
                        </div>
                    </div>

                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label for="driver_name" class="form-label fw-semibold small">Authorized Driver Name</label>
                            <input type="text" class="form-control" id="driver_name" name="driver_name"
                                   placeholder="e.g. Juan Dela Cruz" value="{{ old('driver_name') }}">
                        </div>
                        <div class="col-md-6">
                            <label for="plate_number" class="form-label fw-semibold small">Hauler Truck Plate Number</label>
                            <input type="text" class="form-control" id="plate_number" name="plate_number"
                                   placeholder="e.g. NBD-8899" value="{{ old('plate_number') }}">
                        </div>
                    </div>

                    <div class="mb-4">
                        <label for="additional_remarks" class="form-label fw-semibold small">Special Instructions / Remarks</label>
                        <textarea class="form-control" id="additional_remarks" name="additional_remarks" rows="2"
                                  placeholder="Dip requirements, refinery safety passes, calibration notes... ">{{ old('additional_remarks') }}</textarea>
                    </div>

                    {{-- One submit button serves three forms, so the label has to say
                         which one it is. On the Fuel Trade form the ATL is created and
                         queued in a single action; the Prepare form submits a purchase
                         order, not an ATL. --}}
                    @php
                        $submitLabel = match ($mode) {
                            'fuel_trade' => 'Create ATL & Send for Approval',
                            'add_atl' => 'Create ATL',
                            default => 'Submit for Approval',
                        };
                    @endphp
                    <div class="d-flex justify-content-end gap-2 flex-wrap">
                        <a href="{{ route('stock-orders.atls.index') }}" class="btn btn-light border rounded-pill px-4">Cancel</a>
                        <button type="submit"
                                class="btn btn-primary-custom rounded-pill px-4 shadow-sm {{ $isCleared ? '' : 'disabled' }}"
                                id="submitBtn"
                                @disabled(! $isCleared)
                                title="{{ $isCleared ? ($mode === 'fuel_trade' ? 'Create the ATL and send it to the VP approval queue' : 'Save and send for VP approval') : 'Blocked until Accounting clears this order' }}">
                            <i class="bi bi-patch-check me-1"></i> {{ $submitLabel }}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
let ddIndex = 1;
const productOptionsHtml = (function () {
    const sel = document.querySelector('.dd-product-select');
    return sel ? sel.innerHTML : '<option value="Diesel">Diesel</option>';
})();

function poAvailability(poSelect) {
    const opt = poSelect.options[poSelect.selectedIndex];
    if (!opt) return {};
    try {
        return JSON.parse(opt.getAttribute('data-items') || '{}');
    } catch (e) {
        return {};
    }
}

function handleProductChange(select) {
    const row = select.closest('.drawdown-row');
    const product = select.value;
    const avail = parseInt(poAvailability(row.querySelector('.po-select'))[product] || 0);

    const qtyInput = row.querySelector('.dd-qty-input');
    if (qtyInput) {
        qtyInput.max = avail;
        if (parseInt(qtyInput.value || 0) > avail) {
            qtyInput.value = avail || '';
        }
    }

    calculateTotalDrawn();
}

function updateAvailHint(row) {
    const product = row.querySelector('.dd-product-select').value;
    const avail = parseInt(poAvailability(row.querySelector('.po-select'))[product] || 0);
    const hint = row.querySelector('[data-avail-hint]');
    if (hint) {
        // A visible badge rather than a line of small text: the wireframe
        // calls for the system to state what is available before the user
        // types a volume.
        hint.textContent = avail > 0
            ? avail.toLocaleString() + ' L Available'
            : 'No ' + product + ' left on this PO';
        hint.className = 'badge rounded-pill px-2 py-1 border ' + (avail > 0
            ? 'bg-success-subtle text-success border-success-subtle'
            : 'bg-danger-subtle text-danger border-danger-subtle');
    }
    return avail;
}

/**
 * After a PO is chosen, offer only the products that PO still holds, which is
 * what the supervisor asked for: "after maka select ng PO# may mag show na
 * kung anong product at ilang qty ang available under that PO".
 */
function refreshProductOptions(row, preferredProduct) {
    const select = row.querySelector('.dd-product-select');
    if (!select) return;

    const availability = poAvailability(row.querySelector('.po-select'));
    const previous = preferredProduct || select.value;

    let html = '';
    Object.keys(availability).forEach(function (product) {
        html += '<option value="' + product + '">' + product + '</option>';
    });

    // No PO chosen yet, or a PO with nothing left: fall back to the full list
    // so the select is never empty and the user can still see the products.
    select.innerHTML = html || productOptionsHtml;

    if (availability[previous] !== undefined) {
        select.value = previous;
    } else if (!html && previous) {
        select.value = previous;
    }
}

function addDrawdownRow() {
    const tbody = document.getElementById('drawdownRowsBody');
    if (!tbody) return;

    const firstSelect = tbody.querySelector('.po-select');
    if (!firstSelect) return;

    const optionsHtml = firstSelect.innerHTML;
    const tr = document.createElement('tr');
    tr.className = 'drawdown-row';
    tr.innerHTML = `
        <td class="ps-2">
            <select name="drawdowns[${ddIndex}][purchase_order_id]" class="form-select form-select-sm po-select" required onchange="handlePoSelectChange(this)">
                ${optionsHtml}
            </select>
        </td>
        <td>
            <select name="drawdowns[${ddIndex}][product]" class="form-select form-select-sm dd-product-select" required onchange="handleProductChange(this)">
                ${productOptionsHtml}
            </select>
        </td>
        <td>
            <div class="mb-1">
                <span class="badge rounded-pill px-2 py-1 border" data-avail-hint>—</span>
            </div>
            <div class="input-group input-group-sm">
                <input type="number" name="drawdowns[${ddIndex}][quantity]" class="form-control form-control-sm dd-qty-input font-monospace"
                       placeholder="Liters" min="1" step="1" required oninput="calculateTotalDrawn()">
                <span class="input-group-text">L</span>
            </div>
        </td>
        <td class="pe-2 text-center">
            <button type="button" class="btn btn-sm btn-link text-danger p-0" onclick="removeDrawdownRow(this)">
                <i class="bi bi-trash"></i>
            </button>
        </td>
    `;
    tbody.appendChild(tr);
    ddIndex++;
    updateDrawdownRemoveButtons();
    calculateTotalDrawn();
}

function removeDrawdownRow(btn) {
    const row = btn.closest('tr');
    row.remove();
    updateDrawdownRemoveButtons();
    calculateTotalDrawn();
}

function updateDrawdownRemoveButtons() {
    const rows = document.querySelectorAll('.drawdown-row');
    rows.forEach(r => {
        const removeBtn = r.querySelector('button');
        if (removeBtn) {
            removeBtn.disabled = rows.length === 1;
        }
    });
}

function handlePoSelectChange(select) {
    const row = select.closest('.drawdown-row');
    // Narrow the product list to what this PO actually holds, then re-run the
    // quantity step so the availability badge reflects the new pair.
    refreshProductOptions(row);
    handleProductChange(row.querySelector('.dd-product-select'));
}

function calculateTotalDrawn() {
    let requirements = {};
    const reqEl = document.getElementById('soRequirements');
    if (reqEl) {
        try { requirements = JSON.parse(reqEl.value || '{}'); } catch (e) { requirements = {}; }
    }

    const drawnByProduct = {};
    let total = 0;
    let overAllocated = false;

    document.querySelectorAll('.drawdown-row').forEach(row => {
        const product = row.querySelector('.dd-product-select').value;
        const qty = parseInt(row.querySelector('.dd-qty-input').value) || 0;

        drawnByProduct[product] = (drawnByProduct[product] || 0) + qty;
        total += qty;

        if (qty > updateAvailHint(row)) {
            overAllocated = true;
        }
    });

    const display = document.getElementById('totalDrawnDisplay');
    if (display) {
        display.textContent = total.toLocaleString() + ' L';
    }

    // Per-product breakdown so the user can see each requirement being met.
    const breakdown = document.getElementById('productBreakdown');
    if (breakdown) {
        let html = '';
        Object.keys(drawnByProduct).forEach(product => {
            const drawn = drawnByProduct[product];
            const required = requirements[product];
            let note;
            if (required === undefined) {
                note = '<span class="text-danger">not required by this SO</span>';
            } else if (drawn === required) {
                note = '<span class="text-success">&#10003; matched</span>';
            } else if (drawn > required) {
                note = '<span class="text-danger">over by ' + (drawn - required).toLocaleString() + ' L</span>';
            } else {
                note = '<span class="text-warning-emphasis">short by ' + (required - drawn).toLocaleString() + ' L</span>';
            }
            html += '<div>' + product + ': <strong>' + drawn.toLocaleString() + ' L</strong> of '
                + (required === undefined ? '0 L' : required.toLocaleString() + ' L') + ' &mdash; ' + note + '</div>';
        });
        breakdown.innerHTML = html;
    }

    const badge = document.getElementById('matchBadge');
    if (badge) {
        const products = Object.keys(requirements);
        const allMatched = products.length > 0 && products.every(
            product => (drawnByProduct[product] || 0) === requirements[product]
        );

        if (overAllocated) {
            badge.className = 'badge bg-danger-subtle text-danger border border-danger-subtle rounded-pill px-3 py-1';
            badge.innerHTML = '<i class="bi bi-exclamation-triangle-fill me-1"></i> Exceeds PO availability';
        } else if (allMatched) {
            badge.className = 'badge bg-success-subtle text-success border border-success-subtle rounded-pill px-3 py-1';
            badge.innerHTML = '<i class="bi bi-check-circle-fill me-1"></i> 100% Matched';
        } else {
            badge.className = 'badge bg-warning-subtle text-warning-emphasis border border-warning-subtle rounded-pill px-3 py-1';
            badge.innerHTML = '<i class="bi bi-clock-history me-1"></i> Check per product';
        }
    }
}

function handleDeliveryChannelChange(channel) {
    const container = document.getElementById('tankerContainer');
    const isTanker = channel === 'SUPPLIER_DOYEN_PICKUP' || channel === 'BUY_BACK_DOYEN_PICKUP';
    if (container) {
        container.style.display = isTanker ? 'block' : 'none';
        const tankerSelect = document.getElementById('storage_tank_id');
        if (tankerSelect) {
            tankerSelect.required = isTanker;
        }
    }
}

function handleTankerSelectChange(select) {
    const selectedOption = select.options[select.selectedIndex];
    if (selectedOption && selectedOption.dataset.plate) {
        const plateInput = document.getElementById('plate_number');
        if (plateInput && !plateInput.value) {
            plateInput.value = selectedOption.dataset.plate;
        }
    }
}

document.addEventListener('DOMContentLoaded', function() {
    calculateTotalDrawn();
    const channelSelect = document.getElementById('delivery_channel');
    if (channelSelect) {
        handleDeliveryChannelChange(channelSelect.value);
    }

    // Doyen-issued vs client-provided ATL: client-provided records need their
    // own number and Drive link, and never enter the VP approval queue.
    const sourceInputs = document.querySelectorAll('.atl-source-input');
    const clientFields = document.getElementById('clientAtlFields');
    const submitBtn = document.getElementById('submitBtn');

    function applySourceVisibility() {
        const clientRadio = document.getElementById('atlSourceClient');
        const isClient = clientRadio && clientRadio.checked;

        if (clientFields) {
            clientFields.style.display = isClient ? 'block' : 'none';
        }

        if (submitBtn) {
            // Client-provided ATLs skip approval, so submitting for approval is
            // not a meaningful action for them.
            submitBtn.style.display = isClient ? 'none' : '';
        }
    }

    sourceInputs.forEach(function (input) {
        input.addEventListener('change', applySourceVisibility);
    });
    applySourceVisibility();
});
</script>
@endsection
