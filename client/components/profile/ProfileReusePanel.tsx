"use client";

import { AiConsentNotice } from "@/components/ai/AiConsentNotice";
import { Button } from "@/components/ui/Button";
import { useAiStatus } from "@/hooks/useAiStatus";
import { suggestProfileReuse } from "@/lib/api/ai";
import { approveAiDraftFields, type AiProfileDraft, type Profile } from "@/lib/api/profile";
import { Check, Loader2, Sparkles } from "lucide-react";
import { useEffect, useMemo, useState } from "react";

type Props = {
  profile: Profile;
  profiles: Profile[];
  onChanged: () => void | Promise<void>;
};

export function ProfileReusePanel({ profile, profiles, onChanged }: Props) {
  const { status, loading, error: statusError, accept, refresh } = useAiStatus();
  const [sourceId, setSourceId] = useState("");
  const [busy, setBusy] = useState(false);
  const [message, setMessage] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [draft, setDraft] = useState<AiProfileDraft | null>(
    (profile.ai_draft as AiProfileDraft | null) ?? null
  );
  const [approvedFields, setApprovedFields] = useState<string[]>(profile.ai_approved_fields ?? []);
  const [approvingField, setApprovingField] = useState<string | null>(null);

  const candidates = useMemo(
    () => profiles.filter((candidate) => candidate.id !== profile.id),
    [profile.id, profiles]
  );

  useEffect(() => {
    setDraft((profile.ai_draft as AiProfileDraft | null) ?? null);
    setApprovedFields(profile.ai_approved_fields ?? []);
  }, [profile.id, profile.ai_draft, profile.ai_approved_fields]);

  const generatedDraftFields = useMemo(() => {
    if (!draft || draft.source !== "ai") return [];
    return ["headline", "about", "experience", "skills", "services", "seeking", "offering"].filter(
      (field) => {
        const value = draft[field];
        return (
          value !== undefined &&
          value !== null &&
          value !== "" &&
          !(Array.isArray(value) && value.length === 0)
        );
      }
    );
  }, [draft]);

  const aiBlocked = Boolean(
    status &&
      (!status.configured ||
        status.consent_required ||
        (status.remaining_today !== null && status.remaining_today <= 0))
  );

  const handleSuggest = async () => {
    if (!sourceId) {
      setError("Choose a profile to reuse details from.");
      return;
    }

    setBusy(true);
    setError(null);
    setMessage(null);
    try {
      const response = await suggestProfileReuse(profile.id, Number(sourceId));
      setDraft(response.draft ?? (response.profile?.ai_draft as AiProfileDraft | null) ?? null);
      setApprovedFields(response.profile?.ai_approved_fields ?? []);
      setMessage(response.message);
      if (response.available) {
        await onChanged();
      }
    } catch (e) {
      const data = (e as { response?: { data?: { message?: string; errors?: Record<string, string[]> } } })
        .response?.data;
      const consentMessage = data?.errors?.consent_required?.[0];
      setError(
        consentMessage ??
          data?.errors?.source_profile_id?.[0] ??
          data?.message ??
          "Unable to suggest reused details."
      );
      if (consentMessage) void refresh();
    } finally {
      setBusy(false);
    }
  };

  const handleApproveField = async (field: string) => {
    setApprovingField(field);
    setError(null);
    try {
      const response = await approveAiDraftFields(profile.id, [field]);
      setDraft((response.profile.ai_draft as AiProfileDraft | null) ?? draft);
      setApprovedFields(response.profile.ai_approved_fields ?? [...approvedFields, field]);
      setMessage("Approved field applied to this profile.");
      await onChanged();
    } catch (e) {
      setError(
        (e as { response?: { data?: { message?: string } } })?.response?.data?.message ??
          "Unable to approve that field."
      );
    } finally {
      setApprovingField(null);
    }
  };

  return (
    <div className="rounded-2xl border border-roicard-border bg-roicard-bg-elevated p-5">
      <div className="flex items-start gap-3">
        <Sparkles className="mt-0.5 h-5 w-5 shrink-0 text-roicard-accent" />
        <div>
          <h2 className="text-sm font-semibold text-roicard-text">Reuse details from another profile</h2>
          <p className="mt-1 text-xs text-roicard-text-muted">
            AI can rewrite relevant details from another profile you own for this
            profile&apos;s context. Every field is proposed first and needs your approval.
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

      {candidates.length === 0 ? (
        <p className="mt-4 text-xs text-roicard-text-muted">
          Add a second personal or business profile before reusing details.
        </p>
      ) : (
        <>
          <div className="mt-4 flex flex-col gap-3 sm:flex-row sm:items-end">
            <div className="flex-1">
              <label htmlFor={`reuse-source-${profile.id}`} className="mb-2 block text-sm font-medium text-roicard-text">
                Reuse from
              </label>
              <select
                id={`reuse-source-${profile.id}`}
                value={sourceId}
                onChange={(event) => setSourceId(event.target.value)}
                className="h-11 w-full rounded-lg border border-roicard-border bg-roicard-bg-muted px-3 text-sm text-roicard-text"
              >
                <option value="">Choose a profile</option>
                {candidates.map((candidate) => (
                  <option key={candidate.id} value={String(candidate.id)}>
                    {candidate.display_name || candidate.title || `Profile ${candidate.id}`} (
                    {candidate.profile_type})
                  </option>
                ))}
              </select>
            </div>
            <Button
              onClick={handleSuggest}
              disabled={busy || loading || aiBlocked || !sourceId}
              className="rounded-xl"
            >
              {busy ? <Loader2 className="h-4 w-4 animate-spin" /> : <Sparkles className="h-4 w-4" />}
              Suggest with AI
            </Button>
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

          {generatedDraftFields.length > 0 && (
            <div className="mt-4 space-y-3">
              {generatedDraftFields.map((field) => {
                const value = draft?.[field];
                const approved = approvedFields.includes(field);
                return (
                  <div key={field} className="rounded-xl border border-roicard-border bg-roicard-bg-muted/30 p-4">
                    <div className="flex items-center justify-between gap-3">
                      <p className="text-xs font-semibold uppercase tracking-wider text-roicard-accent">
                        {field}
                      </p>
                      {approved ? (
                        <span className="flex items-center gap-1 text-xs font-medium text-emerald-400">
                          <Check className="h-3.5 w-3.5" />
                          Approved
                        </span>
                      ) : (
                        <Button
                          size="sm"
                          variant="secondary"
                          onClick={() => handleApproveField(field)}
                          disabled={approvingField !== null}
                        >
                          {approvingField === field ? (
                            <Loader2 className="h-3.5 w-3.5 animate-spin" />
                          ) : (
                            "Approve"
                          )}
                        </Button>
                      )}
                    </div>
                    <p className="mt-2 whitespace-pre-line text-sm text-roicard-text">
                      {Array.isArray(value)
                        ? field === "experience"
                          ? value
                              .map((entry) => {
                                if (!entry || typeof entry !== "object") return "";
                                const row = entry as { title?: string; company?: string };
                                return [row.title, row.company].filter(Boolean).join(" at ");
                              })
                              .filter(Boolean)
                              .join("\n")
                          : value.join(", ")
                        : String(value ?? "")}
                    </p>
                  </div>
                );
              })}
            </div>
          )}
        </>
      )}
    </div>
  );
}
