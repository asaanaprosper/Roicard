"use client";

import {
  DataTable,
  DataTableCell,
  DataTableRow,
} from "@/components/admin/DataTable";
import { Button } from "@/components/ui/Button";
import { Input } from "@/components/ui/Input";
import { Modal } from "@/components/ui/Modal";
import { Textarea } from "@/components/ui/Textarea";
import {
  approveAdminReferral,
  getAdminReferrals,
  markAdminReferralPaid,
  voidAdminReferral,
  type AdminReferral,
} from "@/lib/api/admin";
import { cn } from "@/lib/cn";
import { Gift } from "lucide-react";
import { useCallback, useEffect, useState } from "react";

export function AdminReferralsView() {
  const [referrals, setReferrals] = useState<AdminReferral[]>([]);
  const [summary, setSummary] = useState<Record<string, unknown>>({});
  const [isLoading, setIsLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [status, setStatus] = useState<string | null>(null);
  const [statusFilter, setStatusFilter] = useState("");
  const [query, setQuery] = useState("");
  const [busyId, setBusyId] = useState<number | null>(null);
  const [voidTarget, setVoidTarget] = useState<AdminReferral | null>(null);
  const [voidReason, setVoidReason] = useState("");
  const [paidTarget, setPaidTarget] = useState<AdminReferral | null>(null);
  const [paidNote, setPaidNote] = useState("");

  const load = useCallback(async () => {
    setIsLoading(true);
    setError(null);
    try {
      const response = await getAdminReferrals({
        status: statusFilter || undefined,
        q: query.trim() || undefined,
        per_page: 50,
      });
      setReferrals(response.referrals.data);
      setSummary(response.summary);
    } catch {
      setError("Unable to load referrals.");
    } finally {
      setIsLoading(false);
    }
  }, [statusFilter, query]);

  useEffect(() => {
    void load();
  }, [load]);

  const approve = async (referral: AdminReferral) => {
    setBusyId(referral.id);
    try {
      const response = await approveAdminReferral(referral.id);
      setStatus(response.message);
      await load();
    } catch (e) {
      const data = (e as { response?: { data?: { message?: string } } })?.response?.data;
      setError(data?.message ?? "Unable to approve the reward.");
    } finally {
      setBusyId(null);
    }
  };

  const voidReward = async () => {
    if (!voidTarget) return;
    setBusyId(voidTarget.id);
    try {
      const response = await voidAdminReferral(voidTarget.id, voidReason.trim());
      setStatus(response.message);
      setVoidTarget(null);
      setVoidReason("");
      await load();
    } catch (e) {
      const data = (e as { response?: { data?: { message?: string } } })?.response?.data;
      setError(data?.message ?? "Unable to void the reward.");
    } finally {
      setBusyId(null);
    }
  };

  const markPaid = async () => {
    if (!paidTarget) return;
    setBusyId(paidTarget.id);
    try {
      const response = await markAdminReferralPaid(paidTarget.id, paidNote.trim());
      setStatus(response.message);
      setPaidTarget(null);
      setPaidNote("");
      await load();
    } catch (e) {
      const data = (e as { response?: { data?: { message?: string } } })?.response?.data;
      setError(data?.message ?? "Unable to mark the reward paid.");
    } finally {
      setBusyId(null);
    }
  };

  return (
    <div className="space-y-8">
      <div>
        <h1 className="flex items-center gap-2 text-2xl font-bold text-roicard-text">
          <Gift className="h-6 w-6 text-roicard-accent" />
          Referrals
        </h1>
        <p className="mt-1 text-sm text-roicard-text-muted">
          Review attributed referrals and append audited ledger corrections. No
          payout provider is connected by this screen.
        </p>
      </div>

      <div className="grid gap-3 rounded-2xl border border-roicard-border bg-roicard-bg-elevated p-4 sm:grid-cols-2 lg:grid-cols-4">
        <Input label="Search" value={query} onChange={(e) => setQuery(e.target.value)} placeholder="Code, referrer or invitee" />
        <div>
          <label htmlFor="referral-status" className="mb-2 block text-sm font-medium text-roicard-text">Status</label>
          <select
            id="referral-status"
            value={statusFilter}
            onChange={(e) => setStatusFilter(e.target.value)}
            className="h-11 w-full rounded-lg border border-roicard-border bg-roicard-bg-muted px-3 text-sm text-roicard-text"
          >
            <option value="">All</option>
            {["attributed", "qualified", "available", "paid", "voided", "expired", "capped"].map((status) => (
              <option key={status} value={status}>{status}</option>
            ))}
          </select>
        </div>
        <div className="flex items-end">
          <Button variant="secondary" onClick={() => void load()} disabled={isLoading}>Refresh</Button>
        </div>
        <div className="flex items-end text-xs text-roicard-text-muted">
          Available value: {String(summary.available_value ?? 0)} · Paid: {String(summary.paid_value ?? 0)}
        </div>
      </div>

      {Boolean(summary.budget_alert) && (
        <p className="rounded-lg border border-amber-500/30 bg-amber-500/10 px-3 py-2 text-sm text-amber-300">
          Referral budget alert threshold reached. Review reward configuration.
        </p>
      )}
      {!Boolean(summary.program_enabled) && (
        <p className="rounded-lg border border-roicard-border bg-roicard-bg-elevated px-3 py-2 text-sm text-roicard-text-muted">
          Referral qualification is not enabled in configuration. Review remains available.
        </p>
      )}
      {status && <p className="rounded-lg border border-emerald-500/30 bg-emerald-500/10 px-3 py-2 text-sm text-emerald-400">{status}</p>}
      {error && <p className="rounded-lg border border-rose-500/30 bg-rose-500/10 px-3 py-2 text-sm text-rose-400">{error}</p>}

      {isLoading ? (
        <div className="flex min-h-[30vh] items-center justify-center">
          <div className="h-8 w-8 animate-spin rounded-full border-2 border-roicard-border border-t-roicard-primary" />
        </div>
      ) : referrals.length === 0 ? (
        <div className="rounded-2xl border border-roicard-border bg-roicard-bg-elevated p-10 text-center text-sm text-roicard-text-muted">
          No referrals match.
        </div>
      ) : (
        <DataTable columns={[
          { key: "referrer", label: "Referrer" }, { key: "invitee", label: "Invitee" },
          { key: "status", label: "Status" }, { key: "risk", label: "Risk" },
          { key: "actions", label: "Actions" },
        ]}>
          {referrals.map((referral) => (
            <DataTableRow key={referral.id}>
              <DataTableCell className="text-xs">
                {referral.referrer ? `${referral.referrer.first_name} ${referral.referrer.last_name}` : `#${referral.referrer_user_id}`}
                <span className="block font-mono text-roicard-text-muted">{referral.referral_code}</span>
              </DataTableCell>
              <DataTableCell className="text-xs">
                {referral.invitee ? `${referral.invitee.first_name} ${referral.invitee.last_name}` : referral.invitee_email ?? "—"}
              </DataTableCell>
              <DataTableCell>
                <span className={cn(
                  "rounded-full px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wider",
                  referral.status === "available" || referral.status === "paid"
                    ? "bg-emerald-500/15 text-emerald-400"
                    : referral.status === "voided" || referral.status === "expired" || referral.status === "capped"
                    ? "bg-rose-500/15 text-rose-400"
                    : "bg-roicard-bg-muted text-roicard-text-muted"
                )}>
                  {referral.status}
                </span>
              </DataTableCell>
              <DataTableCell className="text-xs text-roicard-text-muted">
                {referral.risk_flags?.join(", ") || "—"}
              </DataTableCell>
              <DataTableCell>
                <div className="flex flex-wrap gap-1.5">
                  {referral.status === "qualified" && (
                    <Button size="sm" className="rounded-lg" onClick={() => approve(referral)} disabled={busyId === referral.id}>
                      Approve reward
                    </Button>
                  )}
                  {referral.status !== "voided" && referral.status !== "expired" && referral.status !== "paid" && (
                    <Button size="sm" variant="secondary" className="rounded-lg" onClick={() => setVoidTarget(referral)}>
                      Void
                    </Button>
                  )}
                  {referral.status === "available" && Boolean(summary.payout_enabled) && (
                    <Button size="sm" variant="ghost" className="rounded-lg" onClick={() => setPaidTarget(referral)}>
                      Mark paid
                    </Button>
                  )}
                </div>
              </DataTableCell>
            </DataTableRow>
          ))}
        </DataTable>
      )}

      <Modal
        isOpen={voidTarget !== null}
        onClose={() => setVoidTarget(null)}
        title="Void referral reward?"
        description="A correcting ledger entry is appended and audited. The original entry is never deleted."
        footer={
          <>
            <Button variant="secondary" onClick={() => setVoidTarget(null)} disabled={busyId !== null}>Cancel</Button>
            <Button variant="danger" onClick={voidReward} isLoading={busyId !== null}>Void reward</Button>
          </>
        }
      >
        <Textarea label="Reason" rows={3} value={voidReason} onChange={(e) => setVoidReason(e.target.value)} required />
      </Modal>

      <Modal
        isOpen={paidTarget !== null}
        onClose={() => setPaidTarget(null)}
        title="Mark reward paid"
        description="Only available when reward payouts are explicitly enabled in configuration."
        footer={
          <>
            <Button variant="secondary" onClick={() => setPaidTarget(null)} disabled={busyId !== null}>Cancel</Button>
            <Button onClick={markPaid} isLoading={busyId !== null}>Mark paid</Button>
          </>
        }
      >
        <Textarea label="Payment note / reference" rows={3} value={paidNote} onChange={(e) => setPaidNote(e.target.value)} required />
      </Modal>
    </div>
  );
}
