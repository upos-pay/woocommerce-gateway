# UPOS API Reference

Reference documentation for UPOS Payment API integration with WooCommerce.

## Base URL

```text
Production: https://api.upos.fi
```

## Authentication

All authenticated API requests use Bearer token:

```http
Authorization: Bearer <secret_key>
```

Key formats:

- Public key: `pk_test_xxx` (test) / `pk_live_xxx` (production)
- Secret key: `sk_test_xxx` (test) / `sk_live_xxx` (production)

---

## Endpoints

### Get Supported Currencies

Returns the list of supported cryptocurrencies and their networks for payment.

```http
GET /v1/merchants/supported-currencies
```

**Authentication:** Required (Bearer token)

**Response:**

```json
{
  "currencies": [
    {
      "id": "usdt",
      "name": "USDT",
      "networks": [
        { "id": "tron", "name": "Tron" }
      ]
    }
  ]
}
```

| Field | Type | Description |
|-------|------|-------------|
| currencies | array | List of supported currencies |
| currencies[].id | string | Currency ID (e.g., `usdt`) |
| currencies[].name | string | Currency name (e.g., `USDT`) |
| currencies[].networks | array | Supported networks |
| currencies[].networks[].id | string | Network ID (e.g., `tron`) |
| currencies[].networks[].name | string | Network name (e.g., `Tron`) |

---

### Create Payment Intent

Creates a new payment intent with a unique wallet address for receiving payment.
If a payment intent with the same orderId already exists, returns the existing one with status 200.

```http
POST /v1/payment-intents
```

**Authentication:** Required (Bearer token)

**Request Body:**

```json
{
  "orderId": "order_123",
  "amount": "100.00",
  "paymentMethod": {
    "type": "crypto_tron",
    "currency": "USDT"
  },
  "returnUrl": "https://example.com/payment/complete",
  "webhookUrl": "https://example.com/wp-json/upos/v1/webhook"
}
```

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| orderId | string | Yes | Merchant's order ID |
| amount | string | Yes | Payment amount (e.g., `"100.00"`) |
| paymentMethod | object | Yes | Payment method details |
| paymentMethod.type | string | Yes | Payment method type (e.g., `crypto_tron`) |
| paymentMethod.currency | string | Yes | Currency code (e.g., `USDT`) |
| returnUrl | string | No | URL to redirect after payment |
| webhookUrl | string | No | Webhook URL for this order (overrides merchant default) |

**Response (201 Created / 200 Existing):**

```json
{
  "token": "pit_abc123xyz789...",
  "paymentUrl": "https://xxx...",
  "intent": {
    "id": "pi_abc123def456",
    "mode": "test",
    "orderId": "order_123",
    "orderAmount": "100.000000",
    "paymentAmount": "102.000000",
    "netAmount": "98.000000",
    "buyerFee": "2.000000",
    "sellerFee": "2.000000",
    "feeRules": {
      "buyer": {
        "min": "1.000000",
        "rate": "0.015000"
      },
      "seller": {
        "base": "1.000000",
        "rate": "0.010000",
        "threshold": "50.000000"
      }
    },
    "paymentMethod": {
      "type": "crypto_tron",
      "currency": "USDT",
      "network": "TRON",
      "address": "TXxx..."
    },
    "status": "created",
    "returnUrl": "https://example.com/payment/complete",
    "expiredAt": 1234567890,
    "createdAt": 1234567890
  }
}
```

| Field | Type | Description |
|-------|------|-------------|
| token | string | One-time token for payment page (`pit_xxx`) |
| data.id | string | Payment intent ID (`pi_xxx`) |
| data.mode | string | Environment mode (`live` or `test`) |
| data.orderId | string | Merchant's order ID |
| data.orderAmount | string | Original order amount |
| data.paymentAmount | string | Amount to be paid by buyer |
| data.netAmount | string | Net amount to be settled to merchant |
| data.buyerFee | string | Fee paid by buyer |
| data.sellerFee | string | Fee paid by merchant |
| data.feeRules | object | Fee calculation rules |
| data.paymentMethod | object | Payment method details with wallet address |
| data.paymentMethod.address | string | Wallet address for payment |
| data.status | string | Initial status (`created`) |
| data.returnUrl | string | Return URL |
| data.expiredAt | number | Expiration timestamp (epoch ms) |
| data.createdAt | number | Creation timestamp (epoch ms) |

