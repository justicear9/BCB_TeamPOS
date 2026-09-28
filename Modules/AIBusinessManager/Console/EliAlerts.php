<?php

namespace Modules\AIBusinessManager\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\AIBusinessManager\Services\Manager\BriefBuilder;
use Modules\AIBusinessManager\Services\Manager\ManagerScope;

class EliAlerts extends Command
{
    protected $signature = 'eli:alerts {--business= : Only this business id}';

    protected $description = 'Raise Eli alerts (slow trading, early sell-outs, registers left open).';

    public function handle(): int
    {
        $ids = $this->option('business')
            ? [(int) $this->option('business')]
            : (Schema::hasTable('ai_business_manager_preferences') ? DB::table('ai_business_manager_preferences')->pluck('business_id')->all() : []);
        foreach ($ids as $businessId) {
            $scope = ManagerScope::forBusiness((int) $businessId);
            if (! $scope) {
                continue;
            }
            try {
                foreach ((new BriefBuilder($scope))->checkAlerts() as $alert) {
                    $this->line('Business '.$businessId.': '.$alert['message']);
                }
            } catch (\Throwable $e) {
                report($e);
                $this->error('Business '.$businessId.': '.$e->getMessage());
            }
        }

        return self::SUCCESS;
    }
}
