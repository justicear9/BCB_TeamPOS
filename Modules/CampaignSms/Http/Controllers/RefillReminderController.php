<?php

namespace Modules\CampaignSms\Http\Controllers;

use App\Contact;
use App\Product;
use App\Utils\ModuleUtil;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\CampaignSms\Entities\SmsRefillReminder;
use Modules\CampaignSms\Services\RefillReminderScheduler;

class RefillReminderController extends Controller
{
    public function __construct(
        protected ModuleUtil $moduleUtil
    ) {
    }

    public function data(Request $request, $contact_id)
    {
        $business_id = (int) $request->session()->get('user.business_id');
        if (! $this->moduleUtil->isSubscribed($business_id)) {
            return response()->json(['data' => []], 402);
        }
        $contact = Contact::where('business_id', $business_id)->findOrFail($contact_id);
        if (! auth()->user()->can('campaignsms.manage_refills')) {
            abort(403);
        }

        $rows = SmsRefillReminder::where('business_id', $business_id)
            ->where('contact_id', $contact->id)
            ->with('product')
            ->orderBy('id', 'desc')
            ->get();

        return response()->json(['data' => $rows]);
    }

    public function store(Request $request, $contact_id)
    {
        $business_id = (int) $request->session()->get('user.business_id');
        if (! $this->moduleUtil->isSubscribed($business_id)) {
            return response()->json(['success' => false, 'msg' => 'Subscription'], 402);
        }
        if (! auth()->user()->can('campaignsms.manage_refills')) {
            abort(403);
        }

        $contact = Contact::where('business_id', $business_id)->findOrFail($contact_id);

        $request->validate([
            'product_id' => 'required|integer',
            'interval_days' => 'required|integer|min:1|max:3650',
            'next_run_at' => 'required|date',
            'template_body' => 'nullable|string|max:2000',
        ]);

        $product = Product::where('business_id', $business_id)->where('id', $request->product_id)->firstOrFail();

        $exists = SmsRefillReminder::where('business_id', $business_id)
            ->where('contact_id', $contact->id)
            ->where('product_id', $product->id)
            ->exists();

        if ($exists) {
            return response()->json([
                'success' => false,
                'msg' => __('campaignsms::lang.reminder_exists_for_product'),
            ], 422);
        }

        /** @var RefillReminderScheduler $scheduler */
        $scheduler = app(RefillReminderScheduler::class);
        $intervalDays = (int) $request->interval_days;
        $rb = $scheduler->reminderDaysBeforeForBusiness($business_id);
        $lastPurchase = $scheduler->lastPurchaseAt($business_id, $contact->id, $product->id);

        if ($lastPurchase) {
            $nextRunAt = $scheduler->computeNextRunFromPurchase($lastPurchase, $intervalDays, $rb);
        } else {
            $nextRunAt = Carbon::parse($request->next_run_at)->setTime(9, 0, 0);
        }

        $reminder = SmsRefillReminder::create([
            'business_id' => $business_id,
            'contact_id' => $contact->id,
            'product_id' => $product->id,
            'interval_days' => $intervalDays,
            'next_run_at' => $nextRunAt,
            'template_body' => $request->input('template_body'),
            'is_active' => true,
        ]);

        return response()->json(['success' => true, 'data' => $reminder->load('product')]);
    }

    public function update(Request $request, $id)
    {
        $business_id = (int) $request->session()->get('user.business_id');
        if (! auth()->user()->can('campaignsms.manage_refills')) {
            abort(403);
        }

        $reminder = SmsRefillReminder::where('business_id', $business_id)->findOrFail($id);

        $request->validate([
            'interval_days' => 'sometimes|integer|min:1|max:3650',
            'next_run_at' => 'sometimes|date',
            'template_body' => 'nullable|string|max:2000',
            'is_active' => 'sometimes|boolean',
        ]);

        $reminder->fill($request->only(['interval_days', 'template_body', 'is_active']));
        if ($request->has('next_run_at')) {
            $reminder->next_run_at = \Carbon\Carbon::parse($request->next_run_at)->setTime(9, 0, 0);
        }
        $reminder->save();

        if ($request->has('interval_days')) {
            /** @var RefillReminderScheduler $scheduler */
            $scheduler = app(RefillReminderScheduler::class);
            $lastPurchase = $scheduler->lastPurchaseAt($business_id, $reminder->contact_id, $reminder->product_id);
            if ($lastPurchase) {
                $rb = $scheduler->reminderDaysBeforeForBusiness($business_id);
                $reminder->next_run_at = $scheduler->computeNextRunFromPurchase(
                    $lastPurchase,
                    (int) $reminder->interval_days,
                    $rb
                );
                $reminder->save();
            }
        }

        return response()->json(['success' => true, 'data' => $reminder->load('product')]);
    }

    public function destroy(Request $request, $id)
    {
        $business_id = (int) $request->session()->get('user.business_id');
        if (! auth()->user()->can('campaignsms.manage_refills')) {
            abort(403);
        }

        $reminder = SmsRefillReminder::where('business_id', $business_id)->findOrFail($id);
        $reminder->delete();

        return response()->json(['success' => true]);
    }

    public function products(Request $request)
    {
        $business_id = (int) $request->session()->get('user.business_id');
        if (! auth()->user()->can('campaignsms.manage_refills')) {
            abort(403);
        }

        $q = trim((string) $request->get('q', ''));
        $query = Product::where('business_id', $business_id)->active()->productForSales();

        if ($q !== '') {
            $query->where('name', 'like', '%'.$q.'%');
        }

        $products = $query->orderBy('name')->limit(50)->get(['id', 'name']);

        return response()->json([
            'results' => $products->map(fn ($p) => ['id' => $p->id, 'text' => (string) $p->name]),
        ]);
    }
}
