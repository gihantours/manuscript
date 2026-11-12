<?php

namespace Tests\Feature;

use Illuminate\Contracts\Redis\LimiterTimeoutException;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

class SimilarityConcurrencyTest extends TestCase
{
    public function test_no_more_than_four_similarity_slots_can_be_held(): void
    {
        if (! extension_loaded('redis')) {
            $this->markTestSkipped('The redis extension is not loaded.');
        }

        try {
            Redis::connection()->ping();
        } catch (\Throwable) {
            $this->markTestSkipped('Redis is not reachable.');
        }

        $name = 'test-similarity-'.uniqid();
        $fifthTimedOut = false;

        $hold = function (int $depth) use (&$hold, $name, &$fifthTimedOut) {
            return Redis::funnel($name)
                ->limit(4)
                ->releaseAfter(10)
                ->block(0)
                ->then(function () use ($depth, &$hold, $name, &$fifthTimedOut) {
                    if ($depth < 3) {
                        return $hold($depth + 1);
                    }

                    try {
                        Redis::funnel($name)->limit(4)->releaseAfter(10)->block(0)->then(fn () => true);
                    } catch (LimiterTimeoutException) {
                        $fifthTimedOut = true;
                    }

                    return true;
                });
        };

        $this->assertTrue($hold(0));
        $this->assertTrue($fifthTimedOut, 'A fifth concurrent similarity request should have timed out.');
    }
}
