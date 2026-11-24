import apiClient from "./client";

export type BillingInterval = "month" | "year";

export interface PlanOption {
  id: number;
  key: string;
  name: string;
  description: string | null;
  price: number | string | null;
  currency: string | null;
  billing_interval: BillingInterval | null;
  verification_mark: boolean;
  sort_order: number;
  entitlements: Record<string, number | null>;
  is_current: boolean;
  upgrade_available: boolean;
  pricing_configured: boolean;
}

export interface EntitlementUsageItem {
  key: string;
  limit: number | null;
  used: number;
  remaining: number | null;
  unlimited: boolean;
  disabled: boolean;
}

export interface SubscriptionSummary {
  id: number;
  status: string;
  plan: { id: number; key: string; name: string } | null;
  provider: string | null;
  current_period_start: string | null;
  current_period_end: string | null;
  cancel_at_period_end: boolean;
  cancelled_at: string | null;
  ended_at: string | null;
}

export interface PlanSnapshot {
  plan: PlanOption | null;
  current_plan_key: string | null;
  subscription: SubscriptionSummary | null;
  verification: { active: boolean; label: string };
  legacy_member: boolean;
  limits: Record<string, EntitlementUsageItem>;
  plans: PlanOption[];
  provider: { name: string; configured: boolean };
  upgrade_url: string;
  billing_url: string;
}

export interface PaymentRecord {
  id: number;
  amount: string;
  currency: string;
  method: string | null;
  status: string;
  purpose: string;
  provider_reference: string | null;
  provider: string | null;
  paid_at: string | null;
  created_at: string;
  plan?: { id: number; key: string; name: string } | null;
}

export interface BillingHistory {
  payments: PaymentRecord[];
  subscription: SubscriptionSummary | null;
  events: {
    id: number;
    event_type: string;
    provider: string | null;
    processed_at: string | null;
    created_at: string;
  }[];
}

export async function getPlan(): Promise<PlanSnapshot> {
  const { data } = await apiClient.get("/plan");
  return data;
}

export async function subscribeToPlan(payload: {
  plan_id: number;
  method?: string;
  momo_number?: string;
}): Promise<{
  payment?: PaymentRecord | null;
  subscription?: SubscriptionSummary | null;
  redirect?: Record<string, unknown>;
  message?: string;
}> {
  const { data } = await apiClient.post("/billing/subscribe", payload);
  return data;
}

export async function cancelSubscription(): Promise<{ subscription: SubscriptionSummary; message: string }> {
  const { data } = await apiClient.post("/billing/cancel");
  return data;
}

export async function resumeSubscription(): Promise<{ subscription: SubscriptionSummary; message: string }> {
  const { data } = await apiClient.post("/billing/resume");
  return data;
}

export async function getBillingHistory(): Promise<BillingHistory> {
  const { data } = await apiClient.get("/billing/history");
  return data;
}
