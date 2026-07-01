<?php

namespace App\Exports;

use App\Models\Order;
use Carbon\Carbon;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithColumnWidths;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;

class InventoryExport implements FromArray, WithHeadings, WithColumnWidths, WithEvents
{
    public function array(): array
    {
        $orders = Order::orderBy('date', 'desc')->get();
        $rows = [];

        foreach ($orders as $order) {
            $deliveries = $order->deliveries()->orderBy('delivery_date', 'asc')->get();
            $runningBalance = $order->qty_ordered;

            if ($deliveries->isEmpty()) {
                $rows[] = [
                    'ACCOUNT' => $order->account,
                    'DATE' => $order->date
                        ? Carbon::parse($order->date)->format('M j, Y')
                        : '',
                    'QTY ORDERED' => (int) $order->qty_ordered,
                    'SO#' => $order->so_number,
                    'DR#' => '',
                    'DELIVERY DATE' => '',
                    'QTY OUT' => '',
                    'DELIVERY BALANCE' => (int) $runningBalance,
                    'DELIVERY STATUS' => '',
                    'TYPE' => '',
                    'REMARKS' => '',
                ];
            } else {
                foreach ($deliveries as $idx => $delivery) {
                    if ($delivery->status !== 'CANCELLED') {
                        $runningBalance -= $delivery->qty_out;
                    }

                    $statusMapped = $delivery->status === 'FULFILLED' ? 'DONE' : $delivery->status;

                    $row = [
                        'ACCOUNT' => $idx === 0 ? $order->account : '',
                        'DATE' => $idx === 0 && $order->date
                            ? Carbon::parse($order->date)->format('M j, Y')
                            : '',
                        'QTY ORDERED' => $idx === 0 ? (int) $order->qty_ordered : '',
                        'SO#' => $idx === 0 ? $order->so_number : '',
                        'DR#' => $delivery->dr_number,
                        'DELIVERY DATE' => $delivery->delivery_date
                            ? Carbon::parse($delivery->delivery_date)->format('M j, Y')
                            : '',
                        'QTY OUT' => (int) $delivery->qty_out,
                        'DELIVERY BALANCE' => (int) $runningBalance,
                        'DELIVERY STATUS' => $statusMapped,
                        'TYPE' => $delivery->type,
                        'REMARKS' => $delivery->remarks ?? '',
                    ];
                    $rows[] = $row;
                }
            }
        }

        return $rows;
    }

    public function headings(): array
    {
        return [
            'ACCOUNT',
            'DATE',
            'QTY ORDERED',
            'SO#',
            'DR#',
            'DELIVERY DATE',
            'QTY OUT',
            'DELIVERY BALANCE',
            'DELIVERY STATUS',
            'TYPE',
            'REMARKS',
        ];
    }

    public function columnWidths(): array
    {
        return [
            'A' => 38,
            'B' => 15,
            'C' => 15,
            'D' => 16,
            'E' => 16,
            'F' => 17,
            'G' => 13,
            'H' => 21,
            'I' => 19,
            'J' => 13,
            'K' => 32,
        ];
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event) {
                $sheet = $event->sheet->getDelegate();
                $lastRow = $sheet->getHighestRow();

                $this->styleHeader($sheet);
                $this->styleDataRows($sheet, $lastRow);
                $this->applyNumberFormats($sheet, $lastRow);
                $this->applyMergeRanges($sheet, $lastRow);
                $this->fillZeroBalances($sheet, $lastRow);
                $sheet->freezePane('A2');
            },
        ];
    }

    protected function styleHeader($sheet): void
    {
        $sheet->getStyle('A1:K1')->applyFromArray([
            'font' => [
                'bold' => true,
                'color' => ['rgb' => 'FFFFFF'],
                'name' => 'Verdana',
                'size' => 10,
            ],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['rgb' => 'FF9900'],
            ],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_CENTER,
                'vertical' => Alignment::VERTICAL_CENTER,
            ],
        ]);
        $sheet->getRowDimension('1')->setRowHeight(55);
    }

    protected function styleDataRows($sheet, int $lastRow): void
    {
        if ($lastRow < 2) {
            return;
        }

        $sheet->getStyle("A2:K{$lastRow}")->applyFromArray([
            'font' => [
                'name' => 'Verdana',
                'size' => 10,
            ],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_CENTER,
                'vertical' => Alignment::VERTICAL_CENTER,
            ],
        ]);

        for ($i = 2; $i <= $lastRow; $i++) {
            $sheet->getRowDimension($i)->setRowHeight(19.5);
        }
    }

    protected function applyNumberFormats($sheet, int $lastRow): void
    {
        if ($lastRow < 2) {
            return;
        }

        $sheet->getStyle("C2:C{$lastRow}")
            ->getNumberFormat()
            ->setFormatCode('#,##0');

        $sheet->getStyle("G2:G{$lastRow}")
            ->getNumberFormat()
            ->setFormatCode('#,##0');

        $sheet->getStyle("H2:H{$lastRow}")
            ->getNumberFormat()
            ->setFormatCode('#,##0');
    }

    protected function applyMergeRanges($sheet, int $lastRow): void
    {
        $startRow = 2;

        while ($startRow <= $lastRow) {
            $account = $sheet->getCell("A{$startRow}")->getValue();

            if (!empty($account)) {
                $endRow = $startRow;

                for ($r = $startRow + 1; $r <= $lastRow; $r++) {
                    $nextAccount = $sheet->getCell("A{$r}")->getValue();
                    if (!empty($nextAccount)) {
                        break;
                    }
                    $endRow = $r;
                }

                if ($endRow > $startRow) {
                    foreach (['A', 'B', 'C', 'D'] as $col) {
                        $sheet->mergeCells("{$col}{$startRow}:{$col}{$endRow}");
                    }
                }

                $startRow = $endRow + 1;
            } else {
                $startRow++;
            }
        }
    }

    protected function fillZeroBalances($sheet, int $lastRow): void
    {
        for ($r = 2; $r <= $lastRow; $r++) {
            $cell = $sheet->getCell("H{$r}");
            if ($cell->getValue() === null) {
                $cell->setValueExplicit(0, 'n');
            }
        }
    }
}
