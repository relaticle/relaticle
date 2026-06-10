<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Relaticle\Chat\Http\Controllers\ChatController;
use Relaticle\Chat\Http\Controllers\PendingActionController;

Route::middleware(['auth:web'])->group(function (): void {
    Route::get('/chat/mentions', [ChatController::class, 'mentions'])
        ->middleware('throttle:60,1')
        ->name('chat.mentions');
    Route::post('/chat/conversations', [ChatController::class, 'createConversation'])
        ->middleware('throttle:chat-send')
        ->name('chat.conversations.create');
    Route::get('/chat/conversations', [ChatController::class, 'conversations'])->name('chat.conversations');
    Route::delete('/chat/conversations/{conversation}', [ChatController::class, 'destroyConversation'])->name('chat.conversations.destroy');

    Route::post('/chat/actions/{pendingAction}/approve', [PendingActionController::class, 'approve'])->name('chat.actions.approve');
    Route::post('/chat/actions/{pendingAction}/reject', [PendingActionController::class, 'reject'])->name('chat.actions.reject');
    Route::post('/chat/actions/{pendingAction}/restore', [PendingActionController::class, 'restore'])->name('chat.actions.restore');

    Route::post('/chat/conversations/{conversationId}/cancel', [ChatController::class, 'cancel'])
        ->name('chat.cancel');

    Route::post('/chat/conversations/{conversationId}/resume', [ChatController::class, 'resume'])
        ->middleware('throttle:10,1')
        ->name('chat.resume');

    Route::post('/chat/conversations/{conversationId}/rename', [ChatController::class, 'rename'])
        ->name('chat.rename');

    Route::post('/chat/{conversation?}', [ChatController::class, 'send'])
        ->middleware('throttle:chat-send')
        ->name('chat.send');
});
