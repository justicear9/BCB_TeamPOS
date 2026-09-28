<?php

namespace Modules\AIBusinessManager\Http\Controllers;

use App\Utils\ModuleUtil;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Modules\AIBusinessManager\Entities\AiBusinessManagerPreference;
use Modules\AIBusinessManager\Services\Manager\ManagerStore;

class SettingsController extends Controller
{
    public function edit()
    {
        $this->ensureInstalled();

        if (! auth()->user()->can('aibusinessmanager.use')) {
            abort(403, 'Unauthorized action.');
        }

        $business_id = (int) session()->get('user.business_id');
        $pref = Schema::hasTable('ai_business_manager_preferences')
            ? AiBusinessManagerPreference::query()->firstWhere('business_id', $business_id)
            : null;

        return view('aibusinessmanager::settings.edit', [
            'preference' => $pref,
            'theme_accent' => app(AssistantController::class)->themeAccent(),
            'manager' => $this->managerSettings($business_id),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function managerSettings(int $business_id): array
    {
        $store = new ManagerStore($business_id);
        $ready = $store->available();

        return [
            'ready' => $ready,
            'can_edit' => auth()->user()->permitted_locations($business_id) === 'all',
            'locations' => DB::table('business_locations')->where('business_id', $business_id)->orderBy('name')->pluck('name', 'id')->all(),
            'targets' => $ready ? $store->targets() : [],
            'costs' => $ready ? $store->fixedCosts() : [],
            'events' => $ready ? $store->allCalendarEvents() : [],
            'metrics' => ManagerStore::TARGET_METRICS,
            'categories' => ManagerStore::COST_CATEGORIES,
            'kinds' => ManagerStore::EVENT_KINDS,
        ];
    }

    public function updateManager(Request $request)
    {
        $this->ensureInstalled();

        $business_id = (int) session()->get('user.business_id');
        if (! auth()->user()->can('aibusinessmanager.use') || auth()->user()->permitted_locations($business_id) !== 'all') {
            abort(403);
        }
        $locationIds = DB::table('business_locations')->where('business_id', $business_id)->pluck('id')->map(fn ($id) => (string) $id)->all();
        $location = ['nullable', Rule::in($locationIds)];

        $validated = $request->validate([
            'targets' => 'array|max:200',
            'targets.*.location_id' => $location,
            'targets.*.metric' => ['required_with:targets.*.value', 'nullable', Rule::in(ManagerStore::TARGET_METRICS)],
            'targets.*.month' => 'required_with:targets.*.value|nullable|date_format:Y-m',
            'targets.*.value' => 'nullable|numeric',
            'costs' => 'array|max:200',
            'costs.*.location_id' => $location,
            'costs.*.name' => 'required_with:costs.*.monthly_amount|nullable|string|max:120',
            'costs.*.category' => ['nullable', Rule::in(ManagerStore::COST_CATEGORIES)],
            'costs.*.monthly_amount' => 'nullable|numeric|min:0',
            'costs.*.starts_on' => 'nullable|date',
            'costs.*.ends_on' => 'nullable|date',
            'events' => 'array|max:200',
            'events.*.location_id' => $location,
            'events.*.name' => 'required_with:events.*.starts_on|nullable|string|max:160',
            'events.*.kind' => ['nullable', Rule::in(ManagerStore::EVENT_KINDS)],
            'events.*.starts_on' => 'required_with:events.*.name|nullable|date',
            'events.*.ends_on' => 'nullable|date',
            'events.*.effect_pct' => 'nullable|numeric|min:-100|max:500',
        ]);

        $store = new ManagerStore($business_id);
        if (! $store->available()) {
            return redirect()->action([self::class, 'edit'])->with('status', __('aibusinessmanager::lang.settings_error_no_preferences_table'));
        }
        $userId = (int) auth()->id();

        $targets = array_values(array_filter($validated['targets'] ?? [], fn ($r) => ($r['value'] ?? '') !== '' && ! empty($r['metric']) && ! empty($r['month'])));
        $store->replaceTargets(array_map(fn ($r) => [
            'location_id' => $r['location_id'] ?? null,
            'metric' => $r['metric'],
            'month' => $r['month'].'-01',
            'value' => $r['value'],
        ], $targets), $userId);

        $costs = array_values(array_filter($validated['costs'] ?? [], fn ($r) => trim((string) ($r['name'] ?? '')) !== '' && ($r['monthly_amount'] ?? '') !== ''));
        $store->replaceFixedCosts(array_map(fn ($r) => [
            'location_id' => $r['location_id'] ?? null,
            'name' => trim((string) $r['name']),
            'category' => $r['category'] ?? 'other',
            'monthly_amount' => $r['monthly_amount'],
            'starts_on' => $r['starts_on'] ?? null,
            'ends_on' => $r['ends_on'] ?? null,
        ], $costs), $userId);

        $events = array_values(array_filter($validated['events'] ?? [], fn ($r) => trim((string) ($r['name'] ?? '')) !== '' && ! empty($r['starts_on'])));
        $store->replaceCalendarEvents(array_map(fn ($r) => [
            'location_id' => $r['location_id'] ?? null,
            'name' => trim((string) $r['name']),
            'kind' => $r['kind'] ?? 'event',
            'starts_on' => $r['starts_on'],
            'ends_on' => $r['ends_on'] ?? null,
            'effect_pct' => $r['effect_pct'] ?? null,
        ], $events), $userId);

        return redirect()->action([self::class, 'edit'])->with('status', __('aibusinessmanager::lang.settings_saved'));
    }

    public function update(Request $request)
    {
        $this->ensureInstalled();

        if (! auth()->user()->can('aibusinessmanager.use')) {
            abort(403);
        }

        $validated = $request->validate([
            'business_industry' => 'nullable|string|max:191',
            'context_notes' => 'nullable|string|max:12000',
        ]);

        $business_id = (int) session()->get('user.business_id');

        if (! Schema::hasTable('ai_business_manager_preferences')) {
            return redirect()
                ->action([self::class, 'edit'])
                ->with('status', __('aibusinessmanager::lang.settings_error_no_preferences_table'));
        }

        $industry = trim((string) ($validated['business_industry'] ?? ''));
        $notes = trim((string) ($validated['context_notes'] ?? ''));

        AiBusinessManagerPreference::query()->updateOrCreate(
            ['business_id' => $business_id],
            [
                'business_industry' => $industry === '' ? null : $industry,
                'context_notes' => $notes === '' ? null : $notes,
            ]
        );

        return redirect()
            ->action([self::class, 'edit'])
            ->with('status', __('aibusinessmanager::lang.settings_saved'));
    }

    protected function ensureInstalled(): void
    {
        $moduleUtil = new ModuleUtil();
        if (! $moduleUtil->isModuleInstalled('AIBusinessManager')) {
            abort(404);
        }
    }

}
