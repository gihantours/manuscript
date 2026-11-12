<?php

namespace Tests\Feature;

use App\Jobs\ValidateManuscriptJob;
use App\Models\OutboxEvent;
use App\Services\OutboxDispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class OutboxDispatcherTest extends TestCase
{
    use RefreshDatabase;

    public function test_redis_failure_leaves_the_event_unpublished_for_retry(): void
    {
        $event = OutboxEvent::create([
            'event_type' => 'manuscript.submitted',
            'aggregate_id' => '1',
            'payload' => ['manuscript_id' => 1, 'priority' => 'normal'],
            'available_at' => now(),
        ]);

        $dispatcher = new OutboxDispatcher(function () {
            throw new RuntimeException('redis down');
        });

        $this->assertFalse($dispatcher->dispatchEvent($event));

        $event->refresh();

        $this->assertNull($event->published_at);
        $this->assertSame(1, $event->attempts);
        $this->assertNotNull($event->available_at);
        $this->assertStringContainsString('redis down', (string) $event->last_error);
    }

    public function test_high_priority_events_are_published_to_the_high_queue(): void
    {
        $event = OutboxEvent::create([
            'event_type' => 'manuscript.submitted',
            'aggregate_id' => '1',
            'payload' => ['manuscript_id' => 1, 'priority' => 'high'],
            'available_at' => now(),
        ]);

        $captured = null;
        $dispatcher = new OutboxDispatcher(function ($job, string $queue) use (&$captured) {
            $captured = [$job, $queue];
        });

        $this->assertTrue($dispatcher->dispatchEvent($event));
        $this->assertNotNull($event->refresh()->published_at);
        $this->assertInstanceOf(ValidateManuscriptJob::class, $captured[0]);
        $this->assertSame('high', $captured[1]);
    }

    public function test_similarity_events_are_published_to_the_similarity_queue(): void
    {
        $event = OutboxEvent::create([
            'event_type' => 'manuscript.text_extracted',
            'aggregate_id' => '1',
            'payload' => ['manuscript_id' => 1, 'priority' => 'normal'],
            'available_at' => now(),
        ]);

        $queue = null;
        $dispatcher = new OutboxDispatcher(function ($job, string $queueName) use (&$queue) {
            $queue = $queueName;
        });

        $this->assertTrue($dispatcher->dispatchEvent($event));
        $this->assertSame('similarity', $queue);
    }

    public function test_dispatch_pending_claims_available_events_and_ignores_future_ones(): void
    {
        $first = OutboxEvent::create([
            'event_type' => 'manuscript.submitted',
            'aggregate_id' => '1',
            'payload' => ['manuscript_id' => 1, 'priority' => 'normal'],
            'available_at' => now(),
        ]);

        $second = OutboxEvent::create([
            'event_type' => 'manuscript.submitted',
            'aggregate_id' => '2',
            'payload' => ['manuscript_id' => 2, 'priority' => 'normal'],
            'available_at' => now(),
        ]);

        $future = OutboxEvent::create([
            'event_type' => 'manuscript.submitted',
            'aggregate_id' => '3',
            'payload' => ['manuscript_id' => 3, 'priority' => 'normal'],
            'available_at' => now()->addMinute(),
        ]);

        $publishedManuscriptIds = [];
        $dispatcher = new OutboxDispatcher(function (ValidateManuscriptJob $job) use (&$publishedManuscriptIds) {
            $publishedManuscriptIds[] = $job->manuscriptId;
        });

        $this->assertSame(
            ['claimed' => 2, 'published' => 2, 'failed' => 0],
            $dispatcher->dispatchPending(),
        );
        $this->assertSame([1, 2], $publishedManuscriptIds);
        $this->assertNotNull($first->refresh()->published_at);
        $this->assertNotNull($second->refresh()->published_at);
        $this->assertNull($future->refresh()->published_at);

        $this->assertSame(
            ['claimed' => 0, 'published' => 0, 'failed' => 0],
            $dispatcher->dispatchPending(),
        );
    }

    public function test_dispatch_pending_counts_failures_and_schedules_a_retry(): void
    {
        $event = OutboxEvent::create([
            'event_type' => 'manuscript.submitted',
            'aggregate_id' => '1',
            'payload' => ['manuscript_id' => 1, 'priority' => 'normal'],
            'available_at' => now(),
        ]);

        $dispatcher = new OutboxDispatcher(function () {
            throw new RuntimeException('redis down');
        });

        $this->assertSame(
            ['claimed' => 1, 'published' => 0, 'failed' => 1],
            $dispatcher->dispatchPending(),
        );

        $event->refresh();

        $this->assertNull($event->published_at);
        $this->assertSame(1, $event->attempts);
        $this->assertTrue($event->available_at->isFuture());
        $this->assertStringContainsString('redis down', (string) $event->last_error);
    }

    public function test_dispatch_pending_respects_the_limit(): void
    {
        foreach (range(1, 3) as $manuscriptId) {
            OutboxEvent::create([
                'event_type' => 'manuscript.submitted',
                'aggregate_id' => (string) $manuscriptId,
                'payload' => ['manuscript_id' => $manuscriptId, 'priority' => 'normal'],
                'available_at' => now(),
            ]);
        }

        $dispatcher = new OutboxDispatcher(function (ValidateManuscriptJob $job, string $queue) {});

        $this->assertSame(
            ['claimed' => 2, 'published' => 2, 'failed' => 0],
            $dispatcher->dispatchPending(2),
        );
        $this->assertSame(2, OutboxEvent::whereNotNull('published_at')->count());
        $this->assertSame(1, OutboxEvent::whereNull('published_at')->count());
    }
}
