<?php

namespace Modules\Cashier\Services;

use App\Business;
use App\BusinessLocation;
use App\Contact;
use App\Product;
use App\Transaction;
use App\User;
use App\Utils\BusinessUtil;
use App\Utils\ProductUtil;
use App\Utils\TransactionUtil;
use App\VariationLocationDetails;
use Illuminate\Support\Facades\DB;
use Modules\Cashier\Entities\CashierClientRef;

class SaleCreator
{
    public function __construct(
        private TransactionUtil $transactionUtil,
        private ProductUtil $productUtil,
        private BusinessUtil $businessUtil
    ) {
    }

    public function create(User $user, array $payload): array
    {
        auth()->setUser($user);

        if (! $user->can('sell.create')) {
            abort(403, 'Missing sell.create');
        }

        $existing = CashierClientRef::where('business_id', $user->business_id)
            ->where('client_uuid', $payload['client_uuid'])
            ->where('entity_type', 'sale')
            ->first();

        if ($existing) {
            $transaction = Transaction::where('business_id', $user->business_id)
                ->where('id', $existing->entity_id)
                ->firstOrFail();

            return $this->present($existing, $transaction, true);
        }

        if (! User::can_access_this_location($payload['location_id'], $user->business_id)) {
            abort(403, 'Location is not allowed');
        }

        return DB::transaction(function () use ($user, $payload) {
            $businessId = $user->business_id;
            $location = BusinessLocation::where('business_id', $businessId)
                ->findOrFail($payload['location_id']);
            $contact = Contact::where('business_id', $businessId)
                ->whereIn('type', ['customer', 'both'])
                ->findOrFail($payload['contact_id']);

            $lines = [];
            foreach ($payload['products'] as $line) {
                $product = Product::where('business_id', $businessId)
                    ->with('variations')
                    ->findOrFail($line['product_id']);
                $variation = $product->variations->firstWhere('id', (int) $line['variation_id']);
                if (! $variation) {
                    abort(422, 'Variation is not on this product');
                }

                $unitPrice = (float) $line['unit_price'];
                $lines[] = [
                    'product_id' => $product->id,
                    'variation_id' => $variation->id,
                    'product_type' => $product->type,
                    'unit_price' => $unitPrice,
                    'line_discount_type' => 'fixed',
                    'line_discount_amount' => 0,
                    'tax_id' => null,
                    'item_tax' => 0,
                    'sell_line_note' => null,
                    'enable_stock' => $product->enable_stock,
                    'quantity' => $line['quantity'],
                    'product_unit_id' => $product->unit_id,
                    'sub_unit_id' => null,
                    'unit_price_inc_tax' => $unitPrice,
                    'base_unit_multiplier' => 1,
                ];
            }

            $discount = ['discount_type' => 'fixed', 'discount_amount' => 0];
            $invoiceTotal = $this->productUtil->calculateInvoiceTotal($lines, null, $discount, false);

            $input = [
                'location_id' => $location->id,
                'contact_id' => $contact->id,
                'transaction_date' => $payload['transaction_date'],
                'status' => 'final',
                'source' => 'cashier_app',
                'discount_type' => 'fixed',
                'discount_amount' => 0,
                'final_total' => $invoiceTotal['final_total'],
                'is_created_from_api' => 1,
                'products' => $lines,
            ];

            $transaction = $this->transactionUtil->createSellTransaction(
                $businessId,
                $input,
                $invoiceTotal,
                $user->id,
                false
            );

            $this->transactionUtil->createOrUpdateSellLines(
                $transaction,
                $lines,
                $location->id,
                false,
                null,
                [],
                false
            );

            $payments = [];
            foreach ($payload['payments'] as $payment) {
                $payments[] = array_merge($this->emptyPayment(), [
                    'method' => $payment['method'],
                    'amount' => $payment['amount'],
                ]);
            }
            $payments[] = array_merge($this->emptyPayment(), [
                'amount' => 0,
                'is_return' => 1,
            ]);

            $this->transactionUtil->createOrUpdatePaymentLines(
                $transaction,
                $payments,
                $businessId,
                $user->id,
                false
            );

            foreach ($lines as $line) {
                if ($line['enable_stock']) {
                    $this->productUtil->decreaseProductQuantity(
                        $line['product_id'],
                        $line['variation_id'],
                        $location->id,
                        $line['quantity']
                    );
                }
            }

            $transaction->refresh();
            $this->transactionUtil->updatePaymentStatus($transaction->id, $transaction->final_total);

            $business = Business::findOrFail($businessId);
            $details = $this->businessUtil->getDetails($businessId);
            $posSettings = empty($details->pos_settings)
                ? $this->businessUtil->defaultPosSettings()
                : json_decode($details->pos_settings, true);
            // The sale is already finished on the device. Purchase mapping must not reject it.
            $posSettings['allow_overselling'] = 1;
            $this->transactionUtil->mapPurchaseSell([
                'id' => $businessId,
                'accounting_method' => $business->accounting_method,
                'location_id' => $location->id,
                'pos_settings' => $posSettings,
            ], $transaction->sell_lines, 'purchase');

            $ref = CashierClientRef::create([
                'business_id' => $businessId,
                'client_uuid' => $payload['client_uuid'],
                'entity_type' => 'sale',
                'entity_id' => $transaction->id,
                'device_ref' => $payload['device_ref'],
            ]);

            return $this->present($ref, $transaction->fresh(), false);
        });
    }

    private function present(CashierClientRef $ref, Transaction $transaction, bool $replayed): array
    {
        return [
            'client_uuid' => $ref->client_uuid,
            'entity_type' => 'sale',
            'entity_id' => $transaction->id,
            'invoice_no' => $transaction->invoice_no,
            'final_total' => $transaction->final_total,
            'device_ref' => $ref->device_ref,
            'replayed' => $replayed,
        ];
    }

    private function emptyPayment(): array
    {
        return [
            'method' => 'cash',
            'amount' => 0,
            'note' => '',
            'card_transaction_number' => '',
            'card_number' => '',
            'card_type' => '',
            'card_holder_name' => '',
            'card_month' => '',
            'card_year' => '',
            'card_security' => '',
            'cheque_number' => '',
            'bank_account_number' => '',
            'is_return' => 0,
            'transaction_no' => '',
        ];
    }
}
