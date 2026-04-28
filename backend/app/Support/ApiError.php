<?php

namespace App\Support;

use Illuminate\Http\JsonResponse;

class ApiError
{
    public static function response(string $message, string $code, int $status, array $details = []): JsonResponse
    {
        return response()->json([
            'message' => $message,
            'code' => $code,
            'details' => (object) $details,
        ], $status);
    }
}
