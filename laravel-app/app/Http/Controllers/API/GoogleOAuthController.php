<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Resources\User\UserResource;
use App\Models\User;
use Illuminate\Http\Request;
use Laravel\Socialite\Socialite;
// before encode http://localhost:5173/google/oauth/callback
// after decode aHR0cDovL2xvY2FsaG9zdDo1MTczL2xvZ2lu
class GoogleOAuthController extends Controller
{
    function googleOAuthRedirect(Request $request)
    {
        $callback_url = $request->query('callback_url', '');

        $redirectUrl = Socialite::driver('google')
            //tell laravel to don't use session
            ->stateless()
            // sent state parameter to google oauth request
            ->with(['state' => base64_encode($callback_url)])
            // Build the Google login URL.
            ->redirect()
            //get url as string
            ->getTargetUrl();

        return response(['redirect_url' => $redirectUrl], 200);
    }

    function googleOAuthCallback(Request $request)
    {
        $callback_url = base64_decode($request->query('state', ''));
        try {
            $googleUser = Socialite::driver('google')->stateless()->user();
        } catch (\Exception $e) {
            return redirect($callback_url . '?error=google_oauth_failed');
        }

        $user = User::updateOrCreate(
            ['email' => $googleUser->getEmail()],
            [
                'name' => $googleUser->getName(),
            ]
        );

        $user->save();

        if (!$user->hasVerifiedEmail()) {
            $user->markEmailAsVerified();
        }

        $token = $user->createToken('auth_token', ['exchange-new-token'], now()->addMinute())->plainTextToken;

        return redirect($callback_url . '?token=' . urlencode($token));
    }

    function googleOAuthExchangeToken(Request $request)
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
