<?php

namespace App\Services;

use Illuminate\Validation\ValidationException;

class ManuscriptFileValidator
{
    public const MAX_BYTES = 10 * 1024 * 1024;

    /**
     * Validate that the given bytes are a non-empty, human-readable UTF-8 text file.
     *
     * @throws ValidationException
     */
    public function validate(string $contents, ?string $path = null): void
    {
        if ($contents === '') {
            throw ValidationException::withMessages([
                'file' => 'The uploaded file is empty.',
            ]);
        }

        if (strlen($contents) > self::MAX_BYTES) {
            throw ValidationException::withMessages([
                'file' => 'The uploaded file exceeds the 10 MB limit.',
            ]);
        }

        if (! mb_check_encoding($contents, 'UTF-8')) {
            throw ValidationException::withMessages([
                'file' => 'The uploaded file is not valid UTF-8 text.',
            ]);
        }

        if (str_contains($contents, "\0")) {
            throw ValidationException::withMessages([
                'file' => 'The uploaded file is binary, not text.',
            ]);
        }

        $controlCharacters = preg_match_all('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $contents);

        if ($controlCharacters > 0) {
            throw ValidationException::withMessages([
                'file' => 'The uploaded file contains binary control characters.',
            ]);
        }

        if ($path !== null) {
            $this->validateMimeType($path);
        }
    }

    /**
     * @throws ValidationException
     */
    private function validateMimeType(string $path): void
    {
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($path);

        if ($mime === false || ! str_starts_with($mime, 'text/')) {
            throw ValidationException::withMessages([
                'file' => 'The uploaded file does not look like plain text.',
            ]);
        }
    }
}
