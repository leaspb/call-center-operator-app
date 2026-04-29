<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chats', function (Blueprint $table) {
            $table->index(['status', 'last_message_at', 'id'], 'chats_status_last_message_id_index');
            $table->index(['assigned_operator_id', 'last_message_at', 'id'], 'chats_operator_last_message_id_index');
        });

        Schema::table('messages', function (Blueprint $table) {
            $table->index(['chat_id', 'direction', 'id'], 'messages_chat_direction_id_index');
            $table->index('external_message_id', 'messages_external_message_id_index');
        });

        Schema::table('audit_logs', function (Blueprint $table) {
            $table->index(['event_type', 'id'], 'audit_logs_event_type_id_index');
            $table->index(['actor_user_id', 'id'], 'audit_logs_actor_user_id_id_index');
        });
    }

    public function down(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->dropIndex('audit_logs_actor_user_id_id_index');
            $table->dropIndex('audit_logs_event_type_id_index');
        });

        Schema::table('messages', function (Blueprint $table) {
            $table->dropIndex('messages_external_message_id_index');
            $table->dropIndex('messages_chat_direction_id_index');
        });

        Schema::table('chats', function (Blueprint $table) {
            $table->dropIndex('chats_operator_last_message_id_index');
            $table->dropIndex('chats_status_last_message_id_index');
        });
    }
};
