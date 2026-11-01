<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

class MerchantSessionController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        if (is_string($request->input('email'))) {
            $request->merge(['email' => mb_strtolower(trim($request->input('email')))]);
        }
        $credentials = $request->validate(['email' => ['required', 'email', 'max:254'], 'password' => ['required', 'string', 'max:1024']]);
        $key = 'merchant-login:'.hash('sha256', $credentials['email'].'|'.$request->ip());
        if (RateLimiter::tooManyAttempts($key, 5)) {
            return response()->json(['message' => 'Zu viele Anmeldeversuche. Bitte warte eine Minute.'], 429)->header('Retry-After', (string) RateLimiter::availableIn($key));
        }
        if (! Auth::attempt($credentials)) {
            RateLimiter::hit($key, 60);
            throw ValidationException::withMessages(['email' => 'E-Mail oder Passwort ist nicht korrekt.']);
        }
        RateLimiter::clear($key);
        $request->session()->regenerate();

        return response()->json(['data' => ['redirect' => '/portal']]);
    }

    public function destroy(Request $request): JsonResponse
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['data' => ['redirect' => '/portal/login']]);
    }

    public function show(Request $request): JsonResponse
    {
        return response()->json(['data' => [
            'user' => $request->user()->only(['name', 'email']),
            'restaurants' => $request->user()->restaurants()->orderBy('name')->get()->map(fn ($r) => [
                ...$r->only(['slug', 'name']), 'role' => $r->pivot->role,
            ]),
        ]]);
    }
}
