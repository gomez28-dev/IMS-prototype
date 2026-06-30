@extends('layouts.app')

@section('title', 'Import Inventory from Excel')

@section('content')
<div class="row justify-content-center">
    <div class="col-md-8 col-lg-6">
        <div class="mb-3">
            <a href="{{ route('dashboard') }}" class="text-decoration-none text-secondary small">
                <i class="bi bi-arrow-left me-1"></i> Back to Dashboard
            </a>
        </div>
        <div class="card card-custom p-4 border-0">
            <div class="card-body">
                <h4 class="fw-bold mb-3 text-dark">
                    <i class="bi bi-file-earmark-excel text-success me-2"></i>Import Excel Inventory
                </h4>
                <p class="text-muted small mb-4">
                    Upload an Excel file (.xlsx or .xls) to import or merge inventory records. The importer will match orders by <strong>SO#</strong>: updating existing ones and creating new ones if they don't exist.
                </p>

                <form method="POST" action="{{ route('import') }}" enctype="multipart/form-data">
                    @csrf

                    <div class="mb-4">
                        <label for="excel_file" class="form-label fw-medium text-secondary small">Choose Excel File</label>
                        <input class="form-control @error('excel_file') is-invalid @enderror" type="file" id="excel_file" name="excel_file" accept=".xlsx, .xls" required>
                        @error('excel_file')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    <div class="bg-light p-3 rounded-3 mb-4 border border-light-subtle">
                        <h6 class="fw-bold text-secondary small mb-2"><i class="bi bi-info-circle me-1"></i>Expected Sheet Format:</h6>
                        <ul class="text-muted extra-small mb-0 ps-3" style="font-size: 0.8rem; line-height: 1.5;">
                            <li>The first row of the sheet can contain description headers.</li>
                            <li>The <strong>second row</strong> (Row 2) must be the column header row containing:
                                <code>ACCOUNT</code>, <code>DATE</code>, <code>QTY ORDERED</code>, <code>SO#</code>, <code>DR#</code>, <code>DELIVERY DATE</code>, <code>QTY OUT</code>, <code>DELIVERY STATUS</code>, <code>REMARKS</code>.
                            </li>
                            <li>Subsequent rows map to order and delivery details. An order row starts with a populated <code>ACCOUNT</code>, and subsequent delivery rows for that order leave <code>ACCOUNT</code> blank.</li>
                        </ul>
                    </div>

                    <div class="d-flex justify-content-end gap-2">
                        <a href="{{ route('dashboard') }}" class="btn btn-light border">Cancel</a>
                        <button type="submit" class="btn btn-primary-custom">Start Import</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
@endsection
