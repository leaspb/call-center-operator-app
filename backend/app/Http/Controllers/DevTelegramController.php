<?php

namespace App\Http\Controllers;

use App\Services\ChatPresenter;
use App\Services\TelegramUpdateProcessor;
use App\Support\ApiError;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DevTelegramController extends Controller
{
    public function __construct(private readonly TelegramUpdateProcessor $processor, private readonly ChatPresenter $presenter) {}

    public function store(Request $request): JsonResponse
    {
        if (! app()->environment(['local', 'testing']) && ! config('app.debug')) {
            return ApiError::response('Dev Telegram replay endpoint is disabled', 'DEV_ENDPOINT_DISABLED', 403);
        }

        $result = $this->processor->process($request->all());
        if (! ($result['ok'] ?? false)) {
            return ApiError::response($result['message'], $result['code'], $result['status']);
        }

        return response()->json([
            'ok' => true,
            'duplicate' => $result['duplicate'] ?? false,
            'ignored' => $result['ignored'] ?? false,
            'chat' => isset($result['chat']) ? $this->presenter->chat($result['chat']) : null,
            'message' => isset($result['message']) ? $this->presenter->message($result['message']) : null,
        ]);
    }
}
