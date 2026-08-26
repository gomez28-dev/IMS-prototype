@extends('layouts.app')

@section('title', $warehouse->name . ' Tanks & Tankers')

@section('content')
<div class="row justify-content-center">
    <div class="col-12">
        <div class="mb-3 d-flex justify-content-between align-items-center">
            <a href="{{ route('wetstock.dashboard') }}" class="text-decoration-none text-secondary small">
                <i class="bi bi-arrow-left me-1"></i> Back to Wet Stock Dashboard
            </a>
            @if (Auth::user()->canEditModule2())
                <a href="{{ route('wetstock.tanks.create', $warehouse->id) }}" class="btn btn-primary-custom btn-sm">
                    <i class="bi bi-plus-circle me-1"></i> Add Tank / Tanker
                </a>
            @endif
        </div>

        <div class="card card-custom p-4 border-0 shadow-sm">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <div>
                        <h4 class="fw-bold text-dark mb-1">
                            <i class="bi bi-building text-primary me-2"></i>{{ $warehouse->name }} Storage Tanks & Tankers
                        </h4>
                        <p class="text-muted small mb-0">Manage capacity, category, and contamination status of storage tanks in {{ $warehouse->name }}</p>
                    </div>
                </div>

                @if ($tanks->isEmpty())
                    <div class="text-center py-5">
                        <i class="bi bi-box-seam display-4 text-muted mb-3 d-block"></i>
                        <p class="text-muted mb-3">No tanks added for {{ $warehouse->name }} yet.</p>
                        @if (Auth::user()->canEditModule2())
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
                                    <th>Type</th>
                                    <th>Name / Identifier</th>
                                    <th>Max Capacity</th>
                                    <th>Stock Available</th>
                                    <th>Sellable Available</th>
                                    <th>Status</th>
                                    @if (Auth::user()->canEditModule2())
                                        <th class="text-end">Actions</th>
                                    @endif
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($tanks as $tank)
                                    <tr class="{{ $tank->hasContamination() ? ($tank->isFullyContaminated() ? 'table-danger' : 'table-warning-subtle') : '' }}">
                                        <td>
                                            @if ($tank->isTanker())
                                                <span class="badge bg-info-subtle text-info-emphasis border border-info-subtle rounded-pill px-2 py-1"><i class="bi bi-truck me-1"></i>Tanker Truck</span>
                                            @else
                                                <span class="badge bg-secondary-subtle text-secondary border border-secondary-subtle rounded-pill px-2 py-1"><i class="bi bi-box-seam me-1"></i>Depot Tank</span>
                                            @endif
                                        </td>
                                        <td class="fw-bold text-dark">
                                            {{ $tank->name }}
                                            @if ($tank->remarks)
                                                <div class="extra-small text-muted fw-normal">{{ $tank->remarks }}</div>
                                            @endif
                                        </td>
                                        <td>{{ number_format($tank->max_capacity) }} L</td>
                                        <td class="fw-semibold text-dark">{{ number_format($tank->stock_available) }} L</td>
                                        <td class="fw-semibold {{ $tank->hasContamination() ? 'text-danger' : 'text-success' }}">
                                            {{ number_format($tank->sellable_available) }} L
                                            @if ($tank->hasContamination())
                                                <div class="badge {{ $tank->isFullyContaminated() ? 'bg-danger' : 'bg-warning text-dark border' }} mt-1 d-block" style="font-size: 0.65rem;">
                                                    <i class="bi bi-exclamation-triangle-fill me-1"></i>{{ $tank->isFullyContaminated() ? 'FULLY ' : '' }}CONTAMINATED ({{ number_format($tank->contaminated_liters) }}L{{ $tank->hasContamination() && !$tank->isFullyContaminated() ? ' — ' . number_format($tank->sellable_available) . 'L still sellable' : '' }})
                                                </div>
                                            @endif
                                        </td>
                                        <td>
                                            @if ($tank->is_active)
                                                <span class="badge bg-success-subtle text-success border border-success-subtle rounded-pill px-2 py-1">Active</span>
                                            @else
                                                <span class="badge bg-secondary-subtle text-secondary border border-secondary-subtle rounded-pill px-2 py-1">Deactivated</span>
                                            @endif
                                        </td>
                                        @if (Auth::user()->canEditModule2())
                                            <td class="text-end">
                                                <div class="d-flex justify-content-end gap-1">
                                                    <!-- Contamination Controls (set/adjust quantity, or clear) -->
                                                    @if ($tank->hasContamination())
                                                        <span class="badge bg-danger text-white align-self-center me-1" title="Contaminated volume excluded from sellable stock">
                                                            {{ number_format($tank->contaminated_liters) }}L Contaminated{{ $tank->isFullyContaminated() ? ' (FULL)' : '' }}
                                                        </span>
                                                        <form method="POST" action="{{ route('wetstock.tanks.toggle-contamination', $tank->id) }}" class="d-inline">
                                                            @csrf
                                                            <button type="submit" class="btn btn-sm btn-success" onclick="return confirm('Clear contamination flag on {{ $tank->name }}?')">
                                                                <i class="bi bi-shield-check me-1"></i> Clear
                                                            </button>
                                                        </form>
                                                    @endif
                                                    <button type="button" class="btn btn-sm {{ $tank->hasContamination() ? 'btn-outline-warning' : 'btn-outline-danger' }}" data-bs-toggle="modal" data-bs-target="#contamModal-{{ $tank->id }}">
                                                        <i class="bi bi-exclamation-triangle me-1"></i> {{ $tank->hasContamination() ? 'Adjust' : 'Flag Contaminated' }}
                                                    </button>

                                                    <a href="{{ route('wetstock.tanks.edit', $tank->id) }}" class="btn btn-sm btn-outline-secondary">
                                                        <i class="bi bi-pencil"></i> Edit
                                                    </a>
                                                    <form method="POST" action="{{ route('wetstock.tanks.toggle-active', $tank->id) }}" class="d-inline">
                                                        @csrf
                                                        <button type="submit" class="btn btn-sm {{ $tank->is_active ? 'btn-outline-dark' : 'btn-outline-success' }}" onclick="return confirm('Are you sure you want to {{ $tank->is_active ? 'deactivate' : 'reactivate' }} this tank?')">
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

