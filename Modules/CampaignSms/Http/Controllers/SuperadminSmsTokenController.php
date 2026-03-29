<?php

namespace Modules\CampaignSms\Http\Controllers;

use App\Business;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\CampaignSms\Services\SmsTokenService;

class SuperadminSmsTokenController extends Controller
{
    public function __construct(
        protected SmsTokenService $tokenService
    ) {
    }

    public function edit($business_id)
    {
        if (! auth()->user()->can('superadmin')) {
            abort(403, 'Unauthorized action.');
        }

        $business = Business::findOrFail($business_id);
        $balance = $this->tokenService->getBalance((int) $business_id);

        return view('campaignsms::superadmin.sms_tokens', compact('business', 'balance'));
    }

    public function update(Request $request, $business_id)
    {
        if (! auth()->user()->can('superadmin')) {
            abort(403, 'Unauthorized action.');
        }

        $request->validate([
            'action' => 'required|in:set,add',
            'amount' => 'required|integer|min:0',
        ]);

        $business = Business::findOrFail($business_id);
        $bid = (int) $business->id;

        if ($request->input('action') === 'set') {
            $this->tokenService->setBalance($bid, (int) $request->input('amount'));
        } else {
            $this->tokenService->addTokens($bid, (int) $request->input('amount'));
        }

        return redirect()
            ->route('campaignsms.superadmin.tokens', ['business_id' => $bid])
            ->with('status', [
                'success' => 1,
                'msg' => __('lang_v1.success'),
            ]);
    }
}
