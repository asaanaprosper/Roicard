import apiClient from "./client";

export interface Profile {
  id: number;
  user_id: number;
  profile_type: "personal" | "business";
  display_name: string | null;
  title: string | null;
  role_description: string | null;
  organisation: string | null;
  whatsapp_phone: string | null;
  phone: string | null;
  contact_email: string | null;
  website_url: string | null;
  skills: string[] | null;
  services: string[] | null;
  location: string | null;
  bio: string | null;
  seeking: string | null;
  offering: string | null;
  slug: string | null;
  is_live: boolean;
  is_published: boolean;
  published_at: string | null;
  privacy_confirmed_at: string | null;
  visibility: string;
  is_searchable: boolean;
  is_contactable: boolean;
  connection_request_policy?: "communities" | "matching" | "none" | null;
  contact_details_visibility: string;
  contact_visibility: Record<string, string> | null;
  is_default: boolean;
  completion_pct: number;
  avatar_url?: string | null;
  logo_url?: string | null;
  cover_url?: string | null;
  ai_draft?: Record<string, unknown> | null;
  ai_approved_fields?: string[] | null;
  ai_catalog_draft?: Record<string, unknown> | null;
  categories?: { id: number; name: string; kind: string; slug: string }[];
  created_at: string;
  updated_at: string;
}

export interface ProfilesResponse {
  profiles: Profile[];
  default_profile_id: number | null;
}

export interface ProfileCategoryPayload {
  seeking_category_ids: number[];
  offering_category_ids: number[];
}

export interface AiExperienceDraft {
  title: string;
  company: string;
  start_date: string;
  end_date?: string | null;
  location?: string | null;
}

export interface AiProfileDraft {
  headline?: string;
  about?: string;
  experience?: AiExperienceDraft[];
  skills?: string[];
  services?: string[];
  seeking?: string;
  offering?: string;
  source?: string;
  status?: string;
  feature?: string;
  provider?: string;
  model?: string;
  generated_at?: string;
  source_profile_id?: number;
  [key: string]: unknown;
}

export interface AiProfileDraftResponse {
  available: boolean;
  message: string;
  draft: AiProfileDraft | null;
  profile?: Profile;
  warnings?: string[];
}

export interface CvUploadResponse {
  message: string;
  cv: {
    id: number;
    name: string;
    size_kb: number;
    visible: boolean;
    download_url: string;
  } | null;
}

export interface PublicProfileMeetingType {
  id: number;
  name: string;
  description: string | null;
  duration_minutes: number;
  format: string;
  format_label: string;
  location_detail: string | null;
  custom_questions: {
    id: number;
    question: string;
    is_required: boolean;
  }[];
}

export interface PublicProfile {
  id: number;
  slug: string;
  profile_type: "personal" | "business";
  display_name: string | null;
  title: string | null;
  role_description: string | null;
  organisation: string | null;
  whatsapp_phone: string | null;
  phone: string | null;
  contact_email: string | null;
  website_url: string | null;
  skills: string[] | null;
  services: string[] | null;
  date_of_birth: string | null;
  gender: "male" | "female" | "prefer_not_to_say" | null;
  interests: string[] | null;
  location: string | null;
  bio: string | null;
  seeking: string | null;
  offering: string | null;
  seeking_items: {
    id: number;
    description: string;
    categories: { id: number; name: string }[];
  }[];
  offering_items: {
    id: number;
    description: string;
    categories: { id: number; name: string }[];
  }[];
  verified_member: boolean;
  verification_label: string;
  logo_url: string | null;
  cover_image_url: string | null;
  catalog_items: {
    id: number;
    type: "service" | "product";
    title: string;
    description: string | null;
    category: { id: number; name: string; slug?: string | null } | null;
    price: number | null;
    price_mode: "fixed" | "from" | "on_request";
    price_label: string;
    currency: string;
    cta_type: "enquire" | "request_meeting" | "call";
    sort_order: number;
    images: { id: number; url: string; name: string }[];
  }[];
  organizations: {
    id: number;
    name: string;
    slug: string;
    industry: string | null;
    logo_url: string | null;
    role: string | null;
  }[];
  avatar: string | null;
  is_published: boolean;
  published_at: string | null;
  visibility: string;
  is_searchable: boolean;
  can_contact: boolean;
  contact_details_visible: boolean;
  visible_contact_fields: {
    phone: boolean;
    whatsapp: boolean;
    email: boolean;
    website: boolean;
  };
  user: { first_name: string; last_name: string; email: string };
  social_links: { platform: string; value: string }[];
  education: {
    id: number;
    institution: string;
    degree: string;
    start_year: number;
    end_year: number | null;
    honours: string | null;
  }[];
  experience: {
    id: number;
    title: string;
    company: string;
    start_date: string;
    end_date: string | null;
    location: string | null;
  }[];
  achievements: {
    id: number;
    title: string;
    issuer: string | null;
    date: string | null;
  }[];
  cv: { url: string; name: string; size_kb: number } | null;
  meeting_types: PublicProfileMeetingType[];
}

export async function getProfile(): Promise<{ profile: Profile }> {
  const { data } = await apiClient.get("/profile");
  return data;
}

export async function createProfile(payload: Partial<Profile>): Promise<{ profile: Profile }> {
  const { data } = await apiClient.post("/profile", payload);
  return data;
}

export async function updateProfile(payload: Partial<Profile>): Promise<{ profile: Profile }> {
  const { data } = await apiClient.put("/profile", payload);
  return data;
}

export async function getProfiles(): Promise<ProfilesResponse> {
  const { data } = await apiClient.get("/profiles");
  return data;
}

