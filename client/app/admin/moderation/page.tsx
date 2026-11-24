import { ModerationView } from "@/components/admin/ModerationView";
import type { Metadata } from "next";

export const metadata: Metadata = {
  title: "Safety moderation",
};

export default function AdminModerationPage() {
  return <ModerationView />;
}
