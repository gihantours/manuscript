<?php

namespace Tests\Feature;

use App\Services\SampleDataImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\InteractsWithManuscripts;
use Tests\TestCase;
use ZipArchive;

class SampleDataImporterTest extends TestCase
{
    use InteractsWithManuscripts, RefreshDatabase;

    public function test_import_reports_every_row_and_rejects_unsafe_entries(): void
    {
        Storage::fake('local');
        $base = sys_get_temp_dir().DIRECTORY_SEPARATOR.'importer_'.uniqid();
        $extract = $base.DIRECTORY_SEPARATOR.'extract';
        File::ensureDirectoryExists($base);

        $zipPath = $base.DIRECTORY_SEPARATOR.'samples.zip';
        $zip = new ZipArchive;
        $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('manuscripts/ok.txt', str_repeat('valid text ', 40));
        $zip->addFromString('manuscripts/empty.txt', '');
        $zip->addFromString('manuscripts/binary.txt', "\x89PNG\r\n\x1a\n".str_repeat("\0", 32));
        $zip->addFromString('manuscripts/missing-row.txt', 'this file exists');
        $zip->addFromString('../evil.txt', 'traversal');
        $zip->close();

        $manifestPath = $base.DIRECTORY_SEPARATOR.'manifest.csv';
        $handle = fopen($manifestPath, 'wb');
        fputcsv($handle, ['file_name', 'title', 'author_email', 'priority']);
        fputcsv($handle, ['ok.txt', 'Quoted, comma title', 'good@example.org', 'normal']);
        fputcsv($handle, ['empty.txt', 'Empty', 'good@example.org', 'normal']);
        fputcsv($handle, ['binary.txt', 'Binary', 'good@example.org', 'normal']);
        fputcsv($handle, ['missing.txt', 'Missing', 'good@example.org', 'normal']);
        fputcsv($handle, ['ok.txt', 'Bad email', 'not-an-email', 'normal']);
        fclose($handle);

        $owner = $this->makeUser();
        $result = app(SampleDataImporter::class)->import($zipPath, $manifestPath, $owner, $extract);

        $this->assertSame(5, $result['summary']['total']);
        $this->assertSame(1, $result['summary']['imported']);
        $this->assertSame(4, $result['summary']['rejected']);
        $this->assertTrue(collect($result['rejected_entries'])->contains(fn ($entry) => $entry['entry'] === '../evil.txt'));
        $this->assertFileDoesNotExist($base.DIRECTORY_SEPARATOR.'evil.txt');
        $this->assertSame(1, $owner->manuscripts()->count());

        $imported = collect($result['rows'])->firstWhere('status', 'imported');
        $this->assertSame('ok.txt', $imported['file_name']);
        $this->assertStringStartsWith('m_', $imported['manuscript_id']);

        File::deleteDirectory($base);
    }
}
