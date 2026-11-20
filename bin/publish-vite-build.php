<?php

declare(strict_types=1);

$staged = 'public/build-next';
$active = 'public/build';

$manifestFiles = static function (string $directory): array {
    $entries = json_decode(file_get_contents($directory.'/manifest.json'), true, flags: JSON_THROW_ON_ERROR);
    $files = [];

    foreach ($entries as $entry) {
        foreach ([$entry['file'], ...($entry['css'] ?? []), ...($entry['assets'] ?? [])] as $file) {
            $files[$file] = true;
        }
    }

    return $files;
};

$next = $manifestFiles($staged);
foreach ($next as $file => $_) {
    if (! is_file($staged.'/'.$file)) {
        throw new RuntimeException("Missing Vite build output: {$file}");
    }
}

if (! is_dir($active) && ! mkdir($active)) {
    throw new RuntimeException('Cannot create the Vite build directory.');
}

$hasManifest = is_file($active.'/manifest.json');
$changed = ! $hasManifest || hash_file('sha256', $active.'/manifest.json') !== hash_file('sha256', $staged.'/manifest.json');

if ($hasManifest && $changed) {
    foreach (array_diff_key($manifestFiles($active), $next) as $file => $_) {
        if (! is_file($active.'/'.$file)) {
            fwrite(STDERR, "Previous Vite asset already missing: {$file}\n");

            continue;
        }

        $destination = $staged.'/'.$file;
        if (! is_dir(dirname($destination))) {
            mkdir(dirname($destination), 0755, true);
        }
        if (! link($active.'/'.$file, $destination)) {
            throw new RuntimeException("Cannot retain the previous Vite asset: {$file}");
        }
    }
}

passthru('rsync -a --exclude=/manifest.json public/build-next/ public/build/', $status);
if ($status !== 0 || ! rename($staged.'/manifest.json', $active.'/manifest.json')) {
    exit(1);
}

if ($changed) {
    passthru('rsync -a --delete public/build-next/assets/ public/build/assets/', $status);
    exit($status);
}
