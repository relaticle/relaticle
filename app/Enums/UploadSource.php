<?php

declare(strict_types=1);

namespace App\Enums;

enum UploadSource: string
{
    case Panel = 'panel';
    case Url = 'url';
    case Base64 = 'base64';
    case SignedPut = 'signed_put';
}
