@extends('layouts.app')

@section('title', 'Select Portal')

@section('content')
<div class="row justify-content-center align-items-center my-4">
    <div class="col-12 text-center mb-5">
        <h2 class="fw-bold text-dark display-6 mb-2">Welcome to IMS Portal</h2>
        <p class="text-muted fs-5">Select a module to continue</p>
    </div>

    <div class="col-md-5 col-lg-4 mb-4">
        <div class="card card-custom h-100 p-4 border-0 text-center shadow-sm">
            <div class="card-body d-flex flex-column align-items-center justify-content-between">
                <div class="mb-4">
                    <div class="rounded-circle bg-light d-inline-flex align-items-center justify-content-center mb-3" style="width: 80px; height: 80px; color: var(--brand-color);">
                        <i class="bi bi-box-seam fs-1"></i>
                    </div>
                    <h4 class="fw-bold text-dark mb-2">Sales Inventory</h4>
                    <p class="text-muted small">Manage client orders, deliveries, clearances, and generate sales reports.</p>
                </div>
                <a href="{{ route('dashboard') }}" class="btn btn-primary-custom w-100 py-2 fs-6">
                    Enter Sales Inventory <i class="bi bi-arrow-right ms-1"></i>
                </a>
            </div>
        </div>
    </div>

    <div class="col-md-5 col-lg-4 mb-4">
        <div class="card card-custom h-100 p-4 border-0 text-center shadow-sm">
            <div class="card-body d-flex flex-column align-items-center justify-content-between">
                <div class="mb-4">
                    <div class="rounded-circle bg-light d-inline-flex align-items-center justify-content-center mb-3" style="width: 80px; height: 80px; color: var(--brand-color);">
                        <i class="bi bi-fuel-pump fs-1"></i>
                    </div>
                    <h4 class="fw-bold text-dark mb-2">Wet Stock</h4>
                    <p class="text-muted small">Monitor fuel storage tanks, log stock-in entries, and assign delivery stock in real-time.</p>
                </div>
                <a href="{{ route('wetstock.dashboard') }}" class="btn btn-primary-custom w-100 py-2 fs-6">
                    Enter Wet Stock <i class="bi bi-arrow-right ms-1"></i>
                </a>
            </div>
        </div>
    </div>
</div>
@endsection
