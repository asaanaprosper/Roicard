import apiClient from "./client";
import type { AdminDateRange } from "@/lib/admin/types";

export interface AdminStats {
  total_users: number;
  active_users: number;
  draft_users: number;
  total_payments: number;
  successful_payments: number;
  total_revenue: number;
  total_smart_cards: number;
  cards_shipped: number;
  cards_delivered: number;
  total_connections: number;
  total_analytics_events: number;
  recent_views_7d: number;
}

export interface AdminUserProfile {
  id?: number;
  user_id?: number;
  slug?: string | null;
  title?: string | null;
  organisation?: string | null;
  avatar_url?: string | null;
  role_description?: string | null;
  bio?: string | null;
  location?: string | null;
  phone?: string | null;
  whatsapp_phone?: string | null;
  date_of_birth?: string | null;
  gender?: string | null;
  seeking?: string | null;
  offering?: string | null;
  interests?: string[] | null;
  social_links?: AdminSocialLink[] | null;
  education?: AdminEducationEntry[] | null;
  experience?: AdminExperienceEntry[] | null;
  achievements?: AdminAchievementEntry[] | null;
  completion_pct?: number;
  is_live?: boolean;
}

export interface AdminSocialLink {
  platform: string;
  value: string;
}

export interface AdminEducationEntry {
  id?: number;
  institution: string;
  degree: string;
  start_year: number | string;
  end_year?: number | string | null;
  honours?: string | null;
}

export interface AdminExperienceEntry {
  id?: number;
  title: string;
  company: string;
  start_date: string;
  end_date?: string | null;
  location?: string | null;
}

export interface AdminAchievementEntry {
  id?: number;
  title: string;
  issuer?: string | null;
  date?: string | null;
}

export interface AdminPayment {
  id: number;
  amount: number;
  currency: string;
  method: string | null;
  momo_number: string | null;
  status: string;
  provider: string;
  provider_reference: string;
  created_at: string | null;
}

export interface AdminUser {
  id: number;
  first_name: string;
  last_name: string;
  email: string;
  status: string;
  role: string;
  campaign_code?: string | null;
  plan_id?: number | null;
  plan?: { id: number; key: string; name: string; is_active?: boolean } | null;
  profile?: AdminUserProfile | null;
  email_verified_at?: string | null;
  onboarding_completed?: boolean;
  onboarding_completed_at?: string | null;
  is_deactivated?: boolean;
  deactivated_at?: string | null;
  next_step?: string;
  profile_completion_pct?: number;
  created_at: string;
  payments?: AdminPayment[];
  payment_summary?: {
    count: number;
    successful: number;
    total_paid: number;
    has_paid: boolean;
  };
  smart_card?: AdminSmartCard | null;
}

export interface AdminUserListResponse {
  data: AdminUser[];
  current_page?: number;
  last_page?: number;
  per_page?: number;
  total?: number;
}

export interface AdminSmartCard {
  id: number;
  card_id: string;
  user_id: number | null;
  status: string;
  inventory_status: string;
  assigned_at: string | null;
  delivery_name: string | null;
  dispatched_at: string | null;
  delivered_at: string | null;
  created_at: string;
  user?: {
    id: number;
    first_name: string;
    last_name: string;
    email: string;
  } | null;
  public_profile_url?: string | null;
  public_profile_qr_url?: string | null;
}

export interface AdminConnection {
  id: number;
  member_id: number;
  guest_name: string | null;
  guest_email: string | null;
  status: string;
  created_at: string;
  member?: {
    id: number;
    first_name: string;
    last_name: string;
    email: string;
  } | null;
}

export interface AdminActivityLogEntry {
  id: number;
  action: string;
  created_at: string;
  admin?: {
    id: number;
    first_name: string;
    last_name: string;
  } | null;
  target_user?: {
    id: number;
    first_name: string;
    last_name: string;
  } | null;
}

export interface AdminTrends {
  usersGrowth: { label: string; value: number }[];
  connectionsGrowth: { label: string; value: number }[];
  nfcUsage: { label: string; value: number }[];
  profileViewTrends: { label: string; value: number }[];
}

export interface PaginatedResponse<T> {
  data: T[];
  current_page?: number;
  last_page?: number;
  per_page?: number;
  total?: number;
}

export async function getAdminStats(): Promise<AdminStats> {
  const { data } = await apiClient.get("/admin/stats");
  return data;
}

export async function getAdminUsers(params?: {
  page?: number;
  per_page?: number;
  filter?: Record<string, string>;
  sort?: string;
}): Promise<AdminUserListResponse> {
  const { data } = await apiClient.get("/admin/users", { params });
  return data;
}

