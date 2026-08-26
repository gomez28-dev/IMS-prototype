@extends('layouts.app')

@section('title', $title)

@section('content')
<div class="row justify-content-center">
    <div class="col-md-8 col-lg-6">
        <div class="card card-custom border-0 shadow-sm">
            <div class="card-header bg-white border-bottom py-3">
                <h5 class="fw-bold text-dark mb-0"><i class="bi bi-box-arrow-in-down me-2 text-primary"></i>{{ $title }}</h5>
            </div>
            <div class="card-body p-4">
                <form action="{{ $supplierOrder ? route('wetstock.supplier-orders.update', $supplierOrder->id) : route('wetstock.supplier-orders.store') }}" method="POST">
                    @csrf
                    @if ($supplierOrder)
                        @method('POST')
                    @endif

                    <div class="mb-3">
                        <label for="po_number" class="form-label fw-semibold">Purchase Order (PO #)</label>
                        <input type="text" name="po_number" id="po_number" class="form-control @error('po_number') is-invalid @enderror" value="{{ old('po_number', $supplierOrder->po_number ?? '') }}" required placeholder="e.g. PO-2026-0089">
                        @error('po_number')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    <div class="mb-3">
                        <label for="atl_dr_number" class="form-label fw-semibold">ATL / D.R. #</label>
                        <input type="text" name="atl_dr_number" id="atl_dr_number" class="form-control @error('atl_dr_number') is-invalid @enderror" value="{{ old('atl_dr_number', $supplierOrder->atl_dr_number ?? '') }}" required placeholder="e.g. ATL-2026-00123">
                        @error('atl_dr_number')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    <div class="mb-3">
                        <label for="warehouse_id" class="form-label fw-semibold">Destination Location / Warehouse</label>
                        <select name="warehouse_id" id="warehouse_id" class="form-select form-control @error('warehouse_id') is-invalid @enderror" required>
                            <option value="">Select Warehouse...</option>
                            @foreach ($warehouses as $wh)
                                <option value="{{ $wh->id }}" {{ old('warehouse_id', $supplierOrder->warehouse_id ?? '') == $wh->id ? 'selected' : '' }}>{{ $wh->name }}</option>
                            @endforeach
                        </select>
                        @error('warehouse_id')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    <div class="mb-3">
                        <label for="supplier_name" class="form-label fw-semibold">Supplier Name</label>
                        <input type="text" name="supplier_name" id="supplier_name" class="form-control @error('supplier_name') is-invalid @enderror" value="{{ old('supplier_name', $supplierOrder->supplier_name ?? '') }}" required placeholder="e.g. Petron / Shell / Unioil">
                        @error('supplier_name')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    <div class="mb-3">
                        <label for="liters" class="form-label fw-semibold">Fuel Volume (Liters)</label>
                        <input type="number" name="liters" id="liters" class="form-control @error('liters') is-invalid @enderror" value="{{ old('liters', $supplierOrder->liters ?? '') }}" required min="1" step="1" placeholder="e.g. 50000">
                        @error('liters')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    <div class="mb-3">
                        <label for="status" class="form-label fw-semibold">Order Status</label>
                        <select name="status" id="status" class="form-select form-control @error('status') is-invalid @enderror" required>
                            <option value="UNLIFTED_PICKUP" {{ old('status', $supplierOrder->status ?? 'UNLIFTED_PICKUP') === 'UNLIFTED_PICKUP' ? 'selected' : '' }}>Unlifted Stock Pick Up (Purchased, waiting for our pickup)</option>
                            <option value="PENDING_DELIVERY" {{ old('status', $supplierOrder->status ?? '') === 'PENDING_DELIVERY' ? 'selected' : '' }}>Pending Stock Delivery (In transit to depot)</option>
                            <option value="COMPLETED" {{ old('status', $supplierOrder->status ?? '') === 'COMPLETED' ? 'selected' : '' }}>Completed / Received (Fuel arrived at depot/tanker)</option>
                            <option value="CANCELLED" {{ old('status', $supplierOrder->status ?? '') === 'CANCELLED' ? 'selected' : '' }}>Cancelled Order</option>
                        </select>
                        @error('status')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    <div class="mb-4">
                        <label for="remarks" class="form-label fw-semibold">Remarks (Optional)</label>
                        <textarea name="remarks" id="remarks" rows="3" class="form-control @error('remarks') is-invalid @enderror" placeholder="Add optional details...">{{ old('remarks', $supplierOrder->remarks ?? '') }}</textarea>
                        @error('remarks')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    @if ($supplierOrder)
                    <div class="mb-4">
                        <label for="modification_reason" class="form-label fw-bold text-dark small">Reason for Modification</label>
                        <textarea name="modification_reason" id="modification_reason" class="form-control @error('modification_reason') is-invalid @enderror" rows="2" placeholder="e.g. PO correction per supplier confirmation...">{{ old('modification_reason') }}</textarea>
                        @error('modification_reason')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                        <div class="form-text small">This change will be submitted as a modification request for Operations Manager review before updating the live record.</div>
                    </div>
                    @endif

                    <div class="d-flex justify-content-between align-items-center">
                        <a href="{{ route('wetstock.supplier-orders.index') }}" class="btn btn-secondary-custom">Cancel</a>
                        <button type="submit" class="btn btn-primary-custom px-4">
                            <i class="bi bi-check-circle me-1"></i> Save Supplier Order
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
@endsection
