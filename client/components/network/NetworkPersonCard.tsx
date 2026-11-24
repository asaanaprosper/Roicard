/**
 * NetworkPersonCard
 *
 * Shared profile card for Discover and Search. Reuses the ROICARD card
 * conventions (glass-card, rounded-2xl, accent badges) and only renders
 * public-safe profile data returned by the backend.
 */

"use client";

import { Button } from "@/components/ui/Button";
import { cn } from "@/lib/cn";
import {
  fullName,
  type MatchedSignalItem,
  type NetworkProfile,
} from "@/lib/api/network";
import {
  ArrowUpRight,
  BadgeCheck,
  Bookmark,
  Building2,
  Flag,
  MapPin,
  Share2,
  ShieldBan,
  Sparkles,
  UserPlus,
  X,
} from "lucide-react";
import Link from "next/link";
import { useState } from "react";

type NetworkPersonCardProps = {
  profile: NetworkProfile;
  explanation?: string | null;
  reasonCodes?: string[];
  matchedItems?: MatchedSignalItem[];
  matchScore?: number;
  sharedCommunities?: number;
  onConnect?: (profile: NetworkProfile) => void;
  onDismiss?: (profile: NetworkProfile) => void;
  onNotRelevant?: (profile: NetworkProfile) => void;
  onSaveContact?: (profile: NetworkProfile) => void;
  onShare?: (profile: NetworkProfile) => void;
  onReport?: (profile: NetworkProfile) => void;
  onBlock?: (profile: NetworkProfile) => void;
  isBusy?: boolean;
  compact?: boolean;
  entrySource?: "link" | "search" | "discover" | "dashboard";
};

const reasonLabels: Record<string, string> = {
  offering_meets_seeking: "Offers what you need",
  seeking_meets_offering: "Needs what you offer",
  mutual_match: "Mutual fit",
  tag_overlap: "Shared interests",
  same_organization: "Same organisation",
  same_city: "Same city",
  same_country: "Same country",
  complete_profile: "Complete profile",
};

