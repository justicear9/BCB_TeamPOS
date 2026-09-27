<?php

namespace App\Http\Controllers;

use Exception;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use MyFatoorah\Library\API\MyFatoorahSupplier;
use MyFatoorah\Library\API\Payment\MyFatoorahPayment;
use MyFatoorah\Library\API\Payment\MyFatoorahPaymentEmbedded;
use MyFatoorah\Library\API\Payment\MyFatoorahPaymentStatus;
use MyFatoorah\Library\MyFatoorah;

class MyFatoorahController extends Controller {

    /**
     * MyFatoorah Config Array
     * 
     * @var array
     */
    public $mfConfig = [];

    /**
     * Store Config Array
     * 
     * @var array
     */
    private $params = [];

//-----------------------------------------------------------------------------------------------------------------------------------------

    /**
     * Initiate MyFatoorah Configuration
     */
    public function __construct() {
        $this->params = config('myfatoorah');

        $this->mfConfig = [
            'apiKey'    => $this->params['api_key'],
            'isTest'    => $this->params['is_test'],
            'vcCode'    => $this->params['vc_code'],
            'loggerObj' => storage_path('logs/myfatoorah.log')
        ];
    }

//-----------------------------------------------------------------------------------------------------------------------------------------

    /**
     * Example on how to redirect the system to MyFatoorah invoice URL
     * Provide the index method with the order id and (payment method id or session id)
     *
     * @return RedirectResponse|JsonResponse
     */
    public function index() {
        try {
            //For example: pmid=0 for MyFatoorah invoice or pmid=1 for Knet in test mode
            $paymentId = request('pmid') ?: 0;
            $sessionId = request('sid') ?: null;

            $orderId  = request('oid') ?: 147;
            $curlData = $this->getPayLoadData($orderId);

            $mfObj   = new MyFatoorahPayment($this->mfConfig);
            $payment = $mfObj->getInvoiceURL($curlData, $paymentId, $orderId, $sessionId);

            return redirect($payment['invoiceURL']);
        } catch (Exception $ex) {
            $exMessage = $this->mfTransMsg($ex->getMessage());
            return response()->json(['IsSuccess' => false, 'Message' => $exMessage]);
        }
    }

//-----------------------------------------------------------------------------------------------------------------------------------------

    /**
     * Example on how to map order data to MyFatoorah
     * You can get the data using the order object in your system
     * 
     * @param string $orderId
     * 
     * @return array
     */
    private function getPayLoadData($orderId) {
        $callbackURL = route('myfatoorah.process');

        //You can get the data using the order object in your system
        $order  = $this->mfGetTestOrderData($orderId);
        $amount = $order['total'];
        return [
            'CustomerName'       => 'FName LName',
            'InvoiceValue'       => $amount,
            'DisplayCurrencyIso' => $order['currency'],
            'CustomerEmail'      => 'test@test.com',
            'CallBackUrl'        => $callbackURL,
            'ErrorUrl'           => $callbackURL,
            'MobileCountryCode'  => '+965',
            'CustomerMobile'     => '12345678',
            'Language'           => app()->getLocale(),
            'CustomerReference'  => $orderId,
            'SourceInfo'         => 'Laravel ' . app()::VERSION . ' - MyFatoorah Package ' . MYFATOORAH_LARAVEL_PACKAGE_VERSION,
            'Suppliers'          => $this->getSupplierInfo($amount),
        ];
    }

//-----------------------------------------------------------------------------------------------------------------------------------------

    /**
     * Example on a loading page that used to wait MyFatoorah response to be received.
     * 
     * @return View
     */
    public function process() {
        $paymentId = request('paymentId');
        if (!$paymentId) {
            return abort(404);
        }

        $callbackURL = route('myfatoorah.callback');
        return view('myfatoorah.process', compact('paymentId', 'callbackURL'));
    }

//-----------------------------------------------------------------------------------------------------------------------------------------

    /**
     * Example on how to get MyFatoorah Payment Information
     * Provide the callback method with the paymentId
     * 
     * @return JsonResponse
     */
    public function callback() {
        $paymentId = request('paymentId');
        if (!$paymentId) {
            return abort(404);
        }

        try {
            $mfObj = new MyFatoorahPaymentStatus($this->mfConfig);
            $data  = $mfObj->getPaymentStatus($paymentId, 'PaymentId');

            $message = $this->mfGetTestMessage($data->InvoiceStatus, $data->InvoiceError);

            return response()->json(['IsSuccess' => true, 'Message' => $message, 'Data' => $data]);
        } catch (Exception $ex) {
            $exMessage = $this->mfTransMsg($ex->getMessage());
            return response()->json(['IsSuccess' => false, 'Message' => $exMessage]);
        }
    }

//-----------------------------------------------------------------------------------------------------------------------------------------

