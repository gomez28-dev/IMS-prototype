<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Authority To Load - {{ $delivery->atl_number ?? $po->po_number }}</title>
    <style>
        @page {
            margin: 28px 32px 32px 32px;
            size: letter portrait;
        }
        body {
            font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif;
            font-size: 11px;
            line-height: 1.4;
            color: #222;
            margin: 0;
            padding: 0;
        }
        .header-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
        }
        .logo-cell {
            width: 35%;
            vertical-align: middle;
        }
        .logo-img {
            max-width: 180px;
            height: auto;
        }
        .title-cell {
            width: 65%;
            text-align: right;
            vertical-align: middle;
        }
        .doc-title {
            font-size: 22px;
            font-weight: 800;
            letter-spacing: 1.5px;
            color: #c92a2a;
            margin: 0;
            text-transform: uppercase;
        }
        .doc-subtitle {
            font-size: 11px;
            font-weight: 600;
            color: #666;
            margin-top: 4px;
        }
        .meta-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 22px;
        }
        .meta-table td {
            padding: 6px 10px;
            font-size: 11px;
            border: 1px solid #e2b6b5;
        }
        .meta-label {
            background-color: #fce8e6;
            font-weight: 700;
            color: #491217;
            width: 18%;
            text-transform: uppercase;
            font-size: 10px;
        }
        .meta-value {
            background-color: #ffffff;
            color: #111;
            width: 32%;
            font-weight: 600;
        }
        .details-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 22px;
        }
        .details-table th {
            background-color: #fce8e6;
            color: #491217;
            border: 1px solid #e2b6b5;
            padding: 8px 6px;
            font-size: 10px;
            font-weight: 700;
            text-transform: uppercase;
            text-align: center;
        }
        .details-table td {
            border: 1px solid #e2b6b5;
            padding: 10px 8px;
            font-size: 11px;
            text-align: center;
            vertical-align: middle;
        }
        .details-table td.text-left {
            text-align: left;
        }
        .remarks-box {
            border: 1px solid #e2b6b5;
            margin-bottom: 25px;
        }
        .remarks-header {
            background-color: #fce8e6;
            color: #491217;
            font-weight: 700;
            font-size: 10px;
            padding: 6px 10px;
            text-transform: uppercase;
            border-bottom: 1px solid #e2b6b5;
        }
        .remarks-content {
            padding: 10px 12px;
            font-size: 11px;
            min-height: 40px;
            background-color: #fff;
            color: #333;
        }
        .signatures-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 25px;
            margin-bottom: 30px;
        }
        .signature-col {
            width: 50%;
            padding: 0 25px;
            text-align: center;
            vertical-align: top;
        }
        .sig-label {
            font-size: 10px;
            font-weight: 700;
            color: #666;
            text-transform: uppercase;
            margin-bottom: 45px;
            text-align: left;
        }
        .sig-name {
            font-size: 12px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            border-top: 1.5px solid #222;
            padding-top: 6px;
            color: #111;
        }
        .sig-title {
            font-size: 10px;
            color: #555;
            font-weight: 500;
        }
        .footer {
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            text-align: center;
            font-size: 9.5px;
            color: #888;
            border-top: 1px solid #eee;
            padding-top: 8px;
        }
    </style>
