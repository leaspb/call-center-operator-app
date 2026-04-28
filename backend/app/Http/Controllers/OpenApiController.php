<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;

class OpenApiController extends Controller
{
    public function __invoke(): JsonResponse
    {
        return response()->json([
            'openapi' => '3.0.3',
            'info' => ['title' => 'Call Center Operator API', 'version' => '1.0.0-mvp'],
            'servers' => [['url' => '/api/v1']],
            'components' => [
                'securitySchemes' => ['bearerAuth' => ['type' => 'http', 'scheme' => 'bearer']],
                'schemas' => [
                    'Error' => ['type' => 'object', 'required' => ['message', 'code', 'details'], 'properties' => ['message' => ['type' => 'string'], 'code' => ['type' => 'string'], 'details' => ['type' => 'object']]],
                    'User' => ['type' => 'object', 'properties' => ['id' => ['type' => 'integer'], 'name' => ['type' => 'string'], 'email' => ['type' => 'string'], 'role' => ['type' => 'string', 'enum' => ['admin', 'operator']], 'is_active' => ['type' => 'boolean']]],
                    'Chat' => ['type' => 'object', 'properties' => ['id' => ['type' => 'integer'], 'status' => ['type' => 'string', 'enum' => ['open', 'assigned', 'closed']], 'assignment_state' => ['type' => 'string'], 'assigned_operator' => ['nullable' => true], 'external_user' => ['type' => 'object'], 'channel' => ['type' => 'object']]],
                    'Message' => ['type' => 'object', 'properties' => ['id' => ['type' => 'integer'], 'chat_id' => ['type' => 'integer'], 'direction' => ['type' => 'string', 'enum' => ['inbound', 'outbound']], 'type' => ['type' => 'string', 'enum' => ['text', 'unsupported_message']], 'body' => ['type' => 'string', 'nullable' => true], 'delivery_status' => ['type' => 'string', 'nullable' => true], 'read_by' => ['type' => 'array']]],
                ],
            ],
            'paths' => $this->paths(),
        ]);
    }

    private function paths(): array
    {
        $ok = ['200' => ['description' => 'OK']];
        $created = ['201' => ['description' => 'Created']];
        $noContent = ['200' => ['description' => 'OK']];
        $secured = [['bearerAuth' => []]];

        return [
            '/auth/register' => ['post' => ['summary' => 'Bootstrap first admin', 'responses' => $created]],
            '/auth/login' => ['post' => ['summary' => 'Login and issue Sanctum token', 'responses' => $ok]],
            '/auth/logout' => ['post' => ['security' => $secured, 'summary' => 'Logout current token', 'responses' => $noContent]],
            '/me' => ['get' => ['security' => $secured, 'summary' => 'Current user', 'responses' => $ok]],
            '/admin/users' => ['get' => ['security' => $secured, 'summary' => 'List users', 'responses' => $ok], 'post' => ['security' => $secured, 'summary' => 'Create operator/admin', 'responses' => $created]],
            '/admin/users/{userId}/role' => ['patch' => ['security' => $secured, 'summary' => 'Change user role', 'responses' => $ok]],
            '/admin/users/{userId}/reset-password' => ['post' => ['security' => $secured, 'summary' => 'Reset user password', 'responses' => $ok]],
            '/chats' => ['get' => ['security' => $secured, 'summary' => 'Paginated chat list with filters', 'responses' => $ok]],
            '/chats/{chatId}' => ['get' => ['security' => $secured, 'summary' => 'Chat details', 'responses' => $ok]],
            '/chats/{chatId}/messages' => ['get' => ['security' => $secured, 'summary' => 'Cursor-paginated message history', 'responses' => $ok], 'post' => ['security' => $secured, 'summary' => 'Create outbound message using transactional outbox', 'responses' => $created]],
            '/chats/{chatId}/assign' => ['post' => ['security' => $secured, 'summary' => 'Atomically assign chat to current operator', 'responses' => $ok + ['409' => ['description' => 'Chat already assigned']]]],
            '/chats/{chatId}/release' => ['post' => ['security' => $secured, 'summary' => 'Release owned chat', 'responses' => $ok]],
            '/admin/chats/{chatId}/assign' => ['post' => ['security' => $secured, 'summary' => 'Admin assigns chat to operator', 'responses' => $ok]],
            '/admin/chats/{chatId}/force-release' => ['post' => ['security' => $secured, 'summary' => 'Admin force release', 'responses' => $ok]],
            '/chats/{chatId}/close' => ['post' => ['security' => $secured, 'summary' => 'Close chat', 'responses' => $ok]],
            '/chats/{chatId}/heartbeat' => ['post' => ['security' => $secured, 'summary' => 'Refresh assignment activity for 10-minute auto-release', 'responses' => $ok]],
            '/messages/{messageId}/read' => ['post' => ['security' => $secured, 'summary' => 'Mark inbound message read by current operator', 'responses' => $ok]],
            '/deliveries/{deliveryId}/retry' => ['post' => ['security' => $secured, 'summary' => 'Manual retry failed outbound delivery', 'responses' => $ok]],
            '/telegram/webhook' => ['post' => ['summary' => 'Telegram webhook with X-Telegram-Bot-Api-Secret-Token', 'responses' => $ok]],
            '/audit-log' => ['get' => ['security' => $secured, 'summary' => 'Admin audit log', 'responses' => $ok]],
            '/dev/telegram/updates/simulate' => ['post' => ['summary' => 'Local/dev replay of Telegram update JSON', 'responses' => $ok]],
            '/openapi.json' => ['get' => ['summary' => 'OpenAPI contract', 'responses' => $ok]],
        ];
    }
}