export interface AdminUserUpsertPayload {
  first_name?: string;
  last_name?: string;
  email?: string;
  username?: string | null;
  password?: string;
  password_confirmation?: string;
  status?: "draft" | "active";
  role?: "member" | "admin";
  campaign_code?: string | null;
  plan_id?: number | null;
  onboarding_completed?: boolean;
  email_verified?: boolean;
  account_access?: "active" | "suspended";
  send_welcome_email?: boolean;
}

export async function getAdminUser(
  id: number
): Promise<{ data: AdminUser }> {
  const { data } = await apiClient.get(`/admin/users/${id}`);
  return data;
}

export async function createAdminUser(
  body: AdminUserUpsertPayload & {
    first_name: string;
    last_name: string;
    email: string;
    password: string;
    password_confirmation: string;
  }
): Promise<{ user: AdminUser; message: string }> {
  const { data } = await apiClient.post("/admin/users", body);
  return data;
}

export async function updateAdminUser(
  id: number,
  body: AdminUserUpsertPayload
): Promise<{ user: AdminUser; message: string }> {
  const { data } = await apiClient.patch(`/admin/users/${id}`, body);
  return data;
}

export async function uploadAdminUserAvatar(
  id: number,
  file: File
): Promise<{ url: string | null }> {
  const form = new FormData();
  form.append("avatar", file);
  const { data } = await apiClient.post(`/admin/users/${id}/avatar`, form, {
    headers: { "Content-Type": "multipart/form-data" },
  });
  return data;
}

export async function deleteAdminUserAvatar(
  id: number
): Promise<{ url: null; message: string }> {
  const { data } = await apiClient.delete(`/admin/users/${id}/avatar`);
  return data;
}

export interface AdminUserProfilePayload {
  first_name?: string;
  last_name?: string;
  title?: string;
  organisation?: string;
  role_description?: string;
  bio?: string;
  location?: string;
  phone?: string;
  whatsapp_phone?: string;
  date_of_birth?: string | null;
  gender?: string | null;
  seeking?: string;
  offering?: string;
  interests?: string;
  social_links?: string;
  education?: AdminEducationEntry[];
  experience?: AdminExperienceEntry[];
  achievements?: AdminAchievementEntry[];
  send_welcome_email?: boolean;
  mark_onboarding_complete?: boolean;
}

export async function updateAdminUserProfile(
  id: number,
  body: AdminUserProfilePayload
): Promise<{ user: AdminUser; profile: unknown; message: string }> {
  const { data } = await apiClient.patch(`/admin/users/${id}/profile`, body);
  return data;
}

export async function deleteAdminUser(id: number): Promise<{ message: string }> {
  const { data } = await apiClient.delete(`/admin/users/${id}`);
  return data;
}

export async function sendAdminUserPasswordReset(
  id: number
): Promise<{ message: string }> {
  const { data } = await apiClient.post(`/admin/users/${id}/password-reset`);
  return data;
}

export async function getAdminSmartCards(params?: {
  page?: number;
  per_page?: number;
}): Promise<PaginatedResponse<AdminSmartCard>> {
  const { data } = await apiClient.get("/admin/smart-cards", { params });
  return data;
}

export async function registerSmartCard(body: {
  card_id?: string;
}): Promise<{ smart_card: AdminSmartCard; message: string }> {
  const { data } = await apiClient.post("/admin/smart-cards", body);
  return data;
}

export async function assignSmartCard(id: number, userId: number): Promise<{ smart_card: AdminSmartCard; message: string }> {
  const { data } = await apiClient.patch(`/admin/smart-cards/${id}/assign`, { user_id: userId });
  return data;
}

export async function unassignSmartCard(id: number): Promise<{ smart_card: AdminSmartCard; message: string }> {
  const { data } = await apiClient.patch(`/admin/smart-cards/${id}/unassign`);
  return data;
}

export async function activateSmartCard(id: number): Promise<{ smart_card: AdminSmartCard; message: string }> {
  const { data } = await apiClient.patch(`/admin/smart-cards/${id}/activate`);
  return data;
}

export async function deactivateSmartCard(id: number): Promise<{ smart_card: AdminSmartCard; message: string }> {
  const { data } = await apiClient.patch(`/admin/smart-cards/${id}/deactivate`);
  return data;
}

export async function dispatchSmartCard(id: number): Promise<{ message: string }> {
  const { data } = await apiClient.patch(`/admin/smart-cards/${id}/dispatch`);
  return data;
}

export async function deliverSmartCard(id: number): Promise<{ message: string }> {
  const { data } = await apiClient.patch(`/admin/smart-cards/${id}/deliver`);
  return data;
}

