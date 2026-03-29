<?php

namespace Modules\Accounting\Exports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;

class FixedAssetScheduleExport implements FromCollection, WithHeadings
{
    /**
     * @param  Collection<int, array<int, string|float|int|null>>  $rows
     */
    public function __construct(
        private Collection $rows,
        private array $headings,
    ) {
    }

    public function collection(): Collection
    {
        return $this->rows;
    }

    public function headings(): array
    {
        return $this->headings;
    }
}
