<?php

namespace App\Enums;

enum VariantKind: string
{
    case Thumb = 'thumb';
    case Preview = 'preview';
    case Watermarked = 'watermarked';
    case WatermarkedThumb = 'wm_thumb';
    case Poster = 'poster';
}