export async function getAdminConnections(params?: {
  page?: number;
  per_page?: number;
}): Promise<PaginatedResponse<AdminConnection>> {
  const { data } = await apiClient.get("/admin/connections", { params });
  return data;
}

export async function getAdminActivityLog(): Promise<AdminActivityLogEntry[]> {
  const { data } = await apiClient.get("/admin/activity-log");
  return data;
}

export async function getAdminTrends(
  period: AdminDateRange = "30d"
): Promise<AdminTrends> {
  const { data } = await apiClient.get("/admin/stats/trends", {
    params: { period },
  });
  return data;
}

export interface AdminDiscountCampaign {
  id: number;
  code: string;
  name: string;
  amount: number;
  starts_at: string | null;
  expires_at: string | null;
  is_active: boolean;
  users_count?: number;
  created_at?: string;
}

export interface DiscountCampaignPayload {
  code: string;
  name: string;
  amount: number;
  starts_at?: string | null;
  expires_at?: string | null;
  is_active?: boolean;
}

export async function getAdminDiscountCampaigns(): Promise<{
  campaigns: AdminDiscountCampaign[];
}> {
  const { data } = await apiClient.get("/admin/discount-campaigns");
  return data;
}

export async function createAdminDiscountCampaign(
  body: DiscountCampaignPayload
): Promise<{ campaign: AdminDiscountCampaign; message: string }> {
  const { data } = await apiClient.post("/admin/discount-campaigns", body);
  return data;
}

export async function updateAdminDiscountCampaign(
  id: number,
  body: Partial<DiscountCampaignPayload>
): Promise<{ campaign: AdminDiscountCampaign; message: string }> {
  const { data } = await apiClient.patch(`/admin/discount-campaigns/${id}`, body);
  return data;
}

/* -------------------------------------------------------------------------- */
/* Manual member messages                                                      */
/* -------------------------------------------------------------------------- */

export type AdminMessageCategory = "service" | "nudge";

export type AdminMessageAudienceFilters = {
  status?: "draft" | "active";
  onboarding_completed?: boolean;
  email_verified?: boolean;
  has_campaign_code?: boolean;
  campaign_code?: string;
  created_from?: string;
  created_to?: string;
  search?: string;
};

export interface AdminMessageRecipientPreview {
  id: number;
  first_name: string;
  last_name: string;
  name: string;
  email: string;
  status: string;
  role: string;
  campaign_code: string | null;
  onboarding_completed: boolean;
  email_verified: boolean;
  created_at: string | null;
  draft_closes_at: string | null;
  draft_days_remaining: number | null;
}

export interface AdminMessageQuota {
  daily_limit: number;
  monthly_limit: number;
  committed_today: number;
  committed_month: number;
  daily_remaining: number;
  monthly_remaining: number;
  tracked_only: boolean;
}

export interface AdminMessage {
  id: number;
  sent_by: number | null;
  sent_by_name: string | null;
  subject: string;
  body: string | null;
  category: AdminMessageCategory;
  filter_snapshot: Record<string, unknown> | null;
  recipient_count: number;
  skipped_count: number;
  queued_count: number;
  sent_count: number;
  failed_count: number;
  queued_recipients_count: number;
  sent_recipients_count: number;
  failed_recipients_count: number;
  status: "queued" | "sending" | "sent" | "partial" | "failed";
  is_stuck: boolean;
  started_at: string | null;
  completed_at: string | null;
  created_at: string | null;
}

export interface AdminMessageRecipient {
  id: number;
  user_id: number | null;
  name: string | null;
  email: string;
  status: "queued" | "sending" | "sent" | "failed";
  error: string | null;
  attempts: number;
  sent_at: string | null;
}

export interface AdminMessagePreview {
  subject: string;
  text: string;
  body_html: string;
  html: string;
  recipient: { id: number; name: string; email: string } | null;
}

export interface AdminMessageAudiencePayload {
  filters?: AdminMessageAudienceFilters;
  include_user_ids?: number[];
  exclude_user_ids?: number[];
  include_admins?: boolean;
}

export async function getAdminMessageRecipients(
  params: AdminMessageAudienceFilters & { page?: number; per_page?: number; include_admins?: boolean }
): Promise<{
  count: number;
  total: number;
  data: AdminMessageRecipientPreview[];
  current_page: number;
  last_page: number;
  per_page: number;
}> {
  const { data } = await apiClient.get("/admin/messages/recipients", { params });
  return data;
}

export async function getAdminMessageQuota(): Promise<AdminMessageQuota> {
  const { data } = await apiClient.get("/admin/messages/quota");
  return data;
}

