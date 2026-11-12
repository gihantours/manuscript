<?php

namespace App\Jobs;

use App\Enums\ProcessingStage;
use App\Exceptions\PermanentExternalException;
use App\Models\Manuscript;
use App\Services\ManuscriptFileValidator;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class ValidateManuscriptJob extends ManuscriptStageJob
{
    protected function stage(): ProcessingStage
    {
        return ProcessingStage::Validated;
    }

    protected function perform(Manuscript $manuscript): void
    {
        if (! Storage::disk('local')->exists($manuscript->file_path)) {
            throw new PermanentExternalException('The stored manuscript file is missing.');
        }

        $contents = Storage::disk('local')->get($manuscript->file_path);

        try {
            app(ManuscriptFileValidator::class)->validate($contents);
        } catch (ValidationException $exception) {
            throw new PermanentExternalException($exception->validator->errors()->first('file'));
        }

        if (! hash_equals($manuscript->file_hash, hash('sha256', $contents))) {
            throw new PermanentExternalException('The stored manuscript hash does not match the submitted file.');
        }

        $this->completeAndAdvance($manuscript, 'manuscript.validated');
    }
}
