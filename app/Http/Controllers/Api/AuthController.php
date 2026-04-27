<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\TelegramNotifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function register(Request $request, TelegramNotifier $telegramNotifier): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'responsible_name' => ['nullable', 'string', 'max:255'],
            'responsible_phone' => ['nullable', 'string', 'max:30'],
            'vat_number' => ['nullable', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'confirmed', Password::min(8)->mixedCase()->numbers()],
        ]);

        $user = User::query()->create([
            'name' => $data['name'],
            'responsible_name' => $data['responsible_name'] ?? null,
            'responsible_phone' => $data['responsible_phone'] ?? null,
            'vat_number' => $data['vat_number'] ?? null,
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
            'role' => 'company',
            'approval_status' => 'pending',
            'approved_at' => null,
        ]);

        AuditLog::record('company.registered', $user, 'Societa registrata', actor: $user, company: $user);
        $telegramNotifier->notifyCompanyRegistered($user);

        return response()->json([
            'message' => 'Registrazione effettuata. Attendi l approvazione dell admin prima di accedere.',
            'user' => $this->userPayload($user),
        ], 201);
    }

    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = User::query()
            ->where('email', $credentials['email'])
            ->where('role', 'company')
            ->first();

        if (! $user || ! Hash::check($credentials['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['Credenziali non valide.'],
            ]);
        }

        if ($user->approval_status !== 'approved') {
            throw ValidationException::withMessages([
                'email' => ['Account in attesa di approvazione da parte dell amministratore.'],
            ]);
        }

        $token = Str::random(80);

        $user->forceFill([
            'api_token' => hash('sha256', $token),
        ])->save();

        return response()->json([
            'token' => $token,
            'user' => $this->userPayload($user),
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json([
            'user' => $this->userPayload($request->user()),
        ]);
    }

    public function profile(Request $request): JsonResponse
    {
        return response()->json([
            'user' => $this->userPayload($request->user()),
        ]);
    }

    public function updateProfile(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'responsible_name' => ['nullable', 'string', 'max:255'],
            'responsible_phone' => ['nullable', 'string', 'max:30'],
            'vat_number' => ['nullable', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
        ]);

        $original = $user->only(['name', 'responsible_name', 'responsible_phone', 'vat_number', 'email']);
        $user->update($data);

        AuditLog::record('company.profile_updated', $user, 'Profilo societa aggiornato', [
            'before' => $original,
            'after' => $user->only(['name', 'responsible_name', 'responsible_phone', 'vat_number', 'email']),
        ], actor: $user, company: $user);

        return response()->json([
            'user' => $this->userPayload($user->fresh()),
        ]);
    }

    public function updatePassword(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'confirmed', Password::min(8)->mixedCase()->numbers()],
        ]);

        if (! Hash::check($data['current_password'], $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => ['Password attuale non corretta.'],
            ]);
        }

        $user->forceFill([
            'password' => Hash::make($data['password']),
        ])->save();

        AuditLog::record('company.password_updated', $user, 'Password societa aggiornata', actor: $user, company: $user);

        return response()->json([
            'message' => 'Password aggiornata.',
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->forceFill([
            'api_token' => null,
        ])->save();

        return response()->json([
            'message' => 'Logout effettuato.',
        ]);
    }

    private function userPayload(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'responsible_name' => $user->responsible_name,
            'responsible_phone' => $user->responsible_phone,
            'vat_number' => $user->vat_number,
            'approval_status' => $user->approval_status,
        ];
    }
}
