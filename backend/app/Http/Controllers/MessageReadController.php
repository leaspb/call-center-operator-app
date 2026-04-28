<?php

namespace App\Http\Controllers;

use App\Events\OperatorEvent;
use App\Models\Message;
use App\Services\ChatPresenter;
use App\Support\ApiError;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MessageReadController extends Controller
{
    public function __construct(private readonly ChatPresenter $presenter) {}

    public function store(Request $request, Message $message): JsonResponse
    {
        if ($message->direction !== 'inbound') {
            return ApiError::response('Only inbound messages have operator read receipts', 'READ_RECEIPT_INBOUND_ONLY', 422);
        }

        $message->reads()->updateOrCreate(
            ['user_id' => $request->user()->id],
            ['read_at' => now()]
        );
        event(new OperatorEvent('message.read', ['message_id' => $message->id, 'chat_id' => $message->chat_id, 'user_id' => $request->user()->id]));

        return response()->json(['message' => $this->presenter->message($message->fresh(['deliveries', 'reads.user']))]);
    }
}
