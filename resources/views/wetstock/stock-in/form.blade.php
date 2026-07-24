@extends('layouts.app')

@section('title', 'Log Stock IN')

@section('content')
<div class="row justify-content-center">
    <div class="col-md-8 col-lg-6">
        <div class="mb-3">
            <a href="{{ route('wetstock.stock-in.index') }}" class="text-decoration-none text-secondary small">
                <i class="bi bi-arrow-left me-1"></i> Back to Stock IN History
            </a>
        </div>

        <div class="card card-custom p-4 border-0">
            <div class="card-body">
                <h4 class="fw-bold mb-4 text-dark">
                    <i class="bi bi-plus-circle-dotted text-primary me-2"></i>Log Stock IN
                </h4>

                <form method="POST" action="{{ route('wetstock.stock-in.store') }}" novalidate>
                    @csrf

                    <div class="mb-3">
                        <label for="warehouse_id" class="form-label fw-medium text-secondary small">Select Warehouse</label>
                        <select id="warehouse_id" class="form-control form-select" required onchange="filterTanks()">
                            <option value="">-- Select Warehouse --</option>
                            @foreach ($warehouses as $warehouse)
                                <option value="{{ $warehouse->id }}">{{ $warehouse->name }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div class="mb-3">
                        <label for="storage_tank_id" class="form-label fw-medium text-secondary small">Select Storage Tank</label>
                        <select name="storage_tank_id" id="storage_tank_id" class="form-control form-select @error('storage_tank_id') is-invalid @enderror" required onchange="updateTankInfo()">
                            <option value="">-- First Select a Warehouse --</option>
                        </select>
                        @error('storage_tank_id')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    <!-- Tank Info Box -->
                    <div id="tankInfoBox" class="alert alert-info border-0 rounded-3 d-none mb-3 py-2 px-3 small">
                        <div class="d-flex justify-content-between">
                            <span>Max Capacity: <strong id="infoMaxCap">0</strong> L</span>
                            <span>Current Stock: <strong id="infoAvailable">0</strong> L</span>
                            <span class="text-success">Remaining Space: <strong id="infoRemaining">0</strong> L</span>
                        </div>
                    </div>

                    <div class="row mb-4">
                        <div class="col-md-6">
                            <label for="quantity" class="form-label fw-medium text-secondary small">Quantity Added (Liters)</label>
                            <input type="number" name="quantity" id="quantity" class="form-control @error('quantity') is-invalid @enderror" placeholder="e.g. 20000" value="{{ old('quantity') }}" min="1" required>
                            @error('quantity')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>
                        <div class="col-md-6">
                            <label for="date" class="form-label fw-medium text-secondary small">Stock IN Date</label>
                            <input type="date" name="date" id="date" class="form-control @error('date') is-invalid @enderror" value="{{ old('date', date('Y-m-d')) }}" required>
                            @error('date')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>
                    </div>

                    <div class="d-flex justify-content-end gap-2">
                        <a href="{{ route('wetstock.stock-in.index') }}" class="btn btn-light border">Cancel</a>
                        <button type="submit" class="btn btn-primary-custom">Log Stock IN</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
    const warehousesData = @json($warehouses);
    const selectedTankIdInitial = @json($selectedTankId);

    function filterTanks() {
        const warehouseId = document.getElementById('warehouse_id').value;
        const tankSelect = document.getElementById('storage_tank_id');
        tankSelect.innerHTML = '<option value="">-- Select Storage Tank --</option>';
        document.getElementById('tankInfoBox').classList.add('d-none');

        if (!warehouseId) return;

        const warehouse = warehousesData.find(w => w.id == warehouseId);
        if (warehouse && warehouse.tanks) {
            warehouse.tanks.forEach(tank => {
                const opt = document.createElement('option');
                opt.value = tank.id;
                opt.textContent = `${tank.name} (Capacity: ${tank.max_capacity.toLocaleString()}L)`;
                opt.dataset.maxCap = tank.max_capacity;
                opt.dataset.available = tank.stock_available;
                opt.dataset.remaining = tank.remaining_capacity;
                if (selectedTankIdInitial && selectedTankIdInitial == tank.id) {
                    opt.selected = true;
                }
                tankSelect.appendChild(opt);
            });
        }
        updateTankInfo();
    }

    function updateTankInfo() {
        const tankSelect = document.getElementById('storage_tank_id');
        const selectedOpt = tankSelect.options[tankSelect.selectedIndex];
        const infoBox = document.getElementById('tankInfoBox');

        if (selectedOpt && selectedOpt.value) {
            document.getElementById('infoMaxCap').textContent = Number(selectedOpt.dataset.maxCap).toLocaleString();
            document.getElementById('infoAvailable').textContent = Number(selectedOpt.dataset.available).toLocaleString();
            document.getElementById('infoRemaining').textContent = Number(selectedOpt.dataset.remaining).toLocaleString();
            infoBox.classList.remove('d-none');
        } else {
            infoBox.classList.add('d-none');
        }
    }

    // Auto-select initial tank if provided in query string
    document.addEventListener('DOMContentLoaded', function() {
        if (selectedTankIdInitial) {
            warehousesData.forEach(w => {
                if (w.tanks && w.tanks.some(t => t.id == selectedTankIdInitial)) {
                    document.getElementById('warehouse_id').value = w.id;
                    filterTanks();
                }
            });
        }
    });
</script>
@endsection
