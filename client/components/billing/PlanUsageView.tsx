"use client";

import { Button } from "@/components/ui/Button";
import { useConfirm } from "@/components/ui/ConfirmProvider";
import {
  cancelSubscription,
  getPlan,
  subscribeToPlan,
  type EntitlementUsageItem,
  type PlanOption,
  type PlanSnapshot,
} from "@/lib/api/billing";
import { confirmMockPayment } from "@/lib/api/payments";
import { cn } from "@/lib/cn";
import {
  BadgeCheck,
  Check,
  CreditCard,
  Gauge,
  Layers,
  ShieldCheck,
  Sparkles,
} from "lucide-react";
import Link from "next/link";
import { useCallback, useEffect, useState } from "react";

const LIMIT_LABELS: Record<string, string> = {
  ACTIVE_SEEKING_ITEMS: "Active Seeking items",
  ACTIVE_OFFERING_ITEMS: "Active Offering items",
  PROFILE_COUNT: "Profiles",
  CATALOG_ITEMS: "Active catalog items",
  SEARCHES_PER_DAY: "Searches today",
  CONNECTION_REQUESTS_PER_DAY: "Connection requests today",
  RECOMMENDATIONS_SHOWN: "Recommendations per view",
  AI_CALLS_PER_DAY: "AI calls today",
  VERIFICATION_MARK: "Verification mark",
  COMMUNITIES_JOINED: "Communities joined",
  ORG_MEMBER_SEATS: "Organization seats",
  SIGNATURE_CARD_TEMPLATES: "Signature card templates",
  REFERRAL_REWARDS: "Referral rewards this month",
};

function usageLabel(item: EntitlementUsageItem): string {
  if (item.disabled) return "Not included";
  if (item.unlimited) return "Unlimited";
  return `${item.used} of ${item.limit}`;
}

