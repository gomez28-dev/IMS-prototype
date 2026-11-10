<?php

namespace App\Exports;

use App\Models\Order;
use App\Models\PurchaseOrder;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithColumnWidths;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;

/**
 * The Stock Orders report as a spreadsheet.
 *
 * One row per purchase order, with the product breakdown as readable cells:
 * the same figures the report page shows, so a printed copy cannot disagree
 * with the screen it came from.
 */
class StockOrderReportExport implements FromArray, WithColumnWidths, WithEvents, WithHeadings
{
    protected Collection $orders;

    /**
     * Ordered / lifted / unlifted litres per PO, keyed by PO id.
     *
     * @var array<int, array<string, array<string, int>>>
     */
    protected array $lines;

    /**
     * The totals row, read off the same rows the sheet prints.
     *
     * @var array{count:int, value:float, ordered:array<string,int>, lifted:array<string,int>, unlifted:array<string,int>}
     */
    protected array $summary;

    public function __construct(Collection $orders, array $lines, array $summary)
    {
        $this->orders = $orders;
        $this->lines = $lines;
        $this->summary = $summary;
    }

    /**
     * "Diesel 10,000 | Premium 5,000" for one bucket of a PO's litres.
     *
     * @param  array<string, int>  $volumes
     */
    protected function breakdown(array $volumes): string
    {
        $parts = [];

        foreach ($volumes as $code => $qty) {
            if ($qty <= 0) {
                continue;
            }

            $parts[] = (Order::PRODUCT_TYPES[$code] ?? 'Unspecified') . ' ' . number_format($qty);
        }

        return implode(' | ', $parts);
    }

    public function array(): array
    {
        $rows = [];

        foreach ($this->orders as $order) {
            $lines = $this->lines[$order->id] ?? [
                'ordered' => [],
                'lifted' => [],
                'unlifted' => [],
            ];

            $date = $order->po_date
                ?? $order->request_date
                ?? $order->created_at;

            $rows[] = [
                'PO#' => $order->po_number,
                'PO DATE' => $date ? Carbon::parse($date)->format('M j, Y') : '',
                'SUPPLIER' => $order->supplier->name ?? $order->supplier_name ?? '',
                'STATUS' => $order->status,
                'AMOUNT' => (float) $order->total_amount,
                'QTY ORDERED' => array_sum($lines['ordered']),
                'PRODUCTS ORDERED' => $this->breakdown($lines['ordered']),
                'QTY LIFTED' => array_sum($lines['lifted']),
                'PRODUCTS LIFTED' => $this->breakdown($lines['lifted']),
                'QTY UNLIFTED' => array_sum($lines['unlifted']),
                'PRODUCTS UNLIFTED' => $this->breakdown($lines['unlifted']),
            ];
        }

        // A totals row, so a reader who prints the sheet does not have to add
        // the column up by hand and get a different answer from the page.
        $rows[] = [
            'PO#' => 'TOTAL (' . $this->summary['count'] . ' PO' . ($this->summary['count'] === 1 ? '' : 's') . ')',
            'PO DATE' => '',
            'SUPPLIER' => '',
            'STATUS' => '',
            'AMOUNT' => (float) $this->summary['value'],
            'QTY ORDERED' => array_sum($this->summary['ordered']),
            'PRODUCTS ORDERED' => $this->breakdown($this->summary['ordered']),
            'QTY LIFTED' => array_sum($this->summary['lifted']),
            'PRODUCTS LIFTED' => $this->breakdown($this->summary['lifted']),
            'QTY UNLIFTED' => array_sum($this->summary['unlifted']),
            'PRODUCTS UNLIFTED' => $this->breakdown($this->summary['unlifted']),
        ];

        return $rows;
    }

    public function headings(): array
    {
        return [
            'PO#',
            'PO DATE',
            'SUPPLIER',
            'STATUS',
            'AMOUNT',
            'QTY ORDERED',
            'PRODUCTS ORDERED',
            'QTY LIFTED',
            'PRODUCTS LIFTED',
            'QTY UNLIFTED',
            'PRODUCTS UNLIFTED',
        ];
    }

    public function columnWidths(): array
    {
        return [
            'A' => 18,
            'B' => 14,
            'C' => 24,
            'D' => 14,
            'E' => 16,
            'F' => 14,
            'G' => 34,
            'H' => 14,
            'I' => 34,
            'J' => 14,
            'K' => 34,
        ];
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event) {
                $sheet = $event->sheet->getDelegate();
                $lastRow = count($this->orders) + 2;

                $sheet->getStyle('A1:K1')->getFont()->setBold(true);
                $sheet->getStyle('A1:K1')->getFill()
                    ->setFillType(Fill::FILL_SOLID)
                    ->getStartColor()->setRGB('F1F5F9');

                $sheet->getStyle('E2:E' . $lastRow)->getNumberFormat()->setFormatCode('#,##0.00');
                $sheet->getStyle('A2:K' . $lastRow)->getAlignment()->setVertical(Alignment::VERTICAL_TOP);

                // The totals row is the last one and reads as a footer.
                $sheet->getStyle('A' . $lastRow . ':K' . $lastRow)->getFont()->setBold(true);
                $sheet->getStyle('A' . $lastRow . ':K' . $lastRow)->getFill()
                    ->setFillType(Fill::FILL_SOLID)
                    ->getStartColor()->setRGB('F8FAFC');
            },
        ];
    }
}
