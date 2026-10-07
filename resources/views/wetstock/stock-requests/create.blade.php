@extends('layouts.app')

@section('title', 'Request Stock Replenishment')

@section('content')
<div class="container-fluid py-2">
    <div class="d-flex align-items-center justify-content-between mb-4">
        <div>
            <h2 class="fw-bold text-dark mb-1">Request Stock Replenishment</h2>
            <p class="text-muted small mb-0">Submit a fuel replenishment request directly to Purchasing (Module 3).</p>
        </div>
        <a href="{{ route('wetstock.supplier-orders.index') }}" class="btn btn-secondary-custom">
            <i class="bi bi-arrow-left me-1"></i> Back to Incoming Stock
        </a>
    </div>

    @if (session('danger'))
        <div class="alert alert-danger alert-dismissible fade show rounded-3" role="alert">
            <i class="bi bi-exclamation-triangle-fill me-2"></i> {{ session('danger') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    @endif

    <div class="row justify-content-center">
        <div class="col-lg-8">
            <div class="card card-custom border-0 shadow-sm">
                <div class="card-body p-4">
                    <form action="{{ route('wetstock.stock-requests.store') }}" method="POST">
                        @csrf

                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label fw-semibold text-dark">Depot / Warehouse <span class="text-danger">*</span></label>
                                <select name="warehouse_id" id="warehouseSelect" class="form-select @error('warehouse_id') is-invalid @enderror" required>
                                    <option value="">Select Depot</option>
                                    @foreach ($warehouses as $wh)
                                        {{-- Plain site name: "San Simon", not "San Simon (7 Tanks)". --}}
                                        <option value="{{ $wh->id }}" {{ old('warehouse_id') == $wh->id ? 'selected' : '' }}>
                                            {{ $wh->name }}
                                        </option>
                                    @endforeach
                                </select>
                                @error('warehouse_id')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>

                            <div class="col-md-6">
                                <label class="form-label fw-semibold text-dark">Request Category <span class="text-danger">*</span></label>
                                <select name="po_type" class="form-select @error('po_type') is-invalid @enderror" required>
                                    <option value="STANDARD_REPLENISHMENT" {{ old('po_type') === 'STANDARD_REPLENISHMENT' ? 'selected' : '' }}>Standard Stock Replenishment</option>
                                    <option value="BUY_BACK" {{ old('po_type') === 'BUY_BACK' ? 'selected' : '' }}>Buy Back Inbound Stock</option>
                                </select>
                                @error('po_type')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>

                            <div class="col-md-6">
                                <label class="form-label fw-semibold text-dark">Product / Fuel Type <span class="text-danger">*</span></label>
                                {{-- The three canonical products, matching Order::PRODUCT_TYPES used across Module 1 and 3. --}}
                                <select name="product" class="form-select @error('product') is-invalid @enderror" required>
                                    <option value="">Select Fuel Product</option>
                                    @foreach (['Unleaded', 'Diesel', 'Premium'] as $fuel)
                                        <option value="{{ $fuel }}" {{ old('product') === $fuel ? 'selected' : '' }}>{{ $fuel }}</option>
                                    @endforeach
                                </select>
                                @error('product')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>

                            <div class="col-md-6">
                                <label class="form-label fw-semibold text-dark">Liters Needed <span class="text-danger">*</span></label>
                                <div class="input-group">
                                    {{--
                                        step="1" is deliberate: with min="1" step="100" a
                                        round value like 10000 is invalid ((10000-1) % 100 = 99),
                                        so the browser rejected the entry and snapped it to
                                        10001. The default is 0 so typing replaces the value
                                        instead of appending to it.
                                    --}}
                                    <input type="number" name="qty_ordered" class="form-control @error('qty_ordered') is-invalid @enderror" value="{{ old('qty_ordered', 0) }}" min="0" step="1" required>
                                    <span class="input-group-text">Liters</span>
                                </div>
                                @error('qty_ordered')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>

                            <div class="col-md-6">
                                <label class="form-label fw-semibold text-dark">Date Needed by Depot</label>
                                <input type="date" name="date_needed" class="form-control @error('date_needed') is-invalid @enderror" value="{{ old('date_needed', now()->addDays(2)->format('Y-m-d')) }}">
                                @error('date_needed')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>

                            <div class="col-12">
                                <label class="form-label fw-semibold text-dark">Operational Remarks & Justification</label>
                                <textarea name="remarks" rows="3" class="form-control @error('remarks') is-invalid @enderror" placeholder="Explain reason (e.g. high outflow forecast, tank reached reorder point)...">{{ old('remarks') }}</textarea>
                                @error('remarks')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>
                        </div>

                        <div class="d-flex justify-content-end gap-2 mt-4 pt-3 border-top">
                            <a href="{{ route('wetstock.supplier-orders.index') }}" class="btn btn-secondary-custom">Cancel</a>
                            <button type="submit" class="btn btn-primary-custom px-4">
                                <i class="bi bi-send me-1"></i> Submit to Purchasing
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
