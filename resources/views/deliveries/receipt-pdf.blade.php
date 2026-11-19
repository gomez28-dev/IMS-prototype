<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Sales Order Receipt {{ $order->formatted_so_number }}</title>
    <style>
        @page { margin: 26px 40px 52px 40px; }
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 10px; color: #111111; }
        .logo { text-align: center; margin-bottom: 4px; }
        .logo img { width: 130px; }
        .title { text-align: center; font-size: 15px; font-weight: bold; letter-spacing: 2px; margin: 6px 0 2px 0; }
        .rule { border-top: 2px solid #111111; margin: 6px 0 8px 0; }
        table { width: 100%; border-collapse: collapse; }
        .info td { padding: 3px 0; vertical-align: top; }
        .info .label { width: 16%; color: #666666; font-size: 9px; text-transform: uppercase; letter-spacing: 1px; }
        .info .value { width: 34%; font-weight: bold; font-size: 11px; }
        .items { margin-top: 10px; }
        .items th { background: #111111; color: #ffffff; font-size: 9px; text-transform: uppercase; letter-spacing: 1px; padding: 6px 8px; text-align: left; }
        .items td { padding: 5px 8px; border-bottom: 1px solid #dddddd; }
        .items .num { text-align: right; }
        .items tfoot td { border-bottom: none; border-top: none; font-weight: bold; padding: 3px 8px; }
        .items tfoot tr.first td { border-top: 2px solid #111111; padding-top: 7px; }
        .items tfoot tr.due td { border-top: 1px solid #111111; font-size: 11px; padding-top: 5px; }
        .company { text-align: center; font-size: 8.5px; line-height: 1.6; color: #444444; margin-top: 4px; }
        .page-footer { position: fixed; left: 0; right: 0; bottom: -34px; border-top: 2px solid #111111; padding-top: 7px; text-align: center; font-size: 9px; }
        .page-footer a { color: #666666; text-decoration: none; }
    </style>
</head>
<body>
    <div class="page-footer"><a href="https://www.doyengroupofcompanies.com">www.doyengroupofcompanies.com</a></div>

    <div class="logo">
        @if ($logo)
            <img src="{{ $logo }}" alt="Doyen International Trading Corporation">
        @else
            <strong>DOYEN INTERNATIONAL TRADING CORPORATION</strong>
        @endif
    </div>

    <div class="company">
        Blk 6 Lot 20 Global Aseana Business Park 2, San Pablo Libutad, 2015 San Simon, Pampanga, Philippines<br>
        welcome@doyengroupofcompanies.com | (02) 7002 1759 | (+63) 919 072 5153<br>
        TIN: 010-509-392-00000
    </div>

    <div class="rule"></div>
    <div class="title">SALES ORDER RECEIPT</div>

    <table class="info">
        <tr>
            <td class="label">Client</td>
            <td class="value">{{ $order->account ?: '—' }}</td>
            <td class="label">SO #</td>
            <td class="value">{{ $order->formatted_so_number }}</td>
        </tr>
        <tr>
            <td class="label">Order Date</td>
            <td class="value">{{ $order->date ? $order->date->format('F j, Y') : '—' }}</td>
            <td class="label">PO #</td>
            <td class="value">{{ $order->po_number ?: '—' }}</td>
        </tr>
        <tr>
            <td class="label">Terms</td>
            <td class="value">{{ $order->terms ?: '—' }}</td>
            <td class="label">VAT</td>
            <td class="value">{{ $vatLabel }}</td>
        </tr>
    </table>

    <table class="items">
        <thead>
            <tr>
                <th>Product</th>
                <th class="num">Qty Ordered (L)</th>
                <th class="num">Unit Price</th>
                <th class="num">Amount</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($rows as $row)
                <tr>
                    <td>{{ $row['product'] }}</td>
                    <td class="num">{{ number_format($row['qty']) }}</td>
                    <td class="num">₱ {{ number_format($row['price'], 2) }}</td>
                    <td class="num">₱ {{ number_format($row['amount'], 2) }}</td>
                </tr>
            @endforeach
        </tbody>
        <tfoot>
            <tr class="first">
                <td>Total</td>
                <td class="num">{{ number_format($totalQty) }}</td>
                <td></td>
                <td class="num">₱ {{ number_format($subtotal, 2) }}</td>
            </tr>
            @if ($vatApplied)
                <tr>
                    <td colspan="3" class="num">Add: VAT (12%)</td>
                    <td class="num">₱ {{ number_format($vatAmount, 2) }}</td>
                </tr>
            @endif
            <tr class="due">
                <td colspan="3" class="num">TOTAL AMOUNT DUE</td>
                <td class="num">₱ {{ number_format($totalDue, 2) }}</td>
            </tr>
        </tfoot>
    </table>
</body>
</html>