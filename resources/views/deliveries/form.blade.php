@extends('layouts.app')

@section('title', $title)

@section('content')
<div class="row justify-content-center">
    <div class="col-md-8 col-lg-6">
        <div class="mb-3">
            <a href="{{ route('order.deliveries', $order->id) }}" class="text-decoration-none text-secondary small">
                <i class="bi bi-arrow-left me-1"></i> Back to Order Deliveries
            </a>
        </div>
        <div class="card card-custom p-4 border-0">
            <div class="card-body">
                <div class="mb-3">
                    <span class="badge bg-light text-dark border">SO# {{ $order->so_number }}</span>
                    <span class="text-muted small ms-2">{{ $order->account }}</span>
                </div>
                <h4 class="fw-bold mb-4 text-dark">
                    <i class="bi bi-truck text-primary me-2"></i>{{ $title }}
                </h4>
                
                <form method="POST" action="{{ $delivery ? route('delivery.update', $delivery->id) : route('delivery.store', $order->id) }}" novalidate>
                    @csrf
                    
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label for="dr_number" class="form-label fw-medium text-secondary small">DR Number</label>
                            <input type="text" name="dr_number" id="dr_number" class="form-control @error('dr_number') is-invalid @enderror" placeholder="e.g. DR-9876" value="{{ old('dr_number', $delivery ? $delivery->dr_number : '') }}" required>
                            @error('dr_number')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>
                        <div class="col-md-6">
                            <label for="delivery_date" class="form-label fw-medium text-secondary small">Delivery Date</label>
                            <input type="date" name="delivery_date" id="delivery_date" class="form-control @error('delivery_date') is-invalid @enderror" value="{{ old('delivery_date', $delivery && $delivery->delivery_date ? $delivery->delivery_date->format('Y-m-d') : '') }}" required>
                            @error('delivery_date')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>
                    </div>

                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label for="qty_out" class="form-label fw-medium text-secondary small">Qty Out</label>
                            <input type="number" name="qty_out" id="qty_out" class="form-control @error('qty_out') is-invalid @enderror" placeholder="e.g. 50" value="{{ old('qty_out', $delivery ? $delivery->qty_out : '') }}" required>
                            @error('qty_out')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>
                        <div class="col-md-6">
                            <label for="status" class="form-label fw-medium text-secondary small">Status</label>
                            <select name="status" id="status" class="form-control form-select @error('status') is-invalid @enderror" required>
                                <option value="PENDING" {{ old('status', $delivery ? $delivery->status : '') === 'PENDING' ? 'selected' : '' }}>PENDING</option>
                                <option value="FULFILLED" {{ old('status', $delivery ? $delivery->status : '') === 'FULFILLED' ? 'selected' : '' }}>FULFILLED</option>
                                <option value="CANCELLED" {{ old('status', $delivery ? $delivery->status : '') === 'CANCELLED' ? 'selected' : '' }}>CANCELLED</option>
                            </select>
                            @error('status')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>
                    </div>

                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label for="type" class="form-label fw-medium text-secondary small">Type</label>
                            <select name="type" id="type" class="form-control form-select @error('type') is-invalid @enderror" required>
                                <option value="DELIVERY" {{ old('type', $delivery ? $delivery->type : 'DELIVERY') === 'DELIVERY' ? 'selected' : '' }}>DELIVERY</option>
                                <option value="PICK-UP" {{ old('type', $delivery ? $delivery->type : '') === 'PICK-UP' ? 'selected' : '' }}>PICK-UP</option>
                            </select>
                            @error('type')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>
                        <div class="col-md-6"></div>
                    </div>

                    <div class="mb-4">
                        <label for="remarks" class="form-label fw-medium text-secondary small">Additional Notes</label>
                        <textarea name="remarks" id="remarks" class="form-control @error('remarks') is-invalid @enderror" rows="3" placeholder="Optional additional notes...">{{ old('remarks', $delivery ? $delivery->remarks : '') }}</textarea>
                        @error('remarks')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    <div class="d-flex justify-content-end gap-2">
                        <a href="{{ route('order.deliveries', $order->id) }}" class="btn btn-light border">Cancel</a>
                        <button type="submit" class="btn btn-primary-custom">Save Delivery</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
@endsection
