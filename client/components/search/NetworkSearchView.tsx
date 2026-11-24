/**
 * NetworkSearchView
 *
 * Signed-in network-wide search. Filters, ranking and visibility are applied
 * by the backend; this view only renders permitted results.
 */

"use client";

import { ConnectModal } from "@/components/network/ConnectModal";
import { NetworkPersonCard } from "@/components/network/NetworkPersonCard";
import { ReportMemberModal } from "@/components/network/ReportMemberModal";
import { AiConsentNotice } from "@/components/ai/AiConsentNotice";
import { Button } from "@/components/ui/Button";
import { useConfirm } from "@/components/ui/ConfirmProvider";
import { Input } from "@/components/ui/Input";
import { useAiStatus } from "@/hooks/useAiStatus";
import { interpretSearch, type AiSearchInterpretation } from "@/lib/api/ai";
import { blockMember } from "@/lib/api/safety";
import {
  fullName,
  getCommunityOptions,
  searchNetwork,
  type CommunityOption,
  type NetworkProfile,
  type SearchFilters,
  type SearchResponse,
} from "@/lib/api/network";
import { getCategories, type CategoryOption } from "@/lib/api/profile";
import { listOrganizations, type Organization } from "@/lib/api/organizations";
import { saveNetworkContact, shareNetworkProfile } from "@/lib/network/actions";
import { ChevronLeft, ChevronRight, Building2, Loader2, Search, Sparkles } from "lucide-react";
import Link from "next/link";
import { useCallback, useEffect, useMemo, useState } from "react";

type FilterState = {
  q: string;
  resultType: "" | "person" | "business";
  category: string;
  location: string;
  community: string;
  organization: string;
  organizationId: string;
  verified: boolean;
};

const EMPTY_FILTERS: FilterState = {
  q: "",
  resultType: "",
  category: "",
  location: "",
  community: "",
  organization: "",
  organizationId: "",
  verified: false,
};

function toFilters(state: FilterState, page: number): SearchFilters {
  return {
    q: state.q.trim() || undefined,
    result_type: state.resultType || undefined,
    category: state.category || undefined,
    location: state.location.trim() || undefined,
    community: state.community || undefined,
    organization: state.organization.trim() || undefined,
    organization_id: state.organizationId ? Number(state.organizationId) : undefined,
    verified: state.verified || undefined,
    per_page: 12,
    page,
  };
}

function flattenCategoryOptions(options: CategoryOption[]): CategoryOption[] {
  return options.flatMap((option) => [option, ...flattenCategoryOptions(option.children ?? [])]);
}

