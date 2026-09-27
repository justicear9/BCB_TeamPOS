<?php

namespace Modules\Cashier\Services;

use App\BusinessLocation;
use App\Contact;
use App\Product;
use App\Transaction;
use App\User;
use App\Utils\Util;
use Carbon\Carbon;

class CatalogPull
{
    public function __construct(private Util $util)
    {
    }

    public function locations(User $user): array
    {
        auth()->setUser($user);
        $query = BusinessLocation::where('business_id', $user->business_id);
        $permitted = $user->permitted_locations($user->business_id);
        if ($permitted !== 'all') {
            $query->whereIn('id', $permitted);
        }

        return $query->orderBy('name')->get(['id', 'name'])
            ->map(fn ($location) => ['id' => $location->id, 'name' => $location->name])
            ->all();
    }

    public function changes(User $user, int $locationId, ?string $since): array
    {
        auth()->setUser($user);
        if (! User::can_access_this_location($locationId, $user->business_id)) {
            abort(403, 'Location is not allowed');
        }

        $location = BusinessLocation::where('business_id', $user->business_id)->find($locationId);
        if (! $location) {
            abort(403, 'Location is not allowed');
        }
        $sinceAt = $since ? Carbon::parse($since) : null;

        return [
            'server_time' => now()->format('Y-m-d H:i:s'),
            'location' => ['id' => $location->id, 'name' => $location->name],
            'products' => $this->products($user->business_id, $location->id, $sinceAt),
            'customers' => $this->customers($user->business_id, $sinceAt),
            'payment_methods' => $this->paymentMethods($location, $user->business_id),
            'sales' => $this->sales($user->business_id, $location->id, $sinceAt),
            'removed_product_variation_ids' => [],
            'removed_customer_ids' => $this->removedCustomers($user->business_id, $sinceAt),
        ];
    }

    private function products(int $businessId, int $locationId, ?Carbon $since): array
    {
        $query = Product::where('business_id', $businessId)
            ->where('is_inactive', 0)
            ->where('not_for_selling', 0)
            ->where('type', '!=', 'modifier')
            ->with(['variations.variation_location_details' => function ($q) use ($locationId) {
                $q->where('location_id', $locationId);
            }]);

        if ($since) {
            $query->where(function ($q) use ($since, $locationId) {
                $q->where('products.updated_at', '>', $since)
                    ->orWhereHas('variations.variation_location_details', function ($q) use ($since, $locationId) {
                        $q->where('location_id', $locationId)
                            ->where('updated_at', '>', $since);
                    });
            });
        }

        $rows = [];
        foreach ($query->get() as $product) {
            foreach ($product->variations as $variation) {
                $stock = $variation->variation_location_details->first();
                $rows[] = [
                    'product_id' => $product->id,
                    'variation_id' => $variation->id,
                    'name' => $product->name,
                    'variation_name' => $variation->name,
                    'sku' => $variation->sub_sku ?: $product->sku,
                    'sell_price' => (string) $variation->sell_price_inc_tax,
                    'qty_available' => (string) ($stock->qty_available ?? 0),
                ];
            }
        }

        return $rows;
    }

    private function customers(int $businessId, ?Carbon $since): array
    {
        $query = Contact::where('business_id', $businessId)
            ->whereIn('type', ['customer', 'both'])
            ->where('contact_status', 'active');
        if ($since) {
            $query->where('updated_at', '>', $since);
        }

        return $query->get(['id', 'name', 'mobile', 'is_default'])
            ->map(fn ($contact) => [
                'id' => $contact->id,
                'name' => $contact->name,
                'mobile' => $contact->mobile,
                'is_default' => (int) $contact->is_default,
            ])->all();
    }

    private function removedCustomers(int $businessId, ?Carbon $since): array
    {
        if (! $since) {
            return [];
        }

        return Contact::where('business_id', $businessId)
            ->whereIn('type', ['customer', 'both'])
            ->where('contact_status', '!=', 'active')
            ->where('updated_at', '>', $since)
            ->pluck('id')
            ->all();
    }

    private function paymentMethods($location, int $businessId): array
    {
        $types = $this->util->payment_types($location, false, $businessId);
        $rows = [];
        foreach ($types as $id => $label) {
            $rows[] = ['id' => $id, 'label' => $label];
        }

        return $rows;
    }

    private function sales(int $businessId, int $locationId, ?Carbon $since): array
    {
        $query = Transaction::where('business_id', $businessId)
            ->where('location_id', $locationId)
            ->where('type', 'sell');

        if ($since) {
            $query->where('updated_at', '>', $since);
        } else {
            $query->where(function ($q) {
                $q->where('transaction_date', '>=', now()->subDays(90))
                    ->orWhere('payment_status', '!=', 'paid');
            });
        }

        return $query->get(['id', 'invoice_no', 'contact_id', 'final_total', 'payment_status', 'transaction_date'])
            ->map(fn ($sale) => [
                'id' => $sale->id,
                'invoice_no' => $sale->invoice_no,
                'contact_id' => $sale->contact_id,
                'final_total' => (string) $sale->final_total,
                'payment_status' => $sale->payment_status,
                'transaction_date' => (string) $sale->transaction_date,
            ])->all();
    }
}