{{-- Contamination Flag/Adjust Modals (always available; prefilled with current contaminated liters) --}}
@foreach ($tanks as $tank)
        <div class="modal fade text-start" id="contamModal-{{ $tank->id }}" tabindex="-1" aria-labelledby="contamLabel-{{ $tank->id }}" aria-hidden="true">
            <div class="modal-dialog">
                <div class="modal-content border-0 shadow">
                    <form method="POST" action="{{ route('wetstock.tanks.toggle-contamination', $tank->id) }}">
                        @csrf
                        <div class="modal-header {{ $tank->hasContamination() ? 'bg-warning text-dark' : 'bg-danger text-white' }}">
                            <h5 class="modal-title fw-bold" id="contamLabel-{{ $tank->id }}"><i class="bi bi-exclamation-triangle-fill me-2"></i>{{ $tank->hasContamination() ? 'Adjust Contaminated Volume' : 'Flag Contaminated' }}: {{ $tank->name }}</h5>
                            <button type="button" class="btn-close {{ $tank->hasContamination() ? '' : 'btn-close-white' }}" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body p-4">
                            @if ($tank->hasContamination())
                                <div class="alert alert-warning small py-2">Currently {{ number_format($tank->contaminated_liters) }}L contaminated — {{ number_format($tank->sellable_available) }}L still sellable. Adjust below or use <em>Clear</em> to remove.</div>
                            @endif
                            <div class="mb-3">
                                <label for="liters-{{ $tank->id }}" class="form-label fw-semibold">Contaminated Fuel Volume (Liters)</label>
                                <input type="number" name="contaminated_liters" id="liters-{{ $tank->id }}" class="form-control" value="{{ old('contaminated_liters', $tank->hasContamination() ? $tank->contaminated_liters : $tank->stock_available) }}" min="1" max="{{ max(1, $tank->stock_available) }}" required>
                                <div class="form-text">
                                    @if ($tank->hasContamination())
                                        Adjust the affected volume (max {{ number_format($tank->stock_available) }}L available). Set via this form; clear entirely with the <em>Clear</em> button.
                                    @else
                                        Defaults to current available volume ({{ number_format($tank->stock_available) }}L). Adjust if only part is contaminated.
                                    @endif
                                </div>
                            </div>
                            <div class="mb-3">
                                <label for="rem-{{ $tank->id }}" class="form-label fw-semibold">Reason / Remarks</label>
                                <textarea name="remarks" id="rem-{{ $tank->id }}" rows="2" class="form-control" placeholder="e.g. Water contamination detected during quality check">{{ old('remarks', $tank->remarks) }}</textarea>
                            </div>
                        </div>
                        <div class="modal-footer bg-light">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" class="btn {{ $tank->hasContamination() ? 'btn-warning' : 'btn-danger' }} fw-semibold"><i class="bi bi-exclamation-triangle-fill me-1"></i>{{ $tank->hasContamination() ? 'Update Contamination' : 'Flag as Contaminated' }}</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
@endforeach
@endsection
