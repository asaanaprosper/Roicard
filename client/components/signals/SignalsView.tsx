/**
 * SignalsView
 *
 * Full Seeking/Offering item management per profile. Items keep their original
 * free-text description while categories and tags are edited.
 */

"use client";

import { ProfileSwitcher } from "@/components/network/ProfileSwitcher";
import { Button } from "@/components/ui/Button";
import { useConfirm } from "@/components/ui/ConfirmProvider";
import { Modal } from "@/components/ui/Modal";
import { Textarea } from "@/components/ui/Textarea";
import { cn } from "@/lib/cn";
import {
  createSignal,
  deleteSignal,
  getSignals,
  getTags,
  pauseSignal,
  resumeSignal,
  updateSignal,
  type ProfileSignal,
  type SignalPayload,
  type SignalType,
  type SignalsResponse,
  type TagOption,
} from "@/lib/api/signals";
import {
  getCategories,
  getProfiles,
  type CategoryOption,
  type Profile,
} from "@/lib/api/profile";
import {
  Check,
  CirclePause,
  CirclePlay,
  Pencil,
  Plus,
  Sparkles,
  Trash2,
} from "lucide-react";
import Link from "next/link";
import { useCallback, useEffect, useMemo, useState } from "react";

type EditorState =
  | { mode: "create"; type: SignalType }
  | { mode: "edit"; type: SignalType; signal: ProfileSignal };

type FormState = {
  description: string;
  categoryIds: number[];
  tagIds: number[];
  isActive: boolean;
};

function flattenCategories(categories: CategoryOption[]): CategoryOption[] {
  return categories.flatMap((category) => [
    category,
    ...flattenCategories(category.children ?? []),
  ]);
}

function errorPayload(error: unknown): {
  message: string;
  limitKey?: string;
  limit?: number | null;
  current?: number;
  fieldErrors?: Record<string, string>;
} {
  const data = (
    error as {
      response?: {
        data?: {
          message?: string;
          limit_key?: string;
          limit?: number | null;
          current_usage?: number;
          errors?: Record<string, string[]>;
        };
      };
    }
  )?.response?.data;

  return {
    message: data?.message ?? "Unable to save this item.",
    limitKey: data?.limit_key,
    limit: data?.limit,
    current: data?.current_usage,
    fieldErrors: data?.errors
      ? Object.fromEntries(
          Object.entries(data.errors).map(([key, messages]) => [key, messages[0]])
        )
      : undefined,
  };
}

function SignalCard({
  signal,
  busy,
  onEdit,
  onToggle,
  onDelete,
}: {
  signal: ProfileSignal;
  busy: boolean;
  onEdit: () => void;
  onToggle: () => void;
  onDelete: () => void;
}) {
  return (
    <article
      className={cn(
        "rounded-2xl border p-4",
        signal.is_active
          ? "border-roicard-border bg-roicard-bg-elevated"
          : "border-dashed border-roicard-border bg-roicard-bg-muted/30"
      )}
    >
      <div className="flex items-start justify-between gap-3">
        <p className="text-sm leading-relaxed text-roicard-text">{signal.description}</p>
        <span
          className={cn(
            "shrink-0 rounded-full px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wider",
            signal.is_active
              ? "bg-emerald-500/15 text-emerald-400"
              : "bg-amber-500/15 text-amber-400"
          )}
        >
          {signal.is_active ? "Active" : "Paused"}
        </span>
      </div>

      {signal.categories.length > 0 && (
        <div className="mt-2 flex flex-wrap gap-1.5">
          {signal.categories.map((category) => (
            <span
              key={category.id}
              className="rounded-full bg-roicard-bg-muted px-2 py-0.5 text-[11px] text-roicard-text-muted"
            >
              {category.name}
            </span>
          ))}
        </div>
      )}
      {signal.tags.length > 0 && (
        <div className="mt-1.5 flex flex-wrap gap-1.5">
          {signal.tags.map((tag) => (
            <span
              key={tag.id}
              className="rounded-full border border-roicard-border px-2 py-0.5 text-[11px] text-roicard-text-muted"
            >
              #{tag.name}
            </span>
          ))}
        </div>
      )}

      <div className="mt-3 flex flex-wrap gap-2">
        <Button size="sm" variant="secondary" className="rounded-lg" onClick={onEdit} disabled={busy}>
          <Pencil className="h-3.5 w-3.5" />
          Edit
        </Button>
        <Button size="sm" variant="secondary" className="rounded-lg" onClick={onToggle} disabled={busy}>
          {signal.is_active ? (
            <>
              <CirclePause className="h-3.5 w-3.5" />
              Pause
            </>
          ) : (
            <>
              <CirclePlay className="h-3.5 w-3.5" />
              Resume
            </>
          )}
        </Button>
        <Button size="sm" variant="ghost" className="rounded-lg" onClick={onDelete} disabled={busy}>
          <Trash2 className="h-3.5 w-3.5" />
          Delete
        </Button>
      </div>
    </article>
  );
}

