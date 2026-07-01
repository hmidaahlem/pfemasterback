<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Mail\ResetPasswordMail;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;

class AuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $request->validate([
            'email' => 'required|email',
            'password' => 'required|string',
        ]);

        $credentials = $request->only('email', 'password');

        if (! $token = JWTAuth::attempt($credentials)) {
            return response()->json([
                'message' => 'Email ou mot de passe incorrect.',
            ], 401);
        }

        $user = JWTAuth::user();

        if ($user->status !== 'active') {
            return response()->json([
                'message' => 'Compte non actif.',
            ], 403);
        }

        return response()->json([
            'access_token' => $token,
            'token_type' => 'bearer',
            'user' => $user->load('role', 'pointDeVente.airport'),
        ]);
    }

    public function me(): JsonResponse
    {
        $user = JWTAuth::user()->load('role', 'pointDeVente.airport');

        return response()->json([
            'user' => $user,
        ]);
    }

    public function logout(): JsonResponse
    {
        JWTAuth::invalidate(JWTAuth::getToken());

        return response()->json([
            'message' => 'Déconnexion réussie.',
        ]);
    }

    public function refresh(): JsonResponse
    {
        return response()->json([
            'access_token' => JWTAuth::refresh(JWTAuth::getToken()),
        ]);
    }

    public function updateProfile(Request $request): JsonResponse
    {
        $user = JWTAuth::user();

        if (! $user) {
            return response()->json([
                'message' => 'Unauthenticated',
            ], 401);
        }

        $request->validate([
            'first_name' => 'sometimes|string|max:255',
            'last_name' => 'sometimes|string|max:255',
            'email' => 'sometimes|email|unique:users,email,'.$user->id,
            'phone' => 'sometimes|nullable|string|max:20',
            'bio' => 'sometimes|nullable|string|max:500',
            'age' => 'sometimes|nullable|integer|min:0|max:120',
            'avatar' => 'sometimes|file|image|mimes:jpg,jpeg,png,webp|max:2048',
        ]);

        $data = $request->only([
            'first_name',
            'last_name',
            'email',
            'phone',
            'bio',
            'age',
        ]);

        if ($request->hasFile('avatar')) {
            $path = $request->file('avatar')->store('avatars', 'public');
            $data['avatar'] = $path;
        }

        $user->update($data);

        return response()->json([
            'message' => 'Profile updated successfully',
            'user' => $user->fresh()->load('role', 'pointDeVente.airport'),
        ]);
    }

    public function resetPassword(Request $request): JsonResponse
    {
        $request->validate([
            'current_password' => 'required|string',
            'password' => ['required', 'confirmed', Password::min(8)],
        ]);

        $user = JWTAuth::user();

        if (! Hash::check($request->current_password, $user->password)) {
            return response()->json(['message' => 'Le mot de passe actuel est incorrect.'], 422);
        }

        $user->update(['password' => Hash::make($request->password)]);

        return response()->json(['message' => 'Mot de passe modifié avec succès.']);
    }

    public function forgotPassword(Request $request): JsonResponse
    {
        $request->validate([
            'email' => 'required|email',
        ]);

        $user = User::where('email', $request->email)->first();

        if (! $user) {
            return response()->json([
                'message' => 'Si cet email existe, un lien de réinitialisation a été envoyé.',
            ], 200);
        }

        $token = Str::random(60);

        DB::table('password_reset_tokens')
            ->where('email', $user->email)
            ->delete();

        DB::table('password_reset_tokens')->insert([
            'email' => $user->email,
            'token' => $token,
            'created_at' => now(),
        ]);

        Mail::to($user->email)->send(
            new ResetPasswordMail($token, $user->email)
        );

        return response()->json([
            'message' => 'Lien de réinitialisation envoyé par email',
        ]);
    }

    public function resetPasswordWithToken(Request $request): JsonResponse
    {
        $request->validate([
            'token' => 'required|string',
            'password' => ['required', 'confirmed', Password::min(8)],
        ]);

        $record = DB::table('password_reset_tokens')
            ->where('token', $request->token)
            ->first();

        if (! $record) {
            return response()->json([
                'message' => 'Token invalide ou expiré',
            ], 400);
        }

        if (isset($record->created_at) && Carbon::parse($record->created_at)->addMinutes(60)->isPast()) {
            return response()->json([
                'message' => 'Token expiré',
            ], 400);
        }

        $user = User::where('email', $record->email)->first();

        if (! $user) {
            return response()->json([
                'message' => 'Utilisateur introuvable',
            ], 404);
        }

        $user->update([
            'password' => bcrypt($request->password),
        ]);

        DB::table('password_reset_tokens')
            ->where('token', $request->token)
            ->delete();

        return response()->json([
            'message' => 'Mot de passe réinitialisé avec succès',
        ], 200);
    }
}
