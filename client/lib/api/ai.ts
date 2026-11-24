import apiClient from "./client";
import type { CatalogItem, CatalogListResponse } from "./catalog";
import type { AiProfileDraft, AiProfileDraftResponse, Profile } from "./profile";

export interface AiStatus {
  configured: boolean;
  available: boolean;
  provider: string;
  model: string;
  provider_region?: string;
  consent_required: boolean;
  consent_version: string;
  consent_text: string;
  consented_at: string | null;
  daily_limit: number | null;
  used_today: number;
  remaining_today: number | null;
  monthly_estimated_cost_usd: number;
  monthly_cost_alert_usd: number | null;
  cost_alert_reached: boolean;
  message: string | null;
}

export async function getAiStatus(): Promise<AiStatus> {
  const { data } = await apiClient.get("/ai/status");
  return data;
}

export async function acceptAiConsent(): Promise<AiStatus & { message?: string }> {
  const { data } = await apiClient.post("/ai/consent", { accepted: true });
  return data;
}

export interface AiCatalogItemDraft {
  id: string;
  status: "proposed" | "approved";
  type: "service" | "product";
  title: string;
  description: string | null;
  category_id: number | null;
  category_name: string | null;
  catalog_item_id?: number;
  approved_at?: string;
}

export interface AiCatalogDraft {
  source?: string;
  status?: string;
  feature?: string;
  provider?: string;
  model?: string;
  generated_at?: string;
  updated_at?: string;
  items?: AiCatalogItemDraft[];
}

export interface AiCatalogDraftResponse {
  available: boolean;
  message: string;
  business_about?: string | null;
  draft?: AiProfileDraft | null;
  catalog_draft?: AiCatalogDraft | null;
  profile?: Profile;
  warnings?: string[];
}

export interface AiCatalogApproveResponse {
  available: boolean;
  message: string;
  items?: CatalogItem[];
  catalog_draft?: AiCatalogDraft | null;
  catalog?: CatalogListResponse;
}

export async function requestCatalogDraft(
  profileId: number,
  body: { source_text?: string; website_url?: string } = {}
): Promise<AiCatalogDraftResponse> {
  const { data } = await apiClient.post(`/profiles/${profileId}/ai-draft/catalog`, body);
  return data;
}

export async function updateCatalogDraftItem(
  profileId: number,
  itemId: string,
  body: Partial<Pick<AiCatalogItemDraft, "type" | "title" | "description" | "category_id">>
): Promise<{ catalog_draft: AiCatalogDraft; message: string }> {
  const { data } = await apiClient.patch(
    `/profiles/${profileId}/ai-draft/catalog/items/${itemId}`,
    body
  );
  return data;
}

export async function deleteCatalogDraftItem(
  profileId: number,
  itemId: string
): Promise<{ catalog_draft: AiCatalogDraft; message: string }> {
  const { data } = await apiClient.delete(
    `/profiles/${profileId}/ai-draft/catalog/items/${itemId}`
  );
  return data;
}

export async function approveCatalogDraftItems(
  profileId: number,
  itemIds: string[]
): Promise<AiCatalogApproveResponse> {
  const { data } = await apiClient.post(`/profiles/${profileId}/ai-draft/catalog/approve`, {
    item_ids: itemIds,
  });
  return data;
}

export async function suggestProfileReuse(
  profileId: number,
  sourceProfileId: number
): Promise<AiProfileDraftResponse> {
  const { data } = await apiClient.post(`/profiles/${profileId}/ai-draft/reuse`, {
    source_profile_id: sourceProfileId,
  });
  return data;
}

export interface AiSearchFilters {
  q: string | null;
  result_type: "person" | "business" | null;
  category: string | null;
  location: string | null;
  organization: string | null;
  community: string | null;
  verified: boolean;
}

export interface AiSearchInterpretation {
  filters: AiSearchFilters;
  category_name?: string | null;
  community_name?: string | null;
  summary: string;
  confidence: number | null;
}

export interface AiSearchInterpretationResponse {
  available: boolean;
  message: string;
  interpretation: AiSearchInterpretation | null;
}

export async function interpretSearch(q: string): Promise<AiSearchInterpretationResponse> {
  const { data } = await apiClient.post("/search/interpret", { q });
  return data;
}
