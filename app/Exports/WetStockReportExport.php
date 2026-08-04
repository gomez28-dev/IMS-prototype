<?php

namespace App\Exports;

use Illuminate\Contracts\View\View;
use Maatwebsite\Excel\Concerns\FromView;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class WetStockReportExport implements FromView, ShouldAutoSize, WithStyles, WithTitle
{
    protected array $reportData;
    protected string $title;

    public function __construct(array $reportData, string $title = 'Wet Stock Report')
    {
        $this->reportData = $reportData;
        $this->title = $title;
    }

    public function view(): View
    {
        return view('wetstock.reports.excel', [
            'reportData' => $this->reportData,
            'title' => $this->title,
        ]);
    }

    public function title(): string
    {
        return 'Wet Stock Report';
    }

    public function styles(Worksheet $sheet)
    {
        return [
            1 => ['font' => ['bold' => true, 'size' => 14]],
            2 => ['font' => ['bold' => true]],
            18 => ['font' => ['bold' => true]],
        ];
    }
}
