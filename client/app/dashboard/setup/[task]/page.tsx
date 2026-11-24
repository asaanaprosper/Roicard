import { SetupTaskView } from "@/components/onboarding/setup/SetupTaskView";
import type { Metadata } from "next";

export const metadata: Metadata = {
  title: "Setup — Dashboard",
};

export default function SetupTaskPage() {
  return <SetupTaskView />;
}
