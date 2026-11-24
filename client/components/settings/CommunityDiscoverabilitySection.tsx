"use client";

import { SettingsSection } from "@/components/settings/SettingsSection";
import {
  getMyCommunities,
  updateCommunityDiscoverability,
  type MyCommunityMembership,
} from "@/lib/api/communities";
import { useEffect, useState } from "react";

export function CommunityDiscoverabilitySection() {
  const [memberships, setMemberships] = useState<MyCommunityMembership[]>([]);
  const [isLoading, setIsLoading] = useState(true);
  const [busyId, setBusyId] = useState<number | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [message, setMessage] = useState<string | null>(null);

  useEffect(() => {
    let cancelled = false;
    getMyCommunities()
      .then((response) => {
        if (!cancelled) {
          setMemberships(
            response.memberships.filter((membership) => membership.status === "active")
          );
        }
      })
      .catch(() => {
        if (!cancelled) setError("Unable to load your community memberships.");
      })
      .finally(() => {
        if (!cancelled) setIsLoading(false);
      });

    return () => {
      cancelled = true;
    };
  }, []);

  const toggle = async (membership: MyCommunityMembership, discoverable: boolean) => {
    setBusyId(membership.id);
    setError(null);
    setMessage(null);
    try {
      const response = await updateCommunityDiscoverability(
        membership.community_id,
        discoverable
      );
      setMemberships((current) =>
        current.map((item) =>
          item.id === membership.id
            ? { ...item, discoverable: response.membership.discoverable }
            : item
        )
      );
      setMessage(response.message);
    } catch (e) {
      const data = (e as { response?: { data?: { message?: string } } })?.response?.data;
      setError(data?.message ?? "Unable to update community discoverability.");
    } finally {
      setBusyId(null);
    }
  };

  return (
    <SettingsSection
      title="Community discoverability"
      description="Choose whether your profile appears in Discover and recommendations for each community you joined. Direct profile links and profile visibility are unaffected."
    >
      {isLoading ? (
        <p className="text-sm text-roicard-text-muted">Loading community memberships…</p>
      ) : memberships.length === 0 ? (
        <p className="text-sm text-roicard-text-muted">
          Join a community to control community-specific discoverability.
        </p>
      ) : (
        <div className="space-y-3">
          {memberships.map((membership) => (
            <label
              key={membership.id}
              className="flex items-start gap-3 rounded-xl border border-roicard-border bg-roicard-bg-muted/30 p-4 text-sm text-roicard-text"
            >
              <input
                type="checkbox"
                checked={membership.discoverable}
                disabled={busyId === membership.id}
                onChange={(event) => void toggle(membership, event.target.checked)}
                className="mt-0.5 h-4 w-4 rounded border-roicard-border"
              />
              <span>
                <span className="font-medium">
                  {membership.community?.name ?? `Community #${membership.community_id}`}
                </span>
                <span className="mt-0.5 block text-xs text-roicard-text-muted">
                  {membership.discoverable
                    ? "Discoverable in this community"
                    : "Hidden from Discover and recommendations in this community"}
                </span>
              </span>
            </label>
          ))}
        </div>
      )}

      {message && (
        <p className="mt-3 text-sm text-emerald-400" role="status">
          {message}
        </p>
      )}
      {error && (
        <p className="mt-3 text-sm text-red-400" role="alert">
          {error}
        </p>
      )}
    </SettingsSection>
  );
}
