/**
 * Legacy onboarding route — /dashboard/onboarding
 *
 * Phase 2 replaced the compulsory wizard with dashboard setup tasks.
 */

import { redirect } from "next/navigation";

export default function LegacyOnboardingPage() {
  redirect("/dashboard");
}
