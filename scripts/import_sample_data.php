#!/usr/bin/env php
<?php

use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../vendor/autoload.php';

$app = require_once __DIR__.'/../bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

$status = $kernel->call('sample:import', [
    '--owner' => $argv[1] ?? 'author@example.com',
]);

echo $kernel->output();

exit($status);
