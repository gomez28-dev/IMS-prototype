@extends('layouts.app')

@section('title', $title)

@section('content')
<div class="row justify-content-center">
    <div class="col-lg-8">
        <div class="mb-3">
            <a href="{{ route('wetstock.transfers.index') }}" class="text-decoration-none text-secondary small">
                <i class="bi bi-arrow-left me-1"></i> Back to Transfers Log
            </a>
        </div>

        <div class="card card-custom border-0 shadow-sm">
            <div class="card-body p-4">
                <div class="d-flex align-items-center justify-content-between mb-4 border-bottom pb-3">
                    <div>
                        <h4 class="fw-bold text-dark mb-1">
                            <i class="bi bi-arrow-left-right text-primary me-2"></i>{{ $title }}
                        </h4>
                        <p class="text-muted small mb-0">Select source tank and destination tank to transfer fuel volume.</p>
                    </div>
                    <div class="btn-group btn-group-sm" role="group">
                        <a href="{{ route('wetstock.transfers.create', ['mode' => 'depot_to_tanker']) }}" class="btn btn-outline-primary {{ $mode === 'depot_to_tanker' ? 'active' : '' }}">
                            Depot → Tanker
                        </a>
                        <a href="{{ route('wetstock.transfers.create', ['mode' => 'tanker_to_depot']) }}" class="btn btn-outline-primary {{ $mode === 'tanker_to_depot' ? 'active' : '' }}">
                            Tanker → Depot
                        </a>
                        <a href="{{ route('wetstock.transfers.create', ['mode' => 'general']) }}" class="btn btn-outline-secondary {{ $mode === 'general' ? 'active' : '' }}">
                            General
                        </a>
                    </div>
                </div>

                <form method="POST" action="{{ route('wetstock.transfers.store') }}" id="transferForm">
                    @csrf

                    <div class="row g-3 mb-4">
                        <!-- Source Tank -->
                        <div class="col-md-6">
                            <label for="source_tank_id" class="form-label fw-bold text-dark small">
                                <i class="bi bi-box-arrow-up-right text-danger me-1"></i>Source Tank / Depot (From)
                            </label>
                            <select name="source_tank_id" id="source_tank_id" class="form-select @error('source_tank_id') is-invalid @enderror" required>
                                <option value="">Select Source Tank...</option>
                                @foreach ($warehouses as $wh)
                                    <optgroup label="{{ $wh->name }}">
                                        @foreach ($wh->activeTanks as $tank)
                                            @php
                                                $matchMode = match ($mode) {
                                                    'depot_to_tanker' => $tank->isDepot(),
                                                    'tanker_to_depot' => $tank->isTanker(),
                                                    default => true,
                                                };
                                            @endphp
                                            <option value="{{ $tank->id }}"
                                                data-warehouse="{{ $wh->name }}"
                                                data-category="{{ $tank->category }}"
                                                data-available="{{ $tank->effective_available }}"
                                                data-contaminated="{{ $tank->is_contaminated ? 1 : 0 }}"
                                                {{ (old('source_tank_id', $sourceTankId) == $tank->id) ? 'selected' : '' }}
                                                {{ (!$matchMode && $mode !== 'general') ? 'class=text-muted' : '' }}
                                                {{ $tank->is_contaminated ? 'disabled' : '' }}>
                                                {{ $tank->name }} ({{ ucfirst($tank->category) }}) — {{ number_format($tank->effective_available) }}L Avail
                                                {{ $tank->is_contaminated ? ' [CONTAMINATED - BLOCKED]' : '' }}
                                            </option>
                                        @endforeach
                                    </optgroup>
                                @endforeach
                            </select>
                            @error('source_tank_id')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                            <div id="source_tank_info" class="mt-2 small text-muted"></div>
                        </div>

                        <!-- Destination Tank -->
                        <div class="col-md-6">
                            <label for="destination_tank_id" class="form-label fw-bold text-dark small">
                                <i class="bi bi-box-arrow-in-down-left text-success me-1"></i>Destination Tank / Tanker (To)
                            </label>
                            <select name="destination_tank_id" id="destination_tank_id" class="form-select @error('destination_tank_id') is-invalid @enderror" required>
                                <option value="">Select Destination Tank...</option>
                                @foreach ($warehouses as $wh)
                                    <optgroup label="{{ $wh->name }}">
                                        @foreach ($wh->activeTanks as $tank)
                                            @php
                                                $matchMode = match ($mode) {
                                                    'depot_to_tanker' => $tank->isTanker(),
                                                    'tanker_to_depot' => $tank->isDepot(),
                                                    default => true,
                                                };
                                            @endphp
                                            <option value="{{ $tank->id }}"
                                                data-warehouse="{{ $wh->name }}"
                                                data-category="{{ $tank->category }}"
                                                data-capacity-remaining="{{ $tank->remaining_capacity }}"
                                                data-max-capacity="{{ $tank->max_capacity }}"
                                                {{ (old('destination_tank_id', $destinationTankId) == $tank->id) ? 'selected' : '' }}
                                                {{ (!$matchMode && $mode !== 'general') ? 'class=text-muted' : '' }}>
                                                {{ $tank->name }} ({{ ucfirst($tank->category) }}) — {{ number_format($tank->remaining_capacity) }}L Room
                                            </option>
                                        @endforeach
                                    </optgroup>
                                @endforeach
                            </select>
                            @error('destination_tank_id')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                            <div id="dest_tank_info" class="mt-2 small text-muted"></div>
                        </div>
                    </div>

                    <div class="row g-3 mb-4">
                        <!-- Transfer Date -->
                        <div class="col-md-6">
                            <label for="transfer_date" class="form-label fw-medium text-secondary small">Transfer Date</label>
                            <input type="date" name="transfer_date" id="transfer_date" class="form-control @error('transfer_date') is-invalid @enderror" value="{{ old('transfer_date', date('Y-m-d')) }}" required>
                            @error('transfer_date')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>

                        <!-- Quantity -->
                        <div class="col-md-6">
                            <label for="quantity" class="form-label fw-medium text-secondary small">Transfer Quantity (Liters)</label>
                            <div class="input-group">
                                <input type="number" name="quantity" id="quantity" class="form-control font-monospace @error('quantity') is-invalid @enderror" placeholder="e.g. 10000" value="{{ old('quantity') }}" min="1" step="1" required>
                                <span class="input-group-text">Liters</span>
                            </div>
                            @error('quantity')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                            <div id="qty_validation_feedback" class="small mt-1"></div>
                        </div>
                    </div>

                    <!-- Notes -->
                    <div class="mb-4">
                        <label for="notes" class="form-label fw-medium text-secondary small">Notes / Trip Ticket / Reference</label>
                        <textarea name="notes" id="notes" class="form-control @error('notes') is-invalid @enderror" rows="3" placeholder="Optional notes, driver name, trip ticket #...">{{ old('notes') }}</textarea>
                        @error('notes')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    <!-- Live Validation Alert Box -->
                    <div id="transfer_alert" class="alert alert-warning d-none small mb-4">
                        <i class="bi bi-exclamation-triangle-fill me-2"></i><span id="transfer_alert_text"></span>
                    </div>

                    <div class="d-flex justify-content-end gap-2">
                        <a href="{{ route('wetstock.transfers.index') }}" class="btn btn-light border">Cancel</a>
                        <button type="submit" class="btn btn-primary-custom px-4" id="submitBtn">
                            <i class="bi bi-check2-circle me-1"></i> Execute Transfer
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function() {
    const sourceSelect = document.getElementById('source_tank_id');
    const destSelect = document.getElementById('destination_tank_id');
    const qtyInput = document.getElementById('quantity');
    const alertBox = document.getElementById('transfer_alert');
    const alertText = document.getElementById('transfer_alert_text');
    const submitBtn = document.getElementById('submitBtn');
    const sourceInfo = document.getElementById('source_tank_info');
    const destInfo = document.getElementById('dest_tank_info');

    function updateLiveValidation() {
        alertBox.classList.add('d-none');
        submitBtn.disabled = false;

        const sourceOpt = sourceSelect.options[sourceSelect.selectedIndex];
        const destOpt = destSelect.options[destSelect.selectedIndex];
        const qty = parseInt(qtyInput.value) || 0;

        let sourceAvail = 0;
        let destRemaining = 0;

        if (sourceOpt && sourceOpt.value) {
            sourceAvail = parseInt(sourceOpt.dataset.available) || 0;
            sourceInfo.innerHTML = `<span class="badge bg-primary-subtle text-primary">Available for transfer: ${sourceAvail.toLocaleString()} L</span>`;
        } else {
            sourceInfo.innerHTML = '';
        }

        if (destOpt && destOpt.value) {
            destRemaining = parseInt(destOpt.dataset.capacityRemaining) || 0;
            const maxCap = parseInt(destOpt.dataset.maxCapacity) || 0;
            destInfo.innerHTML = `<span class="badge bg-success-subtle text-success">Remaining Room: ${destRemaining.toLocaleString()} L</span> <span class="text-muted">(${maxCap.toLocaleString()}L Max)</span>`;
        } else {
            destInfo.innerHTML = '';
        }

        if (sourceOpt && destOpt && sourceOpt.value && destOpt.value && sourceOpt.value === destOpt.value) {
            alertText.textContent = "Source tank and Destination tank cannot be the same.";
            alertBox.classList.remove('d-none');
            submitBtn.disabled = true;
            return;
        }

        if (qty > 0 && sourceOpt && sourceOpt.value && qty > sourceAvail) {
            alertText.textContent = `Transfer volume (${qty.toLocaleString()}L) exceeds available fuel in source tank (${sourceAvail.toLocaleString()}L).`;
            alertBox.classList.remove('d-none');
            submitBtn.disabled = true;
            return;
        }

        if (qty > 0 && destOpt && destOpt.value && qty > destRemaining) {
            alertText.textContent = `Transfer volume (${qty.toLocaleString()}L) exceeds remaining capacity of destination tank (${destRemaining.toLocaleString()}L).`;
            alertBox.classList.remove('d-none');
            submitBtn.disabled = true;
            return;
        }
    }

    sourceSelect.addEventListener('change', updateLiveValidation);
    destSelect.addEventListener('change', updateLiveValidation);
    qtyInput.addEventListener('input', updateLiveValidation);
    updateLiveValidation();
});
</script>
@endpush
@endsection
