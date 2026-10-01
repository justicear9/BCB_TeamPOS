<?php

namespace Modules\Cashier\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Cashier\Services\CatalogPull;
use Modules\Cashier\Services\PaymentRecorder;
use Modules\Cashier\Services\ReceiptBuilder;
use Modules\Cashier\Services\SaleCreator;

class SyncController extends Controller
{
    public function __construct(
        private CatalogPull $catalogPull,
        private SaleCreator $saleCreator,
        private ReceiptBuilder $receipts,
        private PaymentRecorder $payments
    ) {
    }

    public function locations()
    {
        return response()->json([
            'data' => $this->catalogPull->locations(auth()->user()),
        ]);
    }

    public function changes(Request $request)
    {
        $data = $request->validate([
            'location_id' => 'required|integer',
            'since' => 'nullable|date',
        ]);

        return response()->json(
            $this->catalogPull->changes(
                auth()->user(),
                (int) $data['location_id'],
                $data['since'] ?? null
            )
        );
    }

    public function store(Request $request)
    {
        if ($request->input('type') === 'payment.add') {
            $data = $request->validate([
                'type' => 'required|in:payment.add',
                'client_uuid' => 'required|uuid',
                'device_ref' => 'nullable|string|max:32',
                'sale_client_uuid' => 'nullable|uuid',
                'transaction_id' => 'nullable|integer',
                'method' => 'required|string',
                'amount' => 'required|numeric|gt:0',
            ]);

            return response()->json($this->payments->add(auth()->user(), $data));
        }

        if ($request->input('type') === 'payment.advance') {
            $data = $request->validate([
                'type' => 'required|in:payment.advance',
                'client_uuid' => 'required|uuid',
                'device_ref' => 'nullable|string|max:32',
                'contact_id' => 'required|integer',
                'location_id' => 'nullable|integer',
                'method' => 'required|string',
                'amount' => 'required|numeric|gt:0',
            ]);

            return response()->json($this->payments->advance(auth()->user(), $data));
        }

        $data = $request->validate([
            'type' => 'required|in:sale.create',
            'client_uuid' => 'required|uuid',
            'device_ref' => 'required|string|max:32',
            'location_id' => 'required|integer',
            'contact_id' => 'required|integer',
            'transaction_date' => 'required|date',
            'products' => 'required|array|min:1',
            'products.*.product_id' => 'required|integer',
            'products.*.variation_id' => 'required|integer',
            'products.*.quantity' => 'required|numeric|gt:0',
            'products.*.unit_price' => 'required|numeric|min:0',
            'products.*.price_override' => 'nullable|boolean',
            'discount_type' => 'nullable|in:fixed,percentage',
            'discount_amount' => 'nullable|numeric|min:0',
            'rp_redeemed' => 'nullable|integer|min:0',
            'selling_price_group_id' => 'nullable|integer|min:0',
            'payments' => 'present|array',
            'payments.*.method' => 'required|string',
            'payments.*.amount' => 'required|numeric|min:0',
            'payments.*.tendered' => 'nullable|numeric|min:0',
            'latitude' => 'nullable|numeric|between:-90,90',
            'longitude' => 'nullable|numeric|between:-180,180',
            'accuracy' => 'nullable|numeric|min:0',
        ]);

        return response()->json(
            $this->saleCreator->create(auth()->user(), $data)
        );
    }

    public function sale(int $transaction)
    {
        return response()->json($this->catalogPull->sale(auth()->user(), $transaction));
    }

    public function receipt(int $transaction)
    {
        return response()->json([
            'html' => $this->receipts->html(auth()->user(), $transaction),
        ]);
    }
}
