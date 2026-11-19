<?php

declare(strict_types=1);

use Illuminate\Http\UploadedFile;

function fakePdf(string $name, int $kilobytes = 1): UploadedFile
{
    $header = "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n";

    return UploadedFile::fake()->createWithContent($name, str_pad($header, $kilobytes * 1024, ' '));
}

function emailAttachmentFixture(string $kind): string
{
    $zip = static function (array $entries): string {
        $path = tempnam(sys_get_temp_dir(), 'att');
        $archive = new ZipArchive;
        $archive->open($path, ZipArchive::OVERWRITE);

        foreach ($entries as $name => $content) {
            $archive->addFromString($name, $content);
        }

        $archive->close();
        $bytes = (string) file_get_contents($path);
        unlink($path);

        return $bytes;
    };

    return match ($kind) {
        'docx' => $zip(['[Content_Types].xml' => '<Types/>', 'word/document.xml' => '<w:document/>']),
        'xlsx' => $zip(['[Content_Types].xml' => '<Types/>', 'xl/workbook.xml' => '<workbook/>']),
        'odt' => $zip(['mimetype' => 'application/vnd.oasis.opendocument.text', 'content.xml' => '<office/>']),
        'pdf' => "%PDF-1.4\n1 0 obj\n<<>>\nendobj\ntrailer\n<<>>\n%%EOF\n",
        'zip' => $zip(['notes.txt' => 'hello']),
        'doc' => "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1".str_repeat("\x00", 600),
        'csv' => "name,email\nJane,jane@example.com\n",
        'txt' => "Meeting notes\nShip on Friday.\n",
        'txt-source-like' => "#include notes\nShip on Friday.\n",
        'ics' => "BEGIN:VCALENDAR\nVERSION:2.0\nBEGIN:VEVENT\nSUMMARY:Call\nEND:VEVENT\nEND:VCALENDAR\n",
        'png' => base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg=='),
        'jpg' => base64_decode('/9j/4AAQSkZJRgABAQEASABIAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////wgALCAABAAEBAREA/8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABPxA='),
    };
}
