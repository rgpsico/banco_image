<?php

namespace App\Enums;

enum VariantKind: string
{
    case Thumb = 'thumb';
    case Preview = 'preview';
    case Watermarked = 'watermarked';
    case WatermarkedThumb = 'wm_thumb';
    case Poster = 'poster';
    case GalleryThumb = 'g_thumb'; // galeria pública: miniatura (com marca d'água, se ligada)
    case GalleryView = 'g_view';   // galeria pública: foto para ver em tela cheia
}
