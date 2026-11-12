<?php

namespace App\Jobs;

use App\Enums\ProcessingStage;
use App\Models\EmailDelivery;
use App\Models\Manuscript;
use App\Services\NotificationService;
use Illuminate\Database\QueryException;

class NotifyAuthorJob extends ManuscriptStageJob
{
    protected function stage(): ProcessingStage
    {
        return ProcessingStage::AuthorNotified;
    }

    protected function perform(Manuscript $manuscript): void
    {
        $key = "manuscript:{$manuscript->id}:received";
        $subject = "Manuscript {$manuscript->public_id} received: {$manuscript->title}";
        $delivery = $this->deliveryFor($manuscript, $key, $subject);

        if ($delivery->status === 'sent') {
            $this->completeAndAdvance($manuscript, 'manuscript.author_notified');

            return;
        }

        $delivery->increment('attempts');

        $body = "Hello,\n\nWe received your manuscript \"{$manuscript->title}\" "
            ."(reference {$manuscript->public_id}). We will email you again when processing is complete.\n\n"
            .'Thank you.';

        try {
            app(NotificationService::class)->send(
                $manuscript->author_email,
                $subject,
                $body,
                $key,
            );
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

        $this->completeAndAdvance($manuscript, 'manuscript.author_notified');
    }

    private function deliveryFor(Manuscript $manuscript, string $key, string $subject): EmailDelivery
    {
        try {
            return EmailDelivery::firstOrCreate(
                ['idempotency_key' => $key],
                [
                    'manuscript_id' => $manuscript->id,
                    'event_type' => 'received',
                    'recipient' => $manuscript->author_email,
                    'subject' => $subject,
                    'status' => 'pending',
                ],
            );
        } catch (QueryException) {
            return EmailDelivery::where('idempotency_key', $key)->firstOrFail();
        }
    }
}
