# API Documentation — Subscription Backend

**Base URL:** `{APP_URL}/api/v1`  
**Content-Type:** `application/json`  
**Authentication:** Bearer Token (Laravel Sanctum)

---

## Table of Contents

- [Authentication](#authentication)
  - [Register](#post-authregister)
  - [Login](#post-authlogin)
  - [Logout](#post-authlogout)
  - [Me](#get-authme)
- [Subscription](#subscription)
  - [My Status](#get-subscriptionme)
  - [Status by User](#get-subscriptionstatususer)
  - [Validity Check](#get-subscriptioncheckuser)
  - [List Plans](#get-subscriptionplans)
  - [Plan Detail](#get-subscriptionplansplan)
  - [Subscription History](#get-subscriptionhistoryuser)
  - [Activate Trial](#post-subscriptiontrialactivate)
  - [Trial Status](#get-subscriptiontrialuser)
- [Payment](#payment)
  - [Create Order](#post-subscriptionorder)
  - [Submit Proof](#post-subscriptionpaymentproof)
  - [Order Detail](#get-subscriptionorderorder)
  - [Payment History](#get-subscriptionpaymentsuser)
- [Admin](#admin)
  - [Admin Activate Subscription](#post-subscriptionadminactivate)
  - [Admin Verify Payment](#post-subscriptionadminverifyorder)
- [Error Responses](#error-responses)
- [Status Enums](#status-enums)

---

## Standard Response Envelope

All responses follow this structure:

```json
{
  "success": true,
  "message": "Human-readable message",
  "data": { ... }
}
```

Errors:
```json
{
  "success": false,
  "message": "Error description",
  "errors": { "field": ["Validation message"] }
}
```

---

## Authentication

### POST /auth/register

Registers a new user, auto-grants a 7-day free trial, and returns a Bearer token.

**Auth required:** No

**Request body:**
```json
{
  "external_user_id": "USR-001",
  "name": "Budi Santoso",
  "email": "budi@example.com",
  "device_name": "Budi iPhone 15"
}
```

| Field | Type | Rules |
|-------|------|-------|
| `external_user_id` | string | required, unique, max 255 |
| `name` | string | required, max 255 |
| `email` | string | required, valid email, unique |
| `device_name` | string | required — used to label the Sanctum token |

**Response 201:**
```json
{
  "success": true,
  "message": "Registration successful.",
  "data": {
    "user": {
      "id": 1,
      "name": "Budi Santoso",
      "email": "budi@example.com",
      "external_user_id": "USR-001",
      "created_at": "2026-05-07T16:00:00+00:00"
    },
    "token": "1|abc123tokenhere",
    "token_type": "Bearer",
    "subscription": {
      "status": "trial",
      "statusLabel": "Trial",
      "isActive": true,
      "remainingDays": 7
    }
  }
}
```

**Error 422 — Validation failed:**
```json
{
  "success": false,
  "message": "The external user ID is already registered.",
  "errors": {
    "external_user_id": ["This external user ID is already registered."]
  }
}
```

---

### POST /auth/login

Authenticates by `external_user_id` (preferred) or `email`. Returns a new token.

**Auth required:** No

**Request body (by external_user_id):**
```json
{
  "external_user_id": "USR-001",
  "device_name": "Budi iPhone 15"
}
```

**Request body (by email):**
```json
{
  "email": "budi@example.com",
  "device_name": "Budi iPhone 15"
}
```

| Field | Type | Rules |
|-------|------|-------|
| `external_user_id` | string | nullable — at least one identifier required |
| `email` | string | nullable — at least one identifier required |
| `device_name` | string | required |

**Response 200:**
```json
{
  "success": true,
  "message": "Login successful.",
  "data": {
    "user": {
      "id": 1,
      "name": "Budi Santoso",
      "email": "budi@example.com",
      "external_user_id": "USR-001",
      "created_at": "2026-05-07T16:00:00+00:00"
    },
    "token": "2|xyz456tokenhere",
    "token_type": "Bearer",
    "subscription": {
      "status": "trial",
      "statusLabel": "Trial",
      "isActive": true,
      "remainingDays": 6
    }
  }
}
```

**Error 401 — Invalid credentials:**
```json
{
  "success": false,
  "message": "Invalid credentials."
}
```

---

### POST /auth/logout

Revokes the current request's Bearer token. Other device tokens remain valid.

**Auth required:** Yes

**Request:** No body needed

**Response 200:**
```json
{
  "success": true,
  "message": "Logged out successfully.",
  "data": null
}
```

---

### GET /auth/me

Returns the authenticated user's profile and current subscription state.

**Auth required:** Yes

**Response 200:**
```json
{
  "success": true,
  "message": "Success",
  "data": {
    "user": {
      "id": 1,
      "name": "Budi Santoso",
      "email": "budi@example.com",
      "external_user_id": "USR-001",
      "created_at": "2026-05-07T16:00:00+00:00"
    },
    "subscription": {
      "status": "active",
      "statusLabel": "Active",
      "isActive": true,
      "plan": {
        "id": 1,
        "name": "Monthly",
        "slug": "monthly",
        "price": 50000,
        "currency": "IDR",
        "duration_days": 30
      },
      "startDate": "2026-05-07",
      "endDate": "2026-06-06",
      "remainingDays": 30
    }
  }
}
```

---

## Subscription

### GET /subscription/me

Full subscription status plus the last 10 subscription records. One call for the subscription management screen.

**Auth required:** Yes

**Response 200:**
```json
{
  "success": true,
  "message": "Success",
  "data": {
    "current": {
      "status": "trial",
      "statusLabel": "Trial",
      "isActive": true,
      "remainingDays": 5,
      "trialCountdown": "5 days remaining"
    },
    "history": [
      {
        "id": 1,
        "status": "trial",
        "statusLabel": "Trial",
        "isActive": true,
        "plan": null,
        "trialStartDate": "2026-05-07",
        "trialEndDate": "2026-05-14",
        "startDate": "2026-05-07",
        "endDate": "2026-05-14",
        "remainingDays": 5,
        "autoRenew": false,
        "createdAt": "2026-05-07T16:00:00+00:00"
      }
    ]
  }
}
```

---

### GET /subscription/status/{user}

Full subscription status for a specific user. Users can only access their own data.

**Auth required:** Yes  
**URL param:** `{user}` — the user's integer ID

**Response 200:**
```json
{
  "success": true,
  "message": "Success",
  "data": {
    "status": "trial",
    "statusLabel": "Trial",
    "isActive": true,
    "remainingDays": 5,
    "trialCountdown": "5 days remaining"
  }
}
```

**Error 403 — Accessing another user's data:**
```json
{
  "success": false,
  "message": "You are not authorised to access this user's subscription data."
}
```

---

### GET /subscription/check/{user}

Lightweight yes/no validity check. Designed for server-to-server calls.

**Auth required:** Yes  
**URL param:** `{user}` — user's integer ID

**Response 200:**
```json
{
  "success": true,
  "message": "Success",
  "data": {
    "isValid": true,
    "reason": "active"
  }
}
```

---

### GET /subscription/plans

Lists all active subscription plans ordered by price ascending.

**Auth required:** Yes

**Response 200:**
```json
{
  "success": true,
  "message": "Success",
  "data": [
    {
      "id": 1,
      "name": "Monthly",
      "slug": "monthly",
      "price": 50000,
      "currency": "IDR",
      "duration_days": 30,
      "features": {
        "unlimited_access": true,
        "priority_support": false,
        "export_data": true,
        "max_devices": 2
      },
      "is_active": true
    },
    {
      "id": 2,
      "name": "Yearly",
      "slug": "yearly",
      "price": 500000,
      "currency": "IDR",
      "duration_days": 365,
      "features": {
        "unlimited_access": true,
        "priority_support": true,
        "export_data": true,
        "max_devices": 5
      },
      "is_active": true
    }
  ]
}
```

---

### GET /subscription/plans/{plan}

Details for a single plan. Returns 404 for inactive plans.

**Auth required:** Yes  
**URL param:** `{plan}` — plan's integer ID

**Response 200:** Same shape as a single item from the plans list above.

**Error 404:**
```json
{
  "success": false,
  "message": "The requested plan is no longer available."
}
```

---

### GET /subscription/history/{user}

Paginated subscription history. Users can only see their own history.

**Auth required:** Yes  
**URL params:** `{user}` — user's integer ID  
**Query params:** `?page=1&per_page=15` (per_page max 50)

**Response 200:**
```json
{
  "success": true,
  "message": "Success",
  "data": {
    "data": [ { ... subscription objects ... } ],
    "meta": {
      "current_page": 1,
      "per_page": 15,
      "total": 3,
      "last_page": 1,
      "has_more": false
    }
  }
}
```

---

### POST /subscription/trial/activate

Activates the 7-day free trial. `user_id` must match the authenticated user.

**Auth required:** Yes

**Request body:**
```json
{
  "user_id": 1
}
```

**Response 201:**
```json
{
  "success": true,
  "message": "Free trial activated successfully.",
  "data": {
    "subscription": {
      "id": 1,
      "status": "trial",
      "statusLabel": "Trial",
      "isActive": true,
      "plan": null,
      "trialStartDate": "2026-05-07",
      "trialEndDate": "2026-05-14",
      "startDate": "2026-05-07",
      "endDate": "2026-05-14",
      "remainingDays": 7,
      "autoRenew": false,
      "createdAt": "2026-05-07T16:00:00+00:00"
    },
    "trialCountdown": "7 days remaining"
  }
}
```

**Error 422 — Trial already used or user_id mismatch:**
```json
{
  "success": false,
  "message": "The user_id must match your authenticated account.",
  "errors": {
    "user_id": ["The user_id must match your authenticated account."]
  }
}
```

---

### GET /subscription/trial/{user}

Trial-specific status with countdown. Shows whether the user has an active trial, expired trial, or has never started one.

**Auth required:** Yes  
**URL param:** `{user}` — user's integer ID

**Response 200 — Never started:**
```json
{
  "success": true,
  "message": "Success",
  "data": {
    "hasTrialAvailable": true,
    "isOnTrial": false,
    "hasUsedTrial": false,
    "trialStartDate": null,
    "trialEndDate": null,
    "remainingDays": 0,
    "trialCountdown": null,
    "status": "never_started"
  }
}
```

**Response 200 — Active trial:**
```json
{
  "success": true,
  "message": "Success",
  "data": {
    "hasTrialAvailable": false,
    "isOnTrial": true,
    "hasUsedTrial": true,
    "trialStartDate": "2026-05-07",
    "trialEndDate": "2026-05-14",
    "remainingDays": 5,
    "trialCountdown": "5 days remaining",
    "status": "active"
  }
}
```

**Response 200 — Expired trial:**
```json
{
  "success": true,
  "message": "Success",
  "data": {
    "hasTrialAvailable": false,
    "isOnTrial": false,
    "hasUsedTrial": true,
    "trialStartDate": "2026-04-01",
    "trialEndDate": "2026-04-08",
    "remainingDays": 0,
    "trialCountdown": null,
    "status": "expired"
  }
}
```

---

## Payment

### POST /subscription/order

Creates a pending payment order and returns PayPal payment instructions.

**Auth required:** Yes  
**Note:** `user_id` must match the authenticated user.

**Request body:**
```json
{
  "user_id": 1,
  "plan_id": 1
}
```

**Response 201:**
```json
{
  "success": true,
  "message": "Payment order created. Please follow the instructions to complete payment.",
  "data": {
    "order": {
      "id": 10,
      "status": "pending",
      "statusLabel": "Pending",
      "isFinal": false,
      "amount": 50000,
      "currency": "IDR",
      "paymentMethod": "paypal",
      "paypalEmail": null,
      "transactionId": null,
      "proofScreenshotUrl": null,
      "notes": null,
      "verifiedAt": null,
      "createdAt": "2026-05-07T16:00:00+00:00",
      "plan": {
        "id": 1,
        "name": "Monthly",
        "slug": "monthly",
        "price": 50000,
        "currency": "IDR",
        "duration_days": 30
      },
      "subscription": null,
      "verifier": null
    },
    "instructions": {
      "paypal_receiver_email": "payments@example.com",
      "paypal_receiver_name": "Subscription Payments",
      "amount": 50000,
      "currency": "IDR",
      "order_id": 10,
      "steps": [
        { "step": 1, "instruction": "Open PayPal and send IDR 50,000 to: payments@example.com" },
        { "step": 2, "instruction": "Use \"Friends & Family\" to avoid extra transaction fees." },
        { "step": 3, "instruction": "Include your Order ID #10 in the PayPal payment note." },
        { "step": 4, "instruction": "Copy the Transaction ID from your PayPal receipt." },
        { "step": 5, "instruction": "Submit proof via POST /api/v1/subscription/payment/proof with your Transaction ID (and optionally a screenshot)." }
      ]
    }
  }
}
```

---

### POST /subscription/payment/proof

Submits payment proof for a pending order. Accepts a file upload OR an external URL.

**Auth required:** Yes  
**Content-Type:** `multipart/form-data` (when uploading a file) or `application/json`

**Request fields:**

| Field | Type | Rules |
|-------|------|-------|
| `order_id` | integer | required, must belong to authenticated user, order must be pending |
| `transaction_id` | string | required, max 255 |
| `paypal_email` | string | required, valid email |
| `screenshot` | file | nullable — JPG/PNG/WebP, max 5 MB |
| `screenshot_url` | string | nullable — external URL (used if no file uploaded) |

**curl example (with file upload):**
```bash
curl -s -X POST http://localhost:8000/api/v1/subscription/payment/proof \
  -H "Authorization: Bearer $TOKEN" \
  -F "order_id=10" \
  -F "transaction_id=PAYPAL-TXN-ABC123" \
  -F "paypal_email=buyer@example.com" \
  -F "screenshot=@/path/to/screenshot.jpg"
```

**curl example (with URL):**
```bash
curl -s -X POST http://localhost:8000/api/v1/subscription/payment/proof \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "order_id": 10,
    "transaction_id": "PAYPAL-TXN-ABC123",
    "paypal_email": "buyer@example.com",
    "screenshot_url": "https://i.imgur.com/proof.jpg"
  }'
```

**Response 200:**
```json
{
  "success": true,
  "message": "Payment proof submitted. Our team will verify your payment shortly.",
  "data": {
    "order": {
      "id": 10,
      "status": "pending",
      "transactionId": "PAYPAL-TXN-ABC123",
      "paypalEmail": "buyer@example.com",
      "proofScreenshotUrl": "http://localhost:8000/storage/payment-proofs/1/uuid.jpg"
    }
  }
}
```

**Error 422 — Order already finalized:**
```json
{
  "success": false,
  "message": "This order can no longer be modified — current status: Verified.",
  "errors": {
    "order_id": ["This order can no longer be modified — current status: Verified."]
  }
}
```

---

### GET /subscription/order/{order}

Returns full details of a single payment order. Users can only view their own orders (admins see all).

**Auth required:** Yes  
**URL param:** `{order}` — order's integer ID

**Response 200:**
```json
{
  "success": true,
  "message": "Success",
  "data": {
    "order": {
      "id": 10,
      "status": "verified",
      "statusLabel": "Verified",
      "isFinal": true,
      "amount": 50000,
      "currency": "IDR",
      "paymentMethod": "paypal",
      "paypalEmail": "buyer@example.com",
      "transactionId": "PAYPAL-TXN-ABC123",
      "proofScreenshotUrl": "http://localhost:8000/storage/payment-proofs/1/uuid.jpg",
      "notes": null,
      "verifiedAt": "2026-05-08T09:00:00+00:00",
      "createdAt": "2026-05-07T16:00:00+00:00",
      "plan": { "id": 1, "name": "Monthly", "slug": "monthly", "price": 50000, "currency": "IDR", "duration_days": 30 },
      "subscription": { "id": 2, "status": "active", "startDate": "2026-05-08", "endDate": "2026-06-07", "remainingDays": 30 },
      "verifier": { "id": 99, "name": "System Administrator" }
    }
  }
}
```

---

### GET /subscription/payments/{user}

Paginated payment history for a user. Users can only see their own history.

**Auth required:** Yes  
**URL params:** `{user}` — user's integer ID  
**Query params:** `?page=1&per_page=15`

**Response 200:**
```json
{
  "success": true,
  "message": "Success",
  "data": {
    "data": [ { ... order objects ... } ],
    "meta": {
      "current_page": 1,
      "per_page": 15,
      "total": 2,
      "last_page": 1,
      "has_more": false
    }
  }
}
```

---

## Admin

> All admin routes require `Authorization: Bearer {admin_token}` where the token belongs to a user with `is_admin = true`.

### POST /subscription/admin/activate

Manually activates a subscription for any user, bypassing the payment flow.

**Auth required:** Yes + Admin

**Request body:**
```json
{
  "user_id": 5,
  "plan_id": 2
}
```

**Response 200:**
```json
{
  "success": true,
  "message": "Subscription manually activated for user #5.",
  "data": {
    "subscription": {
      "id": 3,
      "status": "active",
      "statusLabel": "Active",
      "isActive": true,
      "plan": {
        "id": 2,
        "name": "Yearly",
        "slug": "yearly",
        "price": 500000,
        "currency": "IDR",
        "duration_days": 365
      },
      "startDate": "2026-05-07",
      "endDate": "2027-05-07",
      "remainingDays": 365,
      "autoRenew": false
    }
  }
}
```

**Error 403 — Not an admin:**
```json
{
  "success": false,
  "message": "Forbidden."
}
```

---

### POST /subscription/admin/verify/{order}

Verifies (approves) or rejects a payment order. Sends email notification to the user.

**Auth required:** Yes + Admin  
**URL param:** `{order}` — order's integer ID

**Request body — Approve:**
```json
{
  "is_verified": true
}
```

**Request body — Reject:**
```json
{
  "is_verified": false,
  "notes": "Transaction ID not found in PayPal dashboard. Please resubmit with a valid screenshot."
}
```

> `notes` is **required** when rejecting (`is_verified: false`).

**Response 200 — Approved:**
```json
{
  "success": true,
  "message": "Payment verified. Subscription has been activated.",
  "data": {
    "order": {
      "id": 10,
      "status": "verified",
      "statusLabel": "Verified",
      "isFinal": true,
      "verifiedAt": "2026-05-08T09:00:00+00:00",
      "subscription": {
        "id": 2,
        "status": "active",
        "startDate": "2026-05-08",
        "endDate": "2026-06-07",
        "remainingDays": 30
      }
    }
  }
}
```

**Response 200 — Rejected:**
```json
{
  "success": true,
  "message": "Payment rejected. The user has been notified.",
  "data": {
    "order": {
      "id": 10,
      "status": "rejected",
      "statusLabel": "Rejected",
      "isFinal": true,
      "notes": "Transaction ID not found in PayPal dashboard."
    }
  }
}
```

---

## Error Responses

| HTTP Status | Meaning |
|-------------|---------|
| `401` | Unauthenticated — missing or invalid Bearer token |
| `403` | Forbidden — authenticated but not allowed (wrong user or not admin) |
| `404` | Resource not found |
| `422` | Validation failed — see `errors` object for field-level messages |
| `500` | Internal server error |

**401 Example:**
```json
{
  "message": "Unauthenticated."
}
```

**422 Example:**
```json
{
  "message": "The name field is required.",
  "errors": {
    "name": ["The name field is required."],
    "email": ["The email field must be a valid email address."]
  }
}
```

---

## Status Enums

### SubscriptionStatus

| Value | Label | Is Active? |
|-------|-------|-----------|
| `trial` | Trial | ✅ Yes |
| `active` | Active | ✅ Yes |
| `expired` | Expired | ❌ No |
| `cancelled` | Cancelled | ❌ No |

### PaymentOrderStatus

| Value | Label | Is Final? |
|-------|-------|-----------|
| `pending` | Pending | ❌ No (can still submit proof) |
| `verified` | Verified | ✅ Yes |
| `rejected` | Rejected | ✅ Yes |
| `cancelled` | Cancelled | ✅ Yes |

---

## Route Summary

| Method | URL | Auth | Description |
|--------|-----|------|-------------|
| POST | `/api/v1/auth/register` | Public | Register & get token |
| POST | `/api/v1/auth/login` | Public | Login & get token |
| POST | `/api/v1/auth/logout` | Bearer | Revoke current token |
| GET | `/api/v1/auth/me` | Bearer | Current user + subscription |
| GET | `/api/v1/subscription/me` | Bearer | Status + history (self) |
| GET | `/api/v1/subscription/status/{user}` | Bearer | Full status for user |
| GET | `/api/v1/subscription/check/{user}` | Bearer | Quick validity check |
| GET | `/api/v1/subscription/plans` | Bearer | List active plans |
| GET | `/api/v1/subscription/plans/{plan}` | Bearer | Plan detail |
| GET | `/api/v1/subscription/history/{user}` | Bearer | Subscription history |
| POST | `/api/v1/subscription/trial/activate` | Bearer | Activate free trial |
| GET | `/api/v1/subscription/trial/{user}` | Bearer | Trial status |
| POST | `/api/v1/subscription/order` | Bearer | Create payment order |
| POST | `/api/v1/subscription/payment/proof` | Bearer | Submit payment proof |
| GET | `/api/v1/subscription/order/{order}` | Bearer | Order detail |
| GET | `/api/v1/subscription/payments/{user}` | Bearer | Payment history |
| POST | `/api/v1/subscription/admin/activate` | Admin | Manually activate subscription |
| POST | `/api/v1/subscription/admin/verify/{order}` | Admin | Verify/reject payment |
