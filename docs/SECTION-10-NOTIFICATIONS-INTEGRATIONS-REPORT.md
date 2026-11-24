# Appendix C & D — Notifications and Required Integrations Report

**Project:** Roicard
**Date:** 23 November 2026
**Status:** Completed with documented provider/configuration blockers.

---

## 1. Summary

Appendix C notifications and the missing provider-independent Appendix D integration pieces were implemented directly. The work reuses the existing Laravel notification, queue, audit, analytics, billing, referral, AI and media architecture.

No notification type outside the Appendix C list was added for its own sake. Existing meeting, organisation, smart-card and auth notifications were preserved.

Pre-change backups are in:

```
.notifications-backup/20261123-200808/
```

---

## 2. Appendix C — notifications completed

### 2.1 Notification catalogue and preferences

- Added a canonical catalogue in `App\Support\NotificationType` for:
  - connection request received,
  - connection accepted,
  - catalog enquiry received,
  - weekly recommendations,
  - referral signup,
  - referral qualification,
  - reward credited,
  - reward paid,
  - subscription about to lapse,
  - subscription lapsed,
  - unpublished-profile reminder,
  - report outcome,
  - admin scraping alert,
  - admin referral reward-cap alert,
  - admin AI budget alert.
- Members can read and update per-type/per-channel preferences at
  `GET/PATCH /api/notifications/preferences`.
- Settings UI now has a **Notifications** tab with in-app, email and push toggles.
- Missing preferences fall back to catalogue defaults, so existing members need no migration data.
- Non-mandatory email notifications include a signed one-click unsubscribe URL and `List-Unsubscribe` header. The signed endpoint is `GET /api/notifications/unsubscribe/{user}/{type}/{channel}`.
- `NOTIFICATION_DAILY_CAP` is supported for non-mandatory notifications. It is **blank by default** until a founder-approved value exists.

### 2.2 Channel delivery

| Channel | Behaviour |
|---|---|
| In-app | Laravel database notification row; existing bell/read flow. |
| Email | Queued notification mail (array/log/SMTP/Resend based on deployment). |
| Push | Provider-independent adapter. Device tokens are registered at `POST /api/notifications/push-tokens`; delivery is skipped until `PUSH_DRIVER=webhook`, `PUSH_WEBHOOK_URL` and `PUSH_WEBHOOK_TOKEN` are configured. |
| WhatsApp | Provider-independent adapter used only by the draft onboarding reminder command. Nothing is sent until the approved Business API endpoint/token/template are configured. |

### 2.3 Required events

- **Connection request received / accepted:** existing notifications now use queued delivery, preferences and the daily cap.
- **Catalog enquiry received:** existing notification now uses queued delivery and preferences.
- **Weekly recommendations:** `notifications:weekly-recommendations` computes a digest through the existing matchmaking service and sends one notification per profile/week. Scheduled weekly.
- **Referral signup:** sent to the referrer after first attribution.
- **Referral qualification:** sent when the configured trigger qualifies.
- **Reward credited:** sent when an available reward entry is created.
- **Reward paid:** sent when the payout ledger is marked paid.
- **Subscription about to lapse:** `notifications:subscription-lapse-reminders` uses `SUBSCRIPTION_LAPSE_WARNING_DAYS` (blank disables it). Scheduled daily.
- **Subscription lapsed:** sent when billing expires a subscription and returns the member to Free limits.
- **Unpublished profile reminder:** `notifications:unpublished-profile-reminders` uses `UNPUBLISHED_PROFILE_REMINDER_DAYS` (blank disables it). Scheduled daily.
- **Report outcome:** notifies the reporter when moderation moves a report to reviewed/actioned/dismissed. Admin notes are never included.
- **Admin scraping alert:** sent once per alert window when search-volume threshold is crossed.
- **Admin referral cap alert:** sent for plan cap, monthly cap, total-budget cap and configured budget-threshold alerts.
- **Admin AI budget alert:** sent once per month when estimated AI cost reaches `AI_MONTHLY_COST_ALERT_USD`.

### 2.4 Delivery reliability

- New and required notifications are queued (`ShouldQueue`).
- Email verification OTP is now queued as well.
- Push and WhatsApp failures are logged and never roll back the business action.
- Daily-cap counters use the configured cache store; production must use a shared cache for cluster-wide enforcement.

