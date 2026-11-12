<?php

namespace App\Services;

use App\Exceptions\PermanentExternalException;
use App\Exceptions\RateLimitException;
use App\Exceptions\TransientExternalException;
use Illuminate\Support\Facades\Http;

class SimilarityService
{
    public function score(string $manuscriptId, string $text): int
    {
        $response = Http::baseUrl((string) config('services.similarity.base_url'))
            ->acceptJson()
            ->connectTimeout((int) config('services.similarity.connect_timeout', 3))
            ->timeout((int) config('services.similarity.timeout', 10))
            ->post('/similarity', [
                'manuscript_id' => $manuscriptId,
                'text' => $text,
            ]);

        if ($response->status() === 429) {
            throw new RateLimitException((int) $response->header('Retry-After', '2'));
        }

        if ($response->serverError()) {
            throw new TransientExternalException('Similarity service returned '.$response->status());
        }

        if ($response->clientError()) {
            throw new PermanentExternalException('Similarity service rejected the request: '.$response->status());
        }

        $score = $response->json('score');

        if (! is_int($score) || $score < 0 || $score > 100) {
            throw new TransientExternalException('Similarity service returned an invalid score.');
        }

        return $score;
    }
}
