# ROICARD staging checklist

This is the concise staging runbook. It does not contain production credentials,
prices, plan limits or referral rewards; those remain founder/admin decisions.

## 1. Before deployment

- [ ] Take a verified database and media backup as described in
      `deploy/backup-restore.md`.
- [ ] Confirm the staging database is empty or intentionally prepared; never
      restore over production.
- [ ] Confirm the staging domain and HTTPS certificate.
- [ ] Confirm `storage/app/private` is persistent and writable by PHP/queue.
- [ ] Confirm `storage/app/private` is **not** exposed by Nginx or a symlink.
- [ ] Confirm public media uses the public disk and `storage:link`.

## 2. Environment configuration

Copy `deploy/.env.production.example` to staging `.env`, then set real staging
values. At minimum verify:

- `APP_ENV=production` (or the staging value used by your platform)
- `APP_KEY` generated on staging
- `APP_DEBUG=false`
- `APP_URL=https://staging-domain`
- `FRONTEND_URL=https://staging-domain`
- `DB_CONNECTION=mysql` or `mariadb`, with staging database credentials
- `SESSION_SECURE_COOKIE=true`
- `SESSION_HTTP_ONLY=true`
- `SESSION_DOMAIN` and `SANCTUM_STATEFUL_DOMAINS` aligned to staging
- `CORS_ALLOWED_ORIGINS` contains only the staging frontend origin
- `QUEUE_CONNECTION=database` (or the chosen queue backend)
- `MAIL_MAILER` set to a real staging mail transport, not `log`/`array`
- `MEDIA_DISK=public` for public profile/catalog media
- `PAYMENT_PROVIDER` set to the approved provider; **not `mock`**
- `BILLING_ALLOW_MOCK=false`
- provider credentials supplied by the deployment secret manager
- `REFERRALS_ENABLED=false` until founder rules are approved

### AI (Gemini)

- `GEMINI_API_KEY` is supplied only through the staging secret store. Never add
  it to the Next.js environment or expose it to the browser.
- `GEMINI_MODEL` and `GEMINI_REGION` are confirmed with the provider/legal
  owner. Gemini must be on a plan that does not train on submitted data.
- `AI_CONSENT_VERSION` and `AI_CONSENT_TEXT` are reviewed and approved by the
  founder before pilot members see the consent screen.
- `PLAN_LIMIT_AI_CALLS_PER_DAY` (or per-plan `AI_CALLS_PER_DAY`) is set to the
  founder-approved value. Do not guess it.
- `AI_MONTHLY_COST_ALERT_USD`, `AI_INPUT_PRICE_PER_MILLION_USD` and
  `AI_OUTPUT_PRICE_PER_MILLION_USD` are set from approved Gemini pricing. They
  stay blank until approved; this disables estimates rather than inventing them.
- Confirm AI fails safely with the key blank: members can still complete and
  publish profiles manually.
- Confirm application logs contain AI token/outcome metadata but never prompt
  text or contact details.

## 3. Install, migrate and storage

```bash
composer install --no-interaction --optimize-autoloader --no-dev
php artisan key:generate --show     # put the resulting value in APP_KEY
php artisan migrate --force
php artisan db:seed --class=PlanSeeder --force
php artisan storage:link
php artisan config:cache
php artisan route:cache
```

Do not run `migrate:fresh` against staging. Do not invent plan prices or limits;
enter only founder-approved values in `/admin/plans`.

## 4. Preflight

```bash
php artisan deploy:preflight
```

Review every ERROR and WARNING. Resolve them before inviting pilot members. The
command is read-only and does not modify configuration, files, migrations or
data.

For an auditable machine-readable record:

```bash
php artisan deploy:preflight --json
```

Use `--strict` only after all warnings have been intentionally accepted; it
returns a non-zero exit code for warnings as well as errors. Mock payments are
also hard-disabled at runtime outside `local`/`testing`, regardless of
`BILLING_ALLOW_MOCK`.

## 5. Private CV migration procedure

1. Confirm the staging backup exists.
2. Preview legacy CV rows:

   ```bash
   php artisan media:migrate-private-cvs --dry-run
   ```

3. Review dry-run candidates, missing files and failures.
4. Run the copy/re-point only on staging:

   ```bash
   php artisan media:migrate-private-cvs
   ```

5. Verify:
   - owner can download a CV from `/api/profiles/{profile}/cv/{media}`;
   - an administrator can download it;
   - a guest/unrelated member gets `403` for a hidden CV;
   - a visible CV on a public profile downloads through the API endpoint;
   - no `/storage/` or private filesystem path appears in public API responses;
   - original public files remain until manually reviewed.

The command never deletes originals. Remove public originals manually only after
a verified backup and review.

## 6. Scheduler and workers

- [ ] Run a queue worker under the chosen process manager.
- [ ] Add the scheduler cron:

  ```cron
  * * * * * cd /path/to/roicard && php artisan schedule:run >> /dev/null 2>&1
  ```

- [ ] Confirm `billing:process`, `recommendations:refresh`, draft reminders and
      meeting reminders are scheduled.
- [ ] Check failed jobs and worker logs after the first test workflow.

## 7. Payment and webhook setup

- [ ] `PAYMENT_PROVIDER` is a configured real provider.
- [ ] Provider secret key/credentials are present in the staging secret store.
- [ ] Register the staging webhook URL:

  ```text
  https://staging-domain/api/payments/webhook/{provider}
  ```

- [ ] Verify a provider test event and confirm duplicate delivery is idempotent.
- [ ] Confirm `BILLING_ALLOW_MOCK=false`.
- [ ] Confirm no real customer is charged during staging tests.
- [ ] Confirm plan prices/intervals remain unset until founder approval.

Never place real credentials in this checklist or commit them to the repository.

## 8. Post-deploy verification

- [ ] Guest can view a published public profile; unpublished/private profiles
      return the correct restricted response.
- [ ] Search, Discover, recommendations, connections, catalog enquiries and
      organization directory work.
- [ ] Member connection request/accept/decline flows work.
- [ ] Admin moderation, billing, referrals and metrics screens are accessible
      only to admins.
- [ ] Signature-card preview/download and QR source tracking work.
- [ ] Referral attribution respects the configured window.
- [ ] Blocking hides members from relevant results.
- [ ] Logs do not expose private CV paths, provider secrets or contact details.
- [ ] `php artisan deploy:preflight` remains free of blocking errors after the
      first deployment.

## 9. Rollback

Use the staging-only restore procedure in `deploy/backup-restore.md`. Never
point a staging restore at production. If a migration must be reversed, test the
rollback on a restored staging copy first.
