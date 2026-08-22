<?php

declare(strict_types=1);

use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Laravel\Ai\Prompts\TranscriptionPrompt;
use Laravel\Ai\Transcription;
use Relaticle\Chat\Http\Controllers\TranscribeController;
use Relaticle\Chat\Services\ModelRegistry;

mutates(TranscribeController::class, ModelRegistry::class);

/**
 * Real recordings, not `UploadedFile::fake()`: `mimetypes:` compares the type
 * libmagic sniffs out of the bytes, and a fake file declares its type instead
 * of carrying any. An audio-only WebM (Chrome/Firefox MediaRecorder) sniffs as
 * video/webm and an audio-only MP4 (Safari) as video/mp4, so an allowlist
 * written from the browser-declared types rejects every real recording. Only a
 * real container catches that.
 */
function audioUpload(string $fixture = 'recording.webm'): UploadedFile
{
    return new UploadedFile(base_path("tests/fixtures/audio/{$fixture}"), $fixture, null, null, true);
}

beforeEach(function (): void {
    $this->user = User::factory()->withPersonalTeam()->create();
    $this->actingAs($this->user);
    Filament::setTenant($this->user->currentTeam);
});

it('rejects unauthenticated requests', function (): void {
    auth()->logout();

    $this->postJson(route('chat.transcribe'))->assertUnauthorized();
});

it('404s when voice input is disabled', function (): void {
    config(['chat.voice_enabled' => false]);

    $this->postJson(route('chat.transcribe'), ['audio' => audioUpload()])->assertNotFound();
});

it('404s when the transcription provider has no key', function (): void {
    config(['ai.providers.openai.key' => null]);

    $this->postJson(route('chat.transcribe'), ['audio' => audioUpload()])->assertNotFound();
});

it('422s when no audio is uploaded', function (): void {
    $this->postJson(route('chat.transcribe'))
        ->assertStatus(422)
        ->assertJsonValidationErrors('audio');
});

it('422s when the audio exceeds the size cap', function (): void {
    $this->postJson(route('chat.transcribe'), [
        'audio' => UploadedFile::fake()->create('recording.webm', 2049, 'audio/webm'),
    ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('audio');
});

it('422s when the upload is not audio', function (): void {
    $this->postJson(route('chat.transcribe'), [
        'audio' => new UploadedFile(base_path('tests/fixtures/imports/companies.csv'), 'recording.webm', null, null, true),
    ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('audio');
});

it('returns the transcribed text', function (): void {
    Transcription::fake(['Schedule a follow up with Acme next Tuesday']);

    $this->postJson(route('chat.transcribe'), ['audio' => audioUpload()])
        ->assertOk()
        ->assertExactJson(['text' => 'Schedule a follow up with Acme next Tuesday']);

    // Asserts on the bytes that actually reached the SDK, so a controller that
    // forwarded the wrong file, or returned a hardcoded string without calling
    // the provider at all, fails here.
    Transcription::assertGenerated(fn (TranscriptionPrompt $prompt): bool => $prompt->audio->content() === file_get_contents(base_path('tests/fixtures/audio/recording.webm')));
});

it('accepts every container a browser MediaRecorder produces', function (string $fixture): void {
    Transcription::fake(['ok']);

    $this->postJson(route('chat.transcribe'), ['audio' => audioUpload($fixture)])->assertOk();
})->with([
    'chrome webm/opus' => 'recording.webm',
    'safari mp4/aac' => 'recording.m4a',
]);

it('throttles after 10 requests a minute', function (): void {
    Transcription::fake(['ok']);
    Cache::flush();

    for ($i = 0; $i < 10; $i++) {
        $this->postJson(route('chat.transcribe'), ['audio' => audioUpload()])->assertOk();
    }

    $this->postJson(route('chat.transcribe'), ['audio' => audioUpload()])->assertStatus(429);
});
