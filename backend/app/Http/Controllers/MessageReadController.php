<?php

namespace App\Http\Controllers;

use App\Models\Message;
use App\Services\ChatPresenter;
use App\Support\ApiError;
use App\Support\ChatVisibility;
use App\Support\OperatorEventRecipients;
use App\Support\OperatorNotifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MessageReadController extends Controller
{
    public function __construct(
        private readonly ChatPresenter $presenter,
        private readonly OperatorNotifier $notifier,
    ) {}

    public function store(Request $request, Message $message): JsonResponse
    {
        $message->loadMissing('chat');
        $chat = $message->chat;
        if (! ChatVisibility::canView($chat, $request->user())) {
            return ApiError::response('Cannot view this chat', 'CHAT_NOT_VISIBLE', 403);
        }

        if ($message->direction !== 'inbound') {
            return ApiError::response('Only inbound messages have operator read receipts', 'READ_RECEIPT_INBOUND_ONLY', 422);
        }

        $message->reads()->updateOrCreate(
            ['user_id' => $request->user()->id],
            ['read_at' => now()]
        );
        $recipients = $chat->assigned_operator_id !== null
            ? OperatorEventRecipients::forUsers([$chat->assigned_operator_id, $request->user()->id])
            : OperatorEventRecipients::all();
        $this->notifier->notify('message.read', [
            'message_id' => $message->id,
            'chat_id' => $message->chat_id,
            'user_id' => $request->user()->id,
        ], $recipients);

        return response()->json(['message' => $this->presenter->message($message->fresh(['deliveries', 'reads.user']))]);
    }
}
