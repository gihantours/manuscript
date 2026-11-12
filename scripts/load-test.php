#!/usr/bin/env php
<?php

use GuzzleHttp\Client;
use GuzzleHttp\Pool;
use GuzzleHttp\Psr7\Response;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Http;
use ZipArchive;

require __DIR__.'/../vendor/autoload.php';

$app = require_once __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$baseUrl = $argv[1] ?? getenv('LOAD_TEST_API_URL') ?: 'http://localhost:8000';
$count = (int) ($argv[2] ?? getenv('LOAD_TEST_COUNT') ?: 50);
$concurrency = (int) ($argv[3] ?? getenv('LOAD_TEST_CONCURRENCY') ?: 10);
$email = getenv('LOAD_TEST_EMAIL') ?: 'author@example.com';
$password = getenv('LOAD_TEST_PASSWORD') ?: 'password';

$zipPath = __DIR__.'/../sample-data/manuscripts.zip';
$zip = new ZipArchive;

if ($zip->open($zipPath) !== true) {
    fwrite(STDERR, "Unable to open {$zipPath}\n");
    exit(1);
}

$samples = [];

for ($i = 0; $i < $zip->numFiles; $i++) {
    $name = str_replace('\\', '/', (string) $zip->getNameIndex($i));

    if (! str_starts_with($name, 'manuscripts/') || in_array('..', explode('/', $name), true)) {
        continue;
    }

    $contents = $zip->getFromIndex($i);

    if ($contents === false || $contents === '' || str_contains($contents, "\0")) {
        continue;
    }

    $samples[] = ['name' => basename($name), 'contents' => $contents];
}

$zip->close();

if ($samples === []) {
    fwrite(STDERR, "No usable sample files found.\n");
    exit(1);
}

$login = Http::post($baseUrl.'/api/login', [
    'email' => $email,
    'password' => $password,
]);

if (! $login->successful()) {
    fwrite(STDERR, 'Login failed: '.$login->body()."\n");
    exit(1);
}

$token = $login->json('token');
$client = new Client(['http_errors' => false, 'timeout' => 30]);
$url = $baseUrl.'/api/manuscripts';
$accepted = 0;
$failed = 0;

$requests = function () use ($count, $samples, $client, $url, $token) {
    for ($i = 0; $i < $count; $i++) {
        $sample = $samples[$i % count($samples)];

        yield function () use ($client, $url, $token, $sample, $i) {
            return $client->postAsync($url, [
                'headers' => ['Authorization' => 'Bearer '.$token, 'Accept' => 'application/json'],
                'multipart' => [
                    ['name' => 'title', 'contents' => 'Load test '.($i + 1).' '.$sample['name']],
                    ['name' => 'author_email', 'contents' => 'loadtest@example.org'],
                    ['name' => 'priority', 'contents' => $i % 10 === 0 ? 'high' : 'normal'],
                    ['name' => 'file', 'contents' => $sample['contents'], 'filename' => $sample['name']],
                ],
            ]);
        };
    }
};

$pool = new Pool($client, $requests(), [
    'concurrency' => max(1, $concurrency),
    'fulfilled' => function (Response $response) use (&$accepted, &$failed) {
        if ($response->getStatusCode() === 202) {
            $accepted++;
        } else {
            $failed++;
        }
    },
    'rejected' => function ($reason) use (&$failed) {
        $failed++;
        fwrite(STDERR, 'Request failed: '.$reason->getMessage()."\n");
    },
]);

$pool->promise()->wait();

echo "Submitted {$count} manuscripts to {$baseUrl}: accepted={$accepted} failed={$failed}\n";
