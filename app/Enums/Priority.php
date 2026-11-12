<?php

namespace App\Enums;

enum Priority: string
{
    case Normal = 'normal';
    case High = 'high';

    public function queue(): string
    {
        return $this === self::High ? 'high' : 'default';
    }
}
