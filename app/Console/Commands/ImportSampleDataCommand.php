<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\SampleDataImporter;
use Illuminate\Console\Command;

class ImportSampleDataCommand extends Command
{
    protected $signature = 'sample:import
        {--owner=author@example.com : Email address of the user who will own imported manuscripts}
        {--zip= : Path to manuscripts.zip}
        {--manifest= : Path to manifest.csv}
        {--report= : Where to write the JSON report}';

    protected $description = 'Safely import the supplied sample manuscripts and manifest';

    public function handle(SampleDataImporter $importer): int
    {
        $owner = User::where('email', $this->option('owner'))->first();

        if ($owner === null) {
            $this->error('Owner user not found. Run `php artisan db:seed` first or pass --owner.');

            return self::FAILURE;
        }

        $zip = $this->option('zip') ?: base_path('sample-data/manuscripts.zip');
        $manifest = $this->option('manifest') ?: base_path('sample-data/manifest.csv');
        $reportPath = $this->option('report') ?: storage_path('logs/import-report.json');

        $result = $importer->import($zip, $manifest, $owner, storage_path('app/private/imports/sample'));

        $this->table(
            ['Row', 'File', 'Status', 'Detail'],
            array_map(fn (array $row) => [
                $row['row'],
                $row['file_name'] ?? '-',
                $row['status'],
                $row['error'] ?? ($row['manuscript_id'] ?? ''),
            ], $result['rows']),
        );

        if ($result['rejected_entries'] !== []) {
            $this->warn('Rejected archive entries:');
            $this->table(
                ['Entry', 'Reason'],
                array_map(fn (array $entry) => [$entry['entry'], $entry['reason']], $result['rejected_entries']),
            );
        }

        file_put_contents($reportPath, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $this->info(sprintf(
            'Imported %d, rejected %d, total %d. Report: %s',
            $result['summary']['imported'],
            $result['summary']['rejected'],
            $result['summary']['total'],
            $reportPath,
        ));

        return self::SUCCESS;
    }
}
