<?php

namespace Modules\AIBusinessManager\Services\Manager;

/**
 * Markdown helpers for official tables attached under Eli's reply.
 */
class Md
{
    /**
     * @param  list<string>  $header
     * @param  list<list<string|int|float|null>>  $rows
     */
    public static function table(array $header, array $rows, ?string $title = null): string
    {
        $esc = fn ($v) => str_replace(['|', "\n"], ['/', ' '], (string) ($v ?? '—'));
        $lines = [];
        if ($title !== null) {
            $lines[] = '**'.$title.'**';
            $lines[] = '';
        }
        $lines[] = '| '.implode(' | ', array_map($esc, $header)).' |';
        $lines[] = '| '.implode(' | ', array_fill(0, count($header), '---')).' |';
        foreach ($rows as $row) {
            $lines[] = '| '.implode(' | ', array_map($esc, $row)).' |';
        }

        return implode("\n", $lines);
    }

    public static function money(?float $amount, ManagerScope $scope): string
    {
        if ($amount === null) {
            return '—';
        }
        $rounded = round($amount, $scope->precision);
        $decimals = abs($rounded - round($rounded)) < 0.0000001 ? 0 : $scope->precision;

        return ($rounded < 0 ? '-' : '').$scope->symbol.number_format(abs($rounded), $decimals);
    }

    public static function qty(?float $qty, int $decimals = 0): string
    {
        if ($qty === null) {
            return '—';
        }
        $rounded = round($qty, $decimals);
        if ($decimals > 0 && abs($rounded - round($rounded)) < 0.0000001) {
            $decimals = 0;
        }

        return number_format($rounded, $decimals);
    }

    public static function pct(?float $pct, int $decimals = 0): string
    {
        return $pct === null ? '—' : number_format($pct, $decimals).'%';
    }
}
