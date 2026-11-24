# Section 8 — Privacy, Data Protection and Safety Report

**Project:** Roicard
**Date:** 23 November 2026
**Status:** Completed with documented founder/legal decisions outstanding.

---

## 1. Summary

Section 8 was verified end to end and the remaining gaps were fixed directly. The existing visibility, searchability, contactability, moderation, consent, export and retention mechanisms from Sections 1–7 were reused rather than replaced.

Additional protection was added for:

1. Community-scoped discoverability across every discovery path.
2. Immediate cache/result invalidation after community membership changes.
3. Contact-detail leakage in the blocked-member list.
4. Report target integrity for connection-based reports.
5. Guest connection decline cooldowns and the “no requests” policy.
6. A bypass where a signed-in member could use the public guest form to ignore a profile’s request policy.
7. Rate limiting and candidate caps on the legacy discovery endpoint.
8. Media retention on profile deletion and account purge.
9. Account-deletion cache invalidation for secondary profiles/recommendations.
10. Export completeness for privacy settings and community discoverability.
11. An explicit audit record when moderation suspends a member.

No UI redesign, destructive migration, data wipe, dependency upgrade or unrelated feature was added.

---

## 2. Section 8 requirements

### 2.1 Profile visibility and privacy settings — verified

- Server-side `ProfileVisibilityService` remains authoritative for public, members, shared communities, connections-only and private profiles.
- Private and unpublished profiles are owner/admin only.
- Owner/admin preview does not expose drafts to other members.
- Privacy settings are exposed in the member setup/privacy form and profile management screens.
- Publishing requires an explicit privacy confirmation.

### 2.2 Per-profile searchability — verified

- Searchability is independent of direct-link visibility.
- Turning it off removes the profile from database-backed search immediately.
- Existing profiles keep their stored setting; publishing does not silently enable search.

### 2.3 Contactability independent of visibility — verified and extended

- `is_contactable` is the master contact switch and is separate from visibility and searchability.
- Connection policies (`communities`, `matching`, `none`) continue to restrict member requests.
- Catalog enquiries now respect contactability as well as visibility.
- Fixed bypass: a signed-in member submitting the public guest Connect form is now routed through the member-request service, so request policy, matching-only, block and member-limit checks still apply.

### 2.4 Community-specific discoverability — fixed

Previously the per-membership opt-out was enforced for community-visible profiles but not consistently for community-scoped discovery of public/members-only profiles.

Now:

- Community-filtered search respects `community_members.discoverable`.
- Discover’s `community_ids` query respects it.
- Membership start/end windows and active community status are enforced.
- Leaving, being removed from, joining or changing a membership invalidates recommendation and public-profile caches.

### 2.5 Contact-detail visibility restrictions — verified and tightened

- Per-field phone, WhatsApp, email and website visibility is enforced after the shared public cache is read.
- Contact links are redacted per viewer.
- The blocked-member list no longer returns or displays the blocked person’s email address.
- Public profile, CV, catalog, meeting, QR and organization directory paths continue to pass through the visibility service.

### 2.6 Blocking across profiles, search and interactions — verified

Verified coverage:

- Direct public profile access.
- Search and recommendations.
- Member/guest connection requests and connection lists.
- Contact-detail redaction.
- Catalog enquiries.
- Public meeting types and booking.
- CV downloads.
- QR scan entry.
- Organization public member directory.

Blocking pending requests remains; accepted connections are retained but hidden from lists and contact details.

### 2.7 Report submission and moderation queue — fixed and verified

- Members can report a profile or a connection with a reason category.
- Reports enter the admin-only moderation queue.
- Reporters cannot read moderation details.
- Fixed connection report integrity: a connection-based report is now attributed to the other party in that connection; supplying a connection together with an unrelated profile is rejected.
- Confirmed reports/actions remain admin-only.

### 2.8 Connection request safety restrictions — fixed and verified

- Contactability, block checks and “not your own profile” checks remain.
- Community-only, matching-only and no-request policies are enforced for member requests.
- Daily, weekly, new-account and declined-request cooldown limits remain configurable.
- Public guest requests now apply:
  - contactability,
  - the `none` policy,
  - the same declined-request cooldown,
  - a dedicated per-IP throttle.
- Public guest requests cannot be used by a signed-in member to bypass the member policy.

### 2.9 Declined-request re-request restrictions — fixed

- Member requests already used `CONNECTION_DECLINED_COOLDOWN_DAYS` (default 30).
- The public guest form now applies the same configured cooldown by linked account or normalized email.
- No decline notification is sent.

### 2.10 Rate limits and new-account restrictions — verified and extended

- Search, report, block, account export, AI, catalog enquiry, connection request and auth limits remain.
- The legacy `/profiles/discover` and `/discover` endpoints now use the search throttle.
- Legacy discovery now respects `SEARCH_MAX_CANDIDATES`.
- Guest connection requests use `throttle:connection-request`.

### 2.11 Suspension and community-removal behaviour — verified and extended

- Admin suspension deactivates sign-in, hides profiles, revokes tokens and retains membership history.
- Self-deactivation remains reversible only when not admin-suspended.
- Community removal/leave is audited for admin actions and now invalidates discovery/public-profile caches immediately.
- Moderation suspension now also writes a distinct `moderation.member_suspended` audit entry.

### 2.12 Consent requirements — verified

- Profile-processing consent is required at registration and stored with a version.
- AI consent is separate and required before provider calls.
- Publishing requires explicit privacy confirmation.
- Public policy wording and `PRIVACY_POLICY_URL` remain founder/legal decisions.

### 2.13 Member data export and deletion — improved

