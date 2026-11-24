"use client";

import { Button } from "@/components/ui/Button";
import {
  getReferrals,
  updateReferralSettings,
  type ReferralSummary,
} from "@/lib/api/referrals";
import { cn } from "@/lib/cn";
import { Copy, Gift, MessageCircle, QrCode, Share2, Sparkles } from "lucide-react";
import { useCallback, useEffect, useState } from "react";

export function ReferralView() {
  const [summary, setSummary] = useState<ReferralSummary | null>(null);
  const [isLoading, setIsLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [status, setStatus] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  const load = useCallback(async () => {
    setIsLoading(true);
    try {
      setSummary(await getReferrals());
    } catch {
      setError("Unable to load referrals.");
    } finally {
      setIsLoading(false);
    }
  }, []);

  useEffect(() => {
    void load();
  }, [load]);

  const copy = async () => {
    if (!summary) return;
    try {
      await navigator.clipboard.writeText(summary.link);
      setStatus("Referral link copied.");
    } catch {
      setError("Unable to copy the link.");
    }
  };

  const share = async () => {
    if (!summary) return;
    if (navigator.share) {
      try {
        await navigator.share({
          title: "Join ROICARD",
          text: "Join me on ROICARD.",
          url: summary.link,
        });
        return;
      } catch {
        // fall back to copy
      }
    }
    await copy();
  };

  const shareWhatsApp = () => {
    if (!summary) return;
    const text = encodeURIComponent(`Join me on ROICARD: ${summary.link}`);
    window.open(`https://wa.me/?text=${text}`, "_blank", "noopener,noreferrer");
  };

  const toggleSignature = async (enabled: boolean) => {
    setBusy(true);
    setError(null);
    try {
      const response = await updateReferralSettings({ signature_referral_enabled: enabled });
      setStatus(response.message);
      await load();
    } catch (e) {
      const data = (e as { response?: { data?: { message?: string } } })?.response?.data;
      setError(data?.message ?? "Unable to update the referral setting.");
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

  if (!summary) {
    return <p className="rounded-lg border border-rose-500/30 bg-rose-500/10 px-3 py-2 text-sm text-rose-400">{error ?? "Referral information unavailable."}</p>;
  }

  return (
    <div className="space-y-8">
      <header className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div>
          <span className="inline-flex items-center gap-2 rounded-full bg-roicard-primary/15 px-3 py-1 text-xs font-medium text-roicard-accent">
            <Gift className="h-3.5 w-3.5" />
            Refer-to-earn
          </span>
          <h1 className="mt-3 text-2xl font-bold text-roicard-text sm:text-3xl">Referrals</h1>
          <p className="mt-2 text-sm text-roicard-text-muted sm:text-base">
            Share your personal link. Attribution is retained for {summary.attribution_days} days.
          </p>
        </div>
      </header>

      {!summary.enabled && (
        <p className="rounded-lg border border-amber-500/30 bg-amber-500/10 px-3 py-2 text-sm text-amber-300">
          The referral program is not enabled yet. Your link can be shared, but
          qualification and rewards remain disabled until ROICARD configures them.
        </p>
      )}
      {status && <p className="rounded-lg border border-emerald-500/30 bg-emerald-500/10 px-3 py-2 text-sm text-emerald-400">{status}</p>}
      {error && <p className="rounded-lg border border-rose-500/30 bg-rose-500/10 px-3 py-2 text-sm text-rose-400">{error}</p>}

      <section className="grid gap-4 lg:grid-cols-[minmax(0,1fr)_320px]">
        <div className="space-y-4">
          <div className="rounded-2xl border border-roicard-border bg-roicard-bg-elevated p-5">
            <p className="text-xs font-semibold uppercase tracking-wider text-roicard-text-muted">Your referral link</p>
            <div className="mt-2 flex flex-wrap items-center gap-2">
              <code className="min-w-0 flex-1 truncate rounded-lg bg-roicard-bg-muted px-3 py-2 text-sm text-roicard-text">
                {summary.link}
              </code>
              <Button size="sm" variant="secondary" className="rounded-lg" onClick={copy}>
                <Copy className="h-3.5 w-3.5" />
                Copy
              </Button>
              <Button size="sm" className="rounded-lg" onClick={share}>
                <Share2 className="h-3.5 w-3.5" />
                Share
              </Button>
              <Button size="sm" variant="secondary" className="rounded-lg" onClick={shareWhatsApp}>
                <MessageCircle className="h-3.5 w-3.5" />
                WhatsApp
              </Button>
            </div>
            <p className="mt-3 text-xs text-roicard-text-muted">
              Code: <span className="font-mono text-roicard-text">{summary.code}</span>
            </p>
          </div>

          <div className="grid gap-4 sm:grid-cols-3">
            {[
              { label: "Available", value: summary.balance.available },
              { label: "Pending", value: summary.balance.pending },
              { label: "Paid", value: summary.balance.paid },
            ].map((item) => (
              <div key={item.label} className="rounded-2xl border border-roicard-border bg-roicard-bg-elevated p-4">
                <p className="text-xs uppercase tracking-wider text-roicard-text-muted">{item.label}</p>
                <p className="mt-1 text-lg font-bold text-roicard-text">
                  {summary.balance.currency} {Number(item.value).toLocaleString()}
                </p>
              </div>
            ))}
          </div>

          <div className="rounded-2xl border border-roicard-border bg-roicard-bg-elevated p-4">
            <div className="flex flex-wrap items-center justify-between gap-3">
              <div>
                <p className="text-sm font-medium text-roicard-text">Referral attribution on signature cards</p>
                <p className="text-xs text-roicard-text-muted">
                  Off by default. When enabled, signature-card QR codes include your referral code.
                </p>
              </div>
              <label className="inline-flex items-center gap-2 text-sm text-roicard-text">
                <input
                  type="checkbox"
                  checked={summary.signature_referral_enabled}
                  onChange={(event) => void toggleSignature(event.target.checked)}
                  disabled={busy}
                  className="h-4 w-4 rounded border-roicard-border"
                />
                Enable
              </label>
            </div>
            <p className="mt-2 text-xs text-roicard-text-muted">
              This also appears on the Signature cards screen and only affects QR
              attribution, not your public profile link.
            </p>
          </div>

          <div className="space-y-3">
            <h2 className="text-lg font-semibold text-roicard-text">Referral history</h2>
            {summary.referrals.length === 0 ? (
              <div className="rounded-2xl border border-dashed border-roicard-border bg-roicard-bg-elevated p-6 text-sm text-roicard-text-muted">
                No referrals attributed yet.
              </div>
            ) : (
              <div className="space-y-2">
                {summary.referrals.map((referral) => (
                  <div key={referral.id} className="rounded-xl border border-roicard-border bg-roicard-bg-elevated px-4 py-3">
                    <div className="flex flex-wrap items-center justify-between gap-2">
                      <div>
                        <p className="text-sm text-roicard-text">
                          {referral.invitee
                            ? `${referral.invitee.first_name} ${referral.invitee.last_name}`
                            : "Invitee"}
                        </p>
                        <p className="text-xs text-roicard-text-muted">
                          Attributed {referral.attributed_at ? new Date(referral.attributed_at).toLocaleDateString() : "—"}
                          {referral.expires_at ? ` · window ends ${new Date(referral.expires_at).toLocaleDateString()}` : ""}
                        </p>
                      </div>
                      <span
                        className={cn(
                          "rounded-full px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wider",
                          referral.status === "available" || referral.status === "paid"
                            ? "bg-emerald-500/15 text-emerald-400"
                            : referral.status === "voided" || referral.status === "expired" || referral.status === "capped"
                            ? "bg-rose-500/15 text-rose-400"
                            : "bg-roicard-bg-muted text-roicard-text-muted"
                        )}
                      >
                        {referral.status}
                      </span>
                    </div>
                  </div>
                ))}
              </div>
            )}
          </div>

          <div className="space-y-3">
            <h2 className="text-lg font-semibold text-roicard-text">Reward ledger</h2>
            {summary.ledger.length === 0 ? (
              <div className="rounded-2xl border border-dashed border-roicard-border bg-roicard-bg-elevated p-6 text-sm text-roicard-text-muted">
                No reward entries yet. Qualification and reward rules are founder-configured.
              </div>
            ) : (
              <div className="overflow-hidden rounded-2xl border border-roicard-border">
                <table className="w-full text-left text-sm">
                  <thead className="bg-roicard-bg-muted text-xs uppercase tracking-wider text-roicard-text-muted">
                    <tr>
                      <th className="px-4 py-2">Date</th>
                      <th className="px-4 py-2">Status</th>
                      <th className="px-4 py-2">Amount</th>
                      <th className="px-4 py-2">Balance impact</th>
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-roicard-border bg-roicard-bg-elevated">
                    {summary.ledger.map((entry) => (
                      <tr key={entry.id}>
                        <td className="px-4 py-2 text-xs text-roicard-text-muted">
                          {new Date(entry.created_at).toLocaleDateString()}
                        </td>
                        <td className="px-4 py-2 capitalize text-roicard-text">{entry.status}</td>
                        <td className="px-4 py-2 text-roicard-text">
                          {entry.currency ?? summary.balance.currency} {Number(entry.amount ?? 0).toLocaleString()}
                        </td>
                        <td className={cn(
                          "px-4 py-2 text-roicard-text",
                          Number(entry.delta_amount) < 0 && "text-rose-400"
                        )}>
                          {Number(entry.delta_amount) > 0 ? "+" : ""}
                          {Number(entry.delta_amount).toLocaleString()}
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            )}
          </div>
        </div>

        <aside className="space-y-4">
          <div className="rounded-2xl border border-roicard-border bg-roicard-bg-elevated p-4">
            <p className="flex items-center gap-2 text-sm font-semibold text-roicard-text">
              <QrCode className="h-4 w-4 text-roicard-accent" />
              Referral QR
            </p>
            <div
              className="mx-auto mt-3 w-full max-w-[220px] rounded-xl bg-white p-3"
              dangerouslySetInnerHTML={{ __html: summary.qr_svg }}
            />
            <p className="mt-2 text-center text-xs text-roicard-text-muted">
              Opens the registration page with your code.
            </p>
          </div>

          <div className="rounded-2xl border border-roicard-border bg-roicard-bg-elevated p-4">
            <p className="flex items-center gap-2 text-sm font-semibold text-roicard-text">
              <Sparkles className="h-4 w-4 text-roicard-accent" />
              Program state
            </p>
            <p className="mt-2 text-xs text-roicard-text-muted">
              Qualification: {summary.configured ? "configured" : "not configured"}. Payouts:{" "}
              {summary.reward.payout_enabled ? "enabled" : "disabled"}.
            </p>
            <p className="mt-1 text-xs text-roicard-text-muted">
              Reward: {summary.reward.amount ?? "Not set"} {summary.reward.currency}
            </p>
            {summary.refund_window_days !== null && summary.refund_window_days !== undefined && (
              <p className="mt-1 text-xs text-roicard-text-muted">
                Rewards release after the configured {String(summary.refund_window_days)}-day refund window.
              </p>
            )}
          </div>
        </aside>
      </section>
    </div>
  );
}
