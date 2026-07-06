@extends('layouts.app')

@section('title', 'Manage Clients')

@section('content')
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h2 class="fw-bold text-dark mb-1">Manage Clients</h2>
        <p class="text-muted small mb-0">Create and manage client accounts for order assignments.</p>
    </div>
    <a href="{{ route('clients.create') }}" class="btn btn-primary-custom shadow-sm d-flex align-items-center">
        <i class="bi bi-building-add me-2"></i> Create Client
    </a>
</div>

<!-- Tabs toggle -->
@if (Auth::user()->isAdmin())
<ul class="nav nav-tabs mb-4">
    <li class="nav-item">
        <a class="nav-link" href="{{ route('accounts.index') }}">
            <i class="bi bi-people me-1"></i> System Accounts
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link active" href="{{ route('clients.index') }}">
            <i class="bi bi-building me-1"></i> Client Accounts
        </a>
    </li>
</ul>
@endif

<div class="card card-custom border-0 overflow-hidden">
    <div class="card-body p-0">
        <!-- Desktop table -->
        <div class="table-responsive d-none d-md-block">
            <table class="table table-custom table-hover align-middle mb-0">
                <thead>
                    <tr>
                        <th class="ps-4">Client Name</th>
                        <th class="text-end pe-4">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @if ($clients->isNotEmpty())
                        @foreach ($clients as $client)
                        <tr>
                            <td class="ps-4 fw-semibold text-dark">{{ $client->name }}</td>
                            <td class="text-end pe-4">
                                <div class="d-flex justify-content-end gap-2">
                                    <a href="{{ route('clients.edit', $client->id) }}" class="btn btn-sm btn-outline-secondary rounded-3 px-3 py-1" title="Edit Client">
                                        <i class="bi bi-pencil me-1"></i> Edit
                                    </a>
                                    <form method="POST" action="{{ route('clients.destroy', $client->id) }}" class="d-inline" onsubmit="return confirm('Are you sure you want to delete this client?');">
                                        @csrf
                                        <button type="submit" class="btn btn-sm btn-outline-danger rounded-3 px-3 py-1" title="Delete Client">
                                            <i class="bi bi-trash"></i>
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                        @endforeach
                    @else
                        <tr>
                            <td colspan="2" class="text-center py-5 text-muted">
                                <i class="bi bi-building fs-1 d-block mb-3 text-secondary"></i>
                                No clients found.
                            </td>
                        </tr>
                    @endif
                </tbody>
            </table>
        </div>

        <!-- Mobile cards -->
        <div class="d-md-none p-3">
            @if ($clients->isNotEmpty())
                @foreach ($clients as $client)
                <div class="card border-0 bg-light mb-3 rounded-4 shadow-sm">
                    <div class="card-body p-4">
                        <h5 class="fw-bold text-dark mb-3">{{ $client->name }}</h5>
                        <div class="d-flex gap-2">
                            <a href="{{ route('clients.edit', $client->id) }}" class="btn btn-sm btn-outline-secondary rounded-3 px-3 py-2 flex-fill text-center">
                                <i class="bi bi-pencil me-1"></i> Edit
                            </a>
                            <form method="POST" action="{{ route('clients.destroy', $client->id) }}" class="d-inline" onsubmit="return confirm('Are you sure you want to delete this client?');">
                                @csrf
                                <button type="submit" class="btn btn-sm btn-outline-danger rounded-3 px-3 py-2">
                                    <i class="bi bi-trash"></i> Delete
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
                @endforeach
            @else
                <div class="text-center py-5 text-muted">
                    <i class="bi bi-building fs-1 d-block mb-3 text-secondary"></i>
                    No clients found.
                </div>
            @endif
        </div>
    </div>
</div>

<!-- Pagination -->
<div class="d-flex justify-content-center mt-4">
    {{ $clients->links() }}
</div>
@endsection
