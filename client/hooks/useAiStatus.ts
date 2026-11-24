"use client";

import { acceptAiConsent, getAiStatus, type AiStatus } from "@/lib/api/ai";
import { useCallback, useEffect, useState } from "react";

export function useAiStatus() {
  const [status, setStatus] = useState<AiStatus | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  const refresh = useCallback(async () => {
    setLoading(true);
    try {
      const next = await getAiStatus();
      setStatus(next);
      setError(null);
    } catch {
      setError("Unable to load AI settings.");
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    void refresh();
  }, [refresh]);

  const accept = useCallback(async (): Promise<AiStatus> => {
    try {
      const next = await acceptAiConsent();
      setStatus(next);
      setError(null);
      return next;
    } catch (e) {
      const message =
        (e as { response?: { data?: { message?: string } } })?.response?.data?.message ??
        "Unable to record AI consent.";
      setError(message);
      throw e;
    }
  }, []);

  return { status, loading, error, refresh, accept };
}
