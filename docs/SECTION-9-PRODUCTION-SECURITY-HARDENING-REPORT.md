# Section 9 — Production Security Hardening Report

**Project:** Roicard
**Date:** 23 November 2026
**Scope:** Authentication, authorization, uploads, payments/webhooks, AI, configuration, dependencies and security logging.
**Status:** Completed with documented external/provider blockers.

---

## 1. Executive summary

Section 9 removed or contained every confirmed, code-level vulnerability found during this review. The work covers eight areas:

1. Cross-user idempotency-key collision and response replay.
2. Two-factor-pending tokens acting as full sessions.
3. Password-reset and email-change session persistence.
4. Weak password policy on reset and change paths.
5. Account and email enumeration through recovery endpoints.
6. Payment webhook amount/currency integrity.
7. Sensitive data in application logs.
8. Missing security headers, insecure cookie/CORS defaults, rate-limit gaps and vulnerable dependencies.

A dedicated regression suite (`tests/Feature/Section9SecurityHardeningTest.php`) was added. The complete backend suite, TypeScript check, production build, `npm audit` and `composer audit` all pass.

No section 1–8 functionality, UI, architecture or user data was removed. Databases were not seeded, wiped or structurally migrated. The project is not under Git, so pre-change copies of every modified file are stored in:

```
.security-backup/20261123-132326/
```

---

## 2. Confirmed vulnerabilities and fixes

### 2.1 Cross-user idempotency-key collision and response replay — High

**Confirmed issue.** The idempotency cache key was derived only from the client-supplied `Idempotency-Key`. Two different members (or an anonymous caller and a member) using the same key, intentionally or by accident, shared one cache entry. A second caller could therefore receive the first caller's cached JSON response, or block the first caller with a `409` while the key was processing. The middleware also replayed arbitrary cached response headers.

**Fix.**
- Cache key now includes the authenticated actor (`user:<id>` or `ip:<address>`), HTTP method, path, query string, client key and SHA-256 body hash.
- Keys are validated as 1–128 characters of `[A-Za-z0-9._:-]`; invalid keys return `400`.
- Only response body and status are stored; cached headers are no longer replayed.
- Processing locks expire after 5 minutes instead of a long TTL, preventing a failed request from permanently locking a key.
- Non-2xx responses release the key.

**Files.**
- `app/Http/Middleware/IdempotencyMiddleware.php`

**Regression coverage.**
- `test_idempotency_keys_are_scoped_to_the_authenticated_member`
- `test_idempotency_replay_for_the_same_member_returns_the_same_result`
- `test_idempotency_key_validation_rejects_unsafe_values`

---

### 2.2 Two-factor-pending token accepted as a full session — High

**Confirmed issue.** Login with 2FA enabled issued a short-lived token with the `two-factor` ability, but `auth:sanctum` accepted that token on every authenticated route. A password-only attacker could therefore reach `/api/me`, account export, profile editing and other member data without completing the second factor. The same token was also accepted as a viewer on public profile, CV, booking and QR routes, exposing members-only profiles.

**Fix.**
- New `EnsureFullAccessToken` middleware, registered as `full.access` and applied to the authenticated route group. It requires the current access token to have the `*` ability; only `api/auth/two-factor/verify` and `api/auth/logout` are exempt.
- New `App\Support\BearerViewer` resolves optional public-route viewers only when the bearer token has full access. Applied to:
  - `PublicProfileController`
  - `CvDownloadController`
  - `PublicBookingController`
  - `QRController`
- Public viewer resolution no longer treats a 2FA-pending token as a signed-in member.

**Files.**
- `app/Http/Middleware/EnsureFullAccessToken.php` (new)
- `app/Support/BearerViewer.php` (new)
- `bootstrap/app.php`
- `routes/api.php`
- `app/Http/Controllers/Api/PublicProfileController.php`
- `app/Http/Controllers/Api/CvDownloadController.php`
- `app/Http/Controllers/Api/PublicBookingController.php`
- `app/Http/Controllers/Api/QRController.php`

**Regression coverage.**
- `test_two_factor_pending_token_cannot_use_general_authenticated_routes`
- `test_two_factor_pending_token_cannot_read_restricted_profiles`

---

### 2.3 Password reset did not revoke existing sessions — High