---

## 3. Appendix D — integrations completed/verified

### 3.1 Email and OTP

- Existing 6-digit email OTP remains 30 minutes/one-time and throttled.
- `VerifyEmail` is now queued, improving delivery decoupling from registration/account requests.
- Phone OTP is **not enabled**: the product has no verified authentication-phone identity and no approved SMS/WhatsApp OTP provider/template. This is a provider/policy blocker, not a code workaround.

### 3.2 Payments and webhooks

- Existing Paystack/Hubtel initiation, signature verification, replay protection and amount/currency checks remain unchanged and were regression-tested.
- No provider was selected or credential invented.

### 3.3 Payouts

- Existing referral payout gate (`REFERRAL_PAYOUT_ENABLED`) and append-only ledger remain.
- Reward-credited and reward-paid notifications are wired.
- Live payout provider remains founder/credential-dependent.

### 3.4 Gemini AI

- Existing server-only Gemini configuration, consent, redaction, quota and privacy behaviour remain.
- AI budget alert now writes an admin notification in addition to the existing log.
- Deployment still needs an approved key, region and no-training terms.

### 3.5 Search

- Existing signed-in restriction, filters, pagination, per-page cap, candidate cap, rate limiting and abuse alert remain.
- Added typo-tolerant fallback: when strict LIKE matching returns no candidates, a privacy-scoped candidate set is compared with a small edit-distance threshold.
- The fallback still applies visibility, searchability, block and community-discoverability scopes and the same candidate cap.

### 3.6 Images, storage and CDN

- Existing server-side signature-card PNG generation and hash/time cache were verified.
- Existing Spatie media storage for avatars, logos, covers, CVs and catalog images remains; deletion/purge cleanup from Section 8 remains in place.
- No new storage provider or CDN was selected.

### 3.7 Metrics, event tracking and alerts

- Existing analytics event tracking and `/api/admin/metrics` remain.
- Added admin notifications for the three required alert categories.
- Error reporting remains application logging/`report()`; no external error-reporting provider was selected.

---

## 4. Files changed

### New backend

- `database/migrations/2026_11_24_000001_add_notification_preferences_and_push_tokens.php`
- `app/Support/NotificationType.php`
- `app/Models/PushToken.php`
- `app/Services/Notifications/NotificationPreferenceService.php`
- `app/Services/Notifications/PushDeliveryService.php`
- `app/Services/Notifications/AdminAlertService.php`
- `app/Services/Notifications/WhatsAppDeliveryService.php`
- `app/Notifications/Channels/PushChannel.php`
- `app/Notifications/Concerns/RespectsNotificationPreferences.php`
- `app/Notifications/MemberNotification.php`
- `app/Notifications/WeeklyRecommendationsNotification.php`
- `app/Notifications/ReferralSignupNotification.php`
- `app/Notifications/ReferralQualifiedNotification.php`
- `app/Notifications/ReferralRewardCreditedNotification.php`
- `app/Notifications/ReferralRewardPaidNotification.php`
- `app/Notifications/SubscriptionLapseWarningNotification.php`
- `app/Notifications/SubscriptionLapsedNotification.php`
- `app/Notifications/UnpublishedProfileReminderNotification.php`
- `app/Notifications/ReportOutcomeNotification.php`
- `app/Notifications/AdminAlertNotification.php`
- `app/Http/Controllers/Api/NotificationPreferenceController.php`
- `app/Http/Controllers/Api/PushTokenController.php`
- `app/Console/Commands/SendWeeklyRecommendations.php`
- `app/Console/Commands/SendUnpublishedProfileReminders.php`
- `app/Console/Commands/SendSubscriptionLapseReminders.php`
- `tests/Feature/AppendixCNotificationsTest.php`

### Backend modified

- `app/Models/User.php`
- `app/Notifications/ConnectionRequestNotification.php`
- `app/Notifications/MemberConnectionRequestNotification.php`
- `app/Notifications/ConnectionApprovedNotification.php`
- `app/Notifications/MemberConnectionAcceptedNotification.php`
- `app/Notifications/CatalogEnquiryReceivedNotification.php`
- `app/Notifications/VerifyEmail.php`
- `app/Services/ReferralService.php`
- `app/Services/BillingService.php`
- `app/Services/Ai/AiUsageService.php`
- `app/Services/SearchService.php`
- `app/Http/Controllers/Api/SearchController.php`
- `app/Http/Controllers/Api/AdminModerationController.php`
- `app/Console/Commands/SendDraftExpirationReminders.php`
- `app/Providers/AppServiceProvider.php`
- `routes/api.php`
- `routes/console.php`
- `config/roicard.php`
- `config/services.php`
- `.env.example`
- `deploy/.env.production.example`

