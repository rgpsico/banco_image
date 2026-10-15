<?php

namespace App\Enums;

enum VariantKind: string
{
    case Thumb = 'thumb';
    case Preview = 'preview';
    case Watermarked = 'watermarked';
    case Poster = 'poster';
}