---

### Get Payment Intent By ID (Authenticated)

Retrieves the full details of a payment intent using merchant authentication.
Only returns payment intents belonging to the authenticated merchant.

```http
GET /v1/payment-intents/:id/detail
```

**Authentication:** Required (Bearer token)

**Path Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| id | string | Payment intent ID (`pi_xxx`) |

**Response:**

```json
{
  "id": "pi_abc123def456",
  "mode": "test",
  "orderId": "order_123",
  "orderAmount": "100.000000",
  "paymentAmount": "102.000000",
  "netAmount": "98.000000",
  "buyerFee": "2.000000",
  "sellerFee": "2.000000",
  "feeRules": {
    "buyer": {
      "min": "1.000000",
      "rate": "0.015000"
    },
    "seller": {
      "base": "1.000000",
      "rate": "0.010000",
      "threshold": "50.000000"
    }
  },
  "paymentMethod": {
    "type": "crypto_tron",
    "currency": "USDT",
    "network": "TRON",
    "address": "TXxx..."
  },
  "receivedAmount": "100.000000",
  "status": "paid_confirmed",
  "returnUrl": "https://example.com/payment/complete",
  "events": [
    {
      "type": "crypto_tron",
      "amount": "100.000000",
      "status": "confirmed",
      "direction": "incoming",
      "timestamp": 1234567890,
      "externalId": "txhash...",
      "refId": null,
      "data": {
        "fromAddress": "TYxx...",
        "toAddress": "TXxx...",
        "currency": "usdt",
        "network": "TRON"
      },
      "createdAt": 1234567890
    }
  ],
  "statusHistory": [
    { "id": 1, "status": "created", "createdAt": 1234567800 },
    { "id": 2, "status": "awaiting_payment", "createdAt": 1234567810 },
    { "id": 3, "status": "paid_confirmed", "createdAt": 1234567890 }
  ],
  "disbursements": [
    {
      "amount": "99.00",
      "date": 1234567900,
      "success": true
    }
  ],
  "paidAt": 1234567890,
  "settledAt": 1234567900,
  "expiredAt": 1234567900,
  "createdAt": 1234567800,
  "updatedAt": 1234567890
}
```

| Field | Type | Description |
|-------|------|-------------|
| id | string | Payment intent ID |
| mode | string | Environment mode (`live` or `test`) |
| orderId | string | Merchant's order ID |
| orderAmount | string | Original order amount |
| paymentAmount | string | Amount to be paid by buyer |
| netAmount | string | Net amount to be settled to merchant |
| buyerFee | string | Fee paid by buyer |
| sellerFee | string | Fee paid by merchant |
| feeRules | object | Fee calculation rules |
| paymentMethod | object | Payment method with wallet address |
| receivedAmount | string | Total received amount |
| status | string | Current status (`created`, `awaiting_payment`, `paid_confirmed`, `settled`) |
| returnUrl | string \| null | Return URL |
| events | array | Payment events (transactions) |
| events[].type | string | Event type (e.g., `crypto_tron`) |
| events[].amount | string | Transaction amount |
| events[].status | string | Event status (`pending`, `confirmed`, `failed`) |
| events[].direction | string | Transaction direction (`incoming`, `outgoing`) |
| events[].timestamp | number | Transaction timestamp |
| events[].externalId | string \| null | External reference (e.g., txHash) |
| events[].data | object | Event-specific data |
| statusHistory | array | Status change history |
| disbursements | array | Disbursement records |
| disbursements[].amount | string | Disbursement amount |
| disbursements[].date | number | Disbursement date (epoch ms) |
| disbursements[].success | boolean | Whether disbursement was successful |
| paidAt | number \| null | Payment confirmation timestamp |
| settledAt | number \| null | Settlement timestamp |
| expiredAt | number \| null | Expire timestamp |
| createdAt | number | Creation timestamp |
| updatedAt | number | Last update timestamp |

