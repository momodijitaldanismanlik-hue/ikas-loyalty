# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Running the app

```bash
node index.js
```

Requires a `.env` file with:
```
DATABASE_URL=...
IKAS_STORE=<store-subdomain>       # used in: https://<store>.myikas.com/api/admin/oauth/token
IKAS_CLIENT_ID=...
IKAS_CLIENT_SECRET=...
ADMIN_SECRET=...
PORT=3000                          # optional, defaults to 3000
```

No build step — this is plain CommonJS Node.js (`"type": "commonjs"` in package.json).

## Architecture

Everything lives in a single file: `index.js`.

**External integrations:**
- **ikas API** — OAuth2 client-credentials flow via `getIkasToken()`, then GraphQL queries via `ikasQuery()` against `https://api.myikas.com/api/v1/admin/graphql`. A new token is fetched on every request (no caching).
- **PostgreSQL** — accessed via `pg.Pool` using `DATABASE_URL`. SSL is enabled with `rejectUnauthorized: false`.

**Database tables (not defined in this repo — must exist externally):**
- `loyalty_wallets` — one row per `customer_id`, holds `points_balance` and `updated_at`
- `loyalty_transactions` — append-only log with columns: `customer_id`, `order_id`, `type` (`EARN` | `REFUND`), `points`, `order_total`, `description`, `created_at`
- `orders_sync` — deduplication table; stores `order_id` values that have already been processed for EARN

**Core logic:**
- Points formula: `floor(orderTotal / 100) * 5` — 5 points per 100 TL
- `syncOrders()` — fetches all orders from ikas and processes them: awards EARN points for PAID orders (idempotent via `orders_sync`), and issues REFUND transactions for REFUNDED/CANCELLED orders if an EARN was previously recorded
- `node-cron` runs `syncOrders()` every 5 minutes automatically

**Routes:**

| Method | Path | Auth | Description |
|--------|------|------|-------------|
| GET | `/` | — | DB health check |
| GET | `/ikas-test` | — | Calls ikas `me` query |
| GET | `/ikas-orders` | admin | Lists all ikas orders |
| GET | `/loyalty-transactions/:customerId` | admin | Returns wallet row (note: see known issue below) |
| POST | `/earn` | admin | Manually award points |
| GET | `/sync-orders` | admin | Trigger a manual sync |

Admin auth: pass `key=<ADMIN_SECRET>` as query param or `x-admin-key` header.

## Known issue

There are two `GET /loyalty-transactions/:customerId` routes registered (lines 262 and 289). Express matches the first one, so the second route (which returns both wallet and transactions) is **unreachable**. The admin-protected route at line 262 always takes precedence.
