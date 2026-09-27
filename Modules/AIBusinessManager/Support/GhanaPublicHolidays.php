<?php

namespace Modules\AIBusinessManager\Support;

use Carbon\Carbon;

/**
 * Statutory public holidays for businesses in Ghana.
 * Movable feasts (Easter, Eid) are included only for years with a published list.
 */
class GhanaPublicHolidays
{
    public static function applies(string $timezone, ?string $country = null): bool
    {
        if (strcasecmp($timezone, 'Africa/Accra') === 0) {
            return true;
        }

        return $country !== null && preg_match('/ghana/i', $country) === 1;
    }

    public static function caveat(): string
    {
        return 'Fixed holidays and Farmers\' Day (first Friday of December) are statutory. Easter and Eid dates are included only when a published Ghana list exists for that year (2026 is loaded). Do not invent holiday dates that are not listed.';
    }

    /**
     * Holidays from 14 days ago through 120 days ahead, relative to $today.
     *
     * @return list<array{date: string, name: string, when: string}>
     */
    public static function around(Carbon $today): array
    {
        $today = $today->copy()->startOfDay();
        $from = $today->copy()->subDays(14);
        $to = $today->copy()->addDays(120);

        $rows = [];
        for ($year = (int) $from->year; $year <= (int) $to->year; $year++) {
            foreach (self::forYear($year) as $holiday) {
                $date = Carbon::parse($holiday['date'])->startOfDay();
                if ($date->lt($from) || $date->gt($to)) {
                    continue;
                }
                $when = 'upcoming';
                if ($date->equalTo($today)) {
                    $when = 'today';
                } elseif ($date->lt($today)) {
                    $when = 'recent';
                }
                $rows[] = [
                    'date' => $date->toDateString(),
                    'name' => $holiday['name'],
                    'when' => $when,
                ];
            }
        }

        usort($rows, fn ($a, $b) => strcmp($a['date'], $b['date']));

        return $rows;
    }

    /**
     * @return list<array{date: string, name: string}>
     */
    public static function forYear(int $year): array
    {
        $rows = [
            ['date' => sprintf('%04d-01-01', $year), 'name' => "New Year's Day"],
            ['date' => sprintf('%04d-01-07', $year), 'name' => 'Constitution Day'],
            ['date' => sprintf('%04d-03-06', $year), 'name' => 'Independence Day'],
            ['date' => sprintf('%04d-05-01', $year), 'name' => "Workers' Day"],
            ['date' => sprintf('%04d-08-04', $year), 'name' => "Founders' Day"],
            ['date' => sprintf('%04d-09-21', $year), 'name' => 'Kwame Nkrumah Memorial Day'],
            ['date' => self::farmersDay($year), 'name' => "Farmers' Day"],
            ['date' => sprintf('%04d-12-25', $year), 'name' => 'Christmas Day'],
            ['date' => sprintf('%04d-12-26', $year), 'name' => 'Boxing Day'],
        ];

        if ($year === 2026) {
            $rows[] = ['date' => '2026-03-20', 'name' => 'Eid-Ul-Fitr'];
            $rows[] = ['date' => '2026-03-21', 'name' => 'Eid-Ul-Fitr (Shaqq Day)'];
            $rows[] = ['date' => '2026-03-23', 'name' => 'Eid-Ul-Fitr observed'];
            $rows[] = ['date' => '2026-04-03', 'name' => 'Good Friday'];
            $rows[] = ['date' => '2026-04-06', 'name' => 'Easter Monday'];
            $rows[] = ['date' => '2026-05-27', 'name' => 'Eid-Ul-Adha'];
        }

        usort($rows, fn ($a, $b) => strcmp($a['date'], $b['date']));

        return $rows;
    }

    public static function farmersDay(int $year): string
    {
        $day = Carbon::create($year, 12, 1, 0, 0, 0);
        if ($day->dayOfWeek !== Carbon::FRIDAY) {
            $day->next(Carbon::FRIDAY);
        }

        return $day->toDateString();
    }
}
