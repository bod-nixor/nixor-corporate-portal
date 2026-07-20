# Nixor Connect Entitlement Operations

This runbook covers the NCP side of Nixor Connect identity, desired membership state, and durable change delivery to Panapticon. NCP remains authoritative for eligibility, suspension, institutional roles, entity/department/project relationships, and managed resource keys. It does not store Matrix room IDs.

## Release artifacts

- Targeted migration: `202607190001_connect_identity_delivery_hardening.sql`
- Identity endpoints:
  - `POST /api/connect/identity/resolve-google`
  - `POST /api/connect/entitlements/resolve`
- Worker entrypoints:
  - `php cron/run.php --connect-entitlement-reconciliation`
  - `php cron/run.php --connect-entitlement-outbox`
- Readiness check: `php scripts/check-connect-readiness.php`

## Required configuration

Configure independent secrets for API resolution and outbound delivery. Each secret must contain 32–512 printable ASCII characters. Do not reuse Google, database, session, OAuth-state, or Panapticon API credentials.

```dotenv
NCP_API_SHARED_SECRET=
CONNECT_ENTITLEMENT_WEBHOOK_URL=https://<panapticon-host>/internal/ncp/entitlements/changed
CONNECT_ENTITLEMENT_WEBHOOK_SECRET=
CONNECT_ENTITLEMENT_WEBHOOK_MAX_ATTEMPTS=10
CONNECT_ENTITLEMENT_WEBHOOK_LEASE_SECONDS=600
CONNECT_ENTITLEMENT_RECONCILIATION_BATCH_SIZE=250
AUTOMATED_EMAILS_ENABLED=false
```

The webhook URL must use HTTPS in production, contain no credentials/query/fragment, and use the exact path shown above. Configure the same webhook secret as `NCP_ENTITLEMENT_WEBHOOK_SECRET` in Panapticon. The API shared secret is separate and must match Panapticon's `NCP_API_SHARED_SECRET`.

## Pre-deployment gate

1. Record the application SHA and take a verified MariaDB backup.
2. Record non-PII baseline counts for users, Connect identities, resource mappings, and outbox statuses.
3. Verify that the existing migration ledger is trustworthy for the already-deployed Connect migrations. Do not insert historical ledger rows merely to make the runner pass.
4. Rehearse the target migration on a restored production-like copy and on an empty MariaDB database.
5. Confirm the Panapticon release accepts `delivered_at` separately from the original `occurred_at`; delayed outbox retries depend on this contract.

## Safe production migration

The production NCP migration ledger is not assumed to be fully baselined. Apply only the new migration:

```bash
/usr/bin/php -q scripts/migrate.php --only 202607190001_connect_identity_delivery_hardening.sql
```

Run the same command a second time. It must report `skipped`, proving the recorded checksum matches. Do not use the unfiltered migration command on production until the complete historical ledger has been audited against a backup.

The migration is additive. It creates immutable Matrix-ID claims, entitlement snapshots/reconciliation state, and outbox lease/dead-letter fields. It does not delete or rewrite existing identities, resource mappings, memberships, or outbox events.

## Deployment and activation

1. Apply the targeted migration.
2. Deploy the NCP application code without replacing production `.env`, `.htaccess`, uploads, logs, or runtime files.
3. Deploy the compatible Panapticon webhook receiver.
4. Configure both independent secrets and the HTTPS webhook URL.
5. Run:

   ```bash
   /usr/bin/php -q scripts/check-connect-readiness.php
   /usr/bin/php -q cron/run.php --connect-entitlement-reconciliation
   /usr/bin/php -q cron/run.php --connect-entitlement-outbox
   ```

6. Configure the generic non-email cron once per minute. It runs a bounded reconciliation batch before dispatching the outbox:

   ```bash
   * * * * * /usr/bin/php -q /home/<cpanel_user>/public_html/portal/cron/run.php
   ```

7. Resolve one approved synthetic test user through Google and current-entitlement endpoints. Verify the same NCP user ID, Matrix user ID, entitlement version, and stable `updated_at` are returned on a repeated unchanged request.
8. Change one synthetic managed membership, verify a queued event becomes `sent`, and verify Panapticon performs one idempotent reconciliation.

## Identity behavior

- Every supplied lookup identifier must resolve to the same NCP subject. A conflicting NCP user ID, Google subject, email, or Matrix ID is rejected instead of falling back to the first match.
- Matrix IDs are claimed once. Existing explicit IDs and unambiguous legacy email-localpart IDs are preserved; ambiguous/colliding identities receive a stable hash suffix.
- Matrix-only lookup uses the persisted claim and never guesses by removing punctuation from an email address.
- Verified Google binding links an existing email overlay to the real NCP user, records verification/login timestamps, and never grants access to an inactive or suspended user.
- Entitlement versions hash the complete canonical desired state. Their `updated_at` changes only when that state changes.

## Worker behavior and monitoring

The reconciliation cursor scans at most `CONNECT_ENTITLEMENT_RECONCILIATION_BATCH_SIZE` users per run. It repairs missed application events and catches date/expiry-driven changes. Outbox workers claim rows conditionally, recover expired leases, refresh `delivered_at` on each attempt, and dead-letter after the configured attempt ceiling.

Monitor:

- queued/failed age and count,
- stale `sending` leases,
- dead-letter count,
- reconciliation failures and cursor progress,
- Panapticon duplicate-event and reconciliation failure metrics.

`scripts/check-connect-readiness.php` returns only configuration state and aggregate counts; it does not print secrets or student identity data. Any dead-letter or stale lease makes the readiness result fail until investigated.

After correcting the downstream cause, requeue one reviewed event by exact event ID using a controlled SQL session:

```sql
UPDATE connect_entitlement_outbox
SET status = 'queued', attempts = 0, next_attempt_at = UTC_TIMESTAMP(6),
    claim_token = NULL, claimed_at = NULL, dead_lettered_at = NULL, last_error = NULL
WHERE event_id = '<reviewed-event-id>' AND status = 'dead_letter';
```

Record the operator, event ID, reason, and change ticket outside the database. Never bulk-requeue dead letters without classifying the root cause.

## Rollback

Application rollback is safe because the migration is additive: stop the cron, restore the previous application SHA, and leave the new tables/columns in place. Do not remove the migration ledger row or drop schema during an incident. If DDL itself fails partway on a shared-hosting engine, stop, preserve logs, and restore the rehearsed backup or ship a reviewed forward repair migration. Re-enable workers only after readiness and the synthetic entitlement flow pass.

## Release verification

Required evidence:

- full PHPUnit suite,
- PHP lint for application, cron, scripts, and tests,
- Composer strict validation and locked dependency audit,
- empty-database migration,
- prior-ledger targeted migration plus second-run checksum skip,
- readiness output with no secrets,
- Panapticon unit/build/audit checks after the delivery-timestamp contract change.
