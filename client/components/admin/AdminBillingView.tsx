"use client";

import {
  DataTable,
  DataTableCell,
  DataTableRow,
} from "@/components/admin/DataTable";
import { Button } from "@/components/ui/Button";
import { Input } from "@/components/ui/Input";
import {
  getAdminBillingEvents,
  getAdminBillingPayments,
  getAdminBillingSubscriptions,
  type AdminBillingEvent,
  type AdminBillingPayment,
  type AdminSubscription,
} from "@/lib/api/admin";
import { cn } from "@/lib/cn";
import { CreditCard } from "lucide-react";
import { useCallback, useEffect, useState } from "react";

type Tab = "payments" | "subscriptions" | "events";

export function AdminBillingView() {
  const [tab, setTab] = useState<Tab>("payments");
  const [payments, setPayments] = useState<AdminBillingPayment[]>([]);
  const [subscriptions, setSubscriptions] = useState<AdminSubscription[]>([]);
  const [events, setEvents] = useState<AdminBillingEvent[]>([]);
  const [isLoading, setIsLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [statusFilter, setStatusFilter] = useState("");
  const [purposeFilter, setPurposeFilter] = useState("");
  const [query, setQuery] = useState("");

  const load = useCallback(async () => {
    setIsLoading(true);
    setError(null);
    try {
      if (tab === "payments") {
        const response = await getAdminBillingPayments({
          status: statusFilter || undefined,
          purpose: purposeFilter || undefined,
          q: query.trim() || undefined,
          per_page: 50,
        });
        setPayments(response.data);
      } else if (tab === "subscriptions") {
        const response = await getAdminBillingSubscriptions({
          status: statusFilter || undefined,
          per_page: 50,
        });
        setSubscriptions(response.data);
      } else {
        const response = await getAdminBillingEvents({ per_page: 50 });
        setEvents(response.data);
      }
    } catch {
      setError("Unable to load billing records.");
    } finally {
      setIsLoading(false);
    }
  }, [tab, statusFilter, purposeFilter, query]);

  useEffect(() => {
    void load();
  }, [load]);

  return (
    <div className="space-y-8">
      <div>
        <h1 className="flex items-center gap-2 text-2xl font-bold text-roicard-text">
          <CreditCard className="h-6 w-6 text-roicard-accent" />
          Billing
        </h1>
        <p className="mt-1 text-sm text-roicard-text-muted">
          Read-only payment, subscription and provider-event review. Activation
          requires a verified payment/webhook; this screen cannot mark payments successful.
        </p>
      </div>

      <div className="flex flex-wrap gap-2">
        {(["payments", "subscriptions", "events"] as Tab[]).map((option) => (
          <button
            key={option}
            type="button"
            onClick={() => {
              setTab(option);
              setStatusFilter("");
              setPurposeFilter("");
            }}
            className={cn(
              "rounded-xl border px-4 py-2 text-sm font-medium capitalize",
              tab === option
                ? "border-roicard-primary/40 bg-roicard-primary/10 text-roicard-text"
                : "border-roicard-border bg-roicard-bg-elevated text-roicard-text-muted hover:text-roicard-text"
            )}
          >
            {option}
          </button>
        ))}
      </div>

      {tab !== "events" && (
        <div className="grid gap-3 rounded-2xl border border-roicard-border bg-roicard-bg-elevated p-4 sm:grid-cols-3">
          <div>
            <label htmlFor="billing-status" className="mb-2 block text-sm font-medium text-roicard-text">Status</label>
            <select
              id="billing-status"
              value={statusFilter}
              onChange={(event) => setStatusFilter(event.target.value)}
              className="h-11 w-full rounded-lg border border-roicard-border bg-roicard-bg-muted px-3 text-sm text-roicard-text"
            >
              <option value="">All</option>
              {["pending", "success", "failed", "refunded", "active", "past_due", "cancelled", "expired"].map((status) => (
                <option key={status} value={status}>{status}</option>
              ))}
            </select>
          </div>
          {tab === "payments" && (
            <div>
              <label htmlFor="billing-purpose" className="mb-2 block text-sm font-medium text-roicard-text">Purpose</label>
              <select
                id="billing-purpose"
                value={purposeFilter}
                onChange={(event) => setPurposeFilter(event.target.value)}
                className="h-11 w-full rounded-lg border border-roicard-border bg-roicard-bg-muted px-3 text-sm text-roicard-text"
              >
                <option value="">All</option>
                <option value="membership">Membership</option>
                <option value="subscription">Subscription</option>
                <option value="card_activation">Card activation</option>
              </select>
            </div>
          )}
          {tab === "payments" && (
            <Input
              label="Search"
              value={query}
              onChange={(event) => setQuery(event.target.value)}
              placeholder="Member or reference"
            />
          )}
          <div className="flex items-end">
            <Button variant="secondary" onClick={() => void load()} disabled={isLoading}>Refresh</Button>
          </div>
        </div>
      )}

      {error && <p className="rounded-lg border border-rose-500/30 bg-rose-500/10 px-3 py-2 text-sm text-rose-400">{error}</p>}

      {isLoading ? (
        <div className="flex min-h-[30vh] items-center justify-center">
          <div className="h-8 w-8 animate-spin rounded-full border-2 border-roicard-border border-t-roicard-primary" />
        </div>
      ) : tab === "payments" ? (
        payments.length === 0 ? <Empty /> : (
          <DataTable columns={[
            { key: "date", label: "Date" }, { key: "member", label: "Member" },
            { key: "purpose", label: "Purpose" }, { key: "amount", label: "Amount" },
            { key: "status", label: "Status" }, { key: "reference", label: "Reference" },
          ]}>
            {payments.map((payment) => (
              <DataTableRow key={payment.id}>
                <DataTableCell className="text-xs">{new Date(payment.created_at).toLocaleString()}</DataTableCell>
                <DataTableCell className="text-xs">{payment.user ? `${payment.user.first_name} ${payment.user.last_name}` : `#${payment.user_id}`}</DataTableCell>
                <DataTableCell className="text-xs capitalize">{payment.purpose.replace("_", " ")}</DataTableCell>
                <DataTableCell className="text-xs">{payment.currency} {Number(payment.amount).toLocaleString()}</DataTableCell>
                <DataTableCell className="text-xs capitalize">{payment.status}</DataTableCell>
                <DataTableCell className="font-mono text-xs">{payment.provider_reference ?? "—"}</DataTableCell>
              </DataTableRow>
            ))}
          </DataTable>
        )
      ) : tab === "subscriptions" ? (
        subscriptions.length === 0 ? <Empty /> : (
          <DataTable columns={[
            { key: "member", label: "Member" }, { key: "plan", label: "Plan" },
            { key: "status", label: "Status" }, { key: "end", label: "Period end" },
          ]}>
            {subscriptions.map((subscription) => (
              <DataTableRow key={subscription.id}>
                <DataTableCell className="text-xs">{subscription.user ? `${subscription.user.first_name} ${subscription.user.last_name}` : `#${subscription.user_id}`}</DataTableCell>
                <DataTableCell className="text-xs">{subscription.plan?.name ?? "—"}</DataTableCell>
                <DataTableCell className="text-xs capitalize">{subscription.status}{subscription.cancel_at_period_end ? " · cancels" : ""}</DataTableCell>
                <DataTableCell className="text-xs">{subscription.current_period_end ? new Date(subscription.current_period_end).toLocaleDateString() : "—"}</DataTableCell>
              </DataTableRow>
            ))}
          </DataTable>
        )
      ) : events.length === 0 ? <Empty /> : (
        <DataTable columns={[
          { key: "when", label: "When" }, { key: "event", label: "Event" },
          { key: "member", label: "Member" }, { key: "provider", label: "Provider" },
        ]}>
          {events.map((event) => (
            <DataTableRow key={event.id}>
              <DataTableCell className="text-xs">{new Date(event.created_at).toLocaleString()}</DataTableCell>
              <DataTableCell className="font-mono text-xs">{event.event_type}</DataTableCell>
              <DataTableCell className="text-xs">{event.user ? `${event.user.first_name} ${event.user.last_name}` : "—"}</DataTableCell>
              <DataTableCell className="text-xs">{event.provider ?? "—"}</DataTableCell>
            </DataTableRow>
          ))}
        </DataTable>
      )}
    </div>
  );
}

function Empty() {
  return <div className="rounded-2xl border border-roicard-border bg-roicard-bg-elevated p-10 text-center text-sm text-roicard-text-muted">No records match.</div>;
}
