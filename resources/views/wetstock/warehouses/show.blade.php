@extends('layouts.app')

@section('title', $warehouse->name . ' Tanks')

@section('content')
<div class="row justify-content-center">
    <div class="col-12">
        <div class="mb-3 d-flex justify-content-between align-items-center">
            <a href="{{ route('wetstock.dashboard') }}" class="text-decoration-none text-secondary small">
                <i class="bi bi-arrow-left me-1"></i> Back to Wet Stock Dashboard
            </a>
            @if (Auth::user()->isAdmin() || Auth::user()->isEditor() || Auth::user()->isWarehouse())
                <a href="{{ route('wetstock.tanks.create', $warehouse->id) }}" class="btn btn-primary-custom btn-sm">
                    <i class="bi bi-plus-circle me-1"></i> Add Storage Tank
                </a>
            @endif
        </div>

        <div class="card card-custom p-4 border-0">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <div>
                        <h4 class="fw-bold text-dark mb-1">
                            <i class="bi bi-building text-primary me-2"></i>{{ $warehouse->name }} Storage Tanks
                        </h4>
                        <p class="text-muted small mb-0">Manage capacity and status of storage tanks in {{ $warehouse->name }}</p>
                    </div>
                </div>

                @if ($tanks->isEmpty())
                    <div class="text-center py-5">
                        <i class="bi bi-box-seam display-4 text-muted mb-3 d-block"></i>
                        <p class="text-muted mb-3">No tanks added for {{ $warehouse->name }} yet.</p>
                        @if (Auth::user()->isAdmin() || Auth::user()->isEditor() || Auth::user()->isWarehouse())
                            <a href="{{ route('wetstock.tanks.create', $warehouse->id) }}" class="btn btn-primary-custom btn-sm">
                                <i class="bi bi-plus-circle me-1"></i> Add First Tank
                            </a>
                        @endif
                    </div>
                @else
                    <div class="table-responsive">
                        <table class="table table-custom align-middle">
                            <thead>
                                <tr>
                                    <th>Tank Name</th>
                                    <th>Max Capacity</th>
                                    <th>Stock Available</th>
                                    <th>Stock for Delivery</th>
                                    <th>Total Out</th>
                                    <th>Status</th>
                                    @if (Auth::user()->isAdmin() || Auth::user()->isEditor() || Auth::user()->isWarehouse())
                                        <th class="text-end">Actions</th>
                                    @endif
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($tanks as $tank)
                                    <tr>
                                        <td class="fw-bold text-dark">
                                            <i class="bi bi-box-seam me-2 text-secondary"></i>{{ $tank->name }}
                                        </td>
                                        <td>{{ number_format($tank->max_capacity) }} L</td>
                                        <td class="fw-semibold text-success">{{ number_format($tank->stock_available) }} L</td>
                                        <td class="fw-semibold text-warning" style="color: #a16207 !important;">{{ number_format($tank->stock_for_delivery) }} L</td>
                                        <td class="fw-semibold text-primary">{{ number_format($tank->stock_out) }} L</td>
                                        <td>
                                            @if ($tank->is_active)
                                                <span class="badge bg-success-subtle text-success border border-success-subtle rounded-pill px-2 py-1">Active</span>
                                            @else
                                                <span class="badge bg-secondary-subtle text-secondary border border-secondary-subtle rounded-pill px-2 py-1">Deactivated</span>
                                            @endif
                                        </td>
                                        @if (Auth::user()->isAdmin() || Auth::user()->isEditor() || Auth::user()->isWarehouse())
                                            <td class="text-end">
                                                <div class="d-flex justify-content-end gap-1">
                                                    <a href="{{ route('wetstock.tanks.edit', $tank->id) }}" class="btn btn-sm btn-outline-secondary">
                                                        <i class="bi bi-pencil"></i> Edit
                                                    </a>
                                                    <form method="POST" action="{{ route('wetstock.tanks.toggle-active', $tank->id) }}" class="d-inline">
                                                        @csrf
                                                        <button type="submit" class="btn btn-sm {{ $tank->is_active ? 'btn-outline-danger' : 'btn-outline-success' }}" onclick="return confirm('Are you sure you want to {{ $tank->is_active ? 'deactivate' : 'reactivate' }} this tank?')">
                                                            <i class="bi {{ $tank->is_active ? 'bi-power' : 'bi-check-circle' }}"></i> {{ $tank->is_active ? 'Deactivate' : 'Reactivate' }}
                                                        </button>
                                                    </form>
                                                </div>
                                            </td>
                                        @endif
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>
        </div>
    </div>
</div>
@endsection
