<script src="{{ $jsDomain }}/googlepay/v1/googlepay.js"></script>
<script>
var mfGpConfig = {
    sessionId: "{{ $mfSession->SessionId }}", // Here you add the "SessionId" you receive from the InitiateSession endpoint.
    countryCode: "{{ $mfSession->CountryCode }}", // Here, add your country code.
    amount: "{{ $paymentMethods['gp']->GatewayData['GatewayTotalAmount'] }}", // Add the invoice amount.
    currencyCode: "{{ $paymentMethods['gp']->GatewayData['GatewayCurrency'] }}", // Here, add your currency code.
    cardViewId: "mf-gp-element",
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

myFatoorahGP.init(mfGpConfig);
</script>
