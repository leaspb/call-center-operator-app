<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class AdminUserController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(): JsonResponse
    {
        return response()->json([
            'data' => User::query()->orderBy('id')->get()->map(fn (User $user) => $this->payload($user)),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', Password::min(8)],
            'role' => ['sometimes', Rule::in(['admin', 'operator'])],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => $data['password'],
            'role' => $data['role'] ?? 'operator',
            'is_active' => $data['is_active'] ?? true,
        ]);
        $this->audit->log('admin.user_created', $request->user(), 'user', $user->id, ['role' => $user->role], $request);

        return response()->json(['user' => $this->payload($user)], 201);
    }

    public function updateRole(Request $request, User $user): JsonResponse
    {
        $data = $request->validate(['role' => ['required', Rule::in(['admin', 'operator'])]]);
        $oldRole = $user->role;
        $user->forceFill(['role' => $data['role']])->save();
        $this->audit->log('admin.user_role_changed', $request->user(), 'user', $user->id, ['old_role' => $oldRole, 'new_role' => $user->role], $request);

        return response()->json(['user' => $this->payload($user)]);
    }

    public function resetPassword(Request $request, User $user): JsonResponse
    {
        $data = $request->validate(['password' => ['required', Password::min(8)]]);
        $user->forceFill(['password' => $data['password']])->save();
        $user->tokens()->delete();
        $this->audit->log('admin.user_password_reset', $request->user(), 'user', $user->id, [], $request);

        return response()->json(['user' => $this->payload($user)]);
    }

    private function payload(User $user): array
    {
        return ['id' => $user->id, 'name' => $user->name, 'email' => $user->email, 'role' => $user->role, 'is_active' => $user->is_active];
    }
}
