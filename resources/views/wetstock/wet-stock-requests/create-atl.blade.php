@extends('layouts.app')

@section('title', 'Raise an ATL for '.$request->request_number)

@section('content')
<div class="container-fluid py-2">

    <a href="{{ route('stock-orders.wet-stock-requests.atls', $request->id) }}" class="btn btn-link text-decoration-none px-0 mb-3">
        <i class="bi bi-arrow-left me-1"></i> Back to ATLs
    </a>

    <h4 class="fw-bold text-dark mb-1">Raise an ATL</h4>
    <p class="text-muted small mb-4">
        {{ $request->request_number }} &middot; {{ $request->warehouse->name ?? '—' }}.
        Draw on whichever purchase order still has the volume outstanding.
    </p>

    @if ($errors->any())
        <div class="alert alert-danger alert-dismissible fade show rounded-3" role="alert">
            <i class="bi bi-exclamation-triangle-fill me-2"></i>
            <strong class="d-block mb-1">That ATL was not saved:</strong>
            <ul class="mb-0 ps-3">
                @foreach ($errors->all() as $message)
                    <li>{{ $message }}</li>
                @endforeach
            </ul>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    @endif

    {{-- The drawdown form is shared: the request's ATL page embeds this same
         partial inside its collapsible card, and this standalone page keeps
         serving it directly. One form, one payload, two ways in. --}}
    @include('wetstock.wet-stock-requests.partials.create-atl-form')
</div>

@endsection
