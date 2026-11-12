<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use ZipArchive;

class SampleDataImporter
{
    private const MAX_ENTRY_BYTES = 20 * 1024 * 1024;

    private const MAX_TOTAL_BYTES = 100 * 1024 * 1024;

    private const MAX_ENTRIES = 200;

    public function __construct(
        private readonly ManuscriptSubmissionService $submissionService,
        private readonly ManuscriptFileValidator $fileValidator,
    ) {}

    /**
     * @return array{rows: array<int, array<string, mixed>>, rejected_entries: array<int, array<string, string>>, summary: array<string, int>}
     */
    public function import(string $zipPath, string $manifestPath, User $owner, ?string $extractDir = null): array
    {
        $extractDir ??= Storage::disk('local')->path('imports/sample');
        $this->ensureDirectory($extractDir);

        [$files, $rejectedEntries] = $this->extractSafely($zipPath, $extractDir);
        [$rows, $summary] = $this->importManifest($manifestPath, $files, $owner);

        return [
            'rows' => $rows,
            'rejected_entries' => $rejectedEntries,
            'summary' => $summary,
        ];
    }

    /**
     * @return array{0: array<string, string>, 1: array<int, array<string, string>>}
     */
    private function extractSafely(string $zipPath, string $extractDir): array
    {
        $zip = new ZipArchive;

        if ($zip->open($zipPath) !== true) {
            throw new \RuntimeException('Unable to open the sample archive.');
        }

        $files = [];
        $rejected = [];
        $totalBytes = 0;

        try {
            if ($zip->numFiles > self::MAX_ENTRIES) {
                throw new \RuntimeException('The sample archive contains too many entries.');
            }

            for ($index = 0; $index < $zip->numFiles; $index++) {
                $stat = $zip->statIndex($index);

                if ($stat === false) {
                    continue;
                }

                $name = str_replace('\\', '/', $stat['name']);

                if ($this->isUnsafeEntry($name, $stat)) {
                    $rejected[] = ['entry' => $name, 'reason' => 'unsafe path (absolute, traversal, or symlink)'];

                    continue;
                }

                if (! str_starts_with($name, 'manuscripts/') || str_ends_with($name, '/')) {
                    $rejected[] = ['entry' => $name, 'reason' => 'outside manuscripts/ directory'];

                    continue;
                }

                $size = (int) $stat['size'];

                if ($size > self::MAX_ENTRY_BYTES) {
                    $rejected[] = ['entry' => $name, 'reason' => 'entry exceeds per-file size cap'];

                    continue;
                }

                $totalBytes += $size;

                if ($totalBytes > self::MAX_TOTAL_BYTES) {
                    $rejected[] = ['entry' => $name, 'reason' => 'archive exceeds total uncompressed size cap'];

                    continue;
                }

                $stream = $zip->getStream($name);

                if ($stream === false) {
                    $rejected[] = ['entry' => $name, 'reason' => 'unable to read entry'];

                    continue;
                }

                $baseName = basename($name);

                if (isset($files[$baseName])) {
                    $rejected[] = ['entry' => $name, 'reason' => 'duplicate base name in archive'];

                    continue;
                }

                $target = $extractDir.DIRECTORY_SEPARATOR.$baseName;
                $handle = fopen($target, 'wb');

                if ($handle === false) {
                    fclose($stream);
                    $rejected[] = ['entry' => $name, 'reason' => 'unable to write extraction target'];

                    continue;
                }

                stream_copy_to_stream($stream, $handle);
                fclose($handle);
                fclose($stream);

                $files[$baseName] = $target;
            }
        } finally {
            $zip->close();
        }

        return [$files, $rejected];
    }

