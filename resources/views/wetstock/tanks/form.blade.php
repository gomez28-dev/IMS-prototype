@extends('layouts.app')

@section('title', $title)

@section('content')
<div class="row justify-content-center">
    <div class="col-md-8 col-lg-6">
        <div class="mb-3">
            <a href="{{ route('wetstock.warehouses.show', $warehouse->id) }}" class="text-decoration-none text-secondary small">
                <i class="bi bi-arrow-left me-1"></i> Back to {{ $warehouse->name }} Tanks
            </a>
        </div>
        <div class="card card-custom p-4 border-0">
            <div class="card-body">
                <div class="mb-3">
                    <span class="badge bg-light text-dark border">Warehouse: {{ $warehouse->name }}</span>
                </div>
                <h4 class="fw-bold mb-4 text-dark">
                    <i class="bi bi-box-seam text-primary me-2"></i>{{ $title }}
                </h4>

                <form method="POST" action="{{ $tank ? route('wetstock.tanks.update', $tank->id) : route('wetstock.tanks.store', $warehouse->id) }}" novalidate>
                    @csrf

                    <div class="mb-3">
                        <label for="name" class="form-label fw-medium text-secondary small">Tank Name / Identifier</label>
                        <input type="text" name="name" id="name" class="form-control @error('name') is-invalid @enderror" placeholder="e.g. Tank 1, Tank A" value="{{ old('name', $tank ? $tank->name : '') }}" required>
                        @error('name')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    <div class="mb-4">
                        <label for="max_capacity" class="form-label fw-medium text-secondary small">Max Capacity (Liters)</label>
                        <div class="input-group">
                            <input type="number" name="max_capacity" id="max_capacity" class="form-control @error('max_capacity') is-invalid @enderror" placeholder="e.g. 50000" value="{{ old('max_capacity', $tank ? $tank->max_capacity : '') }}" min="1" required>
                            <span class="input-group-text bg-light text-muted">Liters</span>
                            @error('max_capacity')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>
                        <div class="form-text small text-muted">Enter total volume capacity of this tank in whole liters.</div>
                    </div>

                    <div class="d-flex justify-content-end gap-2">
                        <a href="{{ route('wetstock.warehouses.show', $warehouse->id) }}" class="btn btn-light border">Cancel</a>
                        <button type="submit" class="btn btn-primary-custom">Save Tank</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
@endsection
