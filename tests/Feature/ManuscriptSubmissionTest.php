<?php

namespace Tests\Feature;

use App\Models\Manuscript;
use App\Models\OutboxEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\InteractsWithManuscripts;
use Tests\TestCase;

class ManuscriptSubmissionTest extends TestCase
{
    use InteractsWithManuscripts, RefreshDatabase;

    public function test_submission_returns_202_and_persists_manuscript_and_outbox(): void
    {
        Storage::fake('local');
        $user = $this->makeUser();
        Sanctum::actingAs($user);

        $response = $this->post('/api/manuscripts', [
            'title' => 'Reading Habits',
            'author_email' => 'ben@example.org',
            'priority' => 'normal',
            'file' => UploadedFile::fake()->createWithContent('manuscript.txt', str_repeat('reading habits ', 50)),
        ], ['Accept' => 'application/json']);

        $response->assertStatus(202)->assertJson(['status' => 'received']);

        $manuscript = Manuscript::firstOrFail();

        $this->assertSame(7, $manuscript->stages()->count());
        $this->assertSame('completed', $manuscript->stages()->where('stage_name', 'received')->firstOrFail()->status->value);
        $this->assertTrue(Storage::disk('local')->exists($manuscript->file_path));
        $this->assertDatabaseHas('outbox_events', [
            'event_type' => 'manuscript.submitted',
            'aggregate_id' => (string) $manuscript->id,
        ]);
        $this->assertSame(1, OutboxEvent::count());
    }

    public function test_submission_logs_the_manuscript_id(): void
    {
        Storage::fake('local');
        Sanctum::actingAs($this->makeUser());
        Log::spy();

        $response = $this->post('/api/manuscripts', [
            'title' => 'Logged submission',
            'author_email' => 'author@example.org',
            'priority' => 'high',
            'file' => UploadedFile::fake()->createWithContent('manuscript.txt', str_repeat('logged text ', 20)),
        ], ['Accept' => 'application/json']);

        $response->assertStatus(202);
        $id = $response->json('id');

        Log::shouldHaveReceived('info')
            ->withArgs(fn (string $message, array $context): bool => $message === 'manuscript.submitted'
                && ($context['manuscript_id'] ?? null) === $id
                && ($context['outcome'] ?? null) === 'accepted')
            ->once();
    }

    public function test_empty_file_is_rejected(): void
    {
        Storage::fake('local');
        Sanctum::actingAs($this->makeUser());

        $this->post('/api/manuscripts', [
            'title' => 'Empty',
            'author_email' => 'author@example.org',
            'priority' => 'normal',
            'file' => UploadedFile::fake()->createWithContent('empty.txt', ''),
        ], ['Accept' => 'application/json'])->assertStatus(422);
    }

    public function test_binary_file_with_txt_extension_is_rejected(): void
    {
        Storage::fake('local');
        Sanctum::actingAs($this->makeUser());

        $png = "\x89PNG\r\n\x1a\n".str_repeat("\0", 32);

        $this->post('/api/manuscripts', [
            'title' => 'Binary',
            'author_email' => 'author@example.org',
            'priority' => 'normal',
            'file' => UploadedFile::fake()->createWithContent('fake.txt', $png),
        ], ['Accept' => 'application/json'])->assertStatus(422);
    }

    public function test_oversized_file_is_rejected(): void
    {
        Storage::fake('local');
        Sanctum::actingAs($this->makeUser());

        $this->post('/api/manuscripts', [
            'title' => 'Too big',
            'author_email' => 'author@example.org',
            'priority' => 'normal',
            'file' => UploadedFile::fake()->create('big.txt', 11 * 1024),
        ], ['Accept' => 'application/json'])->assertStatus(422);
    }

    public function test_non_txt_extension_is_rejected(): void
    {
        Storage::fake('local');
        Sanctum::actingAs($this->makeUser());

        $this->post('/api/manuscripts', [
            'title' => 'Wrong type',
            'author_email' => 'author@example.org',
            'priority' => 'normal',
            'file' => UploadedFile::fake()->createWithContent('manuscript.pdf', 'plain text'),
        ], ['Accept' => 'application/json'])->assertStatus(422);
    }
}