export async function checkAdminMessageDuplicates(
  subject: string,
  audience: AdminMessageAudiencePayload
): Promise<{ count: number; window_days: number; recipients: AdminMessageRecipientPreview[] }> {
  const { data } = await apiClient.get("/admin/messages/duplicate-check", {
    params: {
      subject,
      ...audience.filters,
      include_admins: audience.include_admins,
      include_user_ids: audience.include_user_ids,
      exclude_user_ids: audience.exclude_user_ids,
    },
  });
  return data;
}

export async function previewAdminMessage(body: {
  subject: string;
  body: string;
  category: AdminMessageCategory;
  user_id?: number;
}): Promise<AdminMessagePreview> {
  const { data } = await apiClient.post("/admin/messages/preview", body);
  return data;
}

export async function sendAdminMessageTest(body: {
  subject: string;
  body: string;
  category: AdminMessageCategory;
}): Promise<{ message: string; email: string }> {
  const { data } = await apiClient.post("/admin/messages/test", body);
  return data;
}

export async function createAdminMessage(
  body: {
    subject: string;
    body: string;
    category: AdminMessageCategory;
    expected_recipient_count: number;
    confirmation_text?: string;
    acknowledge_duplicates?: boolean;
  } & AdminMessageAudiencePayload,
  idempotencyKey: string
): Promise<AdminMessage> {
  const { data } = await apiClient.post("/admin/messages", body, {
    headers: { "Idempotency-Key": idempotencyKey },
  });
  return data;
}

export async function getAdminMessages(params?: {
  page?: number;
  per_page?: number;
  status?: string;
  search?: string;
}): Promise<{
  data: AdminMessage[];
  current_page: number;
  last_page: number;
  per_page: number;
  total: number;
}> {
  const { data } = await apiClient.get("/admin/messages", { params });
  return data;
}

export async function getAdminMessage(
  id: number,
  params?: { page?: number; per_page?: number; status?: string; search?: string }
): Promise<{
  message: AdminMessage;
  recipients: AdminMessageRecipient[];
  current_page: number;
  last_page: number;
  per_page: number;
  total: number;
}> {
  const { data } = await apiClient.get(`/admin/messages/${id}`, { params });
  return data;
}

export async function retryAdminMessage(
  id: number,
  idempotencyKey: string
): Promise<{ message: string; queued_count: number }> {
  const { data } = await apiClient.post(
    `/admin/messages/${id}/retry`,
    {},
    { headers: { "Idempotency-Key": idempotencyKey } }
  );
  return data;
}

/* -------------------------------------------------------------------------- */
/* MVP 2 — categories                                                         */
/* -------------------------------------------------------------------------- */

export type AdminCategoryKind = "seeking" | "offering";

export interface AdminCategory {
  id: number;
  parent_id: number | null;
  kind: AdminCategoryKind;
  name: string;
  slug: string;
  description: string | null;
  is_active: boolean;
  sort_order: number;
  children_count?: number;
  profiles_count?: number;
  parent?: { id: number; name: string } | null;
}

export interface AdminCategoryPayload {
  parent_id?: number | null;
  kind?: AdminCategoryKind;
  name?: string;
  slug?: string | null;
  description?: string | null;
  is_active?: boolean;
  sort_order?: number;
}

export async function getAdminCategories(params?: {
  kind?: AdminCategoryKind;
  is_active?: boolean;
  q?: string;
}): Promise<{ categories: AdminCategory[]; kinds: AdminCategoryKind[] }> {
  const { data } = await apiClient.get("/admin/categories", { params });
  return data;
}

export async function createAdminCategory(
  body: AdminCategoryPayload & { kind: AdminCategoryKind; name: string }
): Promise<{ category: AdminCategory; message: string }> {
  const { data } = await apiClient.post("/admin/categories", body);
  return data;
}

export async function updateAdminCategory(
  id: number,
  body: AdminCategoryPayload
): Promise<{ category: AdminCategory; message: string }> {
  const { data } = await apiClient.patch(`/admin/categories/${id}`, body);
  return data;
}

export async function deleteAdminCategory(id: number): Promise<{ message: string }> {
  const { data } = await apiClient.delete(`/admin/categories/${id}`);
  return data;
}

/* -------------------------------------------------------------------------- */
/* MVP 2 — communities                                                        */
/* -------------------------------------------------------------------------- */

export type AdminCommunityType = "event" | "group" | "organization";
export type AdminCommunityJoinMethod = "open" | "request" | "invite";
export type AdminCommunityStatus = "active" | "inactive" | "archived";

