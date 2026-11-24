# ROICARD controlled-pilot checklist

Use this checklist for a small, invited pilot. Do not name or create pilot
organizations/communities until the founder supplies the approved list.

## Before invitations

- [ ] Production `.env` is complete: `APP_URL`, `FRONTEND_URL`, database,
      mail, `CORS_ALLOWED_ORIGINS`, `PAYMENT_PROVIDER` and provider secrets.
- [ ] `SESSION_SECURE_COOKIE=true` and HTTPS are enforced at the proxy.
- [ ] `php artisan migrate --force` completed after a verified backup.
- [ ] `php artisan db:seed --class=PlanSeeder --force` completed.
- [ ] Founder-approved plan prices, billing intervals, entitlements and
      verification flags entered in `/admin/plans`.
- [ ] Referral settings intentionally enabled or left disabled.
- [ ] Provider webhooks are registered and a test event has been replayed.
- [ ] Admin accounts use strong unique passwords and 2FA where available.
- [ ] `storage:link` exists for public media.
- [ ] Backup schedule and restore drill are confirmed.
- [ ] `billing:process`, `recommendations:refresh`, draft reminders and meeting
      reminders are scheduled.

## Administrator pre-flight

- [ ] Open `/admin/metrics` and confirm event totals start from the pilot date,
      not invented historical values.
- [ ] Review `/admin/plans`, `/admin/billing`, `/admin/referrals` and
      `/admin/organizations`.
- [ ] Approve only the founder-approved pilot organizations.
- [ ] Add/invite the approved organization administrators.
- [ ] Confirm support handling for blocks, reports, moderation queue, billing
      questions and card replacement.
- [ ] Confirm the escalation contact and response window.

## Member invite and onboarding

- [ ] Send invitations to the approved pilot list only.
- [ ] Member verifies email and signs in.
- [ ] Member completes profile, chooses visibility and searchability.
- [ ] Member publishes at least one profile.
- [ ] Member adds Seeking/Offering items.
- [ ] Business member adds catalog items and tests an enquiry.
- [ ] Member connects to another pilot member; recipient accepts.
- [ ] Member checks Discover, Search and the organization directory.
- [ ] Member reviews plan/usage and any limit messages.
- [ ] If used, member activates a physical card only after server-verified
      payment.
- [ ] If enabled, member tests referral attribution and the reward ledger.
- [ ] Member previews/downloads a signature card and verifies the QR/profile
      link.

## Safety and support checks

- [ ] Blocking hides both members from search/discovery/connections.
- [ ] A formal report reaches `/admin/moderation`.
- [ ] A private profile is not visible to non-owners.
- [ ] Hidden CV/contact details are absent from public responses.
- [ ] Owner/admin can download a CV through the authorized endpoint; a guest gets 403.
- [ ] Guest Connect still creates a pending request.
- [ ] Catalog enquiries reach the business owner.
- [ ] Organization membership requires confirmation and member acceptance.
- [ ] Subscription cancellation remains active until period end.
- [ ] No payment/card/subscription activates without a verified server event.

## Pilot review

- [ ] Review `/admin/metrics` for profile views/sources, searches, connections,
      catalogue enquiries, signature cards, referral activity and billing.
- [ ] Review error logs and queue failures.
- [ ] Collect member feedback and record support incidents.
- [ ] Decide whether to continue, pause or roll back the pilot.
