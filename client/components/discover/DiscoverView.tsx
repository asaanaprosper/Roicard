/**
 * DiscoverView
 *
 * Member-facing discovery: explainable recommendations, community members and
 * the wider network. All data comes from the visibility-aware backend.
 */

"use client";

import { ConnectModal } from "@/components/network/ConnectModal";
import { NetworkPersonCard } from "@/components/network/NetworkPersonCard";
import { ProfileSwitcher } from "@/components/network/ProfileSwitcher";
import { ReportMemberModal } from "@/components/network/ReportMemberModal";
import { Button } from "@/components/ui/Button";
import { useConfirm } from "@/components/ui/ConfirmProvider";
import { blockMember } from "@/lib/api/safety";
import { getProfiles, type Profile } from "@/lib/api/profile";
import {
  dismissRecommendation,
  fullName,
  getDiscover,
  markRecommendationNotRelevant,
  type DiscoverResponse,
  type NetworkProfile,
  type RecommendationItem,
} from "@/lib/api/network";
import { saveNetworkContact, shareNetworkProfile } from "@/lib/network/actions";
import { Compass, Search, Sparkles, Users } from "lucide-react";
import Link from "next/link";
import { useCallback, useEffect, useMemo, useState } from "react";

function SectionHeading({
  icon: Icon,
  title,
  description,
}: {
  icon: typeof Sparkles;
  title: string;
  description: string;
}) {
  return (
    <div>
      <h2 className="flex items-center gap-2 text-lg font-semibold text-roicard-text">
        <Icon className="h-5 w-5 text-roicard-accent" aria-hidden />
        {title}
      </h2>
      <p className="mt-1 text-sm text-roicard-text-muted">{description}</p>
    </div>
  );
}

