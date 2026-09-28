<?php

namespace Modules\AIBusinessManager\Support;

/**
 * Normalize TeamPOS unit labels into comparable families.
 * Merchants often treat loaf / loaves as the same countable unit as Pc / Pcs.
 */
final class UnitAlias
{
    /**
     * Canonical family keys.
     */
    public const FAMILY_PIECE = 'piece';

    /**
     * @var array<string, list<string>>
     */
    private const FAMILIES = [
        self::FAMILY_PIECE => [
            'pc', 'pcs', 'pc(s)', 'pcs.', 'pce', 'pces',
            'piece', 'pieces',
            'loaf', 'loaves',
            'ea', 'each', 'unit', 'units',
        ],
    ];

    public static function normalize(string $unit): string
    {
        $u = mb_strtolower(trim($unit));
        $u = preg_replace('/\s+/', '', $u) ?? $u;
        $u = str_replace(['.', '_', '-'], '', $u);

        return $u;
    }

    /**
     * Family key, or the normalized label when no family matches.
     */
    public static function family(string $unit): string
    {
        $normalized = self::normalize($unit);
        if ($normalized === '') {
            return self::FAMILY_PIECE;
        }

        foreach (self::FAMILIES as $family => $aliases) {
            foreach ($aliases as $alias) {
                if (self::normalize($alias) === $normalized) {
                    return $family;
                }
            }
        }

        return $normalized;
    }

    /**
     * True when the string looks like a unit label (not a product name).
     */
    public static function looksLikeUnitQuery(string $text): bool
    {
        $normalized = self::normalize($text);
        if ($normalized === '') {
            return false;
        }

        foreach (self::FAMILIES as $aliases) {
            foreach ($aliases as $alias) {
                if (self::normalize($alias) === $normalized) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $units
     */
    public static function unitsAreCompatible(array $units): bool
    {
        $families = [];
        foreach ($units as $unit) {
            $families[self::family((string) $unit)] = true;
        }

        return count($families) <= 1;
    }

    /**
     * SQL-friendly list of LIKE patterns for matching short_name / actual_name.
     *
     * @return list<string>
     */
    public static function sqlMatchNeedles(string $unitQuery): array
    {
        $family = self::family($unitQuery);
        $needles = [];
        if (isset(self::FAMILIES[$family])) {
            foreach (self::FAMILIES[$family] as $alias) {
                $needles[] = $alias;
            }
        } else {
            $needles[] = trim($unitQuery);
        }

        return array_values(array_unique($needles));
    }

    public static function displayLabel(string $unitQuery): string
    {
        $family = self::family($unitQuery);
        if ($family === self::FAMILY_PIECE) {
            $n = self::normalize($unitQuery);
            if (str_starts_with($n, 'loaf')) {
                return 'loaves';
            }

            return 'Pc(s)';
        }

        return trim($unitQuery) !== '' ? trim($unitQuery) : 'unit';
    }
}
