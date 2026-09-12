<?php

declare(strict_types=1);

return [
    'errors' => [
        'too_large' => 'The file is larger than 10 MB.',
        'mime_not_allowed' => 'Files of type :mime are not accepted. Allowed: pdf, doc, docx, jpeg, png, gif, webp.',
        'unreachable' => 'The URL could not be fetched.',
        'url_not_allowed' => 'Only public https URLs on port 443 can be fetched.',
        'not_found' => 'The upload was not found or has expired.',
        'rate_limited' => 'Upload limit reached: 60 uploads per hour per workspace. Try again later.',
        'invalid_base64' => 'The base64 payload could not be decoded.',
    ],
];
