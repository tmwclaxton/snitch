<?php

namespace App\Enums;

enum PostType: string
{
    case Reel = 'reel';
    case Video = 'video';
    case Carousel = 'carousel';
    case Image = 'image';
    case Text = 'text';
}
