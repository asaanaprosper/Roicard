import apiClient from "./client";

export interface MyCommunityMembership {
  id: number;
  community_id: number;
  user_id: number;
  role: string;
  status: string;
  join_method: string;
  discoverable: boolean;
  joined_at: string | null;
  ends_at: string | null;
  community: {
    id: number;
    name: string;
    slug: string;
    type: string;
  } | null;
}

export async function getMyCommunities(): Promise<{ memberships: MyCommunityMembership[] }> {
  const { data } = await apiClient.get("/communities/mine");
  return data;
}

export async function updateCommunityDiscoverability(
  communityId: number,
  discoverable: boolean
): Promise<{ membership: MyCommunityMembership; message: string }> {
  const { data } = await apiClient.patch(`/communities/${communityId}/membership`, {
    discoverable,
  });
  return data;
}
