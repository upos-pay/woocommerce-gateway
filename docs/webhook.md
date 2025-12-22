# UPOS Webhook

Documentation for UPOS webhook notifications.

## Endpoint

```
POST /wp-json/upos/v1/webhook
```

## Configuration

Two ways to receive webhook notifications:

1. **Global webhook**: Configure webhook URL in UPOS merchant settings
2. **Per-order webhook**: Pass `webhookUrl` when creating payment intent

Per-order webhook takes precedence over global webhook.

## Event Types

| Event | Description |
|-------|-------------|
| `payment_intent.paid` | Payment confirmed (sufficient payment received) |
| `payment_intent.expired` | Payment expired |
| `payment_intent.settled` | Payment disbursed to merchant |
| `payment_event.received` | New payment events received (partial payment, etc.) |

```typescript
export const WebhookEventType = {
  PaymentIntentPaid: 'payment_intent.paid',
  PaymentIntentSettled: 'payment_intent.settled',
  PaymentEventReceived: 'payment_event.received'
} as const
```

## Payload Format

```json
{
  "event": "payment_intent.paid",
  "timestamp": 1234567890,
  "data": {
    "id": "pi_xxx",
    "orderId": "ORD-12345",
    "orderAmount": "100.000000",
    "paymentAmount": "102.000000",
    "receivedAmount": "102.000000",
    "status": "paid_confirmed",
    "createdAt": 1234567890
  }
}
```

| Field | Type | Description |
|-------|------|-------------|
| event | string | Event type |
| timestamp | number | Event timestamp (epoch ms) |
| data.id | string | Payment intent ID (`pi_xxx`) |
| data.orderId | string | Merchant's order ID |
| data.orderAmount | string | Original order amount |
| data.paymentAmount | string | Amount to be paid by buyer |
| data.receivedAmount | string | Amount received so far |
| data.status | string | Current payment intent status |
| data.createdAt | number | Payment intent creation timestamp (epoch ms) |

## Signature Verification

Webhook requests include signature header for verification:

```
X-UPOS-Signature: <hmac_sha256_signature>
```

Signature is calculated as:

```
HMAC-SHA256(request_body, secret_key)
```

Where `secret_key` is the merchant's secret key matching the payment intent's mode (test/live).

### PHP Verification Example

```php
$body = $request->get_body();
$signature = $request->get_header('X-UPOS-Signature');
$expected = hash_hmac('sha256', $body, $secret_key);

if (hash_equals($expected, $signature)) {
    // Valid signature
}
```

## Response

### Success

```json
{
  "success": true
}
```

HTTP Status: `200 OK`

### Error

```json
{
  "success": false,
  "message": "Error description"
}
```

HTTP Status: `400`, `401`, or `500`

## Retry Policy

UPOS will retry failed webhook deliveries with exponential backoff.

## Security Notes

1. Always verify the signature before processing
2. Only process orders with `payment_method = 'upos'`
3. Use HTTPS for webhook endpoint
4. Respond quickly (< 30 seconds) to avoid timeout
