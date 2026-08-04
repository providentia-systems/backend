# Billing provider operations

Billing and both checkout providers are disabled by default. Enabling a
provider does not enable billing; `BILLING_ENABLED=1` and at least one provider
flag are both required. Keep every credential in the deployment secret store,
never in a committed environment file.

## PayPal

Create one PayPal REST application per environment and configure:

- `PAYPAL_ENVIRONMENT=sandbox|live`
- `PAYPAL_CLIENT_ID` and `PAYPAL_CLIENT_SECRET`
- `PAYPAL_WEBHOOK_ID`, obtained when the HTTPS listener is registered

Subscribe only to events understood by the adapter:

- `BILLING.SUBSCRIPTION.ACTIVATED`
- `BILLING.SUBSCRIPTION.UPDATED`
- `BILLING.SUBSCRIPTION.CANCELLED`
- `BILLING.SUBSCRIPTION.EXPIRED`
- `BILLING.SUBSCRIPTION.SUSPENDED`
- `BILLING.SUBSCRIPTION.PAYMENT.FAILED`
- `CHECKOUT.ORDER.APPROVED`
- `CHECKOUT.ORDER.COMPLETED`
- `PAYMENT.CAPTURE.COMPLETED`

Create and activate a PayPal product and subscription plan for every recurring
price/currency combination, then record the PayPal plan ID through the billing
operator provider-price mapping. One-time prices use Orders v2 and do not need
a provider price reference. PayPal does not dynamically apply Providentia
promotion codes; a promotion needs its own PayPal plan mapping or the checkout
is rejected before the buyer is redirected.

Webhook deliveries are authenticated by posting the original event and PayPal
transmission headers to PayPal's verify-webhook-signature endpoint. Approved
Orders are captured server-to-server with the event ID as the idempotency key.

## Configurable hosted-card redirect provider

The provider must expose the configured checkout path and accept a JSON POST
authenticated with `Authorization: Bearer <HOSTED_CARD_API_KEY>` and an
`Idempotency-Key`. It must collect all cardholder data on its own HTTPS surface.
Providentia sends price, entitlement, promotion, and return/cancel metadata but
never PAN, CVC, expiry, track data, or household identity.

Configure:

- `HOSTED_CARD_API_BASE` and `HOSTED_CARD_CHECKOUT_PATH`
- `HOSTED_CARD_REDIRECT_HOSTS`, a comma-separated exact allowlist
- `HOSTED_CARD_API_KEY`
- `HOSTED_CARD_WEBHOOK_SECRET`, at least 32 random bytes
- optional webhook header names and a 30–900 second timestamp tolerance

The create response must contain `id`, an allowlisted HTTPS `redirect_url`, and
an ISO-8601 `expires_at`. Webhooks use the configured timestamp and signature
headers. The signature is lowercase hexadecimal
`HMAC-SHA256("<timestamp>.<raw-body>", webhook-secret)`, optionally prefixed
with `v1=`. Event fixtures in `tests/fixtures/billing/hosted-card` define the
neutral event envelope.

## Operator access

Plan management requires the existing `platform_administrator` role or a
`billing_operator` row in `user_platform_roles`. Home owners receive
`billing.read` and `billing.manage`; managers receive `billing.read`; both can
read billing by default, while only owners manage it by default. Non-owner
defaults can be changed through the normal versioned home permission policy;
an owner always retains both billing permissions. Run a sandbox checkout,
signed webhook replay, duplicate delivery, cancellation, and provider settlement
reconciliation rehearsal before enabling live traffic.
