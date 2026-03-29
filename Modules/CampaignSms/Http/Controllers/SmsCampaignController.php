<?php

namespace Modules\CampaignSms\Http\Controllers;

use App\Business;
use App\Contact;
use App\CustomerGroup;
use App\Utils\ModuleUtil;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\CampaignSms\Entities\SmsCampaign;
use Modules\CampaignSms\Entities\SmsCampaignRecipient;
use Modules\CampaignSms\Services\SmsTemplateHelper;
use Modules\CampaignSms\Services\SmsTokenService;

class SmsCampaignController extends Controller
{
    public function __construct(
        protected SmsTokenService $tokenService,
        protected ModuleUtil $moduleUtil
    ) {
    }

    public function index(Request $request)
    {
        $business_id = request()->session()->get('user.business_id');
        if (! $this->moduleUtil->isSubscribed($business_id)) {
            return $this->moduleUtil->expiredResponse();
        }
        if (! auth()->user()->can('campaignsms.view_logs')) {
            abort(403);
        }

        $q = trim((string) $request->input('q', ''));

        $query = SmsCampaign::where('business_id', $business_id)
            ->orderByDesc('id');

        if ($q !== '') {
            $query->where(function ($sub) use ($q) {
                $sub->where('name', 'like', '%'.$q.'%')
                    ->orWhere('body', 'like', '%'.$q.'%')
                    ->orWhere('status', 'like', '%'.$q.'%')
                    ->orWhere('audience_type', 'like', '%'.$q.'%');
                if (ctype_digit($q)) {
                    $sub->orWhere('id', (int) $q);
                }
            });
        }

        $campaigns = $query->paginate(20)->appends($request->query());

        $balance = $this->tokenService->getBalance($business_id);

        return view('campaignsms::campaigns.index', compact('campaigns', 'balance', 'q'));
    }

    /**
     * Campaign detail for history (name, audience summary, message body).
     */
    public function show(Request $request, $campaign)
    {
        $business_id = (int) $request->session()->get('user.business_id');
        if (! $this->moduleUtil->isSubscribed($business_id)) {
            return response()->json(['error' => 'forbidden'], 402);
        }
        if (! auth()->user()->can('campaignsms.view_logs')) {
            abort(403);
        }

        $campaignModel = SmsCampaign::where('business_id', $business_id)
            ->where('id', (int) $campaign)
            ->firstOrFail();

        return response()->json([
            'name' => $campaignModel->name ?: '',
            'audience' => $this->describeCampaignAudience($campaignModel),
            'message' => (string) $campaignModel->body,
        ]);
    }

    protected function describeCampaignAudience(SmsCampaign $campaign): string
    {
        $type = (string) $campaign->audience_type;

        if ($type === 'all_customers') {
            return __('campaignsms::lang.all_customers');
        }

        if ($type === 'customer_group') {
            if (! empty($campaign->customer_group_id)) {
                $group = CustomerGroup::where('business_id', $campaign->business_id)
                    ->find($campaign->customer_group_id);

                return $group
                    ? __('campaignsms::lang.customer_group') . ': ' . $group->name
                    : __('campaignsms::lang.customer_group');
            }

            return __('campaignsms::lang.customer_group');
        }

        if ($type === 'specific_contacts') {
            return __('campaignsms::lang.specific_contacts') . ' (' . (int) $campaign->recipient_count . ')';
        }

        return $type;
    }

    public function create()
    {
        $business_id = request()->session()->get('user.business_id');
        if (! $this->moduleUtil->isSubscribed($business_id)) {
            return $this->moduleUtil->expiredResponse();
        }
        if (! auth()->user()->can('campaignsms.send_bulk')) {
            abort(403);
        }

        $business = Business::findOrFail($business_id);
        $customer_groups = CustomerGroup::forDropdown($business_id, true, false);
        $balance = $this->tokenService->getBalance($business_id);
        $sms_ok = $this->tokenService->businessHasSmsConfigured($business);

        return view('campaignsms::campaigns.create', compact('customer_groups', 'balance', 'sms_ok', 'business'));
    }

