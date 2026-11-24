# ROICARD privacy and data-protection inventory

This is an internal engineering and DPO-review document, not a public privacy
policy. Public consent wording, lawful-basis language and the policy URL require
founder/legal approval before launch.

## 1. Data collected

### Account and access
- Name, email, password hash, role, status, email-verification state.
- Registration IP (referral anti-fraud only; hidden from API responses).
- Sign-up profile-processing consent timestamp/version.
- Separate AI data-processing consent timestamp/version.
- Plan, subscription and entitlement state.
- Authentication/session tokens and security settings.
- Notification preferences, push-device tokens, and in-app notification
  records (type, payload and read state). Push delivery is skipped until an
  approved provider is configured.

### Profile and discoverability
- Personal/business profile type, display name, headline, role description,
  organisation, location, About/bio, skills, services, website and contact
  options.
- Profile visibility, searchable, contactable, connection-request policy and
  per-field contact visibility.
- Seeking and Offering items (description, active/paused state, categories,
  tags, sort order and source). Catalog-generated Offering signals are derived
  from catalog items.
- Education, experience, achievements, interests and social links.
- CV media (private disk), avatar, logo and cover media.
- AI draft content, approved-field list and proposed catalog items.

### Safety, connections and communities
- Connection requests, notes, match explanations/reason codes, status and
  timestamps.
- Member blocks with reason and timestamps.
- Reports filed with reason category, description, status and moderation state.
  Moderator notes are admin-only.
- Community memberships, roles, affiliation confirmation/acceptance and
  start/end dates.
- Meeting types, hosted bookings and booking guest details supplied by guests.

### Billing, referrals and operations
- Payments (amount, currency, status, purpose, provider reference, method).
- Subscriptions, billing events and plan history.
- Referrals, attribution risk flags, reward-ledger entries and reward status.
- AI usage metadata (feature, provider, model, token counts, outcome, estimated
  cost). Prompt/completion text is not stored or logged.
- Recommendation events, analytics events and admin/security audit actions.
- Onboarding task state and social-login account identifiers.

## 2. Privacy controls

- **Profile visibility:** public, Roicard members, shared communities,
  connections only, or private. The server-side `ProfileVisibilityService` is
  authoritative for every profile read and discovery query.
- **Searchability:** per-profile setting, independent of direct-link visibility.
  Turning it off removes the profile from database-backed search immediately and
  invalidates recommendation caches.
- **Community-specific discoverability:** per-membership setting. It is applied
  to community-scoped search, the Discover community section and community
  recommendations. Leaving, being removed from, or changing a membership
  invalidates recommendation and public-profile caches immediately. It does not
  change profile visibility or a direct profile link.
- **Contactability:** independent of visibility/search. `is_contactable` is the
  master switch; `connection_request_policy` can restrict member requests to
  shared communities or matching Seeking/Offering. Existing profiles with a
  null policy retain legacy behaviour until they save privacy settings.
- **Contact details:** per-field phone/WhatsApp/email/website visibility with a
  profile-wide fallback. Public profile responses are redacted per viewer after
  the shared cache is read.
- **Blocking:** blocks hide both members from search, recommendations, direct
  public profile access, contact details, connection actions and community/
  organization member directory results. Admins retain their review access. The
  blocker's blocked-member list shows names and profile labels only, not email
  addresses.
- **Reporting:** any profile or connection can be reported with a reason
  category. A connection-based report is always attributed to the other party in
  that connection, and mixing a connection with an unrelated profile is
  rejected. Reports enter the admin moderation queue; a suspension decision is
  written to the audit trail separately from the report update.
- **Connection safety:** contactability is the master switch; an explicit "none"
  policy blocks every request entry point. Member requests also honour the
  per-profile request policy, daily/weekly/new-account limits and the declined-
  request cooldown. The public guest form applies contactability, the "none"
  policy, the same declined-request cooldown and a dedicated per-IP rate limit.
- **Search protections:** sign-in required, paginated results, a configured
  per-page cap, a candidate cap, per-member rate limiting, plan search limits
  and no search export. The legacy discovery endpoint uses the same throttle and
  candidate cap. A configurable warning alert can be emitted when a member
  exceeds an hourly search threshold.
- **Notifications:** per-type/channel toggles are stored on the member account.
  In-app and email are the baseline channels; push is delivered only through an
  approved provider adapter. Non-mandatory notifications use the configured
  daily cap, and non-mandatory emails carry a signed unsubscribe link.
  Security/transactional notices remain subject to the member's explicit
  preference choices and are not silently replaced by marketing messages.

## 3. Consent

- Profile-processing consent is required at sign-up and stored with a version
  from `PRIVACY_CONSENT_VERSION`.
- AI consent is separate and recorded when a member first uses AI features,
  with `AI_CONSENT_VERSION` and configurable consent text.
- Consent wording and any public privacy-policy URL must be approved by the
  founder/legal owner. `PRIVACY_POLICY_URL` is currently unset.

## 4. Export, deletion and retention

- Members can download their own data as JSON from
  `GET /api/auth/account/export`. The export excludes other members' private
  contact details, moderation notes, secrets and filesystem paths. It includes
  profile privacy settings and per-community discoverability choices.
- Account deletion requires the current password and revokes all sessions.
  The account is soft-deleted for `APP_DATA_RETENTION_DAYS` so investigations
  can recover it; `users:purge` then force-deletes it.
- Deleting a non-default profile or purging a retained account removes avatar,
  logo, cover, CV and catalog-image rows plus their stored files. Spatie media
  rows are polymorphic, so this cleanup cannot rely on database cascades.
- Purge removes profiles, Seeking/Offering signals, profile media, AI drafts,
  tokens and both directions of connection records. Billing/audit history may be
  retained as required by the applicable retention policy.
- `data:prune` supports configurable retention for recommendation events,
  admin/security audit actions and AI usage logs. All three windows are
  **unset by default** and must be set by the founder/legal owner before launch:
  - `RETENTION_RECOMMENDATION_EVENT_DAYS`
  - `RETENTION_ADMIN_ACTION_LOG_DAYS`
  - `RETENTION_AI_USAGE_LOG_DAYS`

## 5. AI and provider location

- AI provider: Google Gemini, server-side only.
- Model and region are configured by `GEMINI_MODEL` and `GEMINI_REGION`.
- AI requests contain only the text needed for drafting, with email addresses
  and phone numbers redacted. Contact fields from the profile are never sent.
- The provider account must be on a plan that does not train on submitted data.
  Confirm the processing region and update the public privacy policy before
  enabling AI in production.

## 6. Open founder/legal decisions

1. Approve the public privacy policy and consent wording, and set
   `PRIVACY_POLICY_URL`.
2. Approve the profile-processing consent version.
3. Set retention windows for recommendation events, audit logs and AI usage
   logs before launch.
4. Confirm the AI provider region and no-training terms.
5. Confirm the searchable-default decision. The current implementation keeps
   the existing stored setting and exposes it explicitly in the privacy form;
   it does not silently enable searchability during publishing.
6. Produce the formal Ghana Data Protection Commission filing/notice from this
   inventory. This file is not a substitute for legal review.
