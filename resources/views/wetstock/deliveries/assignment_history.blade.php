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
                        {{ $assignments->links() }}
                    </div>
                @endif
            </div>
        </div>
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