---

### Get Payment Intent By Token (Public)

Retrieves the details of a payment intent using a token. No authentication required.
Returns simplified response without `events`, `statusHistory`, `disbursements`.

```http
GET /v1/payment-intents/detail?token=pit_xxx
```

**Authentication:** Not required

**Query Parameters:**

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| token | string | Yes | Token from payment intent creation (`pit_xxx`) |

**Response:**

```json
{
  "id": "pi_abc123def456",
  "mode": "test",
  "orderId": "order_123",
  "orderAmount": "100.000000",
  "paymentAmount": "102.000000",
  "buyerFee": "2.000000",
  "paymentMethod": {
    "type": "crypto_tron",
    "currency": "USDT",
    "network": "TRON",
    "address": "TXxx..."
  },
  "receivedAmount": "100.000000",
  "status": "paid_confirmed",
  "returnUrl": "https://example.com/payment/complete",
  "paidAt": 1234567890,
  "expiredAt": 1234567900,
  "createdAt": 1234567800,
  "updatedAt": 1234567890
}
```

| Field | Type | Description |
|-------|------|-------------|
| id | string | Payment intent ID (`pi_xxx`) |
| mode | string | Environment mode (`live` or `test`) |
| orderId | string | Merchant's order ID |
| orderAmount | string | Original order amount |
| paymentAmount | string | Amount to be paid by buyer |
| buyerFee | string | Fee paid by buyer |
| paymentMethod | object | Payment method details |
| receivedAmount | string | Total received amount |
| status | string | Current status |
| returnUrl | string | Return URL |
| paidAt | number | Payment confirmation timestamp (epoch ms) |
| expiredAt | number | Expiration timestamp (epoch ms) |
| createdAt | number | Creation timestamp (epoch ms) |
| updatedAt | number | Last update timestamp (epoch ms) |


---

### Get Disbursement Statistics

Returns disbursement statistics including pending disbursement and disbursed amounts.

```http
GET /v1/merchants/statistics/disbursement
```

**Authentication:** Required (Bearer token)

**Query Parameters:**

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| timezone | string | Yes | Timezone for date calculations (e.g., `Asia/Taipei`) |
| week-starts-on | number | No | First day of week (0=Sunday, 1=Monday). Default: 1 |

**Response:**

```json
{
  "pendingDisbursement": {
    "usdt": "1000.000000"
  },
  "disbursed": {
    "yesterday": { "usdt": "500.000000" },
    "thisWeek": { "usdt": "2000.000000" },
    "all": { "usdt": "10000.000000" }
  }
}
```

---

## Payment Page URL

Build payment page URL using the token:

```text
{BASE_URL}/pay?token={pit_xxx}
```

Example: `https://api.upos.example.com/pay?token=pit_abc123xyz789`

---

## Payment Intent Status

| Status | Description |
|--------|-------------|
| `created` | Payment intent created, waiting for payment method |
| `awaiting_payment` | Payment method set, waiting for payment |
| `paid_confirmed` | Payment confirmed (sufficient amount received) |
| `settled` | Payment disbursed to merchant |

---

## Error Responses

**HTTP Status Codes:**

| Status | Description |
|--------|-------------|
| 400 | Bad Request - Invalid parameters |
| 401 | Unauthorized - Missing or invalid API key |
| 403 | Forbidden - Access denied |
| 404 | Not Found - Resource not found |

**Error Response Format:**

```json
{
  "code": "0x7001",
  "message": "Payment intent already exists"
}
```

**Common Error Codes:**

| Code | Description |
|------|-------------|
| `0x6002` | Missing API key |
| `0x6003` | Invalid merchant key |
| `0x7000` | Payment intent not found |
| `0x7001` | Payment intent already exists |
| `0x7002` | Payment intent invalid status |
