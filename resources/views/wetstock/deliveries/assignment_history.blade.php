@extends('layouts.app')

@section('title', 'Delivery Assignment History')

@section('content')
<div class="row justify-content-center">
    <div class="col-12">
        <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
            <div>
                <h3 class="fw-bold text-dark mb-1">
                    <i class="bi bi-clock-history text-primary me-2"></i>Assignment History
                </h3>
                <p class="text-muted small mb-0">Record of delivery-to-storage-tank assignments</p>
            </div>
            <div>
                <a href="{{ route('wetstock.dashboard') }}" class="btn btn-outline-secondary btn-sm rounded-pill px-3">
                    <i class="bi bi-arrow-left me-1"></i> Dashboard
                </a>
            </div>
        </div>

        <div class="card card-custom p-4 border-0">
            <div class="card-body">
                @if ($assignments->isEmpty())
                    <div class="text-center py-5">
                        <i class="bi bi-inbox display-4 text-muted mb-3 d-block"></i>
                        <h5 class="fw-bold text-dark">No Assignments Yet</h5>
                        <p class="text-muted">No deliveries have been assigned to storage tanks.</p>
                    </div>
                @else
                    <!-- Desktop table -->
                    <div class="table-responsive d-none d-md-block">
                        <table class="table table-custom align-middle">
                            <thead>
                                <tr>
                                    <th>DR Number</th>
                                    <th>Account / Client</th>
                                    <th>Tank Assigned</th>
                                    <th>Warehouse</th>
                                    <th class="text-center">Qty Out</th>
                                    <th>Assigned By</th>
                                    <th>Date Assigned</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($assignments as $a)
                                    <tr>
                                        <td class="fw-bold text-dark">{{ $a->dr_number }}</td>
                                        <td>{{ $a->order->account ?? '-' }}</td>
                                        <td>
                                            <span class="badge bg-light text-dark border">
                                                <i class="bi bi-box-seam me-1 text-primary"></i>{{ $a->storageTank->name ?? '—' }}
                                            </span>
                                        </td>
                                        <td>{{ $a->storageTank->warehouse->name ?? '—' }}</td>
                                        <td class="text-center fw-semibold">{{ number_format($a->qty_out) }} L</td>
                                        <td>{{ $a->assignedBy->name ?? '—' }}</td>
                                        <td class="text-muted small">{{ $a->updated_at ? $a->updated_at->timezone('Asia/Manila')->format('M d, Y h:i A') : '—' }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    <!-- Mobile cards -->
                    <div class="d-md-none">
                        @foreach ($assignments as $a)
                            <div class="card border-0 bg-light mb-3 rounded-4 shadow-sm">
                                <div class="card-body p-4">
                                    <div class="d-flex justify-content-between align-items-start mb-2">
                                        <h5 class="fw-bold text-dark mb-0">{{ $a->dr_number }}</h5>
                                        <span class="badge bg-light text-dark border fs-6">
                                            {{ number_format($a->qty_out) }} L
                                        </span>
                                    </div>
                                    <p class="mb-1"><span class="fw-medium text-muted">Account:</span> {{ $a->order->account ?? '-' }}</p>
                                    <p class="mb-1">
                                        <span class="fw-medium text-muted">Tank:</span>
                                        <span class="badge bg-light text-dark border">
                                            <i class="bi bi-box-seam me-1 text-primary"></i>{{ $a->storageTank->name ?? '—' }}
                                        </span>
                                        ({{ $a->storageTank->warehouse->name ?? '—' }})
                                    </p>
                                    <p class="mb-1"><span class="fw-medium text-muted">Assigned By:</span> {{ $a->assignedBy->name ?? '—' }}</p>
                                    <p class="mb-0 text-muted small"><span class="fw-medium">Date:</span> {{ $a->updated_at ? $a->updated_at->timezone('Asia/Manila')->format('M d, Y h:i A') : '—' }}</p>
                                </div>
                            </div>
                        @endforeach
                    </div>

                    <!-- Pagination -->
                    <div class="d-flex justify-content-center mt-4">
                        {{ $assignments->links() }}
                    </div>
                @endif
            </div>
        </div>
    </div>
</div>
@endsection