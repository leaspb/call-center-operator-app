<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\AuditLogger;
use App\Support\ApiError;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

class AuthController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function bootstrapStatus(): JsonResponse
    {
        return response()->json(['registration_available' => ! User::query()->exists()]);
    }

    public function register(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'confirmed', $this->passwordRule()],
        ]);

        if (User::query()->exists()) {
            return ApiError::response('Registration is closed after bootstrap admin is created', 'REGISTRATION_CLOSED', 403);
        }

        try {
            $user = DB::transaction(function () use ($data) {
                if (User::query()->lockForUpdate()->exists()) {
                    return null;
                }

                $user = User::create([
                    'name' => $data['name'],
                    'email' => $data['email'],
                    'password' => $data['password'],
                    'role' => 'admin',
                    'is_active' => true,
                ]);
                $user->forceFill(['bootstrap_admin_key' => 'first-admin'])->save();

                return $user;
            });
        } catch (QueryException $exception) {
            if ($exception->getCode() !== '23000') {
                throw $exception;
            }

            return ApiError::response('Registration is closed after bootstrap admin is created', 'REGISTRATION_CLOSED', 403);
        }
        if (! $user) {
            return ApiError::response('Registration is closed after bootstrap admin is created', 'REGISTRATION_CLOSED', 403);
        }
        $token = $this->createToken($user);
        $this->audit->log('auth.registered_first_admin', $user, 'user', $user->id, [], $request);

        return response()->json(['token' => $token, 'user' => $this->userPayload($user)], 201);
    }

    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = User::where('email', $data['email'])->first();
        if (! $user || ! $user->is_active || ! Hash::check($data['password'], $user->password)) {
            return ApiError::response('Invalid credentials', 'INVALID_CREDENTIALS', 401);
        }

        $token = $this->createToken($user);
        $this->audit->log('auth.login', $user, 'user', $user->id, [], $request);

        return response()->json(['token' => $token, 'user' => $this->userPayload($user)]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()?->delete();
        $this->audit->log('auth.logout', $request->user(), 'user', $request->user()->id, [], $request);

        return response()->json(['message' => 'Logged out']);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json(['user' => $this->userPayload($request->user())]);
    }

    private function userPayload(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role,
            'is_active' => $user->is_active,
        ];
    }

    private function createToken(User $user): string
    {
        return $user->createToken('api', ['*'], now()->addMinutes((int) config('auth.api_token_ttl_minutes', 480)))->plainTextToken;
    }

    private function passwordRule(): Password
    {
        return Password::min(6)->letters()->numbers();
    }
}
