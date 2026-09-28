<?php

namespace Modules\Cashier\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Cashier\Services\CashierRegister;
use Modules\Cashier\Services\CustomerWriter;
use Modules\Cashier\Services\SaleReturner;
use Modules\Cashier\Support\CashierContext;

/**
 * Online-only actions: the cash drawer, returns, and customer records.
 */
class DeskController extends Controller
{
    public function __construct(
        private CashierRegister $register,
        private SaleReturner $returns,
        private CustomerWriter $customers
    ) {
    }

    public function register()
    {
        return response()->json($this->register->summary(auth()->user()));
    }

    public function openRegister(Request $request)
    {
        $data = $request->validate([
            'location_id' => 'required|integer',
            'opening_cash' => 'nullable|numeric|min:0|max:100000000',
        ]);
        CashierContext::bind(auth()->user());
        $this->register->open(auth()->user(), (int) $data['location_id'], (float) ($data['opening_cash'] ?? 0));

        return response()->json($this->register->summary(auth()->user()));
    }

    public function closeRegister(Request $request)
    {
        $data = $request->validate([
            'closing_cash' => 'required|numeric|min:0|max:100000000',
            'note' => 'nullable|string|max:500',
        ]);

        return response()->json(
            $this->register->close(auth()->user(), (float) $data['closing_cash'], $data['note'] ?? null)
        );
    }

    public function storeReturn(Request $request)
    {
        $data = $request->validate([
            'client_uuid' => 'required|uuid',
            'device_ref' => 'nullable|string|max:32',
            'transaction_id' => 'required|integer',
            'refund_method' => 'required|string|max:32',
            'lines' => 'required|array|min:1',
            'lines.*.sell_line_id' => 'required|integer',
            'lines.*.quantity' => 'required|numeric|gt:0',
        ]);

        return response()->json($this->returns->create(auth()->user(), $data));
    }

    public function storeCustomer(Request $request)
    {
        return response()->json($this->customers->create(auth()->user(), $this->customerData($request, true)));
    }

    public function updateCustomer(Request $request, int $contact)
    {
        return response()->json($this->customers->update(auth()->user(), $contact, $this->customerData($request, false)));
    }

    private function customerData(Request $request, bool $creating): array
    {
        return $request->validate(array_filter([
            'client_uuid' => $creating ? 'required|uuid' : null,
            'name' => 'required|string|max:191',
            'business_name' => 'nullable|string|max:191',
            'mobile' => 'required|string|min:7|max:32',
            'email' => 'nullable|email|max:191',
            'address' => 'nullable|string|max:191',
        ]));
    }
}
