"use client";

import { AiConsentNotice } from "@/components/ai/AiConsentNotice";
import { Button } from "@/components/ui/Button";
import { Input } from "@/components/ui/Input";
import { Textarea } from "@/components/ui/Textarea";
import { useAiStatus } from "@/hooks/useAiStatus";
import {
  approveCatalogDraftItems,
  deleteCatalogDraftItem,
  requestCatalogDraft,
  type AiCatalogDraft,
} from "@/lib/api/ai";
import { approveAiDraftFields, type CategoryOption, type Profile } from "@/lib/api/profile";
import { Check, Loader2, Sparkles, Trash2 } from "lucide-react";
import { useEffect, useState } from "react";

type Props = {
  profile: Profile;
  categories: CategoryOption[];
  onChanged: () => void | Promise<void>;
};

function errorMessage(error: unknown, fallback: string): string {
  return (
    (error as { response?: { data?: { message?: string } } })?.response?.data?.message ?? fallback
  );
}

export function CatalogAiDraftPanel({ profile, categories, onChanged }: Props) {
  const { status, loading, error: statusError, accept, refresh } = useAiStatus();
  const [sourceText, setSourceText] = useState("");
  const [websiteUrl, setWebsiteUrl] = useState(profile.website_url ?? "");
  const [busy, setBusy] = useState(false);
  const [message, setMessage] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [catalogDraft, setCatalogDraft] = useState<AiCatalogDraft | null>(
    (profile.ai_catalog_draft as AiCatalogDraft | null) ?? null
  );
  const [aboutDraft, setAboutDraft] = useState<string | null>(null);
  const [aboutApproved, setAboutApproved] = useState(false);
  const [approvingItem, setApprovingItem] = useState<string | null>(null);

  useEffect(() => {
    setCatalogDraft((profile.ai_catalog_draft as AiCatalogDraft | null) ?? null);
    setAboutDraft(
      typeof profile.ai_draft?.about === "string" ? (profile.ai_draft.about as string) : null
    );
    setAboutApproved((profile.ai_approved_fields ?? []).includes("about"));
    setWebsiteUrl(profile.website_url ?? "");
  }, [profile.id, profile.ai_catalog_draft, profile.ai_draft, profile.ai_approved_fields, profile.website_url]);

  const aiBlocked = Boolean(
    status &&
      (!status.configured ||
        status.consent_required ||
        (status.remaining_today !== null && status.remaining_today <= 0))
  );

  const proposedItems = (catalogDraft?.items ?? []).filter((item) => item.status !== "approved");

  const handleGenerate = async () => {
    if (!sourceText.trim() && !websiteUrl.trim()) {
      setError("Add a website link or a short business description first.");
      return;
    }

    setBusy(true);
    setError(null);
    setMessage(null);
    try {
      const response = await requestCatalogDraft(profile.id, {
        source_text: sourceText || undefined,
        website_url: websiteUrl || undefined,
      });
      setCatalogDraft(response.catalog_draft ?? null);
      setAboutDraft(response.business_about ?? null);
      setAboutApproved(false);
      setMessage(response.message);
      if (response.available) {
        await onChanged();
      }
    } catch (e) {
      const data = (e as { response?: { data?: { message?: string; errors?: Record<string, string[]> } } })
        .response?.data;
      const consentMessage = data?.errors?.consent_required?.[0];
      setError(consentMessage ?? data?.message ?? "Unable to draft catalog content. You can continue manually.");
      if (consentMessage) void refresh();
    } finally {
      setBusy(false);
    }
  };

  const handleApproveAbout = async () => {
    setBusy(true);
    setError(null);
    try {
      const response = await approveAiDraftFields(profile.id, ["about"]);
      setAboutApproved((response.profile.ai_approved_fields ?? []).includes("about"));
      setMessage("Business About text approved.");
      await onChanged();
    } catch (e) {
      setError(errorMessage(e, "Unable to approve the business About text."));
    } finally {
      setBusy(false);
    }
  };

  const handleApproveItem = async (itemId: string) => {
    setApprovingItem(itemId);
    setError(null);
    try {
      const response = await approveCatalogDraftItems(profile.id, [itemId]);
      setCatalogDraft(response.catalog_draft ?? catalogDraft);
      setMessage(response.message);
      await onChanged();
    } catch (e) {
      setError(errorMessage(e, "Unable to approve that catalog item."));
    } finally {
      setApprovingItem(null);
    }
  };

  const handleDismissItem = async (itemId: string) => {
    setApprovingItem(itemId);
    setError(null);
    try {
      const response = await deleteCatalogDraftItem(profile.id, itemId);
      setCatalogDraft(response.catalog_draft);
      setMessage(response.message);
    } catch (e) {
      setError(errorMessage(e, "Unable to remove that proposed item."));
    } finally {
      setApprovingItem(null);
    }
  };

  return (
    <div className="rounded-2xl border border-roicard-border bg-roicard-bg-elevated p-5 print:hidden">
      <div className="flex items-start gap-3">
        <Sparkles className="mt-0.5 h-5 w-5 shrink-0 text-roicard-accent" />
        <div>
          <h2 className="text-sm font-semibold text-roicard-text">Draft business content with AI</h2>
          <p className="mt-1 text-xs text-roicard-text-muted">
            Add your website or describe the business. AI proposes About text and catalog
            items; nothing is added until you approve each item.
          </p>
        </div>
      </div>

      <AiConsentNotice
        status={status}
        loading={loading}
        error={statusError}
        onAccept={accept}
        className="mt-4"
      />

      <div className="mt-4 grid gap-3 lg:grid-cols-2">
        <Textarea
          rows={4}
          label="Business description"
          placeholder="Describe what the business sells, who it serves and what makes it different."
          value={sourceText}
          onChange={(event) => setSourceText(event.target.value)}
        />
        <Input
          label="Website (optional)"
          type="url"
          placeholder="https://your-business.com"
          value={websiteUrl}
          onChange={(event) => setWebsiteUrl(event.target.value)}
        />
      </div>

      <div className="mt-3 flex flex-wrap items-center gap-2">
        <Button type="button" size="sm" onClick={handleGenerate} disabled={busy || loading || aiBlocked}>
          {busy ? <Loader2 className="h-4 w-4 animate-spin" /> : <Sparkles className="h-4 w-4" />}
          Draft business and catalog
        </Button>
        {status?.remaining_today !== null && status?.remaining_today !== undefined && (
          <span className="text-xs text-roicard-text-muted">
            {status.remaining_today} AI call{status.remaining_today === 1 ? "" : "s"} left today
          </span>
        )}
      </div>

      {message && (
        <p className="mt-3 rounded-lg border border-amber-500/30 bg-amber-500/10 px-3 py-2 text-xs text-amber-300">
          {message}
        </p>
      )}
      {error && (
        <p className="mt-3 rounded-lg border border-rose-500/30 bg-rose-500/10 px-3 py-2 text-xs text-rose-400">
          {error}
        </p>
      )}

      {aboutDraft && (
        <div className="mt-4 rounded-xl border border-roicard-border bg-roicard-bg-muted/30 p-4">
          <div className="flex flex-wrap items-center justify-between gap-2">
            <p className="text-xs font-semibold uppercase tracking-wider text-roicard-accent">
              Proposed business About
            </p>
            {aboutApproved ? (
              <span className="flex items-center gap-1 text-xs font-medium text-emerald-400">
                <Check className="h-3.5 w-3.5" />
                Approved
              </span>
            ) : (
              <Button size="sm" variant="secondary" onClick={handleApproveAbout} disabled={busy}>
                Approve About
              </Button>
            )}
          </div>
          <p className="mt-2 whitespace-pre-line text-sm text-roicard-text">{aboutDraft}</p>
        </div>
      )}

      {proposedItems.length > 0 && (
        <div className="mt-4 space-y-3">
          <p className="text-xs font-semibold uppercase tracking-wider text-roicard-accent">
            Proposed catalog items
          </p>
          {proposedItems.map((item) => (
            <div key={item.id} className="rounded-xl border border-roicard-border bg-roicard-bg-muted/30 p-4">
              <div className="flex flex-wrap items-start justify-between gap-2">
                <div>
                  <p className="font-medium text-roicard-text">{item.title}</p>
                  <p className="text-xs text-roicard-text-muted">
                    {item.type}
                    {item.category_name ? ` · ${item.category_name}` : ""}
                  </p>
                </div>
                <div className="flex gap-2">
                  <Button
                    size="sm"
                    variant="secondary"
                    onClick={() => handleApproveItem(item.id)}
                    disabled={approvingItem !== null}
                  >
                    {approvingItem === item.id ? (
                      <Loader2 className="h-3.5 w-3.5 animate-spin" />
                    ) : (
                      <Check className="h-3.5 w-3.5" />
                    )}
                    Approve item
                  </Button>
                  <Button
                    size="sm"
                    variant="ghost"
                    onClick={() => handleDismissItem(item.id)}
                    disabled={approvingItem !== null}
                    aria-label="Remove proposed item"
                  >
                    <Trash2 className="h-3.5 w-3.5" />
                  </Button>
                </div>
              </div>
              {item.description && (
                <p className="mt-2 whitespace-pre-line text-sm text-roicard-text-muted">
                  {item.description}
                </p>
              )}
              {item.category_id === null && (
                <p className="mt-2 text-xs text-amber-300">
                  No matching offering category yet. After approval you can assign one in the
                  catalog editor.
                </p>
              )}
            </div>
          ))}
        </div>
      )}

      {!aboutDraft && proposedItems.length === 0 && catalogDraft?.items && catalogDraft.items.length > 0 && (
        <p className="mt-4 text-xs text-roicard-text-muted">
          All previously proposed items have been reviewed.
        </p>
      )}

      <p className="mt-4 text-xs text-roicard-text-muted">
        Category options available: {categories.length > 0 ? `${categories.length} offering categories` : "none configured"}.
      </p>
    </div>
  );
}