    /**
     * @param  array<string, mixed>  $stat
     */
    private function isUnsafeEntry(string $name, array $stat): bool
    {
        if ($name === '' || str_starts_with($name, '/') || preg_match('/^[A-Za-z]:/', $name)) {
            return true;
        }

        if (in_array('..', explode('/', $name), true)) {
            return true;
        }

        $attributes = (int) ($stat['external_attributes'] ?? 0);
        $fileType = ($attributes >> 16) & 0170000;

        return $fileType === 0120000;
    }

    /**
     * @param  array<string, string>  $files
     * @return array{0: array<int, array<string, mixed>>, 1: array<string, int>}
     */
    private function importManifest(string $manifestPath, array $files, User $owner): array
    {
        $handle = fopen($manifestPath, 'rb');

        if ($handle === false) {
            throw new \RuntimeException('Unable to open the sample manifest.');
        }

        $header = fgetcsv($handle);
        $header = $header === false ? [] : array_map(fn ($value) => ltrim((string) $value, "\xEF\xBB\xBF"), $header);
        $rows = [];
        $summary = ['total' => 0, 'imported' => 0, 'rejected' => 0];
        $line = 1;

        try {
            while (($values = fgetcsv($handle)) !== false) {
                $line++;
                $summary['total']++;

                if (count($values) !== count($header)) {
                    $rows[] = ['row' => $line, 'file_name' => null, 'status' => 'rejected', 'error' => 'Column count does not match header'];
                    $summary['rejected']++;

                    continue;
                }

                $row = array_combine($header, $values);
                $fileName = (string) ($row['file_name'] ?? '');

                $validator = Validator::make($row, [
                    'file_name' => ['required', 'string', 'max:255'],
                    'title' => ['required', 'string', 'max:255'],
                    'author_email' => ['required', 'string', 'email:rfc', 'max:255'],
                    'priority' => ['required', 'string', 'in:normal,high'],
                ]);

                if ($validator->fails()) {
                    $rows[] = [
                        'row' => $line,
                        'file_name' => $fileName,
                        'status' => 'rejected',
                        'error' => $validator->errors()->first(),
                    ];
                    $summary['rejected']++;

                    continue;
                }

                if (! isset($files[$fileName])) {
                    $rows[] = [
                        'row' => $line,
                        'file_name' => $fileName,
                        'status' => 'rejected',
                        'error' => 'Source file is missing from the archive',
                    ];
                    $summary['rejected']++;

                    continue;
                }

                $contents = file_get_contents($files[$fileName]);

                if ($contents === false) {
                    $rows[] = ['row' => $line, 'file_name' => $fileName, 'status' => 'rejected', 'error' => 'Could not read source file'];
                    $summary['rejected']++;

                    continue;
                }

                try {
                    $this->fileValidator->validate($contents, $files[$fileName]);

                    $manuscript = $this->submissionService->submitContents($owner, $contents, $fileName, [
                        'title' => $row['title'],
                        'author_email' => $row['author_email'],
                        'priority' => $row['priority'],
                    ]);

                    $rows[] = [
                        'row' => $line,
                        'file_name' => $fileName,
                        'status' => 'imported',
                        'manuscript_id' => $manuscript->public_id,
                        'priority' => $manuscript->priority->value,
                    ];
                    $summary['imported']++;
                } catch (ValidationException $exception) {
                    $rows[] = [
                        'row' => $line,
                        'file_name' => $fileName,
                        'status' => 'rejected',
                        'error' => $exception->validator->errors()->first(),
                    ];
                    $summary['rejected']++;
                } catch (\Throwable $exception) {
                    $rows[] = [
                        'row' => $line,
                        'file_name' => $fileName,
                        'status' => 'rejected',
                        'error' => $exception->getMessage(),
                    ];
                    $summary['rejected']++;
                }
            }
        } finally {
            fclose($handle);
        }

        return [$rows, $summary];
    }

    private function ensureDirectory(string $directory): void
    {
        if (! is_dir($directory) && ! mkdir($directory, 0775, true) && ! is_dir($directory)) {
            throw new \RuntimeException('Unable to create the extraction directory.');
        }
    }
}