</head>
<body>

    <!-- Header Section -->
    <table class="header-table">
        <tr>
            <td class="logo-cell">
                @if(!empty($logoBase64))
                    <img src="{{ $logoBase64 }}" class="logo-img" alt="Doyen Petroleum">
                @else
                    <div style="font-size: 20px; font-weight: 900; color: #c92a2a; letter-spacing: 1px;">DOYEN PETROLEUM</div>
                @endif
            </td>
            <td class="title-cell">
                <h1 class="doc-title">AUTHORITY TO LOAD</h1>
                <div class="doc-subtitle">DOYEN PETROLEUM CORP. &bull; LOGISTICS & SUPPLY DIVISION</div>
            </td>
        </tr>
    </table>

    <!-- Metadata Section -->
    <table class="meta-table">
        <tr>
            <td class="meta-label">ISSUED DATE</td>
            <td class="meta-value">
                @if($delivery->approved_at)
                    {{ \Carbon\Carbon::parse($delivery->approved_at)->format('F d, Y') }}
                @elseif($po->approved_at)
                    {{ \Carbon\Carbon::parse($po->approved_at)->format('F d, Y') }}
                @else
                    {{ now()->format('F d, Y') }}
                @endif
            </td>
            <td class="meta-label">ATL NUMBER</td>
            <td class="meta-value" style="color: #c92a2a; font-weight: 700;">{{ $delivery->atl_number ?: ($delivery->client_atl_number ?: '—') }}</td>
        </tr>
        <tr>
            <td class="meta-label">COMPANY</td>
            <td class="meta-value">{{ $po->supplier_name ?: '—' }}</td>
            <td class="meta-label">S.O. NO.</td>
            <td class="meta-value">{{ $delivery->so_number ?: '—' }}</td>
        </tr>
        <tr>
            <td class="meta-label">DESTINATION / SITE</td>
            <td class="meta-value" colspan="3">{{ optional($po->warehouse)->name ?: ($po->isFuelTrade() ? 'Direct to Client Pick-Up' : '—') }}</td>
        </tr>
    </table>

    <!-- Product & Delivery Details Table -->
    <table class="details-table">
        <thead>
            <tr>
                <th style="width: 14%;">P.O. NO.</th>
                <th style="width: 14%;">PRODUCT</th>
                <th style="width: 12%;">QUANTITY</th>
                <th style="width: 17%;">DRIVER'S NAME</th>
                <th style="width: 13%;">PLATE NO.</th>
                <th style="width: 14%;">PICK UP DATE</th>
                <th style="width: 16%;">LOCATION</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td style="font-weight: 700;">
                    @if ($delivery->allocations && $delivery->allocations->isNotEmpty())
                        {{ $delivery->allocations->map(fn($a) => $a->purchaseOrder?->po_number)->filter()->unique()->implode(', ') ?: ($po->po_number ?: '—') }}
                        @if ($delivery->allocations->count() > 1)
                            <div style="font-size: 8px; color: #555; font-weight: normal; margin-top: 2px;">
                                {{ $delivery->allocations_summary }}
                            </div>
                        @endif
                    @else
                        {{ $po->po_number ?: '—' }}
                    @endif
                </td>
                <td><strong style="color: #222;">{{ $delivery->product }}</strong></td>
                <td style="font-weight: 700; color: #0d6efd;">{{ number_format($delivery->qty_to_receive) }} L</td>
                <td class="text-left">{{ $delivery->driver_name ?: '—' }}</td>
                <td><strong>{{ $delivery->plate_number ?: '—' }}</strong></td>
                <td>
                    {{ $delivery->receiving_date ? \Carbon\Carbon::parse($delivery->receiving_date)->format('F d, Y') : '—' }}
                </td>
                <td class="text-left">{{ $delivery->location ?: '—' }}</td>
            </tr>
        </tbody>
    </table>

    <!-- Additional Remarks -->
    <div class="remarks-box">
        <div class="remarks-header">ADDITIONAL REMARKS</div>
        <div class="remarks-content">
            {{ $delivery->additional_remarks ?: 'No special handling instructions indicated. Standard loading safety protocols apply.' }}
        </div>
    </div>

    <!-- Signatures -->
    <table class="signatures-table">
        <tr>
            <td class="signature-col">
                <div class="sig-label">PREPARED BY:</div>
                <div class="sig-name">RICA ESAGA</div>
                <div class="sig-title">Purchasing Department</div>
            </td>
            <td class="signature-col">
                <div class="sig-label">APPROVED BY:</div>
                <div class="sig-name">BERNICE NIKKI LEE</div>
                <div class="sig-title">Vice President</div>
            </td>
        </tr>
    </table>

    <!-- Footer -->
    <div class="footer">
        This document is an electronically issued Authority to Load (ATL) of Doyen Petroleum Corp. &bull; www.doyengroupofcompanies.com
    </div>

</body>
</html>
