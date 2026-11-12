<?php

namespace Tests\Feature;

use App\Exceptions\TransientExternalException;
use App\Jobs\NotifyAuthorJob;
use App\Services\StageTracker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\InteractsWithManuscripts;
use Tests\TestCase;

class EmailIdempotencyTest extends TestCase
{
    use InteractsWithManuscripts, RefreshDatabase;

    public function test_504_after_send_retry_reuses_the_same_idempotency_key(): void
    {
        Storage::fake('local');
        $manuscript = $this->makeManuscript($this->makeUser(), [], true);
        $key = "manuscript:{$manuscript->id}:received";

        Http::fake([
            '*/notify' => Http::sequence()
                ->push(['error' => 'gateway timeout'], 504)
                ->push(['status' => 'duplicate_ignored'], 200),
        ]);

        $job = new NotifyAuthorJob($manuscript->id);

        try {
            $job->handle(app(StageTracker::class));
            $this->fail('Expected a transient failure on the first attempt.');
        } catch (TransientExternalException) {
            // Expected: the provider may have sent the email and then timed out.
        }

        $this->assertDatabaseHas('email_deliveries', [
            'manuscript_id' => $manuscript->id,
            'idempotency_key' => $key,
            'status' => 'failed',
        ]);

        (new NotifyAuthorJob($manuscript->id))->handle(app(StageTracker::class));

        $this->assertDatabaseHas('email_deliveries', [
            'manuscript_id' => $manuscript->id,
            'idempotency_key' => $key,
            'status' => 'sent',
        ]);
        $this->assertSame(1, $manuscript->emailDeliveries()->count());
        $this->assertDatabaseHas('manuscript_processing_stages', [
            'manuscript_id' => $manuscript->id,
            'stage_name' => 'author_notified',
            'status' => 'completed',
        ]);
        $this->assertDatabaseHas('outbox_events', [
            'event_type' => 'manuscript.author_notified',
            'aggregate_id' => (string) $manuscript->id,
        ]);

        Http::assertSentCount(2);
        Http::assertSent(fn ($request) => $request->hasHeader('Idempotency-Key', $key));
    }

    public function test_rerunning_a_completed_notification_job_sends_nothing(): void
    {
        Storage::fake('local');
        $manuscript = $this->makeManuscript($this->makeUser(), [], true);

        Http::fake([
            '*/notify' => Http::response(['status' => 'sent'], 200),
        ]);

        (new NotifyAuthorJob($manuscript->id))->handle(app(StageTracker::class));
        (new NotifyAuthorJob($manuscript->id))->handle(app(StageTracker::class));

        Http::assertSentCount(1);
        $this->assertSame(1, $manuscript->emailDeliveries()->count());
    }
}
