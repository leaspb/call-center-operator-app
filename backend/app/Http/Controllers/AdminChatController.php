<?php

namespace App\Http\Controllers;

use App\Models\Chat;
use App\Models\User;
use App\Services\ChatAssignmentService;
use App\Services\ChatPresenter;
use App\Support\ApiError;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AdminChatController extends Controller
{
    public function __construct(private readonly ChatAssignmentService $assignment, private readonly ChatPresenter $presenter) {}

    public function assign(Request $request, Chat $chat): JsonResponse
    {
        $data = $request->validate([
            'operator_id' => [
                'required',
                Rule::exists('users', 'id')->where('role', 'operator')->where('is_active', true),
            ],
        ]);
        $operator = User::query()
            ->where('role', 'operator')
            ->where('is_active', true)
            ->findOrFail($data['operator_id']);
        $chat = $this->assignment->adminAssign($chat, $request->user(), $operator);

        return response()->json(['chat' => $this->presenter->chat($chat, $request->user())]);
    }

    public function forceRelease(Request $request, Chat $chat): JsonResponse
    {
        $released = $this->assignment->release($chat, $request->user(), 'chat.force_released');
        if (is_array($released)) {
            return ApiError::response($released['message'], $released['code'], $released['status']);
        }

        return response()->json(['chat' => $this->presenter->chat($released, $request->user())]);
    }
}