export async function getProfileById(id: number): Promise<{ profile: Profile }> {
  const { data } = await apiClient.get(`/profiles/${id}`);
  return data;
}

export async function createProfileV2(
  payload: Partial<Profile> & Record<string, unknown>
): Promise<{ profile: Profile; message: string }> {
  const { data } = await apiClient.post("/profiles", payload);
  return data;
}

export async function updateProfileV2(
  id: number,
  payload: Partial<Profile> & Record<string, unknown>
): Promise<{ profile: Profile; message: string }> {
  const { data } = await apiClient.put(`/profiles/${id}`, payload);
  return data;
}

export async function deleteProfileV2(id: number): Promise<{ message: string }> {
  const { data } = await apiClient.delete(`/profiles/${id}`);
  return data;
}

export async function setDefaultProfileV2(
  id: number
): Promise<{ profile: Profile; message: string }> {
  const { data } = await apiClient.post(`/profiles/${id}/default`);
  return data;
}

export async function uploadProfileAvatarV2(id: number, file: File): Promise<string> {
  const form = new FormData();
  form.append("avatar", file);
  const { data } = await apiClient.post(`/profiles/${id}/avatar`, form, {
    headers: { "Content-Type": "multipart/form-data" },
  });
  return data.url;
}

export async function uploadBusinessBranding(
  id: number,
  kind: "logo" | "cover",
  file: File
): Promise<string> {
  const form = new FormData();
  form.append("image", file);
  const { data } = await apiClient.post(`/profiles/${id}/${kind}`, form, {
    headers: { "Content-Type": "multipart/form-data" },
  });
  return data.url;
}

export async function publishProfileV2(
  id: number
): Promise<{ profile: Profile; onboarding: unknown; message: string }> {
  const { data } = await apiClient.post(`/profiles/${id}/publish`);
  return data;
}

export async function unpublishProfileV2(
  id: number
): Promise<{ profile: Profile; onboarding: unknown; message: string }> {
  const { data } = await apiClient.post(`/profiles/${id}/unpublish`);
  return data;
}

export async function requestAiDraft(
  id: number,
  body: { source_text?: string; website_url?: string; cv_media_id?: number } = {}
): Promise<AiProfileDraftResponse> {
  const { data } = await apiClient.post(`/profiles/${id}/ai-draft/request`, body);
  return data;
}

export async function approveAiDraftFields(
  id: number,
  fields: string[]
): Promise<{ profile: Profile; onboarding: unknown; message: string }> {
  const { data } = await apiClient.patch(`/profiles/${id}/ai-draft/approve`, { fields });
  return data;
}

export async function updateProfileCategories(
  id: number,
  payload: ProfileCategoryPayload
): Promise<{ profile: Profile; onboarding: unknown; message: string }> {
  const { data } = await apiClient.put(`/profiles/${id}/categories`, payload);
  return data;
}

export interface CategoryOption {
  id: number;
  parent_id: number | null;
  kind: "seeking" | "offering";
  name: string;
  slug: string;
  children?: CategoryOption[];
}

export async function getCategories(kind?: "seeking" | "offering"): Promise<CategoryOption[]> {
  const { data } = await apiClient.get("/categories", { params: kind ? { kind } : undefined });
  return data.data;
}

export async function getPublicProfile(slug: string, src?: string): Promise<PublicProfile> {
  const { data } = await apiClient.get(`/public/${slug}`, { params: { src } });
  return data;
}

export async function trackProfileEvent(slug: string, type: string): Promise<void> {
  await apiClient.post(`/public/${slug}/event`, { type });
}

export async function uploadAvatar(file: File): Promise<string> {
  const form = new FormData();
  form.append("avatar", file);
  const { data } = await apiClient.post("/profile/avatar", form, {
    headers: { "Content-Type": "multipart/form-data" },
  });
  return data.url;
}

export async function uploadCv(file: File): Promise<CvUploadResponse> {
  const form = new FormData();
  form.append("cv", file);
  const { data } = await apiClient.post("/profile/cv", form, {
    headers: { "Content-Type": "multipart/form-data" },
  });
  return data;
}

export async function deleteCv(id: number): Promise<void> {
  await apiClient.delete(`/profile/cv/${id}`);
}

export async function addEducation(payload: Record<string, unknown>): Promise<void> {
  await apiClient.post("/profile/education", payload);
}

export async function updateEducation(id: number, payload: Record<string, unknown>): Promise<void> {
  await apiClient.patch(`/profile/education/${id}`, payload);
}

export async function deleteEducation(id: number): Promise<void> {
  await apiClient.delete(`/profile/education/${id}`);
}

export async function addExperience(payload: Record<string, unknown>): Promise<void> {
  await apiClient.post("/profile/experience", payload);
}

export async function updateExperience(id: number, payload: Record<string, unknown>): Promise<void> {
  await apiClient.patch(`/profile/experience/${id}`, payload);
}

export async function deleteExperience(id: number): Promise<void> {
  await apiClient.delete(`/profile/experience/${id}`);
}

export async function addAchievement(payload: Record<string, unknown>): Promise<void> {
  await apiClient.post("/profile/achievements", payload);
}

export async function updateAchievement(id: number, payload: Record<string, unknown>): Promise<void> {
  await apiClient.patch(`/profile/achievements/${id}`, payload);
}

export async function deleteAchievement(id: number): Promise<void> {
  await apiClient.delete(`/profile/achievements/${id}`);
}

export async function updateSocialLinks(links: { platform: string; value: string }[]): Promise<void> {
  await apiClient.put("/profile/social-links", { links });
}
