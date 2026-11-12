<?php

namespace App\Console\Commands;

use App\Enums\ManuscriptStatus;
use App\Enums\ProcessingStatus;
use App\Models\Manuscript;
use App\Models\ManuscriptProcessingStage;
use App\Models\OutboxEvent;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class DlqReplayCommand extends Command
{
    protected $signature = 'dlq:replay
        {manuscript? : Manuscript public id (m_123) or numeric id}
        {--all : Replay every failed stage}
        {--reason=manual : Audit reason recorded in the replay log}';

    protected $description = 'Safely requeue failed manuscript stages with an audit trail';

    private const STAGE_EVENTS = [
        'validated' => 'manuscript.submitted',
        'text_extracted' => 'manuscript.validated',
        'similarity_checked' => 'manuscript.text_extracted',
        'preview_built' => 'manuscript.similarity_checked',
        'author_notified' => 'manuscript.preview_built',
        'done' => 'manuscript.author_notified',
    ];

    public function handle(): int
    {
        $query = ManuscriptProcessingStage::query()
            ->where('status', ProcessingStatus::Failed->value)
            ->with('manuscript');

        if (! $this->option('all')) {
            $identifier = $this->argument('manuscript');

            if ($identifier === null) {
                $this->error('Pass a manuscript id or use --all.');

                return self::FAILURE;
            }

            $manuscript = Manuscript::query()
                ->where('public_id', $identifier)
                ->orWhere('id', ctype_digit((string) $identifier) ? (int) $identifier : 0)
                ->first();

            if ($manuscript === null) {
                $this->error('Manuscript not found.');

                return self::FAILURE;
            }

            $query->where('manuscript_id', $manuscript->id);
        }

        $stages = $query->get();

        if ($stages->isEmpty()) {
            $this->info('No failed stages matched.');

            return self::SUCCESS;
        }

        $replayed = 0;

        foreach ($stages as $stage) {
            $event = self::STAGE_EVENTS[$stage->stage_name] ?? null;

            if ($event === null) {
                $this->warn("No replay event for stage {$stage->stage_name}; skipped.");

                continue;
            }

            DB::transaction(function () use ($stage, $event, &$replayed) {
                $stage->forceFill([
                    'status' => ProcessingStatus::Pending,
                    'last_error' => null,
                    'started_at' => null,
                    'finished_at' => null,
                ])->save();

                $manuscript = $stage->manuscript;

                if ($manuscript !== null) {
                    $manuscript->forceFill([
                        'status' => ManuscriptStatus::Processing,
                        'failure_reason' => null,
                    ])->save();

                    OutboxEvent::create([
                        'event_type' => $event,
                        'aggregate_id' => (string) $manuscript->id,
                        'payload' => [
                            'manuscript_id' => $manuscript->id,
                            'priority' => $manuscript->priority->value,
                        ],
                        'available_at' => now(),
                    ]);
                }

                $replayed++;
            });

            $this->line(sprintf(
                'Replayed %s stage %s',
                $stage->manuscript?->public_id ?? $stage->manuscript_id,
                $stage->stage_name,
            ));
        }

        file_put_contents(storage_path('logs/replay.log'), json_encode([
            'at' => now()->toIso8601String(),
            'reason' => $this->option('reason'),
            'replayed' => $replayed,
        ]).PHP_EOL, FILE_APPEND);

        $this->info("Replayed {$replayed} stage(s).");

        return self::SUCCESS;
    }
}
