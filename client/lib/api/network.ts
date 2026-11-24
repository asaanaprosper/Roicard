import apiClient from "./client";

/** Public-safe profile summary returned by discovery, recommendations and search. */
export interface NetworkProfile {
  id: number;
  slug: string | null;
  profile_type: "personal" | "business";
  display_name: string | null;
  title: string | null;
  organisation: string | null;
  location: string | null;
  bio: string | null;
  avatar_url: string | null;
  completion_pct: number;
  logo_url?: string | null;
  cover_image_url?: string | null;
  catalog_items?: {
    id: number;
    type: "service" | "product";
    title: string;
    description: string | null;
    price_label: string;
    category: string | null;
  }[];
  organizations?: {
    id: number;
    name: string;
    slug: string;
    industry: string | null;
    logo_url: string | null;
  }[];
  categories: {
    id: number;
    name: string;
    kind: string;
    slug?: string;
  }[];
  user: {
    first_name: string | null;
    last_name: string | null;
  } | null;
  verified: boolean;
}

export interface MatchedSignalItem {
  direction: "offering_meets_seeking" | "seeking_meets_offering";
  seeking_item: {
    id: number;
    description: string;
    categories: string[];
  };
  offering_item: {
    id: number;
    description: string;
    categories: string[];
  };
  shared_category_keys: string[];
  shared_category_ids: number[];
  shared_tag_ids: number[];
}

export interface RecommendationItem {
  profile: NetworkProfile;
  score: number;
  reason_codes: string[];
  explanation: string;
  matched_items: MatchedSignalItem[];
  category_pair_keys: string[];
}

export interface RecommendationPayload {
  items: RecommendationItem[];
  total: number;
  needs_signals: boolean;
  missing_signals: ("seeking" | "offering")[];
  message: string | null;
  profile_id?: number;
}

export interface SearchResultItem {
  profile: NetworkProfile;
  relevance: number;
  shared_communities: number;
  verified: boolean;
  completion_pct: number;
}

export interface SearchResponse {
  current_page: number;
  data: SearchResultItem[];
  first_page_url: string;
  from: number | null;
  last_page: number;
  last_page_url: string;
  next_page_url: string | null;
  path: string;
  per_page: number;
  prev_page_url: string | null;
  to: number | null;
  total: number;
}

export interface SearchFilters {
  q?: string;
  result_type?: "person" | "business";
  category?: string;
  location?: string;
  community?: string;
  organization?: string;
  organization_id?: number;
  verified?: boolean;
  per_page?: number;
  page?: number;
}

export interface DiscoverResponse {
  profile_id: number;
  recommendations: RecommendationPayload;
  community_people: SearchResultItem[];
  network: SearchResultItem[];
  has_communities: boolean;
}

export function fullName(profile: NetworkProfile): string {
  const name = [profile.user?.first_name, profile.user?.last_name]
    .filter(Boolean)
    .join(" ")
    .trim();
  return name || profile.display_name || profile.organisation || "ROICARD member";
}

export async function getRecommendations(profileId?: number): Promise<RecommendationPayload> {
  const { data } = await apiClient.get("/recommendations", {
    params: profileId ? { profile_id: profileId } : undefined,
  });
  return data;
}

export async function getDiscover(profileId?: number): Promise<DiscoverResponse> {
  const { data } = await apiClient.get("/discover", {
    params: profileId ? { profile_id: profileId } : undefined,
  });
  return data;
}

export async function dismissRecommendation(
  profileId: number,
  recommendedProfileId: number
): Promise<{ message: string }> {
  const { data } = await apiClient.post(
    `/recommendations/${recommendedProfileId}/dismiss`,
    {},
    { params: { profile_id: profileId } }
  );
  return data;
}

export async function markRecommendationNotRelevant(
  profileId: number,
  recommendedProfileId: number,
  categoryPairKeys: string[] = []
): Promise<{ message: string }> {
  const { data } = await apiClient.post(
    `/recommendations/${recommendedProfileId}/not-relevant`,
    { category_pair_keys: categoryPairKeys },
    { params: { profile_id: profileId } }
  );
  return data;
}

export async function searchNetwork(filters: SearchFilters): Promise<SearchResponse> {
  const { data } = await apiClient.get("/search", {
    params: {
      ...filters,
      verified: filters.verified ? 1 : undefined,
    },
  });
  return data;
}

export interface CommunityOption {
  id: number;
  name: string;
  slug: string;
}

export async function getCommunityOptions(): Promise<CommunityOption[]> {
  const { data } = await apiClient.get("/communities", {
    params: { per_page: 50 },
  });
  const rows = Array.isArray(data) ? data : data.data;
  return (rows ?? []).map((community: CommunityOption) => ({
    id: community.id,
    name: community.name,
    slug: community.slug,
  }));
}
