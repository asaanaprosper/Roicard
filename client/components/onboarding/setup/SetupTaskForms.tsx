"use client";

import { PublicProfileCardStack } from "@/components/profile/public/PublicProfileCardStack";
import { AiConsentNotice } from "@/components/ai/AiConsentNotice";
import { Button } from "@/components/ui/Button";
import { Input } from "@/components/ui/Input";
import { Textarea } from "@/components/ui/Textarea";
import { useAiStatus } from "@/hooks/useAiStatus";
import type { OnboardingStatus } from "@/lib/api/onboarding";
import {
  approveAiDraftFields,
  createProfileV2,
  getCategories,
  publishProfileV2,
  requestAiDraft,
  unpublishProfileV2,
  updateProfileCategories,
  updateProfileV2,
  uploadCv,
  uploadProfileAvatarV2,
  type AiProfileDraft,
  type CategoryOption,
  type Profile,
} from "@/lib/api/profile";
import { apiProfileToUserProfile } from "@/lib/profile/apiProfile";
import {
  AtSign,
  Camera,
  Check,
  ExternalLink,
  FileText,
  Globe,
  Loader2,
  Phone,
  Sparkles,
  Upload,
} from "lucide-react";
import { ChangeEvent, useEffect, useMemo, useState } from "react";

type Account = { first_name: string; last_name: string; email: string } | null;

type FormProps = {
  profile: Profile;
  busy?: boolean;
  onSaved: () => void | Promise<void>;
};

const VISIBILITY_OPTIONS = [
  { value: "public", label: "Public — anyone with the link" },
  { value: "members", label: "Roicard members only" },
  { value: "communities", label: "Members in my communities" },
  { value: "connections", label: "Connections only" },
  { value: "private", label: "Private — only me" },
];

const CONTACT_VISIBILITY_OPTIONS = [
  { value: "public", label: "Everyone" },
  { value: "connections", label: "Connections" },
  { value: "communities", label: "Connections and communities" },
  { value: "hidden", label: "Hidden" },
];

const REQUEST_POLICY_OPTIONS = [
  { value: "communities", label: "Anyone in my communities" },
  { value: "matching", label: "People with a matching Seeking or Offering" },
  { value: "none", label: "No one" },
];

function ErrorNote({ message }: { message: string | null }) {
  if (!message) return null;
  return (
    <p className="rounded-lg border border-rose-500/30 bg-rose-500/10 px-3 py-2 text-sm text-rose-400">
      {message}
    </p>
  );
}

function flattenCategories(categories: CategoryOption[]): CategoryOption[] {
  return categories.flatMap((category) => [
    category,
    ...flattenCategories(category.children ?? []),
  ]);
}

