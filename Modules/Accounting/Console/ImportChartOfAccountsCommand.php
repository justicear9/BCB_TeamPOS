<?php

namespace Modules\Accounting\Console;

use App\Business;
use Illuminate\Console\Command;
use Modules\Accounting\Services\CoaCsvImportService;

class ImportChartOfAccountsCommand extends Command
{
    protected $signature = 'accounting:import-coa
                            {business : Business name (exact match) or numeric business id}
                            {--file= : Absolute or relative path to CSV (default: LIKA Health sample in module)}
                            {--user=1 : User id for created_by on new accounts}';

    protected $description = 'Import chart of accounts from CSV for a business (same rules as Accounting → COA import).';

    public function handle(CoaCsvImportService $importService): int
    {
        $businessArg = (string) $this->argument('business');
        $business = is_numeric($businessArg)
            ? Business::find((int) $businessArg)
            : Business::where('name', $businessArg)->first();

        if (! $business) {
            $this->error('Business not found: '.$businessArg);

            return self::FAILURE;
        }

        $defaultPath = dirname(__DIR__).'/Resources/samples/lika_health_chart_of_accounts.csv';
        $path = $this->option('file') ?: $defaultPath;
        if ($path !== '' && $path[0] !== '/' && ! preg_match('#^[A-Za-z]:[\\\\/]#', $path)) {
            $path = base_path($path);
        }
        if (! is_readable($path)) {
            $this->error('File not readable: '.$path);

            return self::FAILURE;
        }

        $userId = (int) ($this->option('user') ?: 1);
        $this->info('Importing COA for business: '.$business->name.' (id '.$business->id.')');
        $this->line('File: '.$path);

        $result = $importService->importFile($path, (int) $business->id, $userId);

        $this->info('Created: '.$result['created'].', Updated: '.$result['updated']);
        if (! empty($result['errors'])) {
            foreach (array_slice($result['errors'], 0, 20) as $err) {
                $this->warn($err);
            }
            if (count($result['errors']) > 20) {
                $this->warn('… and '.(count($result['errors']) - 20).' more error(s).');
            }
        }

        if ($result['created'] === 0 && $result['updated'] === 0 && ! empty($result['errors'])) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
