<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Resources\User\UserResource;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Laravel\Socialite\Socialite;

class OAuthController extends Controller
{
    function OAuthRedirect(Request $request, $driver)
    {
        $callback_url = $request->query('callback_url', '');
        $socialite = Socialite::driver($driver);

        if ($driver !== 'twitter-oauth-2') {
            $socialite->stateless();
            $socialite->with(['state' => base64_encode($callback_url)]);
        } else {
            session(['oauth_callback_url' => $callback_url]);
        }

        $redirectUrl = $socialite->redirect()->getTargetUrl();
        return response(['redirect_url' => $redirectUrl], 200);
    }

    function OAuthCallback(Request $request, $driver)
    {
        $callback_url = $driver === 'twitter-oauth-2'
            ? session('oauth_callback_url', '')
            : base64_decode($request->query('state', ''));

        try {
            $socialite = Socialite::driver($driver);
            if ($driver !== 'twitter-oauth-2') {
                $socialite->stateless();
            }
            $OauthUser = $socialite->user();
        } catch (\Exception $e) {
            Log::error('OAuth callback failed', ['driver' => $driver, 'message' => $e->getMessage()]);
            return redirect($callback_url . '?error=_oauth_failed');
        }

        $user = User::updateOrCreate(
            ['email' => $OauthUser->getEmail()],
            [
                'name' => $OauthUser->getName(),
            ]
        );

        $user->save();

        if (!$user->hasVerifiedEmail()) {
            $user->markEmailAsVerified();
        }

        $token = $user->createToken('auth_token', ['exchange-new-token'], now()->addMinute())->plainTextToken;

        return redirect($callback_url . '?token=' . urlencode($token));
    }

    function OAuthExchangeToken(Request $request)
    {
        $user = $request->user();

        if (!$user->currentAccessToken()->can('exchange-new-token')) {
            return response(['message' => 'Invalid token.'], 403);
        }

        $user->currentAccessToken()->delete();

        $token = $user->createToken('auth_token')->plainTextToken;

        return response([
            'message' => 'User signed in.',
            'user' => new UserResource($user),
            'token' => $token
        ], 200);
    }
}
