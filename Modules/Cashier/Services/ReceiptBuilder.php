<?php

namespace Modules\Cashier\Services;

use App\BusinessLocation;
use App\Transaction;
use App\User;
use App\Utils\BusinessUtil;
use App\Utils\TransactionUtil;
use Modules\Cashier\Support\CashierContext;

class ReceiptBuilder
{
    public function __construct(
        private TransactionUtil $transactionUtil,
        private BusinessUtil $businessUtil
    ) {
    }

    public function html(User $user, int $transactionId): string
    {
        CashierContext::bind($user);

        $transaction = Transaction::where('business_id', $user->business_id)
            ->where('type', 'sell')
            ->findOrFail($transactionId);

        if (! User::can_access_this_location($transaction->location_id, $user->business_id)) {
            abort(403, 'Location is not allowed');
        }

        $businessDetails = $this->businessUtil->getDetails($user->business_id);
        $location = BusinessLocation::where('business_id', $user->business_id)
            ->findOrFail($transaction->location_id);
        $layoutId = $transaction->is_direct_sale
            ? $location->sale_invoice_layout_id
            : $location->invoice_layout_id;
        $invoiceLayout = $this->businessUtil->invoiceLayout($user->business_id, $layoutId);

        $receiptDetails = $this->transactionUtil->getReceiptDetails(
            $transaction->id,
            $location->id,
            $invoiceLayout,
            $businessDetails,
            $location,
            'browser'
        );
        $receiptDetails->currency = [
            'symbol' => $businessDetails->currency_symbol,
            'thousand_separator' => $businessDetails->thousand_separator,
            'decimal_separator' => $businessDetails->decimal_separator,
        ];

        $view = ! empty($receiptDetails->design)
            ? 'sale_pos.receipts.'.$receiptDetails->design
            : 'sale_pos.receipts.classic';
        $body = view($view, ['receipt_details' => $receiptDetails])->render();
        $title = e($receiptDetails->invoice_no ?: 'Receipt');
        $vendor = e(asset('css/vendor.css'));
        $app = e(asset('css/app.css'));

        return '<!DOCTYPE html><html><head><meta charset="utf-8">'
            .'<meta name="viewport" content="width=device-width, initial-scale=1">'
            .'<title>'.$title.'</title>'
            .'<link rel="stylesheet" href="'.$vendor.'">'
            .'<link rel="stylesheet" href="'.$app.'">'
            .'<style>body{background:#fff;color:#000;margin:0;} @media print{.no-print{display:none;}}</style>'
            .'</head><body><div class="container"><div class="row"><div class="col-xs-12">'
            .$body
            .'</div></div></div></body></html>';
    }
}
