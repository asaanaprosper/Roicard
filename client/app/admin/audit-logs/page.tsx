import { AuditLogsView } from "@/components/admin";
import type { Metadata } from "next";

export const metadata: Metadata = {
  title: "Audit Logs — Admin",
};

export default function AdminAuditLogsPage() {
  return <AuditLogsView />;
}
