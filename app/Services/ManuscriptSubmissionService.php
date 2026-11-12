<?php

namespace App\Services;

use App\Enums\ManuscriptStatus;
use App\Enums\ProcessingStage;
use App\Enums\ProcessingStatus;
use App\Models\Manuscript;
use App\Models\ManuscriptProcessingStage;
use App\Models\OutboxEvent;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ManuscriptSubmissionService
{
    public function __construct(private readonly ManuscriptFileValidator $fileValidator) {}

    public function submit(User $user, UploadedFile $file, array $data): Manuscript
    {
        $path = $file->getRealPath();
        $contents = $path !== false ? file_get_contents($path) : false;

        if ($contents === false) {
            throw new \RuntimeException('Unable to read the uploaded file.');
        }

        $this->fileValidator->validate($contents, $path ?: null);

        return $this->submitContents($user, $contents, $file->getClientOriginalName(), $data);
    }

    /**
     * Submit already-validated bytes. Used by the HTTP flow and the sample-data importer.
     */
    public function submitContents(
        User $user,
        string $contents,
        string $originalFilename,
        array $data,
    ): Manuscript {
        $this->fileValidator->validate($contents);

        $storageKey = 'manuscripts/'.Str::uuid().'.txt';
        Storage::disk('local')->put($storageKey, $contents);

        try {
            return DB::transaction(function () use ($user, $contents, $originalFilename, $data, $storageKey) {
                $manuscript = Manuscript::create([
                    'public_id' => 'tmp_'.Str::uuid(),
                    'user_id' => $user->id,
                    'title' => $data['title'],
                    'author_email' => $data['author_email'],
                    'original_filename' => $originalFilename,
                    'file_path' => $storageKey,
                    'file_hash' => hash('sha256', $contents),
                    'priority' => $data['priority'] ?? 'normal',
                    'status' => ManuscriptStatus::Received,
                ]);

                $manuscript->forceFill(['public_id' => 'm_'.$manuscript->id])->save();

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

                OutboxEvent::create([
                    'event_type' => 'manuscript.submitted',
                    'aggregate_id' => (string) $manuscript->id,
                    'payload' => [
                        'manuscript_id' => $manuscript->id,
                        'priority' => $manuscript->priority->value,
                    ],
                    'available_at' => now(),
                ]);

                return $manuscript->load('stages');
            });
        } catch (\Throwable $exception) {
            Storage::disk('local')->delete($storageKey);

            throw $exception;
        }
    }
}
