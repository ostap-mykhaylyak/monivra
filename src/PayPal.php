<?php
namespace Ostap\PayPal;

use Exception;

class PayPal
{
    private string $clientId;
    private string $clientSecret;
    private string $apiBase;
    private float $amount;
    private string $currency = "EUR";
    private string $orderId;
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

    /** Creazione prodotto (necessario per abbonamenti) */
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

    /** Pagamento singolo */
    public function pay(): array
    {
        $this->authenticate();

        $data = [
            "intent" => "CAPTURE",
            "purchase_units" => [[
                "reference_id" => $this->orderId,
                "amount" => [
                    "currency_code" => $this->currency,
                    "value" => number_format($this->amount, 2, ".", "")
                ]
            ]],
            "application_context" => [
                "return_url" => "https://tuo-sito.com/paypal/success",
                "cancel_url" => "https://tuo-sito.com/paypal/cancel"
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

    /** Conferma pagamento */
    public function captureOrder(string $paypalOrderId): array
    {
        $this->authenticate();
        return $this->request("POST", "/v2/checkout/orders/{$paypalOrderId}/capture");
    }

    /** Creazione abbonamento */
    public function subscribe(string $interval = "M", string $productId = "PRODOTTO123"): array
    {
        $this->authenticate();

        $intervalUnit = $interval === "Y" ? "YEAR" : "MONTH";

        $data = [
            "plan" => [
                "product_id" => $productId,
                "name" => "Abbonamento {$intervalUnit}",
                "billing_cycles" => [[
                    "frequency" => [
                        "interval_unit" => $intervalUnit,
                        "interval_count" => 1
                    ],
                    "tenure_type" => "REGULAR",
                    "sequence" => 1,
                    "total_cycles" => 0,
                    "pricing_scheme" => [
                        "fixed_price" => [
                            "value" => number_format($this->amount, 2, ".", ""),
                            "currency_code" => $this->currency
                        ]
                    ]
                ]],
                "payment_preferences" => [
                    "auto_bill_outstanding" => true,
                    "setup_fee_failure_action" => "CONTINUE",
                    "payment_failure_threshold" => 1
                ]
            ],
            "application_context" => [
                "return_url" => "https://tuo-sito.com/paypal/success",
                "cancel_url" => "https://tuo-sito.com/paypal/cancel"
            ]
        ];

        $response = $this->request("POST", "/v1/billing/subscriptions", $data);

        return [
            "response" => $response,
            "approval_url" => $response["links"][0]["href"] ?? null
        ];
    }

    /** Stato abbonamento */
    public function getSubscription(string $subscriptionId): array
    {
        $this->authenticate();
        return $this->request("GET", "/v1/billing/subscriptions/{$subscriptionId}");
    }

    /** Verifica firma webhook */
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

    /** Metodo generico richieste */
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
