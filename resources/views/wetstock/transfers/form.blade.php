@extends('layouts.app')

@section('title', $title)

@section('content')
<div class="row justify-content-center">
    <div class="col-lg-8">
        <div class="mb-3">
            <a href="{{ route('wetstock.transfers.index', ['type' => $type]) }}" class="text-decoration-none text-secondary small">
                <i class="bi bi-arrow-left me-1"></i> Back to Stock Transfers Log
            </a>
        </div>

        <div class="card card-custom border-0 shadow-sm">
            <div class="card-body p-4">
                <div class="d-flex align-items-center justify-content-between mb-4 border-bottom pb-3 flex-wrap gap-2">
                    <div>
                        <h4 class="fw-bold text-dark mb-1">
                            <i class="bi bi-arrow-left-right text-primary me-2"></i>{{ $title }}
                        </h4>
                        <p class="text-muted small mb-0">
                            @if ($type === 'transfer')
                                Intra-Site Movement: Source and destination tanks must belong to the <strong>same warehouse</strong>.
                            @elseif ($type === 'borrow')
                                Cross-Site Borrowing: Source and destination tanks must belong to <strong>different warehouses</strong>.
                            @else
                                Cross-Site Return: Source and destination tanks must belong to <strong>different warehouses</strong>.
                            @endif
                        </p>
                    </div>

                    @if (!$transfer)
                    <div class="btn-group btn-group-sm" role="group">
                        <a href="{{ route('wetstock.transfers.create', ['type' => 'transfer']) }}" class="btn btn-outline-primary {{ $type === 'transfer' ? 'active' : '' }}">
                            Transfer
                        </a>
                        <a href="{{ route('wetstock.transfers.create', ['type' => 'borrow']) }}" class="btn btn-outline-primary {{ $type === 'borrow' ? 'active' : '' }}">
                            Borrow
                        </a>
                        <a href="{{ route('wetstock.transfers.create', ['type' => 'return']) }}" class="btn btn-outline-success {{ $type === 'return' ? 'active' : '' }}">
                            Return
                        </a>
                    </div>
                    @endif
                </div>

                @if ($transfer)
                    <div class="alert alert-warning border-0 rounded-3 py-2 px-3 small mb-4">
                        <i class="bi bi-info-circle-fill me-1"></i> <strong>Approval Notice:</strong> Modifying an existing transfer creates a <strong>Modification Request</strong> for Operations Manager review before updating the live inventory balances.
                    </div>
                @endif

                <form method="POST" action="{{ $transfer ? route('wetstock.transfers.update', $transfer->id) : route('wetstock.transfers.store') }}" id="transferForm">
                    @csrf
                    <input type="hidden" name="type" id="transfer_type" value="{{ $type }}">

                    <div class="row g-3 mb-4">
                        <!-- Source Tank -->
                        <div class="col-md-6">
                            <label for="source_tank_id" class="form-label fw-bold text-dark small">
                                <i class="bi bi-box-arrow-up-right text-danger me-1"></i>Source Tank (From)
                            </label>
                            <select name="source_tank_id" id="source_tank_id" class="form-select @error('source_tank_id') is-invalid @enderror" required>
                                <option value="">Select Source Tank...</option>
                                @foreach ($warehouses as $wh)
                                    <optgroup label="{{ $wh->name }}">
                                        @foreach ($wh->activeTanks as $tank)
                                            <option value="{{ $tank->id }}"
                                                data-warehouse-id="{{ $wh->id }}"
                                                data-warehouse-name="{{ $wh->name }}"
                                                data-category="{{ $tank->category }}"
                                                data-available="{{ $tank->effective_available }}"
                                                data-contaminated="{{ $tank->is_contaminated ? 1 : 0 }}"
                                                {{ (old('source_tank_id', $transfer ? $transfer->source_tank_id : $sourceTankId) == $tank->id) ? 'selected' : '' }}
                                                {{ ($tank->is_contaminated && (!$transfer || $transfer->source_tank_id != $tank->id)) ? 'disabled' : '' }}>
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
                                <i class="bi bi-box-arrow-in-down-left text-success me-1"></i>Destination Tank (To)
                            </label>
                            <select name="destination_tank_id" id="destination_tank_id" class="form-select @error('destination_tank_id') is-invalid @enderror" required>
                                <option value="">Select Destination Tank...</option>
                                @foreach ($warehouses as $wh)
                                    <optgroup label="{{ $wh->name }}">
                                        @foreach ($wh->activeTanks as $tank)
                                            <option value="{{ $tank->id }}"
                                                data-warehouse-id="{{ $wh->id }}"
                                                data-warehouse-name="{{ $wh->name }}"
                                                data-category="{{ $tank->category }}"
                                                data-capacity-remaining="{{ $tank->remaining_capacity }}"
                                                data-max-capacity="{{ $tank->max_capacity }}"
                                                {{ (old('destination_tank_id', $transfer ? $transfer->destination_tank_id : $destinationTankId) == $tank->id) ? 'selected' : '' }}>
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
                            <label for="transfer_date" class="form-label fw-medium text-secondary small">Transaction Date</label>
                            <input type="date" name="transfer_date" id="transfer_date" class="form-control @error('transfer_date') is-invalid @enderror" value="{{ old('transfer_date', $transfer ? ($transfer->transfer_date ? $transfer->transfer_date->format('Y-m-d') : '') : date('Y-m-d')) }}" required>
                            @error('transfer_date')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>

                        <!-- Quantity -->
                        <div class="col-md-6">
                            <label for="quantity" class="form-label fw-medium text-secondary small">Volume (Liters)</label>
                            <div class="input-group">
                                <input type="number" name="quantity" id="quantity" class="form-control font-monospace @error('quantity') is-invalid @enderror" placeholder="e.g. 10000" value="{{ old('quantity', $transfer ? $transfer->quantity : '') }}" min="1" step="1" required>
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
                        <textarea name="notes" id="notes" class="form-control @error('notes') is-invalid @enderror" rows="3" placeholder="Optional notes, driver name, trip ticket #...">{{ old('notes', $transfer ? $transfer->notes : '') }}</textarea>
                        @error('notes')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    @if ($transfer)
                    <!-- Modification Reason -->
                    <div class="mb-4">
                        <label for="modification_reason" class="form-label fw-bold text-dark small">Reason for Modification</label>
                        <textarea name="modification_reason" id="modification_reason" class="form-control @error('modification_reason') is-invalid @enderror" rows="2" placeholder="e.g. Quantity correction per actual tank dipping..." required>{{ old('modification_reason') }}</textarea>
                        @error('modification_reason')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>
                    @endif

                    <!-- Live Validation Alert Box -->
                    <div id="transfer_alert" class="alert alert-warning d-none small mb-4">
                        <i class="bi bi-exclamation-triangle-fill me-2"></i><span id="transfer_alert_text"></span>
                    </div>

                    <div class="d-flex justify-content-end gap-2">
                        <a href="{{ route('wetstock.transfers.index', ['type' => $type]) }}" class="btn btn-light border">Cancel</a>
                        <button type="submit" class="btn btn-primary-custom px-4" id="submitBtn">
                            <i class="bi bi-check2-circle me-1"></i> {{ $transfer ? 'Submit Modification Request' : 'Save Record' }}
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
    const typeInput = document.getElementById('transfer_type');
    const alertBox = document.getElementById('transfer_alert');
    const alertText = document.getElementById('transfer_alert_text');
    const submitBtn = document.getElementById('submitBtn');
    const sourceInfo = document.getElementById('source_tank_info');
    const destInfo = document.getElementById('dest_tank_info');

    const currentType = typeInput ? typeInput.value : 'transfer';

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
            sourceInfo.innerHTML = `<span class="badge bg-primary-subtle text-primary">Available for transfer: ${sourceAvail.toLocaleString()} L (${sourceOpt.dataset.warehouseName})</span>`;
        } else {
            sourceInfo.innerHTML = '';
        }

        if (destOpt && destOpt.value) {
            destRemaining = parseInt(destOpt.dataset.capacityRemaining) || 0;
            const maxCap = parseInt(destOpt.dataset.maxCapacity) || 0;
            destInfo.innerHTML = `<span class="badge bg-success-subtle text-success">Remaining Room: ${destRemaining.toLocaleString()} L</span> <span class="text-muted">(${maxCap.toLocaleString()}L Max, ${destOpt.dataset.warehouseName})</span>`;
        } else {
            destInfo.innerHTML = '';
        }

        if (sourceOpt && destOpt && sourceOpt.value && destOpt.value) {
            const sourceWhId = sourceOpt.dataset.warehouseId;
            const destWhId = destOpt.dataset.warehouseId;

            if (sourceOpt.value === destOpt.value) {
                alertText.textContent = "Source tank and Destination tank cannot be the same.";
                alertBox.classList.remove('d-none');
                submitBtn.disabled = true;
                return;
            }

            // Warehouse validation
            if (currentType === 'transfer' && sourceWhId !== destWhId) {
                alertText.textContent = `Intra-Site Transfer Error: Source (${sourceOpt.dataset.warehouseName}) and Destination (${destOpt.dataset.warehouseName}) must be in the SAME warehouse. For cross-site fuel movement, switch to Borrow or Return.`;
                alertBox.classList.remove('d-none');
                submitBtn.disabled = true;
                return;
            }

            if ((currentType === 'borrow' || currentType === 'return') && sourceWhId === destWhId) {
                alertText.textContent = `Cross-Site ${currentType.toUpperCase()} Error: Source and Destination must be in DIFFERENT warehouses. For movements within the same site, switch to Transfer.`;
                alertBox.classList.remove('d-none');
                submitBtn.disabled = true;
                return;
            }
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
