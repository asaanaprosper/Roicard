"use client";

import { getAdminMetrics, type AdminMetrics } from "@/lib/api/admin";
import { cn } from "@/lib/cn";
import { BarChart3, Building2, CreditCard, Gift, Image, Link2, Search, ShoppingBag, Users } from "lucide-react";
import { useCallback, useEffect, useState } from "react";

const WINDOWS = [7, 30, 90] as const;

export function AdminMetricsView() {
  const [days, setDays] = useState<number>(30);
  const [metrics, setMetrics] = useState<AdminMetrics | null>(null);
  const [isLoading, setIsLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  const load = useCallback(async () => {
    setIsLoading(true);
    setError(null);
    try {
      setMetrics(await getAdminMetrics(days));
    } catch {
      setError("Unable to load pilot metrics.");
    } finally {
      setIsLoading(false);
    }
  }, [days]);

  useEffect(() => {
    void load();
  }, [load]);

  return (
    <div className="space-y-8">
      <div className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div>
          <h1 className="flex items-center gap-2 text-2xl font-bold text-roicard-text">
            <BarChart3 className="h-6 w-6 text-roicard-accent" />
            Pilot metrics
          </h1>
          <p className="mt-1 text-sm text-roicard-text-muted">
            Totals derived from recorded events. No historical values are inferred
            where the event did not exist.
          </p>
        </div>
        <div className="flex gap-2">
          {WINDOWS.map((window) => (
            <button
              key={window}
              type="button"
              onClick={() => setDays(window)}
              className={cn(
                "rounded-xl border px-4 py-2 text-sm font-medium",
                days === window
                  ? "border-roicard-primary/40 bg-roicard-primary/10 text-roicard-text"
                  : "border-roicard-border bg-roicard-bg-elevated text-roicard-text-muted"
              )}
            >
              {window}d
            </button>
          ))}
        </div>
      </div>

      {error && <p className="rounded-lg border border-rose-500/30 bg-rose-500/10 px-3 py-2 text-sm text-rose-400">{error}</p>}

      {isLoading || !metrics ? (
        <div className="flex min-h-[30vh] items-center justify-center">
          <div className="h-8 w-8 animate-spin rounded-full border-2 border-roicard-border border-t-roicard-primary" />
        </div>
      ) : (
        <>
          <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <Metric icon={Users} label="Active members" value={metrics.users.active_members} hint={`${metrics.users.new_members} new in ${days}d`} />
            <Metric icon={Search} label="Profile views" value={metrics.discovery.profile_views} hint={`${metrics.discovery.searches} searches`} />
            <Metric icon={Link2} label="Connections accepted" value={metrics.connections.accepted_total} hint={metrics.connections.acceptance_rate !== null ? `${metrics.connections.acceptance_rate}% acceptance` : "No requests yet"} />
            <Metric icon={ShoppingBag} label="Catalog enquiries" value={metrics.catalog.enquiries_total} hint={`${metrics.catalog.active_items} active items`} />
            <Metric icon={Building2} label="Verified organizations" value={metrics.organizations.verified} hint={`${metrics.organizations.pending} pending`} />
            <Metric icon={Gift} label="Referral signups" value={metrics.referrals.signup_events} hint={`${metrics.referrals.qualification_events} qualified`} />
            <Metric icon={Image} label="Signature cards" value={metrics.signature_cards.generated_total} hint={`${metrics.discovery.signature_scans} signature scans`} />
            <Metric icon={CreditCard} label="Active cards" value={metrics.billing.active_cards} hint={`${metrics.billing.card_activation_payments} activation payments`} />
          </div>

          <section className="grid gap-6 lg:grid-cols-2">
            <div className="rounded-2xl border border-roicard-border bg-roicard-bg-elevated p-5">
              <h2 className="text-base font-semibold text-roicard-text">Profile-view entry sources</h2>
              {metrics.discovery.sources.length === 0 ? (
                <p className="mt-2 text-sm text-roicard-text-muted">No source-attributed views recorded yet.</p>
              ) : (
                <div className="mt-3 space-y-2">
                  {metrics.discovery.sources.map((source) => (
                    <div key={source.source} className="flex items-center justify-between rounded-lg bg-roicard-bg-muted/50 px-3 py-2 text-sm">
                      <span className="capitalize text-roicard-text">{source.source}</span>
                      <span className="font-medium text-roicard-text">{source.count}</span>
                    </div>
                  ))}
                </div>
              )}
              <p className="mt-3 text-xs text-roicard-text-muted">
                QR scans: {metrics.discovery.qr_scans} · Signature scans: {metrics.discovery.signature_scans}
              </p>
            </div>

            <div className="rounded-2xl border border-roicard-border bg-roicard-bg-elevated p-5">
              <h2 className="text-base font-semibold text-roicard-text">Conversions and health</h2>
              <div className="mt-3 space-y-2 text-sm">
                <Row label="Connection requests" value={metrics.connections.requests} />
                <Row label="Pending connections" value={metrics.connections.pending} />
                <Row label="Organization member additions" value={metrics.organizations.member_added_events} />
                <Row label="Affiliations confirmed" value={metrics.organizations.affiliation_confirmed_events} />
                <Row label="Active subscriptions" value={metrics.billing.active_subscriptions} />
                <Row label="Reward value pending/available" value={metrics.referrals.reward_value_pending_available} />
              </div>
            </div>
          </section>

          <section className="rounded-2xl border border-roicard-border bg-roicard-bg-elevated p-5">
            <h2 className="text-base font-semibold text-roicard-text">Recorded event coverage</h2>
            <p className="mt-1 text-xs text-roicard-text-muted">
              These are raw counts for the selected window; zero means no matching event was recorded.
            </p>
            <div className="mt-3 grid gap-2 sm:grid-cols-2 lg:grid-cols-4">
              <Row label="Publishing events" value={metrics.profiles.published_events} />
              <Row label="Searchability disabled" value={metrics.profiles.searchability_disabled_events} />
              <Row label="Signature generations" value={metrics.signature_cards.generated_events} />
              <Row label="Successful payments" value={metrics.billing.successful_payments} />
            </div>
          </section>
        </>
      )}
    </div>
  );
}

function Metric({
  icon: Icon,
  label,
  value,
  hint,
}: {
  icon: typeof Users;
  label: string;
  value: number;
  hint: string;
}) {
  return (
    <div className="rounded-2xl border border-roicard-border bg-roicard-bg-elevated p-4">
      <p className="flex items-center gap-2 text-xs font-semibold uppercase tracking-wider text-roicard-text-muted">
        <Icon className="h-4 w-4 text-roicard-accent" />
        {label}
      </p>
      <p className="mt-2 text-2xl font-bold text-roicard-text">{Number(value).toLocaleString()}</p>
      <p className="mt-1 text-xs text-roicard-text-muted">{hint}</p>
    </div>
  );
}

function Row({ label, value }: { label: string; value: number }) {
  return (
    <div className="flex items-center justify-between gap-3 border-b border-roicard-border/60 py-2 last:border-0">
      <span className="text-roicard-text-muted">{label}</span>
      <span className="font-medium text-roicard-text">{Number(value).toLocaleString()}</span>
    </div>
  );
}
