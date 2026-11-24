"use client";

import { ProfileCard } from "@/components/profile/public/ProfileCard";
import type { ProfileOrganization } from "@/lib/profile/types";
import { Building2 } from "lucide-react";
import Link from "next/link";

export function OrganizationsCard({ organizations }: { organizations: ProfileOrganization[] }) {
  if (organizations.length === 0) return null;

  return (
    <ProfileCard>
      <div className="border-b border-roicard-border/60 px-5 pb-3 pt-4">
        <span className="flex items-center gap-2.5">
          <span className="flex h-8 w-8 items-center justify-center rounded-xl bg-roicard-primary/10 text-roicard-accent">
            <Building2 className="h-4 w-4" aria-hidden />
          </span>
          <span className="text-sm font-semibold text-roicard-text">Organizations</span>
        </span>
      </div>
      <div className="space-y-2 px-5 pb-5 pt-4">
        {organizations.map((organization) => (
          <Link
            key={organization.id}
            href={`/organizations/${organization.slug}`}
            className="flex items-center gap-3 rounded-xl border border-roicard-border/70 bg-roicard-bg-muted/30 px-3 py-2.5 transition-colors hover:border-roicard-accent/40"
          >
            {organization.logo_url ? (
              // eslint-disable-next-line @next/next/no-img-element
              <img
                src={organization.logo_url}
                alt={organization.name}
                className="h-9 w-9 rounded-lg border border-roicard-border object-contain"
              />
            ) : (
              <span className="flex h-9 w-9 items-center justify-center rounded-lg bg-roicard-bg-muted text-xs font-bold text-roicard-accent">
                {organization.name.slice(0, 1).toUpperCase()}
              </span>
            )}
            <span className="min-w-0">
              <span className="block truncate text-sm font-medium text-roicard-text">
                {organization.name}
              </span>
              <span className="block truncate text-xs text-roicard-text-muted">
                {organization.industry || "Verified organization"}
              </span>
            </span>
          </Link>
        ))}
      </div>
    </ProfileCard>
  );
}
