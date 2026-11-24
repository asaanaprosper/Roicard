import {
  BarChart3,
  Building2,
  Calendar,
  Compass,
  CreditCard,
  Gift,
  Image,
  Layers,
  LayoutDashboard,
  Link2,
  Search,
  Settings,
  User,
  type LucideIcon,
} from "lucide-react";

export const BRAND = {
  /** Accent and CTA colors are theme-stable; surfaces use CSS variables in globals.css */
  accentOrange: "#FF8C42",
  primaryRed: "#E63946",
  gradient: "linear-gradient(135deg, #E63946 0%, #EF6B35 50%, #FF8C42 100%)",
} as const;

export type NavItem = {
  label: string;
  href: string;
  icon: LucideIcon;
  /** When true, only exact pathname match counts as active (e.g. /dashboard home) */
  exact?: boolean;
};

export const DASHBOARD_NAV: NavItem[] = [
  { label: "Dashboard", href: "/dashboard", icon: LayoutDashboard, exact: true },
  { label: "Discover", href: "/dashboard/discover", icon: Compass },
  { label: "Search", href: "/dashboard/search", icon: Search },
  { label: "Profiles", href: "/dashboard/profile", icon: User },
  { label: "Meetings", href: "/dashboard/meetings", icon: Calendar },
  { label: "Connections", href: "/dashboard/connections", icon: Link2 },
  { label: "Organization", href: "/dashboard/organization", icon: Building2 },
  { label: "Plan & Usage", href: "/dashboard/plan", icon: Layers },
  { label: "Physical Card", href: "/dashboard/card", icon: CreditCard },
  { label: "Referrals", href: "/dashboard/referrals", icon: Gift },
  { label: "Signature Cards", href: "/dashboard/signature-cards", icon: Image },
  { label: "Analytics", href: "/dashboard/analytics", icon: BarChart3 },
  { label: "Settings", href: "/dashboard/settings", icon: Settings },
];
