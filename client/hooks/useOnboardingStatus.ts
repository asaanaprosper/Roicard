"use client";

import { getOnboardingStatus, type OnboardingStatus } from "@/lib/api/onboarding";
import { useCallback, useEffect, useState } from "react";

export function useOnboardingStatus() {
  const [status, setStatus] = useState<OnboardingStatus | null>(null);
  const [isLoading, setIsLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  const refresh = useCallback(async () => {
    setIsLoading(true);
    setError(null);
    try {
      setStatus(await getOnboardingStatus());
    } catch {
      setError("Unable to load your setup progress.");
    } finally {
      setIsLoading(false);
    }
  }, []);

  useEffect(() => {
    refresh();
  }, [refresh]);

  return { status, setStatus, isLoading, error, refresh };
}
