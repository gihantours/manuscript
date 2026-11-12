<?php

namespace App\Jobs;

use App\Enums\ManuscriptStatus;
use App\Models\EmailDelivery;
use App\Models\Manuscript;
use App\Services\NotificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class NotifyEditorStatusJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    public int $timeout = 60;

    public function __construct(
        public readonly int $manuscriptId,
        public readonly int $statusVersion,
        public readonly string $status,
    ) {}

    public function backoff(): array
    {
        return [5, 15, 45, 120];
    }

    public function handle(NotificationService $notificationService): void
    {
        $manuscript = Manuscript::find($this->manuscriptId);

        if ($manuscript === null) {
            return;
        }

        Log::withContext([
            'manuscript_id' => $manuscript->public_id,
            'stage' => 'editor_status_notification',
            'attempt' => $this->attempts(),
        ]);

        $key = "manuscript:{$manuscript->id}:editor-status:{$this->statusVersion}";
        $label = ManuscriptStatus::tryFrom($this->status)?->label() ?? $this->status;
        $subject = "Manuscript {$manuscript->public_id}: {$label}";
        $delivery = $this->deliveryFor($manuscript, $key, $subject, $label);

        if ($delivery->status === 'sent') {
            return;
        }

        $delivery->increment('attempts');

        $body = "Hello,\n\nYour manuscript \"{$manuscript->title}\" "
            ."(reference {$manuscript->public_id}) has a new editorial status: {$label}.\n\n"
            .'Thank you.';

        try {
            $notificationService->send($manuscript->author_email, $subject, $body, $key);
        } catch (\Throwable $exception) {
            $delivery->forceFill([
                'status' => 'failed',
                'last_error' => mb_substr($exception->getMessage(), 0, 2000),
            ])->save();

            throw $exception;
        }

        $delivery->forceFill([
            'status' => 'sent',
            'sent_at' => now(),
            'last_error' => null,
        ])->save();
    }

    public function failed(?\Throwable $exception): void
    {
        $manuscript = Manuscript::find($this->manuscriptId);

        if ($manuscript === null) {
            return;
        }

        $key = "manuscript:{$manuscript->id}:editor-status:{$this->statusVersion}";

        EmailDelivery::where('idempotency_key', $key)->update([
            'status' => 'failed',
            'last_error' => mb_substr($exception?->getMessage() ?? 'Unknown failure', 0, 2000),
        ]);

        Log::error('editor_status.failed', [
            'manuscript_id' => $manuscript->public_id,
            'status_version' => $this->statusVersion,
            'error' => $exception?->getMessage(),
        ]);
    }

    private function deliveryFor(Manuscript $manuscript, string $key, string $subject, string $label): EmailDelivery
    {
        try {
            return EmailDelivery::firstOrCreate(
                ['idempotency_key' => $key],
                [
                    'manuscript_id' => $manuscript->id,
                    'event_type' => 'editor_status',
                    'recipient' => $manuscript->author_email,
                    'subject' => $subject,
                    'status_version' => $this->statusVersion,
                    'status' => 'pending',
                ],
            );
        } catch (QueryException) {
            return EmailDelivery::where('idempotency_key', $key)->firstOrFail();
        }
    }
}
