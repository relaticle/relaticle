<?php

declare(strict_types=1);

use Illuminate\Http\UploadedFile;

function fakePdf(string $name, int $kilobytes = 1): UploadedFile
{
    $header = "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n";

    return UploadedFile::fake()->createWithContent($name, str_pad($header, $kilobytes * 1024, ' '));
}
