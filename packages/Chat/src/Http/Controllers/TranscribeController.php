<?php

declare(strict_types=1);

namespace Relaticle\Chat\Http\Controllers;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Laravel\Ai\Exceptions\ProviderConnectionException;
use Laravel\Ai\Exceptions\ProviderOverloadedException;
use Laravel\Ai\Exceptions\RateLimitedException;
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
        // The client pins Opus at 32 kbps and hard-stops at 120s, so the most
        // it can send is 32000 * 120 / 8 = 480 KB. This cap is roughly 4x that.
        // Do not widen it without lowering AUDIO_BITS_PER_SECOND in voice.js to
        // match: the SDK triples the bytes in memory, holding the raw upload
        // plus its base64 (Base64Audio::fromUpload) and then base64-decoding
        // the whole thing again for the outbound attach.
        $unreadable = $this->unreadableMessage();

        $request->validate([
            'audio' => ['bail', 'required', 'file', 'max:2048', 'mimetypes:audio/webm,video/webm,audio/mp4,video/mp4'],
        ], [
            'audio.required' => $unreadable,
            'audio.file' => $unreadable,
            'audio.max' => $unreadable,
            'audio.mimetypes' => $unreadable,
        ]);

        /** @var UploadedFile $audio */
        $audio = $request->file('audio');

        try {
            $text = Transcription::fromUpload($audio)->generate()->text;
        } catch (RequestException $exception) {
            return $this->failedResponse($exception);
        } catch (RateLimitedException $exception) {
            throw_if($this->outOfQuota($exception), $exception);

            return $this->busyResponse();
        } catch (ProviderOverloadedException|ProviderConnectionException|ConnectionException) {
            return $this->busyResponse();
        }

        return response()->json(['text' => $text]);
    }

    private function failedResponse(RequestException $exception): JsonResponse
    {
        $status = $exception->response->status();

        $unreadable = in_array($status, [413, 415], true)
            || ($status === 400 && str_starts_with((string) $exception->response->json('error.message'), 'Audio file'));

        if ($unreadable) {
            $message = $this->unreadableMessage();

            return response()->json(['message' => $message, 'errors' => ['audio' => [$message]]], 422);
        }

        throw_if($status < 500, $exception);

        return $this->busyResponse();
    }

    private function outOfQuota(RateLimitedException $exception): bool
    {
        $previous = $exception->getPrevious();

        return $previous instanceof RequestException
            && $previous->response->json('error.code') === 'insufficient_quota';
    }

    private function unreadableMessage(): string
    {
        return __("That recording couldn't be read. Try again.");
    }

    private function busyResponse(): JsonResponse
    {
        return response()->json(['message' => __('Voice input is busy. Try again in a moment.')], 503);
    }
}