**Confirmed issue.** A successful password reset changed the password but left every existing Sanctum token valid. An attacker holding a stolen token retained access after the victim reset their password.

**Fix.** The reset callback now deletes all of the user's access tokens. Sessions established before the reset are invalid immediately.

**Files.**
- `app/Http/Controllers/Api/AuthController.php`

**Regression coverage.**
- `test_password_reset_revokes_existing_sessions_and_requires_strong_password`

---

### 2.4 Weak passwords accepted on reset and change — Medium

**Confirmed issue.** Registration enforced an uppercase/lowercase/number-or-symbol policy, but password reset and authenticated password change accepted any 8-character string. A member could reduce their own account security through reset, and a reset token could set a trivially weak password.

**Fix.** Centralised `App\Support\PasswordPolicy` now supplies the same rules and messages to registration, authenticated password change and password reset.

**Files.**
- `app/Support/PasswordPolicy.php` (new)
- `app/Http/Requests/RegisterRequest.php`
- `app/Http/Controllers/Api/AuthController.php`

**Regression coverage.**
- `test_password_reset_revokes_existing_sessions_and_requires_strong_password`

---

### 2.5 Account and email enumeration — Medium

**Confirmed issue.**
- `POST /api/auth/forgot-password` returned different messages/statuses for registered and unregistered addresses.
- `POST /api/auth/verify-email/code` returned `404` for unknown addresses and a different message for already-verified addresses.
- Resend-verification responses distinguished unknown and already-verified accounts.

**Fix.**
- Forgot-password always returns: “If that email is registered, a password reset link has been sent.”
- Verification-code failures always return `422` with “Invalid or expired verification code”.
- Resend-verification always returns the same neutral message.
- Throttling still applies, so enumeration cannot be accelerated.

**Files.**
- `app/Http/Controllers/Api/AuthController.php`
- `routes/api.php`

**Regression coverage.**
- `test_forgot_password_does_not_reveal_unknown_email`
- `test_email_verification_code_does_not_reveal_missing_accounts`

---

### 2.6 Email change without re-authentication — Medium/High

**Confirmed issue.** An authenticated session could change the account email without knowing the current password. Combined with an unrevoked stolen session, this permitted persistent account takeover: the attacker changes the address, verifies it, and locks the legitimate member out.

**Fix.**
- Changing the email now requires `current_password`.
- Comparison is case-insensitive, so a case-only change does not trigger the password prompt.
- The new address is stored lowercase and marked unverified.
- All other access tokens are revoked; the current session remains usable.
- The settings UI exposes a current-password field and the API client type was updated.

**Files.**
- `app/Http/Controllers/Api/AuthController.php`
- `client/lib/api/auth.ts`
- `client/components/settings/AccountSettingsSection.tsx`
- `tests/Feature/AccountSecurityTest.php`

**Regression coverage.**
- `test_email_change_requires_the_current_password`

---

### 2.7 Payment webhook amount/currency not verified — High

**Confirmed issue.** Paystack webhooks were signature-verified, but the signed event's `amount` and `currency` were not compared with the server-created `Payment` record before activation. A validly signed event for a different amount or currency could activate a plan that was not paid for, and provider-side misconfiguration or currency confusion could silently grant access.

**Fix.** Before marking a Paystack payment successful, `BillingService` now:
- Converts the server-side payment amount to minor units and requires an exact match with `data.amount`.
- Requires `data.currency` to match the payment currency case-insensitively.
- Logs `critical` entries containing payment ID plus expected/received values (no customer PII) and rejects the webhook with `422` on mismatch. The payment remains pending.

The API also continues to ignore client-supplied amounts; payment amounts are derived server-side.

**Files.**
- `app/Services/BillingService.php`
- `app/Http/Requests/PaymentInitiateRequest.php` (verified: amount is not accepted)

**Regression coverage.**
- `test_paystack_webhook_rejects_amount_mismatch_before_activation`

---

### 2.8 Catalog enquiries bypassed contactability — Medium

**Confirmed issue.** Catalog item enquiries checked profile visibility but not the member's “accepting enquiries/contact” preference. A restricted business could still receive enquiry emails through a catalog item.

**Fix.** `CatalogEnquiryService` now calls `ProfileVisibilityService::canContact` in addition to `canView`, matching the existing connection-request rule.

