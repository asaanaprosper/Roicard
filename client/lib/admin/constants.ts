/**
 * Admin navigation items for the admin sidebar.
 */

import {
  BarChart3,
  Building2,
  CreditCard,
  Flag,
  Gift,
  Layers,
  LayoutDashboard,
  Mail,
  Network,
  Nfc,
  Percent,
  ScrollText,
  Tags,
  Users,
  type LucideIcon,
} from "lucide-react";

export type AdminNavItem = {
  label: string;
  href: string;
  icon: LucideIcon;
  exact?: boolean;
};

export const ADMIN_NAV: AdminNavItem[] = [
  { label: "Dashboard Overview", href: "/admin", icon: LayoutDashboard, exact: true },
  { label: "Pilot Metrics", href: "/admin/metrics", icon: BarChart3 },
  { label: "Users", href: "/admin/users", icon: Users },
  { label: "Moderation", href: "/admin/moderation", icon: Flag },
  { label: "Discounts", href: "/admin/discounts", icon: Percent },
  { label: "Generate Card", href: "/admin/cards/generate", icon: CreditCard },
  { label: "Roicard Management", href: "/admin/nfc", icon: Nfc },
  { label: "Messages", href: "/admin/messages", icon: Mail },
  { label: "Categories", href: "/admin/categories", icon: Tags },
  { label: "Communities", href: "/admin/communities", icon: Network },
  { label: "Organizations", href: "/admin/organizations", icon: Building2 },
  { label: "Billing", href: "/admin/billing", icon: CreditCard },
  { label: "Referrals", href: "/admin/referrals", icon: Gift },
  { label: "Plans & Entitlements", href: "/admin/plans", icon: Layers },
  { label: "Audit Logs", href: "/admin/audit-logs", icon: ScrollText },
  { label: "Statistics", href: "/admin/statistics", icon: BarChart3 },
];

export const ADMIN_IDENTITY = {
  name: "Admin User",
  role: "Platform Administrator",
  initials: "AU",
};
