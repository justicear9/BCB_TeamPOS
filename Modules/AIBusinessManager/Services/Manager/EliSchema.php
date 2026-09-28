<?php

namespace Modules\AIBusinessManager\Services\Manager;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * The live site deploys with git pull only, so new Eli tables are created on first use.
 */
class EliSchema
{
    public const TABLES = ['ai_bm_targets', 'ai_bm_fixed_costs', 'ai_bm_notes', 'ai_bm_calendar_events', 'ai_bm_forecasts', 'ai_bm_briefs', 'ai_bm_alerts'];

    private static ?bool $ready = null;

    public static function ready(): bool
    {
        if (self::$ready !== null) {
            return self::$ready;
        }
        if (self::tablesExist()) {
            return self::$ready = true;
        }

        try {
            Artisan::call('module:migrate', ['module' => 'AIBusinessManager', '--force' => true]);
        } catch (\Throwable $e) {
            Log::warning('AIBusinessManager: could not create Eli tables: '.$e->getMessage());
        }

        return self::$ready = self::tablesExist();
    }

    private static function tablesExist(): bool
    {
        foreach (self::TABLES as $table) {
            if (! Schema::hasTable($table)) {
                return false;
            }
        }

        return true;
    }
}
