<?php

namespace Modules\Cashier\Support;

use App\Business;
use App\User;
use App\Utils\BusinessUtil;

class CashierContext
{
    /**
     * TeamPOS sale and receipt code reads the web session. The cashier API
     * authenticates with a token, so this fills the same session keys for the request.
     */
    public static function bind(User $user): void
    {
        auth()->setUser($user);

        $request = request();
        if (! $request->hasSession()) {
            $store = app('session.store');
            if (! $store->isStarted()) {
                $store->start();
            }
            $request->setLaravelSession($store);
        }

        if ($request->session()->get('user.id') === $user->id && $request->session()->has('business')) {
            return;
        }

        $business = Business::findOrFail($user->business_id);
        $currency = $business->currency;

        $request->session()->put('user', [
            'id' => $user->id,
            'surname' => $user->surname,
            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
            'email' => $user->email,
            'business_id' => $user->business_id,
            'language' => $user->language,
        ]);
        $request->session()->put('business', $business);
        $request->session()->put('currency', [
            'id' => $currency->id,
            'code' => $currency->code,
            'symbol' => $currency->symbol,
            'thousand_separator' => $currency->thousand_separator,
            'decimal_separator' => $currency->decimal_separator,
        ]);
        $request->session()->put(
            'financial_year',
            app(BusinessUtil::class)->getCurrentFinancialYear($business->id)
        );
    }
}