export function BuildProfileForm({
  profile,
  busy,
  onSaved,
}: FormProps) {
  const [displayName, setDisplayName] = useState(profile.display_name ?? "");
  const [title, setTitle] = useState(profile.title ?? "");
  const [bio, setBio] = useState(profile.bio ?? "");
  const [location, setLocation] = useState(profile.location ?? "");
  const [websiteUrl, setWebsiteUrl] = useState(profile.website_url ?? "");
  const [sourceText, setSourceText] = useState("");
  const [aiMessage, setAiMessage] = useState<string | null>(null);
  const [cvName, setCvName] = useState<string | null>(null);
  const [cvId, setCvId] = useState<number | null>(null);
  const [cvBusy, setCvBusy] = useState(false);
  const [aiBusy, setAiBusy] = useState(false);
  const [draft, setDraft] = useState<AiProfileDraft | null>(null);
  const [approvedFields, setApprovedFields] = useState<string[]>(profile.ai_approved_fields ?? []);
  const [approvingField, setApprovingField] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const { status: aiStatus, loading: aiLoading, error: aiError, accept: acceptAi, refresh: refreshAi } = useAiStatus();

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
    aiStatus &&
      (!aiStatus.configured ||
        aiStatus.consent_required ||
        (aiStatus.remaining_today !== null && aiStatus.remaining_today <= 0))
  );

  const handlePasteSource = () => {
    if (sourceText.trim()) setBio(sourceText.trim());
  };

  const handleCv = async (event: ChangeEvent<HTMLInputElement>) => {
    const file = event.target.files?.[0];
    if (!file) return;
    setCvBusy(true);
    setError(null);
    try {
      const response = await uploadCv(file);
      setCvName(file.name);
      setCvId(response.cv?.id ?? null);
    } catch {
      setError("Unable to upload your CV. Please try a PDF or Word document.");
    } finally {
      setCvBusy(false);
    }
  };

  const handleAi = async () => {
    if (!sourceText.trim() && !websiteUrl.trim() && !cvId) {
      setError("Add a CV, website link or pasted text before using AI drafting.");
      return;
    }

    setAiBusy(true);
    setAiMessage(null);
    setError(null);
    try {
      const response = await requestAiDraft(profile.id, {
        source_text: sourceText || undefined,
        website_url: websiteUrl || undefined,
        cv_media_id: cvId ?? undefined,
      });
      setAiMessage(response.message);
      setDraft(response.draft ?? (response.profile?.ai_draft as AiProfileDraft | null) ?? null);
      setApprovedFields(response.profile?.ai_approved_fields ?? profile.ai_approved_fields ?? []);
      if (response.available) {
        await onSaved();
      }
    } catch (e) {
      const data = (e as { response?: { data?: { message?: string; errors?: Record<string, string[]> } } })
        .response?.data;
      const consentMessage = data?.errors?.consent_required?.[0];
      setError(consentMessage ?? data?.message ?? "Unable to request an AI draft. You can continue manually.");
      if (consentMessage) void refreshAi();
    } finally {
      setAiBusy(false);
    }
  };

  const handleApproveDraftField = async (field: string) => {
    setApprovingField(field);
    setError(null);
    try {
      const response = await approveAiDraftFields(profile.id, [field]);
      setDraft((response.profile.ai_draft as AiProfileDraft | null) ?? draft);
      setApprovedFields(response.profile.ai_approved_fields ?? [...approvedFields, field]);
      if (field === "headline" && typeof draft?.headline === "string") {
        setTitle(draft.headline);
      }
      if (field === "about" && typeof draft?.about === "string") {
        setBio(draft.about);
      }
      setAiMessage("Approved field applied to your profile.");
      await onSaved();
    } catch (e) {
      setError(
        (e as { response?: { data?: { message?: string } } })?.response?.data?.message ??
          "Unable to approve that field."
      );
    } finally {
      setApprovingField(null);
    }
  };

  const handleSave = async () => {
    if (!title.trim()) {
      setError("Add a headline before continuing.");
      return;
    }
    setSaving(true);
    setError(null);
    try {
      await updateProfileV2(profile.id, {
        display_name: displayName.trim() || null,
        title: title.trim(),
        bio: bio.trim() || null,
        location: location.trim() || null,
        website_url: websiteUrl.trim() || null,
      });
      await onSaved();
    } catch (e) {
      setError(
        (e as { response?: { data?: { message?: string } } })?.response?.data?.message ??
          "Unable to save your profile."
      );
    } finally {
      setSaving(false);
    }
  };

  return (
    <div className="space-y-6 rounded-2xl border border-roicard-border bg-roicard-bg-elevated p-5 sm:p-6">
      <div className="grid gap-5 sm:grid-cols-2">
        <Input
          label="Display name (optional)"
          placeholder="Use your account name by default"
          value={displayName}
          onChange={(e) => setDisplayName(e.target.value)}
          hint="Useful for a business or professional display name."
        />
        <Input
          label="Headline"
          placeholder="e.g. Product designer and founder"
          value={title}
          onChange={(e) => setTitle(e.target.value)}
        />
      </div>

      <Textarea
        label="About"
        rows={7}
        placeholder="Share what you do, what you care about and what makes you worth connecting with."
        value={bio}
        onChange={(e) => setBio(e.target.value)}
      />

      <div className="grid gap-5 sm:grid-cols-2">
        <Input
          label="Location"
          placeholder="Accra, Ghana"
          value={location}
          onChange={(e) => setLocation(e.target.value)}
        />
        <Input
          label="Website (optional)"
          type="url"
          placeholder="https://your-site.com"
          value={websiteUrl}
          onChange={(e) => setWebsiteUrl(e.target.value)}
        />
      </div>

      <div className="rounded-xl border border-roicard-border bg-roicard-bg-muted/30 p-4">
        <div className="flex items-start gap-3">
          <Sparkles className="mt-0.5 h-5 w-5 shrink-0 text-roicard-accent" />
          <div>
            <p className="text-sm font-semibold text-roicard-text">Build from existing information</p>
            <p className="mt-1 text-xs text-roicard-text-muted">
              Paste a bio or profile summary below, add a website link or upload a PDF/DOCX
              CV. AI drafts headline, About, experience, skills, services, Seeking and
              Offering as proposals. Nothing is applied until you approve each field.
            </p>
          </div>
        </div>

        <AiConsentNotice
          status={aiStatus}
          loading={aiLoading}
          error={aiError}
          onAccept={acceptAi}
          className="mt-4"
        />

        <Textarea
          className="mt-4"
          rows={4}
          label="Paste professional information"
          placeholder="Paste a LinkedIn summary, CV text or short bio…"
          value={sourceText}
          onChange={(e) => setSourceText(e.target.value)}
        />
        <div className="mt-3 flex flex-wrap gap-2">
          <Button type="button" variant="secondary" size="sm" onClick={handlePasteSource} className="rounded-lg">
            Copy into About
          </Button>
          <Button
            type="button"
            variant="secondary"
            size="sm"
            onClick={handleAi}
            disabled={aiBusy || aiLoading || aiBlocked}
            className="rounded-lg"
          >
            {aiBusy ? <Loader2 className="h-4 w-4 animate-spin" /> : <Sparkles className="h-4 w-4" />}
            Draft with AI
          </Button>
          <label className="inline-flex cursor-pointer items-center gap-2 rounded-lg border border-roicard-border bg-roicard-bg-muted px-3 py-2 text-sm font-medium text-roicard-text hover:border-roicard-accent/50">
            {cvBusy ? <Loader2 className="h-4 w-4 animate-spin" /> : <Upload className="h-4 w-4 text-roicard-accent" />}
            {cvName ?? "Upload CV"}
            <input
              type="file"
              accept=".pdf,.doc,.docx"
              className="sr-only"
              onChange={handleCv}
            />
          </label>
        </div>
        {aiMessage && (
          <p className="mt-3 rounded-lg border border-amber-500/30 bg-amber-500/10 px-3 py-2 text-xs text-amber-300">
            {aiMessage}
          </p>
        )}
      </div>

      {generatedDraftFields.length > 0 && (
        <div className="rounded-2xl border border-amber-500/30 bg-amber-500/5 p-5">
          <div className="flex flex-wrap items-start justify-between gap-3">
            <div>
              <h2 className="text-sm font-semibold text-roicard-text">Review AI-generated fields</h2>
              <p className="mt-1 text-xs text-roicard-text-muted">
                Each field stays proposed until you approve it. Approving does not publish your profile.
              </p>
            </div>
            <span className="rounded-full bg-amber-500/15 px-2.5 py-1 text-[10px] font-semibold uppercase tracking-wider text-amber-300">
              Proposed
            </span>
          </div>
          <div className="mt-4 space-y-3">
            {generatedDraftFields.map((field) => {
              const value = draft?.[field];
              const approved = approvedFields.includes(field);
              return (
                <div key={field} className="rounded-xl border border-roicard-border bg-roicard-bg-elevated p-4">
                  <div className="flex items-center justify-between gap-3">
                    <p className="text-xs font-semibold uppercase tracking-wider text-roicard-accent">
                      {field === "about" ? "About" : field}
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
                        onClick={() => handleApproveDraftField(field)}
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
        </div>
      )}

      <ErrorNote message={error} />

      <div className="flex justify-end">
        <Button onClick={handleSave} disabled={saving || busy} className="rounded-xl">
          {saving ? "Saving…" : "Save and continue"}
        </Button>
      </div>
    </div>
  );
}

export function PhotoForm({
  profile,
  account,
  busy,
  onSaved,
}: FormProps & { account: Account }) {
  const [file, setFile] = useState<File | null>(null);
  const [preview, setPreview] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    return () => {
      if (preview) URL.revokeObjectURL(preview);
    };
  }, [preview]);

  const handleFile = (event: ChangeEvent<HTMLInputElement>) => {
    const selected = event.target.files?.[0];
    if (!selected) return;
    if (preview) URL.revokeObjectURL(preview);
    setFile(selected);
    setPreview(URL.createObjectURL(selected));
  };

  const initials = useMemo(() => {
    const name = profile.display_name || account?.first_name || profile.organisation || "R";
    const extra = profile.display_name ? "" : account?.last_name ?? "";
    return `${name.trim().charAt(0)}${extra.trim().charAt(0)}`.toUpperCase();
  }, [account, profile.display_name, profile.organisation]);

  const handleSave = async () => {
    if (!file) {
      setError("Choose a photo first, or skip this optional step.");
      return;
    }
    setSaving(true);
    setError(null);
    try {
      await uploadProfileAvatarV2(profile.id, file);
      await onSaved();
    } catch {
      setError("Unable to upload that photo. Use a JPEG, PNG or WebP under 5 MB.");
    } finally {
      setSaving(false);
    }
  };

  const src = preview ?? profile.avatar_url ?? null;

  return (
    <div className="space-y-6 rounded-2xl border border-roicard-border bg-roicard-bg-elevated p-5 sm:p-6">
      <div className="flex flex-col items-center gap-5 sm:flex-row sm:items-start">
        <div className="relative h-40 w-40 shrink-0 overflow-hidden rounded-3xl border border-roicard-border bg-roicard-bg-muted">
          {src ? (
            // eslint-disable-next-line @next/next/no-img-element
            <img src={src} alt="" className="h-full w-full object-cover" />
          ) : (
            <div className="flex h-full w-full items-center justify-center text-4xl font-bold text-roicard-text-muted">
              {initials}
            </div>
          )}
          <span className="absolute bottom-2 right-2 rounded-full bg-roicard-bg/90 p-2 text-roicard-accent">
            <Camera className="h-4 w-4" />
          </span>
        </div>
        <div className="space-y-3">
          <h2 className="text-lg font-semibold text-roicard-text">Portrait</h2>
          <p className="max-w-md text-sm text-roicard-text-muted">
            Use a clear, well-lit portrait. The poster header crops the image
            with a centred cover fit; initials are used when no photo is added.
          </p>
          <label className="inline-flex cursor-pointer items-center gap-2 rounded-xl border border-roicard-border bg-roicard-bg-muted px-4 py-2.5 text-sm font-medium text-roicard-text hover:border-roicard-accent/50">
            <Upload className="h-4 w-4 text-roicard-accent" />
            Choose photo
            <input type="file" accept="image/jpeg,image/png,image/webp" className="sr-only" onChange={handleFile} />
          </label>
          <p className="text-xs text-roicard-text-muted">JPEG, PNG or WebP. Maximum 5 MB.</p>
        </div>
      </div>

      <ErrorNote message={error} />

      <div className="flex justify-end">
        <Button onClick={handleSave} disabled={saving || busy} className="rounded-xl">
          {saving ? "Uploading…" : "Save and continue"}
        </Button>
      </div>
    </div>
  );
}

export function PrivacyForm({ profile, busy, onSaved }: FormProps) {
  const [visibility, setVisibility] = useState(profile.visibility);
  const [searchable, setSearchable] = useState(profile.is_searchable);
  const [contactable, setContactable] = useState(profile.is_contactable);
  const [requestPolicy, setRequestPolicy] = useState<"communities" | "matching" | "none">(
    profile.connection_request_policy ?? "communities"
  );
  const [contactDetails, setContactDetails] = useState(profile.contact_details_visibility);
  const [confirmed, setConfirmed] = useState(false);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const handleSave = async () => {
    if (!confirmed) {
      setError("Confirm that you understand who can see your profile.");
      return;
    }
    setSaving(true);
    setError(null);
    try {
      await updateProfileV2(profile.id, {
        visibility,
        is_searchable: searchable,
        is_contactable: contactable,
        connection_request_policy: requestPolicy,
        contact_details_visibility: contactDetails,
        confirm_privacy: true,
      });
      await onSaved();
    } catch {
      setError("Unable to save your privacy settings.");
    } finally {
      setSaving(false);
    }
  };

  return (
    <div className="space-y-6 rounded-2xl border border-roicard-border bg-roicard-bg-elevated p-5 sm:p-6">
      <div>
        <label htmlFor="profile-visibility" className="mb-2 block text-sm font-medium text-roicard-text">
          Who can see this profile?
        </label>
        <select
          id="profile-visibility"
          value={visibility}
          onChange={(e) => setVisibility(e.target.value)}
          className="h-11 w-full rounded-lg border border-roicard-border bg-roicard-bg-muted px-3 text-sm text-roicard-text"
        >
          {VISIBILITY_OPTIONS.map((option) => (
            <option key={option.value} value={option.value}>{option.label}</option>
          ))}
        </select>
      </div>

      <div>
        <label htmlFor="contact-details-visibility" className="mb-2 block text-sm font-medium text-roicard-text">
          Default contact detail visibility
        </label>
        <select
          id="contact-details-visibility"
          value={contactDetails}
          onChange={(e) => setContactDetails(e.target.value)}
          className="h-11 w-full rounded-lg border border-roicard-border bg-roicard-bg-muted px-3 text-sm text-roicard-text"
        >
          {CONTACT_VISIBILITY_OPTIONS.filter((option) => option.value !== "public").map((option) => (
            <option key={option.value} value={option.value}>{option.label}</option>
          ))}
        </select>
        <p className="mt-2 text-xs text-roicard-text-muted">
          Individual phone, email and website visibility can be refined in Add contact options.
        </p>
      </div>

      <label className="flex items-start gap-3 rounded-xl border border-roicard-border bg-roicard-bg-muted/30 p-4 text-sm text-roicard-text">
        <input
          type="checkbox"
          checked={searchable}
          onChange={(e) => setSearchable(e.target.checked)}
          className="mt-0.5 h-4 w-4 rounded border-roicard-border"
        />
        <span>
          <span className="font-medium">Show my profile in search and discovery</span>
          <span className="mt-0.5 block text-xs text-roicard-text-muted">
            You can still share the direct link when search is off.
          </span>
        </span>
      </label>

      <label className="flex items-start gap-3 rounded-xl border border-roicard-border bg-roicard-bg-muted/30 p-4 text-sm text-roicard-text">
        <input
          type="checkbox"
          checked={contactable}
          onChange={(e) => setContactable(e.target.checked)}
          className="mt-0.5 h-4 w-4 rounded border-roicard-border"
        />
        <span>
          <span className="font-medium">Allow connection requests and contact actions</span>
          <span className="mt-0.5 block text-xs text-roicard-text-muted">
            This controls requests. Contact details have their own visibility settings.
          </span>
        </span>
      </label>

      <div>
        <label htmlFor="connection-request-policy" className="mb-2 block text-sm font-medium text-roicard-text">
          Who can send me connection requests?
        </label>
        <select
          id="connection-request-policy"
          value={requestPolicy}
          onChange={(e) =>
            setRequestPolicy(e.target.value as "communities" | "matching" | "none")
          }
          className="h-11 w-full rounded-lg border border-roicard-border bg-roicard-bg-muted px-3 text-sm text-roicard-text"
        >
          {REQUEST_POLICY_OPTIONS.map((option) => (
            <option key={option.value} value={option.value}>{option.label}</option>
          ))}
        </select>
        <p className="mt-2 text-xs text-roicard-text-muted">
          This is separate from profile visibility and search. Turning contactability off above blocks all requests.
        </p>
      </div>

      <label className="flex items-start gap-3 rounded-xl border border-amber-500/30 bg-amber-500/5 p-4 text-sm text-roicard-text">
        <input
          type="checkbox"
          checked={confirmed}
          onChange={(e) => setConfirmed(e.target.checked)}
          className="mt-0.5 h-4 w-4 rounded border-roicard-border"
        />
        <span>
          I understand who can see this profile and I am choosing these privacy
          settings explicitly.
        </span>
      </label>

      <ErrorNote message={error} />

      <div className="flex justify-end">
        <Button onClick={handleSave} disabled={saving || busy} className="rounded-xl">
          {saving ? "Saving…" : "Save privacy"}
        </Button>
      </div>
    </div>
  );
}

export function PublishForm({
  profile: initialProfile,
  account,
  status,
  busy,
  onSaved,
}: FormProps & { account: Account; status: OnboardingStatus }) {
  const [profile, setProfile] = useState(initialProfile);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [message, setMessage] = useState<string | null>(null);

  const preview = useMemo(() => apiProfileToUserProfile(profile, account), [account, profile]);
  const draft = profile.ai_draft;
  const approved = profile.ai_approved_fields ?? [];
  const generatedDraftFields = useMemo(() => {
    if (!draft || draft.source !== "ai") return [];
    return ["headline", "about", "experience", "skills", "services", "seeking", "offering"].filter(
      (field) => draft[field] !== undefined && draft[field] !== null && draft[field] !== "" &&
        !(Array.isArray(draft[field]) && (draft[field] as unknown[]).length === 0)
    );
  }, [draft]);

  const requirements = [
    { label: "Name", done: Boolean(profile.display_name || account?.first_name) },
    { label: "Headline", done: Boolean(profile.title?.trim()) },
    { label: "Privacy choice confirmed", done: Boolean(profile.privacy_confirmed_at) },
    { label: "AI fields approved", done: generatedDraftFields.every((field) => approved.includes(field)) },
  ];

  const handleApprove = async (field: string) => {
    setSaving(true);
    setError(null);
    try {
      const response = await approveAiDraftFields(profile.id, [field]);
      setProfile(response.profile);
      setMessage("Approved field applied.");
    } catch {
      setError("Unable to approve that field.");
    } finally {
      setSaving(false);
    }
  };

  const handlePublish = async () => {
    setSaving(true);
    setError(null);
    setMessage(null);
    try {
      const response = await publishProfileV2(profile.id);
      setProfile(response.profile);
      setMessage("Profile published.");
      await onSaved();
    } catch (e) {
      const data = (e as { response?: { data?: { message?: string; errors?: Record<string, string[]> } } })
        .response?.data;
      setError(data?.message || data?.errors?.title?.[0] || "Unable to publish your profile.");
    } finally {
      setSaving(false);
    }
  };

  const handleUnpublish = async () => {
    setSaving(true);
    setError(null);
    try {
      const response = await unpublishProfileV2(profile.id);
      setProfile(response.profile);
      setMessage("Profile unpublished. Only you can see it now.");
    } catch {
      setError("Unable to unpublish your profile.");
    } finally {
      setSaving(false);
    }
  };

  return (
    <div className="space-y-6">
      <div className="rounded-2xl border border-roicard-border bg-roicard-bg-elevated p-5 sm:p-6">
        <h2 className="text-sm font-semibold uppercase tracking-wider text-roicard-accent">
          Publishing checklist
        </h2>
        <div className="mt-4 grid gap-2 sm:grid-cols-2">
          {requirements.map((item) => (
            <div key={item.label} className="flex items-center gap-2 text-sm">
              {item.done ? (
                <Check className="h-4 w-4 text-emerald-400" />
              ) : (
                <span className="h-4 w-4 rounded-full border border-roicard-border" />
              )}
              <span className={item.done ? "text-roicard-text" : "text-roicard-text-muted"}>
                {item.label}
              </span>
            </div>
          ))}
        </div>
      </div>

      {generatedDraftFields.length > 0 && (
        <div className="rounded-2xl border border-amber-500/30 bg-amber-500/5 p-5">
          <h2 className="text-sm font-semibold text-roicard-text">Review AI-generated fields</h2>
          <p className="mt-1 text-xs text-roicard-text-muted">
            Each generated field needs your explicit approval before publication.
          </p>
          <div className="mt-4 space-y-3">
            {generatedDraftFields.map((field) => {
              const value = draft?.[field];
              return (
                <div key={field} className="rounded-xl border border-roicard-border bg-roicard-bg-elevated p-4">
                  <div className="flex items-center justify-between gap-3">
                    <p className="text-xs font-semibold uppercase tracking-wider text-roicard-accent">
                      {field}
                    </p>
                    {approved.includes(field) ? (
                      <span className="text-xs font-medium text-emerald-400">Approved</span>
                    ) : (
                      <Button
                        size="sm"
                        variant="secondary"
                        onClick={() => handleApprove(field)}
                        disabled={saving}
                      >
                        Approve
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
        </div>
      )}

      <div className="rounded-2xl border border-roicard-border bg-roicard-bg-elevated/40 p-5 sm:p-8">
        <p className="mb-4 text-xs font-semibold uppercase tracking-wider text-roicard-accent">
          Poster preview
        </p>
        <div className="mx-auto max-w-[480px]">
          <PublicProfileCardStack profile={preview} />
        </div>
      </div>

      <ErrorNote message={error} />
      {message && (
        <p className="rounded-lg border border-emerald-500/30 bg-emerald-500/10 px-3 py-2 text-sm text-emerald-400">
          {message}
        </p>
      )}

      <div className="flex flex-wrap items-center justify-between gap-3">
        {profile.is_published && profile.slug && (
          <a
            href={`/${profile.slug}`}
            target="_blank"
            rel="noreferrer"
            className="inline-flex items-center gap-1.5 text-sm font-medium text-roicard-accent hover:text-roicard-text"
          >
            <ExternalLink className="h-4 w-4" />
            Open public profile
          </a>
        )}
        <div className="ml-auto flex gap-2">
          {profile.is_published && (
            <Button variant="secondary" onClick={handleUnpublish} disabled={saving || busy}>
              Unpublish
            </Button>
          )}
          <Button onClick={handlePublish} disabled={saving || busy || profile.is_published}>
            {saving ? "Publishing…" : profile.is_published ? "Published" : "Publish profile"}
          </Button>
        </div>
      </div>
    </div>
  );
}

export function MatchForm({
  profile,
  kind,
  busy,
  onSaved,
}: FormProps & { kind: "seeking" | "offering" }) {
  const [text, setText] = useState(kind === "seeking" ? profile.seeking ?? "" : profile.offering ?? "");
  const [categories, setCategories] = useState<CategoryOption[]>([]);
  const [selected, setSelected] = useState<number[]>([]);
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    getCategories(kind)
      .then((items) => setCategories(flattenCategories(items)))
      .catch(() => setCategories([]))
      .finally(() => setLoading(false));
    const current = (profile.categories ?? [])
      .filter((category) => category.kind === kind)
      .map((category) => category.id);
    setSelected(current);
  }, [kind, profile.categories]);

  const toggle = (id: number) => {
    setSelected((current) =>
      current.includes(id) ? current.filter((item) => item !== id) : [...current, id]
    );
  };

  const handleSave = async () => {
    setSaving(true);
    setError(null);
    try {
      const other = (profile.categories ?? [])
        .filter((category) => category.kind !== kind)
        .map((category) => category.id);

      await updateProfileV2(profile.id, {
        [kind]: text.trim() || null,
      });

      await updateProfileCategories(profile.id, {
        seeking_category_ids: kind === "seeking" ? selected : other,
        offering_category_ids: kind === "offering" ? selected : other,
      });

      await onSaved();
    } catch {
      setError("Unable to save your selections.");
    } finally {
      setSaving(false);
    }
  };

  return (
    <div className="space-y-6 rounded-2xl border border-roicard-border bg-roicard-bg-elevated p-5 sm:p-6">
      <Textarea
        label={kind === "seeking" ? "What are you seeking?" : "What can you offer?"}
        rows={5}
        placeholder={
          kind === "seeking"
            ? "Describe the opportunities, people or resources you are looking for."
            : "Describe your skills, services or the value you can offer."
        }
        value={text}
        onChange={(e) => setText(e.target.value)}
      />

      <div>
        <p className="text-sm font-medium text-roicard-text">
          Suggested categories
        </p>
        <p className="mt-1 text-xs text-roicard-text-muted">
          Select any that fit. You can remove suggestions and keep your free-text description.
        </p>
        {loading ? (
          <div className="mt-4 flex items-center gap-2 text-sm text-roicard-text-muted">
            <Loader2 className="h-4 w-4 animate-spin" /> Loading categories…
          </div>
        ) : categories.length === 0 ? (
          <p className="mt-4 rounded-lg border border-roicard-border bg-roicard-bg-muted/30 px-3 py-2 text-xs text-roicard-text-muted">
            No categories are available yet. You can still save a free-text description.
          </p>
        ) : (
          <div className="mt-4 flex flex-wrap gap-2">
            {categories.map((category) => {
              const isSelected = selected.includes(category.id);
              return (
                <button
                  key={category.id}
                  type="button"
                  aria-pressed={isSelected}
                  onClick={() => toggle(category.id)}
                  className={
                    isSelected
                      ? "inline-flex items-center gap-1.5 rounded-full border border-transparent roicard-gradient px-3.5 py-2 text-sm font-medium text-roicard-on-primary"
                      : "inline-flex items-center gap-1.5 rounded-full border border-roicard-border bg-roicard-bg-muted px-3.5 py-2 text-sm font-medium text-roicard-text hover:border-roicard-accent/50"
                  }
                >
                  {isSelected && <Check className="h-3.5 w-3.5" />}
                  {category.name}
                </button>
              );
            })}
          </div>
        )}
      </div>

      <ErrorNote message={error} />

      <div className="flex justify-end">
        <Button onClick={handleSave} disabled={saving || busy} className="rounded-xl">
          {saving ? "Saving…" : "Save and continue"}
        </Button>
      </div>
    </div>
  );
}

export function ContactForm({ profile, busy, onSaved }: FormProps) {
  const [phone, setPhone] = useState(profile.phone ?? "");
  const [whatsapp, setWhatsapp] = useState(profile.whatsapp_phone ?? "");
  const [email, setEmail] = useState(profile.contact_email ?? "");
  const [website, setWebsite] = useState(profile.website_url ?? "");
  const [visibility, setVisibility] = useState<Record<string, string>>({
    phone: profile.contact_visibility?.phone ?? "connections",
    whatsapp: profile.contact_visibility?.whatsapp ?? "connections",
    email: profile.contact_visibility?.email ?? "connections",
    website: profile.contact_visibility?.website ?? "connections",
  });
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const updateVisibility = (field: string, value: string) => {
    setVisibility((current) => ({ ...current, [field]: value }));
  };

  const handleSave = async () => {
    setSaving(true);
    setError(null);
    try {
      await updateProfileV2(profile.id, {
        phone: phone.trim() || null,
        whatsapp_phone: whatsapp.trim() || null,
        contact_email: email.trim() || null,
        website_url: website.trim() || null,
        contact_visibility: visibility,
      });
      await onSaved();
    } catch {
      setError("Unable to save your contact options.");
    } finally {
      setSaving(false);
    }
  };

  const fields = [
    { key: "phone", label: "Phone", icon: Phone, value: phone, set: setPhone, type: "tel" },
    { key: "whatsapp", label: "WhatsApp", icon: Phone, value: whatsapp, set: setWhatsapp, type: "tel" },
    { key: "email", label: "Contact email", icon: AtSign, value: email, set: setEmail, type: "email" },
    { key: "website", label: "Website", icon: Globe, value: website, set: setWebsite, type: "url" },
  ];

  return (
    <div className="space-y-6 rounded-2xl border border-roicard-border bg-roicard-bg-elevated p-5 sm:p-6">
      <div className="space-y-5">
        {fields.map((field) => {
          const Icon = field.icon;
          return (
            <div key={field.key} className="grid gap-3 sm:grid-cols-[1fr_220px] sm:items-start">
              <div className="relative">
                <Icon className="pointer-events-none absolute left-3 top-9 h-4 w-4 text-roicard-text-muted" />
                <Input
                  label={field.label}
                  type={field.type}
                  className="pl-9"
                  value={field.value}
                  onChange={(e) => field.set(e.target.value)}
                />
              </div>
              <div>
                <label className="mb-2 block text-sm font-medium text-roicard-text" htmlFor={`visibility-${field.key}`}>
                  Visible to
                </label>
                <select
                  id={`visibility-${field.key}`}
                  value={visibility[field.key]}
                  onChange={(e) => updateVisibility(field.key, e.target.value)}
                  className="h-11 w-full rounded-lg border border-roicard-border bg-roicard-bg-muted px-3 text-sm text-roicard-text"
                >
                  {CONTACT_VISIBILITY_OPTIONS.map((option) => (
                    <option key={option.value} value={option.value}>{option.label}</option>
                  ))}
                </select>
              </div>
            </div>
          );
        })}
      </div>
      <p className="rounded-lg border border-roicard-border bg-roicard-bg-muted/30 px-3 py-2 text-xs text-roicard-text-muted">
        Contactability is controlled separately in Set privacy. These fields only control which contact details are revealed.
      </p>

      <ErrorNote message={error} />

      <div className="flex justify-end">
        <Button onClick={handleSave} disabled={saving || busy} className="rounded-xl">
          {saving ? "Saving…" : "Save contact options"}
        </Button>
      </div>
    </div>
  );
}

export function BusinessProfileForm({
  busy,
  onSaved,
}: FormProps & { account: Account }) {
  const [displayName, setDisplayName] = useState("");
  const [title, setTitle] = useState("");
  const [organisation, setOrganisation] = useState("");
  const [bio, setBio] = useState("");
  const [location, setLocation] = useState("");
  const [phone, setPhone] = useState("");
  const [email, setEmail] = useState("");
  const [website, setWebsite] = useState("");
  const [categoryId, setCategoryId] = useState("");
  const [categories, setCategories] = useState<CategoryOption[]>([]);
  const [logoFile, setLogoFile] = useState<File | null>(null);
  const [logoPreview, setLogoPreview] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    getCategories("offering")
      .then((items) => setCategories(flattenCategories(items)))
      .catch(() => setCategories([]));
  }, []);

  useEffect(() => {
    return () => {
      if (logoPreview) URL.revokeObjectURL(logoPreview);
    };
  }, [logoPreview]);

  const handleLogo = (event: ChangeEvent<HTMLInputElement>) => {
    const selected = event.target.files?.[0];
    if (!selected) return;
    if (logoPreview) URL.revokeObjectURL(logoPreview);
    setLogoFile(selected);
    setLogoPreview(URL.createObjectURL(selected));
  };

  const handleSave = async () => {
    if (!displayName.trim() && !organisation.trim()) {
      setError("Add a business name before continuing.");
      return;
    }
    if (!title.trim()) {
      setError("Add a business headline before continuing.");
      return;
    }
    setSaving(true);
    setError(null);
    try {
      const response = await createProfileV2({
        profile_type: "business",
        display_name: displayName.trim() || organisation.trim(),
        title: title.trim(),
        organisation: organisation.trim() || displayName.trim(),
        bio: bio.trim() || null,
        location: location.trim() || null,
        phone: phone.trim() || null,
        contact_email: email.trim() || null,
        website_url: website.trim() || null,
        visibility: "public",
        is_searchable: true,
        is_contactable: true,
        contact_details_visibility: "connections",
        confirm_privacy: true,
      });

      if (logoFile) {
        await uploadProfileAvatarV2(response.profile.id, logoFile);
      }

      if (categoryId) {
        await updateProfileCategories(response.profile.id, {
          seeking_category_ids: [],
          offering_category_ids: [Number(categoryId)],
        });
      }

      await onSaved();
    } catch (e) {
      const data = (e as { response?: { data?: { message?: string; errors?: Record<string, string[]> } } })
        .response?.data;
      setError(data?.message || data?.errors?.profile?.[0] || "Unable to create the business profile.");
    } finally {
      setSaving(false);
    }
  };

  return (
    <div className="space-y-6 rounded-2xl border border-roicard-border bg-roicard-bg-elevated p-5 sm:p-6">
      <div className="flex flex-col gap-4 sm:flex-row sm:items-center">
        <div className="flex h-20 w-20 items-center justify-center overflow-hidden rounded-2xl border border-roicard-border bg-roicard-bg-muted">
          {logoPreview ? (
            // eslint-disable-next-line @next/next/no-img-element
            <img src={logoPreview} alt="" className="h-full w-full object-cover" />
          ) : (
            <span className="text-xs text-roicard-text-muted">Logo</span>
          )}
        </div>
        <div>
          <p className="text-sm font-medium text-roicard-text">Business logo (optional)</p>
          <p className="mt-1 text-xs text-roicard-text-muted">
            Used in the business poster header and profile cards.
          </p>
          <label className="mt-2 inline-flex cursor-pointer items-center gap-2 rounded-lg border border-roicard-border bg-roicard-bg-muted px-3 py-2 text-sm font-medium text-roicard-text hover:border-roicard-accent/50">
            <Upload className="h-4 w-4 text-roicard-accent" />
            Choose logo
            <input type="file" accept="image/jpeg,image/png,image/webp" className="sr-only" onChange={handleLogo} />
          </label>
        </div>
      </div>

      <div className="grid gap-5 sm:grid-cols-2">
        <Input label="Business name" value={displayName} onChange={(e) => setDisplayName(e.target.value)} />
        <Input label="Legal / display organisation" value={organisation} onChange={(e) => setOrganisation(e.target.value)} />
        <Input label="Headline" value={title} onChange={(e) => setTitle(e.target.value)} />
        <Input label="Location" value={location} onChange={(e) => setLocation(e.target.value)} />
      </div>
      <Textarea label="About the business" rows={5} value={bio} onChange={(e) => setBio(e.target.value)} />
      <div className="grid gap-5 sm:grid-cols-2">
        <Input label="Phone" type="tel" value={phone} onChange={(e) => setPhone(e.target.value)} />
        <Input label="Email" type="email" value={email} onChange={(e) => setEmail(e.target.value)} />
        <Input label="Website" type="url" value={website} onChange={(e) => setWebsite(e.target.value)} />
        <div>
          <label htmlFor="business-category" className="mb-2 block text-sm font-medium text-roicard-text">
            Business category (optional)
          </label>
          <select
            id="business-category"
            value={categoryId}
            onChange={(e) => setCategoryId(e.target.value)}
            className="h-11 w-full rounded-lg border border-roicard-border bg-roicard-bg-muted px-3 text-sm text-roicard-text"
          >
            <option value="">Select a category…</option>
            {categories.map((category) => (
              <option key={category.id} value={category.id}>{category.name}</option>
            ))}
          </select>
        </div>
      </div>
      <p className="text-xs text-roicard-text-muted">
        The business profile starts published to the audience you choose in its privacy settings. You can edit it later from Manage profiles.
      </p>

      <ErrorNote message={error} />

      <div className="flex justify-end">
        <Button onClick={handleSave} disabled={saving || busy} className="rounded-xl">
          {saving ? "Creating…" : "Create business profile"}
        </Button>
      </div>
    </div>
  );
}