export function SignalsView() {
  const { confirm } = useConfirm();
  const [profiles, setProfiles] = useState<Profile[]>([]);
  const [defaultProfileId, setDefaultProfileId] = useState<number | null>(null);
  const [selectedId, setSelectedId] = useState<number | null>(null);
  const [data, setData] = useState<SignalsResponse | null>(null);
  const [categories, setCategories] = useState<Record<SignalType, CategoryOption[]>>({
    seeking: [],
    offering: [],
  });
  const [tags, setTags] = useState<TagOption[]>([]);
  const [isLoading, setIsLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [status, setStatus] = useState<string | null>(null);
  const [limitNotice, setLimitNotice] = useState<string | null>(null);
  const [busySignalId, setBusySignalId] = useState<number | null>(null);
  const [editor, setEditor] = useState<EditorState | null>(null);
  const [form, setForm] = useState<FormState>({
    description: "",
    categoryIds: [],
    tagIds: [],
    isActive: true,
  });
  const [formErrors, setFormErrors] = useState<Record<string, string>>({});
  const [isSaving, setIsSaving] = useState(false);

  useEffect(() => {
    let cancelled = false;
    Promise.all([
      getProfiles(),
      getCategories("seeking").catch(() => []),
      getCategories("offering").catch(() => []),
      getTags().catch(() => []),
    ])
      .then(([profilesResponse, seekingCategories, offeringCategories, tagRows]) => {
        if (cancelled) return;
        setProfiles(profilesResponse.profiles);
        setDefaultProfileId(profilesResponse.default_profile_id);
        setSelectedId(profilesResponse.default_profile_id ?? profilesResponse.profiles[0]?.id ?? null);
        setCategories({
          seeking: flattenCategories(seekingCategories),
          offering: flattenCategories(offeringCategories),
        });
        setTags(tagRows);
      })
      .catch(() => {
        if (!cancelled) {
          setError("Unable to load your profiles and taxonomy.");
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
      const response = await getSignals(profileId);
      setData(response);
    } catch {
      setError("Unable to load Seeking and Offering items.");
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

  const openCreate = (type: SignalType) => {
    setEditor({ mode: "create", type });
    setForm({ description: "", categoryIds: [], tagIds: [], isActive: true });
    setFormErrors({});
    setLimitNotice(null);
  };

  const openEdit = (type: SignalType, signal: ProfileSignal) => {
    setEditor({ mode: "edit", type, signal });
    setForm({
      description: signal.description,
      categoryIds: signal.categories.map((category) => category.id),
      tagIds: signal.tags.map((tag) => tag.id),
      isActive: signal.is_active,
    });
    setFormErrors({});
    setLimitNotice(null);
  };

  const closeEditor = () => {
    setEditor(null);
    setFormErrors({});
    setIsSaving(false);
  };

  const save = async () => {
    if (!editor || !selectedId) return;
    if (!form.description.trim()) {
      setFormErrors({ description: "Describe this item." });
      return;
    }

    setIsSaving(true);
    setFormErrors({});
    setLimitNotice(null);

    const payload: SignalPayload = {
      description: form.description.trim(),
      category_ids: form.categoryIds,
      tag_ids: form.tagIds,
    };

    try {
      if (editor.mode === "create") {
        payload.type = editor.type;
        payload.is_active = form.isActive;
        const response = await createSignal(selectedId, payload);
        setStatus(response.message || "Item created.");
      } else {
        payload.is_active = form.isActive;
        const response = await updateSignal(selectedId, editor.signal.id, payload);
        setStatus(response.message || "Item updated.");
      }
      closeEditor();
      await load(selectedId);
    } catch (e) {
      const parsed = errorPayload(e);
      setFormErrors(parsed.fieldErrors ?? {});
      if (parsed.limitKey) {
        setLimitNotice(
          `${parsed.message} You are using ${parsed.current ?? "?"} of ${
            parsed.limit ?? "?"
          } active slots on this plan. Pause or delete an item, or contact ROICARD about upgrading.`
        );
      } else {
        setLimitNotice(parsed.message);
      }
    } finally {
      setIsSaving(false);
    }
  };

  const toggleSignal = async (signal: ProfileSignal) => {
    if (!selectedId) return;
    setBusySignalId(signal.id);
    setLimitNotice(null);
    try {
      const response = signal.is_active
        ? await pauseSignal(selectedId, signal.id)
        : await resumeSignal(selectedId, signal.id);
      setStatus(response.message);
      await load(selectedId);
    } catch (e) {
      const parsed = errorPayload(e);
      setLimitNotice(parsed.message);
    } finally {
      setBusySignalId(null);
    }
  };

  const removeSignal = async (signal: ProfileSignal) => {
    if (!selectedId) return;
    const ok = await confirm({
      title: "Delete this item?",
      description:
        "It will stop participating in matching immediately. This cannot be undone.",
      confirmLabel: "Delete item",
      variant: "danger",
    });
    if (!ok) return;

    setBusySignalId(signal.id);
    try {
      await deleteSignal(selectedId, signal.id);
      setStatus("Item deleted.");
      await load(selectedId);
    } catch {
      setError("Unable to delete this item.");
    } finally {
      setBusySignalId(null);
    }
  };

  const editorCategories = editor ? categories[editor.type] : [];
  const canAddSeeking =
    !data?.limits.active_seeking ||
    data.counts.active_seeking < data.limits.active_seeking;
  const canAddOffering =
    !data?.limits.active_offering ||
    data.counts.active_offering < data.limits.active_offering;

  return (
    <div className="space-y-8">
      <header className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div>
          <span className="inline-flex w-fit items-center gap-2 rounded-full bg-roicard-primary/15 px-3 py-1 text-xs font-medium text-roicard-accent">
            <Sparkles className="h-3.5 w-3.5" aria-hidden />
            Seeking &amp; Offering
          </span>
          <h1 className="mt-3 text-2xl font-bold text-roicard-text sm:text-3xl">
            What you need and what you offer
          </h1>
          <p className="mt-2 text-sm text-roicard-text-muted sm:text-base">
            Each item is matched independently for the selected profile.
          </p>
        </div>
        <Link href="/dashboard/discover">
          <Button variant="secondary" className="rounded-xl">
            View recommendations
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
          setLimitNotice(null);
        }}
      />

      {status && (
        <p className="rounded-lg border border-emerald-500/30 bg-emerald-500/10 px-3 py-2 text-sm text-emerald-400">
          {status}
        </p>
      )}
      {limitNotice && (
        <div className="rounded-xl border border-amber-500/30 bg-amber-500/10 px-4 py-3">
          <p className="text-sm text-amber-300">{limitNotice}</p>
        </div>
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
            aria-label="Loading items"
          />
        </div>
      ) : !selectedProfile ? (
        <div className="rounded-2xl border border-dashed border-roicard-border bg-roicard-bg-elevated p-8 text-center">
          <h2 className="text-lg font-semibold text-roicard-text">Create a profile first</h2>
          <p className="mx-auto mt-2 max-w-md text-sm text-roicard-text-muted">
            Seeking and Offering items belong to a personal or business profile.
          </p>
          <Link href="/dashboard/profile" className="mt-5 inline-block">
            <Button className="rounded-xl">Go to profiles</Button>
          </Link>
        </div>
      ) : (
        <div className="grid gap-6 xl:grid-cols-2">
          {(["seeking", "offering"] as const).map((type) => {
            const items = data?.[type] ?? [];
            const activeCount =
              data?.counts[type === "seeking" ? "active_seeking" : "active_offering"] ?? 0;
            const limit =
              data?.limits[type === "seeking" ? "active_seeking" : "active_offering"] ?? null;
            const canAdd = type === "seeking" ? canAddSeeking : canAddOffering;

            return (
              <section
                key={type}
                className="space-y-4 rounded-2xl border border-roicard-border bg-roicard-bg-elevated/40 p-4 sm:p-5"
              >
                <div className="flex flex-wrap items-start justify-between gap-3">
                  <div>
                    <h2 className="text-lg font-semibold capitalize text-roicard-text">
                      {type}
                    </h2>
                    <p className="mt-1 text-xs text-roicard-text-muted">
                      {activeCount} active
                      {limit !== null ? ` of ${limit}` : ""} ·{" "}
                      {items.filter((item) => !item.is_active).length} paused
                    </p>
                  </div>
                  <Button size="sm" className="rounded-xl" onClick={() => openCreate(type)}>
                    <Plus className="h-3.5 w-3.5" />
                    Add {type} item
                  </Button>
                </div>

                {!canAdd && (
                  <p className="rounded-lg border border-amber-500/25 bg-amber-500/10 px-3 py-2 text-xs text-amber-300">
                    Your current plan has reached its active {type} limit. Pause or
                    delete an item, or contact ROICARD about upgrading.
                  </p>
                )}

                {items.length === 0 ? (
                  <div className="rounded-xl border border-dashed border-roicard-border p-6 text-center">
                    <p className="text-sm text-roicard-text-muted">
                      No {type} items yet. Add one to improve your matches.
                    </p>
                  </div>
                ) : (
                  <div className="space-y-3">
                    {items.map((signal) => (
                      <SignalCard
                        key={signal.id}
                        signal={signal}
                        busy={busySignalId === signal.id}
                        onEdit={() => openEdit(type, signal)}
                        onToggle={() => toggleSignal(signal)}
                        onDelete={() => removeSignal(signal)}
                      />
                    ))}
                  </div>
                )}
              </section>
            );
          })}
        </div>
      )}

      <Modal
        isOpen={editor !== null}
        onClose={closeEditor}
        title={
          editor?.mode === "edit"
            ? `Edit ${editor.type} item`
            : editor
            ? `Add ${editor.type} item`
            : "Item"
        }
        description="Your original description is preserved even when categories or tags change."
        className="max-w-2xl"
        footer={
          <>
            <Button variant="secondary" onClick={closeEditor} disabled={isSaving}>
              Cancel
            </Button>
            <Button onClick={save} isLoading={isSaving}>
              {editor?.mode === "edit" ? "Save changes" : "Create item"}
            </Button>
          </>
        }
      >
        {editor && (
          <div className="space-y-4">
            <Textarea
              label="Description"
              rows={4}
              maxLength={2000}
              value={form.description}
              error={formErrors.description}
              onChange={(event) => setForm({ ...form, description: event.target.value })}
              hint="Describe the need or offer in your own words."
            />

            <div>
              <p className="mb-2 text-sm font-medium text-roicard-text">
                Categories
              </p>
              {editorCategories.length === 0 ? (
                <p className="text-xs text-roicard-text-muted">
                  No {editor.type} categories are configured yet. Your description
                  still works on its own.
                </p>
              ) : (
                <div className="flex max-h-44 flex-wrap gap-2 overflow-y-auto rounded-lg border border-roicard-border bg-roicard-bg-muted/40 p-3">
                  {editorCategories.map((category) => {
                    const isSelected = form.categoryIds.includes(category.id);
                    return (
                      <button
                        key={category.id}
                        type="button"
                        aria-pressed={isSelected}
                        onClick={() =>
                          setForm({
                            ...form,
                            categoryIds: isSelected
                              ? form.categoryIds.filter((id) => id !== category.id)
                              : [...form.categoryIds, category.id],
                          })
                        }
                        className={cn(
                          "inline-flex items-center gap-1.5 rounded-full border px-3 py-1.5 text-xs font-medium",
                          isSelected
                            ? "border-transparent roicard-gradient text-roicard-on-primary"
                            : "border-roicard-border bg-roicard-bg-elevated text-roicard-text hover:border-roicard-accent/50"
                        )}
                      >
                        {isSelected && <Check className="h-3 w-3" />}
                        {category.name}
                      </button>
                    );
                  })}
                </div>
              )}
              {formErrors.category_ids && (
                <p className="mt-1 text-sm text-red-400">{formErrors.category_ids}</p>
              )}
            </div>

            <div>
              <p className="mb-2 text-sm font-medium text-roicard-text">Tags</p>
              {tags.length === 0 ? (
                <p className="text-xs text-roicard-text-muted">
                  No tags are configured yet.
                </p>
              ) : (
                <div className="flex max-h-40 flex-wrap gap-2 overflow-y-auto rounded-lg border border-roicard-border bg-roicard-bg-muted/40 p-3">
                  {tags.map((tag) => {
                    const isSelected = form.tagIds.includes(tag.id);
                    return (
                      <button
                        key={tag.id}
                        type="button"
                        aria-pressed={isSelected}
                        onClick={() =>
                          setForm({
                            ...form,
                            tagIds: isSelected
                              ? form.tagIds.filter((id) => id !== tag.id)
                              : [...form.tagIds, tag.id],
                          })
                        }
                        className={cn(
                          "rounded-full border px-2.5 py-1 text-xs font-medium",
                          isSelected
                            ? "border-roicard-accent bg-roicard-primary/15 text-roicard-accent"
                            : "border-roicard-border bg-roicard-bg-elevated text-roicard-text-muted hover:text-roicard-text"
                        )}
                      >
                        #{tag.name}
                      </button>
                    );
                  })}
                </div>
              )}
            </div>

            <label className="flex items-center gap-2 text-sm text-roicard-text">
              <input
                type="checkbox"
                checked={form.isActive}
                onChange={(event) => setForm({ ...form, isActive: event.target.checked })}
                className="h-4 w-4 rounded border-roicard-border"
              />
              Active — include this item in matching
            </label>

            <p className="text-xs leading-relaxed text-roicard-text-muted">
              Paused items stay in your list but do not participate in
              recommendations. Category suggestions from AI are not available in
              this build; manual selection is fully supported.
            </p>
          </div>
        )}
      </Modal>
    </div>
  );
}
