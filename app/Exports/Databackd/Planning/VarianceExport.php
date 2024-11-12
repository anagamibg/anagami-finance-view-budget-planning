<?php

namespace App\Exports\Databackd\Planning;

use App\Exports\Databackd\Concerns\NeutralizesFormulas;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Excel export of the budget vs. actual table (see BudgetVarianceController::buildReport(), key "table").
 */
class VarianceExport implements FromArray, WithHeadings, WithColumnFormatting, WithStyles, WithTitle, ShouldAutoSize
{

    use NeutralizesFormulas;

    /** Rows above the data: caption (1) + column headings (2). */
    const HEADER_ROWS = 2;

    /**
     * @param array $table display rows: kind (section|row|subtotal|profit), code, label, budget, actual, diff, diff_pct (percent), favorable
     */
    public function __construct(protected array $table, protected string $caption)
    {
    }

    public function title(): string
    {
        return mb_substr(__('databackd-planning-variance.export_title'), 0, 31);
    }

    public function headings(): array
    {
        $c = fn(string $key) => __('databackd-planning-variance.columns.' . $key);

        // S6/P4: $this->caption (budget / period name, user-controlled) is neutralized against CSV/Excel
        // formula injection.
        return [
            [self::neutralizeFormula($this->caption)],
            ['', $c('account'), $c('budget'), $c('actual'), $c('diff'), $c('diff_pct')],
        ];
    }

    public function array(): array
    {
        $data = [];

        // S6/P4: $row['code'] / $row['label'] are the account code/name (fin_accounts, user-controlled) -
        // neutralized against CSV/Excel formula injection.
        foreach ($this->table as $row) {
            if ($row['kind'] === 'section') {
                $data[] = ['', self::neutralizeFormula($row['label']), null, null, null, null];

                continue;
            }

            $data[] = [
                self::neutralizeFormula($row['code'] ?? ''),
                self::neutralizeFormula($row['label']),
                (float)$row['budget'],
                (float)$row['actual'],
                (float)$row['diff'],
                $row['diff_pct'] !== null ? $row['diff_pct'] / 100 : null,
            ];
        }

        return $data;
    }

    public function columnFormats(): array
    {
        return [
            'A' => '@',
            'C' => '#,##0.00',
            'D' => '#,##0.00',
            'E' => '#,##0.00',
            'F' => '0.0%',
        ];
    }

    public function styles(Worksheet $sheet): array
    {
        $styles = [
            1 => ['font' => ['bold' => true, 'size' => 12]],
            2 => ['font' => ['bold' => true], 'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'E8EEF3']]],
        ];

        foreach ($this->table as $i => $row) {
            $line = $i + self::HEADER_ROWS + 1;

            if ($row['kind'] === 'section') {
                $styles[$line] = ['font' => ['bold' => true], 'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'F5F7F9']]];
            }
            elseif ($row['kind'] === 'subtotal' || $row['kind'] === 'profit') {
                $styles[$line] = ['font' => ['bold' => true], 'borders' => ['top' => ['borderStyle' => 'thin']]];
            }

            if ($row['kind'] !== 'section') {
                $color = !empty($row['favorable']) ? '1D8348' : 'C0392B';
                $sheet->getStyle('E' . $line . ':F' . $line)->getFont()->getColor()->setRGB($color);
            }
        }

        return $styles;
    }

}
