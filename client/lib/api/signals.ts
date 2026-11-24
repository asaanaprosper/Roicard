import apiClient from "./client";

export type SignalType = "seeking" | "offering";

export interface SignalCategory {
  id: number;
  name: string;
  kind: SignalType;
}

export interface SignalTag {
  id: number;
  name: string;
}

export interface ProfileSignal {
  id: number;
  profile_id: number;
  type: SignalType;
  description: string;
  is_active: boolean;
  paused_at: string | null;
  source: "member" | "legacy";
  sort_order: number;
  categories: SignalCategory[];
  tags: SignalTag[];
}

export interface SignalCounts {
  seeking: number;
  offering: number;
  active_seeking: number;
  active_offering: number;
}

export interface SignalLimits {
  active_seeking: number | null;
  active_offering: number | null;
}

export interface SignalsResponse {
  seeking: ProfileSignal[];
  offering: ProfileSignal[];
  counts: SignalCounts;
  limits: SignalLimits;
}

export interface SignalPayload {
  type?: SignalType;
  description?: string;
  category_ids?: number[];
  tag_ids?: number[];
  is_active?: boolean;
  sort_order?: number;
}

export interface TagOption {
  id: number;
  name: string;
  slug: string;
}

export async function getSignals(profileId: number): Promise<SignalsResponse> {
  const { data } = await apiClient.get(`/profiles/${profileId}/signals`);
  return data;
}

export async function createSignal(
  profileId: number,
  payload: SignalPayload
): Promise<{ signal: ProfileSignal; message: string }> {
  const { data } = await apiClient.post(`/profiles/${profileId}/signals`, payload);
  return data;
}

export async function updateSignal(
  profileId: number,
  signalId: number,
  payload: SignalPayload
): Promise<{ signal: ProfileSignal; message: string }> {
  const { data } = await apiClient.patch(
    `/profiles/${profileId}/signals/${signalId}`,
    payload
  );
  return data;
}

export async function pauseSignal(
  profileId: number,
  signalId: number
): Promise<{ signal: ProfileSignal; message: string }> {
  const { data } = await apiClient.post(
    `/profiles/${profileId}/signals/${signalId}/pause`
  );
  return data;
}

export async function resumeSignal(
  profileId: number,
  signalId: number
): Promise<{ signal: ProfileSignal; message: string }> {
  const { data } = await apiClient.post(
    `/profiles/${profileId}/signals/${signalId}/resume`
  );
  return data;
}

export async function deleteSignal(
  profileId: number,
  signalId: number
): Promise<{ message: string }> {
  const { data } = await apiClient.delete(
    `/profiles/${profileId}/signals/${signalId}`
  );
  return data;
}

export async function getTags(): Promise<TagOption[]> {
  const { data } = await apiClient.get("/tags");
  return data.data;
}
