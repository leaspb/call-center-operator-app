<?php

namespace App\Http\Controllers;

use App\Models\Chat;
use App\Services\AuditLogger;
use App\Services\ChatAssignmentService;
use App\Services\ChatPresenter;
use App\Services\OutboundMessageService;
use App\Support\ApiError;
use App\Support\ChatCursor;
use App\Support\ChatVisibility;
use App\Support\OperatorEventRecipients;
use App\Support\OperatorNotifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ChatController extends Controller
{
    public function __construct(
        private readonly ChatPresenter $presenter,
        private readonly ChatAssignmentService $assignment,
        private readonly OutboundMessageService $outbound,
        private readonly AuditLogger $audit,
        private readonly OperatorNotifier $notifier,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'filter' => ['sometimes', Rule::in(['all', 'unassigned', 'assigned_to_me', 'assigned_to_others', 'unread', 'closed'])],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'cursor' => ['sometimes', 'string', 'max:1024'],
        ]);
        $filter = $data['filter'] ?? 'all';
        $limit = (int) ($data['limit'] ?? 50);
        $user = $request->user();
        $query = Chat::query()
            ->with(['channel', 'externalUser', 'assignedOperator'])
            ->with(['messages' => fn ($q) => $q->latest('id')->limit(1)])
            ->withCount(['messages as unread_count_for_viewer' => fn ($q) => $q
                ->where('direction', 'inbound')
                ->whereDoesntHave('reads', fn ($r) => $r->where('user_id', $user->id)),
            ]);

        match ($filter) {
            'unassigned' => $query->where('status', 'open')->whereNull('assigned_operator_id'),
            'assigned_to_me' => $query->where('assigned_operator_id', $user->id),
            'assigned_to_others' => $query->whereNotNull('assigned_operator_id')->where('assigned_operator_id', '!=', $user->id),
            'closed' => $query->where('status', 'closed'),
            'unread' => $query->whereHas('messages', fn ($q) => $q->where('direction', 'inbound')->whereDoesntHave('reads', fn ($r) => $r->where('user_id', $user->id))),
            default => null,
        };
        ChatVisibility::constrainVisibleTo($query, $user);

        if (isset($data['cursor'])) {
            ChatCursor::apply($query, $data['cursor']);
        }

        $chats = $query->orderByRaw('CASE WHEN last_inbound_message_at IS NULL THEN 1 ELSE 0 END')
            ->orderByDesc('last_message_at')
            ->orderByDesc('id')
            ->limit($limit + 1)
            ->get();
        $visible = $chats->take($limit);
        $next = $chats->count() > $limit ? ChatCursor::fromChat($visible->last()) : null;
        $items = $visible->map(fn (Chat $chat) => $this->presenter->chat($chat, $user));

        return response()->json(['data' => $items, 'next_cursor' => $next]);
    }

    public function show(Request $request, Chat $chat): JsonResponse
    {
        if (! ChatVisibility::canView($chat, $request->user())) {
            return ApiError::response('Cannot view this chat', 'CHAT_NOT_VISIBLE', 403);
        }

        return response()->json(['chat' => $this->presenter->chat($chat->load(['channel', 'externalUser', 'assignedOperator']), $request->user(), true)]);
    }

    public function messages(Request $request, Chat $chat): JsonResponse
    {
        if (! ChatVisibility::canView($chat, $request->user())) {
            return ApiError::response('Cannot view this chat', 'CHAT_NOT_VISIBLE', 403);
        }

        $data = $request->validate([
            'limit' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'before_id' => ['sometimes', 'integer', 'min:1'],
        ]);
        $limit = (int) ($data['limit'] ?? 50);
        $query = $chat->messages()->with(['deliveries', 'reads.user'])->latest('id');
        if (isset($data['before_id'])) {
            $query->where('id', '<', $data['before_id']);
        }
        $messages = $query->limit($limit + 1)->get();
        $visible = $messages->take($limit);
        $next = $messages->count() > $limit ? $visible->last()->id : null;

        return response()->json(['data' => $visible->map(fn ($m) => $this->presenter->message($m))->values(), 'next_cursor' => $next]);
    }

    public function storeMessage(Request $request, Chat $chat): JsonResponse
    {
        $data = $request->validate(['body' => ['required', 'string', 'max:4096']]);
        $result = $this->outbound->create($chat, $request->user(), $data['body']);
        if (! ($result['ok'] ?? false)) {
            return ApiError::response($result['message'], $result['code'], $result['status']);
        }

        $presented = $this->presenter->message($result['message']);
        return response()->json([
            'message' => $presented,
            'delivery' => $presented['delivery'],
        ], 201);
    }

    public function assign(Request $request, Chat $chat): JsonResponse
    {
        $result = $this->assignment->assignToSelf($chat, $request->user());
        if (! ($result['ok'] ?? false)) {
            return ApiError::response($result['message'], $result['code'], $result['status'], [
                'assigned_operator' => isset($result['chat']) ? $this->presenter->chat($result['chat'], $request->user())['assigned_operator'] : null,
            ]);
        }

        return response()->json(['chat' => $this->presenter->chat($result['chat'], $request->user())]);
    }

    public function release(Request $request, Chat $chat): JsonResponse
    {
        $result = $this->assignment->release($chat, $request->user());
        if (is_array($result) && ! ($result['ok'] ?? false)) {
            return ApiError::response($result['message'], $result['code'], $result['status']);
        }

        return response()->json(['chat' => $this->presenter->chat($result, $request->user())]);
    }

    public function close(Request $request, Chat $chat): JsonResponse
    {
        $closed = DB::transaction(function () use ($chat, $request) {
            $locked = Chat::query()->whereKey($chat->id)->lockForUpdate()->firstOrFail();
            if (! $request->user()->isAdmin() && ! $locked->isAssignedTo($request->user())) {
                return ['ok' => false, 'message' => 'Only owner or admin can close chat'];
            }
            $locked->forceFill([
                'status' => 'closed',
                'assigned_operator_id' => null,
                'assigned_by_user_id' => null,
                'assigned_at' => null,
                'assignment_last_activity_at' => null,
            ])->save();
            $this->audit->log('chat.closed', $request->user(), 'chat', $locked->id, [], $request);

            return ['ok' => true, 'chat' => $locked->fresh(['assignedOperator', 'channel', 'externalUser'])];
        });
        if (! $closed['ok']) {
            return ApiError::response($closed['message'], 'CHAT_NOT_OWNED', 403);
        }

        $this->notifier->notify('chat.closed', ['chat_id' => $closed['chat']->id], OperatorEventRecipients::all());

        return response()->json(['chat' => $this->presenter->chat($closed['chat'], $request->user())]);
    }

    public function heartbeat(Request $request, Chat $chat): JsonResponse
    {
        $result = $this->assignment->heartbeat($chat, $request->user());
        if (is_array($result) && ! ($result['ok'] ?? false)) {
            return ApiError::response($result['message'], $result['code'], $result['status']);
        }

        return response()->json(['chat' => $this->presenter->chat($result, $request->user())]);
    }
}
