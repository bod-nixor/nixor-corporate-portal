# Nixor Entities Endeavour Dashboard

Production-ready Next.js 14 (App Router) platform for managing Nixor entities, endeavours, and volunteer participation. The system enforces Google OAuth domain restrictions, RBAC, entity-scoped permissions, rate limits, and parent notifications.

## Tech Stack
- Next.js 14 (App Router) + TypeScript
- Prisma ORM (MySQL provider) with migrations
- MySQL (compatible with cPanel-hosted instances)
- Tailwind CSS + shadcn/ui primitives
- NextAuth (Google OAuth restricted to `@nixorcollege.edu.pk`)
- Nodemailer via SMTP (mocked locally)
- Redis-compatible rate limiting (Upstash/Redis or in-memory fallback)
- Pino logging, Zod validation, Vitest for utilities

## Getting Started

### Prerequisites
- Node.js 18+
- pnpm / npm / yarn (project agnostic)
- Access to a MySQL database (8.0+ recommended)
- Optional Redis (local or managed) for production rate limiting

### Environment Variables
Copy `.env.example` to `.env.local` and populate:

```bash
cp .env.example .env.local
```

Key variables:
- `DATABASE_URL`: MySQL connection string. For cPanel, include host/port/user/password explicitly: `mysql://USER:PASSWORD@HOST:PORT/DB_NAME`.
- `DIRECT_URL`: Optional direct connection string (Prisma).
- `GOOGLE_CLIENT_ID` / `GOOGLE_CLIENT_SECRET`: OAuth credentials restricted to the Nixor domain.
- `NEXTAUTH_SECRET`: Generate via `openssl rand -base64 32`.
- `SMTP_*`: SMTP credentials. In development, a console provider logs emails.
- `REDIS_URL`: Optional Redis URL (e.g., Upstash). If omitted, an in-memory rate limiter is used.
- `VISIBILITY_MODE`: `RESTRICTED` or `OPEN`.

### cPanel MySQL Connection
1. Create a remote MySQL user from cPanel and whitelist your server IPs.
2. Compose the Prisma connection string using SSL parameters if required: `mysql://USER:PASSWORD@HOST:PORT/DB_NAME?sslmode=require`.
3. Update `prisma/schema.prisma` if you must supply CA certificates (see Prisma docs).
4. Run `npx prisma generate` and `npx prisma migrate deploy` on the hosting environment.

### Installation & Development

```bash
npm install
npm run dev
```

The app runs at `http://localhost:3000`.

### Database Migrations & Seeding

```bash
npx prisma migrate dev
npm run seed
```

In production/cPanel:

```bash
npx prisma migrate deploy
```

### Docker Compose (Local Dev)

```bash
docker-compose up -d
```

This provisions:
- MySQL 8.0 with exposed port 3306, seeded via Prisma migrations.
- Redis (optional) for rate limiting.

### RBAC Matrix

| Role | Global Scope | Entity Scope |
| ---- | ------------ | ------------ |
| ADMIN | Manage settings, quotas, audit logs | Can act as entity manager anywhere |
| HR | Shortlist volunteers, manage notes | View entity history |
| ENTITY_MANAGER | Create endeavours for entities they manage | Approve volunteers |
| VOLUNTEER | View/register per visibility mode | Submit consent, payments |

Entity memberships (`EntityMembership`) assign `ENTITY_MANAGER` or `VOLUNTEER` per entity.

### Visibility Modes
- **Restricted View**: Volunteers only see endeavours for entities they belong to.
- **Open View**: Volunteers see all endeavours but can only register where they belong.
Switch via Admin Settings UI or `VISIBILITY_MODE` env. In production, change the env value and redeploy.

### Rate Limit Design
- Per-entity publish quota enforced via Redis key: `RATE_LIMIT_REDIS_NAMESPACE:<entityId>`.
- Rolling 7-day window using TTL resets on first publish within window.
- In dev, falls back to an in-memory map; production should supply `REDIS_URL` (Upstash, ElastiCache, etc.).

### Security Checklist
- CSP, referrer policy, frame ancestors enforced via middleware/Next config.
- OAuth restricted to `@nixorcollege.edu.pk` accounts.
- Sessions stored in DB with secure cookies (NextAuth defaults + custom TTL).
- Zod validation and RBAC guard on every route handler.
- CSRF covered by NextAuth session cookies + same-origin route handlers.
- Prisma `select` used to minimize PII.
- Audit logs recorded for sensitive actions.
- Data deletion: remove registrations/consent before deleting users (Prisma relations configured with restrictive deletes).

### Testing

```bash
npm run test
```

Vitest covers RBAC guards, visibility logic, and rate limiter windowing.

### Admin Settings
- Toggle visibility mode.
- Edit entity quotas.

### Email Templates
- `Parent_Registration_Notice`
- `Volunteer_Consent_Reminder`

Preview email templates via `/api/emails/preview` (ADMIN/HR only).

### Payments
- Abstracted payment provider with mock implementation. Integrate real providers by implementing `PaymentProvider` interface.

### Account Deletion
Implement by removing related registrations/consent snapshots (respect FK constraints) and finally deleting the user record.
