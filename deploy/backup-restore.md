# ROICARD backup and restoration

This document covers the pilot deployment. No automated backup job exists in the
repository; backups must be scheduled and monitored by the hosting operator.

## What must be backed up

1. **Database** — members, profiles, connections, organizations, catalog,
   enquiries, referrals, payments, subscriptions, billing events, audit logs and
   entitlement usage.
2. **Uploaded media**:
   - `storage/app/public` — profile photos, business logos/covers, catalog
     images and organization media.
   - configured media disk (`S3`/equivalent) if `FILESYSTEM_DISK` or the Spatie
     media disk is remote.
3. **Private generated and document media**:
   - `storage/app/private/signature-cards` — generated signature-card PNGs.
   - `storage/app/private` — private CV/document media (see
     `deploy/private-cv-storage.md`).
   - any other private local storage used by the deployment.
4. **Configuration** — `.env` or the secret manager values, application key,
   payment webhook secrets, CORS/frontend URLs.
5. **Deployment metadata** — release identifier, migration version and a copy
   of `composer.lock` / frontend lockfile.

## MySQL/MariaDB backup

Run from a host with database credentials, using `--single-transaction` so the
backup is consistent without taking the application offline:

```bash
mysqldump --single-transaction --routines --triggers \
  --default-character-set=utf8mb4 \
  -h "$DB_HOST" -u "$DB_USERNAME" -p "$DB_DATABASE" \
  | gzip > "roicard-$(date +%Y%m%dT%H%M%S).sql.gz"
```

Store the dump encrypted (for example with `age` or `gpg`) with restricted
access. Keep at least one off-host copy.

## Media backup

```bash
tar -C /path/to/roicard -czf "roicard-media-$(date +%Y%m%dT%H%M%S).tar.gz" \
  storage/app/public storage/app/private/signature-cards
```

If media uses S3, enable bucket versioning and replication instead of relying
only on local tarballs.

## Restoration into a fresh staging environment

**Never restore over the live database.**

1. Provision empty staging database and application storage.
2. Deploy the same application release and `.env` shape.
3. Restore the database into the empty staging database:

   ```bash
   gunzip -c backup.sql.gz | mysql -h "$STAGING_DB_HOST" -u "$STAGING_DB_USERNAME" -p "$STAGING_DB_DATABASE"
   ```

4. Restore media files into the staging application storage, then run:

   ```bash
   php artisan storage:link
   php artisan migrate:status
   php artisan config:clear
   php artisan cache:clear
   ```

5. Verify:
   - public profile photo and business/catalog images render;
   - a generated signature card image and its signed URL render;
   - QR/profile links resolve;
   - payment, subscription, referral and audit rows are present;
   - a non-owner cannot access private profiles, CVs, enquiries or signature
     images;
   - an admin can open `/admin/metrics` and `/admin/billing`.

## SQLite/local restoration check

The local pilot database uses SQLite. A safe isolated check is:

```powershell
Copy-Item database/roicard-local.sqlite "$env:TEMP/roicard-restore-check.sqlite"
$env:DB_CONNECTION='sqlite'
$env:DB_DATABASE="$env:TEMP/roicard-restore-check.sqlite"
php artisan migrate:status
```

Also copy `storage/app/public` and `storage/app/private/signature-cards` to a
temporary directory and compare file counts/hashes. This validates the backup
procedure without touching the live database.

## Backup schedule for pilot

- Database: every 6 hours, plus a manual dump before every deployment.
- Media: daily incremental or bucket versioning; weekly full copy.
- Retention: at least 30 daily database backups and 12 weekly media backups,
  encrypted at rest. The final retention decision remains a founder decision.
- Restoration drill: at least once before the pilot and after any material
  migration.
