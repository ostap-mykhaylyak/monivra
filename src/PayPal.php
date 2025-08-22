<?php
namespace Ostap\Monivra;

use Exception;

class PayPal
{
    private string $clientId;
    private string $clientSecret;
    private string $apiBase;
    private float $amount;
    private string $currency = "EUR";
	private string $productId;
    private string $orderId;
	private string $planId;
	private string $intervalUnit = "MONTH"; // YEAR
    private ?string $accessToken = null;

    public function __construct(string $clientId, string $clientSecret, bool $sandbox = true)
    {
        $this->clientId = $clientId;
        $this->clientSecret = $clientSecret;
        $this->apiBase = $sandbox ? "https://api-m.sandbox.paypal.com" : "https://api-m.paypal.com";
    }

    public function amount(float $amount, string $currency = "EUR"): self
    {
        $this->amount = $amount;
        $this->currency = $currency;
        return $this;
    }

    public function order(string $orderId): self
    {
        $this->orderId = $orderId;
        return $this;
    }
	
	public function plan(string $planId): self
    {
        $this->planId = $planId;
        return $this;
    }
	
	public function product(string $productId): self
    {
        $this->productId = $productId;
        return $this;
    }

    private function authenticate(): void
    {
        if ($this->accessToken) return;

        $ch = curl_init("{$this->apiBase}/v1/oauth2/token");
        curl_setopt($ch, CURLOPT_HTTPHEADER, ["Accept: application/json", "Accept-Language: en_US"]);
        curl_setopt($ch, CURLOPT_USERPWD, "{$this->clientId}:{$this->clientSecret}");
        curl_setopt($ch, CURLOPT_POSTFIELDS, "grant_type=client_credentials");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        $response = json_decode(curl_exec($ch), true);
        curl_close($ch);

        if (!isset($response["access_token"])) {
            throw new Exception("Errore autenticazione PayPal: " . json_encode($response));
        }

        $this->accessToken = $response["access_token"];
    }

    public function createProduct(string $name, string $description = "", string $type = "SERVICE"): array
    {
        $this->authenticate();

        $data = [
            "name" => $name,
            "description" => $description,
            "type" => $type,
        ];

        return $this->request("POST", "/v1/catalogs/products", $data);
    }
	
	public function createPlan(string $description = ""): array
    {
        $this->authenticate();

        $data = [
		  "product_id" => $this->productId,
		  "name" => $description,
		  "description" => $description,
		  "status" => "ACTIVE",
		  "billing_cycles" => [
			[
			  "frequency" => [
				"interval_unit" => $this->intervalUnit,
				"interval_count" => 1
			  ],
			  "tenure_type" => "REGULAR",
			  "sequence" => 1,
			  "total_cycles" => 0, // 0 = rinnovi infiniti
			  "pricing_scheme" => [
				"fixed_price" => [
				  "value" => number_format($this->amount, 2, ".", ""),
				  "currency_code" => $this->currency
				]
			  ]
			]
		  ],
		  "payment_preferences" => [
			"auto_bill_outstanding" => true,
			"setup_fee_failure_action" => "CONTINUE",
			"payment_failure_threshold" => 3
		  ]
		];

		return $this->request("POST", "/v1/billing/plans", $data);
    }

    public function pay(): array
    {
        $this->authenticate();

        $data = [
            "intent" => "CAPTURE",
            "purchase_units" => [[
                "reference_id" => $this->orderId,
                "amount" => [
                    "currency_code" => $this->currency,
                    "value" => number_format($this->amount, 2, ".", ""),
                ]
            ]],
            "application_context" => [
				"return_url" => base_url() . "paypal/success",
				"cancel_url" => base_url() . "paypal/cancel",
				"brand_name" => getenv('app.brand_name'),
				"logo_image" => "https://tuo-sito.com/logo.png",
				"shipping_preference" => "NO_SHIPPING",
			]
        ];

        $response = $this->request("POST", "/v2/checkout/orders", $data);

        $approvalUrl = null;
        foreach ($response["links"] ?? [] as $link) {
            if ($link["rel"] === "approve") {
                $approvalUrl = $link["href"];
                break;
            }
        }

        return ["response" => $response, "approval_url" => $approvalUrl];
    }

    public function captureOrder(string $paypalOrderId): array
    {
        $this->authenticate();
        return $this->request("POST", "/v2/checkout/orders/{$paypalOrderId}/capture");
    }

    public function subscribe(): array
    {
        $this->authenticate();

        $data = [
            "plan_id" => $this->planId,
            "application_context" => [
                "return_url" => base_url() . "paypal/success",
				"cancel_url" => base_url() . "paypal/cancel",
				"brand_name" => getenv('app.brand_name'),
				"logo_image" => base_url() . "/logo.png",
				"shipping_preference" => "NO_SHIPPING",
            ]
        ];

        $response = $this->request("POST", "/v1/billing/subscriptions", $data);

        return [
            "response" => $response,
            "approval_url" => $response["links"][0]["href"] ?? null
        ];
    }

    public function getSubscription(string $subscriptionId): array
    {
        $this->authenticate();
        return $this->request("GET", "/v1/billing/subscriptions/{$subscriptionId}");
    }

    public function verifyWebhook(array $headers, string $body, string $webhookId): bool
    {
        $this->authenticate();

        $data = [
            "auth_algo" => $headers["paypal-auth-algo"] ?? "",
            "cert_url" => $headers["paypal-cert-url"] ?? "",
            "transmission_id" => $headers["paypal-transmission-id"] ?? "",
            "transmission_sig" => $headers["paypal-transmission-sig"] ?? "",
            "transmission_time" => $headers["paypal-transmission-time"] ?? "",
            "webhook_id" => $webhookId,
            "webhook_event" => json_decode($body, true),
        ];

        $response = $this->request("POST", "/v1/notifications/verify-webhook-signature", $data);

        return isset($response["verification_status"]) && $response["verification_status"] === "SUCCESS";
    }

    private function request(string $method, string $endpoint, array $data = []): array
    {
        $ch = curl_init("{$this->apiBase}{$endpoint}");
        $headers = [
            "Content-Type: application/json",
            "Authorization: Bearer {$this->accessToken}"
        ];

        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        if ($method === "POST") {
            curl_setopt($ch, CURLOPT_POST, true);
            if (!empty($data)) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
            }
        }

        $response = json_decode(curl_exec($ch), true);
        curl_close($ch);

        return $response ?: [];
    }
}