export function DiscoverView() {
  const { confirm } = useConfirm();
  const [profiles, setProfiles] = useState<Profile[]>([]);
  const [defaultProfileId, setDefaultProfileId] = useState<number | null>(null);
  const [selectedId, setSelectedId] = useState<number | null>(null);
  const [data, setData] = useState<DiscoverResponse | null>(null);
  const [isLoading, setIsLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [status, setStatus] = useState<string | null>(null);
  const [busyProfileId, setBusyProfileId] = useState<number | null>(null);
  const [connectTarget, setConnectTarget] = useState<RecommendationItem | null>(
    null
  );
  const [reportTarget, setReportTarget] = useState<NetworkProfile | null>(null);

  useEffect(() => {
    let cancelled = false;
    getProfiles()
      .then((response) => {
        if (cancelled) return;
        setProfiles(response.profiles);
        setDefaultProfileId(response.default_profile_id);
        setSelectedId(
          response.default_profile_id ?? response.profiles[0]?.id ?? null
        );
      })
      .catch(() => {
        if (!cancelled) {
          setError("Unable to load your profiles.");
          setIsLoading(false);
        }
      });
    return () => {
      cancelled = true;
    };
  }, []);

  const load = useCallback(async (profileId: number) => {
    setIsLoading(true);
    setError(null);
    try {
      const response = await getDiscover(profileId);
      setData(response);
    } catch {
      setError("Unable to load recommendations right now.");
    } finally {
      setIsLoading(false);
    }
  }, []);

  useEffect(() => {
    if (selectedId) void load(selectedId);
  }, [selectedId, load]);

  const selectedProfile = useMemo(
    () => profiles.find((profile) => profile.id === selectedId) ?? null,
    [profiles, selectedId]
  );

  const removeRecommendation = (profileId: number) => {
    setData((current) =>
      current
        ? {
            ...current,
            recommendations: {
              ...current.recommendations,
              items: current.recommendations.items.filter(
                (item) => item.profile.id !== profileId
              ),
              total: Math.max(0, current.recommendations.total - 1),
            },
          }
        : current
    );
  };

  const handleDismiss = async (profile: NetworkProfile) => {
    if (!selectedId) return;
    setBusyProfileId(profile.id);
    try {
      const response = await dismissRecommendation(selectedId, profile.id);
      removeRecommendation(profile.id);
      setStatus(response.message);
    } catch {
      setError("Unable to dismiss this recommendation.");
    } finally {
      setBusyProfileId(null);
    }
  };

  const handleNotRelevant = async (profile: NetworkProfile) => {
    if (!selectedId) return;
    const item = data?.recommendations.items.find(
      (recommendation) => recommendation.profile.id === profile.id
    );
    setBusyProfileId(profile.id);
    try {
      const response = await markRecommendationNotRelevant(
        selectedId,
        profile.id,
        item?.category_pair_keys ?? []
      );
      removeRecommendation(profile.id);
      setStatus(response.message);
    } catch {
      setError("Unable to save your feedback.");
    } finally {
      setBusyProfileId(null);
    }
  };

  const handleSaveContact = async (profile: NetworkProfile) => {
    const ok = await saveNetworkContact(profile);
    setStatus(
      ok
        ? `Contact card for ${profile.display_name || "this member"} downloaded.`
        : "A contact card is not available for this profile."
    );
  };

  const handleShare = async (profile: NetworkProfile) => {
    const ok = await shareNetworkProfile(profile);
    setStatus(
      ok
        ? "Profile link copied or shared."
        : "This profile does not have a public link yet."
    );
  };

  const handleBlock = async (profile: NetworkProfile) => {
    const confirmed = await confirm({
      title: `Block ${fullName(profile)}?`,
      description:
        "You will no longer see each other in search, discovery, recommendations or connections. Contact details stay hidden.",
      confirmLabel: "Block member",
      variant: "danger",
    });
    if (!confirmed) return;

    setBusyProfileId(profile.id);
    setError(null);
    try {
      const response = await blockMember({ profileId: profile.id });
      setStatus(response.message);
      if (selectedId) await load(selectedId);
    } catch {
      setError("Unable to block this member.");
    } finally {
      setBusyProfileId(null);
    }
  };

  const handleSent = (message: string) => {
    setStatus(message);
    if (connectTarget) removeRecommendation(connectTarget.profile.id);
  };

  return (
    <div className="space-y-8">
      <header className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div>
          <span className="inline-flex w-fit items-center gap-2 rounded-full bg-roicard-primary/15 px-3 py-1 text-xs font-medium text-roicard-accent">
            <Compass className="h-3.5 w-3.5" aria-hidden />
            Discover
          </span>
          <h1 className="mt-3 text-2xl font-bold text-roicard-text sm:text-3xl">
            People worth meeting
          </h1>
          <p className="mt-2 text-sm text-roicard-text-muted sm:text-base">
            Explainable matches based on what you are seeking and offering.
          </p>
        </div>
        <Link href="/dashboard/search">
          <Button variant="secondary" className="rounded-xl">
            <Search className="h-4 w-4" />
            Search the network
          </Button>
        </Link>
      </header>

      <ProfileSwitcher
        profiles={profiles}
        defaultProfileId={defaultProfileId}
        selectedId={selectedId}
        onChange={(id) => {
          setSelectedId(id);
          setStatus(null);
        }}
      />

      {status && (
        <p className="rounded-lg border border-emerald-500/30 bg-emerald-500/10 px-3 py-2 text-sm text-emerald-400">
          {status}
        </p>
      )}
      {error && (
        <p className="rounded-lg border border-rose-500/30 bg-rose-500/10 px-3 py-2 text-sm text-rose-400">
          {error}
        </p>
      )}

      {isLoading ? (
        <div className="flex min-h-[40vh] items-center justify-center">
          <div
            className="h-8 w-8 animate-spin rounded-full border-2 border-roicard-border border-t-roicard-primary"
            role="status"
            aria-label="Loading recommendations"
          />
        </div>
      ) : !selectedProfile ? (
        <div className="rounded-2xl border border-dashed border-roicard-border bg-roicard-bg-elevated p-8 text-center">
          <h2 className="text-lg font-semibold text-roicard-text">
            Create a profile to start discovering
          </h2>
          <p className="mx-auto mt-2 max-w-md text-sm text-roicard-text-muted">
            Recommendations run per profile, so add your personal or business
            profile first.
          </p>
          <Link href="/dashboard/profile" className="mt-5 inline-block">
            <Button className="rounded-xl">Go to profiles</Button>
          </Link>
        </div>
      ) : (
        <>
          <section className="space-y-4">
            <SectionHeading
              icon={Sparkles}
              title="Recommended for you"
              description="Based on the Seeking and Offering items on this profile."
            />

            {data?.recommendations.needs_signals ? (
              <div className="rounded-2xl border border-dashed border-roicard-border bg-roicard-bg-elevated p-6 sm:p-8">
                <h3 className="text-base font-semibold text-roicard-text">
                  Tell us what you need and offer
                </h3>
                <p className="mt-1 max-w-2xl text-sm text-roicard-text-muted">
                  {data.recommendations.message}
                </p>
                <Link href="/dashboard/profile/signals" className="mt-4 inline-block">
                  <Button className="rounded-xl">Add Seeking or Offering items</Button>
                </Link>
              </div>
            ) : (data?.recommendations.items.length ?? 0) === 0 ? (
              <div className="rounded-2xl border border-dashed border-roicard-border bg-roicard-bg-elevated p-6 sm:p-8">
                <h3 className="text-base font-semibold text-roicard-text">
                  No strong matches yet
                </h3>
                <p className="mt-1 max-w-2xl text-sm text-roicard-text-muted">
                  {data?.recommendations.message ??
                    "Add more detail to your Seeking and Offering items, or check back later."}
                </p>
                <Link href="/dashboard/profile/signals" className="mt-4 inline-block">
                  <Button variant="secondary" className="rounded-xl">
                    Improve my match profile
                  </Button>
                </Link>
              </div>
            ) : (
              <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                {data?.recommendations.items.map((item) => (
                  <NetworkPersonCard entrySource="discover"
                    key={item.profile.id}
                    profile={item.profile}
                    explanation={item.explanation}
                    reasonCodes={item.reason_codes}
                    matchedItems={item.matched_items}
                    matchScore={item.score}
                    isBusy={busyProfileId === item.profile.id}
                    onConnect={() => setConnectTarget(item)}
                    onDismiss={handleDismiss}
                    onNotRelevant={handleNotRelevant}
                    onSaveContact={handleSaveContact}
                    onShare={handleShare}
                    onReport={setReportTarget}
                    onBlock={handleBlock}
                  />
                ))}
              </div>
            )}
          </section>

          <section className="space-y-4">
            <SectionHeading
              icon={Users}
              title="People in your communities"
              description="Members who share an active community with you."
            />
            {data?.has_communities === false ? (
              <div className="rounded-2xl border border-roicard-border bg-roicard-bg-elevated p-5 text-sm text-roicard-text-muted">
                You are not in a community yet. Community members will appear
                here once you join one.
              </div>
            ) : (data?.community_people.length ?? 0) === 0 ? (
              <div className="rounded-2xl border border-roicard-border bg-roicard-bg-elevated p-5 text-sm text-roicard-text-muted">
                No other discoverable members in your communities yet.
              </div>
            ) : (
              <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                {data?.community_people.map((item) => (
                  <NetworkPersonCard entrySource="discover"
                    key={`community-${item.profile.id}`}
                    profile={item.profile}
                    sharedCommunities={item.shared_communities}
                    compact
                    onConnect={() =>
                      setConnectTarget({
                        profile: item.profile,
                        score: 0,
                        reason_codes: [],
                        explanation: "",
                        matched_items: [],
                        category_pair_keys: [],
                      })
                    }
                    onSaveContact={handleSaveContact}
                    onShare={handleShare}
                    onReport={setReportTarget}
                    onBlock={handleBlock}
                  />
                ))}
              </div>
            )}
          </section>

          <section className="space-y-4">
            <SectionHeading
              icon={Search}
              title="Across the network"
              description="Public, searchable members you may not have met yet."
            />
            {(data?.network.length ?? 0) === 0 ? (
              <div className="rounded-2xl border border-roicard-border bg-roicard-bg-elevated p-5 text-sm text-roicard-text-muted">
                No additional searchable profiles are available right now.
              </div>
            ) : (
              <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                {data?.network.map((item) => (
                  <NetworkPersonCard entrySource="discover"
                    key={`network-${item.profile.id}`}
                    profile={item.profile}
                    sharedCommunities={item.shared_communities}
                    compact
                    onConnect={() =>
                      setConnectTarget({
                        profile: item.profile,
                        score: 0,
                        reason_codes: [],
                        explanation: "",
                        matched_items: [],
                        category_pair_keys: [],
                      })
                    }
                    onSaveContact={handleSaveContact}
                    onShare={handleShare}
                    onReport={setReportTarget}
                    onBlock={handleBlock}
                  />
                ))}
              </div>
            )}
          </section>
        </>
      )}

      <ConnectModal
        isOpen={connectTarget !== null}
        onClose={() => setConnectTarget(null)}
        profile={connectTarget?.profile ?? null}
        senderProfileId={selectedId ?? undefined}
        matchReason={
          connectTarget?.explanation && connectTarget.explanation.length > 0
            ? connectTarget.explanation
            : null
        }
        reasonCodes={connectTarget?.reason_codes ?? []}
        matchScore={connectTarget?.score}
        onSent={handleSent}
      />

      <ReportMemberModal
        isOpen={reportTarget !== null}
        onClose={() => setReportTarget(null)}
        memberName={reportTarget ? fullName(reportTarget) : "this member"}
        profileId={reportTarget?.id}
        onReported={(message) => setStatus(message)}
      />
    </div>
  );
}
