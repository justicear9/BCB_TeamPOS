<?php

namespace Modules\Cashier\Http\Controllers;

use App\User;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Hash;
use Modules\Cashier\Http\Middleware\FreshCashierToken;

class AuthController extends Controller
{
    public function login(Request $request)
    {
        $data = $request->validate([
            'username' => 'required|string|max:191',
            'password' => 'required|string|max:191',
            'device' => 'nullable|string|max:64',
        ]);

        $user = User::where('username', $data['username'])->whereNotNull('business_id')->first();
        if (! $user || ! Hash::check($data['password'], $user->password)) {
            abort(422, 'Wrong username or password');
        }
        if (! $user->business || ! $user->business->is_active) {
            abort(403, 'This business is not active');
        }
        if ($user->status !== 'active' || ! $user->allow_login) {
            abort(403, 'This account cannot sign in');
        }
        if (! $user->can('sell.create')) {
            abort(403, 'This account cannot make sales');
        }

        $result = $user->createToken('cashier '.($data['device'] ?? 'phone'));
        $result->token->expires_at = now()->addDays(FreshCashierToken::MAX_AGE_DAYS);
        $result->token->save();

        return response()->json([
            'access_token' => $result->accessToken,
            'expires_at' => (string) $result->token->expires_at,
            'user' => [
                'id' => $user->id,
                'username' => $user->username,
                'name' => trim($user->first_name.' '.$user->last_name),
            ],
        ]);
    }

    public function logout(Request $request)
    {
        $token = $request->user()->token();
        if ($token) {
            $token->revoke();
        }

        return response()->json(['ok' => true]);
    }
}
