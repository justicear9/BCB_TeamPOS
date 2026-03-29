<?php

namespace Modules\CampaignSms\Http\Controllers;

use App\Utils\ModuleUtil;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\CampaignSms\Entities\SmsCampaignSetting;

class RefillSettingsController extends Controller
{
    public function __construct(
        protected ModuleUtil $moduleUtil
    ) {
    }

    public function edit()
    {
        $business_id = request()->session()->get('user.business_id');
        if (! $this->moduleUtil->isSubscribed($business_id)) {
            return $this->moduleUtil->expiredResponse();
        }
        if (! auth()->user()->can('campaignsms.manage_refills')) {
            abort(403);
        }

        $settings = SmsCampaignSetting::firstOrCreate(
            ['business_id' => $business_id],
            [
                'default_refill_template' => 'Hi {customer_name}, reminder to refill {product_name} at {business_name}.',
                'reminder_days_before' => 3,
            ]
        );

        return view('campaignsms::settings.refill', compact('settings'));
    }

    public function update(Request $request)
    {
        $business_id = (int) $request->session()->get('user.business_id');
        if (! $this->moduleUtil->isSubscribed($business_id)) {
            return $this->moduleUtil->expiredResponse();
        }
        if (! auth()->user()->can('campaignsms.manage_refills')) {
            abort(403);
        }

        $request->validate([
            'default_refill_template' => 'required|string|max:2000',
            'reminder_days_before' => 'required|integer|min:0|max:365',
        ]);

        $row = SmsCampaignSetting::firstOrCreate(
            ['business_id' => $business_id],
            []
        );
        $row->default_refill_template = $request->input('default_refill_template');
        $row->reminder_days_before = (int) $request->input('reminder_days_before');
        $row->save();

        return redirect()->route('campaignsms.refill-settings.edit')->with('status', [
            'success' => 1,
            'msg' => __('lang_v1.success'),
        ]);
    }
}
