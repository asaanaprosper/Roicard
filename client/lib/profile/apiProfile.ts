import type { Profile } from "@/lib/api/profile";
import type { SocialLinks, UserProfile } from "@/lib/profile/types";

const EMPTY_SOCIAL: SocialLinks = {
  linkedin: "",
  instagram: "",
  twitter: "",
  facebook: "",
  youtube: "",
  tiktok: "",
  snapchat: "",
  website: "",
};

/**
 * Maps the Phase 2 API profile shape into the existing UserProfile view model
 * used by the Roicard poster/profile components.
 */
export function apiProfileToUserProfile(
  profile: Profile,
  account?: { first_name: string; last_name: string; email: string } | null
): UserProfile {
  const social: SocialLinks = { ...EMPTY_SOCIAL };
  const links = (profile as unknown as { social_links?: { platform: string; value: string }[] })
    .social_links;
  if (Array.isArray(links)) {
    for (const link of links) {
      if (link?.platform in social) {
        social[link.platform as keyof SocialLinks] = link.value;
      }
    }
  }
  if (profile.website_url) {
    social.website = profile.website_url;
  }

  const displayName = profile.display_name?.trim();

  return {
    firstName: displayName || account?.first_name || "",
    lastName: displayName ? "" : account?.last_name ?? "",
    profilePhotoUrl: profile.avatar_url ?? null,
    professionalTitle: profile.title ?? "",
    roleDescription: profile.role_description ?? "",
    organization: profile.organisation ?? "",
    bio: profile.bio ?? "",
    email: profile.contact_email ?? account?.email ?? "",
    phone: profile.phone ?? "",
    whatsapp: profile.whatsapp_phone ?? "",
    dateOfBirth: (profile as unknown as { date_of_birth?: string | null }).date_of_birth?.slice(0, 10) ?? "",
    gender: "",
    location: profile.location ?? "",
    social,
    interests: Array.isArray((profile as unknown as { interests?: string[] }).interests)
      ? ((profile as unknown as { interests: string[] }).interests)
      : [],
    seeking: profile.seeking ?? "",
    offering: profile.offering ?? "",
    username: profile.slug ?? "",
    createdAt: profile.created_at,
    membershipStatus: profile.is_published ? "active" : "pending",
    skills: profile.skills ?? [],
    services: profile.services ?? [],
  };
}
