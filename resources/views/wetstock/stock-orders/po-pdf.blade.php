<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Purchase Order - {{ $po->po_number }}</title>
    <style>
        @page {
            margin: 26px 30px 30px 30px;
            size: letter portrait;
        }
        body {
            font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif;
            font-size: 11px;
            line-height: 1.35;
            color: #222;
            margin: 0;
            padding: 0;
        }

        /* Header */
        .header-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 12px;
        }
        .logo-cell {
            width: 38%;
            vertical-align: middle;
        }
        .logo-img {
            max-width: 190px;
            height: auto;
        }
        .company-cell {
            width: 62%;
            text-align: right;
            vertical-align: middle;
        }
        .company-name {
            font-size: 15px;
            font-weight: 800;
            letter-spacing: 0.5px;
            color: #111;
            margin: 0;
        }
        .company-line {
            font-size: 9px;
            color: #444;
            margin: 1px 0 0;
        }

        /* Orange document banner */
        .doc-banner {
            width: 100%;
            background-color: #f7941d;
            color: #fff;
            font-size: 14px;
            font-weight: 800;
            letter-spacing: 2px;
            text-align: center;
            text-transform: uppercase;
            padding: 6px 0;
            margin-bottom: 14px;
        }

        /* Company / reference block */
        .head-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 12px;
        }
        .head-table td {
            padding: 3px 6px;
            vertical-align: top;
            font-size: 11px;
        }
        .head-label {
            width: 78px;
            font-weight: 700;
            color: #333;
        }
        .head-value {
            width: 42%;
            font-weight: 600;
            color: #111;
        }
        .ref-value {
            color: #d9480f;
            font-weight: 700;
        }

        /* Line items */
        .items-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 0;
        }
        .items-table th {
            background-color: #f7941d;
            color: #fff;
            border: 1px solid #d9822b;
            padding: 7px 6px;
            font-size: 10px;
            font-weight: 700;
            text-transform: uppercase;
            text-align: center;
        }
        .items-table td {
            border: 1px solid #ccc;
            padding: 8px 6px;
            font-size: 11px;
            text-align: center;
            vertical-align: middle;
        }
        .items-table td.num {
            text-align: right;
        }
        .items-table td.particulars {
            text-transform: uppercase;
            letter-spacing: 0.5px;
            font-weight: 600;
        }

        /* Totals block */
        .totals-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: -1px;
        }
        .totals-table td {
            border: 1px solid #ccc;
            padding: 5px 8px;
            font-size: 11px;
        }
        .totals-table td.label {
            width: 68%;
            font-weight: 700;
            text-transform: uppercase;
            font-size: 10px;
            letter-spacing: 0.5px;
        }
        .totals-table td.value {
            width: 32%;
            text-align: right;
            font-weight: 600;
        }
        .totals-table tr.net td {
            background-color: #fafafa;
            font-weight: 800;
        }

        /* Remarks and acceptance */
        .foot-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 12px;
        }
        .foot-table td {
            border: 1px solid #ccc;
            padding: 8px 10px;
            vertical-align: top;
            height: 70px;
        }
        .foot-label {
            font-weight: 700;
            text-transform: uppercase;
            font-size: 10px;
            letter-spacing: 0.5px;
            color: #333;
        }

        /* Signatures */
        .signatures-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 26px;
        }
        .signatures-table td {
            width: 50%;
            padding: 0 22px;
            text-align: center;
            vertical-align: bottom;
        }
        .sig-role {
            font-size: 11px;
            font-weight: 700;
            text-align: left;
            margin-bottom: 40px;
        }
        .sig-line {
            border-top: 1.2px solid #222;
            padding-top: 5px;
        }
        .sig-name {
            font-size: 11px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .sig-title {
            font-size: 9px;
            color: #555;
            margin-top: 1px;
        }

        .footer {
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            text-align: center;
            font-size: 9px;
            color: #888;
            border-top: 1px solid #eee;
            padding-top: 6px;
        }
    </style>
</head>
<body>

    {{-- Header: logo and company identity --}}
    <table class="header-table">
        <tr>
            <td class="logo-cell">
                @if(!empty($logoBase64))
                    <img src="{{ $logoBase64 }}" class="logo-img" alt="Doyen Petroleum">
                @else
                    <div style="font-size: 18px; font-weight: 900; color: #c92a2a; letter-spacing: 1px;">DOYEN PETROLEUM</div>
                @endif
            </td>
            <td class="company-cell">
                <p class="company-name">DOYEN INTERNATIONAL TRADING CORP.</p>
                <p class="company-line">2108 Lamesa St, Brgy. Ugong Distr. 2, Valenzuela City</p>
                <p class="company-line">Email Add: doyen.petroelum@yahoo.com</p>
            </td>
        </tr>
    </table>

    <div class="doc-banner">Purchase Order</div>

    {{-- Company and reference details --}}
    <table class="head-table">
        <tr>
            <td class="head-label">Company</td>
            <td class="head-value">{{ $supplierName }}</td>
            <td class="head-label">Reference No.</td>
            <td class="head-value ref-value">{{ $po->po_number ?: '—' }}</td>
        </tr>
        <tr>
            <td class="head-label">Address</td>
            <td class="head-value">{{ $supplierAddress ?: '—' }}</td>
            <td class="head-label">Date</td>
            <td class="head-value">{{ $poDate ?: '—' }}</td>
        </tr>
        <tr>
            <td class="head-label">Attention</td>
            <td class="head-value">{{ $attention ?: '—' }}</td>
            <td class="head-label">Terms</td>
            <td class="head-value">{{ $po->terms ?: '—' }}</td>
        </tr>
    </table>

    {{-- Line items --}}
    <table class="items-table">
        <thead>
            <tr>
                <th style="width: 16%;">Quantity</th>
                <th style="width: 10%;">Unit</th>
                <th style="width: 34%;">Particulars</th>
                <th style="width: 20%;">Unit Price</th>
                <th style="width: 20%;">Amount</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($lines as $line)
                <tr>
                    <td class="num">{{ number_format($line['qty'], 0) }}</td>
                    <td>{{ $line['unit'] ?: 'LTRS' }}</td>
                    <td class="particulars">{{ $line['particulars'] }}</td>
                    <td class="num">{{ number_format($line['price'], 2) }}</td>
                    <td class="num">{{ number_format($line['amount'], 2) }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="5">No product lines recorded.</td>
                </tr>
            @endforelse
        </tbody>
    </table>

    {{-- Totals. VAT rows are entered by hand, matching the printed form. --}}
    <table class="totals-table">
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

    {{-- Remarks and supplier acceptance --}}
    <table class="foot-table">
        <tr>
            <td style="width: 62%;">
                <div class="foot-label">Remarks:</div>
                <div style="margin-top: 6px; color: #c92a2a; font-style: italic; font-weight: 600;">
                    {{ $po->remarks ?: '' }}
                </div>
            </td>
            <td style="width: 38%;">
                <div class="foot-label">Order Accepted by:</div>
                <div style="margin-top: 6px; font-size: 10px; color: #555;">Signature over printed name/Date</div>
            </td>
        </tr>
    </table>

    {{-- Prepared and approved. Printed names only; no signature images. --}}
    <table class="signatures-table">
        <tr>
            <td>
                <div class="sig-role">Prepared by:</div>
                <div class="sig-line">
                    <div class="sig-name">{{ $preparedBy ?: '—' }}</div>
                    <div class="sig-title">Signature over printed name</div>
                </div>
            </td>
            <td>
                <div class="sig-role">Approved by:</div>
                <div class="sig-line">
                    <div class="sig-name">{{ $approvedBy ?: '—' }}</div>
                    <div class="sig-title">Signature over printed name</div>
                </div>
            </td>
        </tr>
    </table>

    <div class="footer">
        Purchase Order {{ $po->po_number }} &mdash; Doyen International Trading Corp.
    </div>
</body>
</html>
