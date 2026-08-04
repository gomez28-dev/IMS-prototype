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
                                        <th class="text-center">Actions</th>
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
                                        <td class="text-center">
                                            <div class="btn-group btn-group-sm">
                                                <button type="button" class="btn btn-outline-primary btn-sm" onclick="openReassignModal({{ $a->id }}, 'DR #{{ $a->dr_number }}', '{{ number_format($a->qty_out) }} L', {{ $a->storage_tank_id ?? 'null' }})" title="Edit assignment">
                                                    <i class="bi bi-pencil"></i>
                                                </button>
                                                <form method="POST" action="{{ route('wetstock.deliveries.unassign', $a->id) }}" class="d-inline" onsubmit="return confirm('Unassign DR #{{ $a->dr_number }} from this tank?');">
                                                    @csrf
                                                    <button type="submit" class="btn btn-outline-danger btn-sm" title="Unassign">
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
                                        <div class="d-flex gap-1 mt-2">
                                            <button type="button" class="btn btn-sm btn-outline-primary" onclick="openReassignModal({{ $a->id }}, 'DR #{{ $a->dr_number }}', '{{ number_format($a->qty_out) }} L', {{ $a->storage_tank_id ?? 'null' }})">
                                                <i class="bi bi-pencil me-1"></i> Edit
                                            </button>
                                            <form method="POST" action="{{ route('wetstock.deliveries.unassign', $a->id) }}" class="d-inline" onsubmit="return confirm('Unassign DR #{{ $a->dr_number }} from this tank?');">
                                                @csrf
                                                <button type="submit" class="btn btn-sm btn-outline-danger">
                                                    <i class="bi bi-arrow-return-left me-1"></i> Unassign
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

<!-- Reassign Delivery Modal -->
<div class="modal fade" id="reassignModal" tabindex="-1" aria-labelledby="reassignModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title fw-bold" id="reassignModalLabel">Reassign delivery</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="d-flex gap-2 mb-3">
                    <span class="badge bg-dark" id="modal-dr-badge">DR #0000</span>
                    <span class="badge bg-dark" id="modal-qty-badge">0 L</span>
                </div>
                <label for="modal-tank-select" class="form-label fw-medium">Storage tank</label>
                <select class="form-select" id="modal-tank-select">
                    <option value="">-- Select Tank --</option>
                    @foreach ($warehouses as $wh)
                        <optgroup label="{{ $wh->name }}">
                            @foreach ($wh->tanks as $t)
                                <option value="{{ $t->id }}">
                                    {{ $wh->name }} — {{ $t->name }} ({{ number_format($t->effective_available) }}L available)
                                </option>
                            @endforeach
                        </optgroup>
                    @endforeach
                </select>
                <small class="text-muted"><i class="bi bi-info-circle me-1"></i>Changing the tank will reassign this delivery.</small>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary-custom" id="modal-save-btn">Save</button>
            </div>
        </div>
    </div>
</div>

<script>
function openReassignModal(id, drNumber, qty, currentTankId) {
    document.getElementById('modal-dr-badge').textContent = drNumber;
    document.getElementById('modal-qty-badge').textContent = qty;
    var select = document.getElementById('modal-tank-select');
    select.value = currentTankId || '';
    document.getElementById('modal-save-btn').setAttribute('data-delivery-id', id);
    var modal = bootstrap.Modal.getOrCreateInstance(document.getElementById('reassignModal'));
    modal.show();
}
document.getElementById('modal-save-btn').addEventListener('click', function() {
    var id = this.getAttribute('data-delivery-id');
    var select = document.getElementById('modal-tank-select');
    var tankId = select.value;
    if (!tankId) { alert('Please select a tank.'); return; }
    var form = document.createElement('form');
    form.method = 'POST';
    form.action = '{{ route('wetstock.deliveries.assign', '__DELIVERY_ID__') }}'.replace('__DELIVERY_ID__', id);
    var csrf = document.createElement('input');
    csrf.type = 'hidden';
    csrf.name = '_token';
    csrf.value = '{{ csrf_token() }}';
    var tankInput = document.createElement('input');
    tankInput.type = 'hidden';
    tankInput.name = 'storage_tank_id';
    tankInput.value = tankId;
    form.appendChild(csrf);
    form.appendChild(tankInput);
    document.body.appendChild(form);
    form.submit();
});
</script>
@endsection
