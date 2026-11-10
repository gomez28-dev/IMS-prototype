@extends('layouts.app')

@section('title', 'Edit Personnel')

@section('content')
<div class="row justify-content-center">
    <div class="col-md-8 col-lg-6">
        <div class="mb-3">
            <a href="{{ route('logistics.personnel.index') }}" class="text-decoration-none text-secondary small">
                <i class="bi bi-arrow-left me-1"></i> Back to Personnel
            </a>
        </div>
        <div class="card card-custom p-4 border-0">
            <div class="card-body">
                <h4 class="fw-bold mb-4 text-dark">
                    <i class="bi bi-pencil-square text-primary me-2"></i>Edit {{ $personnel->name }}
                </h4>

                <form method="POST" action="{{ route('logistics.personnel.update', $personnel->id) }}" novalidate>
                    @csrf

                    <div class="mb-3">
                        <label for="name" class="form-label fw-medium text-secondary small">Full Name</label>
                        <input type="text" name="name" id="name" class="form-control @error('name') is-invalid @enderror"
                               value="{{ old('name', $personnel->name) }}" required>
                        @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>

                    <div class="mb-3">
                        <label for="role" class="form-label fw-medium text-secondary small">Role</label>
                        <select name="role" id="role" class="form-select @error('role') is-invalid @enderror" required>
                            @foreach (\App\Models\Personnel::ROLES as $value => $label)
                                <option value="{{ $value }}" @selected(old('role', $personnel->role) === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                        @error('role')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>

                    <div class="mb-4">
                        <label for="phone" class="form-label fw-medium text-secondary small">Phone (optional)</label>
                        <input type="text" name="phone" id="phone" class="form-control @error('phone') is-invalid @enderror"
                               value="{{ old('phone', $personnel->phone) }}" maxlength="32">
                        @error('phone')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>

                    <div class="d-flex justify-content-end gap-2">
                        <a href="{{ route('logistics.personnel.index') }}" class="btn btn-light border">Cancel</a>
                        <button type="submit" class="btn btn-primary-custom">Save Changes</button>
                    </div>
                </form>

                <hr class="my-4">
                <form method="POST" action="{{ route('logistics.personnel.toggle', $personnel->id) }}"
                      onsubmit="return confirm('{{ $personnel->is_active ? 'Deactivate' : 'Activate' }} {{ $personnel->name }}? Past trips are kept.');">
                    @csrf
                    <div class="d-flex justify-content-between align-items-center gap-2">
                        <div class="small text-muted">
                            {{ $personnel->is_active ? 'Active — can be assigned to trips.' : 'Inactive — hidden from the crew pickers.' }}
                        </div>
                        <button type="submit" class="btn btn-sm {{ $personnel->is_active ? 'btn-outline-danger' : 'btn-outline-success' }}">
                            {{ $personnel->is_active ? 'Deactivate' : 'Activate' }}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
@endsection
