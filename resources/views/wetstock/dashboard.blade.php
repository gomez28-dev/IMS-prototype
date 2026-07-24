@extends('layouts.app')

@section('title', 'Wet Stock Dashboard')

@section('content')
<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <div>
        <h3 class="fw-bold text-dark mb-1">
            <i class="bi bi-fuel-pump text-primary me-2"></i>Wet Stock Dashboard
        </h3>
        <p class="text-muted small mb-0">Real-time fuel storage tracking & capacity management</p>
    </div>
    <div class="d-flex gap-2 flex-wrap">
        <a href="{{ route('wetstock.deliveries.unassigned') }}" class="btn btn-outline-warning btn-sm">
            <i class="bi bi-truck me-1"></i> Unassigned Deliveries
        </a>
        @if (Auth::user()->isAdmin() || Auth::user()->isEditor() || Auth::user()->isWarehouse())
            <a href="{{ route('wetstock.stock-in.create') }}" class="btn btn-primary-custom btn-sm">
                <i class="bi bi-plus-circle me-1"></i> Log Stock IN
            </a>
        @endif
    </div>
</div>

@if ($warehouses->isEmpty())
    <div class="card card-custom p-5 text-center">
        <p class="text-muted mb-0">No warehouses configured yet.</p>
    </div>
@else
    @foreach ($warehouses as $warehouse)
        <div class="card card-custom p-4 mb-5 border-0">
            <div class="d-flex justify-content-between align-items-center mb-3 pb-2 border-bottom">
                <div class="d-flex align-items-center">
                    <h4 class="fw-bold text-dark mb-0 me-3">
                        <i class="bi bi-building text-primary me-2"></i>{{ $warehouse->name }} Warehouse
                    </h4>
                    <span class="badge bg-light text-dark border rounded-pill px-3 py-1">
                        {{ $warehouse->activeTanks->count() }} Active Tanks
                    </span>
                </div>
                <div>
                    <a href="{{ route('wetstock.warehouses.show', $warehouse->id) }}" class="btn btn-sm btn-outline-dark rounded-pill px-3">
                        <i class="bi bi-gear me-1"></i> Manage Tanks
                    </a>
                </div>
            </div>

            <!-- Warehouse Level Summary -->
            <div class="row g-3 mb-4">
                <div class="col-md-3">
                    <div class="p-3 rounded-3" style="background-color: #f0fdf4; border: 1px solid #bbf7d0;">
                        <span class="text-uppercase text-muted fw-bold" style="font-size: 0.7rem; letter-spacing: 0.05em;">Total Stock Available</span>
                        <h4 class="fw-bold text-success mb-0 mt-1">{{ number_format($warehouse->total_stock_available) }} <small class="fs-6 text-muted">L</small></h4>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="p-3 rounded-3" style="background-color: #fefce8; border: 1px solid #fef08a;">
                        <span class="text-uppercase text-muted fw-bold" style="font-size: 0.7rem; letter-spacing: 0.05em;">Total Stock for Delivery</span>
                        <h4 class="fw-bold text-warning mb-0 mt-1" style="color: #a16207 !important;">{{ number_format($warehouse->total_stock_for_delivery) }} <small class="fs-6 text-muted">L</small></h4>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="p-3 rounded-3" style="background-color: #eff6ff; border: 1px solid #bfdbfe;">
                        <span class="text-uppercase text-muted fw-bold" style="font-size: 0.7rem; letter-spacing: 0.05em;">Total Out (Fulfilled)</span>
                        <h4 class="fw-bold text-primary mb-0 mt-1">{{ number_format($warehouse->total_out) }} <small class="fs-6 text-muted">L</small></h4>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="p-3 rounded-3" style="background-color: #f8fafc; border: 1px solid #e2e8f0;">
                        <span class="text-uppercase text-muted fw-bold" style="font-size: 0.7rem; letter-spacing: 0.05em;">Total Capacity</span>
                        <h4 class="fw-bold text-dark mb-0 mt-1">{{ number_format($warehouse->total_capacity) }} <small class="fs-6 text-muted">L</small></h4>
                    </div>
                </div>
            </div>

            <!-- Tanks Grid -->
            @if ($warehouse->activeTanks->isEmpty())
                <p class="text-muted text-center py-3">No active storage tanks in {{ $warehouse->name }}. <a href="{{ route('wetstock.tanks.create', $warehouse->id) }}">Add a tank</a> to start tracking fuel.</p>
            @else
                <div class="row g-3">
                    @foreach ($warehouse->activeTanks as $tank)
                        @php
                            $percentageUsed = $tank->max_capacity > 0 ? min(100, round(($tank->stock_available / $tank->max_capacity) * 100)) : 0;
                            $barColor = $percentageUsed > 85 ? 'bg-danger' : ($percentageUsed > 60 ? 'bg-warning' : 'bg-success');
                        @endphp
                        <div class="col-md-6 col-lg-4">
                            <div class="card h-100 border shadow-sm rounded-3">
                                <div class="card-header bg-white d-flex justify-content-between align-items-center py-3 border-bottom-0">
                                    <h6 class="fw-bold mb-0 text-dark">
                                        <i class="bi bi-box-seam me-1 text-primary"></i>{{ $tank->name }}
                                    </h6>
                                    <span class="badge bg-light text-muted border small">{{ number_format($tank->max_capacity) }}L max</span>
                                </div>
                                <div class="card-body pt-0">
                                    <!-- Capacity Bar -->
                                    <div class="mb-3">
                                        <div class="d-flex justify-content-between small text-muted mb-1">
                                            <span>Fill Level</span>
                                            <span class="fw-semibold">{{ $percentageUsed }}%</span>
                                        </div>
                                        <div class="progress" style="height: 8px;">
                                            <div class="progress-bar {{ $barColor }}" role="progressbar" style="width: {{ $percentageUsed }}%;" aria-valuenow="{{ $percentageUsed }}" aria-valuemin="0" aria-valuemax="100"></div>
                                        </div>
                                    </div>

                                    <div class="row g-2 text-center my-2">
                                        <div class="col-4">
                                            <div class="p-2 rounded bg-light border">
                                                <div class="text-muted" style="font-size: 0.65rem;">AVAILABLE</div>
                                                <div class="fw-bold text-success small">{{ number_format($tank->stock_available) }}</div>
                                            </div>
                                        </div>
                                        <div class="col-4">
                                            <div class="p-2 rounded bg-light border">
                                                <div class="text-muted" style="font-size: 0.65rem;">FOR DELIVERY</div>
                                                <div class="fw-bold text-warning small" style="color: #a16207 !important;">{{ number_format($tank->stock_for_delivery) }}</div>
                                            </div>
                                        </div>
                                        <div class="col-4">
                                            <div class="p-2 rounded bg-light border">
                                                <div class="text-muted" style="font-size: 0.65rem;">OUT</div>
                                                <div class="fw-bold text-primary small">{{ number_format($tank->stock_out) }}</div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div class="card-footer bg-light border-top-0 d-flex justify-content-between align-items-center py-2">
                                    <span class="text-muted" style="font-size: 0.75rem;">Rem: {{ number_format($tank->remaining_capacity) }}L</span>
                                    @if (Auth::user()->isAdmin() || Auth::user()->isEditor() || Auth::user()->isWarehouse())
                                        <a href="{{ route('wetstock.stock-in.create', ['tank_id' => $tank->id]) }}" class="btn btn-sm btn-outline-primary py-0 px-2" style="font-size: 0.75rem;">
                                            + Stock IN
                                        </a>
                                    @endif
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    @endforeach
@endif
@endsection
