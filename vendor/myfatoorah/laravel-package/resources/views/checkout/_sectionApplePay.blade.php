<script src="{{ $jsDomain }}/applepay/v3/applepay.js"></script>
<script>
var mfApAmount = "{{ $paymentMethods['ap']->GatewayData['GatewayTotalAmount'] }}".replace(',', '');
var mfApConfig = {
    sessionId: "{{ $mfSession->SessionId }}", // Here you add the "SessionId" you receive from the InitiateSession endpoint.
    countryCode: "{{ $mfSession->CountryCode }}", // Here, add your country code.
    amount: mfApAmount, // Add the invoice amount.
    currencyCode: "{{ $paymentMethods['ap']->GatewayData['GatewayCurrency'] }}", // Here, add your currency code.
    cardViewId: "mf-ap-element",
    callback: mfCallback,
        style: {
            frameHeight: 51,
            button: {
                height: "35px",
                text: "Pay with",
                borderRadius: "2px",
                color: "black",
                language: "en"
            }
        }
};
myFatoorahAP.init(mfApConfig);
</script>