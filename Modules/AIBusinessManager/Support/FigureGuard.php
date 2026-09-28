<?php

namespace Modules\AIBusinessManager\Support;

/**
 * Removes money amounts, percentages and large numbers from a reply when they cannot be traced to the data
 * Eli was given (tool results, business snapshot, the conversation), and adds a sources line.
 *
 * A figure is traceable when it matches a known number after rounding, or is the sum, difference, product,
 * ratio or percentage change of two known numbers.
 */
class FigureGuard
{
    private const MAX_KNOWN = 400;

    public const REMOVED = '[unverified figure removed]';

    /** @var list<float> */
    private array $known = [];

    /** @var array<string, true> */
    private array $knownKeys = [];

    public function addSource(string $text): void
    {
        if (! preg_match_all('/-?\d[\d,]*(?:\.\d+)?/', $text, $m)) {
            return;
        }
        foreach ($m[0] as $raw) {
            $value = (float) str_replace(',', '', $raw);
            $key = (string) round($value, 4);
            if (isset($this->knownKeys[$key])) {
                continue;
            }
            $this->knownKeys[$key] = true;
            $this->known[] = $value;
        }
    }

    /**
     * @return array{text: string, removed: int}
     */
    public function clean(string $text, string $currencySymbol = ''): array
    {
        $symbols = array_filter(array_unique([preg_quote($currencySymbol, '/'), '¢', 'GH₵', 'GHS', 'GH¢', '\\$']));
        $money = '(?:'.implode('|', $symbols).')\s?-?\d[\d,]*(?:\.\d+)?';
        $pattern = '/'.$money.'|-?\d[\d,]*(?:\.\d+)?\s?%|\b\d{1,3}(?:,\d{3})+(?:\.\d+)?\b|\b\d{4,}\.\d+\b/u';

        $removed = 0;
        $out = preg_replace_callback($pattern, function ($m) use (&$removed) {
            $raw = $m[0];
            if (! preg_match('/-?\d[\d,]*(?:\.\d+)?/', $raw, $num)) {
                return $raw;
            }
            $value = (float) str_replace(',', '', $num[0]);
            $isPct = str_contains($raw, '%');
            if ($this->traceable($value, $isPct)) {
                return $raw;
            }
            $removed++;

            return self::REMOVED;
        }, $text) ?? $text;

        return ['text' => $out, 'removed' => $removed];
    }

    public function traceable(float $value, bool $isPct = false): bool
    {
        $abs = abs($value);
        if (! $isPct && $abs < 10) {
            return true;
        }
        if ($isPct && in_array(round($abs, 4), [0.0, 50.0, 100.0], true)) {
            return true;
        }
        $tol = max(0.51, $abs * 0.005);
        foreach ($this->known as $k) {
            if (abs(abs($k) - $abs) <= $tol) {
                return true;
            }
        }
        $known = array_slice($this->known, 0, self::MAX_KNOWN);
        $n = count($known);
        for ($i = 0; $i < $n; $i++) {
            $a = $known[$i];
            if ($a == 0.0) {
                continue;
            }
            for ($j = 0; $j < $n; $j++) {
                if ($i === $j) {
                    continue;
                }
                $b = $known[$j];
                if ($isPct) {
                    if ($b != 0.0 && (abs(abs($a / $b * 100) - $abs) <= 0.6 || abs(abs(($a - $b) / $b * 100) - $abs) <= 0.6)) {
                        return true;
                    }

                    continue;
                }
                if (abs(abs($a + $b) - $abs) <= $tol || abs(abs($a - $b) - $abs) <= $tol) {
                    return true;
                }
                if (abs(abs($a * $b) - $abs) <= max($tol, $abs * 0.01)) {
                    return true;
                }
                if ($b != 0.0 && abs(abs($a / $b) - $abs) <= $tol) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param  list<array{name: string, args: array<string, mixed>}>  $calls
     */
    public static function sourcesLine(array $calls): string
    {
        $parts = [];
        foreach ($calls as $call) {
            $label = str_replace('_', ' ', $call['name']);
            $args = $call['args'];
            $range = null;
            if (! empty($args['start_date']) || ! empty($args['end_date'])) {
                $range = trim(($args['start_date'] ?? '').' to '.($args['end_date'] ?? 'today'));
            } elseif (! empty($args['target_date'])) {
                $range = 'for '.$args['target_date'];
            }
            foreach (['location_name', 'name_query'] as $key) {
                if (! empty($args[$key]) && is_string($args[$key])) {
                    $range = trim(($range ?? '').' '.$args[$key]);
                }
            }
            $parts[$label.($range ? ' ('.$range.')' : '')] = true;
        }
        if ($parts === []) {
            return '';
        }

        return '_Sources: TeamPOS live data · '.implode(' · ', array_slice(array_keys($parts), 0, 6)).'_';
    }
}
