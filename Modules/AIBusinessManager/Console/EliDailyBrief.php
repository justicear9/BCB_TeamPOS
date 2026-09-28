<?php

namespace Modules\AIBusinessManager\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\AIBusinessManager\Services\Manager\BriefBuilder;
use Modules\AIBusinessManager\Services\Manager\ManagerScope;

class EliDailyBrief extends Command
{
    protected $signature = 'eli:daily-brief {--business= : Only this business id} {--refresh : Rebuild even if today\'s brief exists}';

    protected $description = 'Build Eli\'s morning brief for each business that uses Eli.';

    public function handle(): int
    {
        foreach ($this->businessIds() as $businessId) {
            $scope = ManagerScope::forBusiness($businessId);
            if (! $scope) {
                continue;
            }
            try {
                $content = (new BriefBuilder($scope))->briefFor($scope->today(), 'daily', (bool) $this->option('refresh'));
                $this->info('Business '.$businessId.': '.($content !== null ? 'brief ready' : 'no brief'));
            } catch (\Throwable $e) {
                report($e);
                $this->error('Business '.$businessId.': '.$e->getMessage());
            }
        }

        return self::SUCCESS;
    }

    /**
     * @return list<int>
     */
    private function businessIds(): array
    {
        if ($this->option('business')) {
            return [(int) $this->option('business')];
        }
        if (! Schema::hasTable('ai_business_manager_preferences')) {
            return [];
        }

        return DB::table('ai_business_manager_preferences')->pluck('business_id')->map(fn ($id) => (int) $id)->all();
    }
}