    public function store(Request $request)
    {
        $business_id = (int) $request->session()->get('user.business_id');
        if (! $this->moduleUtil->isSubscribed($business_id)) {
            return $this->moduleUtil->expiredResponse();
        }
        if (! auth()->user()->can('campaignsms.send_bulk')) {
            abort(403);
        }

        $request->merge([
            'contact_ids' => $this->normalizeContactIdsInput($request->input('contact_ids')),
        ]);

        $request->validate([
            'body' => 'required|string|max:2000',
            'audience_type' => 'required|in:all_customers,customer_group,specific_contacts',
            'customer_group_id' => 'nullable|integer',
            'contact_ids' => 'nullable|array',
            'contact_ids.*' => 'integer',
            'name' => 'nullable|string|max:255',
        ]);

        $business = Business::findOrFail($business_id);

        if (! $this->tokenService->businessHasSmsConfigured($business)) {
            return redirect()->back()->with('status', [
                'success' => 0,
                'msg' => __('campaignsms::lang.configure_sms_in_business_settings'),
            ])->withInput();
        }

        $body = $request->input('body');
        $audience = $request->input('audience_type');

        $recipients = $this->resolveRecipients($business_id, $audience, $request);

        if ($recipients->isEmpty()) {
            return redirect()->back()->with('status', [
                'success' => 0,
                'msg' => __('campaignsms::lang.no_recipients'),
            ])->withInput();
        }

        $personalizedByContactId = [];
        $segmentsByContactId = [];
        $totalTokens = 0;
        foreach ($recipients as $contact) {
            $personalized = SmsTemplateHelper::bulkCampaign($body, $contact, $business);
            $segments = $this->tokenService->segmentCount($personalized);
            $personalizedByContactId[$contact->id] = $personalized;
            $segmentsByContactId[$contact->id] = $segments;
            $totalTokens += $segments;
        }

        if (! $this->tokenService->canAfford($business_id, $totalTokens)) {
            return redirect()->back()->with('status', [
                'success' => 0,
                'msg' => __('campaignsms::lang.insufficient_tokens', ['need' => $totalTokens, 'have' => $this->tokenService->getBalance($business_id)]),
            ])->withInput();
        }

        if (! $this->tokenService->tryDeduct($business_id, $totalTokens)) {
            return redirect()->back()->with('status', [
                'success' => 0,
                'msg' => __('campaignsms::lang.insufficient_tokens', ['need' => $totalTokens, 'have' => $this->tokenService->getBalance($business_id)]),
            ])->withInput();
        }

        $campaign = new SmsCampaign([
            'business_id' => $business_id,
            'name' => $request->input('name'),
            'body' => $body,
            'audience_type' => $audience,
            'customer_group_id' => $audience === 'customer_group' ? $request->input('customer_group_id') : null,
            'status' => 'sending',
            'total_tokens_charged' => $totalTokens,
            'recipient_count' => $recipients->count(),
            'created_by' => auth()->id(),
        ]);
        $campaign->save();

        foreach ($recipients as $contact) {
            $segments = $segmentsByContactId[$contact->id];
            SmsCampaignRecipient::create([
                'sms_campaign_id' => $campaign->id,
                'contact_id' => $contact->id,
                'mobile_snapshot' => (string) $contact->mobile,
                'segments' => $segments,
                'token_cost' => $segments,
                'send_status' => 'pending',
            ]);
        }

        $campaign->load('recipients');

        $sent = 0;
        $failed = 0;

        foreach ($campaign->recipients as $recipient) {
            $mobile = trim((string) $recipient->mobile_snapshot);
            $messageBody = $personalizedByContactId[$recipient->contact_id] ?? '';

            if ($mobile === '') {
                $recipient->update([
                    'send_status' => 'failed',
                    'error_message' => 'Empty mobile',
                ]);
                $failed++;

                continue;
            }

            try {
                $result = $this->tokenService->sendSms($business, $mobile, $messageBody);
                if ($result === false) {
                    throw new \RuntimeException('SMS gateway misconfigured or URL empty.');
                }
                $recipient->update(['send_status' => 'sent']);
                $sent++;
            } catch (\Throwable $e) {
                \Log::warning('CampaignSms send failed: '.$e->getMessage());
                $recipient->update([
                    'send_status' => 'failed',
                    'error_message' => $e->getMessage(),
                ]);
                $failed++;
            }
        }

        $campaign->update([
            'status' => $failed === 0 ? 'sent' : ($sent === 0 ? 'failed' : 'sent'),
        ]);

        return redirect()->route('campaignsms.campaigns.index')->with('status', [
            'success' => 1,
            'msg' => __('campaignsms::lang.campaign_finished', ['sent' => $sent, 'failed' => $failed]),
        ]);
    }