export interface AdminCommunity {
  id: number;
  owner_user_id: number;
  name: string;
  slug: string;
  description: string | null;
  type: AdminCommunityType;
  join_method: AdminCommunityJoinMethod;
  status: AdminCommunityStatus;
  organisation_name: string | null;
  member_seat_limit: number | null;
  starts_at: string | null;
  ends_at: string | null;
  members_count?: number;
  active_members_count?: number;
  owner?: {
    id: number;
    first_name: string;
    last_name: string;
    email?: string;
  } | null;
}

export interface AdminCommunityMember {
  id: number;
  community_id: number;
  user_id: number;
  role: "owner" | "manager" | "moderator" | "member";
  status: "pending" | "active" | "invited" | "declined" | "left";
  join_method: "open" | "request" | "invite" | "admin";
  joined_at: string | null;
  starts_at: string | null;
  ends_at: string | null;
  user?: {
    id: number;
    first_name: string;
    last_name: string;
    email: string;
  } | null;
}

export interface AdminCommunityPayload {
  owner_user_id?: number;
  name?: string;
  slug?: string | null;
  description?: string | null;
  type?: AdminCommunityType;
  join_method?: AdminCommunityJoinMethod;
  status?: AdminCommunityStatus;
  organisation_name?: string | null;
  member_seat_limit?: number | null;
  starts_at?: string | null;
  ends_at?: string | null;
}

export async function getAdminCommunities(params?: {
  page?: number;
  per_page?: number;
  type?: AdminCommunityType;
  status?: AdminCommunityStatus;
  join_method?: AdminCommunityJoinMethod;
  q?: string;
}): Promise<PaginatedResponse<AdminCommunity>> {
  const { data } = await apiClient.get("/admin/communities", { params });
  return data;
}

export async function getAdminCommunity(id: number): Promise<{
  community: AdminCommunity;
  roles: AdminCommunityMember["role"][];
  statuses: AdminCommunityMember["status"][];
  join_methods: AdminCommunityMember["join_method"][];
}> {
  const { data } = await apiClient.get(`/admin/communities/${id}`);
  return data;
}

export async function createAdminCommunity(
  body: AdminCommunityPayload & { owner_user_id: number; name: string }
): Promise<{ community: AdminCommunity; message: string }> {
  const { data } = await apiClient.post("/admin/communities", body);
  return data;
}

export async function updateAdminCommunity(
  id: number,
  body: AdminCommunityPayload
): Promise<{ community: AdminCommunity; message: string }> {
  const { data } = await apiClient.patch(`/admin/communities/${id}`, body);
  return data;
}

export async function deleteAdminCommunity(id: number): Promise<{ message: string }> {
  const { data } = await apiClient.delete(`/admin/communities/${id}`);
  return data;
}

export async function getAdminCommunityMembers(
  communityId: number,
  params?: { page?: number; per_page?: number; status?: string; role?: string; q?: string }
): Promise<PaginatedResponse<AdminCommunityMember>> {
  const { data } = await apiClient.get(`/admin/communities/${communityId}/members`, {
    params,
  });
  return data;
}

export async function addAdminCommunityMember(
  communityId: number,
  body: {
    user_id: number;
    role?: AdminCommunityMember["role"];
    status?: AdminCommunityMember["status"];
    join_method?: AdminCommunityMember["join_method"];
  }
): Promise<{ membership: AdminCommunityMember; message: string }> {
  const { data } = await apiClient.post(`/admin/communities/${communityId}/members`, body);
  return data;
}

export async function updateAdminCommunityMember(
  communityId: number,
  membershipId: number,
  body: {
    role?: AdminCommunityMember["role"];
    status?: AdminCommunityMember["status"];
    starts_at?: string | null;
    ends_at?: string | null;
  }
): Promise<{ membership: AdminCommunityMember; message: string }> {
  const { data } = await apiClient.patch(
    `/admin/communities/${communityId}/members/${membershipId}`,
    body
  );
  return data;
}

export async function removeAdminCommunityMember(
  communityId: number,
  membershipId: number
): Promise<{ message: string }> {
  const { data } = await apiClient.delete(
    `/admin/communities/${communityId}/members/${membershipId}`
  );
  return data;
}

/* -------------------------------------------------------------------------- */
/* MVP 2 — plans and entitlements                                             */
/* -------------------------------------------------------------------------- */

export interface AdminPlanEntitlement {
  id: number;
  plan_id: number;
  limit_key: string;
  limit_value: number | null;
}

export interface AdminPlan {
  id: number;
  key: string;
  name: string;
  description: string | null;
  is_active: boolean;
  is_default: boolean;
  sort_order: number;
  price: number | string | null;
  currency: string | null;
  billing_interval: "month" | "year" | null;
  verification_mark: boolean;
  entitlements: AdminPlanEntitlement[];
  users_count?: number;
}

