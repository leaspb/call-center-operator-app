<?php

namespace App\Http\Controllers;

use App\Models\Chat;
use App\Models\User;
use App\Services\ChatAssignmentService;
use App\Services\ChatPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminChatController extends Controller
{
    public function __construct(private readonly ChatAssignmentService $assignment, private readonly ChatPresenter $presenter) {}

    public function assign(Request $request, Chat $chat): JsonResponse
    {
        $data = $request->validate(['operator_id' => ['required', 'exists:users,id']]);
        $operator = User::query()->where('is_active', true)->findOrFail($data['operator_id']);
        $chat = $this->assignment->adminAssign($chat, $request->user(), $operator);

        return response()->json(['chat' => $this->presenter->chat($chat, $request->user())]);
    }

    public function forceRelease(Request $request, Chat $chat): JsonResponse
    {
        $released = $this->assignment->release($chat, $request->user(), 'chat.force_released');

        return response()->json(['chat' => $this->presenter->chat($released, $request->user())]);
    }
}
