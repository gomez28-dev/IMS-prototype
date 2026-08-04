@php
    $warehousesRaw = $reportData['warehouses'];
    $warehouses = $warehousesRaw instanceof \Illuminate\Support\Collection
        ? array_values($warehousesRaw->all())
        : array_values($warehousesRaw);
    $pairs = array_chunk($warehouses, 2);
    $fmt = fn($v) => number_format((int) $v);

    // Template-faithful styles (WET STOCK REPORT.xlsx)
    $o = 'background-color:#FF9900;color:#FFFFFF;font-weight:bold;font-family:Verdana;border:1px solid #000000;';
    $p = 'background-color:#FCE5CD;font-weight:bold;font-family:Verdana;border:1px solid #000000;';
    $g = 'background-color:#CCCCCC;font-weight:bold;font-family:Verdana;border:1px solid #000000;';
    $l = 'font-family:Verdana;font-weight:bold;border:1px solid #000000;';
    $n = 'font-family:Verdana;border:1px solid #000000;';
    $v = 'font-family:Verdana;text-align:right;border:1px solid #000000;';

    $norm = function ($items) {
        if ($items instanceof \Illuminate\Support\Collection) {
            return array_values($items->all());
        }
        return array_values($items ?? []);
    };
    $bval = fn($b, $key) => $b ? $fmt($b[$key] ?? 0) : '0';
@endphp

