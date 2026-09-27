# <ins>MyFatoorah Laravel Package</ins>

## Description
This package is the official MyFatoorah Payment Gateway Laravel package.
It uses [myfatoorah/library](https://packagist.org/packages/myfatoorah/library) composer package. 

## Main Features
* Example of creating MyFatoorah invoices.
* Example of displaying the MyFatoorah payment status.
* Example of displaying the enabled gateways at your MyFatoorah account to be displayed on the checkout page.
* Example of how to use webhook.

## Important Note!
The MyFatoorah Laravel package provides **examples** of how to use the MyFatoorah Library and the MyFatoorah API endpoints.
However, you must implement validations or security measures to ensure a seamless payment experience.
For more information, contact [MyFatoorah Technical Team](mailto:tech@myfatoorah.com).


# <ins>Installation steps</ins>

## Install the Package
Install the package via [myfatoorah/laravel-package](https://packagist.org/packages/myfatoorah/laravel-package) composer.
Open a command console, enter your project directory, and execute the following command to download the latest stable version:

```bash
composer require myfatoorah/laravel-package
```

## Publish the **MyFatoorah** provider 
Open the console, and enter the following command:

```bash
php artisan vendor:publish --provider="MyFatoorah\LaravelPackage\MyFatoorahServiceProvider" --tag="myfatoorah"
```

## Add Configuration Data
Edit the **config/myfatoorah.php** file with your correct vendor data.

### Demo configuration
1. Use the test API token key mentioned [here](https://myfatoorah.readme.io/docs/test-token).
2. Set the test mode to true.
3. Use one of [the test cards](https://myfatoorah.readme.io/docs/test-cards).

### Live Configuration
1. Use the live API token key mentioned [here](https://myfatoorah.readme.io/docs/live-token).
2. Set the test mode to false.
3. Set the country ISO code as mentioned in [this link](https://myfatoorah.readme.io/docs/iso-lookups).

## Clear Cache
Open the console, and enter the following command:

```bash
php artisan optimize:clear
```

## Test the Payment Cycle
To test the payment cycle, type the URL below onto your browser.
Replace only the **{example.com}** with your site domain:

```browser
https://{example.com}/myfatoorah
```

## List the Payment Gateways
To test the display of available gateways on the checkout page, please enter the following URL into your browser.
Replace only the **{example.com}** with your site domain:

```browser
https://{example.com}/myfatoorah/checkout?oid=22&customerId=32
```

## Code Customization
Understand the MyFatooah library functions in the **app/Http/Controllers/MyFatoorahController.php** file.
Then, use them in your project per your store needs. For security reasons, remove any unused MyFatoorah routes as a final step.
