import apiClient from "./client";

export interface ReferralRewardEntry {
  id: number;
  referral_id?: number;
  status: "pending" | "available" | "voided" | "paid";
  entry_type: string;
  amount: string | number | null;
  delta_amount: string | number | null;
  currency: string | null;
  note?: string | null;
  created_at: string;
}

export interface ReferralRecord {
  id: number;
  status: string;
  attributed_at: string | null;
  expires_at: string | null;
  qualified_at: string | null;
  invitee: {
    first_name: string;
    last_name: string;
    email: string;
    created_at: string;
  } | null;
  reward_entries: ReferralRewardEntry[];
}

export interface ReferralSummary {
  enabled: boolean;
  configured: boolean;
  attribution_days: number;
  qualification_trigger?: string | null;
  supported_triggers?: string[];
  refund_window_days?: number | string | null;
  signature_referral_enabled: boolean;
  code: string;
  link: string;
  qr_svg: string;
  reward: {
    amount: number | string | null;
    currency: string;
    payout_enabled: boolean;
  };
  counts: Record<string, number>;
  balance: {
    pending: number;
    available: number;
    paid: number;
    ledger_balance: number;
    currency: string;
  };
  referrals: ReferralRecord[];
  ledger: ReferralRewardEntry[];
}

export async function getReferrals(): Promise<ReferralSummary> {
  const { data } = await apiClient.get("/referrals");
  return data;
}

export async function updateReferralSettings(payload: {
  signature_referral_enabled: boolean;
}): Promise<{ signature_referral_enabled: boolean; message: string }> {
  const { data } = await apiClient.patch("/referrals/settings", payload);
  return data;
}
