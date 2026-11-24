import apiClient from "./client";
import type { PaymentRecord } from "./billing";

export interface MemberCard {
  id: number;
  card_id: string;
  user_id: number | null;
  profile_id: number | null;
  inventory_status: string;
  status: string;
  binding_key: string | null;
  activated_at: string | null;
  deactivated_at: string | null;
  assigned_at: string | null;
  profile?: { id: number; slug: string | null; display_name: string | null; title: string | null } | null;
}

export async function getCards(): Promise<{
  cards: MemberCard[];
  activation_fee: number;
  currency: string;
}> {
  const { data } = await apiClient.get("/cards/status");
  return data;
}

export async function activateCard(payload: {
  profile_id: number;
  card_id: string;
  method?: string;
  momo_number?: string;
}): Promise<{
  card: MemberCard;
  payment?: PaymentRecord | null;
  redirect?: Record<string, unknown>;
  message?: string;
}> {
  const { data } = await apiClient.post("/cards/activate", payload);
  return data;
}

export async function deactivateCard(cardId: number): Promise<{ card: MemberCard; message: string }> {
  const { data } = await apiClient.post(`/cards/${cardId}/deactivate`);
  return data;
}
