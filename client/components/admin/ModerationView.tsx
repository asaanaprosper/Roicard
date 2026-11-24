/**
 * ModerationView
 *
 * Private safety queue for platform administrators. Members never see
 * moderation records or notes.
 */

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
  getModerationReports,
  updateModerationReport,
  type ModerationReport,
  type ModerationReportStatus,
} from "@/lib/api/admin";
import { REPORT_REASONS } from "@/lib/api/safety";
import { cn } from "@/lib/cn";
import { Flag, ShieldAlert } from "lucide-react";
import { useCallback, useEffect, useState } from "react";

function formatDate(iso: string | null): string {
  if (!iso) return "—";
  return new Date(iso).toLocaleString(undefined, {
    dateStyle: "medium",
    timeStyle: "short",
  });
}

function memberName(
  member: { first_name: string; last_name: string } | null | undefined
): string {
  if (!member) return "—";
  return `${member.first_name} ${member.last_name}`.trim() || "Member";
}

const STATUS_STYLES: Record<string, string> = {
  pending: "bg-amber-500/15 text-amber-400",
  reviewed: "bg-sky-500/15 text-sky-400",
  actioned: "bg-emerald-500/15 text-emerald-400",
  dismissed: "bg-roicard-bg-muted text-roicard-text-muted",
};

