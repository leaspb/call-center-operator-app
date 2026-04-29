<?php

namespace App\Http\Controllers;

use App\Models\Chat;
use App\Services\AiSuggestionService;
use App\Support\ApiError;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AiSuggestionController extends Controller
{
    public function __invoke(Request $request, Chat $chat, AiSuggestionService $service): JsonResponse
    {
        $user = $request->user();

        if (! $user->isAdmin() && $chat->assigned_operator_id !== $user->id) {
            return ApiError::response('CHAT_NOT_OWNED', 'Only the assigned operator can request a suggestion', 403);
        }

        $suggestion = $service->suggest($chat);

        if ($suggestion === null) {
            return response()->json(['ok' => false, 'code' => 'AI_UNAVAILABLE', 'message' => 'AI suggestion is not available'], 503);
        }

        return response()->json(['ok' => true, 'suggestion' => $suggestion]);
    }
}
