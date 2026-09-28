<?php

namespace Modules\AIBusinessManager\Services\Manager;

use App\Business;
use App\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Business, timezone, currency and permitted locations for one Eli request.
 * Every manager query is limited to $businessId and $locationIds (null = all locations of the business).
 */
class ManagerScope
{
    /** @var array<int, string> */
    public array $locationNames = [];

    /**
     * @param  list<int>|null  $locationIds
     */
    public function __construct(
        public int $businessId,
        public string $timezone,
        public int $precision,
        public string $symbol,
        public ?array $locationIds,
        public ?int $userId = null,
    ) {
        $this->locationNames = DB::table('business_locations')
            ->where('business_id', $businessId)
            ->when($locationIds !== null, fn ($q) => $q->whereIn('id', $locationIds === [] ? [0] : $locationIds))
            ->orderBy('id')
            ->pluck('name', 'id')
            ->mapWithKeys(fn ($name, $id) => [(int) $id => (string) $name])
            ->all();
    }

    public static function forUser(User $user, int $businessId): ?self
    {
        $business = Business::with('currency')->find($businessId);
        if (! $business) {
            return null;
        }
        $permitted = $user->permitted_locations($businessId);
        $locationIds = $permitted === 'all' ? null : array_values(array_map('intval', is_array($permitted) ? $permitted : []));

        return new self(
            $businessId,
            (string) ($business->time_zone ?: config('app.timezone')),
            (int) ($business->currency_precision ?? 2),
            (string) ($business->currency->symbol ?? ''),
            $locationIds,
            (int) $user->id,
        );
    }

    public static function forBusiness(int $businessId): ?self
    {
        $business = Business::with('currency')->find($businessId);
        if (! $business) {
            return null;
        }

        return new self(
            $businessId,
            (string) ($business->time_zone ?: config('app.timezone')),
            (int) ($business->currency_precision ?? 2),
            (string) ($business->currency->symbol ?? ''),
            null,
        );
    }

    /**
     * @return list<int>
     */
    public function visibleLocationIds(): array
    {
        return array_keys($this->locationNames);
    }

    public function now(): Carbon
    {
        return Carbon::now($this->timezone);
    }

    public function today(): Carbon
    {
        return $this->now()->startOfDay();
    }

    public function money(float $amount): float
    {
        return round($amount, $this->precision);
    }

    /**
     * Narrow to one location the user can see. Returns null when the location is not visible.
     */
    public function narrowTo(int $locationId): ?self
    {
        if (! isset($this->locationNames[$locationId])) {
            return null;
        }
        $copy = clone $this;
        $copy->locationIds = [$locationId];
        $copy->locationNames = [$locationId => $this->locationNames[$locationId]];

        return $copy;
    }

    /**
     * Resolve location_id / location_name tool arguments.
     *
     * @param  array<string, mixed>  $args
     * @return array{scope?: self, error?: array<string, mixed>}
     */
    public function resolveLocationArgs(array $args): array
    {
        if (isset($args['location_id']) && is_numeric($args['location_id']) && (int) $args['location_id'] > 0) {
            $narrow = $this->narrowTo((int) $args['location_id']);

            return $narrow ? ['scope' => $narrow] : ['error' => ['ok' => false, 'error' => 'location_forbidden_or_unknown']];
        }
        $name = isset($args['location_name']) ? mb_strtolower(trim((string) $args['location_name'])) : '';
        if ($name === '') {
            return ['scope' => $this];
        }
        $exact = [];
        $partial = [];
        foreach ($this->locationNames as $id => $locationName) {
            $lower = mb_strtolower($locationName);
            if ($lower === $name) {
                $exact[] = $id;
            } elseif (str_contains($lower, $name)) {
                $partial[] = $id;
            }
        }
        $matches = $exact !== [] ? $exact : $partial;
        if ($matches === []) {
            return ['error' => ['ok' => false, 'error' => 'location_not_found', 'locations' => array_values($this->locationNames)]];
        }
        if (count($matches) > 1) {
            return ['error' => [
                'ok' => true,
                'ambiguous' => true,
                'matches' => array_map(fn ($id) => ['location_id' => $id, 'location_name' => $this->locationNames[$id]], $matches),
                'note' => 'Several locations matched. Call again with location_id.',
            ]];
        }

        return ['scope' => $this->narrowTo($matches[0])];
    }
}
