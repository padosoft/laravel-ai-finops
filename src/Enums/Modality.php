<?php

declare(strict_types=1);

namespace Padosoft\LaravelAiFinOps\Enums;

enum Modality: string
{
    case Text = 'text';
    case Image = 'image';
    case Audio = 'audio';
    case Video = 'video';
    case Embedding = 'embedding';
}