### Frontend

- `client/lib/api/notifications.ts`
- `client/lib/settings/types.ts`
- `client/components/settings/NotificationPreferencesSection.tsx` (new)
- `client/components/settings/SettingsTabs.tsx`
- `client/components/settings/SettingsView.tsx`

### Documentation

- `docs/privacy-data-protection.md`
- `docs/SECTION-10-NOTIFICATIONS-INTEGRATIONS-REPORT.md`

---

## 5. Existing functionality preserved

- Sections 1–9 flows, privacy controls, security hardening, billing, referrals, AI, meetings, catalogues, organisations, search and account management remain.
- Existing meeting, guest, organisation, smart-card, verification and admin-message notifications were not removed or renamed.
- No database was wiped, seeded or destructively migrated.
- Notification preference defaults are additive; existing members keep receiving current notifications.
- The new migration only adds a nullable preferences column and a push-token table.

---

## 6. Verification

### Focused Appendix C suite

```
php artisan test tests/Feature/AppendixCNotificationsTest.php
```

Result:

```
Tests: 15 passed (45 assertions)
```

Coverage: preferences/toggles, daily cap, signed unsubscribe, push tokens/adapter, WhatsApp gating, referral lifecycle notifications, subscription warning/lapse, unpublished reminder, report outcome privacy, scraping/referral-cap/AI admin alerts, weekly digest and typo-tolerant search.

### Related regression tests

The focused run was followed by the full suite, including billing, referrals, connections, moderation, search, catalogues, meetings, AI, guest notifications, privacy and Section 9 security.

```
php artisan test
```

Result:

```
Tests: 492 passed (2311 assertions)
Duration: 146.41s
```

### Frontend

```
cd client
npx tsc --noEmit
```

No TypeScript errors.

```
npm run build
```

Production build completed successfully.

---

## 7. Providers, credentials and external services still required

1. **Push provider** — configure `PUSH_DRIVER=webhook`, `PUSH_WEBHOOK_URL`, `PUSH_WEBHOOK_TOKEN`; a client service worker/provider registration also needs approval. Until then push is skipped.
2. **WhatsApp Business** — configure `WHATSAPP_API_URL`, `WHATSAPP_API_TOKEN`, `WHATSAPP_ONBOARDING_TEMPLATE` and obtain message-template approval.
3. **SMS/phone OTP** — no approved provider or authentication-phone policy exists.
4. **Email deliverability** — deployment must configure the approved mailer (Resend/SMTP) and verify sender/domain.
5. **Notification daily cap** — `NOTIFICATION_DAILY_CAP` is intentionally blank.
6. **Unpublished-profile delay** — `UNPUBLISHED_PROFILE_REMINDER_DAYS` is intentionally blank.
7. **Subscription warning window** — `SUBSCRIPTION_LAPSE_WARNING_DAYS` is intentionally blank.
8. **Payouts** — a live payout provider and `REFERRAL_PAYOUT_ENABLED` approval are still required.
9. **Payment provider** — production Paystack/Hubtel credentials and webhook configuration remain deployment work.
10. **Gemini** — approved API key, model, region and no-training terms remain.
11. **Error reporting/CDN** — no external error-reporting or CDN provider was selected in this task.
12. **Shared cache/queue** — production must run a queue worker and a shared cache store for reliable queued notifications and cluster-wide caps.

These are provider/account/approval blockers; the provider-independent code paths are in place.

---

## 8. Remaining specification items

- Phone OTP and any SMS fallback cannot be completed without an approved provider and phone-identity policy.
- Live push and WhatsApp delivery cannot be exercised without provider credentials and approved templates.
- Live payment/payout webhook behaviour still requires provider sandbox credentials.
- Founder-approved values are still required for the daily cap, reminder delays, retention windows and AI cost alert/pricing.
