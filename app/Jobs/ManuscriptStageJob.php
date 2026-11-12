<?php

namespace App\Jobs;

use App\Enums\ManuscriptStatus;
use App\Enums\ProcessingStage;
use App\Exceptions\PermanentExternalException;
use App\Exceptions\RateLimitException;
use App\Models\Manuscript;
use App\Models\OutboxEvent;
use App\Services\StageTracker;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

abstract class ManuscriptStageJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    public int $timeout = 120;

    public bool $failOnTimeout = true;

    public function __construct(public readonly int $manuscriptId) {}

    abstract protected function stage(): ProcessingStage;

    abstract protected function perform(Manuscript $manuscript): void;

    public function backoff(): array
    {
        return [5, 15, 45, 120];
    }

    public function handle(StageTracker $tracker): void
    {
        $manuscript = Manuscript::find($this->manuscriptId);

        if ($manuscript === null) {
            return;
        }

        Log::withContext([
            'manuscript_id' => $manuscript->public_id,
            'stage' => $this->stage()->value,
            'attempt' => $this->attempts(),
        ]);

        if ($tracker->isCompleted($manuscript, $this->stage())) {
            return;
        }

        $tracker->markRunning($manuscript, $this->stage());

        try {
            $this->perform($manuscript);
        } catch (RateLimitException $exception) {
            $tracker->markRetrying($manuscript, $this->stage(), $exception);
            $this->release($exception->retryAfter);

            return;
        } catch (PermanentExternalException $exception) {
            $this->fail($exception);

            return;
        } catch (\Throwable $exception) {
            $tracker->markRetrying($manuscript, $this->stage(), $exception);

            throw $exception;
        }
    }

    public function failed(?\Throwable $exception): void
    {
        $manuscript = Manuscript::find($this->manuscriptId);

        if ($manuscript === null) {
            return;
        }

        app(StageTracker::class)->markFailed($manuscript, $this->stage(), $exception ?? new \RuntimeException('Unknown failure'));

        $manuscript->forceFill([
            'status' => ManuscriptStatus::Failed,
            'needs_attention' => true,
            'failure_reason' => mb_substr($exception?->getMessage() ?? 'Unknown failure', 0, 2000),
        ])->save();

        Log::error('stage.failed', [
            'manuscript_id' => $manuscript->public_id,
            'stage' => $this->stage()->value,
            'attempts' => $this->attempts(),
            'error' => $exception?->getMessage(),
        ]);
    }

    protected function completeAndAdvance(Manuscript $manuscript, ?string $nextEvent): void
    {
        DB::transaction(function () use ($manuscript, $nextEvent) {
            app(StageTracker::class)->markCompleted($manuscript, $this->stage());

            if ($nextEvent !== null) {
                OutboxEvent::create([
                    'event_type' => $nextEvent,
                    'aggregate_id' => (string) $manuscript->id,
                    'payload' => [
                        'manuscript_id' => $manuscript->id,
                        'priority' => $manuscript->priority->value,
                    ],
                    'available_at' => now(),
                ]);
            }

            if (! $manuscript->isEditorialStatus()) {
                $manuscript->forceFill(['status' => ManuscriptStatus::Processing])->save();
            }
        });
    }
}
