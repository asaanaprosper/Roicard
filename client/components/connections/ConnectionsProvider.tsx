/**
 * ConnectionsProvider
 *
 * Shared connection state for dashboard pages. Sources guest requests,
 * member-to-member requests and accepted connections from the backend.
 */

"use client";

import {
  acceptRequest as acceptRequestStorage,
  declineRequest as declineRequestStorage,
  getConnectionSummary,
  getConnections,
  getIncomingRequests,
  getSentRequests,
  removeConnection as removeConnectionStorage,
  withdrawRequest as withdrawRequestStorage,
} from "@/lib/connections/storage";
import type {
  Connection,
  ConnectionSummary,
  IncomingConnectionRequest,
} from "@/lib/connections/types";
import {
  ReactNode,
  createContext,
  useCallback,
  useContext,
  useEffect,
  useMemo,
  useState,
} from "react";

type ConnectionsContextValue = {
  requests: IncomingConnectionRequest[];
  sentRequests: IncomingConnectionRequest[];
  connections: Connection[];
  summary: ConnectionSummary;
  isLoading: boolean;
  error: string | null;
  refresh: () => Promise<void>;
  acceptRequest: (
    requestId: string,
    requestType?: "guest" | "member"
  ) => Promise<void>;
  declineRequest: (
    requestId: string,
    requestType?: "guest" | "member"
  ) => Promise<void>;
  withdrawRequest: (requestId: string) => Promise<void>;
  removeConnection: (connectionId: string) => Promise<void>;
};

const ConnectionsContext = createContext<ConnectionsContextValue | null>(null);

export function useConnections() {
  const context = useContext(ConnectionsContext);
  if (!context) {
    throw new Error("useConnections must be used within ConnectionsProvider");
  }
  return context;
}

type ConnectionsProviderProps = {
  children: ReactNode;
};

export function ConnectionsProvider({ children }: ConnectionsProviderProps) {
  const [requests, setRequests] = useState<IncomingConnectionRequest[]>([]);
  const [sentRequests, setSentRequests] = useState<IncomingConnectionRequest[]>(
    []
  );
  const [connections, setConnections] = useState<Connection[]>([]);
  const [summary, setSummary] = useState<ConnectionSummary>({
    pendingCount: 0,
    totalConnections: 0,
    recentConnections: [],
  });
  const [isLoading, setIsLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  /** Reloads all connection data from the backend. */
  const refresh = useCallback(async () => {
    try {
      setError(null);
      const [requests, sentRequests, connections, summary] = await Promise.all([
        getIncomingRequests(),
        getSentRequests(),
        getConnections(),
        getConnectionSummary(),
      ]);
      setRequests(requests);
      setSentRequests(sentRequests);
      setConnections(connections);
      setSummary(summary);
    } catch {
      setError("Unable to load your connections.");
    } finally {
      setIsLoading(false);
    }
  }, []);

  useEffect(() => {
    refresh();
  }, [refresh]);

  /** State transition: pending request → accepted connection. */
  const acceptRequest = useCallback(
    async (requestId: string, requestType: "guest" | "member" = "guest") => {
      await acceptRequestStorage(requestId, requestType);
      await refresh();
    },
    [refresh]
  );

  /** Removes a declined request from the queue. */
  const declineRequest = useCallback(
    async (requestId: string, requestType: "guest" | "member" = "guest") => {
      await declineRequestStorage(requestId, requestType);
      await refresh();
    },
    [refresh]
  );

  const withdrawRequest = useCallback(
    async (requestId: string) => {
      await withdrawRequestStorage(requestId);
      await refresh();
    },
    [refresh]
  );

  const removeConnection = useCallback(
    async (connectionId: string) => {
      await removeConnectionStorage(connectionId);
      await refresh();
    },
    [refresh]
  );

  const value = useMemo(
    () => ({
      requests,
      sentRequests,
      connections,
      summary,
      isLoading,
      error,
      refresh,
      acceptRequest,
      declineRequest,
      withdrawRequest,
      removeConnection,
    }),
    [
      requests,
      sentRequests,
      connections,
      summary,
      isLoading,
      error,
      refresh,
      acceptRequest,
      declineRequest,
      withdrawRequest,
      removeConnection,
    ]
  );

  return (
    <ConnectionsContext.Provider value={value}>
      {children}
    </ConnectionsContext.Provider>
  );
}