    protected function resolveRecipients(int $business_id, string $audience, Request $request)
    {
        $q = Contact::where('business_id', $business_id)
            ->whereIn('type', ['customer', 'both'])
            ->whereNotNull('mobile')
            ->where('mobile', '!=', '');

        if ($audience === 'customer_group') {
            $gid = $request->input('customer_group_id');
            if (empty($gid) || $gid === '') {
                return collect();
            }
            $q->where('customer_group_id', $gid);
        }

        if ($audience === 'specific_contacts') {
            $ids = $request->input('contact_ids', []);
            if (! is_array($ids)) {
                $ids = [];
            }
            $ids = array_values(array_filter(array_map('intval', $ids)));
            if (empty($ids)) {
                return collect();
            }
            $q->whereIn('id', $ids);
        }

        return $q->get()->unique('id');
    }

    public function audienceCount(Request $request)
    {
        $business_id = (int) $request->session()->get('user.business_id');
        if (! auth()->user()->can('campaignsms.send_bulk')) {
            abort(403);
        }

        $request->merge([
            'contact_ids' => $this->normalizeContactIdsInput($request->input('contact_ids')),
        ]);

        $request->validate([
            'audience_type' => 'required|in:all_customers,customer_group,specific_contacts',
            'customer_group_id' => 'nullable|integer',
            'contact_ids' => 'nullable|array',
            'contact_ids.*' => 'integer',
        ]);

        $recipients = $this->resolveRecipients($business_id, $request->input('audience_type'), $request);

        return response()->json(['count' => $recipients->count()]);
    }

    public function searchContacts(Request $request)
    {
        $business_id = (int) $request->session()->get('user.business_id');
        if (! auth()->user()->can('campaignsms.send_bulk')) {
            abort(403);
        }

        $term = $request->get('q', '');
        $query = Contact::where('business_id', $business_id)
            ->whereIn('type', ['customer', 'both'])
            ->whereNotNull('mobile')
            ->where('mobile', '!=', '');

        if ($term !== '') {
            $query->where(function ($q) use ($term) {
                $q->where('name', 'like', '%'.$term.'%')
                    ->orWhere('mobile', 'like', '%'.$term.'%');
            });
        }

        $contacts = $query->orderBy('name')->limit(50)->get(['id', 'name', 'mobile']);

        $results = $contacts->map(function ($c) {
            return [
                'id' => $c->id,
                'text' => $c->name.' — '.$c->mobile,
            ];
        });

        return response()->json(['results' => $results]);
    }

    /**
     * @param  array|string|null  $raw
     * @return array<int>
     */
    protected function normalizeContactIdsInput($raw): array
    {
        if ($raw === null || $raw === '' || $raw === []) {
            return [];
        }
        if (is_array($raw)) {
            return array_values(array_filter(array_map('intval', $raw)));
        }

        return array_values(array_filter(array_map('intval', explode(',', (string) $raw))));
    }
}
