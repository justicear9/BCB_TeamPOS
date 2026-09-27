<?php

namespace Modules\AIBusinessManager\Http\Controllers;

use App\Utils\ModuleUtil;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Schema;
use Modules\AIBusinessManager\Entities\AiBusinessManagerPreference;

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
        ]);
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