export interface AdminPlanPayload {
  key?: string;
  name?: string;
  description?: string | null;
  is_active?: boolean;
  is_default?: boolean;
  sort_order?: number;
  price?: number | string | null;
  currency?: string | null;
  billing_interval?: "month" | "year" | null;
  verification_mark?: boolean;
  entitlements?: Record<string, number | null>;
}

export async function getAdminPlans(): Promise<{
  plans: AdminPlan[];
  limit_keys: string[];
  configured_defaults: Record<string, number | null>;
  default_plan_key: string;
}> {
  const { data } = await apiClient.get("/admin/plans");
  return data;
}

export async function createAdminPlan(
  body: AdminPlanPayload & { key: string; name: string }
): Promise<{ plan: AdminPlan; message: string }> {
  const { data } = await apiClient.post("/admin/plans", body);
  return data;
}

export async function updateAdminPlan(
  id: number,
  body: AdminPlanPayload
): Promise<{ plan: AdminPlan; message: string }> {
  const { data } = await apiClient.patch(`/admin/plans/${id}`, body);
  return data;
}

export async function deleteAdminPlan(id: number): Promise<{ message: string }> {
  const { data } = await apiClient.delete(`/admin/plans/${id}`);
  return data;
}

export async function updateAdminPlanEntitlements(
  id: number,
  entitlements: Record<string, number | null>
): Promise<{ plan: AdminPlan; message: string }> {
  const { data } = await apiClient.put(`/admin/plans/${id}/entitlements`, {
    entitlements,
  });
  return data;
}

/* -------------------------------------------------------------------------- */
/* MVP 2 — audit logs                                                         */
/* -------------------------------------------------------------------------- */

export interface AdminAuditLogEntryV2 {
  id: number;
  actor_id: number | null;
  admin_id: number | null;
  action: string;
  target_user_id: number | null;
  target_type: string | null;
  target_id: string | null;
  metadata: Record<string, unknown> | null;
  created_at: string | null;
  actor?: {
    id: number;
    first_name: string;
    last_name: string;
    email: string;
  } | null;
  target_user?: {
    id: number;
    first_name: string;
    last_name: string;
    email: string;
  } | null;
}

export async function getAdminAuditLogs(params?: {
  actor_id?: number;
  action?: string;
  target_type?: string;
  target_id?: string;
  from?: string;
  to?: string;
  q?: string;
  page?: number;
  per_page?: number;
}): Promise<PaginatedResponse<AdminAuditLogEntryV2>> {
  const { data } = await apiClient.get("/admin/audit-logs", { params });
  return data;
}


/* -------------------------------------------------------------------------- */
/* Safety moderation queue                                                    */
/* -------------------------------------------------------------------------- */

export type ModerationReportStatus =
  | "pending"
  | "reviewed"
  | "actioned"
  | "dismissed";

export interface ModerationReport {
  id: number;
  reporter_id: number;
  reported_user_id: number;
  profile_id: number | null;
  connection_id: number | null;
  reason_category: string;
  description: string | null;
  status: ModerationReportStatus;
  reviewed_by: number | null;
  reviewed_at: string | null;
  admin_notes: string | null;
  created_at: string;
  updated_at: string;
  reporter?: {
    id: number;
    first_name: string;
    last_name: string;
    email: string;
  } | null;
  reported_user?: {
    id: number;
    first_name: string;
    last_name: string;
    email: string;
    status?: string;
  } | null;
  profile?: {
    id: number;
    user_id: number;
    slug: string | null;
    display_name: string | null;
    title: string | null;
    organisation: string | null;
  } | null;
  connection?: {
    id: number;
    member_id: number;
    guest_user_id: number | null;
    status: string;
    request_type: string;
    member_note?: string | null;
    match_reason?: string | null;
  } | null;
  reviewer?: {
    id: number;
    first_name: string;
    last_name: string;
  } | null;
}

export async function getModerationReports(params?: {
  status?: ModerationReportStatus;
  reason_category?: string;
  q?: string;
  page?: number;
  per_page?: number;
}): Promise<PaginatedResponse<ModerationReport>> {
  const { data } = await apiClient.get("/admin/reports", { params });
  return data;
}

export async function getModerationReport(
  id: number
): Promise<{ report: ModerationReport }> {
  const { data } = await apiClient.get(`/admin/reports/${id}`);
  return data;
}

