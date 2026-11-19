<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Delivery Receipt {{ $delivery->dr_number }}</title>
    <style>
        @page { margin: 18px 26px 14px 26px; }
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 8px; color: #111111; }
        table { width: 100%; border-collapse: collapse; }
        .head td { vertical-align: top; }
        .logo-cell { width: 56%; text-align: center; }
        .logo-cell img { width: 118px; }
        .company { font-size: 6.8px; line-height: 1.5; color: #444444; margin-top: 3px; }
        .title { text-align: right; font-size: 13px; font-weight: bold; letter-spacing: 1px; margin-bottom: 4px; }
        .drbox { width: 100%; border: 1px solid #111111; }
        .drbox th { background: #111111; color: #ffffff; font-size: 7px; padding: 2px 4px; border: 1px solid #ffffff; text-align: center; }
        .drbox td { text-align: center; font-size: 10px; font-weight: bold; padding: 4px; border: 1px solid #111111; }
        .drno { color: #c0392b; }
        .form { margin-top: 6px; table-layout: fixed; }
        .form td { border: 1px solid #111111; padding: 2px 4px; vertical-align: top; }
        .form .sec { background: #f0f0f0; font-size: 7.5px; font-weight: bold; text-transform: uppercase; }
        .form .lbl { background: #f0f0f0; font-size: 6.5px; font-weight: bold; text-transform: uppercase; text-align: center; padding: 2px 3px; }
        .form .val { font-size: 8.5px; font-weight: bold; text-align: center; height: 13px; }
        .small { font-size: 6px; font-weight: bold; text-transform: uppercase; color: #444444; }
        .big { font-size: 9px; font-weight: bold; }
        .addr { font-size: 8.5px; font-weight: bold; }
        .irow td { height: 15px; font-size: 8.5px; }
        .center { text-align: center; }
        .other { font-size: 8px; font-weight: bold; }
        .sig td { height: 46px; }
        .siglbl { font-size: 7px; font-weight: bold; text-transform: uppercase; }
        .sigcap { margin: 26px 6px 0 6px; border-top: 1px solid #111111; padding-top: 1px; text-align: center; font-size: 6px; font-weight: bold; }
        .notice { text-align: right; font-size: 8px; font-weight: bold; margin-top: 4px; letter-spacing: 0.5px; }
    </style>
</head>
<body>
    @php
        $minRows = 10;
        $totalRows = max($minRows, count($rows));
        $dateText = $delivery->delivery_date ? $delivery->delivery_date->format('m/d/Y') : '';
    @endphp

    <table class="head">
        <tr>
            <td class="logo-cell">
                @if ($logo)
                    <img src="{{ $logo }}" alt="Doyen International Trading Corporation">
                @else
                    <strong>DOYEN INTERNATIONAL TRADING CORPORATION</strong>
                @endif
                <div class="company">
                    Blk 6 Lot 20 Global Aseana Business Park 2, San Pablo Libutad, 2015 San Simon, Pampanga, Philippines<br>
                    welcome@doyengroupofcompanies.com | (02) 7002 1759 | (+63) 919 072 5153<br>
                    TIN: 010-509-392-00000
                </div>
            </td>
            <td>
                <div class="title">DELIVERY RECEIPT</div>
                <table class="drbox">
                    <tr>
                        <th style="width: 55%;">D.R. No.</th>
                        <th>DATE</th>
                    </tr>
                    <tr>
                        <td class="drno">No. {{ $delivery->dr_number }}</td>
                        <td>{{ $dateText }}</td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>

    <table class="form">
        <colgroup>
            <col style="width: 21%;">
            <col style="width: 17%;">
            <col style="width: 18%;">
            <col style="width: 20%;">
            <col style="width: 24%;">
        </colgroup>

        <tr>
            <td colspan="3" class="sec">Customer</td>
            <td colspan="2" class="sec">Delivery Address</td>
        </tr>
        <tr>
            <td colspan="3" style="height: 30px;">
                <div class="small">Registered Name:</div>
                <div class="big">{{ $order?->account }}</div>
                <div class="small" style="margin-top: 5px;">TIN:</div>
            </td>
            <td colspan="2">
                <div class="addr">{!! nl2br(e($delivery->delivery_address)) !!}</div>
            </td>
        </tr>

        <tr>
            <td class="lbl">P.O. No.</td>
            <td class="lbl">Terms of Payment</td>
            <td class="lbl">Sales Representative</td>
            <td class="lbl">Delivery Date</td>
            <td class="lbl">SOA No.</td>
        </tr>
        <tr>
            <td class="val">{{ $order?->po_number }}</td>
            <td class="val">{{ $order?->terms }}</td>
            <td class="val">{{ $order?->sales_rep }}</td>
            <td class="val">{{ $dateText }}</td>
            <td class="val"></td>
        </tr>

        <tr>
            <td class="lbl">Item</td>
            <td class="lbl">Quantity</td>
            <td class="lbl">Unit</td>
            <td class="lbl">Container / Seal No.</td>
            <td class="lbl">Other Details</td>
        </tr>
        @for ($i = 0; $i < $totalRows; $i++)
            <tr class="irow">
                <td>{{ isset($rows[$i]) ? mb_strtoupper($rows[$i]['product']) : '' }}</td>
                <td class="center">{{ isset($rows[$i]) ? number_format($rows[$i]['qty']) : '' }}</td>
                <td class="center">{{ isset($rows[$i]) ? 'LITERS' : '' }}</td>
                <td></td>
                @if ($i === 0)
                    <td rowspan="{{ $totalRows }}" class="other">
                        {!! nl2br(e($delivery->remarks)) !!}
                    </td>
                @endif
            </tr>
        @endfor

        <tr class="sig">
            <td>
                <div class="siglbl">Prepared by:</div>
                <div class="sigcap">Signature Over Printed Name</div>
            </td>
            <td>
                <div class="siglbl">Checked by:</div>
                <div class="sigcap">Signature Over Printed Name</div>
            </td>
            <td>
                <div class="siglbl">Approved by:</div>
                <div class="sigcap">Signature Over Printed Name</div>
            </td>
            <td colspan="2">
                <div class="siglbl">Received the above goods in good condition:</div>
                <table style="margin-top: 0;">
                    <tr>
                        <td style="width: 68%; border: none; padding: 0;"><div class="sigcap">Signature Over Printed Name</div></td>
                        <td style="border: none; padding: 0;"><div class="sigcap">Date</div></td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>

    <div class="notice">THIS DOCUMENT IS NOT VALID IN CLAIMING INPUT TAXES</div>
</body>
</html>