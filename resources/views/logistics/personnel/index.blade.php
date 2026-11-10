@extends('layouts.app')

@section('title', 'Manage Personnel')

@section('content')
<div class="d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center mb-4 gap-3">
    <div>
        <h2 class="fw-bold text-dark mb-1">Manage Personnel</h2>
        <p class="text-muted small mb-0">Drivers and helpers who can be assigned to a tanker trip.</p>
    </div>
    <a href="{{ route('logistics.personnel.create') }}" class="btn btn-primary-custom shadow-sm d-flex align-items-center">
        <i class="bi bi-person-plus me-2"></i> Add Personnel
    </a>
</div>

<div class="card card-custom border-0 overflow-hidden">
    <div class="card-body p-0">
        <div class="table-responsive d-none d-md-block">
            <table class="table table-custom table-hover align-middle mb-0">
                <thead>
                    <tr>
                        <th class="ps-4">Name</th>
                        <th>Role</th>
                        <th>Phone</th>
                        <th>Status</th>
                        <th class="text-end pe-4">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($personnel as $person)
                        <tr>
                            <td class="ps-4 fw-semibold text-dark">{{ $person->name }}</td>
                            <td>{{ $person->role_label }}</td>
                            <td class="text-muted">{{ $person->phone ?: '-' }}</td>
                            <td>
                                @if ($person->is_active)
                                    <span class="badge bg-success-subtle text-success-emphasis rounded-pill">Active</span>
                                @else
                                    <span class="badge bg-secondary-subtle text-secondary-emphasis rounded-pill">Inactive</span>
                                @endif
                            </td>
                            <td class="text-end pe-4">
                                <div class="d-flex justify-content-end gap-2">
                                    <a href="{{ route('logistics.personnel.edit', $person->id) }}" class="btn btn-sm btn-outline-secondary rounded-3 px-3 py-1">
                                        <i class="bi bi-pencil me-1"></i> Edit
                                    </a>
                                    <form method="POST" action="{{ route('logistics.personnel.toggle', $person->id) }}" class="d-inline">
                                        @csrf
                                        <button type="submit" class="btn btn-sm {{ $person->is_active ? 'btn-outline-danger' : 'btn-outline-success' }} rounded-3 px-3 py-1">
                                            {{ $person->is_active ? 'Deactivate' : 'Activate' }}
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="text-center py-5 text-muted">
                                <i class="bi bi-people fs-1 d-block mb-3 text-secondary"></i>
                                No drivers or helpers yet.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="d-md-none p-3">
            @forelse ($personnel as $person)
                <div class="card border-0 bg-light mb-3 rounded-4 shadow-sm">
                    <div class="card-body p-4">
                        <div class="d-flex justify-content-between align-items-start mb-2">
                            <div>
                                <h5 class="fw-bold text-dark mb-0">{{ $person->name }}</h5>
                                <div class="small text-muted">{{ $person->role_label }}{{ $person->phone ? ' · ' . $person->phone : '' }}</div>
                            </div>
                            @if ($person->is_active)
                                <span class="badge bg-success-subtle text-success-emphasis rounded-pill">Active</span>
                            @else
                                <span class="badge bg-secondary-subtle text-secondary-emphasis rounded-pill">Inactive</span>
                            @endif
                        </div>
                        <div class="d-flex gap-2">
                            <a href="{{ route('logistics.personnel.edit', $person->id) }}" class="btn btn-sm btn-outline-secondary rounded-3 px-3 py-2 flex-fill text-center">
                                <i class="bi bi-pencil me-1"></i> Edit
                            </a>
                            <form method="POST" action="{{ route('logistics.personnel.toggle', $person->id) }}" class="d-inline">
                                @csrf
                                <button type="submit" class="btn btn-sm {{ $person->is_active ? 'btn-outline-danger' : 'btn-outline-success' }} rounded-3 px-3 py-2">
                                    {{ $person->is_active ? 'Deactivate' : 'Activate' }}
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
            @empty
                <div class="text-center py-5 text-muted">
                    <i class="bi bi-people fs-1 d-block mb-3 text-secondary"></i>
                    No drivers or helpers yet.
                </div>
            @endforelse
        </div>
    </div>
</div>

<div class="d-flex justify-content-center mt-4">
    {{ $personnel->links() }}
</div>
@endsection
