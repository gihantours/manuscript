<?php

namespace App\Console\Commands;

use App\Services\OutboxDispatcher;
use Illuminate\Console\Command;

class OutboxDispatchCommand extends Command
{
    protected $signature = 'outbox:dispatch {--limit=50}';

    protected $description = 'Publish pending outbox events once';

    public function handle(OutboxDispatcher $dispatcher): int
    {
        $result = $dispatcher->dispatchPending((int) $this->option('limit'));

        $this->info(sprintf(
            'claimed=%d published=%d failed=%d',
            $result['claimed'],
            $result['published'],
            $result['failed'],
        ));

        return self::SUCCESS;
    }
}
