<?php

namespace Tests\Feature;

use App\Enums\ProcessingStage;
use App\Exceptions\RateLimitException;
use App\Exceptions\TransientExternalException;
use App\Jobs\CheckSimilarityJob;
use App\Jobs\ManuscriptStageJob;
use App\Models\Manuscript;
use App\Services\SimilarityService;
use App\Services\StageTracker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\Concerns\InteractsWithManuscripts;
use Tests\TestCase;

class SimilarityFailureTest extends TestCase
{
    use InteractsWithManuscripts, RefreshDatabase;

    public function test_transient_503_is_converted_to_a_transient_exception(): void
    {
        Http::fake(['*/similarity' => Http::response(['error' => 'temporarily unavailable'], 503)]);

        $this->expectException(TransientExternalException::class);
        app(SimilarityService::class)->score('m_1', 'text');
    }

    public function test_429_exposes_the_retry_after_delay(): void
    {
        Http::fake([
            '*/similarity' => Http::response(['error' => 'too many'], 429, ['Retry-After' => '7']),
        ]);

        try {
            app(SimilarityService::class)->score('m_1', 'text');
            $this->fail('Expected a rate limit exception.');
        } catch (RateLimitException $exception) {
            $this->assertSame(7, $exception->retryAfter);
        }
    }

    public function test_rate_limit_releases_the_job_with_the_provider_delay(): void
    {
        Storage::fake('local');
        $manuscript = $this->makeManuscript($this->makeUser(), [], true);

        $job = new class($manuscript->id) extends ManuscriptStageJob
        {
            protected function stage(): ProcessingStage
            {
                return ProcessingStage::SimilarityChecked;
            }

            protected function perform(Manuscript $manuscript): void
            {
                throw new RateLimitException(7);
            }
        };

        $job->withFakeQueueInteractions();
        $job->handle(app(StageTracker::class));

        $job->assertReleased(7);

        $this->assertDatabaseHas('manuscript_processing_stages', [
            'manuscript_id' => $manuscript->id,
            'stage_name' => 'similarity_checked',
            'status' => 'retrying',
        ]);
    }

    public function test_retry_policy_is_bounded(): void
    {
        $job = new CheckSimilarityJob(1);

        $this->assertSame(5, $job->tries);
        $this->assertSame([5, 15, 45, 120], $job->backoff());
    }

    public function test_similarity_score_is_returned_on_success(): void
    {
        Http::fake(['*/similarity' => Http::response(['manuscript_id' => 'm_1', 'score' => 82], 200)]);

        $this->assertSame(82, app(SimilarityService::class)->score('m_1', 'text'));
    }

    public function test_exhausted_similarity_job_marks_stage_and_manuscript_failed(): void
    {
        Storage::fake('local');
        $manuscript = $this->makeManuscript($this->makeUser(), [], true);

        (new CheckSimilarityJob($manuscript->id))->failed(new RuntimeException('503 from similarity service'));

        $this->assertDatabaseHas('manuscript_processing_stages', [
            'manuscript_id' => $manuscript->id,
            'stage_name' => 'similarity_checked',
            'status' => 'failed',
        ]);
        $this->assertDatabaseHas('manuscripts', [
            'id' => $manuscript->id,
            'status' => 'failed',
            'needs_attention' => true,
        ]);
    }
}
