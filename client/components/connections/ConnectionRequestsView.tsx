/**
 * ConnectionRequestsView
 *
 * Incoming connection requests with accept/decline actions.
 * Route: /dashboard/connections/requests
 */

"use client";

import { ConnectionRequestCard } from "@/components/connections/ConnectionRequestCard";
import { GuestProfileModal } from "@/components/connections/GuestProfileModal";
import { SentRequestCard } from "@/components/connections/SentRequestCard";
import { useConnections } from "@/components/connections/ConnectionsProvider";
import { ConnectionsEmptyState } from "@/components/connections/ConnectionsEmptyState";
import { ConnectionsLoadingState } from "@/components/connections/ConnectionsLoadingState";
import { ConnectionsSubNav } from "@/components/connections/ConnectionsSubNav";
import { ReportMemberModal } from "@/components/network/ReportMemberModal";
import { useConfirm } from "@/components/ui/ConfirmProvider";
import { blockMember } from "@/lib/api/safety";
import { getPersonFullName, sortRequests } from "@/lib/connections/helpers";
import type {
  ConnectionPerson,
  IncomingConnectionRequest,
} from "@/lib/connections/types";
import { useMemo, useState } from "react";

export function ConnectionRequestsView() {
  const {
    requests,
    sentRequests,
    isLoading,
    acceptRequest,
    declineRequest,
    withdrawRequest,
    refresh,
  } = useConnections();
  const { confirm } = useConfirm();
  const [processingId, setProcessingId] = useState<string | null>(null);
  const [guestPerson, setGuestPerson] = useState<ConnectionPerson | null>(null);
  const [reportTarget, setReportTarget] = useState<IncomingConnectionRequest | null>(null);
  const [status, setStatus] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);

  const sortedRequests = useMemo(() => sortRequests(requests), [requests]);

  /** Accept flow — confirm then accept through the correct backend flow. */
  const handleAccept = async (requestId: string) => {
    const request = requests.find((r) => r.id === requestId);
    if (!request) return;

    const confirmed = await confirm({
      title: "Accept connection?",
      description: `Add ${getPersonFullName(request.person)} to your connections.`,
      confirmLabel: "Accept",
    });

    if (!confirmed) return;

    setProcessingId(requestId);
    setError(null);
    try {
      await acceptRequest(requestId, request.requestType ?? "guest");
      setStatus("Connection accepted.");
    } catch {
      setError("Unable to accept this request.");
    } finally {
      setProcessingId(null);
    }
  };

  /** Decline flow — confirm then decline without notifying the sender. */
  const handleDecline = async (requestId: string) => {
    const request = requests.find((r) => r.id === requestId);
    if (!request) return;

    const confirmed = await confirm({
      title: "Decline connection request?",
      description: `This will remove the request from ${getPersonFullName(request.person)}.`,
      confirmLabel: "Decline",
      variant: "danger",
    });

    if (!confirmed) return;

    setProcessingId(requestId);
    setError(null);
    try {
      await declineRequest(requestId, request.requestType ?? "guest");
      setStatus("Connection request declined.");
    } catch {
      setError("Unable to decline this request.");
    } finally {
      setProcessingId(null);
    }
  };

  const handleWithdraw = async (request: IncomingConnectionRequest) => {
    const confirmed = await confirm({
      title: "Withdraw this request?",
      description: `Your request to ${getPersonFullName(request.person)} will be cancelled.`,
      confirmLabel: "Withdraw",
      variant: "danger",
    });
    if (!confirmed) return;

    setProcessingId(request.id);
    setError(null);
    try {
      await withdrawRequest(request.id);
      setStatus("Connection request withdrawn.");
    } catch {
      setError("Unable to withdraw this request.");
    } finally {
      setProcessingId(null);
    }
  };

  const handleBlock = async (request: IncomingConnectionRequest) => {
    const userId = request.person.userId;
    if (!userId) return;

    const confirmed = await confirm({
      title: "Block this member?",
      description: `You will no longer see ${getPersonFullName(
        request.person
      )} in search, discovery or connection requests.`,
      confirmLabel: "Block member",
      variant: "danger",
    });
    if (!confirmed) return;

    setProcessingId(request.id);
    setError(null);
    try {
      await blockMember({ userId: Number(userId) });
      await refresh();
      setStatus("Member blocked.");
    } catch {
      setError("Unable to block this member.");
    } finally {
      setProcessingId(null);
    }
  };

  return (
    <div className="space-y-6">
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div>
          <h1 className="text-2xl font-bold text-roicard-text">Connection Requests</h1>
          <p className="mt-1 text-sm text-roicard-text-muted">
            Review and respond to incoming requests
          </p>
        </div>
        {requests.length > 0 && (
          <span className="inline-flex items-center rounded-full bg-roicard-primary/15 px-3 py-1 text-sm font-medium text-roicard-accent">
            {requests.length} pending
          </span>
        )}
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

      {isLoading ? (
        <ConnectionsLoadingState />
      ) : sortedRequests.length === 0 && sentRequests.length === 0 ? (
        <ConnectionsEmptyState
          title="No pending requests"
          description="When someone sends you a connection request, it will appear here for you to accept or decline."
          actionLabel="View connections"
          actionHref="/dashboard/connections"
        />
      ) : (
        <div className="space-y-6">
          {sortedRequests.length > 0 && (
            <div className="space-y-4">
              <h2 className="text-sm font-semibold uppercase tracking-wide text-roicard-text-muted">
                Received
              </h2>
              {sortedRequests.map((request) => (
                <ConnectionRequestCard
                  key={request.id}
                  request={request}
                  onAccept={handleAccept}
                  onDecline={handleDecline}
                  onViewGuest={setGuestPerson}
                  onBlock={handleBlock}
                  onReport={setReportTarget}
                  isProcessing={processingId === request.id}
                />
              ))}
            </div>
          )}

          {sentRequests.length > 0 && (
            <div className="space-y-4">
              <h2 className="text-sm font-semibold uppercase tracking-wide text-roicard-text-muted">
                Sent — waiting for response
              </h2>
              {sentRequests.map((request) => (
                <SentRequestCard
                  key={request.id}
                  request={request}
                  onWithdraw={handleWithdraw}
                  onBlock={handleBlock}
                  isProcessing={processingId === request.id}
                />
              ))}
            </div>
          )}
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
        memberName={
          reportTarget ? getPersonFullName(reportTarget.person) : "this member"
        }
        connectionId={reportTarget ? Number(reportTarget.id) : undefined}
        onReported={(message) => setStatus(message)}
      />
    </div>
  );
}
