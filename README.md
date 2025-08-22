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
use Ostap\Monivra\PayPal;

$paypal = new PayPal("xxxx", "ssss", true);

$result = $paypal->amount(10)->order("2025-001")->pay();
//header("Location: " . $result["approval_url"]);

$product = $paypal->createProduct("Hosting Mensile", "Abbonamento hosting web mensile");
echo "Product ID: " . $product["id"] . PHP_EOL;
		
$plan = $paypal->product($product["id"])->amount(10.00)->createPlan("Abbonamento hosting web mensile");
echo "Plan ID: " . $plan["id"]. PHP_EOL;
		
$result = $paypal->plan($plan["id"])->subscribe();
//header("Location: " . $result["approval_url"]);
```
