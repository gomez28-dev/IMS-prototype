<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Authority To Load - {{ $delivery->atl_number ?? ($po?->po_number ?? 'ATL') }}</title>
    <style>
        @page {
            margin: 30px 38px 46px 38px;
            size: letter landscape;
        }
        body {
            font-family: 'Montserrat', sans-serif;
            font-size: 9pt;
            line-height: 1.35;
            color: #111;
            margin: 0;
            padding: 0;
        }
        table {
            width: 100%;
            border-collapse: collapse;
        }
        .header-table td {
            padding: 0;
            vertical-align: middle;
        }
        .logo-img {
            width: 176px;
            height: auto;
        }
        .doc-title {
            margin: 0;
            font-size: 22pt;
            font-weight: 800;
            letter-spacing: 1.2px;
            text-align: right;
            text-transform: uppercase;
            color: #111;
        }
        .rule {
            border-top: 1.4px solid #000;
            margin: 10px 0 16px 0;
        }
        /* Every framed block on the form: black hairlines, rice-paper labels. */
        .boxed td,
        .boxed th {
            border: 1px solid #000;
        }
        .label {
            width: 20%;
            padding: 5px 8px;
            background-color: #fee8e5;
            font-size: 7.5pt;
            font-weight: 700;
            letter-spacing: 0.5px;
            text-transform: uppercase;
            vertical-align: middle;
        }
        .value {
            padding: 5px 8px;
            font-size: 9pt;
            vertical-align: middle;
        }
        .grid {
            margin-bottom: 13px;
        }
        .grid .label {
            width: 16%;
        }
        .grid .value {
            width: 34%;
        }
        .items {
            margin-bottom: 13px;
        }
        .items th {
            padding: 6px 8px;
            background-color: #fee8e5;
            font-size: 7.5pt;
            font-weight: 700;
            letter-spacing: 0.5px;
            text-transform: uppercase;
            text-align: center;
        }
        .items td {
            height: 24px;
            padding: 7px 8px;
            text-align: center;
            vertical-align: middle;
        }
        .items td.strong {
            font-weight: 700;
        }
        .detail td {
            height: 20px;
        }
        .signatures {
            margin-top: 24px;
        }
        .signatures td {
            width: 50%;
            padding: 0 8px;
            vertical-align: top;
        }
        .sig-label {
            font-size: 7.5pt;
            font-weight: 700;
            letter-spacing: 0.5px;
            text-transform: uppercase;
        }
        .sig-space {
            height: 34px;
        }
        .sig-name {
            font-size: 10.5pt;
            font-weight: 800;
            letter-spacing: 0.4px;
            text-align: center;
            text-transform: uppercase;
        }
        .sig-caption {
            margin-top: 2px;
            font-size: 8.5pt;
            font-style: italic;
            text-align: center;
            color: #333;
        }
        .footer {
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            padding-top: 5px;
            border-top: 1.2px solid #000;
            text-align: center;
            font-size: 8pt;
            color: #444;
        }
    </style>
</head>
<body>

    {{-- Masthead: the wordmark left, the document's name set hard against the
         right margin, exactly as the printed form is laid out. --}}
    <table class="header-table">
        <tr>
            <td style="width: 42%;">
                @if (!empty($logoBase64))
                    <img src="{{ $logoBase64 }}" class="logo-img" alt="Doyen Petroleum">
                @else
                    <div style="font-size: 20px; font-weight: 800; letter-spacing: 1px;">DOYEN PETROLEUM</div>
                @endif
            </td>
            <td style="width: 58%;">
                <h1 class="doc-title">Authority to Load</h1>
            </td>
        </tr>
    </table>

    <div class="rule"></div>

    {{-- Issued date, reference, company and sales order: the four boxes that
         open the printed form. --}}
    <table class="boxed grid">
        <tr>
            <td class="label">Issued Date</td>
            <td class="value">
                @if ($delivery->approved_at)
                    {{ \Carbon\Carbon::parse($delivery->approved_at)->format('F d, Y') }}
                @elseif ($po?->approved_at)
                    {{ \Carbon\Carbon::parse($po->approved_at)->format('F d, Y') }}
                @else
                    {{ now()->format('F d, Y') }}
                @endif
            </td>
            <td class="label">Reference No.</td>
            <td class="value">{{ $delivery->atl_number ?: ($delivery->client_atl_number ?: '—') }}</td>
        </tr>
        <tr>
            <td class="label">Company</td>
            <td class="value">{{ $po?->supplier_name ?: ($delivery->stockRequest?->requester?->name ?: '—') }}</td>
            <td class="label">S.O. No.</td>
            <td class="value">{{ $delivery->order?->formatted_so_number ?: ($delivery->so_number ?: ($delivery->stockRequest?->request_number ?: '—')) }}</td>
        </tr>
    </table>

    {{-- One line per PO allocation, the way the on-screen ATL record lists
         them. Driver, plate, date and location belong to the lift itself, so
         they print once in the block below rather than on every line. --}}
    <table class="boxed items">
        <thead>
            <tr>
                <th style="width: 30%;">P.O. No.</th>
                <th style="width: 40%;">Product</th>
                <th style="width: 30%;">Quantity</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($delivery->allocations as $allocation)
                <tr>
                    <td class="strong">{{ $allocation->purchaseOrder?->po_number ?: ($po?->po_number ?: '—') }}</td>
                    <td>{{ $allocation->product }}</td>
                    <td>{{ number_format($allocation->quantity) }} L</td>
                </tr>
            @empty
                {{-- Legacy rows written before allocations existed still print,
                     from the delivery's own single product line. --}}
                <tr>
                    <td class="strong">{{ $po?->po_number ?: ($delivery->stockRequest?->request_number ?: '—') }}</td>
                    <td>{{ $delivery->product }}</td>
                    <td>{{ number_format($delivery->qty_to_receive) }} L</td>
                </tr>
            @endforelse
        </tbody>
    </table>

    {{-- The lift's own particulars. --}}
    <table class="boxed detail">
        <tr>
            <td class="label">Driver's Name</td>
            <td class="value">{{ $delivery->driver_name ?: '—' }}</td>
        </tr>
        <tr>
            <td class="label">Plate No.</td>
            <td class="value">{{ $delivery->plate_number ?: '—' }}</td>
        </tr>
        <tr>
            <td class="label">Pick Up Date</td>
            <td class="value">
                {{ $delivery->receiving_date ? \Carbon\Carbon::parse($delivery->receiving_date)->format('F d, Y') : '—' }}
            </td>
        </tr>
        <tr>
            <td class="label">Location</td>
            <td class="value">{{ $delivery->location ?: '—' }}</td>
        </tr>
        <tr>
            <td class="label">Additional Remarks</td>
            <td class="value">{{ $delivery->additional_remarks ?: '—' }}</td>
        </tr>
    </table>

    {{-- Signatures: label at the left, printed name and its caption centred
         under the space left for the wet signature. --}}
    <table class="signatures">
        <tr>
            <td>
                <div class="sig-label">Prepared by:</div>
                <div class="sig-space"></div>
                <div class="sig-name">Rica Esaga</div>
                <div class="sig-caption">signature over printed name</div>
            </td>
            <td>
                <div class="sig-label">Approved by:</div>
                <div class="sig-space"></div>
                <div class="sig-name">Bernice Nikki Lee</div>
                <div class="sig-caption">signature over printed name</div>
            </td>
        </tr>
    </table>

    <div class="footer">www.doyengroupofcompanies.com</div>

</body>
</html>
