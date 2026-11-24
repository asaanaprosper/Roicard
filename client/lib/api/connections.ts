import apiClient from "./client";

export interface ConnectionUser {
  id: number;
  first_name: string;
  last_name: string;
  email: string;
  profile?: {
    id: number;
    user_id: number;
    slug: string | null;
    display_name?: string | null;
    title: string | null;
    organisation: string | null;
    avatar_url?: string | null;
  } | null;
}

export interface ConnectionProfileSummary {
  id: number;
  user_id: number;
  slug: string | null;
  display_name: string | null;
  title: string | null;
  organisation: string | null;
  avatar_url?: string | null;
}

export interface Connection {
  id: number;
  member_id: number;
  guest_user_id: number | null;
  direction: "received" | "sent";
  request_type: "guest" | "member";
  member?: ConnectionUser | null;
  guest_user?: ConnectionUser | null;
  sender_profile_id: number | null;
  recipient_profile_id: number | null;
  sender_profile?: ConnectionProfileSummary | null;
  recipient_profile?: ConnectionProfileSummary | null;
  guest_name: string;
  guest_email: string;
  guest_phone: string | null;
  guest_org: string | null;
  guest_meeting_context: string | null;
  guest_introduction: string | null;
  guest_intent: string | null;
  member_note: string | null;
  match_reason: string | null;
  match_reason_codes: string[] | null;
  status: "pending" | "approved" | "declined" | "withdrawn" | "removed";
  pair_key: string | null;
  responded_at: string | null;
  withdrawn_at: string | null;
  removed_at: string | null;
  created_at: string;
  updated_at: string;
}

export async function getConnections(): Promise<{
  connections: {
    data: Connection[];
  };
}> {
  const { data } = await apiClient.get("/connections");
  return data;
}

export async function createConnection(payload: {
  slug: string;
  guest_name: string;
  guest_email: string;
  guest_phone?: string;
  guest_org?: string;
  guest_meeting_context?: string;
  guest_introduction?: string;
  guest_intent?: string;
}): Promise<{ connection: Connection; message: string }> {
  const { data } = await apiClient.post("/connections", payload);
  return data;
}

export async function updateConnection(
  id: number,
  action: "approve" | "decline"
): Promise<{ connection: Connection; message: string }> {
  const { data } = await apiClient.patch(`/connections/${id}`, { action });
  return data;
}

/** Sends a member-to-member connection request from a profile card. */
export async function sendMemberConnectionRequest(payload: {
  profile_id: number;
  sender_profile_id?: number;
  note?: string;
  match_reason?: string;
  reason_codes?: string[];
  match_score?: number;
}): Promise<{ connection: Connection; message: string }> {
  const { data } = await apiClient.post("/member-connections", payload);
  return data;
}

export async function acceptMemberConnection(
  id: number
): Promise<{ connection: Connection; message: string }> {
  const { data } = await apiClient.patch(`/member-connections/${id}/accept`);
  return data;
}

export async function declineMemberConnection(
  id: number
): Promise<{ connection: Connection; message: string }> {
  const { data } = await apiClient.patch(`/member-connections/${id}/decline`);
  return data;
}

export async function withdrawMemberConnection(
  id: number
): Promise<{ connection: Connection; message: string }> {
  const { data } = await apiClient.patch(`/member-connections/${id}/withdraw`);
  return data;
}

export async function removeMemberConnection(
  id: number
): Promise<{ connection: Connection; message: string }> {
  const { data } = await apiClient.delete(`/member-connections/${id}`);
  return data;
}