export async function updateModerationReport(
  id: number,
  payload: {
    status: Exclude<ModerationReportStatus, "pending">;
    action?: "review" | "dismiss" | "suspend_user" | "no_action";
    admin_notes?: string;
  }
): Promise<{ report: ModerationReport; message: string }> {
  const { data } = await apiClient.patch(`/admin/reports/${id}`, payload);
  return data;
}

/* -------------------------------------------------------------------------- */
/* Organizations                                                              */
/* -------------------------------------------------------------------------- */

export interface AdminOrganization {
  id: number;
  name: string;
  slug: string;
  description: string | null;
  industry: string | null;
  website_url: string | null;
  location: string | null;
  services: string[];
  seeking: string | null;
  offering: string | null;
  logo_url: string | null;
  cover_url: string | null;
  status: "active" | "inactive" | "archived";
  verification_status: "unverified" | "pending" | "verified" | "rejected" | "suspended";
  join_method: "open" | "request" | "invite";
  owner_user_id: number;
  approved_at: string | null;
  rejected_reason: string | null;
  suspended_at: string | null;
  member_seat_limit: number | null;
  members_count: number;
  owner?: { id: number; first_name: string; last_name: string; email: string } | null;
}

export async function getAdminOrganizations(params?: {
  q?: string;
  verification_status?: string;
  status?: string;
  page?: number;
  per_page?: number;
}): Promise<PaginatedResponse<AdminOrganization>> {
  const { data } = await apiClient.get("/admin/organizations", { params });
  return data;
}

export async function getAdminOrganization(id: number): Promise<{
  organization: AdminOrganization;
  members: unknown[];
  invitations: unknown[];
}> {
  const { data } = await apiClient.get(`/admin/organizations/${id}`);
  return data;
}

export async function createAdminOrganization(payload: {
  owner_user_id: number;
  name: string;
  description?: string | null;
  industry?: string | null;
  website_url?: string | null;
  location?: string | null;
  services?: string[];
  verification_status?: string;
  status?: string;
  member_seat_limit?: number | null;
}): Promise<{ organization: AdminOrganization; message: string }> {
  const { data } = await apiClient.post("/admin/organizations", payload);
  return data;
}

export async function updateAdminOrganization(
  id: number,
  payload: Record<string, unknown>
): Promise<{ organization: AdminOrganization; message: string }> {
  const { data } = await apiClient.patch(`/admin/organizations/${id}`, payload);
  return data;
}

export async function approveAdminOrganization(id: number): Promise<{
  organization: AdminOrganization;
  message: string;
}> {
  const { data } = await apiClient.post(`/admin/organizations/${id}/approve`);
  return data;
}

export async function rejectAdminOrganization(
  id: number,
  reason?: string
): Promise<{ organization: AdminOrganization; message: string }> {
  const { data } = await apiClient.post(`/admin/organizations/${id}/reject`, { reason });
  return data;
}

export async function suspendAdminOrganization(
  id: number,
  reason?: string
): Promise<{ organization: AdminOrganization; message: string }> {
  const { data } = await apiClient.post(`/admin/organizations/${id}/suspend`, { reason });
  return data;
}

export async function assignAdminOrganizationAdministrator(
  id: number,
  userId: number
): Promise<{ membership: unknown; message: string }> {
  const { data } = await apiClient.post(`/admin/organizations/${id}/administrators`, {
    user_id: userId,
  });
  return data;
}

/* -------------------------------------------------------------------------- */
/* Billing review                                                             */
/* -------------------------------------------------------------------------- */

export interface AdminBillingPayment {
  id: number;
  user_id: number;
  amount: string;
  currency: string;
  status: string;
  purpose: string;
  provider: string | null;
  provider_reference: string | null;
  paid_at: string | null;
  created_at: string;
  user?: { id: number; first_name: string; last_name: string; email: string } | null;
  plan?: { id: number; key: string; name: string } | null;
}

export interface AdminSubscription {
  id: number;
  user_id: number;
  plan_id: number | null;
  status: string;
  provider: string | null;
  current_period_start: string | null;
  current_period_end: string | null;
  cancel_at_period_end: boolean;
  created_at: string;
  user?: { id: number; first_name: string; last_name: string; email: string } | null;
  plan?: { id: number; key: string; name: string; price?: string | null } | null;
}

export interface AdminBillingEvent {
  id: number;
  event_type: string;
  provider: string | null;
  provider_event_id: string | null;
  processed_at: string | null;
  created_at: string;
  user?: { id: number; first_name: string; last_name: string; email: string } | null;
}

export async function getAdminBillingPayments(params?: {
  status?: string;
  purpose?: string;
  q?: string;
  page?: number;
  per_page?: number;
}): Promise<PaginatedResponse<AdminBillingPayment>> {
  const { data } = await apiClient.get("/admin/billing/payments", { params });
  return data;
}

