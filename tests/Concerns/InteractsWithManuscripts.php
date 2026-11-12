<?php

namespace Tests\Concerns;

use App\Enums\ManuscriptStatus;
use App\Enums\ProcessingStage;
use App\Enums\ProcessingStatus;
use App\Enums\UserRole;
use App\Models\Manuscript;
use App\Models\ManuscriptProcessingStage;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

trait InteractsWithManuscripts
{
    protected function makeUser(string $role = 'author', ?string $email = null): User
    {
        return User::factory()->create([
            'email' => $email ?? ($role === 'editor' ? 'editor-'.uniqid().'@example.com' : 'author-'.uniqid().'@example.com'),
            'role' => $role === 'editor' ? UserRole::Editor->value : UserRole::Author->value,
        ]);
    }

    protected function makeManuscript(User $user, array $overrides = [], bool $withText = false): Manuscript
    {
        $contents = $overrides['contents'] ?? 'A short manuscript about editorial workflows and reading habits.';
        unset($overrides['contents']);

        $filePath = 'manuscripts/test-'.uniqid().'.txt';
        Storage::disk('local')->put($filePath, $contents);

        $manuscript = Manuscript::create(array_merge([
            'public_id' => 'm_test_'.uniqid(),
            'user_id' => $user->id,
            'title' => 'Test manuscript',
            'author_email' => 'author@example.org',
            'original_filename' => 'test.txt',
            'file_path' => $filePath,
            'file_hash' => hash('sha256', $contents),
            'priority' => 'normal',
            'status' => ManuscriptStatus::Received,
        ], $overrides));

        foreach (ProcessingStage::pipeline() as $stage) {
            $isReceived = $stage === ProcessingStage::Received;

            ManuscriptProcessingStage::create([
                'manuscript_id' => $manuscript->id,
                'stage_name' => $stage->value,
                'status' => $isReceived ? ProcessingStatus::Completed : ProcessingStatus::Pending,
                'attempts' => $isReceived ? 1 : 0,
                'started_at' => $isReceived ? now() : null,
                'finished_at' => $isReceived ? now() : null,
            ]);
        }

        if ($withText) {
            $textPath = 'manuscripts/extracted/test-'.$manuscript->id.'.txt';
            Storage::disk('local')->put($textPath, $contents);
            $manuscript->forceFill([
                'extracted_text_path' => $textPath,
                'extracted_text_hash' => hash('sha256', $contents),
                'word_count' => count(preg_split('/\s+/u', trim($contents), -1, PREG_SPLIT_NO_EMPTY) ?: []),
            ])->save();
        }

        return $manuscript->refresh();
    }
}
