# SproutVest API

A land investment platform backend built with Laravel 11, PostgreSQL (PostGIS), JWT authentication, and Redis. Users can register, deposit funds, purchase land units, track portfolio performance, and withdraw proceeds.

---

## Table of Contents

- [Requirements](#requirements)
- [Tech Stack](#tech-stack)
- [Getting Started](#getting-started)
  - [Local (Docker)](#local-docker)
  - [Environment Variables](#environment-variables)
- [Authentication](#authentication)
- [API Reference](#api-reference)
  - [Auth](#auth)
  - [Transaction PIN](#transaction-pin)
  - [Profile](#profile)
  - [Lands](#lands)
  - [Deposits](#deposits)
  - [Withdrawals](#withdrawals)
  - [Portfolio](#portfolio)
  - [Transactions](#transactions)
  - [KYC](#kyc)
  - [Referrals](#referrals)
  - [Notifications](#notifications)
  - [Support](#support)
  - [Admin](#admin)
- [Webhooks](#webhooks)
- [Queue & Scheduler](#queue--scheduler)
- [Database Schema Notes](#database-schema-notes)
- [Error Responses](#error-responses)

---

## Requirements

| Tool | Version |
|------|---------|
| PHP | ^8.2 |
| PostgreSQL | 16 + PostGIS 3.4 |
| Redis | 7 |
| Node / npm | For Vite (dev only) |
| Docker + Compose | Optional but recommended |

---

## Tech Stack

- **Framework** — Laravel 11
- **Auth** — `tymon/jwt-auth` v2.2 (JWT tokens, UUID subjects)
- **Database** — PostgreSQL 16 with PostGIS for geospatial land data
- **Cache / Queue** — Redis
- **Payments** — Paystack, Monnify, OPay
- **Email** — Laravel Mailables (queued)
- **Storage** — Local disk (KYC images), Public disk (land images)

---

## Getting Started


Services exposed:

| Service | URL |
|---------|-----|
| API (Nginx) | http://localhost:8000 |
| pgAdmin | http://localhost:8080 |
| Mailhog | http://localhost:8025 |
| PostgreSQL | localhost:5432 |

---

### Environment Variables

Key variables to configure in `.env`:

```env
APP_URL=http://localhost:8000
FRONTEND_URL=http://localhost:3000

DB_CONNECTION=pgsql
DB_HOST=db
DB_PORT=5432
DB_DATABASE=growthpoles
DB_USERNAME=laravel
DB_PASSWORD=laravel

REDIS_HOST=redis

JWT_SECRET=           # php artisan jwt:secret

MAIL_MAILER=smtp
MAIL_HOST=mailhog
MAIL_PORT=1025

# Paystack
PAYSTACK_PUBLIC_KEY=
PAYSTACK_SECRET_KEY=

# Monnify
MONNIFY_API_KEY=
MONNIFY_SECRET_KEY=
MONNIFY_CONTRACT_CODE=

# OPay
OPAY_MERCHANT_ID=
OPAY_PUBLIC_KEY=
OPAY_SECRET_KEY=
OPAY_BASE_URL=https://testapi.opaycheckout.com
```

---

## Authentication

All protected routes require a Bearer JWT token in the `Authorization` header:

```
Authorization: Bearer <token>
```

Tokens are issued on login and registration. Refresh via `POST /api/refresh`.

User identifiers in tokens use the user's `uid` (UUID format, e.g. `9fe31d79-0c47-4698-bfeb-c29c37c4548d`) — not the integer `id` — to prevent user enumeration.

---

## API Reference

All routes are prefixed with `/api`.

### Auth

| Method | Endpoint | Auth | Description |
|--------|----------|------|-------------|
| POST | `/register` | No | Register a new user |
| POST | `/login` | No | Login, returns JWT token |
| POST | `/logout` | Yes | Invalidate token |
| POST | `/refresh` | Yes | Refresh JWT token |
| POST | `/email/verify/code` | No | Verify email with 6-digit code |
| POST | `/email/resend-verification` | No | Resend email verification code |
| POST | `/password/reset/code` | No | Send password reset code |
| POST | `/password/reset/verify` | No | Verify password reset code |
| POST | `/password/reset` | No | Reset password |
| POST | `/user/change-password` | Yes | Change password (authenticated) |

**Register**
```json
POST /api/register
{
  "name": "John Doe",
  "email": "john@example.com",
  "password": "Secret@123",
  "password_confirmation": "Secret@123",
  "referral_code": "ABC12345"   // optional
}
```

**Login**
```json
POST /api/login
{
  "email": "john@example.com",
  "password": "Secret@123"
}
```
```json
// Response
{
  "message": "Login successful",
  "data": { "token": "eyJ0eXAiOiJKV1Q..." }
}
```

---

### Transaction PIN

All PIN routes require authentication + verified email.

| Method | Endpoint | Description |
|--------|----------|-------------|
| POST | `/pin/set` | Set PIN for the first time |
| POST | `/pin/update` | Change existing PIN |
| POST | `/pin/forgot` | Send 8-digit reset code to email |
| POST | `/pin/verify-code` | Verify code, receive `reset_token` |
| POST | `/pin/reset` | Set new PIN using `reset_token` |

**Reset Flow**

```
1. POST /pin/forgot          → code sent to email (expires 30 min)
2. POST /pin/verify-code     → { reset_token: "..." } (expires 15 min)
3. POST /pin/reset           → PIN updated
```

```json
POST /api/pin/verify-code
{ "code": "123456" }

// Response
{
  "success": true,
  "reset_token": "aBcDeFgH...",
  "message": "Code verified. Use the reset token to set a new PIN within 15 minutes."
}
```

```json
POST /api/pin/reset
{
  "reset_token": "aBcDeFgH...",
  "new_pin": "1234",
  "pin_confirmation": "1234"
}
```

> `/pin/forgot`, `/pin/verify-code`, and `/pin/reset` are rate-limited to **3 attempts per 15 minutes** per email + IP.

---

### Profile

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/me` | Get authenticated user profile |
| GET | `/user/account-status` | PIN set, KYC status, can_transact |
| GET | `/user/stats` | Balance, portfolio, withdrawal stats |
| GET | `/user/lands` | User's land holdings |
| PUT | `/user/bank-details` | Update bank account details |

---

### Lands

| Method | Endpoint | Auth | Description |
|--------|----------|------|-------------|
| GET | `/land` | No | Public land listing |
| GET | `/lands` | Yes | Authenticated land listing |
| GET | `/lands/map` | Yes | Map view (bounding box filter) |
| GET | `/lands/{land}` | Yes | Single land detail |
| GET | `/lands/{land}/units` | Yes | Unit availability |

**Map Query Parameters**
```
GET /api/lands/map?min_lng=3.0&min_lat=6.0&max_lng=4.0&max_lat=7.0
```

---

### Deposits

| Method | Endpoint | Description |
|--------|----------|-------------|
| POST | `/deposit` | Initiate deposit (returns payment URL) |
| GET | `/deposit/verify/{reference}` | Check deposit status |
| GET | `/paystack/banks` | List supported banks |
| POST | `/paystack/resolve-account` | Resolve account name |

**Initiate Deposit**
```json
POST /api/deposit
{
  "amount": 10000000,   // in kobo (₦100,000)
  "gateway": "paystack" // paystack | monnify | opay
}
```
```json
// Response
{
  "payment_url": "https://checkout.paystack.com/...",
  "reference": "DEP-...",
  "gateway": "paystack",
  "transaction_fee": 150,
  "total_amount": 100150
}
```

---

### Withdrawals

Requires KYC approval, bank details set, and transaction PIN.

| Method | Endpoint | Description |
|--------|----------|-------------|
| POST | `/withdraw` | Request withdrawal |
| GET | `/withdrawals/{reference}` | Get withdrawal status |

```json
POST /api/withdraw
{
  "amount": 5000000,         // in kobo (₦50,000 minimum)
  "transaction_pin": "1234"
}
```

> Daily withdrawal limit: ₦500,000 (configurable via `services.withdrawals.daily_limit_kobo`)

---

### Portfolio

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/portfolio/summary` | Overall portfolio summary |
| GET | `/portfolio/chart?days=30` | Historical value chart (7/14/30/90/180/365 days) |
| GET | `/portfolio/performance` | ROI and annualized return |
| GET | `/portfolio/allocation` | Per-land allocation breakdown |
| GET | `/portfolio/asset/{land}` | Single land asset detail |

---

### Transactions

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/transactions/user` | Paginated transaction history |
| POST | `/lands/{land}/purchase` | Purchase land units |
| POST | `/lands/{land}/sell` | Sell land units |

**Purchase**
```json
POST /api/lands/1/purchase
{
  "units": 10,
  "use_rewards": true,      // optional, default true
  "transaction_pin": "1234"
}
```

---

### KYC

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/kyc/status` | Get KYC status |
| POST | `/kyc/submit` | Submit KYC documents (multipart) |
| GET | `/kyc/{id}/image/{type}` | Stream KYC image (id_front/id_back/selfie) |

**Submit KYC** — `multipart/form-data`

| Field | Type | Required |
|-------|------|----------|
| full_name | string | Yes |
| date_of_birth | date | Yes |
| phone_number | string | Yes |
| address | string | Yes |
| city | string | Yes |
| state | string | Yes |
| id_type | enum | Yes (nin/drivers_license/voters_card/passport/bvn) |
| id_number | string | Yes |
| id_front | image | Yes (max 5MB) |
| id_back | image | No |
| selfie | image | Yes (max 5MB) |

---

### Referrals

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/referrals/dashboard` | Referral stats and history |
| GET | `/referrals/rewards` | Unclaimed rewards |
| POST | `/referrals/rewards/{id}/claim` | Claim a reward |
| POST | `/referrals/validate` | Validate a referral code (public) |

**Reward Types**

| Type | Recipient | Value |
|------|-----------|-------|
| `cashback` | Referrer | ₦50 credits to rewards wallet |
| `discount` | Referred user | 10% off first purchase |

---

### Notifications

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/notifications` | Paginated notifications |
| GET | `/notifications/unread` | Unread notifications |
| POST | `/notifications/read` | Mark all as read |
| POST | `/notifications/{id}/read` | Mark one as read |

---

### Support

| Method | Endpoint | Auth | Description |
|--------|----------|------|-------------|
| GET | `/support/faqs` | No | FAQ list grouped by category |
| POST | `/support/tickets/guest` | No | Submit guest ticket |
| POST | `/support/chat` | Yes | AI chat (Claude Haiku) |
| GET | `/support/tickets` | Yes | List user's tickets |
| POST | `/support/tickets` | Yes | Create ticket |
| GET | `/support/tickets/{ticket}` | Yes | View ticket + messages |
| POST | `/support/tickets/{ticket}/reply` | Yes | Reply to ticket |

**Categories:** `account` `payment` `kyc` `investment` `withdrawal` `other`

> AI chat is rate-limited to **20 messages per 10 minutes** per user.

---

### Admin

All admin routes require `is_admin = true`. Prefix: `/admin`.

**Users**

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/admin/users` | List all users (filterable) |
| GET | `/admin/users/{user}` | User detail |
| PATCH | `/admin/users/{user}/suspend` | Suspend user |
| PATCH | `/admin/users/{user}/unsuspend` | Unsuspend user |
| PATCH | `/admin/users/{user}/make-admin` | Grant admin |
| PATCH | `/admin/users/{user}/remove-admin` | Revoke admin |
| DELETE | `/admin/users/{user}` | Delete user |
| POST | `/admin/users/{user}/gift-units` | Gift land units to a user from platform inventory (requires `units.gift`, super_admin only) |
| GET | `/admin/users/{user}/gifts` | List a user's gifts (newest first, max 50) with a `reversed` flag (requires `units.gift`) |
| POST | `/admin/users/{user}/gifts/{reference}/reverse` | Reverse a gift: return its units to platform inventory (requires `units.gift`) |

**Gift units** — `POST /admin/users/{user}/gift-units`

```json
{ "land_id": 12, "units": 5, "reason": "Welcome gift" }
```

Units are taken from the land's unsold inventory (`available_units`) and credited to the user. No wallet
or rewards balance moves and no ledger entry is posted. The gift is recorded as a `gift` transaction
(amount 0), added to the user's holding with a cost basis of 0, logged in `admin_action_logs`
(`units.gift`), and the user's certificate is issued/refreshed. Rejected with 422 if the land lacks
enough available units, or the recipient is suspended, admin, or not screening-clear. Supports the
`Idempotency-Key` header.

**Reverse a gift** — `POST /admin/users/{user}/gifts/{reference}/reverse` with `{ "reason": "Issued in error" }`

Takes the gifted units back out of the user's holding and returns them to the land's `available_units`.
All-or-nothing: refused with 422 if the user no longer holds the full quantity (sold or moved some),
if the units are listed on the marketplace, or if the gift was already reversed. Recorded as a
`gift_reversal` transaction with reference `REV-<gift reference>` (unique, so a gift can be reversed at
most once), plus an `admin_action_logs` entry (`units.gift_reverse`). The certificate is refreshed (or
revoked if the holding reaches zero) and the user is notified. Portfolio snapshots are not adjusted,
consistent with how marketplace sales behave today.

**Lands**

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/admin/lands` | All lands paginated |
| POST | `/admin/lands` | Create land (multipart) |
| POST | `/admin/lands/{land}` | Update land |
| PATCH | `/admin/lands/{land}/price` | Update price |
| PATCH | `/admin/lands/{land}/availability` | Toggle availability |

**KYC**

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/admin/kyc` | List KYC submissions |
| GET | `/admin/kyc/{id}` | KYC detail with image URLs |
| POST | `/admin/kyc/{id}/approve` | Approve KYC |
| POST | `/admin/kyc/{id}/reject` | Reject with reason |
| POST | `/admin/kyc/{id}/resubmit` | Request resubmission |

**Support Tickets**

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/admin/support/tickets` | All tickets (filterable) |
| GET | `/admin/support/tickets/{ticket}` | Ticket detail |
| POST | `/admin/support/tickets/{ticket}/reply` | Reply |
| PATCH | `/admin/support/tickets/{ticket}/status` | Update status/priority |
| DELETE | `/admin/support/tickets/{ticket}` | Delete closed ticket |

**Withdrawals**

| Method | Endpoint | Description |
|--------|----------|-------------|
| POST | `/admin/withdrawals/retry` | Retry all pending withdrawals |

---

## Webhooks

Webhook endpoints are public (no JWT). Signatures are verified server-side.

| Gateway | Endpoint | Signature Header |
|---------|----------|-----------------|
| Paystack | `POST /api/paystack/webhook` | `x-paystack-signature` (HMAC-SHA512) |
| Monnify | `POST /api/monnify/webhook` | `monnify-signature` (HMAC-SHA512) |
| OPay | `POST /api/opay/webhook` | `Signature` (HMAC-SHA512) |

All webhook handlers are idempotent — duplicate events are safely ignored via `processed_at` guards.

---

## Queue & Scheduler

**Queue worker** (runs in the `queue` Docker service):
```bash
php artisan queue:work --sleep=3 --tries=3 --timeout=90
```

Queued jobs include: emails (verification, PIN reset, password reset), purchase/sale/withdrawal notifications.

**Scheduler** (runs in the `scheduler` Docker service):
```bash
php artisan schedule:work
```

| Job | Schedule | Description |
|-----|----------|-------------|
| `GenerateDailyPortfolioSnapshot` | Daily at 23:55 | Snapshots all user portfolio values |

To backfill a specific date:
```bash
php artisan tinker
>>> dispatch(new App\Jobs\GenerateDailyPortfolioSnapshot('2025-01-15'));
```

---

## Database Schema Notes

Key tables:

| Table | Purpose |
|-------|---------|
| `users` | Accounts, wallets, PIN, KYC status |
| `lands` | Land listings with PostGIS geometry |
| `land_price_history` | Time-series price per land |
| `purchases` | User land holdings (upserted on buy/sell) |
| `user_land` | Denormalised unit counts for fast reads |
| `deposits` / `withdrawals` | Payment records |
| `ledger_entries` | Immutable double-entry audit trail |
| `portfolio_daily_snapshots` | Daily portfolio value per user |
| `portfolio_asset_snapshots` | Daily value per user per land |
| `kyc_verifications` | KYC documents and status |
| `referrals` / `referral_rewards` | Referral tracking |
| `support_tickets` / `support_messages` | Support system |
| `notifications` | Laravel database notifications |

---

## Error Responses

All errors follow a consistent shape:

```json
{
  "success": false,
  "message": "Human-readable error",
  "errors": {
    "field": ["Validation message"]
  }
}
```

| Code | Meaning |
|------|---------|
| 400 | Bad request / business rule violation |
| 401 | Unauthenticated (missing/expired token) |
| 403 | Forbidden (suspended account, wrong role) |
| 404 | Resource not found |
| 409 | Email not verified |
| 422 | Validation error |
| 429 | Rate limit exceeded |
| 500 | Server error |
| 502 | Payment gateway error |