**Files.**
- `app/Services/CatalogEnquiryService.php`

**Regression coverage.**
- Covered by existing catalog/visibility suites and the full regression run.

---

### 2.9 Sensitive data in logs — Medium

**Confirmed issue.** Logs included:
- Full OAuth callback query strings (which can contain `code` and `state`).
- Member email addresses in social auth, mail and CLI logs.
- Full request URLs in cross-user leak diagnostics.

**Fix.**
- OAuth failure logs no longer include query parameters.
- Social-signup, Google-signup, verification-email, guest-notification, restore and purge logs use IDs (user/booking) instead of email addresses.
- Cross-user leak logs retain path, method and IP but not the full URL or query string.

**Files.**
- `app/Http/Controllers/Api/SocialAuthController.php`
- `app/Http/Controllers/Api/GoogleAuthController.php`
- `app/Http/Controllers/Api/AuthController.php`
- `app/Services/GuestNotificationService.php`
- `app/Console/Commands/PurgeUserEmail.php`
- `app/Console/Commands/RestoreUser.php`
- `app/Http/Middleware/PreventCrossUserLeak.php`

---

### 2.10 AI prompt injection through member-controlled content — Medium

**Confirmed issue.** Profile-generation and search-interpretation prompts inserted CV text, website text and member queries without a trust boundary. Content could contain instructions attempting to change the model's role, output format or factual constraints, affecting generated profile content or filter parsing.

**Fix.** Prompts now explicitly state that all source text and member queries are untrusted data, never instructions. The model must ignore embedded instructions, role changes and formatting requests, and may only return the documented JSON schema using supported facts.

**Files.**
- `app/Services/Ai/ProfileAiService.php`

**Residual risk.** Prompt hardening reduces but does not eliminate model-level prompt injection. All AI output is still validated against schemas and never treated as executable input.

---

### 2.11 Missing baseline security headers — Medium/Low

**Confirmed issue.** API and frontend responses lacked standard browser hardening headers, and Next.js advertised `X-Powered-By`.

**Fix.**
- Laravel `SecurityHeaders` middleware now sets:
  - `X-Content-Type-Options: nosniff`
  - `X-Frame-Options: DENY`
  - `Referrer-Policy: strict-origin-when-cross-origin`
  - `Permissions-Policy: camera=(), microphone=(), geolocation=()`
  - `X-Permitted-Cross-Domain-Policies: none`
  - `Strict-Transport-Security` on secure requests
- Next.js sets the equivalent headers, conditionally adds HSTS in production, and disables `X-Powered-By`.

A strict frontend Content-Security-Policy was deliberately not added in this pass: Next.js App Router requires nonce/hash-aware CSP integration, and an incorrect policy would break the UI. This is listed as a remaining risk.

**Files.**
- `app/Http/Middleware/SecurityHeaders.php` (new)
- `bootstrap/app.php`
- `client/next.config.ts`

**Regression coverage.**
- `test_security_headers_are_present_on_api_responses`

---

### 2.12 Insecure session cookie default — Medium

**Confirmed issue.** `SESSION_SECURE_COOKIE` defaulted to `null`, so production cookies were not secure unless the environment explicitly set the flag.

**Fix.** Secure cookies are now the default outside `local` and `testing`. The environment variable still overrides the default.

**Files.**
- `config/session.php`
- `deploy/.env.production.example` (already sets `SESSION_SECURE_COOKIE=true`)

---

### 2.13 Wildcard CORS combined with credentialed requests — Medium

**Confirmed issue.** CORS origins were read raw (whitespace preserved), and `supports_credentials` could remain enabled while `*` was configured, which browsers reject or which can be misconfigured into an unsafe combination.

**Fix.**
- Origins are trimmed and empty entries removed.
- Credential support defaults to `false` whenever `*` is present.
- `deploy/.env.production.example` documents the rule.

**Files.**
- `config/cors.php`
- `.env.example`
- `deploy/.env.production.example`

---

### 2.14 Rate-limit gaps — Medium/Low

**Confirmed issue.** Several sensitive or abuse-prone endpoints had no throttle:
- Email verification by code.
- Password reset submission.
- Account reactivation.
- 2FA setup/confirm/disable/verify.
- Password change.
- Reports.
- Blocks.
- Account data export.