export function NetworkPersonCard({
  profile,
  explanation,
  reasonCodes = [],
  matchedItems = [],
  matchScore,
  sharedCommunities = 0,
  onConnect,
  onDismiss,
  onNotRelevant,
  onSaveContact,
  onShare,
  onReport,
  onBlock,
  isBusy = false,
  compact = false,
  entrySource = "link",
}: NetworkPersonCardProps) {
  const [saved, setSaved] = useState(false);
  const [shared, setShared] = useState(false);
  const name = fullName(profile);
  const profileHref = profile.slug ? `/${profile.slug}?src=${entrySource}` : null;
  const initials =
    name
      .split(/\s+/)
      .filter(Boolean)
      .slice(0, 2)
      .map((part) => part[0]?.toUpperCase())
      .join("") || "R";

  const handleSave = () => {
    if (!onSaveContact) return;
    onSaveContact(profile);
    setSaved(true);
    window.setTimeout(() => setSaved(false), 2500);
  };

  const handleShare = () => {
    if (!onShare) return;
    onShare(profile);
    setShared(true);
    window.setTimeout(() => setShared(false), 2500);
  };

  return (
    <article className="glass-card flex h-full flex-col rounded-2xl p-4 sm:p-5">
      <div className="flex items-start gap-3">
        {profile.avatar_url ? (
          // eslint-disable-next-line @next/next/no-img-element
          <img
            src={profile.avatar_url}
            alt={name}
            className="h-14 w-14 shrink-0 rounded-xl border border-roicard-border object-cover"
          />
        ) : (
          <div className="flex h-14 w-14 shrink-0 items-center justify-center rounded-xl border border-roicard-border bg-roicard-bg-muted text-base font-semibold text-roicard-text">
            {initials}
          </div>
        )}

        <div className="min-w-0 flex-1">
          <div className="flex items-start justify-between gap-2">
            <div className="min-w-0">
              <h3 className="truncate font-semibold text-roicard-text">
                {profile.slug ? (
                  <Link
                    href={profileHref ?? `/${profile.slug}`}
                    className="underline-offset-4 hover:text-roicard-accent hover:underline"
                  >
                    {name}
                  </Link>
                ) : (
                  name
                )}
              </h3>
              <p className="truncate text-sm text-roicard-text-muted">
                {profile.title || profile.display_name || "ROICARD member"}
              </p>
            </div>
            {profileHref && (
              <Link
                href={profileHref ?? `/${profile.slug}`}
                aria-label={`Open ${name}'s profile`}
                className="rounded-lg p-1.5 text-roicard-text-muted hover:bg-roicard-bg-muted hover:text-roicard-accent"
              >
                <ArrowUpRight className="h-4 w-4" />
              </Link>
            )}
          </div>

          <div className="mt-1.5 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-roicard-text-muted">
            {profile.organisation && (
              <span className="inline-flex items-center gap-1">
                <Building2 className="h-3.5 w-3.5" aria-hidden />
                <span className="truncate">{profile.organisation}</span>
              </span>
            )}
            {profile.location && (
              <span className="inline-flex items-center gap-1">
                <MapPin className="h-3.5 w-3.5" aria-hidden />
                <span className="truncate">{profile.location}</span>
              </span>
            )}
            {profile.verified && (
              <span className="inline-flex items-center gap-1 text-emerald-400">
                <BadgeCheck className="h-3.5 w-3.5" aria-hidden />
                Verified
              </span>
            )}
          </div>
        </div>
      </div>

      {!compact && profile.bio && (
        <p className="mt-3 line-clamp-2 text-sm leading-relaxed text-roicard-text-muted">
          {profile.bio}
        </p>
      )}

      {profile.categories.length > 0 && (
        <div className="mt-3 flex flex-wrap gap-1.5">
          {profile.categories.slice(0, 4).map((category) => (
            <span
              key={category.id}
              className="rounded-full bg-roicard-bg-muted px-2.5 py-1 text-[11px] font-medium text-roicard-text-muted"
            >
              {category.name}
            </span>
          ))}
        </div>
      )}

      {profile.organizations && profile.organizations.length > 0 && (
        <div className="mt-2 flex flex-wrap gap-1.5">
          {profile.organizations.slice(0, 2).map((organization) => (
            <Link
              key={organization.id}
              href={`/organizations/${organization.slug}`}
              className="inline-flex items-center gap-1 rounded-full border border-roicard-border px-2.5 py-1 text-[11px] font-medium text-roicard-accent hover:border-roicard-accent/40"
            >
              <Building2 className="h-3 w-3" aria-hidden />
              {organization.name}
            </Link>
          ))}
        </div>
      )}

      {!compact && profile.catalog_items && profile.catalog_items.length > 0 && (
        <div className="mt-3 space-y-1.5 rounded-xl border border-roicard-border bg-roicard-bg-muted/30 p-2.5">
          {profile.catalog_items.slice(0, 2).map((item) => (
            <div key={item.id} className="flex items-center justify-between gap-2 text-xs">
              <span className="truncate text-roicard-text">{item.title}</span>
              <span className="shrink-0 font-medium text-roicard-accent">{item.price_label}</span>
            </div>
          ))}
        </div>
      )}

      {explanation ? (
        <div className="mt-3 rounded-xl border border-roicard-primary/25 bg-roicard-primary/5 p-3">
          <p className="inline-flex items-center gap-1.5 text-xs font-semibold uppercase tracking-wide text-roicard-accent">
            <Sparkles className="h-3.5 w-3.5" aria-hidden />
            Why this match
            {typeof matchScore === "number" && (
              <span className="ml-auto font-mono text-[10px] normal-case tracking-normal text-roicard-text-muted">
                score {Math.round(matchScore)}
              </span>
            )}
          </p>
          <p className="mt-1.5 text-sm leading-relaxed text-roicard-text">{explanation}</p>
          {matchedItems.length > 0 && (
            <p className="mt-2 line-clamp-2 text-xs leading-relaxed text-roicard-text-muted">
              {matchedItems
                .slice(0, 1)
                .map((item) =>
                  item.direction === "offering_meets_seeking"
                    ? `You need “${item.seeking_item.description}” · they offer “${item.offering_item.description}”`
                    : `They need “${item.seeking_item.description}” · you offer “${item.offering_item.description}”`
                )
                .join(" ")}
            </p>
          )}
          {reasonCodes.length > 0 && (
            <div className="mt-2 flex flex-wrap gap-1">
              {Array.from(new Set(reasonCodes))
                .filter((code) => reasonLabels[code])
                .slice(0, 4)
                .map((code) => (
                  <span
                    key={code}
                    className="rounded bg-roicard-bg-elevated px-1.5 py-0.5 text-[10px] font-medium text-roicard-text-muted"
                  >
                    {reasonLabels[code]}
                  </span>
                ))}
            </div>
          )}
        </div>
      ) : sharedCommunities > 0 ? (
        <p className="mt-3 text-xs font-medium text-roicard-accent">
          {sharedCommunities} shared communit{sharedCommunities === 1 ? "y" : "ies"}
        </p>
      ) : null}

      <div className="mt-auto flex flex-wrap items-center gap-2 pt-4">
        {onConnect && (
          <Button size="sm" className="rounded-xl" onClick={() => onConnect(profile)} disabled={isBusy}>
            <UserPlus className="h-4 w-4" />
            Connect
          </Button>
        )}
        {onSaveContact && (
          <Button
            size="sm"
            variant="secondary"
            className="rounded-xl"
            onClick={handleSave}
            disabled={isBusy}
          >
            <Bookmark className="h-4 w-4" />
            {saved ? "Saved" : "Save contact"}
          </Button>
        )}
        {onShare && (
          <Button
            size="sm"
            variant="secondary"
            className="rounded-xl"
            onClick={handleShare}
            disabled={isBusy}
          >
            <Share2 className="h-4 w-4" />
            {shared ? "Link copied" : "Share"}
          </Button>
        )}
        {onReport && (
          <Button
            size="sm"
            variant="ghost"
            className="rounded-xl"
            onClick={() => onReport(profile)}
            disabled={isBusy}
            aria-label={`Report ${name}`}
            title="Report this profile"
          >
            <Flag className="h-4 w-4" />
          </Button>
        )}
        {onBlock && (
          <Button
            size="sm"
            variant="ghost"
            className="rounded-xl"
            onClick={() => onBlock(profile)}
            disabled={isBusy}
            aria-label={`Block ${name}`}
            title="Block this member"
          >
            <ShieldBan className="h-4 w-4" />
          </Button>
        )}
        {onDismiss && (
          <Button
            size="sm"
            variant="ghost"
            className="rounded-xl"
            onClick={() => onDismiss(profile)}
            disabled={isBusy}
            aria-label={`Dismiss ${name}`}
          >
            <X className="h-4 w-4" />
            Dismiss
          </Button>
        )}
        {onNotRelevant && (
          <button
            type="button"
            className={cn(
              "ml-auto text-xs font-medium text-roicard-text-muted underline-offset-4",
              "hover:text-roicard-accent hover:underline"
            )}
            onClick={() => onNotRelevant(profile)}
            disabled={isBusy}
          >
            Not relevant
          </button>
        )}
      </div>
    </article>
  );
}
