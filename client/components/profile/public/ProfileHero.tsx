/**
 * ProfileHero
 *
 * Full-width poster-style public profile header. The member's photo (or
 * initials fallback) fills the header, with the name, headline and location
 * overlaid on a readable gradient.
 */

import { OrgAvatar } from "@/components/profile/public/OrgAvatar";
import { VerificationBadge } from "@/components/profile/public/VerificationBadge";
import { getFullName } from "@/lib/profile/helpers";
import type { UserProfile } from "@/lib/profile/types";
import { MapPin } from "lucide-react";

type ProfileHeroProps = {
  profile: UserProfile;
  /** Optional organization logo URL (falls back to initials when absent). */
  organizationLogoUrl?: string | null;
};

export function ProfileHero({
  profile,
  organizationLogoUrl,
}: ProfileHeroProps) {
  const fullName = getFullName(profile);
  const isVerified = Boolean(profile.isVerifiedMember);
  const initials = `${profile.firstName?.trim().charAt(0) ?? ""}${profile.lastName?.trim().charAt(0) ?? ""}`.toUpperCase();
  const heroImage = profile.coverImageUrl || profile.profilePhotoUrl;

  return (
    <header className="relative overflow-hidden rounded-3xl border border-roicard-border/40 bg-roicard-bg-muted shadow-[0_18px_50px_-20px_var(--rc-shadow)] theme-transition">
      <div className="relative aspect-[4/3] w-full sm:aspect-[16/10]">
        {heroImage ? (
          // eslint-disable-next-line @next/next/no-img-element
          <img
            src={heroImage}
            alt={fullName}
            className="h-full w-full object-cover object-center"
          />
        ) : (
          <div className="flex h-full w-full items-center justify-center bg-roicard-primary/10">
            <span className="text-6xl font-bold tracking-tight text-roicard-primary/30 sm:text-7xl">
              {initials || "R"}
            </span>
          </div>
        )}

        <div
          aria-hidden
          className="absolute inset-0 bg-gradient-to-t from-black/80 via-black/25 to-transparent"
        />

        {profile.logoUrl && (
          <div className="absolute left-4 top-4 flex h-14 w-14 items-center justify-center overflow-hidden rounded-2xl border border-white/40 bg-white/90 p-1 shadow-lg">
            {/* eslint-disable-next-line @next/next/no-img-element */}
            <img src={profile.logoUrl} alt={`${profile.organization || fullName} logo`} className="h-full w-full rounded-xl object-contain" />
          </div>
        )}

        {isVerified && (
          <VerificationBadge size={30} className="absolute right-4 top-4" />
        )}

        <div className="absolute inset-x-0 bottom-0 px-5 pb-5 pt-16 text-white sm:px-6 sm:pb-6">
          <h1 className="text-2xl font-bold tracking-tight sm:text-[30px]">
            {fullName}
          </h1>

          {profile.professionalTitle && (
            <p className="mt-1 text-sm font-semibold text-white/90 sm:text-base">
              {profile.professionalTitle}
            </p>
          )}

          <div className="mt-2 flex flex-wrap items-center gap-x-3 gap-y-1 text-sm text-white/80">
            {profile.organization && (
              <span className="inline-flex items-center gap-1.5">
                <OrgAvatar
                  name={profile.organization}
                  logoUrl={organizationLogoUrl}
                  size="sm"
                />
                {profile.organization}
              </span>
            )}
            {profile.location && (
              <span className="inline-flex items-center gap-1.5">
                <MapPin className="h-4 w-4" aria-hidden />
                {profile.location}
              </span>
            )}
          </div>

          {isVerified && profile.verificationLabel && (
            <p className="mt-2 text-xs font-medium text-white/80">
              {profile.verificationLabel}
            </p>
          )}
        </div>
      </div>
    </header>
  );
}