**Fix.**
- `verify-email`: 5/min per email+IP.
- `forgot-password` / `reset-password`: 10/min per IP.
- `reactivate` / password change: login throttle.
- 2FA endpoints: 5/min per authenticated user or IP.
- Reports: 10/min per user/IP.
- Blocks: 20/min per user/IP.
- Account export: 3/min per user/IP.

**Files.**
- `app/Providers/AppServiceProvider.php`
- `routes/api.php`

---

### 2.15 Vulnerable dependencies — High

**Confirmed issue.** `npm audit` and `composer audit` reported advisories affecting the Next.js version and several PHP packages.

**Fix.** Patch-level and compatible upgrades only; no major framework migrations.

#### npm (`client`)

| Package | Before | After | Reason |
|---|---|---|---|
| `next` | 16.4.3 | 16.4.6 | Patch release containing security fixes; also removes the reported advisories. |
| `eslint-config-next` | 16.4.3 | 16.4.6 | Must track the Next.js version. |
| `follow-redirects` (override) | vulnerable range | 1.16.3 | Transitive Axios dependency with a redirect-header leak advisory. |
| `typescript-eslint` and related | older | 8.73.0 | `npm audit fix`; resolves the `minimatch` ReDoS advisory chain. |
| `minimatch` | vulnerable ranges | 3.1.5 / 11.0.9 | Deduped/updated through the above. |

Result: `npm audit` reports **0 vulnerabilities**.

#### Composer (backend)

Targeted `composer update` (no major-version migration):

| Package | After | Reason |
|---|---|---|
| `laravel/framework` | 12.73.0 | Security and correctness patches in the 12.x line. |
| `symfony/http-foundation`, `symfony/http-kernel`, related HTTP components | 7.4.23 | Security patches. |
| `phpseclib/phpseclib` | 3.0.59 | Security patch. |
| `guzzlehttp/*` | compatible latest | Security patch. |

Result: `composer audit` reports **No security vulnerability advisories found**.

No public API, route shape, database schema or UI behaviour was changed by these upgrades.

---

## 3. Verification

### 3.1 Focused security suite

```
php artisan test tests/Feature/Section9SecurityHardeningTest.php
```

```
PASS  Tests\Feature\Section9SecurityHardeningTest
Tests: 11 passed (50 assertions)
```

Coverage:
- Idempotency scoping, replay, key validation.
- Security headers.
- Password reset revocation and strength policy.
- Forgot-password and verification-code enumeration resistance.
- Email-change re-authentication.
- 2FA-pending token blocked from authenticated routes and restricted profiles.
- Paystack amount mismatch rejection.

### 3.2 Full backend suite

```
php artisan test
```

```
Tests: 465 passed (2212 assertions)
Duration: 87.34s
```

### 3.3 Frontend

```
cd client
npx tsc --noEmit
```
No output — TypeScript clean.

```
npm run build
```
Production build completed successfully with all routes generated.

### 3.4 Dependency audits

```
npm audit        -> found 0 vulnerabilities
composer audit   -> No security vulnerability advisories found
```

---

## 4. Files changed

### New files
- `app/Http/Middleware/EnsureFullAccessToken.php`
- `app/Http/Middleware/SecurityHeaders.php`
- `app/Support/BearerViewer.php`
- `app/Support/PasswordPolicy.php`
- `tests/Feature/Section9SecurityHardeningTest.php`
- `docs/SECTION-9-PRODUCTION-SECURITY-HARDENING-REPORT.md`

### Modified backend
- `app/Http/Middleware/IdempotencyMiddleware.php`
- `app/Http/Middleware/PreventCrossUserLeak.php`
- `app/Http/Controllers/Api/AuthController.php`
- `app/Http/Controllers/Api/SocialAuthController.php`
- `app/Http/Controllers/Api/GoogleAuthController.php`
- `app/Http/Controllers/Api/PublicProfileController.php`
- `app/Http/Controllers/Api/CvDownloadController.php`
- `app/Http/Controllers/Api/PublicBookingController.php`
- `app/Http/Controllers/Api/QRController.php`
- `app/Http/Requests/RegisterRequest.php`
- `app/Providers/AppServiceProvider.php`
- `app/Services/BillingService.php`
- `app/Services/CatalogEnquiryService.php`
- `app/Services/Ai/ProfileAiService.php`
- `app/Services/GuestNotificationService.php`
- `app/Console/Commands/PurgeUserEmail.php`
- `app/Console/Commands/RestoreUser.php`
- `bootstrap/app.php`
- `routes/api.php`
- `config/cors.php`
- `config/session.php`
- `.env.example`
- `deploy/.env.production.example`
- `composer.lock` (targeted package updates)

