"use client";

import { PublicProfileCardStack } from "@/components/profile/public/PublicProfileCardStack";
import { ProfileCompletenessCard } from "@/components/profile/ProfileCompletenessCard";
import { ProfilePublicLinkCard } from "@/components/profile/ProfilePublicLinkCard";
import { QrCodePreview } from "@/components/onboarding/QrCodePreview";
import { Button } from "@/components/ui/Button";
import { useConfirm } from "@/components/ui/ConfirmProvider";
import { Input } from "@/components/ui/Input";
import { Modal } from "@/components/ui/Modal";
import { ProfileReusePanel } from "@/components/profile/ProfileReusePanel";
import { Textarea } from "@/components/ui/Textarea";
import { useAuth } from "@/contexts/AuthContext";
import {
  createProfileV2,
  deleteProfileV2,
  getProfiles,
  setDefaultProfileV2,
  publishProfileV2,
  unpublishProfileV2,
  updateProfileV2,
  uploadBusinessBranding,
  type Profile,
} from "@/lib/api/profile";
import { apiProfileToUserProfile } from "@/lib/profile/apiProfile";
import { cn } from "@/lib/cn";
import { Check, Pencil, Plus, Power, Star, Trash2 } from "lucide-react";
import Link from "next/link";
import { useCallback, useEffect, useMemo, useState } from "react";

type EditState = {
  display_name: string;
  title: string;
  organisation: string;
  bio: string;
  location: string;
  phone: string;
  contact_email: string;
  website_url: string;
  visibility: string;
  is_searchable: boolean;
  is_contactable: boolean;
  contact_details_visibility: string;
};

function toEditState(profile: Profile): EditState {
  return {
    display_name: profile.display_name ?? "",
    title: profile.title ?? "",
    organisation: profile.organisation ?? "",
    bio: profile.bio ?? "",
    location: profile.location ?? "",
    phone: profile.phone ?? "",
    contact_email: profile.contact_email ?? "",
    website_url: profile.website_url ?? "",
    visibility: profile.visibility,
    is_searchable: profile.is_searchable,
    is_contactable: profile.is_contactable,
    contact_details_visibility: profile.contact_details_visibility,
  };
}