    /**
     * Example on how to display the enabled gateways at your MyFatoorah account to be displayed on the checkout page
     * Provide the checkout method with the order id to display its total amount and currency
     * 
     * @return View
     * 
     * @throws Exception
     */
    public function checkout() {
        try {
            //You can get the data using the order object in your system
            $orderId = request('oid') ?: 147;
            $order   = $this->mfGetTestOrderData($orderId);

            //You can replace this variable with customer Id in your system
            $customerId = request('customerId');

            //You can use the user defined field if you want to save card
            $userDefinedField = $this->params['save_card'] && $customerId ? "CK-$customerId" : '';

            //Get the enabled gateways at your MyFatoorah account to be displayed on the checkout page
            $mfObj          = new MyFatoorahPaymentEmbedded($this->mfConfig);
            $paymentMethods = $mfObj->getCheckoutGateways($order['total'], $order['currency'], $this->params['register_apple_pay']);

            if (empty($paymentMethods['all'])) {
                throw new Exception('noPaymentGateways');
            }

            //Generate MyFatoorah session for embedded payment
            $mfSession = $mfObj->getEmbeddedSession($userDefinedField);

            //Get Environment url
            $isTest = $this->mfConfig['isTest'];
            $vcCode = $this->mfConfig['vcCode'];

            $countries = MyFatoorah::getMFCountries();
            $jsDomain  = ($isTest) ? $countries[$vcCode]['testPortal'] : $countries[$vcCode]['portal'];

            return view('myfatoorah.checkout', compact('mfSession', 'paymentMethods', 'jsDomain', 'userDefinedField'));
        } catch (Exception $ex) {
            $exMessage = $this->mfTransMsg($ex->getMessage());
            return view('myfatoorah.error', ['message' => $exMessage]);
        }
    }

//-----------------------------------------------------------------------------------------------------------------------------------------

    /**
     * Example on how the webhook is working when MyFatoorah try to notify your system about any transaction status update
     * 
     * @param Request $request
     * 
     * @return JsonResponse
     */
    public function webhook(Request $request) {
        //Validate webhook_secret_key
        $secretKey = $this->params['webhook_secret_key'];
        if (empty($secretKey)) {
            return response()->json(null, 404);
        }

        //Validate MyFatoorah-Signature
        $mfSignature = $request->header('MyFatoorah-Signature');
        if (empty($mfSignature)) {
            return response()->json(null, 404);
        }

        //Validate input
        $body  = $request->getContent();
        $input = json_decode($body, true);
        if (empty($input['Data']) || empty($input['EventType']) || $input['EventType'] != 1) {
            return response()->json(null, 404);
        }

        //Validate Signature
        if (!MyFatoorah::isSignatureValid($input['Data'], $secretKey, $mfSignature, $input['EventType'])) {
            return response()->json(null, 404);
        }

        //Update Transaction status on your system
        return $this->changeTransactionStatus($input['Data']);
    }

//-----------------------------------------------------------------------------------------------------------------------------------------

    /**
     * Example on how to update your system with the order status that comes from a webhook
     * 
     * @param array $inputData
     * 
     * @return JsonResponse
     */
    private function changeTransactionStatus($inputData) {
        try {
            //1. Check if orderId is valid on your system.
            $orderId = $inputData['CustomerReference'];

            //2. Get MyFatoorah invoice id
            $invoiceId = $inputData['InvoiceId'];

            //3. Check order status at MyFatoorah side
            $message = 'Invoice is paid.';
            if ($inputData['TransactionStatus'] != 'SUCCESS') {
                //get the error if you want using the getPaymentStatus API endpoint
                $mfObj = new MyFatoorahPaymentStatus($this->mfConfig);
                $data  = $mfObj->getPaymentStatus($invoiceId, 'InvoiceId');

                $message = $this->mfGetTestMessage($data->InvoiceStatus, $data->InvoiceError);
            }

            //4. Update order transaction status on your system
            return response()->json(['IsSuccess' => true, 'Message' => $message, 'Data' => $inputData]);
        } catch (Exception $ex) {
            $exMessage = $this->mfTransMsg($ex->getMessage());
            return response()->json(['IsSuccess' => false, 'Message' => $exMessage]);
        }
    }

//-----------------------------------------------------------------------------------------------------------------------------------------

    /**
     * Example on how to get the supplier array to pass it to the payload of the invoice creation
     * 
     * @param number $amount
     * 
     * @return array|null
     * 
     * @throws Exception
     */
    private function getSupplierInfo($amount) {
        $supplierCode = $this->params['supplier_code'];
        if ($supplierCode == null) {
            return null;
        }

        if (!is_integer($supplierCode) || $supplierCode <= 0) {
            throw new Exception("Invalid Supplier code $supplierCode.");
        }

        $myfatoorahSupplier = new MyFatoorahSupplier($this->mfConfig);
        if (!$myfatoorahSupplier->isSupplierApproved($supplierCode)) {
            throw new Exception("Supplier code $supplierCode is not active in vendor account, please contact MyFatoorah team to activate it.");
        }

        return [[
        'SupplierCode'  => $supplierCode,
        'ProposedShare' => null,
        'InvoiceShare'  => $amount
        ]];
    }

//-----------------------------------------------------------------------------------------------------------------------------------------
    private function mfTransMsg($msg) {
        return __('myfatoorah.' . $msg);
    }

//-----------------------------------------------------------------------------------------------------------------------------------------
    private function mfGetTestOrderData($orderId) {
        return [
            'orderId'  => $orderId,
            'total'    => 1234.56,
            'currency' => 'KWD'
        ];
    }

//-----------------------------------------------------------------------------------------------------------------------------------------
    private function mfGetTestMessage($status, $error) {
        if ($status == 'Paid' || $status == 'SUCCESS') {
            return 'Invoice is paid.';
        } else if ($status == 'Failed') {
            return 'Invoice is not paid due to ' . $error;
        } else if ($status == 'Expired') {
            return $error;
        }
    }

//-----------------------------------------------------------------------------------------------------------------------------------------
}
