<?php

namespace Modules\InventoryReporting\Exports;

use App\Utils\Util;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromArray;

class InventoryAgeingExport implements FromArray
{
    public function __construct(
        protected Collection $rows
    ) {
    }

    public function array(): array
    {
        $header = [
            __('inventoryreporting::lang.export_location'),
            __('sale.product'),
            __('lang_v1.sku'),
            __('inventoryreporting::lang.date_received'),
            __('lang_v1.lot_number'),
            __('report.exp_date'),
            __('inventoryreporting::lang.qty_sold'),
            __('lang_v1.qty_available'),
            __('inventoryreporting::lang.days_in_stock'),
            __('inventoryreporting::lang.last_sale_date'),
        ];

        $lines = [$header];

        foreach ($this->rows as $r) {
            $lines[] = [
                $r->location_name ?? '',
                $r->product_name ?? '',
                $r->sub_sku ?? '',
                ! empty($r->date_received) ? Util::bladeFormatDatetime($r->date_received) : '',
                $r->lot_number ?? '',
                ! empty($r->exp_date) ? Util::bladeFormatDate($r->exp_date) : '',
                $this->num($r->qty_sold ?? 0),
                $this->num($r->qty_remaining ?? 0),
                $r->days_in_stock ?? '',
                ! empty($r->last_sale_date) ? Util::bladeFormatDatetime($r->last_sale_date) : '',
            ];
        }

        return $lines;
    }

    protected function num($value)
    {
        return is_numeric($value) ? (float) $value : '';
    }
}
