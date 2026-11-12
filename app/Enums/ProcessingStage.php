<?php

namespace App\Enums;

enum ProcessingStage: string
{
    case Received = 'received';
    case Validated = 'validated';
    case TextExtracted = 'text_extracted';
    case SimilarityChecked = 'similarity_checked';
    case PreviewBuilt = 'preview_built';
    case AuthorNotified = 'author_notified';
    case Done = 'done';

    /**
     * All stages in pipeline order.
     *
     * @return array<int, self>
     */
    public static function pipeline(): array
    {
        return [
            self::Received,
            self::Validated,
            self::TextExtracted,
            self::SimilarityChecked,
            self::PreviewBuilt,
            self::AuthorNotified,
            self::Done,
        ];
    }
}
