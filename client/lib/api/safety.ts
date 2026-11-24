import apiClient from "./client";

export interface BlockedMember {
  id: number;
  first_name: string;
  last_name: string;
  profile?: {
    id: number;
    slug: string | null;
    display_name: string | null;
    title: string | null;
    organisation: string | null;
    avatar_url?: string | null;
  } | null;
}

export type ReportReason =
  | "spam"
  | "harassment"
  | "fake_profile"
  | "inappropriate_content"
  | "scam"
  | "other";

export const REPORT_REASONS: { value: ReportReason; label: string }[] = [
  { value: "spam", label: "Spam or unsolicited contact" },
  { value: "harassment", label: "Harassment or abusive behaviour" },
  { value: "fake_profile", label: "Fake or impersonating profile" },
  { value: "inappropriate_content", label: "Inappropriate content" },
  { value: "scam", label: "Scam or fraud" },
  { value: "other", label: "Other" },
];

export async function getBlockedMembers(): Promise<BlockedMember[]> {
  const { data } = await apiClient.get("/blocks");
  return data.blocked;
}

export async function blockMember(payload: {
  userId?: number;
  profileId?: number;
  reason?: string;
}): Promise<{ message: string }> {
  const { data } = await apiClient.post("/blocks", {
    user_id: payload.userId,
    profile_id: payload.profileId,
    reason: payload.reason,
  });
  return data;
}

export async function unblockMember(userId: number): Promise<{ message: string }> {
  const { data } = await apiClient.delete(`/blocks/${userId}`);
  return data;
}

export async function reportMember(payload: {
  profileId?: number;
  connectionId?: number;
  reasonCategory: ReportReason;
  description?: string;
}): Promise<{ message: string }> {
  const { data } = await apiClient.post("/reports", {
    profile_id: payload.profileId,
    connection_id: payload.connectionId,
    reason_category: payload.reasonCategory,
    description: payload.description,
  });
  return data;
}
