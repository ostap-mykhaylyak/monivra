## 📦 Installation

Install the module via Composer:
```bash
composer config repositories.monivra vcs https://github.com/ostap-mykhaylyak/monivra
```
```bash
composer require ostap-mykhaylyak/monivra:dev-main
```
```php
<?php
require __DIR__ . "/../vendor/autoload.php";

use Ostap\Monivra\PayPal;

$paypal = new PayPal("CLIENT_ID", "CLIENT_SECRET", true);

$result = $paypal->amount(10)->order("2025-001")->pay();
header("Location: " . $result["approval_url"]);
```

```php
<?php
require __DIR__ . "/../vendor/autoload.php";

use Ostap\Monivra\PayPal;

$paypal = new PayPal("CLIENT_ID", "CLIENT_SECRET", true);

$result = $paypal->amount(20)->order("SUB-001")->subscribe("M", "PROD-XXXX");
header("Location: " . $result["approval_url"]);
```

```php
<?php
require __DIR__ . "/../vendor/autoload.php";

use Ostap\Monivra\PayPal;

$paypal = new PayPal("CLIENT_ID", "CLIENT_SECRET", true);

$body = file_get_contents("php://input");
$headers = array_change_key_case(getallheaders(), CASE_LOWER);

$webhookId = "IL_TUO_WEBHOOK_ID";

if (!$paypal->verifyWebhook($headers, $body, $webhookId)) {
    http_response_code(400);
    exit("Invalid webhook signature");
}

$event = json_decode($body, true);

switch ($event["event_type"]) {
    case "PAYMENT.CAPTURE.COMPLETED":
        // aggiorna DB pagamento singolo
        break;
    case "PAYMENT.SALE.COMPLETED":
        // rinnovo abbonamento
        break;
    case "BILLING.SUBSCRIPTION.CANCELLED":
        // abbonamento annullato
        break;
}

http_response_code(200);
echo "OK";
```
