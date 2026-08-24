<?php

declare(strict_types=1);

use App\Http\Middleware\EnsureHostedWorkspaceAccess;
use Illuminate\Support\Facades\Route;
use Relaticle\Chat\Http\Controllers\ChatController;
use Relaticle\Chat\Http\Controllers\MessageFeedbackController;
use Relaticle\Chat\Http\Controllers\RecordRedirectController;
use Relaticle\Chat\Http\Controllers\TranscribeController;

Route::middleware(['auth:web', EnsureHostedWorkspaceAccess::class])->group(function (): void {
    Route::get('/r/{type}/{id}', RecordRedirectController::class)
        ->middleware('throttle:60,1,record-redirect')
        ->name('chat.record-redirect');

    Route::get('/chat/mentions', [ChatController::class, 'mentions'])
        ->middleware('throttle:60,1,chat-mentions')
        ->name('chat.mentions');
    Route::post('/chat/conversations', [ChatController::class, 'createConversation'])
        ->middleware('throttle:chat-send')
        ->name('chat.conversations.create');
    Route::get('/chat/conversations', [ChatController::class, 'conversations'])
        ->middleware('throttle:60,1,chat-conversations')
        ->name('chat.conversations');
    Route::delete('/chat/conversations/{conversation}', [ChatController::class, 'destroyConversation'])
        ->middleware('throttle:30,1,chat-conversation-delete')
        ->name('chat.conversations.destroy');

    Route::get('/chat/conversations/{conversationId}/search', [ChatController::class, 'searchMessages'])
        ->middleware('throttle:60,1,chat-search')
        ->name('chat.conversations.search');

    Route::post('/chat/conversations/{conversationId}/cancel', [ChatController::class, 'cancel'])
        ->middleware('throttle:30,1,chat-cancel')
        ->name('chat.cancel');

    Route::post('/chat/conversations/{conversationId}/rename', [ChatController::class, 'rename'])
        ->middleware('throttle:30,1,chat-rename')
        ->name('chat.rename');

    Route::post('/chat/conversations/{conversationId}/messages/supersede', [ChatController::class, 'supersedeMessages'])
        ->middleware('throttle:30,1,chat-supersede')
        ->name('chat.messages.supersede');

    // Transcription is unmetered: it reserves no credit, so the limiters are the
    // only ceiling on provider spend. The per-minute one bounds a stuck client,
    // the daily one bounds the account (a human dictating tops out at two or
    // three a minute). Every throttle in this file carries a key prefix (the
    // third argument): the default signature is just the user id, so unprefixed
    // limiters on different routes share one bucket and starve each other
    // (e.g. 10 mention autocompletes used to consume the transcribe allowance).
    Route::post('/chat/transcribe', TranscribeController::class)
        ->middleware(['throttle:10,1,transcribe-minute', 'throttle:60,1440,transcribe-daily', 'throttle:transcribe-team-daily'])
        ->name('chat.transcribe');

    Route::post('/chat/messages/{messageId}/feedback', [MessageFeedbackController::class, 'store'])
        ->middleware('throttle:60,1,chat-feedback')
        ->name('chat.messages.feedback.store');
    Route::delete('/chat/messages/{messageId}/feedback', [MessageFeedbackController::class, 'destroy'])
        ->middleware('throttle:60,1,chat-feedback')
        ->name('chat.messages.feedback.destroy');

    Route::post('/chat/{conversation?}', [ChatController::class, 'send'])
        ->middleware('throttle:chat-send')
        ->name('chat.send');
});
