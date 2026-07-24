@extends('layouts.app')

@section('title', 'Stock IN Log History')

@section('content')
<div class="row justify-content-center">
    <div class="col-12">
        <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
            <div>
                <h3 class="fw-bold text-dark mb-1">
                    <i class="bi bi-fuel-pump text-primary me-2"></i>Stock IN Log History
                </h3>
                <p class="text-muted small mb-0">Immutable audit log of all fuel added to storage tanks</p>
            </div>
            @if (Auth::user()->isAdmin() || Auth::user()->isEditor() || Auth::user()->isWarehouse())
                <div>
                    <a href="{{ route('wetstock.stock-in.create') }}" class="btn btn-primary-custom btn-sm">
                        <i class="bi bi-plus-circle me-1"></i> Log Stock IN
                    </a>
                </div>
            @endif
        </div>

        <div class="card card-custom p-4 border-0">
            <div class="card-body">
                @if ($stockIns->isEmpty())
                    <div class="text-center py-5">
                        <i class="bi bi-journal-x display-4 text-muted mb-3 d-block"></i>
                        <p class="text-muted mb-3">No Stock IN entries logged yet.</p>
                        @if (Auth::user()->isAdmin() || Auth::user()->isEditor() || Auth::user()->isWarehouse())
                            <a href="{{ route('wetstock.stock-in.create') }}" class="btn btn-primary-custom btn-sm">
                                <i class="bi bi-plus-circle me-1"></i> Log First Stock IN
                            </a>
                        @endif
                    </div>
                @else
                    <div class="table-responsive">
                        <table class="table table-custom align-middle">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Warehouse</th>
                                    <th>Storage Tank</th>
                                    <th>Quantity Added</th>
                                    <th>Logged By</th>
                                    <th>Logged Timestamp</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($stockIns as $stockIn)
                                    <tr>
                                        <td class="fw-medium text-dark">{{ $stockIn->date ? $stockIn->date->format('M d, Y') : '-' }}</td>
                                        <td>
                                            <span class="badge bg-light text-dark border">
                                                {{ $stockIn->tank->warehouse->name ?? '-' }}
                                            </span>
                                        </td>
                                        <td class="fw-bold text-dark">
                                            <i class="bi bi-box-seam me-1 text-secondary"></i>{{ $stockIn->tank->name ?? '-' }}
                                        </td>
                                        <td class="fw-bold text-success">
                                            +{{ number_format($stockIn->quantity) }} L
                                        </td>
                                        <td>
                                            <span class="text-muted small">
                                                <i class="bi bi-person me-1"></i>{{ $stockIn->admin->name ?? 'System' }}
                                            </span>
                                        </td>
                                        <td class="text-muted small">
                                            {{ $stockIn->created_at ? $stockIn->created_at->format('Y-m-d H:i') : '-' }}
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    <div class="mt-3">
                        {{ $stockIns->links() }}
                    </div>
                @endif
            </div>
        </div>
    </div>
</div>
@endsection