export function NetworkSearchView() {
  const { confirm } = useConfirm();
  const [filters, setFilters] = useState<FilterState>(EMPTY_FILTERS);
  const [applied, setApplied] = useState<SearchFilters>(toFilters(EMPTY_FILTERS, 1));
  const [results, setResults] = useState<SearchResponse | null>(null);
  const [categories, setCategories] = useState<CategoryOption[]>([]);
  const [communities, setCommunities] = useState<CommunityOption[]>([]);
  const [organizations, setOrganizations] = useState<Organization[]>([]);
  const [isLoading, setIsLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [status, setStatus] = useState<string | null>(null);
  const [connectTarget, setConnectTarget] = useState<NetworkProfile | null>(null);
  const [reportTarget, setReportTarget] = useState<NetworkProfile | null>(null);
  const [aiBusy, setAiBusy] = useState(false);
  const [aiMessage, setAiMessage] = useState<string | null>(null);
  const [interpretation, setInterpretation] = useState<AiSearchInterpretation | null>(null);
  const { status: aiStatus, loading: aiLoading, error: aiStatusError, accept: acceptAi, refresh: refreshAi } = useAiStatus();
  const flatCategories = useMemo(() => flattenCategoryOptions(categories), [categories]);

  useEffect(() => {
    Promise.all([
      getCategories().catch(() => []),
      getCommunityOptions().catch(() => []),
      listOrganizations({ per_page: 50 }).catch(() => ({ data: [] as Organization[] })),
    ]).then(([categoryRows, communityRows, organizationRows]) => {
      setCategories(categoryRows);
      setCommunities(communityRows);
      setOrganizations(organizationRows.data);
    });
  }, []);

  const load = useCallback(async (nextFilters: SearchFilters) => {
    setIsLoading(true);
    setError(null);
    try {
      const response = await searchNetwork(nextFilters);
      setResults(response);
    } catch (e) {
      const code = (e as { response?: { status?: number } })?.response?.status;
      setError(
        code === 429
          ? "Too many searches in a short period. Please wait a minute and try again."
          : "Unable to search the network right now."
      );
    } finally {
      setIsLoading(false);
    }
  }, []);

  useEffect(() => {
    void load(applied);
  }, [applied, load]);

  const applyFilters = () => {
    setStatus(null);
    setApplied(toFilters(filters, 1));
  };

  const clearFilters = () => {
    setFilters(EMPTY_FILTERS);
    setApplied(toFilters(EMPTY_FILTERS, 1));
    setInterpretation(null);
    setAiMessage(null);
  };

  const aiBlocked = Boolean(
    aiStatus &&
      (!aiStatus.configured ||
        aiStatus.consent_required ||
        (aiStatus.remaining_today !== null && aiStatus.remaining_today <= 0))
  );

  const handleInterpret = async () => {
    if (!filters.q.trim()) {
      setAiMessage("Type what you are looking for first.");
      return;
    }

    setAiBusy(true);
    setAiMessage(null);
    setError(null);
    setInterpretation(null);

    try {
      const response = await interpretSearch(filters.q.trim());
      setAiMessage(response.message);

      if (!response.available || !response.interpretation) {
        return;
      }

      const next = response.interpretation;
      setInterpretation(next);

      const matchedCategory = next.filters.category
        ? flatCategories.find(
            (category) =>
              category.slug === next.filters.category ||
              category.name === next.category_name
          )
        : undefined;

      // Populate the form for review. The search itself is not executed until
      // the member presses Apply filters.
      setFilters({
        q: next.filters.q ?? filters.q,
        resultType: next.filters.result_type ?? "",
        category: matchedCategory ? String(matchedCategory.id) : "",
        location: next.filters.location ?? "",
        community: next.filters.community ?? "",
        organization: next.filters.organization ?? "",
        organizationId: "",
        verified: next.filters.verified,
      });
    } catch (e) {
      const data = (e as { response?: { data?: { message?: string; errors?: Record<string, string[]> } } })
        .response?.data;
      const consentMessage = data?.errors?.consent_required?.[0];
      setError(consentMessage ?? data?.message ?? "Unable to interpret that search right now.");
      if (consentMessage) void refreshAi();
    } finally {
      setAiBusy(false);
    }
  };

  const goToPage = (page: number) => {
    const next = { ...applied, page };
    setApplied(next);
    if (typeof window !== "undefined") {
      window.scrollTo({ top: 0, behavior: "smooth" });
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
        "You will no longer see each other in search, discovery, recommendations or connections.",
      confirmLabel: "Block member",
      variant: "danger",
    });
    if (!confirmed) return;

    setStatus(null);
    setError(null);
    try {
      const response = await blockMember({ profileId: profile.id });
      setResults((current) =>
        current
          ? {
              ...current,
              data: current.data.filter((item) => item.profile.id !== profile.id),
              total: Math.max(0, current.total - 1),
            }
          : current
      );
      setStatus(response.message);
    } catch {
      setError("Unable to block this member.");
    }
  };

  return (
    <div className="space-y-8">
      <header className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div>
          <span className="inline-flex w-fit items-center gap-2 rounded-full bg-roicard-primary/15 px-3 py-1 text-xs font-medium text-roicard-accent">
            <Search className="h-3.5 w-3.5" aria-hidden />
            Network search
          </span>
          <h1 className="mt-3 text-2xl font-bold text-roicard-text sm:text-3xl">
            Find people and businesses
          </h1>
          <p className="mt-2 text-sm text-roicard-text-muted sm:text-base">
            Search names, roles, organisations, skills, services and needs.
          </p>
        </div>
        <Link href="/organizations">
          <Button variant="secondary" className="rounded-xl">
            <Building2 className="h-4 w-4" />
            Browse organizations
          </Button>
        </Link>
      </header>

      <form
        className="space-y-4 rounded-2xl border border-roicard-border bg-roicard-bg-elevated p-4 sm:p-5"
        onSubmit={(event) => {
          event.preventDefault();
          applyFilters();
        }}
      >
        <div className="flex flex-col gap-3 sm:flex-row">
          <div className="flex-1">
            <Input
              label="Search"
              placeholder="Name, role, organisation, skill, service or need"
              value={filters.q}
              maxLength={120}
              onChange={(event) => setFilters({ ...filters, q: event.target.value })}
            />
          </div>
          <div className="flex items-end gap-2">
            <Button type="submit" className="rounded-xl">
              <Search className="h-4 w-4" />
              Search
            </Button>
            <Button
              type="button"
              variant="secondary"
              className="rounded-xl"
              onClick={handleInterpret}
              disabled={aiBusy || aiLoading || aiBlocked || !filters.q.trim()}
            >
              {aiBusy ? <Loader2 className="h-4 w-4 animate-spin" /> : <Sparkles className="h-4 w-4" />}
              Interpret with AI
            </Button>
            <Button type="button" variant="secondary" className="rounded-xl" onClick={clearFilters}>
              Clear
            </Button>
          </div>
        </div>

        <AiConsentNotice
          status={aiStatus}
          loading={aiLoading}
          error={aiStatusError}
          onAccept={acceptAi}
          className="mt-1"
        />
        {aiMessage && (
          <p className="rounded-lg border border-amber-500/30 bg-amber-500/10 px-3 py-2 text-xs text-amber-300">
            {aiMessage}
          </p>
        )}

        <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
          <div>
            <label htmlFor="search-result-type" className="mb-2 block text-sm font-medium text-roicard-text">
              Result type
            </label>
            <select
              id="search-result-type"
              value={filters.resultType}
              onChange={(event) =>
                setFilters({
                  ...filters,
                  resultType: event.target.value as FilterState["resultType"],
                })
              }
              className="h-11 w-full rounded-lg border border-roicard-border bg-roicard-bg-muted px-3 text-sm text-roicard-text"
            >
              <option value="">People and businesses</option>
              <option value="person">People</option>
              <option value="business">Businesses</option>
            </select>
          </div>

          <div>
            <label htmlFor="search-category" className="mb-2 block text-sm font-medium text-roicard-text">
              Category
            </label>
            <select
              id="search-category"
              value={filters.category}
              onChange={(event) => setFilters({ ...filters, category: event.target.value })}
              className="h-11 w-full rounded-lg border border-roicard-border bg-roicard-bg-muted px-3 text-sm text-roicard-text"
            >
              <option value="">All categories</option>
              {flatCategories.map((category) => (
                <option key={category.id} value={String(category.id)}>
                  {category.name} ({category.kind})
                </option>
              ))}
            </select>
          </div>

          <div>
            <label htmlFor="search-community" className="mb-2 block text-sm font-medium text-roicard-text">
              Community
            </label>
            <select
              id="search-community"
              value={filters.community}
              onChange={(event) => setFilters({ ...filters, community: event.target.value })}
              className="h-11 w-full rounded-lg border border-roicard-border bg-roicard-bg-muted px-3 text-sm text-roicard-text"
            >
              <option value="">All communities</option>
              {communities.map((community) => (
                <option key={community.id} value={community.slug}>
                  {community.name}
                </option>
              ))}
            </select>
          </div>

          <Input
            label="Location"
            placeholder="City or country"
            value={filters.location}
            maxLength={120}
            onChange={(event) => setFilters({ ...filters, location: event.target.value })}
          />
          <Input
            label="Organisation"
            placeholder="Company or organisation"
            value={filters.organization}
            maxLength={120}
            onChange={(event) => setFilters({ ...filters, organization: event.target.value })}
          />
          <div>
            <label htmlFor="search-organization" className="mb-2 block text-sm font-medium text-roicard-text">
              Affiliated organization
            </label>
            <select
              id="search-organization"
              value={filters.organizationId}
              onChange={(event) => setFilters({ ...filters, organizationId: event.target.value })}
              className="h-11 w-full rounded-lg border border-roicard-border bg-roicard-bg-muted px-3 text-sm text-roicard-text"
            >
              <option value="">All organizations</option>
              {organizations.map((organization) => (
                <option key={organization.id} value={String(organization.id)}>
                  {organization.name}
                </option>
              ))}
            </select>
          </div>
          <div className="flex items-end pb-3">
            <label className="flex items-center gap-2 text-sm text-roicard-text">
              <input
                type="checkbox"
                checked={filters.verified}
                onChange={(event) => setFilters({ ...filters, verified: event.target.checked })}
                className="h-4 w-4 rounded border-roicard-border"
              />
              Verified members only
            </label>
          </div>
        </div>

        <div className="flex flex-wrap items-center justify-between gap-3">
          <p className="text-xs text-roicard-text-muted">
            {results
              ? `${results.total} result${results.total === 1 ? "" : "s"} · page ${
                  results.current_page
                } of ${results.last_page}`
              : "Run a search to see results."}
          </p>
          <Button type="submit" variant="secondary" className="rounded-xl">
            Apply filters
          </Button>
        </div>
      </form>

      {interpretation && (
        <div className="rounded-2xl border border-roicard-accent/30 bg-roicard-accent/5 p-4">
          <p className="flex items-center gap-2 text-xs font-semibold uppercase tracking-wider text-roicard-accent">
            <Sparkles className="h-3.5 w-3.5" />
            Interpreted search
          </p>
          <p className="mt-2 text-sm text-roicard-text">{interpretation.summary}</p>
          <div className="mt-3 flex flex-wrap gap-2 text-xs">
            {interpretation.filters.result_type && (
              <span className="rounded-full bg-roicard-bg-muted px-2.5 py-1 text-roicard-text">
                {interpretation.filters.result_type === "business" ? "Businesses" : "People"}
              </span>
            )}
            {interpretation.filters.q && (
              <span className="rounded-full bg-roicard-bg-muted px-2.5 py-1 text-roicard-text">
                “{interpretation.filters.q}”
              </span>
            )}
            {(interpretation.category_name || interpretation.filters.category) && (
              <span className="rounded-full bg-roicard-bg-muted px-2.5 py-1 text-roicard-text">
                {interpretation.category_name ?? interpretation.filters.category}
              </span>
            )}
            {interpretation.filters.location && (
              <span className="rounded-full bg-roicard-bg-muted px-2.5 py-1 text-roicard-text">
                {interpretation.filters.location}
              </span>
            )}
            {interpretation.filters.organization && (
              <span className="rounded-full bg-roicard-bg-muted px-2.5 py-1 text-roicard-text">
                {interpretation.filters.organization}
              </span>
            )}
            {interpretation.community_name && (
              <span className="rounded-full bg-roicard-bg-muted px-2.5 py-1 text-roicard-text">
                {interpretation.community_name}
              </span>
            )}
            {interpretation.filters.verified && (
              <span className="rounded-full bg-roicard-bg-muted px-2.5 py-1 text-roicard-text">
                Verified only
              </span>
            )}
          </div>
          <p className="mt-3 text-xs text-roicard-text-muted">
            Review and correct the filters above. Results have not been loaded yet.
          </p>
          <div className="mt-3 flex flex-wrap gap-2">
            <Button size="sm" className="rounded-lg" onClick={applyFilters}>
              <Search className="h-3.5 w-3.5" />
              Apply interpreted filters
            </Button>
            <Button
              size="sm"
              variant="secondary"
              className="rounded-lg"
              onClick={() => setInterpretation(null)}
            >
              Dismiss
            </Button>
          </div>
        </div>
      )}

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
        <div className="flex min-h-[30vh] items-center justify-center">
          <div
            className="h-8 w-8 animate-spin rounded-full border-2 border-roicard-border border-t-roicard-primary"
            role="status"
            aria-label="Searching"
          />
        </div>
      ) : !results || results.data.length === 0 ? (
        <div className="rounded-2xl border border-dashed border-roicard-border bg-roicard-bg-elevated p-8 text-center">
          <h2 className="text-lg font-semibold text-roicard-text">No results found</h2>
          <p className="mx-auto mt-2 max-w-md text-sm text-roicard-text-muted">
            Try a broader search term, or clear one of the filters. Only published,
            searchable profiles visible to you are returned.
          </p>
        </div>
      ) : (
        <>
          <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
            {results.data.map((item) => (
              <NetworkPersonCard entrySource="search"
                key={item.profile.id}
                profile={item.profile}
                sharedCommunities={item.shared_communities}
                compact
                onConnect={setConnectTarget}
                onSaveContact={handleSaveContact}
                onShare={handleShare}
                onReport={setReportTarget}
                onBlock={handleBlock}
              />
            ))}
          </div>

          {results.last_page > 1 && (
            <div className="flex items-center justify-between gap-4">
              <Button
                variant="secondary"
                size="sm"
                className="rounded-lg"
                disabled={results.current_page <= 1}
                onClick={() => goToPage(results.current_page - 1)}
              >
                <ChevronLeft className="h-4 w-4" />
                Previous
              </Button>
              <p className="text-sm text-roicard-text-muted">
                Page {results.current_page} of {results.last_page}
              </p>
              <Button
                variant="secondary"
                size="sm"
                className="rounded-lg"
                disabled={results.current_page >= results.last_page}
                onClick={() => goToPage(results.current_page + 1)}
              >
                Next
                <ChevronRight className="h-4 w-4" />
              </Button>
            </div>
          )}
        </>
      )}

      <ConnectModal
        isOpen={connectTarget !== null}
        onClose={() => setConnectTarget(null)}
        profile={connectTarget}
        onSent={(message) => setStatus(message)}
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
