<?php

namespace Modules\Cashier\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Cashier\Services\CatalogPull;
use Modules\Cashier\Services\SaleCreator;

class SyncController extends Controller
{
    public function __construct(
        private CatalogPull $catalogPull,
        private SaleCreator $saleCreator
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
            'payments' => 'required|array|size:1',
            'payments.*.method' => 'required|string',
            'payments.*.amount' => 'required|numeric|min:0',
        ]);

        return response()->json(
            $this->saleCreator->create(auth()->user(), $data)
        );
    }
}
