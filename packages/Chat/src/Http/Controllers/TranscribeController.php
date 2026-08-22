<?php

declare(strict_types=1);

namespace Relaticle\Chat\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Laravel\Ai\Transcription;
use Relaticle\Chat\Services\ModelRegistry;

final class TranscribeController
{
    /**
     * Transcribe a push-to-talk recording from the composer. The text is handed
     * back for the user to review and edit: nothing is ever sent on their
     * behalf, so this endpoint touches no conversation state.
     */
    public function __invoke(Request $request, ModelRegistry $models): JsonResponse
    {
        abort_unless($models->voiceInputAvailable(), 404);

        // `mimetypes:` compares the sniffed type, not the browser-declared one,
        // and libmagic reads an audio-only WebM/MP4 container as video/*. Both
        // spellings of each container are listed because that sniff is what a
        // real MediaRecorder upload arrives as.
        //
        // 2 MB is roughly double what the client can produce: it hard-stops at
        // 120s, and 120s of Opus is about 1 MB. The cap is this tight because
        // the SDK triples the bytes in memory, holding the raw upload plus its
        // base64 (Base64Audio::fromUpload) and then base64-decoding the whole
        // thing again for the outbound attach.
        $request->validate([
            'audio' => ['required', 'file', 'max:2048', 'mimetypes:audio/webm,video/webm,audio/mp4,video/mp4'],
        ]);

        /** @var UploadedFile $audio */
        $audio = $request->file('audio');

        return response()->json([
            'text' => Transcription::fromUpload($audio)->generate()->text,
        ]);
    }
}
