@extends('layouts.app')

@section('title', $title)

@section('content')
<div class="row justify-content-center">
    <div class="col-md-8 col-lg-6">
        <div class="mb-3">
            <a href="{{ route('dashboard') }}" class="text-decoration-none text-secondary small">
                <i class="bi bi-arrow-left me-1"></i> Back to Dashboard
            </a>
        </div>
        <div class="card card-custom p-4 border-0">
            <div class="card-body">
                <h4 class="fw-bold mb-4 text-dark">
                    <i class="bi {{ $order ? 'bi-journal-check' : 'bi-journal-plus' }} text-primary me-2"></i>{{ $title }}
                </h4>
                
                <form method="POST" action="{{ $order ? route('order.update', $order->id) : route('order.store') }}" novalidate>
                    @csrf
                    
                    <div class="mb-3">
                        <label for="account" class="form-label fw-medium text-secondary small">Account</label>
                        <select name="account" id="account" class="form-control form-select @error('account') is-invalid @enderror" required>
                            <option value="" disabled {{ old('account', $order ? $order->account : '') === '' ? 'selected' : '' }}>Select a client account...</option>
                            @foreach ($clients as $client)
                                <option value="{{ $client->name }}" {{ old('account', $order ? $order->account : '') === $client->name ? 'selected' : '' }}>{{ $client->name }}</option>
                            @endforeach
                        </select>
                        @error('account')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    <div class="mb-3">
                        <label for="location" class="form-label fw-medium text-secondary small">Location</label>
                        <select name="location" id="location" class="form-control form-select @error('location') is-invalid @enderror" required>
                            <option value="Valenzuela" {{ old('location', $order ? $order->location : 'Valenzuela') === 'Valenzuela' ? 'selected' : '' }}>Valenzuela</option>
                            <option value="San Simon" {{ old('location', $order ? $order->location : '') === 'San Simon' ? 'selected' : '' }}>San Simon</option>
                        </select>
                        @error('location')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label for="so_number" class="form-label fw-medium text-secondary small">SO Number</label>
                            <input type="text" name="so_number" id="so_number" class="form-control @error('so_number') is-invalid @enderror" placeholder="e.g. SO-12345" value="{{ old('so_number', $order ? $order->so_number : '') }}" required>
                            @error('so_number')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>
                        <div class="col-md-6">
                            <label for="date" class="form-label fw-medium text-secondary small">Order Date</label>
                            <input type="date" name="date" id="date" class="form-control @error('date') is-invalid @enderror" value="{{ old('date', $order && $order->date ? $order->date->format('Y-m-d') : '') }}" required>
                            @error('date')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>
                    </div>

                    <div class="mb-4">
                        <label for="po_number" class="form-label fw-medium text-secondary small">PO Number</label>
                        <input type="text" name="po_number" id="po_number" class="form-control @error('po_number') is-invalid @enderror" placeholder="e.g. PO-12345 (optional)" value="{{ old('po_number', $order ? $order->po_number : '') }}">
                        @error('po_number')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    <div class="mb-4">
                        <label for="qty_ordered" class="form-label fw-medium text-secondary small">Qty Ordered</label>
                        <input type="number" name="qty_ordered" id="qty_ordered" class="form-control @error('qty_ordered') is-invalid @enderror" placeholder="e.g. 1000" value="{{ old('qty_ordered', $order ? $order->qty_ordered : '') }}" required>
                        @error('qty_ordered')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    <div class="d-flex justify-content-end gap-2">
                        <a href="{{ route('dashboard') }}" class="btn btn-light border">Cancel</a>
                        <button type="submit" class="btn btn-primary-custom">Save Order</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
@endsection