export function ProfilesManagementView() {
  const { user } = useAuth();
  const { confirm } = useConfirm();
  const [profiles, setProfiles] = useState<Profile[]>([]);
  const [defaultId, setDefaultId] = useState<number | null>(null);
  const [selectedId, setSelectedId] = useState<number | null>(null);
  const [isLoading, setIsLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const [editOpen, setEditOpen] = useState(false);
  const [editTarget, setEditTarget] = useState<Profile | null>(null);
  const [form, setForm] = useState<EditState | null>(null);
  const [formErrors, setFormErrors] = useState<Record<string, string>>({});

  const load = useCallback(async () => {
    setIsLoading(true);
    setError(null);
    try {
      const response = await getProfiles();
      setProfiles(response.profiles);
      setDefaultId(response.default_profile_id);
      setSelectedId((current) => {
        if (current && response.profiles.some((p) => p.id === current)) return current;
        return response.default_profile_id ?? response.profiles[0]?.id ?? null;
      });
    } catch {
      setError("Unable to load your profiles.");
    } finally {
      setIsLoading(false);
    }
  }, []);

  useEffect(() => {
    load();
  }, [load]);

  const selected = useMemo(
    () => profiles.find((profile) => profile.id === selectedId) ?? profiles[0] ?? null,
    [profiles, selectedId]
  );

  const preview = selected ? apiProfileToUserProfile(selected, user) : null;

  const openEdit = (profile: Profile) => {
    setEditTarget(profile);
    setForm(toEditState(profile));
    setFormErrors({});
    setEditOpen(true);
  };

  const handleSave = async () => {
    if (!editTarget || !form) return;
    setBusy(true);
    setFormErrors({});
    try {
      const response = await updateProfileV2(editTarget.id, {
        display_name: form.display_name.trim() || null,
        title: form.title.trim() || null,
        organisation: form.organisation.trim() || null,
        bio: form.bio.trim() || null,
        location: form.location.trim() || null,
        phone: form.phone.trim() || null,
        contact_email: form.contact_email.trim() || null,
        website_url: form.website_url.trim() || null,
        visibility: form.visibility,
        is_searchable: form.is_searchable,
        is_contactable: form.is_contactable,
        contact_details_visibility: form.contact_details_visibility,
      });
      setProfiles((current) =>
        current.map((profile) => (profile.id === response.profile.id ? response.profile : profile))
      );
      setEditOpen(false);
      await load();
    } catch (e) {
      const data = (e as { response?: { data?: { message?: string; errors?: Record<string, string[]> } } })
        .response?.data;
      if (data?.errors) {
        setFormErrors(
          Object.fromEntries(Object.entries(data.errors).map(([key, messages]) => [key, messages[0]]))
        );
      } else {
        setError(data?.message ?? "Unable to save the profile.");
      }
    } finally {
      setBusy(false);
    }
  };

  const handleBranding = async (kind: "logo" | "cover", file: File | null) => {
    if (!editTarget || !file) return;
    setBusy(true);
    setError(null);
    try {
      await uploadBusinessBranding(editTarget.id, kind, file);
      await load();
    } catch {
      setError(`Unable to upload the business ${kind}.`);
    } finally {
      setBusy(false);
    }
  };

  const handleSetDefault = async (profile: Profile) => {
    setBusy(true);
    try {
      await setDefaultProfileV2(profile.id);
      await load();
    } catch {
      setError("Unable to change your default profile.");
    } finally {
      setBusy(false);
    }
  };

  const handlePublish = async (profile: Profile) => {
    setBusy(true);
    setError(null);
    try {
      await publishProfileV2(profile.id);
      await load();
    } catch (e) {
      const data = (e as { response?: { data?: { message?: string; errors?: Record<string, string[]> } } })
        .response?.data;
      setError(data?.message ?? data?.errors?.title?.[0] ?? "Unable to publish this profile.");
    } finally {
      setBusy(false);
    }
  };

  const handleUnpublish = async (profile: Profile) => {
    setBusy(true);
    try {
      await unpublishProfileV2(profile.id);
      await load();
    } catch {
      setError("Unable to unpublish this profile.");
    } finally {
      setBusy(false);
    }
  };

  const handleDelete = async (profile: Profile) => {
    const ok = await confirm({
      title: "Delete this profile?",
      description: "This removes the profile and its public page. Your account must keep at least one profile.",
      confirmLabel: "Delete profile",
      variant: "danger",
    });
    if (!ok) return;
    setBusy(true);
    try {
      await deleteProfileV2(profile.id);
      setSelectedId(null);
      await load();
    } catch (e) {
      setError(
        (e as { response?: { data?: { message?: string } } })?.response?.data?.message ??
          "Unable to delete this profile."
      );
    } finally {
      setBusy(false);
    }
  };

  const handleCreatePersonal = async () => {
    setBusy(true);
    setError(null);
    try {
      const response = await createProfileV2({
        profile_type: "personal",
        visibility: "public",
        is_searchable: true,
        is_contactable: true,
        contact_details_visibility: "connections",
      });
      setSelectedId(response.profile.id);
      await load();
    } catch (e) {
      setError(
        (e as { response?: { data?: { message?: string } } })?.response?.data?.message ??
          "Unable to create a profile."
      );
    } finally {
      setBusy(false);
    }
  };

  if (isLoading) {
    return (
      <div className="flex min-h-[40vh] items-center justify-center">
        <div className="h-8 w-8 animate-spin rounded-full border-2 border-roicard-border border-t-roicard-primary" />
      </div>
    );
  }

  if (profiles.length === 0) {
    return (
      <div className="rounded-2xl border border-dashed border-roicard-border bg-roicard-bg-elevated p-8 text-center sm:p-12">
        <h1 className="text-xl font-bold text-roicard-text">Create your ROICARD profile</h1>
        <p className="mx-auto mt-2 max-w-md text-sm text-roicard-text-muted">
          Every account starts with a personal profile. Add a business profile later from setup.
        </p>
        {error && <p className="mt-3 text-sm text-rose-400">{error}</p>}
        <Button className="mt-5 rounded-xl" onClick={handleCreatePersonal} disabled={busy}>
          <Plus className="h-4 w-4" />
          Create personal profile
        </Button>
      </div>
    );
  }

  return (
    <div className="space-y-8">
      <header className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div>
          <span className="inline-flex rounded-full bg-roicard-primary/15 px-3 py-1 text-xs font-medium text-roicard-accent">
            Profiles
          </span>
          <h1 className="mt-3 text-2xl font-bold text-roicard-text sm:text-3xl">Manage profiles</h1>
          <p className="mt-2 text-sm text-roicard-text-muted">
            Switch between your personal and business identities, edit each profile, and control what is public.
          </p>
        </div>
        <div className="flex flex-wrap gap-2">
          <Link href="/dashboard/profile/signals">
            <Button variant="secondary" className="rounded-xl">
              Seeking &amp; Offering
            </Button>
          </Link>
          <Link href="/dashboard/profile/catalog">
            <Button variant="secondary" className="rounded-xl">
              Business catalog
            </Button>
          </Link>
          <Link href="/dashboard/setup/business">
            <Button variant="secondary" className="rounded-xl">
              <Plus className="h-4 w-4" />
              Add business profile
            </Button>
          </Link>
        </div>
      </header>

      {error && (
        <p className="rounded-lg border border-rose-500/30 bg-rose-500/10 px-3 py-2 text-sm text-rose-400">
          {error}
        </p>
      )}

      <div className="flex flex-wrap gap-2">
        {profiles.map((profile) => {
          const isSelected = profile.id === selected?.id;
          return (
            <button
              key={profile.id}
              type="button"
              onClick={() => setSelectedId(profile.id)}
              className={cn(
                "flex items-center gap-2 rounded-xl border px-4 py-2.5 text-left text-sm transition-colors",
                isSelected
                  ? "border-roicard-primary/40 bg-roicard-primary/10 text-roicard-text"
                  : "border-roicard-border bg-roicard-bg-elevated text-roicard-text-muted hover:text-roicard-text"
              )}
            >
              <span className="flex h-8 w-8 items-center justify-center rounded-full bg-roicard-bg-muted text-xs font-bold text-roicard-accent">
                {(profile.display_name || profile.organisation || profile.title || "P")
                  .slice(0, 1)
                  .toUpperCase()}
              </span>
              <span>
                <span className="block font-medium">
                  {profile.display_name || profile.organisation || profile.title || "Profile"}
                </span>
                <span className="block text-[11px] capitalize text-roicard-text-muted">
                  {profile.profile_type}
                  {profile.id === defaultId ? " · default" : ""}
                  {profile.is_published ? " · published" : " · unpublished"}
                </span>
              </span>
              {profile.id === defaultId && <Star className="ml-1 h-3.5 w-3.5 text-amber-400" />}
            </button>
          );
        })}
      </div>

      {selected && preview && (
        <div className="grid gap-6 lg:grid-cols-[minmax(0,1fr)_360px] lg:items-start lg:gap-8">
          <div className="space-y-5">
            <div className="flex flex-wrap items-center gap-2">
              <span
                className={cn(
                  "inline-flex rounded-full px-3 py-1 text-xs font-medium",
                  selected.is_published
                    ? "bg-emerald-500/15 text-emerald-400"
                    : "bg-amber-500/15 text-amber-400"
                )}
              >
                {selected.is_published ? "Published" : "Unpublished"}
              </span>
              <span className="inline-flex rounded-full bg-roicard-bg-muted px-3 py-1 text-xs font-medium capitalize text-roicard-text-muted">
                {selected.profile_type}
              </span>
              {selected.id === defaultId && (
                <span className="inline-flex items-center gap-1 rounded-full bg-amber-500/15 px-3 py-1 text-xs font-medium text-amber-400">
                  <Star className="h-3 w-3" /> Default
                </span>
              )}
              <div className="ml-auto flex flex-wrap gap-2">
                {selected.id !== defaultId && (
                  <Button variant="secondary" size="sm" onClick={() => handleSetDefault(selected)} disabled={busy}>
                    Make default
                  </Button>
                )}
                <Button variant="secondary" size="sm" onClick={() => openEdit(selected)} disabled={busy}>
                  <Pencil className="h-3.5 w-3.5" /> Edit
                </Button>
                {selected.is_published ? (
                  <Button variant="secondary" size="sm" onClick={() => handleUnpublish(selected)} disabled={busy}>
                    <Power className="h-3.5 w-3.5" /> Unpublish
                  </Button>
                ) : (
                  <Button size="sm" onClick={() => handlePublish(selected)} disabled={busy}>
                    <Check className="h-3.5 w-3.5" /> Publish
                  </Button>
                )}
                <Button
                  variant="danger"
                  size="sm"
                  onClick={() => handleDelete(selected)}
                  disabled={busy || profiles.length <= 1}
                >
                  <Trash2 className="h-3.5 w-3.5" /> Delete
                </Button>
              </div>
            </div>

            <ProfileReusePanel profile={selected} profiles={profiles} onChanged={load} />

            <div className="flex justify-center rounded-2xl border border-roicard-border bg-roicard-bg-elevated/40 p-6 sm:p-10">
              <div className="w-full max-w-[480px]">
                <PublicProfileCardStack profile={preview} />
              </div>
            </div>
          </div>

          <aside className="lg:sticky lg:top-6">
            <div className="space-y-4">
              {selected.is_published && selected.slug ? (
                <>
                  <QrCodePreview username={selected.slug} />
                  <ProfilePublicLinkCard username={selected.slug} />
                </>
              ) : (
                <div className="rounded-xl border border-dashed border-roicard-border bg-roicard-bg-elevated p-5 text-sm text-roicard-text-muted">
                  Publish this profile to activate its public link and QR code.
                </div>
              )}
              <ProfileCompletenessCard data={preview} />
            </div>
          </aside>
        </div>
      )}

      <Modal
        isOpen={editOpen}
        onClose={() => setEditOpen(false)}
        title={`Edit ${editTarget?.profile_type === "business" ? "business" : "personal"} profile`}
        className="max-w-2xl"
        footer={
          <>
            <Button variant="secondary" onClick={() => setEditOpen(false)} disabled={busy}>
              Cancel
            </Button>
            <Button onClick={handleSave} disabled={busy}>
              {busy ? "Saving…" : "Save changes"}
            </Button>
          </>
        }
      >
        {form && (
          <div className="space-y-4">
            <div className="grid gap-4 sm:grid-cols-2">
              <Input
                label="Display name"
                value={form.display_name}
                error={formErrors.display_name}
                onChange={(e) => setForm({ ...form, display_name: e.target.value })}
              />
              <Input
                label="Headline"
                value={form.title}
                error={formErrors.title}
                onChange={(e) => setForm({ ...form, title: e.target.value })}
              />
              <Input
                label="Organisation"
                value={form.organisation}
                error={formErrors.organisation}
                onChange={(e) => setForm({ ...form, organisation: e.target.value })}
              />
              <Input
                label="Location"
                value={form.location}
                error={formErrors.location}
                onChange={(e) => setForm({ ...form, location: e.target.value })}
              />
            </div>
            <Textarea
              label="About"
              rows={5}
              value={form.bio}
              error={formErrors.bio}
              onChange={(e) => setForm({ ...form, bio: e.target.value })}
            />
            <div className="grid gap-4 sm:grid-cols-2">
              <Input
                label="Phone"
                value={form.phone}
                error={formErrors.phone}
                onChange={(e) => setForm({ ...form, phone: e.target.value })}
              />
              <Input
                label="Contact email"
                type="email"
                value={form.contact_email}
                error={formErrors.contact_email}
                onChange={(e) => setForm({ ...form, contact_email: e.target.value })}
              />
              <Input
                label="Website"
                type="url"
                value={form.website_url}
                error={formErrors.website_url}
                onChange={(e) => setForm({ ...form, website_url: e.target.value })}
              />
              <div>
                <label htmlFor="edit-visibility" className="mb-2 block text-sm font-medium text-roicard-text">
                  Visibility
                </label>
                <select
                  id="edit-visibility"
                  value={form.visibility}
                  onChange={(e) => setForm({ ...form, visibility: e.target.value })}
                  className="h-11 w-full rounded-lg border border-roicard-border bg-roicard-bg-muted px-3 text-sm text-roicard-text"
                >
                  <option value="public">Public</option>
                  <option value="members">Roicard members</option>
                  <option value="communities">My communities</option>
                  <option value="connections">Connections only</option>
                  <option value="private">Private</option>
                </select>
              </div>
            </div>
            <div className="grid gap-4 sm:grid-cols-2">
              <div>
                <label htmlFor="edit-contact-visibility" className="mb-2 block text-sm font-medium text-roicard-text">
                  Contact details
                </label>
                <select
                  id="edit-contact-visibility"
                  value={form.contact_details_visibility}
                  onChange={(e) => setForm({ ...form, contact_details_visibility: e.target.value })}
                  className="h-11 w-full rounded-lg border border-roicard-border bg-roicard-bg-muted px-3 text-sm text-roicard-text"
                >
                  <option value="connections">Connections only</option>
                  <option value="communities">Connections and communities</option>
                  <option value="hidden">Hidden</option>
                </select>
              </div>
              <div className="flex flex-col gap-2 pt-6 text-sm text-roicard-text">
                <label className="flex items-center gap-2">
                  <input
                    type="checkbox"
                    checked={form.is_searchable}
                    onChange={(e) => setForm({ ...form, is_searchable: e.target.checked })}
                    className="h-4 w-4 rounded border-roicard-border"
                  />
                  Searchable
                </label>
                <label className="flex items-center gap-2">
                  <input
                    type="checkbox"
                    checked={form.is_contactable}
                    onChange={(e) => setForm({ ...form, is_contactable: e.target.checked })}
                    className="h-4 w-4 rounded border-roicard-border"
                  />
                  Contactable
                </label>
              </div>
            </div>

            {editTarget?.profile_type === "business" && (
              <div className="grid gap-4 rounded-xl border border-roicard-border bg-roicard-bg-muted/30 p-4 sm:grid-cols-2">
                <div>
                  <p className="text-sm font-medium text-roicard-text">Business logo</p>
                  {editTarget.logo_url && (
                    // eslint-disable-next-line @next/next/no-img-element
                    <img src={editTarget.logo_url} alt="Business logo" className="mt-2 h-14 w-14 rounded-lg border border-roicard-border bg-white object-contain p-1" />
                  )}
                  <label className="mt-2 inline-flex cursor-pointer rounded-lg border border-roicard-border px-3 py-1.5 text-xs font-medium text-roicard-text-muted hover:text-roicard-text">
                    Upload logo
                    <input type="file" accept="image/*" className="hidden" onChange={(e) => void handleBranding("logo", e.target.files?.[0] ?? null)} />
                  </label>
                </div>
                <div>
                  <p className="text-sm font-medium text-roicard-text">Cover image</p>
                  {editTarget.cover_url && (
                    // eslint-disable-next-line @next/next/no-img-element
                    <img src={editTarget.cover_url} alt="Business cover" className="mt-2 h-14 w-full rounded-lg border border-roicard-border object-cover" />
                  )}
                  <label className="mt-2 inline-flex cursor-pointer rounded-lg border border-roicard-border px-3 py-1.5 text-xs font-medium text-roicard-text-muted hover:text-roicard-text">
                    Upload cover
                    <input type="file" accept="image/*" className="hidden" onChange={(e) => void handleBranding("cover", e.target.files?.[0] ?? null)} />
                  </label>
                </div>
              </div>
            )}
          </div>
        )}
      </Modal>
    </div>
  );
}
