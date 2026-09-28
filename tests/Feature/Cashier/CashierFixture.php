<?php

namespace Tests\Feature\Cashier;

use App\Business;
use App\BusinessLocation;
use App\Contact;
use App\Currency;
use App\InvoiceLayout;
use App\InvoiceScheme;
use App\Product;
use App\ProductVariation;
use App\Unit;
use App\User;
use App\Variation;
use App\VariationLocationDetails;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;

class CashierFixture
{
    public static function make(): array
    {
        $user = User::create([
            'first_name' => 'Cashier',
            'username' => 'cashier_'.Str::lower(Str::random(8)),
            'email' => Str::lower(Str::random(8)).'@cashier.test',
            'password' => Hash::make('secret'),
            'user_type' => 'user',
            'allow_login' => 1,
        ]);

        $business = Business::create([
            'name' => 'Cashier Test '.Str::random(4),
            'currency_id' => Currency::query()->value('id'),
            'owner_id' => $user->id,
            'stop_selling_before' => 0,
            'weighing_scale_setting' => '{}',
            'time_zone' => 'UTC',
            'fy_start_month' => 1,
            'accounting_method' => 'fifo',
            'sell_price_tax' => 'includes',
            'custom_labels' => '{}',
        ]);

        $user->business_id = $business->id;
        $user->save();

        $permissions = ['sell.create', 'sell.payments', 'access_all_locations', 'close_cash_register', 'access_sell_return', 'customer.create', 'customer.update'];
        foreach ($permissions as $name) {
            Permission::findOrCreate($name, 'web');
        }
        $user->givePermissionTo($permissions);

        $scheme = InvoiceScheme::create([
            'business_id' => $business->id,
            'name' => 'Cashier',
            'scheme_type' => 'blank',
            'prefix' => 'C',
            'start_number' => 1,
            'total_digits' => 4,
            'is_default' => 1,
        ]);

        $layout = InvoiceLayout::create([
            'business_id' => $business->id,
            'name' => 'Cashier',
            'design' => 'classic',
        ]);

        $location = BusinessLocation::create([
            'business_id' => $business->id,
            'name' => 'Counter',
            'country' => 'US',
            'state' => 'CA',
            'city' => 'LA',
            'zip_code' => '90001',
            'invoice_scheme_id' => $scheme->id,
            'invoice_layout_id' => $layout->id,
            'default_payment_accounts' => json_encode([
                'cash' => ['is_enabled' => 1],
                'card' => ['is_enabled' => 1],
            ]),
        ]);

        $unit = Unit::create([
            'business_id' => $business->id,
            'actual_name' => 'Pieces',
            'short_name' => 'Pc',
            'allow_decimal' => 0,
            'created_by' => $user->id,
        ]);

        $product = Product::create([
            'name' => 'Test Item',
            'business_id' => $business->id,
            'type' => 'single',
            'unit_id' => $unit->id,
            'tax_type' => 'inclusive',
            'enable_stock' => 1,
            'sku' => 'SKU-'.Str::upper(Str::random(6)),
            'barcode_type' => 'C128',
            'created_by' => $user->id,
            'is_inactive' => 0,
            'not_for_selling' => 0,
        ]);

        $product->product_locations()->sync([$location->id]);

        $productVariation = ProductVariation::create([
            'name' => 'DUMMY',
            'product_id' => $product->id,
            'is_dummy' => 1,
        ]);

        $variation = Variation::create([
            'name' => 'DUMMY',
            'product_id' => $product->id,
            'sub_sku' => $product->sku,
            'product_variation_id' => $productVariation->id,
            'default_sell_price' => 10,
            'sell_price_inc_tax' => 10,
        ]);

        VariationLocationDetails::create([
            'product_id' => $product->id,
            'product_variation_id' => $productVariation->id,
            'variation_id' => $variation->id,
            'location_id' => $location->id,
            'qty_available' => 5,
        ]);

        $contact = Contact::create([
            'business_id' => $business->id,
            'type' => 'customer',
            'name' => 'Walk-in',
            'mobile' => '0000000000',
            'created_by' => $user->id,
            'is_default' => 1,
            'contact_status' => 'active',
        ]);

        return compact('user', 'business', 'location', 'product', 'variation', 'contact');
    }
}