- Export continues to exclude other members’ private contact details, moderation notes, secrets and filesystem paths.
- Export now includes profile privacy fields (`is_default`, `privacy_confirmed_at`) and per-community `discoverable`.
- Account deletion still requires the current password, revokes sessions and soft-deletes for the retention window.
- Deleting a non-default profile now removes its media rows and stored files.
- Account purge now removes profile and catalog media before the cascade deletes profiles.

### 2.14 Data retention — verified

- `APP_DATA_RETENTION_DAYS` and `users:purge` remain the account-retention path.
- `data:prune` still supports optional recommendation-event, admin-action and AI-usage windows.
- All optional windows remain unset by default; nothing is deleted until a founder-approved value is configured.
- Billing/audit retention remains subject to the applicable legal/accounting policy.

### 2.15 Privacy documentation — updated

`docs/privacy-data-protection.md` now documents:

- community-scoped discoverability and membership cache invalidation,
- blocked-list email removal,
- report target integrity and suspension auditing,
- guest connection safety,
- legacy discovery limits,
- media cleanup on deletion/purge,
- privacy fields in the export.

### 2.16 Search-index removal after privacy changes — verified and extended

- Profile visibility/searchability changes bust the public-profile cache and increment the matchmaking version.
- Community membership changes now do the same.
- Account deletion busts all profiles, including secondary profiles, and invalidates recommendations.
- Search itself queries the live database, so privacy changes take effect immediately.

### 2.17 Signed-in search, pagination, limits and scraping — verified

- Search requires authentication.
- Results are paginated with a configured per-page cap.
- Candidate totals are capped by `SEARCH_MAX_CANDIDATES`.
- Per-member search throttling and plan search usage limits remain.
- Abuse alerts are configurable and audited.
- No search export exists.
- The legacy discovery endpoint now has the same throttle and candidate cap.

---

## 3. Files changed

### New

- `app/Observers/CommunityMemberObserver.php`
- `tests/Feature/Section8PrivacySafetyTest.php`
- `docs/SECTION-8-PRIVACY-DATA-PROTECTION-REPORT.md`

### Backend modified

- `app/Services/SearchService.php`
- `app/Http/Controllers/Api/DiscoverController.php`
- `app/Http/Controllers/Api/ProfileDiscoveryController.php`
- `app/Http/Controllers/Api/ReportController.php`
- `app/Http/Controllers/Api/ConnectionController.php`
- `app/Http/Controllers/Api/BlockController.php`
- `app/Http/Controllers/Api/AdminModerationController.php`
- `app/Services/AccountDataExportService.php`
- `app/Services/ProfileManager.php`
- `app/Models/Profile.php`
- `app/Console/Commands/PurgeDeletedUsers.php`
- `app/Observers/UserObserver.php`
- `app/Providers/EventServiceProvider.php`
- `routes/api.php`

### Frontend modified

- `client/lib/api/safety.ts`
- `client/components/settings/BlockedMembersSection.tsx`

### Documentation

- `docs/privacy-data-protection.md`

### Backups

Pre-change copies are stored in:

```
.privacy-section8-backup/20261123-162004/
```

---

## 4. Existing functionality preserved

- Sections 1–7 flows, routes, UI and data model are intact.
- Public profiles, guest Connect, member Connect, search ranking, Discover, organizations, catalogues, meetings, CVs and billing were not redesigned.
- Section 9 authentication, 2FA, idempotency and webhook hardening remain intact.
- No database was seeded, wiped or destructively migrated.
- Account deletion/export semantics and retention behaviour were preserved.
- The only UI change is the blocked-member list fallback text; it no longer depends on an email address.

---

## 5. Verification

### Focused Section 8 suite

```
php artisan test tests/Feature/Section8PrivacySafetyTest.php
```

Result:

```
Tests: 12 passed (54 assertions)
```

Covers community-scoped discoverability, membership cache invalidation, guest cooldown/policy, member guest-endpoint bypass, connection-report integrity, blocked-list privacy, profile media deletion, purge media deletion, export completeness, account-deletion cache invalidation, legacy discovery caps and suspension auditing.

### Related regression tests

The focused run was followed by the full suite, which includes visibility, search, blocking, reporting, connections, communities, organizations, catalogues, publishing, retention, account security and Section 9 tests.

```
php artisan test
```

Result:

```
Tests: 477 passed (2266 assertions)
Duration: 163.54s
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

## 6. Remaining founder/legal decisions and blockers

1. **Public privacy policy and consent wording.** `PRIVACY_POLICY_URL` remains unset; the public policy, lawful-basis wording and Ghana Data Protection Commission notice require founder/legal approval.
2. **Retention windows.** `RETENTION_RECOMMENDATION_EVENT_DAYS`, `RETENTION_ADMIN_ACTION_LOG_DAYS` and `RETENTION_AI_USAGE_LOG_DAYS` remain unset by design. Records are retained until approved values are configured.
3. **Guest Connect and community/matching policies.** Guest requests currently honour contactability and `none`; they cannot prove shared-community membership or a Seeking/Offering match. Founder should confirm whether guests should be blocked entirely for profiles using `communities`/`matching`, or keep the current contactability fallback.
4. **Searchable default.** New profiles are searchable by default and the setting is explicit in the privacy form. The founder should confirm the default.
5. **AI provider terms/region.** Google Gemini’s no-training terms and processing region still need production confirmation.
6. **Billing/audit retention.** Accounting or statutory retention requirements for payment and audit records need legal confirmation.
7. **Device-level anti-abuse.** The product does not collect device identifiers; rate limiting and account controls are the available safeguards.

No confirmed code-level Section 8 requirement remains unimplemented.
