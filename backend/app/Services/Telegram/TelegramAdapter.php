<?php

namespace App\Services\Telegram;

use App\Models\Chat;
use App\Models\Message;

interface TelegramAdapter
{
    public function sendText(Chat $chat, Message $message): TelegramSendResult;
}