### Modified frontend
- `client/next.config.ts`
- `client/lib/api/auth.ts`
- `client/components/settings/AccountSettingsSection.tsx`
- `client/package.json` / `client/package-lock.json`

### Test updates
- `tests/Feature/AccountSecurityTest.php`
- `tests/Feature/Mvp2AuditLogTest.php`

---

## 5. Remaining risks and unresolved items

These are explicitly **not** fully verified or not solvable in code alone.

1. **Live Paystack/Hubtel webhook behaviour.** Amount/currency validation and signature handling are covered by automated tests with synthetic payloads. Real provider dashboards, webhook secrets, retry behaviour, partial payments and recurring-billing configuration require production credentials and must be verified in a provider sandbox before launch.
2. **Frontend Content-Security-Policy.** Baseline headers and HSTS are in place, but a strict CSP was not added. Next.js App Router needs a nonce- or hash-based policy; introducing one without end-to-end browser testing risks breaking the UI. Recommended as a follow-up with a report-only rollout.
3. **Distributed idempotency store.** The middleware uses Laravel's configured cache. Production must use a shared cache (Redis/database), not the per-process array driver, or idempotency guarantees are limited to one node.
4. **True device fingerprinting / anti-abuse.** Roicard does not collect device identifiers. Session and rate-limit controls are present, but device-level fraud detection is out of scope and would require a privacy review before implementation.
5. **AI prompt injection.** Prompts now mark source text as untrusted, and outputs are schema-validated, but LLM behaviour cannot be guaranteed. Continue treating AI output as untrusted content.
6. **Public security/privacy disclosure text.** Any public vulnerability-disclosure policy or updated privacy wording still requires founder/legal approval.
7. **Operational secret hygiene.** Repository scans found no committed production secrets in the reviewed files, but deployment secrets, key rotation and log retention must be confirmed in the hosting environment. Logs now exclude direct PII, but infrastructure-level access control is outside this codebase.
8. **Upload handling.** Upload routes continue to rely on existing MIME/size validation and storage configuration. No new upload vulnerability was confirmed; malware scanning and object-storage lifecycle policy remain deployment responsibilities.
9. **XSS review.** The only `dangerouslySetInnerHTML` uses are the static theme bootstrap script and server-generated QR SVG. If QR SVG generation ever accepts user-controlled content, it must be sanitised before rendering.
10. **Legal/compliance review.** GDPR-style export/delete flows were preserved, but retention periods, subprocessors and cross-border transfer documentation need legal confirmation.

No confirmed code-level vulnerability from this review was left unfixed.

---

## 6. Deployment checklist

Before production rollout:

- [ ] Set `APP_ENV=production`, `APP_DEBUG=false`, and a strong `APP_KEY`.
- [ ] Set `SESSION_SECURE_COOKIE=true`, `SESSION_HTTP_ONLY=true`, `SESSION_SAME_SITE=lax`.
- [ ] Set explicit `CORS_ALLOWED_ORIGINS`; never combine `*` with credentials.
- [ ] Use Redis or the database cache driver for idempotency and rate limiting.
- [ ] Confirm Paystack/Hubtel webhook URLs, secrets and plan codes in the provider dashboard.
- [ ] Run `php artisan config:cache`, `route:cache` and `view:cache` after environment changes.
- [ ] Confirm HTTPS termination and that HSTS is served on secure requests.
- [ ] Verify log destination access control and retention.
- [ ] Re-run `npm audit`, `composer audit`, the backend suite, TypeScript and the production build in CI.

---

## 7. Backup and rollback

Pre-change copies are stored in:

```
.security-backup/20261123-132326/
```

Rollback is file-by-file: restore the relevant file from that directory and rebuild the frontend. No database rollback is required because Section 9 made no destructive schema or data changes.
