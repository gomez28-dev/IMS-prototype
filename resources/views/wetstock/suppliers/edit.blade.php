@extends('layouts.app')

@section('title', 'Edit Supplier')

@section('content')
<div class="row justify-content-center">
    <div class="col-md-8 col-lg-6">
        <div class="mb-3">
            <a href="{{ route('stock-orders.suppliers.index') }}" class="text-decoration-none text-secondary small">
                <i class="bi bi-arrow-left me-1"></i> Back to Suppliers
            </a>
        </div>
        <div class="card card-custom p-4 border-0">
            <div class="card-body">
                <h4 class="fw-bold mb-4 text-dark">
                    <i class="bi bi-building-gear text-primary me-2"></i>Edit Supplier
                </h4>

                <form method="POST" action="{{ route('stock-orders.suppliers.update', $supplier->id) }}" novalidate>
                    @csrf

                    <div class="mb-3">
                        <label for="company_name" class="form-label fw-medium text-secondary small">Company <span class="text-danger">*</span></label>
                        <input type="text" name="company_name" id="company_name" class="form-control @error('company_name') is-invalid @enderror"
                               placeholder="e.g. AGIEKO FUEL TRADING" value="{{ old('company_name', $supplier->company_name) }}" required>
                        @error('company_name')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    <div class="mb-3">
                        <label for="location" class="form-label fw-medium text-secondary small">Location <span class="text-danger">*</span></label>
                        <input type="text" name="location" id="location" class="form-control @error('location') is-invalid @enderror"
                               placeholder="e.g. LIMAY TERMINAL" value="{{ old('location', $supplier->location) }}" required>
                        <div class="form-text">One entry per company and location. A company may have several locations.</div>
                        @error('location')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    <div class="mb-3">
                        <label for="address" class="form-label fw-medium text-secondary small">Address</label>
                        <input type="text" name="address" id="address" class="form-control @error('address') is-invalid @enderror"
                               placeholder="e.g. Brgy. Poblacion, Limay, Bataan" value="{{ old('address', $supplier->address) }}">
                        @error('address')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    <div class="mb-4">
                        <label for="attention" class="form-label fw-medium text-secondary small">Attention</label>
                        <input type="text" name="attention" id="attention" class="form-control @error('attention') is-invalid @enderror"
                               placeholder="e.g. Mr. Aaron Uy" value="{{ old('attention', $supplier->attention) }}">
                        @error('attention')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    <div class="d-flex justify-content-end gap-2">
                        <a href="{{ route('stock-orders.suppliers.index') }}" class="btn btn-light border">Cancel</a>
                        <button type="submit" class="btn btn-primary-custom">Update Supplier</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
@endsection