export function ModerationView() {
  const [reports, setReports] = useState<ModerationReport[]>([]);
  const [isLoading, setIsLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [status, setStatus] = useState<string | null>(null);
  const [page, setPage] = useState(1);
  const [lastPage, setLastPage] = useState(1);
  const [total, setTotal] = useState(0);

  const [statusFilter, setStatusFilter] = useState<"pending" | "">("pending");
  const [reasonFilter, setReasonFilter] = useState("");
  const [search, setSearch] = useState("");

  const [selected, setSelected] = useState<ModerationReport | null>(null);
  const [notes, setNotes] = useState("");
  const [busyAction, setBusyAction] = useState<string | null>(null);

  const load = useCallback(async () => {
    setIsLoading(true);
    setError(null);
    try {
      const response = await getModerationReports({
        page,
        per_page: 20,
        status: statusFilter || undefined,
        reason_category: reasonFilter || undefined,
        q: search.trim() || undefined,
      });
      setReports(response.data);
      setLastPage(response.last_page ?? 1);
      setTotal(response.total ?? response.data.length);
    } catch {
      setError("Unable to load the moderation queue.");
    } finally {
      setIsLoading(false);
    }
  }, [page, statusFilter, reasonFilter, search]);

  useEffect(() => {
    void load();
  }, [load]);

  const openReport = (report: ModerationReport) => {
    setSelected(report);
    setNotes(report.admin_notes ?? "");
    setStatus(null);
  };

  const applyUpdate = async (
    action: "review" | "dismiss" | "suspend_user" | "no_action",
    nextStatus: Exclude<ModerationReportStatus, "pending">
  ) => {
    if (!selected) return;

    if (
      action === "suspend_user" &&
      !window.confirm(
        `Suspend ${memberName(selected.reported_user)}? Their account will be deactivated.`
      )
    ) {
      return;
    }

    setBusyAction(action);
    setError(null);
    try {
      const response = await updateModerationReport(selected.id, {
        status: nextStatus,
        action,
        admin_notes: notes.trim() || undefined,
      });
      setSelected(response.report);
      setStatus(response.message);
      await load();
    } catch (e) {
      const data = (e as { response?: { data?: { message?: string } } })?.response?.data;
      setError(data?.message ?? "Unable to update this report.");
    } finally {
      setBusyAction(null);
    }
  };

  return (
    <div className="space-y-8">
      <div>
        <h1 className="flex items-center gap-2 text-2xl font-bold text-roicard-text">
          <ShieldAlert className="h-6 w-6 text-roicard-accent" />
          Safety moderation
        </h1>
        <p className="mt-1 text-sm text-roicard-text-muted">
          Review member reports, record decisions, and take supported moderation
          actions. Every update is written to the audit log.
        </p>
      </div>

      <div className="grid gap-3 rounded-2xl border border-roicard-border bg-roicard-bg-elevated p-4 sm:grid-cols-2 lg:grid-cols-4">
        <div>
          <label htmlFor="moderation-status" className="mb-2 block text-sm font-medium text-roicard-text">
            Status
          </label>
          <select
            id="moderation-status"
            value={statusFilter}
            onChange={(event) => {
              setStatusFilter(event.target.value as "pending" | "");
              setPage(1);
            }}
            className="h-11 w-full rounded-lg border border-roicard-border bg-roicard-bg-muted px-3 text-sm text-roicard-text"
          >
            <option value="pending">Pending review</option>
            <option value="">All statuses</option>
            <option value="reviewed">Reviewed</option>
            <option value="actioned">Actioned</option>
            <option value="dismissed">Dismissed</option>
          </select>
        </div>

        <div>
          <label htmlFor="moderation-reason" className="mb-2 block text-sm font-medium text-roicard-text">
            Reason
          </label>
          <select
            id="moderation-reason"
            value={reasonFilter}
            onChange={(event) => {
              setReasonFilter(event.target.value);
              setPage(1);
            }}
            className="h-11 w-full rounded-lg border border-roicard-border bg-roicard-bg-muted px-3 text-sm text-roicard-text"
          >
            <option value="">All reasons</option>
            {REPORT_REASONS.map((option) => (
              <option key={option.value} value={option.value}>
                {option.label}
              </option>
            ))}
          </select>
        </div>

        <Input
          label="Search"
          placeholder="Member or description"
          value={search}
          onChange={(event) => {
            setSearch(event.target.value);
            setPage(1);
          }}
        />

        <div className="flex items-end justify-between gap-2">
          <Button variant="secondary" onClick={() => void load()} disabled={isLoading}>
            Refresh
          </Button>
          <span className="self-center text-xs text-roicard-text-muted">{total} reports</span>
        </div>
      </div>

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

      {isLoading ? (
        <div className="flex min-h-[30vh] items-center justify-center">
          <div className="h-8 w-8 animate-spin rounded-full border-2 border-roicard-border border-t-roicard-primary" />
        </div>
      ) : reports.length === 0 ? (
        <div className="rounded-2xl border border-roicard-border bg-roicard-bg-elevated p-10 text-center">
          <Flag className="mx-auto h-6 w-6 text-roicard-text-muted" />
          <p className="mt-3 text-sm text-roicard-text-muted">
            No reports match these filters.
          </p>
        </div>
      ) : (
        <>
          <DataTable
            columns={[
              { key: "when", label: "Reported" },
              { key: "reported", label: "Reported member" },
              { key: "reporter", label: "Reporter" },
              { key: "reason", label: "Reason" },
              { key: "status", label: "Status" },
              { key: "actions", label: "" },
            ]}
          >
            {reports.map((report) => (
              <DataTableRow key={report.id}>
                <DataTableCell className="whitespace-nowrap text-xs">
                  {formatDate(report.created_at)}
                </DataTableCell>
                <DataTableCell>
                  <span className="text-sm text-roicard-text">
                    {memberName(report.reported_user)}
                  </span>
                  <span className="block text-xs text-roicard-text-muted">
                    {report.reported_user?.email ?? "—"}
                  </span>
                </DataTableCell>
                <DataTableCell className="text-xs">
                  {memberName(report.reporter)}
                </DataTableCell>
                <DataTableCell className="text-xs capitalize">
                  {report.reason_category.replace(/_/g, " ")}
                </DataTableCell>
                <DataTableCell>
                  <span
                    className={cn(
                      "rounded-full px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wider",
                      STATUS_STYLES[report.status] ?? STATUS_STYLES.dismissed
                    )}
                  >
                    {report.status}
                  </span>
                </DataTableCell>
                <DataTableCell>
                  <Button size="sm" variant="secondary" onClick={() => openReport(report)}>
                    Review
                  </Button>
                </DataTableCell>
              </DataTableRow>
            ))}
          </DataTable>

          <div className="flex items-center justify-between">
            <p className="text-xs text-roicard-text-muted">
              Page {page} of {lastPage}
            </p>
            <div className="flex gap-2">
              <Button
                variant="secondary"
                size="sm"
                disabled={page <= 1}
                onClick={() => setPage((value) => Math.max(1, value - 1))}
              >
                Previous
              </Button>
              <Button
                variant="secondary"
                size="sm"
                disabled={page >= lastPage}
                onClick={() => setPage((value) => Math.min(lastPage, value + 1))}
              >
                Next
              </Button>
            </div>
          </div>
        </>
      )}

      <Modal
        isOpen={selected !== null}
        onClose={() => setSelected(null)}
        title={selected ? `Report #${selected.id}` : "Report"}
        description={
          selected
            ? `Against ${memberName(selected.reported_user)} · ${formatDate(selected.created_at)}`
            : undefined
        }
        className="max-w-2xl"
        footer={
          <>
            <Button variant="secondary" onClick={() => setSelected(null)}>
              Close
            </Button>
            <Button
              variant="secondary"
              onClick={() => applyUpdate("dismiss", "dismissed")}
              isLoading={busyAction === "dismiss"}
            >
              Dismiss
            </Button>
            <Button
              onClick={() => applyUpdate("review", "reviewed")}
              isLoading={busyAction === "review"}
            >
              Mark reviewed
            </Button>
            <Button
              variant="danger"
              onClick={() => applyUpdate("suspend_user", "actioned")}
              isLoading={busyAction === "suspend_user"}
            >
              Suspend member
            </Button>
          </>
        }
      >
        {selected && (
          <div className="space-y-4">
            <div className="grid gap-3 sm:grid-cols-2">
              <div className="rounded-xl border border-roicard-border bg-roicard-bg-muted/40 p-3">
                <p className="text-xs font-semibold uppercase tracking-wide text-roicard-text-muted">
                  Reported member
                </p>
                <p className="mt-1 text-sm text-roicard-text">
                  {memberName(selected.reported_user)}
                </p>
                <p className="text-xs text-roicard-text-muted">
                  {selected.reported_user?.email ?? "—"}
                </p>
              </div>
              <div className="rounded-xl border border-roicard-border bg-roicard-bg-muted/40 p-3">
                <p className="text-xs font-semibold uppercase tracking-wide text-roicard-text-muted">
                  Reporter
                </p>
                <p className="mt-1 text-sm text-roicard-text">
                  {memberName(selected.reporter)}
                </p>
                <p className="text-xs text-roicard-text-muted">
                  {selected.reporter?.email ?? "—"}
                </p>
              </div>
            </div>

            <div>
              <p className="text-xs font-semibold uppercase tracking-wide text-roicard-text-muted">
                Reason
              </p>
              <p className="mt-1 text-sm capitalize text-roicard-text">
                {selected.reason_category.replace(/_/g, " ")}
              </p>
              <p className="mt-2 rounded-lg bg-roicard-bg-muted/50 px-3 py-2 text-sm leading-relaxed text-roicard-text-muted">
                {selected.description || "No description provided."}
              </p>
            </div>

            {selected.connection && (
              <div className="rounded-xl border border-roicard-border p-3 text-sm">
                <p className="text-xs font-semibold uppercase tracking-wide text-roicard-text-muted">
                  Related connection
                </p>
                <p className="mt-1 text-roicard-text">
                  #{selected.connection.id} · {selected.connection.request_type} ·{" "}
                  {selected.connection.status}
                </p>
                {selected.connection.member_note && (
                  <p className="mt-1 text-xs text-roicard-text-muted">
                    Note: {selected.connection.member_note}
                  </p>
                )}
                {selected.connection.match_reason && (
                  <p className="mt-1 text-xs text-roicard-text-muted">
                    Match reason: {selected.connection.match_reason}
                  </p>
                )}
              </div>
            )}

            <Textarea
              label="Admin notes"
              rows={4}
              maxLength={2000}
              value={notes}
              onChange={(event) => setNotes(event.target.value)}
              hint="Visible to administrators only."
            />

            <div className="flex flex-wrap items-center gap-2">
              <span
                className={cn(
                  "rounded-full px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wider",
                  STATUS_STYLES[selected.status] ?? STATUS_STYLES.dismissed
                )}
              >
                {selected.status}
              </span>
              <Button
                size="sm"
                variant="ghost"
                onClick={() => applyUpdate("no_action", "reviewed")}
                isLoading={busyAction === "no_action"}
              >
                No action needed
              </Button>
            </div>
          </div>
        )}
      </Modal>
    </div>
  );
}
