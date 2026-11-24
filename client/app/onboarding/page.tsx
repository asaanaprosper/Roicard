"use client";

import { useAuth } from "@/contexts/AuthContext";
import { useRouter } from "next/navigation";
import { useEffect } from "react";

/**
 * The long compulsory onboarding wizard has been retired in Phase 2.
 * Old links now send signed-in members to the dashboard setup tasks and
 * signed-out visitors to account creation.
 */
export default function OnboardingRedirectPage() {
  const router = useRouter();
  const { user, isLoading } = useAuth();

  useEffect(() => {
    if (isLoading) return;
    router.replace(user ? "/dashboard" : "/auth/register");
  }, [isLoading, router, user]);

  return (
    <div className="flex min-h-screen items-center justify-center bg-roicard-bg">
      <div
        className="h-8 w-8 animate-spin rounded-full border-2 border-roicard-border border-t-roicard-primary"
        role="status"
        aria-label="Redirecting"
      />
    </div>
  );
}