export function PlanUsageView() {
  const { confirm } = useConfirm();
  const [snapshot, setSnapshot] = useState<PlanSnapshot | null>(null);
  const [isLoading, setIsLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [status, setStatus] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  const load = useCallback(async () => {
    setIsLoading(true);
    setError(null);
    try {
      setSnapshot(await getPlan());
    } catch {
      setError("Unable to load your plan and usage.");
    } finally {
      setIsLoading(false);
    }
  }, []);

  useEffect(() => {
    void load();
  }, [load]);

  const upgrade = async (plan: PlanOption) => {
    const confirmed = await confirm({
      title: `Subscribe to ${plan.name}?`,
      description: plan.price !== null
        ? `${plan.currency ?? "GHS"} ${plan.price} / ${plan.billing_interval ?? "period"}. Payment is confirmed by the server before activation.`
        : "Complete the provider payment to activate this plan.",
      confirmLabel: "Continue to payment",
    });
    if (!confirmed) return;

    setBusy(true);
    setError(null);
    setStatus(null);
    try {
      const response = await subscribeToPlan({ plan_id: plan.id });
      const redirect = (response.redirect ?? {}) as {
        mock?: boolean;
        authorization_url?: string;
        checkout_url?: string;
        status?: string;
      };
      const reference = response.payment?.provider_reference;

      if (redirect.status === "already_active") {
        setStatus("You are already subscribed to this plan.");
        await load();
        return;
      }

      if (redirect.authorization_url || redirect.checkout_url) {
        window.location.assign(redirect.authorization_url ?? redirect.checkout_url ?? "/dashboard/plan");
        return;
      }

      if (redirect.mock && reference) {
        await confirmMockPayment(reference);
        setStatus("Payment confirmed by the backend. Subscription activated.");
        await load();
        return;
      }

      setStatus("Payment started. Complete the provider checkout to activate the plan.");
      await load();
    } catch (e) {
      const data = (e as { response?: { data?: { message?: string } } })?.response?.data;
      setError(data?.message ?? "Unable to start the subscription.");
    } finally {
      setBusy(false);
    }
  };

  const cancel = async () => {
    const confirmed = await confirm({
      title: "Cancel subscription?",
      description:
        "Your plan and verification mark remain active until the end of the current billing period, then you return to Free limits.",
      confirmLabel: "Cancel at period end",
      variant: "danger",
    });
    if (!confirmed) return;

    setBusy(true);
    try {
      const response = await cancelSubscription();
      setStatus(response.message);
      await load();
    } catch (e) {
      const data = (e as { response?: { data?: { message?: string } } })?.response?.data;
      setError(data?.message ?? "Unable to cancel the subscription.");
    } finally {
      setBusy(false);
    }
  };

  if (isLoading) {
    return (
      <div className="flex min-h-[40vh] items-center justify-center">
        <div className="h-8 w-8 animate-spin rounded-full border-2 border-roicard-border border-t-roicard-primary" />
      </div>
    );
  }

  if (!snapshot) {
    return (
      <div className="rounded-2xl border border-rose-500/30 bg-rose-500/10 p-6 text-sm text-rose-400">
        {error ?? "Plan information unavailable."}
        <Button size="sm" variant="secondary" className="ml-3 rounded-lg" onClick={() => void load()}>
          Retry
        </Button>
      </div>
    );
  }

  const current = snapshot.plan;
  const cancellable = Boolean(
    snapshot.subscription &&
      ["active", "past_due"].includes(snapshot.subscription.status) &&
      !snapshot.subscription.cancel_at_period_end
  );

  return (
    <div className="space-y-8">
      <header className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div>
          <span className="inline-flex items-center gap-2 rounded-full bg-roicard-primary/15 px-3 py-1 text-xs font-medium text-roicard-accent">
            <Layers className="h-3.5 w-3.5" />
            Plan &amp; usage
          </span>
          <h1 className="mt-3 text-2xl font-bold text-roicard-text sm:text-3xl">
            {current?.name ?? "No plan assigned"}
          </h1>
          <p className="mt-2 text-sm text-roicard-text-muted sm:text-base">
            Your entitlements, usage and available upgrades.
          </p>
          {snapshot.legacy_member && (
            <p className="mt-2 inline-flex rounded-full bg-amber-500/15 px-3 py-1 text-xs font-medium text-amber-300">
              Legacy member access preserved
            </p>
          )}
        </div>
        <div className="flex flex-wrap gap-2">
          <Link href="/dashboard/billing">
            <Button variant="secondary" className="rounded-xl">
              <CreditCard className="h-4 w-4" />
              Billing history
            </Button>
          </Link>
          {cancellable && (
            <Button variant="ghost" className="rounded-xl" onClick={cancel} disabled={busy}>
              Cancel subscription
            </Button>
          )}
        </div>
      </header>

      {status && (
        <p className="rounded-lg border border-emerald-500/30 bg-emerald-500/10 px-3 py-2 text-sm text-emerald-400">
          {status}
        </p>
      )}
      {error && (
        <p className="rounded-lg border border-rose-500/30 bg-rose-500/10 px-3 py-2 text-sm text-rose-400">
          {error}
        </p>
      )}
      {!snapshot.provider.configured && (
        <p className="rounded-lg border border-amber-500/30 bg-amber-500/10 px-3 py-2 text-sm text-amber-300">
          Billing provider credentials are not configured. Plan upgrades remain
          disabled until a live provider is connected; no charge is initiated.
        </p>
      )}

      <section className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
        <div className="rounded-2xl border border-roicard-border bg-roicard-bg-elevated p-5">
          <p className="flex items-center gap-2 text-xs font-semibold uppercase tracking-wider text-roicard-text-muted">
            <ShieldCheck className="h-4 w-4 text-roicard-accent" />
            Verification mark
          </p>
          <p className="mt-3 flex items-center gap-2 text-sm font-medium text-roicard-text">
            {snapshot.verification.active ? (
              <>
                <BadgeCheck className="h-5 w-5 text-emerald-400" />
                Active
              </>
            ) : (
              "Not active"
            )}
          </p>
          <p className="mt-1 text-xs text-roicard-text-muted">
            {snapshot.verification.label}
          </p>
        </div>

        <div className="rounded-2xl border border-roicard-border bg-roicard-bg-elevated p-5">
          <p className="flex items-center gap-2 text-xs font-semibold uppercase tracking-wider text-roicard-text-muted">
            <Sparkles className="h-4 w-4 text-roicard-accent" />
            Subscription
          </p>
          {snapshot.subscription ? (
            <>
              <p className="mt-3 text-sm font-medium capitalize text-roicard-text">
                {snapshot.subscription.status.replace("_", " ")}
              </p>
              <p className="mt-1 text-xs text-roicard-text-muted">
                {snapshot.subscription.current_period_end
                  ? `Renews/ends ${new Date(snapshot.subscription.current_period_end).toLocaleDateString()}`
                  : "Period end not configured"}
              </p>
              {snapshot.subscription.cancel_at_period_end && (
                <p className="mt-1 text-xs text-amber-300">Cancels at period end</p>
              )}
            </>
          ) : (
            <p className="mt-3 text-sm text-roicard-text-muted">
              No paid subscription. Your current plan follows the configured
              default limits.
            </p>
          )}
        </div>

        <div className="rounded-2xl border border-roicard-border bg-roicard-bg-elevated p-5">
          <p className="flex items-center gap-2 text-xs font-semibold uppercase tracking-wider text-roicard-text-muted">
            <Gauge className="h-4 w-4 text-roicard-accent" />
            Payment provider
          </p>
          <p className="mt-3 text-sm font-medium capitalize text-roicard-text">
            {snapshot.provider.name}
          </p>
          <p className="mt-1 text-xs text-roicard-text-muted">
            {snapshot.provider.configured ? "Configured" : "Not configured"}
          </p>
        </div>
      </section>

      <section className="space-y-3">
        <h2 className="text-lg font-semibold text-roicard-text">Usage and limits</h2>
        <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
          {Object.values(snapshot.limits)
            .filter((item) => item.key !== "VERIFICATION_MARK")
            .map((item) => (
            <div key={item.key} className="rounded-xl border border-roicard-border bg-roicard-bg-elevated/60 p-4">
              <div className="flex items-center justify-between gap-2">
                <p className="text-sm font-medium text-roicard-text">
                  {LIMIT_LABELS[item.key] ?? item.key}
                </p>
                <span
                  className={cn(
                    "rounded-full px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wider",
                    item.disabled
                      ? "bg-rose-500/15 text-rose-400"
                      : item.limit !== null && item.used >= item.limit
                      ? "bg-amber-500/15 text-amber-300"
                      : "bg-roicard-bg-muted text-roicard-text-muted"
                  )}
                >
                  {usageLabel(item)}
                </span>
              </div>
              {item.limit !== null && !item.disabled && (
                <div className="mt-3 h-1.5 overflow-hidden rounded-full bg-roicard-bg-muted">
                  <div
                    className="h-full rounded-full bg-roicard-primary"
                    style={{ width: `${Math.min(100, (item.used / Math.max(1, item.limit)) * 100)}%` }}
                  />
                </div>
              )}
            </div>
          ))}
        </div>
      </section>

      <section className="space-y-4">
        <h2 className="text-lg font-semibold text-roicard-text">Available plans</h2>
        <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
          {snapshot.plans.map((plan) => (
            <article
              key={plan.id}
              className={cn(
                "flex h-full flex-col rounded-2xl border p-5",
                plan.is_current
                  ? "border-roicard-primary/50 bg-roicard-primary/10"
                  : "border-roicard-border bg-roicard-bg-elevated"
              )}
            >
              <div className="flex items-start justify-between gap-2">
                <h3 className="text-base font-semibold text-roicard-text">{plan.name}</h3>
                {plan.verification_mark && (
                  <BadgeCheck className="h-4 w-4 text-emerald-400" aria-label="Includes verification mark" />
                )}
              </div>
              <p className="mt-1 min-h-[40px] text-sm text-roicard-text-muted">
                {plan.description ?? "Plan details confirmed by ROICARD."}
              </p>
              <p className="mt-3 text-lg font-bold text-roicard-text">
                {plan.price !== null && plan.currency
                  ? `${plan.currency} ${Number(plan.price).toLocaleString()}`
                  : "Pricing not configured"}
                {plan.price !== null && plan.billing_interval && (
                  <span className="text-xs font-normal text-roicard-text-muted">
                    {" "}/ {plan.billing_interval}
                  </span>
                )}
              </p>
              <div className="mt-auto pt-4">
                {plan.is_current ? (
                  <Button fullWidth variant="secondary" disabled className="rounded-xl">
                    <Check className="h-4 w-4" />
                    Current plan
                  </Button>
                ) : (
                  <Button
                    fullWidth
                    className="rounded-xl"
                    disabled={!plan.upgrade_available || busy}
                    onClick={() => upgrade(plan)}
                  >
                    {plan.pricing_configured
                      ? snapshot.provider.configured
                        ? "Choose plan"
                        : "Provider not configured"
                      : "Pricing pending"}
                  </Button>
                )}
              </div>
            </article>
          ))}
        </div>
      </section>
    </div>
  );
}
