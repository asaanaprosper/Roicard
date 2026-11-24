"use client";

import { Button } from "@/components/ui/Button";
import { Input } from "@/components/ui/Input";
import { BrandLogo } from "@/components/ui/BrandLogo";
import { listOrganizations, type Organization } from "@/lib/api/organizations";
import { Building2, MapPin, Search, Users } from "lucide-react";
import Link from "next/link";
import { useEffect, useState } from "react";

export function OrganizationsBrowseView() {
  const [organizations, setOrganizations] = useState<Organization[]>([]);
  const [q, setQ] = useState("");
  const [location, setLocation] = useState("");
  const [industry, setIndustry] = useState("");
  const [applied, setApplied] = useState({ q: "", location: "", industry: "" });
  const [page, setPage] = useState(1);
  const [lastPage, setLastPage] = useState(1);
  const [total, setTotal] = useState(0);
  const [isLoading, setIsLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    let cancelled = false;
    setIsLoading(true);
    listOrganizations({
      q: applied.q.trim() || undefined,
      location: applied.location.trim() || undefined,
      industry: applied.industry.trim() || undefined,
      page,
      per_page: 12,
    })
      .then((response) => {
        if (cancelled) return;
        setOrganizations(response.data);
        setLastPage(response.last_page ?? 1);
        setTotal(response.total ?? response.data.length);
      })
      .catch(() => {
        if (!cancelled) setError("Unable to load organizations.");
      })
      .finally(() => {
        if (!cancelled) setIsLoading(false);
      });

    return () => {
      cancelled = true;
    };
  }, [applied, page]);

  return (
    <div className="min-h-screen bg-roicard-bg">
      <header className="border-b border-roicard-border bg-roicard-bg-elevated/80 backdrop-blur-xl">
        <div className="mx-auto flex h-16 w-full max-w-5xl items-center justify-between px-4 sm:px-6">
          <Link href="/"><BrandLogo height={24} /></Link>
          <Link href="/auth/login">
            <Button variant="secondary" size="sm" className="rounded-xl">Sign in</Button>
          </Link>
        </div>
      </header>

      <main className="mx-auto w-full max-w-5xl space-y-8 px-4 py-8 sm:px-6">
        <div>
          <span className="inline-flex items-center gap-2 rounded-full bg-roicard-primary/15 px-3 py-1 text-xs font-medium text-roicard-accent">
            <Building2 className="h-3.5 w-3.5" />
            Organizations
          </span>
          <h1 className="mt-3 text-2xl font-bold text-roicard-text sm:text-3xl">
            Verified organizations on ROICARD
          </h1>
          <p className="mt-2 text-sm text-roicard-text-muted sm:text-base">
            Browse approved organizations and their publicly affiliated members.
          </p>
        </div>

        <form
          onSubmit={(event) => {
            event.preventDefault();
            setPage(1);
            setApplied({ q, location, industry });
          }}
          className="grid gap-3 rounded-2xl border border-roicard-border bg-roicard-bg-elevated p-4 sm:grid-cols-3"
        >
          <Input
            label="Search"
            placeholder="Name, industry or service"
            value={q}
            onChange={(event) => setQ(event.target.value)}
          />
          <Input
            label="Location"
            placeholder="City or country"
            value={location}
            onChange={(event) => setLocation(event.target.value)}
          />
          <div className="flex items-end gap-2">
            <Input
              label="Industry"
              placeholder="e.g. Consulting"
              value={industry}
              onChange={(event) => setIndustry(event.target.value)}
            />
            <Button type="submit" className="mb-0.5 rounded-xl">
              <Search className="h-4 w-4" />
            </Button>
          </div>
        </form>

        {error && (
          <p className="rounded-lg border border-rose-500/30 bg-rose-500/10 px-3 py-2 text-sm text-rose-400">
            {error}
          </p>
        )}

        {isLoading ? (
          <div className="flex min-h-[30vh] items-center justify-center">
            <div className="h-8 w-8 animate-spin rounded-full border-2 border-roicard-border border-t-roicard-primary" />
          </div>
        ) : organizations.length === 0 ? (
          <div className="rounded-2xl border border-dashed border-roicard-border bg-roicard-bg-elevated p-8 text-center">
            <p className="text-sm text-roicard-text-muted">
              No verified organizations match these filters yet.
            </p>
          </div>
        ) : (
          <>
            <p className="text-sm text-roicard-text-muted">{total} organization(s)</p>
            <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
              {organizations.map((organization) => (
                <Link
                  key={organization.id}
                  href={`/organizations/${organization.slug}`}
                  className="glass-card rounded-2xl p-5 transition-colors hover:border-roicard-accent/30"
                >
                  <div className="flex items-center gap-3">
                    {organization.logo_url ? (
                      // eslint-disable-next-line @next/next/no-img-element
                      <img
                        src={organization.logo_url}
                        alt={organization.name}
                        className="h-14 w-14 rounded-xl border border-roicard-border bg-white object-contain p-1"
                      />
                    ) : (
                      <span className="flex h-14 w-14 items-center justify-center rounded-xl bg-roicard-primary/10 text-lg font-bold text-roicard-accent">
                        {organization.name.slice(0, 1).toUpperCase()}
                      </span>
                    )}
                    <div className="min-w-0">
                      <h2 className="truncate font-semibold text-roicard-text">{organization.name}</h2>
                      <p className="truncate text-sm text-roicard-text-muted">
                        {organization.industry || "Verified organization"}
                      </p>
                    </div>
                  </div>
                  {organization.description && (
                    <p className="mt-3 line-clamp-2 text-sm text-roicard-text-muted">
                      {organization.description}
                    </p>
                  )}
                  <div className="mt-3 flex flex-wrap gap-x-3 gap-y-1 text-xs text-roicard-text-muted">
                    {organization.location && (
                      <span className="inline-flex items-center gap-1">
                        <MapPin className="h-3.5 w-3.5" />
                        {organization.location}
                      </span>
                    )}
                    <span className="inline-flex items-center gap-1">
                      <Users className="h-3.5 w-3.5" />
                      {organization.members_count} member(s)
                    </span>
                  </div>
                </Link>
              ))}
            </div>

            {lastPage > 1 && (
              <div className="flex items-center justify-between">
                <Button
                  variant="secondary"
                  size="sm"
                  disabled={page <= 1}
                  onClick={() => setPage((value) => Math.max(1, value - 1))}
                >
                  Previous
                </Button>
                <span className="text-xs text-roicard-text-muted">Page {page} of {lastPage}</span>
                <Button
                  variant="secondary"
                  size="sm"
                  disabled={page >= lastPage}
                  onClick={() => setPage((value) => Math.min(lastPage, value + 1))}
                >
                  Next
                </Button>
              </div>
            )}
          </>
        )}
      </main>
    </div>
  );
}
