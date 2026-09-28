<?php

namespace Modules\AIBusinessManager\Http\Controllers;

use App\Utils\ModuleUtil;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\AIBusinessManager\Services\AiBusinessAssistantService;
use Modules\AIBusinessManager\Services\Manager\BriefBuilder;
use Modules\AIBusinessManager\Services\ReportPageContextService;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class AssistantController extends Controller
{
    public function index(AiBusinessAssistantService $assistant)
    {
        $this->ensureInstalled();

        if (! auth()->user()->can('aibusinessmanager.use')) {
            abort(403, 'Unauthorized action.');
        }

        $business_id = (int) session()->get('user.business_id');
        $user = auth()->user();
        $snapshot = $assistant->buildSnapshot($business_id, $user);
        BriefBuilder::deliverTo($business_id, $user);
        $messages = $this->loadPersistedMessages($business_id, (int) $user->id);

        return view('aibusinessmanager::assistant.index', [
            'snapshot' => $snapshot,
            'messages' => $messages,
            'first_name' => $this->resolveFirstName($user),
            'theme_accent' => $this->resolveThemeAccent(),
            'chat_url' => action([\Modules\AIBusinessManager\Http\Controllers\AssistantController::class, 'chat']),
            'clear_url' => action([\Modules\AIBusinessManager\Http\Controllers\AssistantController::class, 'clear']),
        ]);
    }

    public function chat(Request $request, AiBusinessAssistantService $assistant)
    {
        $this->ensureInstalled();

        if (! auth()->user()->can('aibusinessmanager.use')) {
            abort(403);
        }

        $request->validate([
            'message' => 'required|string|max:8000',
        ]);

        $business_id = (int) session()->get('user.business_id');
        $user = auth()->user();

        $history = $this->loadPersistedMessages(
            $business_id,
            (int) $user->id,
            (int) config('aibusinessmanager.max_memory_messages', 300)
        );

        $report_pages = app(ReportPageContextService::class);
        $report_ctx = $report_pages->resolveFromChatRequest($request);
        $report_block = $report_ctx !== null ? $report_pages->formatModelBlock($report_ctx) : null;

        $result = $assistant->chat(
            $request->input('message'),
            $history,
            $business_id,
            $user,
            $this->resolveFirstName($user),
            $report_block
        );

        if (isset($result['error'])) {
            $msg = $result['error'] === 'no_api_key'
                ? __('aibusinessmanager::lang.error_no_api_key')
                : __('aibusinessmanager::lang.error_generic');

            if (config('app.debug') && ! empty($result['details'])) {
                $msg .= ' ['.$result['details'].']';
            }

            return response()->json(['success' => false, 'msg' => $msg], 422);
        }

        if (Schema::hasTable('ai_business_manager_messages')) {
            DB::table('ai_business_manager_messages')->insert([
            [
                'business_id' => $business_id,
                'user_id' => (int) $user->id,
                'role' => 'user',
                'content' => $request->input('message'),
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'business_id' => $business_id,
                'user_id' => (int) $user->id,
                'role' => 'assistant',
                'content' => $result['reply'],
                'created_at' => now(),
                'updated_at' => now(),
            ],
            ]);
        }

        return response()->json([
            'success' => true,
            'reply' => $result['reply'],
        ]);
    }

    /**
     * Persisted chat turns for the floating widget (same store as the full Eli chat page).
     *
     * Pagination: default 100 newest; pass before_id (exclusive, numeric row id) for older chunks.
     */
    public function messages(Request $request): JsonResponse
    {
        $this->ensureInstalled();

        if (! auth()->user()->can('aibusinessmanager.use')) {
            abort(403);
        }

        $business_id = (int) session()->get('user.business_id');
        $user_id = (int) auth()->id();

        $page_limit = 100;
        $limit = min(max(1, (int) $request->query('limit', $page_limit)), $page_limit);

        $before_raw = $request->query('before_id');
        $before_id = null;
        if ($before_raw !== null && $before_raw !== '' && is_numeric($before_raw)) {
            $bid = (int) $before_raw;
            $before_id = $bid > 0 ? $bid : null;
        }

        $page = $this->loadPersistedMessagesPage($business_id, $user_id, $limit, $before_id);

        return response()->json([
            'success' => true,
            'messages' => $page['messages'],
            'has_more' => $page['has_more'],
        ]);
    }

    /**
     * Eli mascot for the floating FAB (served from module Resources; auth + permission required).
     */
    public function eliFloatingIcon(): BinaryFileResponse
    {
        $this->ensureInstalled();

        if (! auth()->user()->can('aibusinessmanager.use')) {
            abort(403);
        }

        $path = module_path('AIBusinessManager', 'Resources/assets/img/eli-floating-icon.png');
        if (! is_readable($path)) {
            abort(404);
        }

        return response()->file($path, [
            'Content-Type' => 'image/png',
            'Cache-Control' => 'public, max-age=86400',
        ]);
    }

    public function clear()
    {
        $this->ensureInstalled();

        if (! auth()->user()->can('aibusinessmanager.use')) {
            abort(403);
        }

        $business_id = (int) session()->get('user.business_id');
        $user_id = (int) auth()->id();

        if (Schema::hasTable('ai_business_manager_messages')) {
            DB::table('ai_business_manager_messages')
                ->where('business_id', $business_id)
                ->where('user_id', $user_id)
                ->delete();
        }
        Cache::forget($this->contextCacheKey($business_id, $user_id));

        return redirect()->action([self::class, 'index']);
    }

    protected function ensureInstalled(): void
    {
        $moduleUtil = new ModuleUtil();
        if (! $moduleUtil->isModuleInstalled('AIBusinessManager')) {
            abort(404);
        }
    }

    protected function contextCacheKey(int $business_id, int $user_id): string
    {
        return 'aibusinessmanager.ctx.'.$business_id.'.'.$user_id;
    }

    /**
     * One page of persisted rows with ids (chronological order: oldest first).
     *
     * @return array{messages: list<array{id: int, role: string, content: string}>, has_more: bool}
     */
    protected function loadPersistedMessagesPage(int $business_id, int $user_id, int $limit, ?int $before_id): array
    {
        if (! Schema::hasTable('ai_business_manager_messages')) {
            return ['messages' => [], 'has_more' => false];
        }

        $query = DB::table('ai_business_manager_messages')
            ->where('business_id', $business_id)
            ->where('user_id', $user_id);

        if ($before_id !== null && $before_id > 0) {
            $query->where('id', '<', $before_id);
        }

        $rows = $query->orderByDesc('id')
            ->limit($limit)
            ->get(['id', 'role', 'content']);

        $messages = $rows->reverse()->values()->map(fn ($row) => [
            'id' => (int) $row->id,
            'role' => (string) $row->role,
            'content' => (string) $row->content,
        ])->all();

        $has_more = false;
        if ($messages !== []) {
            $oldest_id = $messages[0]['id'];
            $has_more = DB::table('ai_business_manager_messages')
                ->where('business_id', $business_id)
                ->where('user_id', $user_id)
                ->where('id', '<', $oldest_id)
                ->exists();
        }

        return ['messages' => $messages, 'has_more' => $has_more];
    }

    /**
     * @return array<int, array{role: string, content: string}>
     */
    protected function loadPersistedMessages(int $business_id, int $user_id, ?int $limit = null): array
    {
        if (! Schema::hasTable('ai_business_manager_messages')) {
            return [];
        }

        $query = DB::table('ai_business_manager_messages')
            ->where('business_id', $business_id)
            ->where('user_id', $user_id)
            ->orderByDesc('id');

        if (! empty($limit)) {
            $query->limit($limit);
        }

        return $query->get(['role', 'content'])
            ->reverse()
            ->map(fn ($row) => ['role' => (string) $row->role, 'content' => (string) $row->content])
            ->values()
            ->all();
    }

    protected function resolveFirstName($user): string
    {
        $first = trim((string) ($user->first_name ?? ''));
        if ($first !== '') {
            return $first;
        }

        return trim((string) ($user->username ?? ''));
    }

    /**
     * Exposed for sibling controllers (e.g. settings) that share the same accent styling.
     *
     * @return array{hex: string, rgb: string, key: string}
     */
    public function themeAccent(): array
    {
        return $this->resolveThemeAccent();
    }

    /**
     * Map TeamPOS business theme_color (skin key) to a hex accent for module UI.
     *
     * @return array{hex: string, rgb: string, key: string}
     */
    protected function resolveThemeAccent(): array
    {
        $key = strtolower((string) (session('business.theme_color') ?? 'primary'));

        $map = [
            'primary' => '#3c8dbc',
            'purple' => '#605ca8',
            'green' => '#00a65a',
            'red' => '#dd4b39',
            'yellow' => '#f39c12',
            'orange' => '#ff851b',
            'sky' => '#00c0ef',
            'blue-light' => '#3c8dbc',
            'black-light' => '#444444',
            'purple-light' => '#605ca8',
            'green-light' => '#00a65a',
            'red-light' => '#dd4b39',
            'yellow-light' => '#f39c12',
        ];

        $hex = $map[$key] ?? $map['primary'];
        $hex = ltrim($hex, '#');
        if (strlen($hex) !== 6 || ! ctype_xdigit($hex)) {
            $hex = ltrim($map['primary'], '#');
        }

        $r = hexdec(substr($hex, 0, 2));
        $g = hexdec(substr($hex, 2, 2));
        $b = hexdec(substr($hex, 4, 2));

        return [
            'hex' => '#'.$hex,
            'rgb' => $r.', '.$g.', '.$b,
            'key' => $key,
        ];
    }
}