export async function getAdminBillingSubscriptions(params?: {
  status?: string;
  page?: number;
  per_page?: number;
}): Promise<PaginatedResponse<AdminSubscription>> {
  const { data } = await apiClient.get("/admin/billing/subscriptions", { params });
  return data;
}

export async function getAdminBillingEvents(params?: {
  page?: number;
  per_page?: number;
}): Promise<PaginatedResponse<AdminBillingEvent>> {
  const { data } = await apiClient.get("/admin/billing/events", { params });
  return data;
}

/* -------------------------------------------------------------------------- */
/* Referral review                                                            */
/* -------------------------------------------------------------------------- */

export interface AdminReferralRewardEntry {
  id: number;
  status: string;
  entry_type: string;
  amount: string | null;
  delta_amount: string | null;
  currency: string | null;
  note: string | null;
  created_at: string;
}

export interface AdminReferral {
  id: number;
  referrer_user_id: number;
  invitee_user_id: number | null;
  referral_code: string;
  status: string;
  invitee_email: string | null;
  attribution_ip: string | null;
  risk_flags: string[] | null;
  attributed_at: string | null;
  expires_at: string | null;
  qualified_at: string | null;
  voided_at: string | null;
  created_at: string;
  referrer?: { id: number; first_name: string; last_name: string; email: string } | null;
  invitee?: { id: number; first_name: string; last_name: string; email: string } | null;
  reward_entries?: AdminReferralRewardEntry[];
}

export async function getAdminReferrals(params?: {
  status?: string;
  q?: string;
  page?: number;
  per_page?: number;
}): Promise<{
  referrals: PaginatedResponse<AdminReferral>;
  summary: Record<string, unknown>;
}> {
  const { data } = await apiClient.get("/admin/referrals", { params });
  return data;
}

export async function getAdminReferral(id: number): Promise<{ referral: AdminReferral }> {
  const { data } = await apiClient.get(`/admin/referrals/${id}`);
  return data;
}

export async function approveAdminReferral(
  id: number,
  note?: string
): Promise<{ referral: AdminReferral; message: string }> {
  const { data } = await apiClient.post(`/admin/referrals/${id}/approve`, { note });
  return data;
}

export async function voidAdminReferral(
  id: number,
  reason: string
): Promise<{ referral: AdminReferral; message: string }> {
  const { data } = await apiClient.post(`/admin/referrals/${id}/void`, { reason });
  return data;
}

export async function markAdminReferralPaid(
  id: number,
  note: string
): Promise<{ referral: AdminReferral; message: string }> {
  const { data } = await apiClient.post(`/admin/referrals/${id}/mark-paid`, { note });
  return data;
}

export async function replaceAdminCard(
  cardId: number,
  newCardId: string,
  profileId?: number
): Promise<{ old_card: unknown; new_card: unknown; message: string }> {
  const { data } = await apiClient.post(`/admin/smart-cards/${cardId}/replace`, {
    new_card_id: newCardId,
    profile_id: profileId,
  });
  return data;
}

/* -------------------------------------------------------------------------- */
/* Pilot metrics                                                              */
/* -------------------------------------------------------------------------- */

export interface AdminMetrics {
  window_days: number;
  users: { new_members: number; active_members: number };
  profiles: {
    published: number;
    searchable: number;
    published_events: number;
    searchability_disabled_events: number;
  };
  discovery: {
    profile_views: number;
    sources: { source: string; count: number }[];
    qr_scans: number;
    signature_scans: number;
    searches: number;
  };
  connections: {
    requests: number;
    accepted: number;
    pending: number;
    accepted_total: number;
    acceptance_rate: number | null;
  };
  catalog: { enquiries: number; enquiries_total: number; active_items: number };
  organizations: {
    verified: number;
    pending: number;
    affiliated_members: number;
    member_added_events: number;
    affiliation_confirmed_events: number;
  };
  referrals: {
    by_status: Record<string, number>;
    signup_events: number;
    qualification_events: number;
    reward_value_pending_available: number;
  };
  signature_cards: {
    generated_total: number;
    generated_events: number;
    by_format: Record<string, number>;
  };
  billing: {
    active_subscriptions: number;
    expired_subscriptions: number;
    successful_payments: number;
    subscription_payments: number;
    card_activation_payments: number;
    active_cards: number;
  };
}

export async function getAdminMetrics(days?: number): Promise<AdminMetrics> {
  const { data } = await apiClient.get("/admin/metrics", { params: days ? { days } : undefined });
  return data;
}
