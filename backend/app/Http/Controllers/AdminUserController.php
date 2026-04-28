<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\AuditLogger;
use App\Support\AdminUserSafety;
use App\Support\ApiError;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
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
            'password' => ['required', $this->passwordRule()],
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

        return DB::transaction(function () use ($request, $user, $data): JsonResponse {
            $locked = $this->lockAdminChangeSubject($user);
            if (AdminUserSafety::removingLastActiveAdmin($locked, ['role' => $data['role']])) {
                return ApiError::response('At least one active admin must remain', 'LAST_ADMIN_REQUIRED', 409);
            }

            $oldRole = $locked->role;
            $locked->forceFill(['role' => $data['role']])->save();
            $this->audit->log('admin.user_role_changed', $request->user(), 'user', $locked->id, ['old_role' => $oldRole, 'new_role' => $locked->role], $request);

            return response()->json(['user' => $this->payload($locked)]);
        });
    }

    public function resetPassword(Request $request, User $user): JsonResponse
    {
        $data = $request->validate(['password' => ['required', $this->passwordRule()]]);
        $user->forceFill(['password' => Hash::make($data['password'])])->save();
        $user->tokens()->delete();
        $this->audit->log('admin.user_password_reset', $request->user(), 'user', $user->id, [], $request);

        return response()->json(['user' => $this->payload($user)]);
    }

    public function updateStatus(Request $request, User $user): JsonResponse
    {
        $data = $request->validate(['is_active' => ['required', 'boolean']]);

        return DB::transaction(function () use ($request, $user, $data): JsonResponse {
            $locked = $this->lockAdminChangeSubject($user);
            if (AdminUserSafety::removingLastActiveAdmin($locked, ['is_active' => $data['is_active']])) {
                return ApiError::response('At least one active admin must remain', 'LAST_ADMIN_REQUIRED', 409);
            }

            $oldStatus = $locked->is_active;
            $locked->forceFill(['is_active' => $data['is_active']])->save();
            if (! $locked->is_active) {
                $locked->tokens()->delete();
            }
            $this->audit->log('admin.user_status_changed', $request->user(), 'user', $locked->id, ['old_is_active' => $oldStatus, 'new_is_active' => $locked->is_active], $request);

            return response()->json(['user' => $this->payload($locked)]);
        });
    }

    private function lockAdminChangeSubject(User $user): User
    {
        // Serialize admin demotion/deactivation checks so concurrent requests cannot remove all active admins.
        User::query()->where('role', 'admin')->where('is_active', true)->lockForUpdate()->get(['id']);

        return User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
    }

    private function payload(User $user): array
    {
        return ['id' => $user->id, 'name' => $user->name, 'email' => $user->email, 'role' => $user->role, 'is_active' => $user->is_active];
    }

    private function passwordRule(): Password
    {
        return Password::min(12)->letters()->numbers();
    }
}
