<?php

namespace App\Console\Commands;

use App\Services\OutboxDispatcher;
use Illuminate\Console\Command;

class OutboxWorkCommand extends Command
{
    protected $signature = 'outbox:work {--sleep=1 : Seconds to sleep when the outbox is empty} {--limit=50 : Maximum events per poll}';

    protected $description = 'Continuously publish transactional outbox events to the queue';

    public function handle(OutboxDispatcher $dispatcher): int
    {
        $running = true;

        if (function_exists('pcntl_async_signals')) {
            pcntl_async_signals(true);
            pcntl_signal(SIGTERM, function () use (&$running) {
                $running = false;
            });
            pcntl_signal(SIGINT, function () use (&$running) {
                $running = false;
            });
        }

        $this->info('Outbox worker started.');

        while ($running) {
            $result = $dispatcher->dispatchPending((int) $this->option('limit'));

            if ($result['claimed'] > 0) {
                $this->line(sprintf(
                    'claimed=%d published=%d failed=%d',
                    $result['claimed'],
                    $result['published'],
                    $result['failed'],
                ));
            }

            if ($result['claimed'] === 0) {
                sleep(max(1, (int) $this->option('sleep')));
            }
        }

        $this->info('Outbox worker stopped.');

        return self::SUCCESS;
    }
}
