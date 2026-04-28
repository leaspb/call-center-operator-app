<?php

namespace App\Services\Telegram;

use App\Models\Chat;
use App\Models\Message;

class FakeTelegramAdapter implements TelegramAdapter
{
    public function sendText(Chat $chat, Message $message): TelegramSendResult
    {
        return new TelegramSendResult(true, 'fake-'.$message->id);
    }
}
