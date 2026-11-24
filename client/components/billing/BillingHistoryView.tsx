"use client";

import { Button } from "@/components/ui/Button";
import { useConfirm } from "@/components/ui/ConfirmProvider";
import {
  cancelSubscription,
  getBillingHistory,
  resumeSubscription,
  type BillingHistory,
} from "@/lib/api/billing";
import { CreditCard, ReceiptText, RotateCcw } from "lucide-react";
import Link from "next/link";
import { useCallback, useEffect, useState } from "react";

function money(amount: string | number, currency: string): string {
  return `${currency} ${Number(amount).toLocaleString(undefined, { minimumFractionDigits: 2 })}`;
}

export function BillingHistoryView() {
  const { confirm } = useConfirm();
  const [history, setHistory] = useState<BillingHistory | null>(null);
  const [isLoading, setIsLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [status, setStatus] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  const load = useCallback(async () => {
    setIsLoading(true);
    try {
      setHistory(await getBillingHistory());
    } catch {
      setError("Unable to load billing history.");
    } finally {
      setIsLoading(false);
    }
  }, []);

  useEffect(() => {
    void load();
  }, [load]);

  const cancel = async () => {
    const ok = await confirm({
      title: "Cancel subscription?",
      description: "Access remains until the end of the current period, then returns to Free limits.",
      confirmLabel: "Cancel at period end",
      variant: "danger",
    });
    if (!ok) return;

    setBusy(true);
    try {
      const response = await cancelSubscription();
      setStatus(response.message);
      await load();
    } catch (e) {
      const data = (e as { response?: { data?: { message?: string } } })?.response?.data;
      setError(data?.message ?? "Unable to cancel subscription.");
    } finally {
      setBusy(false);
    }
  };

  const resume = async () => {
    setBusy(true);
    try {
      const response = await resumeSubscription();
      setStatus(response.message);
      await load();
    } catch (e) {
      const data = (e as { response?: { data?: { message?: string } } })?.response?.data;
      setError(data?.message ?? "Unable to resume subscription.");
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

  const subscriptionActive = Boolean(
    history?.subscription && ["active", "past_due"].includes(history.subscription.status)
  );

  return (
    <div className="space-y-8">
      <header className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div>
          <span className="inline-flex items-center gap-2 rounded-full bg-roicard-primary/15 px-3 py-1 text-xs font-medium text-roicard-accent">
            <CreditCard className="h-3.5 w-3.5" />
            Billing
          </span>
          <h1 className="mt-3 text-2xl font-bold text-roicard-text sm:text-3xl">
            Billing history
          </h1>
          <p className="mt-2 text-sm text-roicard-text-muted sm:text-base">
            Verified payments, subscription state and billing events.
          </p>
        </div>
        <Link href="/dashboard/plan">
          <Button variant="secondary" className="rounded-xl">Plan &amp; usage</Button>
        </Link>
      </header>

      {status && (
        <p className="rounded-lg border border-emerald-500/30 bg-emerald-500/10 px-3 py-2 text-sm text-emerald-400">{status}</p>
      )}
      {error && (
        <p className="rounded-lg border border-rose-500/30 bg-rose-500/10 px-3 py-2 text-sm text-rose-400">{error}</p>
      )}

      <section className="rounded-2xl border border-roicard-border bg-roicard-bg-elevated p-5">
        <h2 className="text-base font-semibold text-roicard-text">Subscription</h2>
        {history?.subscription ? (
          <div className="mt-3 flex flex-wrap items-center justify-between gap-3">
            <div>
              <p className="text-sm font-medium text-roicard-text">
                {history.subscription.plan?.name ?? "No plan"} ·{" "}
                <span className="capitalize">{history.subscription.status.replace("_", " ")}</span>
              </p>
              <p className="text-xs text-roicard-text-muted">
                {history.subscription.current_period_end
                  ? `Current period ends ${new Date(history.subscription.current_period_end).toLocaleDateString()}`
                  : "No configured period end"}
                {history.subscription.cancel_at_period_end ? " · cancels at period end" : ""}
              </p>
            </div>
            {subscriptionActive ? (
              history.subscription.cancel_at_period_end ? (
                <Button variant="secondary" size="sm" className="rounded-lg" onClick={resume} isLoading={busy}>
                  <RotateCcw className="h-3.5 w-3.5" />
                  Resume renewal
                </Button>
              ) : (
                <Button variant="ghost" size="sm" className="rounded-lg" onClick={cancel} isLoading={busy}>
                  Cancel at period end
                </Button>
              )
            ) : (
              <span className="text-xs capitalize text-roicard-text-muted">
                {history.subscription.status.replace("_", " ")}
              </span>
            )}
          </div>
        ) : (
          <p className="mt-2 text-sm text-roicard-text-muted">No subscription history.</p>
        )}
      </section>

      <section className="space-y-3">
        <h2 className="flex items-center gap-2 text-lg font-semibold text-roicard-text">
          <ReceiptText className="h-5 w-5 text-roicard-accent" />
          Payments
        </h2>
        {!history?.payments.length ? (
          <div className="rounded-2xl border border-dashed border-roicard-border bg-roicard-bg-elevated p-6 text-sm text-roicard-text-muted">
            No payments recorded yet.
          </div>
        ) : (
          <div className="overflow-hidden rounded-2xl border border-roicard-border">
            <table className="w-full text-left text-sm">
              <thead className="bg-roicard-bg-muted text-xs uppercase tracking-wider text-roicard-text-muted">
                <tr>
                  <th className="px-4 py-3">Date</th>
                  <th className="px-4 py-3">Purpose</th>
                  <th className="px-4 py-3">Amount</th>
                  <th className="px-4 py-3">Status</th>
                  <th className="px-4 py-3">Reference</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-roicard-border bg-roicard-bg-elevated">
                {history.payments.map((payment) => (
                  <tr key={payment.id}>
                    <td className="px-4 py-3 text-roicard-text-muted">
                      {new Date(payment.created_at).toLocaleDateString()}
                    </td>
                    <td className="px-4 py-3 capitalize text-roicard-text">
                      {payment.purpose.replace("_", " ")}
                    </td>
                    <td className="px-4 py-3 text-roicard-text">
                      {money(payment.amount, payment.currency)}
                    </td>
                    <td className="px-4 py-3 capitalize text-roicard-text-muted">{payment.status}</td>
                    <td className="px-4 py-3 font-mono text-xs text-roicard-text-muted">
                      {payment.provider_reference ?? "—"}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </section>

      <section className="space-y-3">
        <h2 className="text-lg font-semibold text-roicard-text">Billing events</h2>
        {!history?.events.length ? (
          <div className="rounded-2xl border border-dashed border-roicard-border bg-roicard-bg-elevated p-6 text-sm text-roicard-text-muted">
            No billing events yet.
          </div>
        ) : (
          <div className="space-y-2">
            {history.events.map((event) => (
              <div key={event.id} className="flex flex-wrap items-center justify-between gap-2 rounded-xl border border-roicard-border bg-roicard-bg-elevated px-4 py-3">
                <span className="font-mono text-xs text-roicard-text">{event.event_type}</span>
                <span className="text-xs text-roicard-text-muted">
                  {event.processed_at ? new Date(event.processed_at).toLocaleString() : "Pending"}
                  {event.provider ? ` · ${event.provider}` : ""}
                </span>
              </div>
            ))}
          </div>
        )}
      </section>
    </div>
  );
}
