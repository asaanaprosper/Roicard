/**
 * Dashboard Profile Page
 *
 * Route: /dashboard/profile
 * Manage personal and business profiles, preview the poster profile, QR,
 * public link and completeness.
 */

import { ProfilesManagementView } from "@/components/profile/ProfilesManagementView";
import type { Metadata } from "next";

export const metadata: Metadata = {
  title: "Profiles",
};

export default function ProfilePage() {
  return <ProfilesManagementView />;
}
