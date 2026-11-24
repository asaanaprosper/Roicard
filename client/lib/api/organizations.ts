import apiClient from "./client";

export type OrganizationVerification =
  | "unverified"
  | "pending"
  | "verified"
  | "rejected"
  | "suspended";

export interface Organization {
  id: number;
  name: string;
  slug: string;
  description: string | null;
  type: "organization";
  status: "active" | "inactive" | "archived";
  verification_status: OrganizationVerification;
  join_method: "open" | "request" | "invite";
  industry: string | null;
  website_url: string | null;
  location: string | null;
  services: string[];
  seeking: string | null;
  offering: string | null;
  logo_url: string | null;
  cover_url: string | null;
  owner_user_id: number;
  approved_at: string | null;
  rejected_reason: string | null;
  suspended_at: string | null;
  member_seat_limit: number | null;
  members_count: number;
  can_manage?: boolean;
  my_membership?: {
    id: number;
    role: string;
    status: string;
    affiliation_confirmed_at: string | null;
    member_accepted_at: string | null;
  } | null;
}

export interface OrganizationMember {
  id: number;
  user_id: number;
  role: "owner" | "manager" | "moderator" | "member";
  status: "pending" | "active" | "invited" | "declined" | "left";
  join_method: string;
  joined_at: string | null;
  affiliation_confirmed_at: string | null;
  member_accepted_at: string | null;
  publicly_affiliated: boolean;
  user: {
    id: number;
    first_name: string;
    last_name: string;
    email: string;
  } | null;
}

export interface OrganizationInvitation {
  id: number;
  email: string;
  role: string;
  status: "pending" | "accepted" | "declined" | "revoked" | "expired";
  expires_at: string | null;
  accepted_at: string | null;
  created_at: string | null;
  token?: string;
  organization?: { id: number; name: string; slug: string } | null;
}

export interface DirectoryMember {
  membership_id: number;
  role: string;
  profile: {
    id: number;
    slug: string | null;
    display_name: string | null;
    title: string | null;
    organisation: string | null;
    location: string | null;
    profile_type: "personal" | "business";
    avatar_url: string | null;
    verified: boolean;
    user: { first_name: string | null; last_name: string | null };
  };
}

export interface OrganizationDirectory {
  organization: Organization;
  members: DirectoryMember[];
  members_count: number;
}

export interface OrganizationWorkspace {
  organization: Organization;
  members: OrganizationMember[];
  invitations: OrganizationInvitation[];
  counts: { active: number; affiliated: number; pending: number; invited: number };
  seat_limit: number | null;
}

export interface OrganizationPayload {
  name?: string;
  description?: string | null;
  industry?: string | null;
  website_url?: string | null;
  location?: string | null;
  services?: string[];
  seeking?: string | null;
  offering?: string | null;
  join_method?: "open" | "request" | "invite";
}

export async function listOrganizations(params?: {
  q?: string;
  industry?: string;
  location?: string;
  per_page?: number;
  page?: number;
}): Promise<{
  data: Organization[];
  current_page: number;
  last_page: number;
  total: number;
}> {
  const { data } = await apiClient.get("/organizations", { params });
  return data;
}

export async function getOrganization(slug: string, q?: string): Promise<OrganizationDirectory> {
  const { data } = await apiClient.get(`/organizations/${slug}`, { params: q ? { q } : undefined });
  return data;
}

export async function getMyOrganizations(): Promise<{ organizations: Organization[] }> {
  const { data } = await apiClient.get("/my-organizations");
  return data;
}

export async function registerOrganization(payload: OrganizationPayload): Promise<{
  organization: Organization;
  message: string;
}> {
  const { data } = await apiClient.post("/organizations", payload);
  return data;
}

export async function updateOrganization(
  slug: string,
  payload: OrganizationPayload
): Promise<{ organization: Organization; message: string }> {
  const { data } = await apiClient.patch(`/organizations/${slug}`, payload);
  return data;
}

export async function uploadOrganizationLogo(
  slug: string,
  file: File
): Promise<{ organization: Organization; message: string }> {
  const form = new FormData();
  form.append("logo", file);
  const { data } = await apiClient.post(`/organizations/${slug}/logo`, form, {
    headers: { "Content-Type": "multipart/form-data" },
  });
  return data;
}

export async function uploadOrganizationCover(
  slug: string,
  file: File
): Promise<{ organization: Organization; message: string }> {
  const form = new FormData();
  form.append("cover", file);
  const { data } = await apiClient.post(`/organizations/${slug}/cover`, form, {
    headers: { "Content-Type": "multipart/form-data" },
  });
  return data;
}

export async function getOrganizationWorkspace(slug: string): Promise<OrganizationWorkspace> {
  const { data } = await apiClient.get(`/organizations/${slug}/members`);
  return data;
}

export async function inviteOrganizationMembers(
  slug: string,
  payload: { email?: string; emails?: string[]; role?: string }
): Promise<{
  created: OrganizationInvitation[];
  skipped: string[];
  invitations: OrganizationInvitation[];
  message: string;
}> {
  const { data } = await apiClient.post(`/organizations/${slug}/invitations`, payload);
  return data;
}

export async function addOrganizationMember(
  slug: string,
  payload: { userId?: number; email?: string; role?: string }
): Promise<{ membership: OrganizationMember; message: string }> {
  const { data } = await apiClient.post(`/organizations/${slug}/members`, {
    user_id: payload.userId,
    email: payload.email,
    role: payload.role,
  });
  return data;
}

export async function approveOrganizationMember(
  slug: string,
  membershipId: number
): Promise<{ membership: OrganizationMember; message: string }> {
  const { data } = await apiClient.post(
    `/organizations/${slug}/members/${membershipId}/approve`
  );
  return data;
}

export async function rejectOrganizationMember(
  slug: string,
  membershipId: number
): Promise<{ membership: OrganizationMember; message: string }> {
  const { data } = await apiClient.post(
    `/organizations/${slug}/members/${membershipId}/reject`
  );
  return data;
}

export async function acceptOrganizationAffiliation(
  slug: string,
  membershipId: number
): Promise<{ membership: OrganizationMember; message: string }> {
  const { data } = await apiClient.post(
    `/organizations/${slug}/members/${membershipId}/accept`
  );
  return data;
}

export async function removeOrganizationMember(
  slug: string,
  membershipId: number
): Promise<{ membership: OrganizationMember; message: string }> {
  const { data } = await apiClient.delete(`/organizations/${slug}/members/${membershipId}`);
  return data;
}

export async function revokeOrganizationInvitation(
  slug: string,
  invitationId: number
): Promise<{ invitation: OrganizationInvitation; message: string }> {
  const { data } = await apiClient.delete(`/organizations/${slug}/invitations/${invitationId}`);
  return data;
}

export async function requestOrganizationJoin(
  slug: string
): Promise<{ membership: OrganizationMember; message: string }> {
  const { data } = await apiClient.post(`/organizations/${slug}/join`);
  return data;
}

export async function getMyOrganizationInvitations(): Promise<{
  invitations: OrganizationInvitation[];
}> {
  const { data } = await apiClient.get("/organization-invitations");
  return data;
}

export async function acceptOrganizationInvitation(
  token: string
): Promise<{ membership: OrganizationMember; message: string }> {
  const { data } = await apiClient.post(`/organization-invitations/${token}/accept`);
  return data;
}
