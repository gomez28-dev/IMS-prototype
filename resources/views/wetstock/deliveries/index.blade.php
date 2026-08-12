@extends('layouts.app')

@section('title', 'Assign Deliveries')

@section('content')
<div class="row justify-content-center">
    <div class="col-12">
        <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
            <div>
                <h3 class="fw-bold text-dark mb-1">
                    <i class="bi bi-truck text-primary me-2"></i>Assign Deliveries
                </h3>
                <p class="text-muted small mb-0">Link sales deliveries to storage tanks, and review assignment history</p>
            </div>
            <div>
                <a href="{{ route('wetstock.dashboard') }}" class="btn btn-outline-secondary btn-sm rounded-pill px-3">
                    <i class="bi bi-arrow-left me-1"></i> Dashboard
                </a>
            </div>
        </div>

        <!-- Nav Tabs -->
        <ul class="nav nav-tabs border-bottom mb-4" id="assignTabs" role="tablist">
            <li class="nav-item" role="presentation">
                <a class="nav-link fw-semibold {{ $activeTab === 'unassigned' ? 'active' : '' }}" href="{{ route('wetstock.deliveries.index', ['tab' => 'unassigned']) }}">
                    <i class="bi bi-inbox me-1 text-warning"></i> Unassigned
                    @if ($unassignedCount > 0)
                        <span class="badge bg-warning text-dark rounded-pill ms-1">{{ $unassignedCount }}</span>
                    @endif
                </a>
            </li>
            <li class="nav-item" role="presentation">
                <a class="nav-link fw-semibold {{ $activeTab === 'history' ? 'active' : '' }}" href="{{ route('wetstock.deliveries.index', ['tab' => 'history']) }}">
                    <i class="bi bi-clock-history me-1 text-primary"></i> History
                </a>
            </li>
        </ul>

        @if ($activeTab === 'unassigned')
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
                                            <th>Allocate to Storage Tank</th>
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
                                            <td class="fw-bold text-dark">{{ number_format($delivery->qty_out) }} L
                                                @if ($delivery->allocations->isNotEmpty() && $delivery->remaining_to_allocate > 0)
                                                    <small class="text-muted d-block fw-normal">
                                                        {{ number_format($delivery->allocated_quantity) }} L allocated ·
                                                        <span class="text-warning fw-semibold">{{ number_format($delivery->remaining_to_allocate) }} L remaining</span>
                                                    </small>
                                                @endif
                                            </td>
                                            <td>
                                                @if ($delivery->status === 'FULFILLED' && $delivery->remaining_to_allocate <= 0)
                                                    <span class="badge bg-success-subtle text-success border border-success-subtle rounded-pill px-2 py-1">FULFILLED</span>
                                                @elseif ($delivery->status === 'CANCELLED')
                                                    <span class="badge bg-secondary-subtle text-secondary border border-secondary-subtle rounded-pill px-2 py-1">CANCELLED</span>
                                                @else
                                                    <span class="badge bg-warning-subtle text-warning border border-warning-subtle rounded-pill px-2 py-1" style="color: #a16207 !important;">PENDING</span>
                                                @endif
                                            </td>
                                            @if (Auth::user()->isAdmin() || Auth::user()->isEditor() || Auth::user()->isWarehouse())
                                                <td>
                                                    <form method="POST" action="{{ route('wetstock.deliveries.allocate', $delivery->id) }}" class="d-flex gap-2 align-items-center">
                                                        @csrf
                                                        <input type="number" name="quantity" id="allocate-qty-{{ $delivery->id }}" class="form-control form-control-sm allocate-qty-input" style="width: 110px;" min="1" max="{{ $delivery->remaining_to_allocate }}" value="{{ $delivery->remaining_to_allocate }}" title="Quantity to allocate from this DR" required>
                                                        <select name="storage_tank_id" id="allocate-tank-{{ $delivery->id }}" class="form-select form-select-sm allocate-tank-select" style="min-width: 200px;" required>
                                                            <option value="">-- Select Tank --</option>
                                                            @foreach ($warehouses as $wh)
                                                                <optgroup label="{{ $wh->name }}">
                                                                    @foreach ($wh->tanks as $t)
                                                                        <option value="{{ $t->id }}" data-available="{{ $t->effective_available }}">
                                                                            {{ $wh->name }} - {{ $t->name }} ({{ number_format($t->effective_available) }}L available)
                                                                        </option>
                                                                    @endforeach
                                                                </optgroup>
                                                            @endforeach
                                                        </select>
                                                        <button type="submit" class="btn btn-sm btn-primary-custom py-1 px-3" title="Allocate this quantity to the selected tank">Add</button>
                                                    </form>
                                                    <small class="text-muted d-block mt-1">
                                                        <i class="bi bi-info-circle me-1"></i>Add one tank at a time — the DR is marked FULFILLED once the full {{ number_format($delivery->qty_out) }}L is allocated.
                                                    </small>
                                                    <script>
                                                        (function () {
                                                            var qtyInput = document.getElementById('allocate-qty-{{ $delivery->id }}');
                                                            var tankSelect = document.getElementById('allocate-tank-{{ $delivery->id }}');
                                                            if (!qtyInput || !tankSelect) return;
                                                            function refreshTanks() {
                                                                var qty = parseInt(qtyInput.value, 10);
                                                                if (isNaN(qty) || qty < 1) qty = 0;
                                                                Array.prototype.forEach.call(tankSelect.options, function (opt) {
                                                                    if (!opt.value) return;
                                                                    var available = parseInt(opt.getAttribute('data-available'), 10) || 0;
                                                                    var insufficient = qty > available;
                                                                    opt.disabled = insufficient;
                                                                    opt.title = insufficient ? 'Insufficient stock (only ' + available.toLocaleString() + 'L available)' : '';
                                                                });
                                                                if (tankSelect.selectedOptions.length && tankSelect.selectedOptions[0].disabled) {
                                                                    tankSelect.value = '';
                                                                }
                                                            }
                                                            qtyInput.addEventListener('input', refreshTanks);
                                                            refreshTanks();
                                                        })();
                                                    </script>
                                                </td>
                                            @endif
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>

                        <div class="mt-3">
                            {{ $deliveries->appends(['tab' => 'unassigned'])->links() }}
                        </div>
                    @endif
                </div>
            </div>
        @else
            <div class="card card-custom p-4 border-0">
                <div class="card-body">
                    @if ($assignments->isEmpty())
                        <div class="text-center py-5">
                            <i class="bi bi-inbox display-4 text-muted mb-3 d-block"></i>
                            <h5 class="fw-bold text-dark">No Assignments Yet</h5>
                            <p class="text-muted">No deliveries have been allocated to storage tanks.</p>
                        </div>
                    @else
                        <!-- Desktop table -->
                        <div class="table-responsive d-none d-md-block">
                            <table class="table table-custom align-middle">
                                <thead>
                                    <tr>
                                        <th>DR Number</th>
                                        <th>Account / Client</th>
                                        <th>Tank Allocated</th>
                                        <th>Warehouse</th>
                                        <th class="text-center">Qty Allocated</th>
                                        <th class="text-center">DR Total</th>
                                        <th>Allocated By</th>
                                        <th>Date Allocated</th>
                                        <th class="text-center">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($assignments as $alloc)
                                    <tr>
                                        <td class="fw-bold text-dark">
                                            {{ $alloc->delivery->dr_number }}
                                            @if ($alloc->delivery->status === 'FULFILLED')
                                                <span class="badge bg-success-subtle text-success border border-success-subtle rounded-pill px-2 py-1 ms-1">FULFILLED</span>
                                            @elseif ($alloc->delivery->status === 'CANCELLED')
                                                <span class="badge bg-danger-subtle text-danger border border-danger-subtle rounded-pill px-2 py-1 ms-1">CANCELLED</span>
                                            @else
                                                <span class="badge bg-warning-subtle text-warning border border-warning-subtle rounded-pill px-2 py-1 ms-1" style="color: #a16207 !important;">PENDING</span>
                                            @endif
                                        </td>
                                        <td>{{ $alloc->delivery->order->account ?? '-' }}</td>
                                        <td>
                                            <span class="badge bg-light text-dark border">
                                                <i class="bi bi-box-seam me-1 text-primary"></i>{{ $alloc->tank->name ?? '—' }}
                                            </span>
                                        </td>
                                        <td>{{ $alloc->tank->warehouse->name ?? '—' }}</td>
                                        <td class="text-center fw-semibold">{{ number_format($alloc->quantity) }} L</td>
                                        <td class="text-center text-muted">{{ number_format($alloc->delivery->qty_out) }} L</td>
                                        <td>{{ $alloc->assignedBy->name ?? '—' }}</td>
                                        <td class="text-muted small">{{ $alloc->created_at ? $alloc->created_at->timezone('Asia/Manila')->format('M d, Y h:i A') : '—' }}</td>
                                        <td class="text-center">
                                            <div class="btn-group btn-group-sm">
                                                <form method="POST" action="{{ route('wetstock.deliveries.unassign', $alloc->id) }}" class="d-inline" onsubmit="return confirm('Remove this {{ number_format($alloc->quantity) }}L allocation of DR #{{ $alloc->delivery->dr_number }} from {{ $alloc->tank->name }}?');">
                                                    @csrf
                                                    <button type="submit" class="btn btn-outline-danger btn-sm" title="Remove allocation">
                                                        <i class="bi bi-arrow-return-left"></i>
                                                    </button>
                                                </form>
                                            </div>
                                        </td>
                                    </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>

                        <!-- Mobile cards -->
                        <div class="d-md-none">
                            @foreach ($assignments as $alloc)
                                <div class="card border-0 bg-light mb-3 rounded-4 shadow-sm">
                                    <div class="card-body p-4">
                                        <div class="d-flex justify-content-between align-items-start mb-2">
                                            <h5 class="fw-bold text-dark mb-0">
                                                {{ $alloc->delivery->dr_number }}
                                                @if ($alloc->delivery->status === 'FULFILLED')
                                                    <span class="badge bg-success-subtle text-success border border-success-subtle rounded-pill px-2 py-1">FULFILLED</span>
                                                @elseif ($alloc->delivery->status === 'CANCELLED')
                                                    <span class="badge bg-danger-subtle text-danger border border-danger-subtle rounded-pill px-2 py-1">CANCELLED</span>
                                                @else
                                                    <span class="badge bg-warning-subtle text-warning border border-warning-subtle rounded-pill px-2 py-1" style="color: #a16207 !important;">PENDING</span>
                                                @endif
                                            </h5>
                                            <span class="badge bg-light text-dark border fs-6">
                                                {{ number_format($alloc->quantity) }} L
                                            </span>
                                        </div>
                                        <p class="mb-1"><span class="fw-medium text-muted">Account:</span> {{ $alloc->delivery->order->account ?? '-' }}</p>
                                        <p class="mb-1">
                                            <span class="fw-medium text-muted">Tank:</span>
                                            <span class="badge bg-light text-dark border">
                                                <i class="bi bi-box-seam me-1 text-primary"></i>{{ $alloc->tank->name ?? '—' }}
                                            </span>
                                            ({{ $alloc->tank->warehouse->name ?? '—' }})
                                        </p>
                                        <p class="mb-1"><span class="fw-medium text-muted">DR Total:</span> {{ number_format($alloc->delivery->qty_out) }} L</p>
                                        <p class="mb-1"><span class="fw-medium text-muted">Allocated By:</span> {{ $alloc->assignedBy->name ?? '—' }}</p>
                                        <p class="mb-0 text-muted small"><span class="fw-medium">Date:</span> {{ $alloc->created_at ? $alloc->created_at->timezone('Asia/Manila')->format('M d, Y h:i A') : '—' }}</p>
                                        <div class="d-flex gap-1 mt-2">
                                            <form method="POST" action="{{ route('wetstock.deliveries.unassign', $alloc->id) }}" class="d-inline" onsubmit="return confirm('Remove this {{ number_format($alloc->quantity) }}L allocation of DR #{{ $alloc->delivery->dr_number }} from {{ $alloc->tank->name }}?');">
                                                @csrf
                                                <button type="submit" class="btn btn-sm btn-outline-danger">
                                                    <i class="bi bi-arrow-return-left me-1"></i> Remove allocation
                                                </button>
                                            </form>
                                        </div>
                                    </div>
                                </div>
                            @endforeach
                        </div>

                        <!-- Pagination -->
                        <div class="d-flex justify-content-center mt-4">
                            {{ $assignments->appends(['tab' => 'history'])->links() }}
                        </div>
                    @endif
                </div>
            </div>
        @endif
    </div>
</div>
@endsection
