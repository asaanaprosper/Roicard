/**
 * DashboardRecommendations
 *
 * Dashboard entry point for explainable recommendations. The backend applies
 * the RECOMMENDATIONS_SHOWN entitlement, so the displayed count always matches
 * the configured limit.
 */

"use client";

import { ConnectModal } from "@/components/network/ConnectModal";
import { NetworkPersonCard } from "@/components/network/NetworkPersonCard";
import { Button } from "@/components/ui/Button";
import { getProfiles } from "@/lib/api/profile";
import {
  getRecommendations,
  type NetworkProfile,
  type RecommendationItem,
  type RecommendationPayload,
} from "@/lib/api/network";
import { saveNetworkContact, shareNetworkProfile } from "@/lib/network/actions";
import { Compass, Search, Sparkles } from "lucide-react";
import Link from "next/link";
import { useEffect, useState } from "react";

export function DashboardRecommendations() {
  const [profileId, setProfileId] = useState<number | null>(null);
  const [payload, setPayload] = useState<RecommendationPayload | null>(null);
  const [isLoading, setIsLoading] = useState(true);
  const [connectTarget, setConnectTarget] = useState<RecommendationItem | null>(null);
  const [status, setStatus] = useState<string | null>(null);

  useEffect(() => {
    let cancelled = false;

    getProfiles()
      .then(async (profiles) => {
        const id = profiles.default_profile_id ?? profiles.profiles[0]?.id ?? null;
        if (cancelled) return;
        setProfileId(id);
        if (!id) {
          setPayload(null);
          return;
        }
        const recommendations = await getRecommendations(id);
        if (!cancelled) setPayload(recommendations);
      })
      .catch(() => {
        if (!cancelled) setPayload(null);
      })
      .finally(() => {
        if (!cancelled) setIsLoading(false);
      });

    return () => {
      cancelled = true;
    };
  }, []);

  const handleSaveContact = async (profile: NetworkProfile) => {
    const ok = await saveNetworkContact(profile);
    setStatus(
      ok ? "Contact card downloaded." : "A contact card is not available for this profile."
    );
  };

  const handleShare = async (profile: NetworkProfile) => {
    const ok = await shareNetworkProfile(profile);
    setStatus(ok ? "Profile link copied or shared." : "This profile has no public link yet.");
  };

  if (isLoading) {
    return (
      <section className="space-y-4">
        <div className="h-6 w-48 animate-pulse rounded bg-roicard-bg-muted" />
        <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
          {[0, 1, 2].map((index) => (
            <div
              key={index}
              className="h-48 animate-pulse rounded-2xl border border-roicard-border bg-roicard-bg-elevated"
            />
          ))}
        </div>
      </section>
    );
  }

  const items = payload?.items.slice(0, 3) ?? [];

  return (
    <section className="space-y-4">
      <div className="flex flex-wrap items-end justify-between gap-3">
        <div>
          <h2 className="flex items-center gap-2 text-lg font-semibold text-roicard-text">
            <Sparkles className="h-5 w-5 text-roicard-accent" aria-hidden />
            Recommended people
          </h2>
          <p className="mt-1 text-sm text-roicard-text-muted">
            {payload?.total
              ? `${payload.total} recommendation${payload.total === 1 ? "" : "s"} based on your Seeking and Offering items.`
              : "Matches appear once you add Seeking and Offering items."}
          </p>
        </div>
        <div className="flex flex-wrap gap-2">
          <Link href="/dashboard/discover">
            <Button variant="secondary" size="sm" className="rounded-xl">
              <Compass className="h-4 w-4" />
              Discover
            </Button>
          </Link>
          <Link href="/dashboard/search">
            <Button variant="ghost" size="sm" className="rounded-xl">
              <Search className="h-4 w-4" />
              Search
            </Button>
          </Link>
        </div>
      </div>

      {status && (
        <p className="rounded-lg border border-emerald-500/30 bg-emerald-500/10 px-3 py-2 text-sm text-emerald-400">
          {status}
        </p>
      )}

      {items.length === 0 ? (
        <div className="rounded-2xl border border-dashed border-roicard-border bg-roicard-bg-elevated p-6">
          <h3 className="text-base font-semibold text-roicard-text">
            {payload?.needs_signals ? "Add your Seeking and Offering items" : "No strong matches yet"}
          </h3>
          <p className="mt-1 max-w-2xl text-sm text-roicard-text-muted">
            {payload?.message ??
              "Complete your signals so ROICARD can explain why two members are a good fit."}
          </p>
          <Link href="/dashboard/profile/signals" className="mt-4 inline-block">
            <Button size="sm" className="rounded-xl">
              Manage Seeking &amp; Offering
            </Button>
          </Link>
        </div>
      ) : (
        <>
          <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
            {items.map((item) => (
              <NetworkPersonCard entrySource="dashboard"
                key={item.profile.id}
                profile={item.profile}
                explanation={item.explanation}
                reasonCodes={item.reason_codes}
                matchedItems={item.matched_items}
                matchScore={item.score}
                compact
                onConnect={() => setConnectTarget(item)}
                onSaveContact={handleSaveContact}
                onShare={handleShare}
              />
            ))}
          </div>
          {payload && payload.total > items.length && (
            <Link
              href="/dashboard/discover"
              className="inline-flex text-sm font-medium text-roicard-accent hover:text-roicard-text"
            >
              See all {payload.total} recommendations →
            </Link>
          )}
        </>
      )}

      <ConnectModal
        isOpen={connectTarget !== null}
        onClose={() => setConnectTarget(null)}
        profile={connectTarget?.profile ?? null}
        senderProfileId={profileId ?? undefined}
        matchReason={connectTarget?.explanation || null}
        reasonCodes={connectTarget?.reason_codes ?? []}
        matchScore={connectTarget?.score}
        onSent={(message) => setStatus(message)}
      />
    </section>
  );
}
