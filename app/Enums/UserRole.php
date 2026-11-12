<?php

namespace App\Enums;

enum UserRole: string
{
    case Author = 'author';
    case Editor = 'editor';
}
