<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Relaticle\Chat\Http\Controllers\ChatController;
use Relaticle\Chat\Http\Controllers\MessageFeedbackController;
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

    Route::post('/chat/actions/{pendingAction}/approve', [PendingActionController::class, 'approve'])
        ->middleware('throttle:60,1')
        ->name('chat.actions.approve');
    Route::post('/chat/actions/{pendingAction}/reject', [PendingActionController::class, 'reject'])
        ->middleware('throttle:60,1')
        ->name('chat.actions.reject');
    Route::post('/chat/actions/{pendingAction}/restore', [PendingActionController::class, 'restore'])
        ->middleware('throttle:60,1')
        ->name('chat.actions.restore');

    Route::post('/chat/actions/{pendingAction}/items/{index}/approve', [PendingActionController::class, 'approveItem'])
        ->whereNumber('index')
        ->middleware('throttle:60,1')
        ->name('chat.actions.items.approve');
    Route::post('/chat/actions/{pendingAction}/items/{index}/reject', [PendingActionController::class, 'rejectItem'])
        ->whereNumber('index')
        ->middleware('throttle:60,1')
        ->name('chat.actions.items.reject');

    Route::post('/chat/conversations/{conversationId}/cancel', [ChatController::class, 'cancel'])
        ->middleware('throttle:30,1')
        ->name('chat.cancel');

    Route::post('/chat/conversations/{conversationId}/rename', [ChatController::class, 'rename'])
        ->middleware('throttle:30,1')
        ->name('chat.rename');

    Route::post('/chat/conversations/{conversationId}/messages/supersede', [ChatController::class, 'supersedeMessages'])
        ->middleware('throttle:30,1')
        ->name('chat.messages.supersede');

    Route::post('/chat/messages/{messageId}/feedback', [MessageFeedbackController::class, 'store'])
        ->middleware('throttle:60,1')
        ->name('chat.messages.feedback.store');
    Route::delete('/chat/messages/{messageId}/feedback', [MessageFeedbackController::class, 'destroy'])
        ->middleware('throttle:60,1')
        ->name('chat.messages.feedback.destroy');

    Route::post('/chat/{conversation?}', [ChatController::class, 'send'])
        ->middleware('throttle:chat-send')
        ->name('chat.send');
});
