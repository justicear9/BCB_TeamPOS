<?php

namespace Modules\InventoryReporting\Exports;

use App\Utils\Util;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromArray;

class StockAsAtExport implements FromArray
{
    public function __construct(
        protected Collection $rows,
        protected bool $detailed,
        protected bool $includeCosts
    ) {
    }

    public function array(): array
    {
        if ($this->detailed) {
            $header = [
                __('sale.product'),
                __('lang_v1.sku'),
                __('inventoryreporting::lang.export_location'),
                __('lang_v1.lot_number'),
                __('report.exp_date'),
                __('lang_v1.qty_available'),
            ];
            if ($this->includeCosts) {
                $header[] = __('purchase.unit_cost_after_tax');
            }

            $lines = [$header];
            foreach ($this->rows as $r) {
                $row = [
                    $r->product_name ?? '',
                    $r->sub_sku ?? '',
                    $r->location_name ?? '',
                    $r->lot_number ?? '',
                    ! empty($r->exp_date) ? Util::bladeFormatDate($r->exp_date) : '',
                    $this->num($r->qty_on_hand ?? 0),
                ];
                if ($this->includeCosts) {
                    $row[] = $this->money($r->unit_cost ?? 0);
                }
                $lines[] = $row;
            }

            return $lines;
        }

        $header = [
            __('sale.product'),
            __('lang_v1.sku'),
            __('lang_v1.qty_available'),
        ];
        if ($this->includeCosts) {
            $header[] = __('purchase.unit_cost_after_tax');
        }

        $lines = [$header];
        foreach ($this->rows as $r) {
            $row = [
                $r->product_name ?? '',
                $r->sub_sku ?? '',
                $this->num($r->qty_on_hand ?? 0),
            ];
            if ($this->includeCosts) {
                $row[] = $this->money($r->unit_cost ?? 0);
            }
            $lines[] = $row;
        }

        return $lines;
    }

    protected function num($value)
    {
        return is_numeric($value) ? (float) $value : '';
    }

    protected function money($value)
    {
        if (! is_numeric($value)) {
            return '';
        }

        return round((float) $value, (int) session('business.currency_precision', 2));
    }
}
