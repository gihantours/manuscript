<?php

namespace App\Enums;

enum ProcessingStatus: string
{
    case Pending = 'pending';
    case Running = 'running';
    case Retrying = 'retrying';
    case Completed = 'completed';
    case Failed = 'failed';
    case Skipped = 'skipped';
}
