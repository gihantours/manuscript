<?php

namespace App\Services;

use App\Jobs\BuildPreviewJob;
use App\Jobs\CheckSimilarityJob;
use App\Jobs\ExtractTextJob;
use App\Jobs\FinalizeManuscriptJob;
use App\Jobs\NotifyAuthorJob;
use App\Jobs\NotifyEditorStatusJob;
use App\Jobs\ValidateManuscriptJob;
use App\Models\OutboxEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class OutboxDispatcher
{
    public function __construct(private readonly ?\Closure $publisher = null) {}

    /**
     * @return array{claimed:int, published:int, failed:int}
     */
    public function dispatchPending(int $limit = 50): array
    {
        return DB::transaction(function () use ($limit) {
            $events = OutboxEvent::query()
                ->whereNull('published_at')
                ->where(function ($query) {
                    $query->whereNull('available_at')->orWhere('available_at', '<=', now());
                })
                ->orderBy('id')
                ->limit($limit)
                ->lock('for update skip locked')
                ->get();

            $published = 0;
            $failed = 0;

            foreach ($events as $event) {
                if ($this->dispatchEvent($event)) {
                    $published++;
                } else {
                    $failed++;
                }
            }

            return [
                'claimed' => $events->count(),
                'published' => $published,
                'failed' => $failed,
            ];
        });
    }

    public function dispatchEvent(OutboxEvent $event): bool
    {
        $job = $this->jobFor($event);

        if ($job === null) {
            $event->forceFill([
                'published_at' => now(),
                'last_error' => 'No handler for event type '.$event->event_type,
            ])->save();

            Log::warning('outbox.no_handler', [
                'outbox_event_id' => $event->id,
                'event_type' => $event->event_type,
            ]);

            return false;
        }

        try {
            $queue = $this->queueFor($event);
            $publish = $this->publisher ?? fn (object $job, string $queue) => dispatch($job)->onQueue($queue);
            $publish($job, $queue);

            $event->forceFill([
                'published_at' => now(),
                'last_error' => null,
            ])->save();

            Log::info('outbox.published', [
                'outbox_event_id' => $event->id,
                'event_type' => $event->event_type,
                'manuscript_id' => $event->payload['manuscript_id'] ?? null,
                'queue' => $queue,
            ]);

            return true;
        } catch (\Throwable $exception) {
            $attempts = $event->attempts + 1;
            $delay = min(300, 5 * (2 ** min($attempts - 1, 6)));

            $event->forceFill([
                'attempts' => $attempts,
                'available_at' => now()->addSeconds($delay),
                'last_error' => $exception->getMessage(),
            ])->save();

            Log::error('outbox.publish_failed', [
                'outbox_event_id' => $event->id,
                'event_type' => $event->event_type,
                'attempts' => $attempts,
                'retry_in_seconds' => $delay,
                'error' => $exception->getMessage(),
            ]);

            return false;
        }
    }

    private function jobFor(OutboxEvent $event): ?object
    {
        $manuscriptId = (int) ($event->payload['manuscript_id'] ?? 0);

        return match ($event->event_type) {
            'manuscript.submitted' => new ValidateManuscriptJob($manuscriptId),
            'manuscript.validated' => new ExtractTextJob($manuscriptId),
            'manuscript.text_extracted' => new CheckSimilarityJob($manuscriptId),
            'manuscript.similarity_checked' => new BuildPreviewJob($manuscriptId),
            'manuscript.preview_built' => new NotifyAuthorJob($manuscriptId),
            'manuscript.author_notified' => new FinalizeManuscriptJob($manuscriptId),
            'editor.status_changed' => new NotifyEditorStatusJob(
                $manuscriptId,
                (int) ($event->payload['status_version'] ?? 0),
                (string) ($event->payload['status'] ?? ''),
            ),
            default => null,
        };
    }

    private function queueFor(OutboxEvent $event): string
    {
        if ($event->event_type === 'manuscript.text_extracted') {
            return 'similarity';
        }

        if (in_array($event->event_type, ['manuscript.preview_built', 'editor.status_changed'], true)) {
            return 'notifications';
        }

        return ($event->payload['priority'] ?? 'normal') === 'high' ? 'high' : 'default';
    }
}
