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
                                    <th class="text-center">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($assignments as $a)
                                <tr>
                                    <td class="fw-bold text-dark">{{ $a->dr_number }}</td>
                                    <td>{{ $a->order->account ?? '-' }}</td>
                                    <td id="tank-cell-{{ $a->id }}">
                                        <span class="badge bg-light text-dark border">
                                            <i class="bi bi-box-seam me-1 text-primary"></i>{{ $a->storageTank->name ?? '—' }}
                                        </span>
                                        <span id="edit-form-{{ $a->id }}" style="display:none;">
                                            <select name="storage_tank_id" id="tank-select-{{ $a->id }}" class="form-select form-select-sm" style="min-width: 200px;">
                                                <option value="">-- Select Tank --</option>
                                                @foreach ($warehouses as $wh)
                                                    <optgroup label="{{ $wh->name }}">
                                                        @foreach ($wh->tanks as $t)
                                                            <option value="{{ $t->id }}" {{ $a->storage_tank_id == $t->id ? 'selected' : '' }}>
                                                                {{ $wh->name }} - {{ $t->name }} ({{ number_format($t->effective_available) }}L available)
                                                            </option>
                                                        @endforeach
                                                    </optgroup>
                                                @endforeach
                                            </select>
                                        </span>
                                    </td>
                                    <td id="wh-cell-{{ $a->id }}">{{ $a->storageTank->warehouse->name ?? '—' }}</td>
                                    <td class="text-center fw-semibold">{{ number_format($a->qty_out) }} L</td>
                                    <td>{{ $a->assignedBy->name ?? '—' }}</td>
                                    <td class="text-muted small">{{ $a->updated_at ? $a->updated_at->timezone('Asia/Manila')->format('M d, Y h:i A') : '—' }}</td>
                                    <td class="text-center">
                                        <div class="btn-group btn-group-sm">
                                            <button type="button" class="btn btn-outline-primary btn-sm" onclick="toggleEdit({{ $a->id }})" title="Edit assignment">
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
                                <tr id="save-row-{{ $a->id }}" style="display:none;">
                                    <td colspan="8" class="bg-light p-2">
                                        <div class="d-flex gap-2 align-items-center">
                                            <button type="button" class="btn btn-sm btn-primary-custom" onclick="saveReassign({{ $a->id }})">Save</button>
                                            <button type="button" class="btn btn-sm btn-outline-secondary" onclick="cancelEdit({{ $a->id }})">Cancel</button>
                                            <span class="text-muted small">Changing the tank will reassign this delivery.</span>
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
                                    <p class="mb-1" id="tank-cell-mobile-{{ $a->id }}">
                                        <span class="fw-medium text-muted">Tank:</span>
                                        <span class="badge bg-light text-dark border">
                                            <i class="bi bi-box-seam me-1 text-primary"></i>{{ $a->storageTank->name ?? '—' }}
                                        </span>
                                        ({{ $a->storageTank->warehouse->name ?? '—' }})
                                    </p>
                                    <p class="mb-1"><span class="fw-medium text-muted">Assigned By:</span> {{ $a->assignedBy->name ?? '—' }}</p>
                                    <p class="mb-0 text-muted small"><span class="fw-medium">Date:</span> {{ $a->updated_at ? $a->updated_at->timezone('Asia/Manila')->format('M d, Y h:i A') : '—' }}</p>
                                    <div class="d-flex gap-1 mt-2">
                                        <button type="button" class="btn btn-sm btn-outline-primary" onclick="toggleEditMobile({{ $a->id }})">
                                            <i class="bi bi-pencil me-1"></i> Edit
                                        </button>
                                        <form method="POST" action="{{ route('wetstock.deliveries.unassign', $a->id) }}" class="d-inline" onsubmit="return confirm('Unassign DR #{{ $a->dr_number }} from this tank?');">
                                            @csrf
                                            <button type="submit" class="btn btn-sm btn-outline-danger">
                                                <i class="bi bi-arrow-return-left me-1"></i> Unassign
                                            </button>
                                        </form>
                                    </div>
                                    <div id="edit-form-mobile-{{ $a->id }}" style="display:none;" class="mt-2">
                                        <select name="storage_tank_id" id="tank-select-mobile-{{ $a->id }}" class="form-select form-select-sm mb-1">
                                            <option value="">-- Select Tank --</option>
                                            @foreach ($warehouses as $wh)
                                                <optgroup label="{{ $wh->name }}">
                                                    @foreach ($wh->tanks as $t)
                                                        <option value="{{ $t->id }}" {{ $a->storage_tank_id == $t->id ? 'selected' : '' }}>
                                                            {{ $wh->name }} - {{ $t->name }} ({{ number_format($t->effective_available) }}L)
                                                        </option>
                                                    @endforeach
                                                </optgroup>
                                            @endforeach
                                        </select>
                                        <div class="d-flex gap-1">
                                            <button type="button" class="btn btn-sm btn-primary-custom" onclick="saveReassignMobile({{ $a->id }})">Save</button>
                                            <button type="button" class="btn btn-sm btn-outline-secondary" onclick="cancelEditMobile({{ $a->id }})">Cancel</button>
                                        </div>
                                    </div>
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

<script>
function toggleEdit(id) {
    var editForm = document.getElementById('edit-form-' + id);
    var saveRow = document.getElementById('save-row-' + id);
    if (editForm.style.display === 'none') {
        editForm.style.display = 'inline';
        saveRow.style.display = 'table-row';
    }
}
function cancelEdit(id) {
    document.getElementById('edit-form-' + id).style.display = 'none';
    document.getElementById('save-row-' + id).style.display = 'none';
}
function saveReassign(id) {
    var select = document.getElementById('tank-select-' + id);
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
}
function toggleEditMobile(id) {
    var el = document.getElementById('edit-form-mobile-' + id);
    el.style.display = el.style.display === 'none' ? 'block' : 'none';
}
function cancelEditMobile(id) {
    document.getElementById('edit-form-mobile-' + id).style.display = 'none';
}
function saveReassignMobile(id) {
    var select = document.getElementById('tank-select-mobile-' + id);
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
}
</script>
@endsection