@extends('layouts.app')

@section('title', 'Create Client')

@section('content')
<div class="row justify-content-center">
    <div class="col-md-8 col-lg-6">
        <div class="mb-3">
            <a href="{{ route('clients.index') }}" class="text-decoration-none text-secondary small">
                <i class="bi bi-arrow-left me-1"></i> Back to Clients
            </a>
        </div>
        <div class="card card-custom p-4 border-0">
            <div class="card-body">
                <h4 class="fw-bold mb-4 text-dark">
                    <i class="bi bi-building-add text-primary me-2"></i>Create Client
                </h4>

                <form method="POST" action="{{ route('clients.store') }}" novalidate>
                    @csrf

                    <div class="mb-4">
                        <label for="name" class="form-label fw-medium text-secondary small">Client Name</label>
                        <input type="text" name="name" id="name" class="form-control @error('name') is-invalid @enderror" placeholder="e.g. COMPANY ABC" value="{{ old('name') }}" required>
                        @error('name')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    <div class="d-flex justify-content-end gap-2">
                        <a href="{{ route('clients.index') }}" class="btn btn-light border">Cancel</a>
                        <button type="submit" class="btn btn-primary-custom">Create Client</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
@endsection
