"use client";

import { Button } from "@/components/ui/Button";
import { BrandLogo } from "@/components/ui/BrandLogo";
import { useAuth } from "@/contexts/AuthContext";
import {
  acceptOrganizationInvitation,
  getOrganization,
  requestOrganizationJoin,
  type OrganizationDirectory,
} from "@/lib/api/organizations";
import {
  BadgeCheck,
  Building2,
  Globe2,
  HandHeart,
  MapPin,
  Search,
  Users,
} from "lucide-react";
import Link from "next/link";
import { useCallback, useEffect, useState } from "react";

type OrganizationPublicViewProps = {
  slug: string;
};

export function OrganizationPublicView({ slug }: OrganizationPublicViewProps) {
  const { isAuthenticated } = useAuth();
  const [directory, setDirectory] = useState<OrganizationDirectory | null>(null);
  const [isLoading, setIsLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [status, setStatus] = useState<string | null>(null);
  const [invitationToken, setInvitationToken] = useState<string | null>(null);
  const [search, setSearch] = useState("");
  const [appliedSearch, setAppliedSearch] = useState("");

  useEffect(() => {
    if (typeof window !== "undefined") {
      setInvitationToken(new URLSearchParams(window.location.search).get("invitation"));
    }
  }, []);

  const load = useCallback(async () => {
    setIsLoading(true);
    setError(null);
    try {
      setDirectory(await getOrganization(slug, appliedSearch || undefined));
    } catch {
      setError("This organization is not available.");
    } finally {
      setIsLoading(false);
    }
  }, [slug, appliedSearch]);

  useEffect(() => {
    void load();
  }, [load]);

  const handleInvitation = async () => {
    if (!invitationToken) return;
    setError(null);
    try {
      const response = await acceptOrganizationInvitation(invitationToken);
      setStatus(response.message);
      setInvitationToken(null);
      await load();
    } catch (e) {
      const data = (e as { response?: { data?: { message?: string } } })?.response?.data;
      setError(data?.message ?? "Unable to accept this invitation.");
    }
  };

  const handleJoin = async () => {
    setError(null);
    try {
      const response = await requestOrganizationJoin(slug);
      setStatus(response.message);
    } catch (e) {
      const data = (e as { response?: { data?: { message?: string } } })?.response?.data;
      setError(data?.message ?? "Unable to submit a join request.");
    }
  };

  if (isLoading) {
    return (
      <div className="flex min-h-screen items-center justify-center bg-roicard-bg">
        <div className="h-8 w-8 animate-spin rounded-full border-2 border-roicard-border border-t-roicard-primary" />
      </div>
    );
  }

  if (!directory) {
    return (
      <div className="flex min-h-screen flex-col items-center justify-center gap-4 bg-roicard-bg px-4 text-center">
        <BrandLogo height={26} />
        <p className="text-sm text-roicard-text-muted">{error ?? "Organization not found."}</p>
        <Link href="/organizations">
          <Button variant="secondary" className="rounded-xl">Browse organizations</Button>
        </Link>
      </div>
    );
  }

  const { organization, members } = directory;

  return (
    <div className="min-h-screen bg-roicard-bg">
      <header className="border-b border-roicard-border bg-roicard-bg-elevated/80 backdrop-blur-xl">
        <div className="mx-auto flex h-16 w-full max-w-4xl items-center justify-between px-4 sm:px-6">
          <Link href="/"><BrandLogo height={24} /></Link>
          <Link href="/organizations">
            <Button variant="secondary" size="sm" className="rounded-xl">All organizations</Button>
          </Link>
        </div>
      </header>

      <main className="mx-auto w-full max-w-4xl space-y-6 px-4 py-8 sm:px-6">
        <section className="overflow-hidden rounded-3xl border border-roicard-border bg-roicard-bg-elevated">
          <div className="relative h-40 bg-roicard-primary/10 sm:h-56">
            {organization.cover_url ? (
              // eslint-disable-next-line @next/next/no-img-element
              <img
                src={organization.cover_url}
                alt={organization.name}
                className="h-full w-full object-cover"
              />
            ) : (
              <div className="flex h-full items-center justify-center">
                <Building2 className="h-14 w-14 text-roicard-primary/30" />
              </div>
            )}
          </div>
          <div className="p-5 sm:p-6">
            <div className="-mt-14 flex items-end gap-4">
              {organization.logo_url ? (
                // eslint-disable-next-line @next/next/no-img-element
                <img
                  src={organization.logo_url}
                  alt={organization.name}
                  className="h-20 w-20 rounded-2xl border-4 border-roicard-bg-elevated bg-white object-contain p-1 shadow-lg"
                />
              ) : (
                <span className="flex h-20 w-20 items-center justify-center rounded-2xl border-4 border-roicard-bg-elevated bg-roicard-primary/15 text-2xl font-bold text-roicard-accent shadow-lg">
                  {organization.name.slice(0, 1).toUpperCase()}
                </span>
              )}
              <span className="mb-1 inline-flex items-center gap-1.5 rounded-full bg-emerald-500/15 px-3 py-1 text-xs font-medium text-emerald-400">
                <BadgeCheck className="h-3.5 w-3.5" />
                Verified organization
              </span>
            </div>

            <h1 className="mt-3 text-2xl font-bold text-roicard-text sm:text-3xl">
              {organization.name}
            </h1>
            <div className="mt-1 flex flex-wrap gap-x-4 gap-y-1 text-sm text-roicard-text-muted">
              {organization.industry && <span>{organization.industry}</span>}
              {organization.location && (
                <span className="inline-flex items-center gap-1">
                  <MapPin className="h-4 w-4" />
                  {organization.location}
                </span>
              )}
              <span className="inline-flex items-center gap-1">
                <Users className="h-4 w-4" />
                {members.length} public member(s)
              </span>
              {organization.website_url && (
                <a
                  href={organization.website_url}
                  target="_blank"
                  rel="noreferrer"
                  className="inline-flex items-center gap-1 text-roicard-accent hover:underline"
                >
                  <Globe2 className="h-4 w-4" />
                  Website
                </a>
              )}
            </div>

            {organization.description && (
              <p className="mt-4 whitespace-pre-line text-sm leading-relaxed text-roicard-text-muted">
                {organization.description}
              </p>
            )}

            {organization.services.length > 0 && (
              <div className="mt-4 flex flex-wrap gap-2">
                {organization.services.map((service) => (
                  <span
                    key={service}
                    className="rounded-full border border-roicard-border bg-roicard-bg-muted px-3 py-1 text-xs text-roicard-text"
                  >
                    {service}
                  </span>
                ))}
              </div>
            )}

            {(organization.seeking || organization.offering) && (
              <div className="mt-4 grid gap-3 sm:grid-cols-2">
                {organization.seeking && (
                  <div className="rounded-xl border border-roicard-border bg-roicard-bg-muted/30 p-3">
                    <p className="text-xs font-semibold uppercase tracking-wider text-roicard-accent">Seeking</p>
                    <p className="mt-1 text-sm text-roicard-text-muted">{organization.seeking}</p>
                  </div>
                )}
                {organization.offering && (
                  <div className="rounded-xl border border-roicard-border bg-roicard-bg-muted/30 p-3">
                    <p className="text-xs font-semibold uppercase tracking-wider text-roicard-accent">Offering</p>
                    <p className="mt-1 text-sm text-roicard-text-muted">{organization.offering}</p>
                  </div>
                )}
              </div>
            )}

            <div className="mt-5 flex flex-wrap gap-2">
              {invitationToken && isAuthenticated && (
                <Button className="rounded-xl" onClick={handleInvitation}>
                  Accept invitation
                </Button>
              )}
              {isAuthenticated ? (
                <Button variant="secondary" className="rounded-xl" onClick={handleJoin}>
                  <HandHeart className="h-4 w-4" />
                  Request to join
                </Button>
              ) : (
                <Link href="/auth/login">
                  <Button variant="secondary" className="rounded-xl">
                    Sign in to join
                  </Button>
                </Link>
              )}
            </div>

            {status && (
              <p className="mt-3 rounded-lg border border-emerald-500/30 bg-emerald-500/10 px-3 py-2 text-sm text-emerald-400">
                {status}
              </p>
            )}
            {error && (
              <p className="mt-3 rounded-lg border border-rose-500/30 bg-rose-500/10 px-3 py-2 text-sm text-rose-400">
                {error}
              </p>
            )}
          </div>
        </section>

        <section className="space-y-4">
          <div className="flex flex-wrap items-end justify-between gap-3">
            <div>
              <h2 className="text-lg font-semibold text-roicard-text">Directory</h2>
              <p className="text-sm text-roicard-text-muted">
                Only members whose affiliation is confirmed and who allow their
                profile to be viewed appear here.
              </p>
            </div>
            <form
              className="flex gap-2"
              onSubmit={(event) => {
                event.preventDefault();
                setAppliedSearch(search.trim());
              }}
            >
              <input
                value={search}
                onChange={(event) => setSearch(event.target.value)}
                placeholder="Search members"
                className="h-10 rounded-lg border border-roicard-border bg-roicard-bg-muted px-3 text-sm text-roicard-text"
              />
              <Button type="submit" size="sm" variant="secondary" className="rounded-lg">
                <Search className="h-4 w-4" />
              </Button>
            </form>
          </div>

          {members.length === 0 ? (
            <div className="rounded-2xl border border-dashed border-roicard-border bg-roicard-bg-elevated p-6 text-sm text-roicard-text-muted">
              No publicly affiliated members to show.
            </div>
          ) : (
            <div className="grid gap-3 sm:grid-cols-2">
              {members.map((member) => {
                const name = [member.profile.user.first_name, member.profile.user.last_name]
                  .filter(Boolean)
                  .join(" ") || member.profile.display_name || "Member";
                const card = (
                  <div className="flex items-center gap-3 rounded-2xl border border-roicard-border bg-roicard-bg-elevated p-4 transition-colors hover:border-roicard-accent/30">
                    {member.profile.avatar_url ? (
                      // eslint-disable-next-line @next/next/no-img-element
                      <img
                        src={member.profile.avatar_url}
                        alt={name}
                        className="h-12 w-12 rounded-xl border border-roicard-border object-cover"
                      />
                    ) : (
                      <span className="flex h-12 w-12 items-center justify-center rounded-xl bg-roicard-bg-muted font-semibold text-roicard-text">
                        {name.slice(0, 1).toUpperCase()}
                      </span>
                    )}
                    <div className="min-w-0">
                      <p className="truncate font-medium text-roicard-text">{name}</p>
                      <p className="truncate text-sm text-roicard-text-muted">
                        {member.profile.title || member.profile.organisation || "ROICARD member"}
                      </p>
                      <p className="text-[11px] capitalize text-roicard-accent">{member.role}</p>
                    </div>
                  </div>
                );

                return member.profile.slug ? (
                  <Link key={member.membership_id} href={`/${member.profile.slug}`}>
                    {card}
                  </Link>
                ) : (
                  <div key={member.membership_id}>{card}</div>
                );
              })}
            </div>
          )}
        </section>
      </main>
    </div>
  );
}
