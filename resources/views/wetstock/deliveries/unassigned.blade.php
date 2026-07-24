@extends('layouts.app')

@section('title', 'Assign Storage Tanks to Deliveries')

@section('content')
<div class="row justify-content-center">
    <div class="col-12">
        <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
            <div>
                <h3 class="fw-bold text-dark mb-1">
                    <i class="bi bi-truck text-primary me-2"></i>Unassigned Deliveries
                </h3>
                <p class="text-muted small mb-0">Sales deliveries that need to be linked to a specific storage tank</p>
            </div>
            <div>
                <a href="{{ route('wetstock.dashboard') }}" class="btn btn-outline-secondary btn-sm rounded-pill px-3">
                    <i class="bi bi-arrow-left me-1"></i> Dashboard
                </a>
            </div>
        </div>

        <div class="card card-custom p-4 border-0">
            <div class="card-body">
                @if ($deliveries->isEmpty())
                    <div class="text-center py-5">
                        <i class="bi bi-check-circle display-4 text-success mb-3 d-block"></i>
                        <h5 class="fw-bold text-dark">All Deliveries Assigned!</h5>
                        <p class="text-muted">There are currently no pending or fulfilled deliveries awaiting tank assignment.</p>
                    </div>
                @else
                    <div class="table-responsive">
                        <table class="table table-custom align-middle">
                            <thead>
                                <tr>
                                    <th>DR Number</th>
                                    <th>Account / Client</th>
                                    <th>SO Number</th>
                                    <th>Delivery Date</th>
                                    <th>Qty Out</th>
                                    <th>Status</th>
                                    @if (Auth::user()->isAdmin() || Auth::user()->isEditor() || Auth::user()->isWarehouse())
                                        <th>Assign Storage Tank</th>
                                    @endif
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($deliveries as $delivery)
                                    <tr>
                                        <td class="fw-bold text-dark">{{ $delivery->dr_number }}</td>
                                        <td>{{ $delivery->order->account ?? '-' }}</td>
                                        <td>
                                            <span class="badge bg-light text-dark border">
                                                SO# {{ $delivery->order->so_number ?? '-' }}
                                            </span>
                                        </td>
                                        <td class="text-muted small">
                                            {{ $delivery->delivery_date ? $delivery->delivery_date->format('M d, Y') : '-' }}
                                        </td>
                                        <td class="fw-bold text-dark">{{ number_format($delivery->qty_out) }} L</td>
                                        <td>
                                            @if ($delivery->status === 'FULFILLED')
                                                <span class="badge bg-success-subtle text-success border border-success-subtle rounded-pill px-2 py-1">FULFILLED</span>
                                            @elseif ($delivery->status === 'PENDING')
                                                <span class="badge bg-warning-subtle text-warning border border-warning-subtle rounded-pill px-2 py-1" style="color: #a16207 !important;">PENDING</span>
                                            @else
                                                <span class="badge bg-secondary-subtle text-secondary border border-secondary-subtle rounded-pill px-2 py-1">CANCELLED</span>
                                            @endif
                                        </td>
                                        @if (Auth::user()->isAdmin() || Auth::user()->isEditor() || Auth::user()->isWarehouse())
                                            <td>
                                                <form method="POST" action="{{ route('wetstock.deliveries.assign', $delivery->id) }}" class="d-flex gap-2">
                                                    @csrf
                                                    <select name="storage_tank_id" class="form-select form-select-sm" style="min-width: 200px;" required>
                                                        <option value="">-- Select Tank --</option>
                                                        @foreach ($warehouses as $wh)
                                                            <optgroup label="{{ $wh->name }}">
                                                                @foreach ($wh->tanks as $t)
                                                                    <option value="{{ $t->id }}" {{ $delivery->qty_out > $t->effective_available ? 'disabled title="Insufficient stock"' : '' }}>
                                                                        {{ $wh->name }} - {{ $t->name }} ({{ number_format($t->effective_available) }}L available)
                                                                    </option>
                                                                @endforeach
                                                            </optgroup>
                                                        @endforeach
                                                    </select>
                                                    <button type="submit" class="btn btn-sm btn-primary-custom py-1 px-3">Assign</button>
                                                </form>
                                            </td>
                                        @endif
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    <div class="mt-3">
                        {{ $deliveries->links() }}
                    </div>
                @endif
            </div>
        </div>
    </div>
</div>
@endsection
