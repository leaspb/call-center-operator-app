<?php

namespace App\Support;

use App\Models\Chat;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Throwable;

class ChatCursor
{
    public static function apply(Builder $query, string $cursor): void
    {
        $decoded = self::decode($cursor);
        $inboundNull = (int) $decoded['inbound_null'];
        $lastMessageAt = self::lastMessageAt($decoded);
        $id = $decoded['id'];

        $query->where(function ($outer) use ($inboundNull, $lastMessageAt, $id) {
            $outer->whereRaw('(CASE WHEN last_inbound_message_at IS NULL THEN 1 ELSE 0 END) > ?', [$inboundNull])
                ->orWhere(function ($sameBucket) use ($inboundNull, $lastMessageAt, $id) {
                    $sameBucket->whereRaw('(CASE WHEN last_inbound_message_at IS NULL THEN 1 ELSE 0 END) = ?', [$inboundNull])
                        ->where(function ($ordered) use ($lastMessageAt, $id) {
                            if ($lastMessageAt === null) {
                                $ordered->whereNull('last_message_at')->where('id', '<', $id);

                                return;
                            }

                            $ordered->where('last_message_at', '<', $lastMessageAt)
                                ->orWhereNull('last_message_at')
                                ->orWhere(function ($tie) use ($lastMessageAt, $id) {
                                    $tie->where('last_message_at', $lastMessageAt)->where('id', '<', $id);
                                });
                        });
                });
        });
    }

    public static function fromChat(Chat $chat): array
    {
        return [
            'inbound_null' => $chat->last_inbound_message_at === null,
            'last_message_at' => $chat->getRawOriginal('last_message_at'),
            'id' => $chat->id,
        ];
    }

    private static function decode(string $cursor): array
    {
        $decoded = json_decode($cursor, true);
        if (
            ! is_array($decoded)
            || ! array_key_exists('id', $decoded)
            || ! array_key_exists('inbound_null', $decoded)
            || ! is_int($decoded['id'])
            || $decoded['id'] < 1
            || ! is_bool($decoded['inbound_null'])
            || ! self::hasValidLastMessageAt($decoded)
        ) {
            throw ValidationException::withMessages(['cursor' => 'Invalid chat cursor']);
        }

        return $decoded;
    }

    private static function lastMessageAt(array $decoded): ?string
    {
        try {
            if (! isset($decoded['last_message_at'])) {
                return null;
            }

            Carbon::parse($decoded['last_message_at']);

            return $decoded['last_message_at'];
        } catch (Throwable) {
            throw ValidationException::withMessages(['cursor' => 'Invalid chat cursor']);
        }
    }

    private static function hasValidLastMessageAt(array $decoded): bool
    {
        return ! array_key_exists('last_message_at', $decoded)
            || is_null($decoded['last_message_at'])
            || is_string($decoded['last_message_at']);
    }
}
