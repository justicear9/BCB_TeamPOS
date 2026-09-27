<!DOCTYPE html>
<html lang="en-US">
    <head>
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <style>
            body {
                font-family: sans-serif;
            }
            .loader {
                border: 13px solid #f3f3f3;
                border-radius: 50%;
                border-top: 13px solid #3498db;
                width: 50px;
                height: 50px;
                -webkit-animation: spin 2s linear infinite; /* Safari */
                animation: spin 2s linear infinite;
            }

            /* Safari */
            @-webkit-keyframes spin {
                0% {
                    -webkit-transform: rotate(0deg);
                }
                100% {
                    -webkit-transform: rotate(360deg);
                }
            }

            @keyframes spin {
                0% {
                    transform: rotate(0deg);
                }
                100% {
                    transform: rotate(360deg);
                }
            }
        </style>
    </head>
    <body>
    <center style="margin:10%">
        Please wait while your transaction <b> {{ $paymentId }} </b> is processing...
        <br/><br/>Please do not refresh or close the window
        <br/><br/>
        <div class="loader"></div>
    </center>
    <script>window.location = "{{ $callbackURL }}?paymentId={{ $paymentId }}";</script>
</body>
</html>