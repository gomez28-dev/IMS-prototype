<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Purchase Order - {{ $po->po_number }}</title>
    <style>
        @page {
            margin: 28px 34px 44px 34px;
            size: letter portrait;
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
            vertical-align: top;
        }
        .logo-img {
            width: 150px;
            height: auto;
        }
        .company-name {
            margin: 5px 0 0 0;
            font-size: 7.5pt;
            font-weight: 700;
            letter-spacing: 0.3px;
        }
        .company-line {
            margin: 1px 0 0 0;
            font-size: 7pt;
            color: #444;
        }
        .doc-title {
            margin: 0;
            font-size: 20pt;
            font-weight: 800;
            letter-spacing: 1.2px;
            text-align: right;
            text-transform: uppercase;
            color: #111;
        }
        .rule {
            border-top: 1.4px solid #000;
            margin: 10px 0 14px 0;
        }
        /* Every framed block on the form: black hairlines, rice-paper labels. */
        .boxed td,
        .boxed th {
            border: 1px solid #000;
        }
        .label {
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
            width: 15%;
        }
        .grid .value {
            width: 35%;
        }
        .items {
            margin-bottom: 0;
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
            height: 22px;
            padding: 6px 8px;
            vertical-align: middle;
        }
        .items td.center {
            text-align: center;
        }
        .items td.num {
            text-align: right;
        }
        .items td.particulars {
            text-transform: uppercase;
            letter-spacing: 0.4px;
            font-weight: 700;
        }
        .totals .label {
            width: 68%;
        }
        .totals .value {
            width: 32%;
            text-align: right;
            font-weight: 700;
        }
        .totals tr.net .value {
            font-weight: 800;
        }
        .foot {
            margin-top: 13px;
        }
        .foot .label {
            width: 22%;
        }
        .foot td.value {
            height: 34px;
        }
        .sig-caption {
            font-size: 8pt;
            font-style: italic;
            color: #444;
        }
        .signatures {
            margin-top: 22px;
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
            height: 30px;
        }
        .sig-name {
            font-size: 10.5pt;
            font-weight: 800;
            letter-spacing: 0.4px;
            text-align: center;
            text-transform: uppercase;
        }
        .sig-note {
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

    {{-- Masthead: wordmark and issuer identity left, the document's name set
         hard against the right margin, as on the printed ATL form. --}}
    <table class="header-table">
        <tr>
            <td style="width: 55%;">
                @if (!empty($logoBase64))
                    <img src="{{ $logoBase64 }}" class="logo-img" alt="Doyen Petroleum">
                @else
                    <div style="font-size: 18px; font-weight: 800; letter-spacing: 1px;">DOYEN PETROLEUM</div>
                @endif
                <div class="company-name">DOYEN INTERNATIONAL TRADING CORP.</div>
                <div class="company-line">2108 Lamesa St, Brgy. Ugong Distr. 2, Valenzuela City</div>
                <div class="company-line">Email Add: doyen.petroelum@yahoo.com</div>
            </td>
            <td style="width: 45%;">
                <h1 class="doc-title">Purchase Order</h1>
            </td>
        </tr>
    </table>

    <div class="rule"></div>

    {{-- Who the order is addressed to, and its reference details. --}}
    <table class="boxed grid">
        <tr>
            <td class="label">Company</td>
            <td class="value">{{ $supplierName }}</td>
            <td class="label">Reference No.</td>
            <td class="value" style="font-weight: 700;">{{ $po->po_number ?: '—' }}</td>
        </tr>
        <tr>
            <td class="label">Address</td>
            <td class="value">{{ $supplierAddress ?: '—' }}</td>
            <td class="label">Date</td>
            <td class="value">{{ $poDate ?: '—' }}</td>
        </tr>
        <tr>
            <td class="label">Attention</td>
            <td class="value">{{ $attention ?: '—' }}</td>
            <td class="label">Terms</td>
            <td class="value">{{ $po->terms ?: '—' }}</td>
        </tr>
    </table>

    {{-- Line items, one order line per row. --}}
    <table class="boxed items">
        <thead>
            <tr>
                <th style="width: 15%;">Quantity</th>
                <th style="width: 9%;">Unit</th>
                <th style="width: 38%;">Particulars</th>
                <th style="width: 19%;">Unit Price</th>
                <th style="width: 19%;">Amount</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($lines as $line)
                <tr>
                    <td class="num">{{ number_format($line['qty'], 0) }}</td>
                    <td class="center">{{ $line['unit'] ?: 'LTRS' }}</td>
                    <td class="particulars">{{ $line['particulars'] }}</td>
                    <td class="num">{{ number_format($line['price'], 2) }}</td>
                    <td class="num">{{ number_format($line['amount'], 2) }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="5" class="center">No product lines recorded.</td>
                </tr>
            @endforelse
        </tbody>
    </table>

    {{-- Totals. VAT rows are entered by hand, matching the printed form. --}}
    <table class="boxed totals">
        <tr>
            <td class="label">Total Amount</td>
            <td class="value">{{ number_format($totals['total_amount'], 2) }}</td>
        </tr>
        <tr>
            <td class="label">Vatable Sales Amount</td>
            <td class="value">{{ number_format($totals['vatable_sales_amount'], 2) }}</td>
        </tr>
        <tr>
            <td class="label">VAT Amount</td>
            <td class="value">{{ number_format($totals['vat_amount'], 2) }}</td>
        </tr>
        <tr>
            <td class="label">Less w/ Tax</td>
            <td class="value">{{ number_format($totals['less_w_tax'], 2) }}</td>
        </tr>
        <tr class="net">
            <td class="label">Net Payable Amount</td>
            <td class="value">{{ number_format($totals['net_payable_amount'], 2) }}</td>
        </tr>
    </table>

    {{-- Remarks, and the supplier's acceptance of the order. --}}
    <table class="boxed foot">
        <tr>
            <td class="label">Remarks</td>
            <td class="value">{{ $po->remarks ?: '—' }}</td>
        </tr>
        <tr>
            <td class="label">Order Accepted By</td>
            <td class="value">
                <span class="sig-caption">signature over printed name / date</span>
            </td>
        </tr>
    </table>

    {{-- Prepared and approved. Printed names only; no signature images. --}}
    <table class="signatures">
        <tr>
            <td>
                <div class="sig-label">Prepared by:</div>
                <div class="sig-space"></div>
                <div class="sig-name">{{ $preparedBy ?: '—' }}</div>
                <div class="sig-note">signature over printed name</div>
            </td>
            <td>
                <div class="sig-label">Approved by:</div>
                <div class="sig-space"></div>
                <div class="sig-name">{{ $approvedBy ?: '—' }}</div>
                <div class="sig-note">signature over printed name</div>
            </td>
        </tr>
    </table>

    <div class="footer">www.doyengroupofcompanies.com</div>

</body>
</html>
