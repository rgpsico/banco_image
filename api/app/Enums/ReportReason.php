<?php

namespace App\Enums;

enum ReportReason: string
{
    case Copyright = 'copyright';
    case Inappropriate = 'inappropriate';
    case Misleading = 'misleading';
    case Other = 'other';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }
}
