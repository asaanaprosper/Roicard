import apiClient from "./client";
import type { Profile } from "./profile";

export interface SignatureCardRecord {
  id: number;
  format: "square" | "story" | "email_banner";
  template: string;
  width: number;
  height: number;
  alt_text: string;
  generated_at: string;
  preview_url: string;
}

export interface SignatureCardMetadata {
  profile: Pick<Profile, "id" | "slug" | "display_name" | "profile_type">;
  enabled: boolean;
  limit: number | null;
  implemented_templates: number;
  templates: string[];
  formats: {
    key: SignatureCardRecord["format"];
    label: string;
    width: number;
    height: number;
  }[];
  existing: SignatureCardRecord[];
  settings: {
    referral_signature_enabled: boolean;
    referral_code: string | null;
  };
}

export async function getSignatureCards(profileId: number): Promise<SignatureCardMetadata> {
  const { data } = await apiClient.get(`/profiles/${profileId}/signature-cards`);
  return data;
}

export async function generateSignatureCard(
  profileId: number,
  format: SignatureCardRecord["format"]
): Promise<{ card: SignatureCardRecord; message: string }> {
  const { data } = await apiClient.post(`/profiles/${profileId}/signature-cards/${format}`);
  return data;
}

export async function updateSignatureCardSettings(
  profileId: number,
  payload: { referral_signature_enabled: boolean }
): Promise<{ settings: SignatureCardMetadata["settings"]; message: string }> {
  const { data } = await apiClient.patch(
    `/profiles/${profileId}/signature-cards/settings`,
    payload
  );
  return data;
}