@foreach ($pairs as $pair)
    @php
        $w1 = $pair[0] ?? null;
        $w2 = $pair[1] ?? null;
        $b1 = $w1 ? ($reportData['blocks'][$w1['id']] ?? null) : null;
        $b2 = $w2 ? ($reportData['blocks'][$w2['id']] ?? null) : null;
    @endphp

    <!-- ============ TOP SUMMARY ============ -->
    <table>
        <tr>
            <td width="1.75"></td>
            <td colspan="3" width="37.63" style="{{ $o }};font-size:14pt;">WET STOCKS</td>
            <td colspan="3" width="37.88" style="{{ $o }}">LITERS</td>
            <td width="1.25"></td>
            <td colspan="3" width="37.63" style="{{ $o }};font-size:14pt;">WET STOCKS</td>
            <td colspan="3" width="37.88" style="{{ $o }}">LITERS</td>
        </tr>
        @foreach ([['DEPOT', 'depot_total'], ['TANKERS', 'tankers_total'], ['CONTAMINATED', 'contaminated_total'], ['UNLIFTED STOCK PICK UP', 'unlifted_supplier_total'], ['PENDING STOCK DELIVERY', 'pending_supplier_total']] as [$label, $key])
            <tr>
                <td></td>
                <td colspan="3" style="{{ $n }}">{{ $label }}</td>
                <td colspan="3" style="{{ $v }}">{{ $bval($b1, $key) }}</td>
                <td></td>
                <td colspan="3" style="{{ $n }}">{{ $label }}</td>
                <td colspan="3" style="{{ $v }}">{{ $bval($b2, $key) }}</td>
            </tr>
        @endforeach
        <tr>
            <td></td>
            <td colspan="3" style="{{ $l }}">TOTAL</td>
            <td colspan="3" style="{{ $g }}">{{ $bval($b1, 'total_commitments_in') }}</td>
            <td></td>
            <td colspan="3" style="{{ $l }}">TOTAL</td>
            <td colspan="3" style="{{ $g }}">{{ $bval($b2, 'total_commitments_in') }}</td>
        </tr>
        <tr>
            <td></td>
            <td colspan="3" style="{{ $l }}">HOLD FOR CLEARING<p>FROM SALES DOCS SUM - ACCOUNTING CLEARANCE</p></td>
            <td colspan="3" style="{{ $v }}">{{ $bval($b1, 'pending_clearance_orders_total') }}</td>
            <td></td>
            <td colspan="3" style="{{ $l }}">HOLD FOR CLEARING<p>FROM SALES DOCS SUM - ACCOUNTING CLEARANCE</p></td>
            <td colspan="3" style="{{ $v }}">{{ $bval($b2, 'pending_clearance_orders_total') }}</td>
        </tr>
        <tr>
            <td></td>
            <td colspan="3" style="{{ $n }}">CLIENT UNLIFTED PICK UP</td>
            <td colspan="3" style="{{ $v }}">{{ $bval($b1, 'client_pickup_total') }}</td>
            <td></td>
            <td colspan="3" style="{{ $n }}">CLIENT UNLIFTED PICK UP</td>
            <td colspan="3" style="{{ $v }}">{{ $bval($b2, 'client_pickup_total') }}</td>
        </tr>
        <tr>
            <td></td>
            <td colspan="3" style="{{ $n }}">CLIENT PENDING DELIVERY</td>
            <td colspan="3" style="{{ $v }}">{{ $bval($b1, 'client_pending_delivery_total') }}</td>
            <td></td>
            <td colspan="3" style="{{ $n }}">CLIENT PENDING DELIVERY</td>
            <td colspan="3" style="{{ $v }}">{{ $bval($b2, 'client_pending_delivery_total') }}</td>
        </tr>
        <tr>
            <td></td>
            <td colspan="3" style="{{ $l }}">TOTAL</td>
            <td colspan="3" style="{{ $g }}">{{ $bval($b1, 'total_hold_for_clearing') }}</td>
            <td></td>
            <td colspan="3" style="{{ $l }}">TOTAL</td>
            <td colspan="3" style="{{ $g }}">{{ $bval($b2, 'total_hold_for_clearing') }}</td>
        </tr>
        <tr>
            <td></td>
            <td colspan="3" style="{{ $l }}">TOTAL AVAILABLE FOR SALE</td>
            <td colspan="3" style="{{ $g }}">{{ $bval($b1, 'total_available_for_sale') }}</td>
            <td></td>
            <td colspan="3" style="{{ $l }}">TOTAL AVAILABLE FOR SALE</td>
            <td colspan="3" style="{{ $g }}">{{ $bval($b2, 'total_available_for_sale') }}</td>
        </tr>
        <tr>
            <td></td>
            <td colspan="3" style="{{ $l }}">TOTAL AVAILABLE ON HAND FOR SELLING</td>
            <td colspan="3" style="{{ $g }}">{{ $bval($b1, 'total_available_on_hand_for_selling') }}</td>
            <td></td>
            <td colspan="3" style="{{ $l }}">TOTAL AVAILABLE ON HAND FOR SELLING</td>
            <td colspan="3" style="{{ $g }}">{{ $bval($b2, 'total_available_on_hand_for_selling') }}</td>
        </tr>
    </table>

    <tr></tr>

    <!-- ============ BREAKDOWN ============ -->
    <table>
        <tr>
            <td></td>
            <td colspan="6" style="{{ $o }}">{{ $w1 ? strtoupper($w1['name']) . ' REPORT BREAKDOWN' : '' }}</td>
            <td></td>
            <td colspan="6" style="{{ $o }}">{{ $w2 ? strtoupper($w2['name']) . ' REPORT BREAKDOWN' : '' }}</td>
        </tr>
        <tr>
            <td></td>
            <td style="{{ $p }}">WAREHOUSE</td>
            <td style="{{ $p }}">LITERS</td>
            <td style="{{ $p }}">REMARKS</td>
            <td style="{{ $p }}">TANKERS</td>
            <td style="{{ $p }}">LITERS</td>
            <td style="{{ $p }}">REMARKS</td>
            <td></td>
            <td style="{{ $p }}">WAREHOUSE</td>
            <td style="{{ $p }}">LITERS</td>
            <td style="{{ $p }}">REMARKS</td>
            <td style="{{ $p }}">TANKERS</td>
            <td style="{{ $p }}">LITERS</td>
            <td style="{{ $p }}">REMARKS</td>
        </tr>

        @php
            $depotTanks1 = $norm($b1['depot_tanks'] ?? []);
            $tankerTanks1 = $norm($b1['tanker_tanks'] ?? []);
            $depotTanks2 = $norm($b2['depot_tanks'] ?? []);
            $tankerTanks2 = $norm($b2['tanker_tanks'] ?? []);
            $maxTanks = max(count($depotTanks1), count($tankerTanks1), count($depotTanks2), count($tankerTanks2));
            if ($maxTanks == 0) $maxTanks = 1;
        @endphp
        @for ($i = 0; $i < $maxTanks; $i++)
            @php
                $dt1 = $depotTanks1[$i] ?? null;
                $tt1 = $tankerTanks1[$i] ?? null;
                $dt2 = $depotTanks2[$i] ?? null;
                $tt2 = $tankerTanks2[$i] ?? null;
                $remark1 = fn($t) => $t && $t['is_contaminated'] ? 'CONTAMINATED' : ($t['remarks'] ?? '');
                $remark2 = fn($t) => $t && $t['is_contaminated'] ? 'CONTAMINATED' : ($t['remarks'] ?? '');
            @endphp
            <tr>
                <td></td>
                <td style="{{ $n }}">{{ $dt1['name'] ?? '' }}</td>
                <td style="{{ $v }}">{{ $dt1 ? $fmt($dt1['stock_available']) : '' }}</td>
                <td style="{{ $n }}">{{ $remark1($dt1) }}</td>
                <td style="{{ $n }}">{{ $tt1['name'] ?? '' }}</td>
                <td style="{{ $v }}">{{ $tt1 ? $fmt($tt1['stock_available']) : '' }}</td>
                <td style="{{ $n }}">{{ $remark1($tt1) }}</td>
                <td></td>
                <td style="{{ $n }}">{{ $dt2['name'] ?? '' }}</td>
                <td style="{{ $v }}">{{ $dt2 ? $fmt($dt2['stock_available']) : '' }}</td>
                <td style="{{ $n }}">{{ $remark2($dt2) }}</td>
                <td style="{{ $n }}">{{ $tt2['name'] ?? '' }}</td>
                <td style="{{ $v }}">{{ $tt2 ? $fmt($tt2['stock_available']) : '' }}</td>
                <td style="{{ $n }}">{{ $remark2($tt2) }}</td>
            </tr>
        @endfor
        <tr>
            <td></td>
            <td style="{{ $l }}">TOTAL</td>
            <td style="{{ $g }}">{{ $bval($b1, 'depot_total') }}</td>
            <td></td>
            <td style="{{ $l }}">TOTAL</td>
            <td style="{{ $g }}">{{ $bval($b1, 'tankers_total') }}</td>
            <td></td>
            <td></td>
            <td style="{{ $l }}">TOTAL</td>
            <td style="{{ $g }}">{{ $bval($b2, 'depot_total') }}</td>
            <td></td>
            <td style="{{ $l }}">TOTAL</td>
            <td style="{{ $g }}">{{ $bval($b2, 'tankers_total') }}</td>
            <td></td>
        </tr>
    </table>

    <tr></tr>

    <!-- ============ DETAIL TABLES ============ -->
    @php
        $sections = [
            ['key' => 'unlifted_supplier_orders', 'title' => 'UNLIFTED STOCK PICK UP', 'kind' => 'supplier'],
            ['key' => 'pending_supplier_orders', 'title' => 'PENDING STOCK DELIVERY', 'kind' => 'supplier'],
            ['key' => 'big_tanker_deliveries', 'title' => 'BIG TANKER UNDELIVERED', 'kind' => 'so'],
            ['key' => 'small_tanker_deliveries', 'title' => 'SMALL TANKER UNDELIVERED', 'kind' => 'so'],
            ['key' => 'client_pickup_deliveries', 'title' => 'CLIENT PICK UP', 'kind' => 'so'],
        ];
    @endphp
    @foreach ($sections as $section)
        @php
            $items1 = $norm($b1[$section['key']] ?? []);
            $items2 = $norm($b2[$section['key']] ?? []);
            $isSupplier = $section['kind'] === 'supplier';
            $maxRows = max(count($items1), count($items2));
            $total1 = 0; $total2 = 0;
            foreach ($items1 as $it) { $total1 += (int) ($isSupplier ? $it['liters'] : $it['qty_out']); }
            foreach ($items2 as $it) { $total2 += (int) ($isSupplier ? $it['liters'] : $it['qty_out']); }
            $whName1 = $w1 ? strtoupper($w1['name']) : '';
            $whName2 = $w2 ? strtoupper($w2['name']) : '';
        @endphp
        <table>
            <tr>
                <td></td>
                <td colspan="6" style="{{ $o }}">{{ $section['title'] }}</td>
                <td></td>
                <td colspan="6" style="{{ $o }}">{{ $section['title'] }}</td>
            </tr>
            <tr>
                <td></td>
                <td style="{{ $p }}">{{ $isSupplier ? 'PO#' : 'SO#' }}</td>
                <td colspan="2" style="{{ $p }}">{{ $isSupplier ? 'LOCATION' : 'CLIENT' }}</td>
                <td style="{{ $p }}">{{ $isSupplier ? 'SUPPLIER' : '' }}</td>
                <td colspan="2" style="{{ $p }}">LITERS</td>
                <td></td>
                <td style="{{ $p }}">{{ $isSupplier ? 'PO#' : 'SO#' }}</td>
                <td colspan="2" style="{{ $p }}">{{ $isSupplier ? 'LOCATION' : 'CLIENT' }}</td>
                <td style="{{ $p }}">{{ $isSupplier ? 'SUPPLIER' : '' }}</td>
                <td colspan="2" style="{{ $p }}">LITERS</td>
            </tr>
            @for ($i = 0; $i < max($maxRows, 1); $i++)
                @php
                    $it1 = $items1[$i] ?? null;
                    $it2 = $items2[$i] ?? null;
                    $no1 = $it1 ? ($it1['po_number'] ?? $it1['order']['so_number'] ?? 'N/A') : '';
                    $client1 = $it1 ? ($isSupplier ? $whName1 : ($it1['order']['account'] ?? 'N/A')) : '';
                    $supplier1 = $it1 && $isSupplier ? $it1['supplier_name'] : '';
                    $liters1 = $it1 ? $fmt($isSupplier ? $it1['liters'] : $it1['qty_out']) : '';
                    $no2 = $it2 ? ($it2['po_number'] ?? $it2['order']['so_number'] ?? 'N/A') : '';
                    $client2 = $it2 ? ($isSupplier ? $whName2 : ($it2['order']['account'] ?? 'N/A')) : '';
                    $supplier2 = $it2 && $isSupplier ? $it2['supplier_name'] : '';
                    $liters2 = $it2 ? $fmt($isSupplier ? $it2['liters'] : $it2['qty_out']) : '';
                @endphp
                <tr>
                    <td></td>
                    <td style="{{ $n }}">{{ $no1 }}</td>
                    <td colspan="2" style="{{ $n }}">{{ $client1 }}</td>
                    <td style="{{ $n }}">{{ $supplier1 }}</td>
                    <td colspan="2" style="{{ $v }}">{{ $liters1 }}</td>
                    <td></td>
                    <td style="{{ $n }}">{{ $no2 }}</td>
                    <td colspan="2" style="{{ $n }}">{{ $client2 }}</td>
                    <td style="{{ $n }}">{{ $supplier2 }}</td>
                    <td colspan="2" style="{{ $v }}">{{ $liters2 }}</td>
                </tr>
            @endfor
            <tr>
                <td></td>
                <td colspan="4" style="{{ $l }}">TOTAL</td>
                <td colspan="2" style="{{ $g }}">{{ $fmt($total1) }}</td>
                <td></td>
                <td colspan="4" style="{{ $l }}">TOTAL</td>
                <td colspan="2" style="{{ $g }}">{{ $fmt($total2) }}</td>
            </tr>
        </table>
    @endforeach
@endforeach
