<?php

namespace App\Enums;

enum ManuscriptStatus: string
{
    case Received = 'received';
    case Processing = 'processing';
    case Completed = 'completed';
    case Failed = 'failed';
    case Accepted = 'accepted';
    case Rejected = 'rejected';
    case RevisionRequested = 'revision_requested';

    public function label(): string
    {
        return match ($this) {
            self::Received => 'Received',
            self::Processing => 'Processing',
            self::Completed => 'Completed',
            self::Failed => 'Failed',
            self::Accepted => 'Accepted',
            self::Rejected => 'Rejected',
            self::RevisionRequested => 'Revision requested',
        };
    }
}
