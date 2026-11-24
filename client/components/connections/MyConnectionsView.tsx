/**
 * MyConnectionsView
 *
 * Main connections list with search, sort, and pagination.
 * Route: /dashboard/connections
 */

"use client";

import { ConnectionCard } from "@/components/connections/ConnectionCard";
import { GuestProfileModal } from "@/components/connections/GuestProfileModal";
import { ConnectionSearchBar } from "@/components/connections/ConnectionSearchBar";
import { useConnections } from "@/components/connections/ConnectionsProvider";
import { ConnectionsEmptyState } from "@/components/connections/ConnectionsEmptyState";
import { ConnectionsLoadingState } from "@/components/connections/ConnectionsLoadingState";
import { ConnectionsSubNav } from "@/components/connections/ConnectionsSubNav";
import { ReportMemberModal } from "@/components/network/ReportMemberModal";
import { Button } from "@/components/ui/Button";
import { useConfirm } from "@/components/ui/ConfirmProvider";
import { blockMember } from "@/lib/api/safety";
import {
  filterConnections,
  getPersonFullName,
  getTotalPages,
  paginate,
  sortConnections,
} from "@/lib/connections/helpers";
import type {
  Connection,
  ConnectionPerson,
  ConnectionSortOption,
} from "@/lib/connections/types";
import { ChevronLeft, ChevronRight } from "lucide-react";
import { useMemo, useState } from "react";

const PAGE_SIZE = 6;

const SORT_OPTIONS: { value: ConnectionSortOption; label: string }[] = [
  { value: "most_recent", label: "Most Recent" },
  { value: "oldest", label: "Oldest" },
  { value: "name_az", label: "Name A-Z" },
];

export function MyConnectionsView() {
  const { connections, isLoading, removeConnection, refresh } = useConnections();
  const { confirm } = useConfirm();
  const [search, setSearch] = useState("");
  const [sort, setSort] = useState<ConnectionSortOption>("most_recent");
  const [page, setPage] = useState(1);
  const [guestPerson, setGuestPerson] = useState<ConnectionPerson | null>(null);
  const [reportTarget, setReportTarget] = useState<Connection | null>(null);
  const [busyId, setBusyId] = useState<string | null>(null);
  const [status, setStatus] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);

  /** Search → sort → paginate pipeline */
  const filtered = useMemo(() => {
    const searched = filterConnections(connections, search);
    return sortConnections(searched, sort);
  }, [connections, search, sort]);

  const totalPages = getTotalPages(filtered.length, PAGE_SIZE);
  const paged = paginate(filtered, page, PAGE_SIZE);

  // Reset to page 1 when search or sort changes
  const handleSearch = (value: string) => {
    setSearch(value);
    setPage(1);
  };

  const handleSort = (value: ConnectionSortOption) => {
    setSort(value);
    setPage(1);
  };

  const handleRemove = async (connection: Connection) => {
    const confirmed = await confirm({
      title: "Remove this connection?",
      description: `You and ${getPersonFullName(
        connection.person
      )} will no longer appear in each other's connections.`,
      confirmLabel: "Remove connection",
      variant: "danger",
    });
    if (!confirmed) return;

    setBusyId(connection.id);
    setError(null);
    try {
      await removeConnection(connection.id);
      setStatus("Connection removed.");
    } catch {
      setError("Unable to remove this connection.");
    } finally {
      setBusyId(null);
    }
  };

  const handleBlock = async (connection: Connection) => {
    const userId = connection.person.userId;
    if (!userId) return;

    const confirmed = await confirm({
      title: "Block this member?",
      description: `You will no longer see ${getPersonFullName(
        connection.person
      )} in search, discovery or your connections, and contact details stay hidden.`,
      confirmLabel: "Block member",
      variant: "danger",
    });
    if (!confirmed) return;

    setBusyId(connection.id);
    setError(null);
    try {
      await blockMember({ userId: Number(userId) });
      await refresh();
      setStatus("Member blocked.");
    } catch {
      setError("Unable to block this member.");
    } finally {
      setBusyId(null);
    }
  };

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-2xl font-bold text-roicard-text">Connections</h1>
        <p className="mt-1 text-sm text-roicard-text-muted">
          Manage your professional network
        </p>
      </div>

      <ConnectionsSubNav />

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

      <div className="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
        <div className="flex-1">
          <ConnectionSearchBar
            value={search}
            onChange={handleSearch}
            resultCount={search ? filtered.length : undefined}
          />
        </div>

        <div className="w-full sm:w-48">
          <label htmlFor="connection-sort" className="mb-2 block text-sm font-medium text-roicard-text">
            Sort by
          </label>
          <select
            id="connection-sort"
            value={sort}
            onChange={(event) =>
              handleSort(event.target.value as ConnectionSortOption)
            }
            className="h-11 w-full rounded-lg border border-roicard-border bg-roicard-bg-muted px-3 text-sm text-roicard-text focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-roicard-accent/40"
          >
            {SORT_OPTIONS.map((option) => (
              <option key={option.value} value={option.value}>
                {option.label}
              </option>
            ))}
          </select>
        </div>
      </div>

      <p className="text-sm text-roicard-text-muted">
        <span className="font-medium text-roicard-text">{connections.length}</span> total
        connection{connections.length === 1 ? "" : "s"}
      </p>

      {isLoading ? (
        <ConnectionsLoadingState />
      ) : paged.length === 0 ? (
        <ConnectionsEmptyState
          title={search ? "No matches found" : "No connections yet"}
          description={
            search
              ? "Try a different search term or clear the filter."
              : "Accept connection requests or share your ROICARD to grow your network."
          }
          actionLabel={search ? undefined : "View requests"}
          actionHref={search ? undefined : "/dashboard/connections/requests"}
        />
      ) : (
        <div className="grid gap-4 sm:grid-cols-2">
          {paged.map((connection) => (
            <ConnectionCard
              key={connection.id}
              connection={connection}
              onViewGuest={setGuestPerson}
              onRemove={handleRemove}
              onBlock={handleBlock}
              onReport={setReportTarget}
              isProcessing={busyId === connection.id}
            />
          ))}
        </div>
      )}

      <GuestProfileModal
        person={guestPerson}
        isOpen={guestPerson !== null}
        onClose={() => setGuestPerson(null)}
      />

      <ReportMemberModal
        isOpen={reportTarget !== null}
        onClose={() => setReportTarget(null)}
        memberName={reportTarget ? getPersonFullName(reportTarget.person) : "this member"}
        connectionId={reportTarget ? Number(reportTarget.id) : undefined}
        onReported={(message) => setStatus(message)}
      />

      {/* Pagination controls */}
      {!isLoading && filtered.length > PAGE_SIZE && (
        <div className="flex items-center justify-between gap-4 pt-2">
          <p className="text-sm text-roicard-text-muted">
            Page {page} of {totalPages}
          </p>
          <div className="flex gap-2">
            <Button
              variant="secondary"
              size="sm"
              className="rounded-lg"
              disabled={page <= 1}
              onClick={() => setPage((p) => p - 1)}
            >
              <ChevronLeft className="h-4 w-4" />
              Prev
            </Button>
            <Button
              variant="secondary"
              size="sm"
              className="rounded-lg"
              disabled={page >= totalPages}
              onClick={() => setPage((p) => p + 1)}
            >
              Next
              <ChevronRight className="h-4 w-4" />
            </Button>
          </div>
        </div>
      )}
    </div>
  );
}
