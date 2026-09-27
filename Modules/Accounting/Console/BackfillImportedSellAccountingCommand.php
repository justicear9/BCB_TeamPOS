<?php

namespace Modules\Accounting\Console;

use App\Events\SellCreatedOrModified;
use App\Transaction;
use Illuminate\Console\Command;
use Modules\Accounting\Entities\AccountingAccountsTransaction;

class BackfillImportedSellAccountingCommand extends Command
{
    protected $signature = 'accounting:backfill-imported-sells
                            {--business= : Required numeric business id}
                            {--import-batch= : Only this import batch number}
                            {--location= : Optional location id filter}
                            {--dry-run : List transaction ids without dispatching}
                            {--force : Dispatch even when sell GL lines already exist (idempotent repost)}';

    protected $description = 'Dispatch SellCreatedOrModified for imported sales that never ran through accounting (no reimport).';

    public function handle(): int
    {
        $businessId = $this->option('business');
        if ($businessId === null || $businessId === '' || ! ctype_digit((string) $businessId)) {
            $this->error('Pass --business=<numeric id>.');

            return self::FAILURE;
        }
        $businessId = (int) $businessId;

        $q = Transaction::query()
            ->where('business_id', $businessId)
            ->where('type', 'sell')
            ->where('status', 'final')
            ->whereNotNull('import_batch')
            ->orderBy('id');

        if ($this->option('import-batch') !== null && $this->option('import-batch') !== '') {
            $batch = (int) $this->option('import-batch');
            $q->where('import_batch', $batch);
        }

        if ($this->option('location') !== null && $this->option('location') !== '') {
            $q->where('location_id', (int) $this->option('location'));
        }

        $ids = $q->pluck('id');
        $total = $ids->count();
        if ($total === 0) {
            $this->warn('No matching imported final sales.');

            return self::SUCCESS;
        }

        $this->info('Candidates: '.$total.' (business '.$businessId.')');

        $dry = (bool) $this->option('dry-run');
        $force = (bool) $this->option('force');

        $dispatched = 0;
        $skipped = 0;

        foreach ($ids as $id) {
            if (! $force && $this->alreadyHasSellGl((int) $id)) {
                $skipped++;
                if ($dry) {
                    $this->line('skip existing GL tx='.$id);
                }
                continue;
            }

            if ($dry) {
                $this->line('would dispatch tx='.$id);
                $dispatched++;
                continue;
            }

            $transaction = Transaction::with('sell_lines')->find($id);
            if (! $transaction) {
                continue;
            }

            SellCreatedOrModified::dispatch($transaction);
            $dispatched++;
        }

        if ($dry) {
            $this->info('Dry run: would dispatch '.$dispatched.', skip '.$skipped.' (existing GL).');
        } else {
            $this->info('Dispatched '.$dispatched.', skipped '.$skipped.' (existing GL). Use --force to repost.');
        }

        return self::SUCCESS;
    }

    /**
     * True when invoice-level sell mappings were already written (listener output uses sub_type sell, payment_id null).
     */
    protected function alreadyHasSellGl(int $transactionId): bool
    {
        return AccountingAccountsTransaction::query()
            ->where('transaction_id', $transactionId)
            ->whereNull('transaction_payment_id')
            ->where('sub_type', 'sell')
            ->exists();
    }
}
