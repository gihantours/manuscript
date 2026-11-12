<?php

namespace App\Console\Commands;

use App\Models\ManuscriptProcessingStage;
use Illuminate\Console\Command;

class DlqListCommand extends Command
{
    protected $signature = 'dlq:list';

    protected $description = 'List manuscript stages that exhausted their retries';

    public function handle(): int
    {
        $stages = ManuscriptProcessingStage::query()
            ->where('status', 'failed')
            ->with('manuscript')
            ->orderBy('id')
            ->get();

        if ($stages->isEmpty()) {
            $this->info('No failed stages.');

            return self::SUCCESS;
        }

        $this->table(
            ['Manuscript', 'Stage', 'Attempts', 'Last error'],
            $stages->map(fn (ManuscriptProcessingStage $stage) => [
                $stage->manuscript?->public_id ?? $stage->manuscript_id,
                $stage->stage_name,
                $stage->attempts,
                mb_substr((string) $stage->last_error, 0, 120),
            ])->all(),
        );

        return self::SUCCESS;
    }
}
