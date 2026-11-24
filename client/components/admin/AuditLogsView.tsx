/**
 * AuditLogsView
 *
 * Read-only view of the reusable audit trail for administrative and
 * security-sensitive actions. Route: /admin/audit-logs
 */

"use client";

import {
  DataTable,
  DataTableCell,
  DataTableRow,
} from "@/components/admin/DataTable";
import { Button } from "@/components/ui/Button";
import { Input } from "@/components/ui/Input";
import {
  getAdminAuditLogs,
  type AdminAuditLogEntryV2,
} from "@/lib/api/admin";
import { cn } from "@/lib/cn";
import { ScrollText } from "lucide-react";
import { useCallback, useEffect, useState } from "react";

function formatDate(iso: string | null): string {
  if (!iso) return "—";
  return new Date(iso).toLocaleString(undefined, {
    dateStyle: "medium",
    timeStyle: "short",
  });
}

export function AuditLogsView() {
  const [logs, setLogs] = useState<AdminAuditLogEntryV2[]>([]);
  const [isLoading, setIsLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [page, setPage] = useState(1);
  const [lastPage, setLastPage] = useState(1);
  const [total, setTotal] = useState(0);

  const [action, setAction] = useState("");
  const [targetType, setTargetType] = useState("");
  const [targetId, setTargetId] = useState("");
  const [from, setFrom] = useState("");
  const [to, setTo] = useState("");
  const [search, setSearch] = useState("");

  const load = useCallback(async () => {
    setIsLoading(true);
    setError(null);
    try {
      const response = await getAdminAuditLogs({
        page,
        per_page: 30,
        action: action.trim() || undefined,
        target_type: targetType.trim() || undefined,
        target_id: targetId.trim() || undefined,
        from: from || undefined,
        to: to || undefined,
        q: search.trim() || undefined,
      });
      setLogs(response.data);
      setLastPage(response.last_page ?? 1);
      setTotal(response.total ?? response.data.length);
    } catch {
      setError("Unable to load audit logs.");
    } finally {
      setIsLoading(false);
    }
  }, [page, action, targetType, targetId, from, to, search]);

  useEffect(() => {
    load();
  }, [load]);

  const applyFilters = () => {
    // Filters are part of the load effect's dependencies; resetting the page
    // applies the current values, and an explicit reload supports re-running
    // the same filter after new activity.
    setPage(1);
    void load();
  };

  const clearFilters = () => {
    setAction("");
    setTargetType("");
    setTargetId("");
    setFrom("");
    setTo("");
    setSearch("");
    setPage(1);
  };

  const columns = [
    { key: "when", label: "When" },
    { key: "actor", label: "Actor" },
    { key: "action", label: "Action" },
    { key: "target", label: "Target" },
    { key: "metadata", label: "Metadata" },
  ];

  return (
    <div className="space-y-8">
      <div>
        <h1 className="flex items-center gap-2 text-2xl font-bold text-roicard-text">
          <ScrollText className="h-6 w-6 text-roicard-accent" />
          Audit Logs
        </h1>
        <p className="mt-1 text-sm text-roicard-text-muted">
          Administrative and security-sensitive actions, with actor, action,
          target and structured metadata. This view is read-only.
        </p>
      </div>

      <div className="grid gap-3 rounded-2xl border border-roicard-border bg-roicard-bg-elevated p-4 sm:grid-cols-2 lg:grid-cols-4">
        <Input
          label="Search"
          placeholder="Action, target…"
          value={search}
          onChange={(e) => setSearch(e.target.value)}
        />
        <Input
          label="Action contains"
          placeholder="e.g. category."
          value={action}
          onChange={(e) => setAction(e.target.value)}
        />
        <Input
          label="Target type"
          placeholder="e.g. Category"
          value={targetType}
          onChange={(e) => setTargetType(e.target.value)}
        />
        <Input
          label="Target id"
          value={targetId}
          onChange={(e) => setTargetId(e.target.value)}
        />
        <Input label="From" type="date" value={from} onChange={(e) => setFrom(e.target.value)} />
        <Input label="To" type="date" value={to} onChange={(e) => setTo(e.target.value)} />
        <div className="flex items-end gap-2 lg:col-span-2">
          <Button onClick={applyFilters}>Apply filters</Button>
          <Button variant="secondary" onClick={clearFilters}>
            Clear
          </Button>
          <span className="ml-auto self-center text-xs text-roicard-text-muted">
            {total} entries
          </span>
        </div>
      </div>

      {error && (
        <p className="rounded-lg bg-rose-500/10 px-3 py-2 text-sm text-rose-500">
          {error}
        </p>
      )}

      {isLoading ? (
        <div className="flex min-h-[30vh] items-center justify-center">
          <div className="h-8 w-8 animate-spin rounded-full border-2 border-roicard-border border-t-roicard-primary" />
        </div>
      ) : logs.length === 0 ? (
        <div className="rounded-2xl border border-roicard-border bg-roicard-bg-elevated p-10 text-center">
          <p className="text-sm text-roicard-text-muted">No audit entries match.</p>
        </div>
      ) : (
        <>
          <DataTable columns={columns}>
            {logs.map((log) => (
              <DataTableRow key={log.id}>
                <DataTableCell className="whitespace-nowrap text-xs">
                  {formatDate(log.created_at)}
                </DataTableCell>
                <DataTableCell>
                  {log.actor ? (
                    <span className="text-roicard-text">
                      {log.actor.first_name} {log.actor.last_name}
                      <span className="block text-xs text-roicard-text-muted">
                        {log.actor.email}
                      </span>
                    </span>
                  ) : (
                    <span className="text-xs">System / #{log.actor_id ?? "—"}</span>
                  )}
                </DataTableCell>
                <DataTableCell>
                  <span
                    className={cn(
                      "rounded-md bg-roicard-bg-muted px-2 py-1 font-mono text-xs text-roicard-text"
                    )}
                  >
                    {log.action}
                  </span>
                </DataTableCell>
                <DataTableCell className="text-xs">
                  <span className="block capitalize">{log.target_type ?? "—"}</span>
                  <span className="font-mono text-roicard-text-muted">
                    {log.target_id ?? "—"}
                  </span>
                </DataTableCell>
                <DataTableCell>
                  {log.metadata ? (
                    <details>
                      <summary className="cursor-pointer text-xs text-roicard-accent">
                        View
                      </summary>
                      <pre className="mt-2 max-h-40 max-w-md overflow-auto rounded-lg bg-roicard-bg-muted p-2 text-[11px] leading-relaxed">
                        {JSON.stringify(log.metadata, null, 2)}
                      </pre>
                    </details>
                  ) : (
                    <span className="text-xs text-roicard-text-muted">—</span>
                  )}
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
    </div>
  );
}
