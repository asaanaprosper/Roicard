/**
 * CatalogManagerView
 *
 * Owner-facing business catalog editor. Images, ordering, activation and
 * enquiries all use the real catalog API.
 */

"use client";

import { ProfileSwitcher } from "@/components/network/ProfileSwitcher";
import { CatalogAiDraftPanel } from "@/components/catalog/CatalogAiDraftPanel";
import { Button } from "@/components/ui/Button";
import { useConfirm } from "@/components/ui/ConfirmProvider";
import { Input } from "@/components/ui/Input";
import { Modal } from "@/components/ui/Modal";
import { Textarea } from "@/components/ui/Textarea";
import {
  createCatalogItem,
  deleteCatalogImage,
  deleteCatalogItem,
  getCatalog,
  getCatalogEnquiries,
  reorderCatalog,
  setCatalogItemActive,
  updateCatalogItem,
  uploadCatalogImages,
  type CatalogCta,
  type CatalogEnquiry,
  type CatalogItem,
  type CatalogListResponse,
  type CatalogPriceMode,
  type CatalogType,
} from "@/lib/api/catalog";
import {
  getCategories,
  getProfiles,
  type CategoryOption,
  type Profile,
} from "@/lib/api/profile";
import { cn } from "@/lib/cn";
import {
  ArrowDown,
  ArrowUp,
  Eye,
  ImagePlus,
  Megaphone,
  Pencil,
  Plus,
  ShoppingBag,
  Trash2,
  Wrench,
} from "lucide-react";
import Link from "next/link";
import { useCallback, useEffect, useMemo, useState } from "react";

type EditorState = { mode: "create" } | { mode: "edit"; item: CatalogItem };

type FormState = {
  type: CatalogType;
  title: string;
  description: string;
  categoryId: string;
  price: string;
  priceMode: CatalogPriceMode;
  currency: string;
  ctaType: CatalogCta;
  isActive: boolean;
};

const EMPTY_FORM: FormState = {
  type: "service",
  title: "",
  description: "",
  categoryId: "",
  price: "",
  priceMode: "on_request",
  currency: "GHS",
  ctaType: "enquire",
  isActive: true,
};

function flattenCategories(categories: CategoryOption[]): CategoryOption[] {
  return categories.flatMap((category) => [
    category,
    ...flattenCategories(category.children ?? []),
  ]);
}

function errorData(error: unknown): {
  message: string;
  limitKey?: string;
  errors?: Record<string, string>;
} {
  const data = (
    error as {
      response?: {
        data?: {
          message?: string;
          limit_key?: string;
          errors?: Record<string, string[]>;
        };
      };
    }
  )?.response?.data;

  return {
    message: data?.message ?? "Unable to save this item.",
    limitKey: data?.limit_key,
    errors: data?.errors
      ? Object.fromEntries(Object.entries(data.errors).map(([key, messages]) => [key, messages[0]]))
      : undefined,
  };
}

export function CatalogManagerView() {
  const { confirm } = useConfirm();
  const [profiles, setProfiles] = useState<Profile[]>([]);
  const [defaultProfileId, setDefaultProfileId] = useState<number | null>(null);
  const [selectedId, setSelectedId] = useState<number | null>(null);
  const [catalog, setCatalog] = useState<CatalogListResponse | null>(null);
  const [categories, setCategories] = useState<CategoryOption[]>([]);
  const [enquiries, setEnquiries] = useState<CatalogEnquiry[]>([]);
  const [isLoading, setIsLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [status, setStatus] = useState<string | null>(null);
  const [editor, setEditor] = useState<EditorState | null>(null);
  const [form, setForm] = useState<FormState>(EMPTY_FORM);
  const [formErrors, setFormErrors] = useState<Record<string, string>>({});
  const [pendingImages, setPendingImages] = useState<File[]>([]);
  const [isSaving, setIsSaving] = useState(false);
  const [busyItemId, setBusyItemId] = useState<number | null>(null);

  useEffect(() => {
    let cancelled = false;
    Promise.all([getProfiles(), getCategories("offering").catch(() => [])])
      .then(([profilesResponse, categoryRows]) => {
        if (cancelled) return;
        const businessProfiles = profilesResponse.profiles.filter(
          (profile) => profile.profile_type === "business"
        );
        setProfiles(businessProfiles);
        setDefaultProfileId(profilesResponse.default_profile_id);
        setSelectedId(
          businessProfiles.find((profile) => profile.id === profilesResponse.default_profile_id)?.id
            ?? businessProfiles[0]?.id
            ?? null
        );
        setCategories(flattenCategories(categoryRows));
      })
      .catch(() => {
        if (!cancelled) {
          setError("Unable to load your business profiles.");
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
      const [catalogResponse, enquiryResponse] = await Promise.all([
        getCatalog(profileId),
        getCatalogEnquiries(profileId).catch(() => ({ enquiries: [] })),
      ]);
      setCatalog(catalogResponse);
      setEnquiries(enquiryResponse.enquiries);
    } catch {
      setError("Unable to load the business catalog.");
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

  const openCreate = () => {
    setEditor({ mode: "create" });
    setForm(EMPTY_FORM);
    setPendingImages([]);
    setFormErrors({});
    setError(null);
  };

  const openEdit = (item: CatalogItem) => {
    setEditor({ mode: "edit", item });
    setForm({
      type: item.type,
      title: item.title,
      description: item.description ?? "",
      categoryId: item.category_id ? String(item.category_id) : "",
      price: item.price !== null ? String(item.price) : "",
      priceMode: item.price_mode,
      currency: item.currency || "GHS",
      ctaType: item.cta_type,
      isActive: item.is_active,
    });
    setPendingImages([]);
    setFormErrors({});
    setError(null);
  };

  const closeEditor = () => {
    setEditor(null);
    setFormErrors({});
    setPendingImages([]);
    setIsSaving(false);
  };

  const save = async () => {
    if (!selectedId) return;
    setIsSaving(true);
    setFormErrors({});
    setError(null);

    const payload = {
      type: form.type,
      title: form.title.trim(),
      description: form.description.trim() || null,
      category_id: form.categoryId ? Number(form.categoryId) : null,
      price:
        form.priceMode === "on_request" || form.price.trim() === ""
          ? null
          : Number(form.price),
      price_mode: form.priceMode,
      currency: form.currency.trim().toUpperCase() || "GHS",
      cta_type: form.ctaType,
      is_active: form.isActive,
    };

    try {
      let item: CatalogItem;
      if (editor?.mode === "edit") {
        const response = await updateCatalogItem(selectedId, editor.item.id, payload);
        item = response.item;
        setStatus(response.message);
      } else {
        const response = await createCatalogItem(selectedId, payload);
        item = response.item;
        setStatus(response.message);
      }

      if (pendingImages.length > 0) {
        const uploaded = await uploadCatalogImages(selectedId, item.id, pendingImages);
        item = uploaded.item;
      }

      closeEditor();
      await load(selectedId);
    } catch (e) {
      const parsed = errorData(e);
      setFormErrors(parsed.errors ?? {});
      setError(
        parsed.limitKey
          ? `${parsed.message} Your current plan has reached its active catalog limit. Deactivate or delete an item, or contact ROICARD about upgrading.`
          : parsed.message
      );
    } finally {
      setIsSaving(false);
    }
  };

  const toggleItem = async (item: CatalogItem) => {
    if (!selectedId) return;
    setBusyItemId(item.id);
    setError(null);
    try {
      const response = await setCatalogItemActive(selectedId, item.id, !item.is_active);
      setStatus(response.message);
      await load(selectedId);
    } catch (e) {
      setError(errorData(e).message);
    } finally {
      setBusyItemId(null);
    }
  };

  const removeItem = async (item: CatalogItem) => {
    if (!selectedId) return;
    const ok = await confirm({
      title: "Delete this catalog item?",
      description: "It will stop appearing publicly and will be removed from matching.",
      confirmLabel: "Delete item",
      variant: "danger",
    });
    if (!ok) return;

    setBusyItemId(item.id);
    try {
      await deleteCatalogItem(selectedId, item.id);
      setStatus("Catalog item deleted.");
      await load(selectedId);
    } catch {
      setError("Unable to delete this catalog item.");
    } finally {
      setBusyItemId(null);
    }
  };

  const move = async (item: CatalogItem, direction: -1 | 1) => {
    if (!selectedId || !catalog) return;
    const ids = catalog.items.map((row) => row.id);
    const index = ids.indexOf(item.id);
    const target = index + direction;
    if (index < 0 || target < 0 || target >= ids.length) return;

    [ids[index], ids[target]] = [ids[target], ids[index]];

    setBusyItemId(item.id);
    try {
      await reorderCatalog(selectedId, ids);
      await load(selectedId);
    } catch {
      setError("Unable to update catalog order.");
    } finally {
      setBusyItemId(null);
    }
  };

  const handleUploadImages = async (item: CatalogItem, files: FileList | null) => {
    if (!selectedId || !files || files.length === 0) return;
    setBusyItemId(item.id);
    setError(null);
    try {
      const response = await uploadCatalogImages(selectedId, item.id, Array.from(files));
      setStatus(response.message);
      await load(selectedId);
    } catch (e) {
      setError(errorData(e).message);
    } finally {
      setBusyItemId(null);
    }
  };

  const handleRemoveImage = async (item: CatalogItem, mediaId: number) => {
    if (!selectedId) return;
    setBusyItemId(item.id);
    try {
      await deleteCatalogImage(selectedId, item.id, mediaId);
      await load(selectedId);
    } catch {
      setError("Unable to remove the image.");
    } finally {
      setBusyItemId(null);
    }
  };

  if (isLoading && profiles.length === 0) {
    return (
      <div className="flex min-h-[40vh] items-center justify-center">
        <div className="h-8 w-8 animate-spin rounded-full border-2 border-roicard-border border-t-roicard-primary" />
      </div>
    );
  }

  if (profiles.length === 0) {
    return (
      <div className="rounded-2xl border border-dashed border-roicard-border bg-roicard-bg-elevated p-8 text-center">
        <ShoppingBag className="mx-auto h-7 w-7 text-roicard-accent" />
        <h1 className="mt-4 text-xl font-bold text-roicard-text">Business catalog</h1>
        <p className="mx-auto mt-2 max-w-md text-sm text-roicard-text-muted">
          Catalog items belong to a business profile. Create or switch to a
          business profile first.
        </p>
        <Link href="/dashboard/profile" className="mt-5 inline-block">
          <Button className="rounded-xl">Manage profiles</Button>
        </Link>
      </div>
    );
  }

  const activeCount = catalog?.counts.active ?? 0;
  const limit = catalog?.limits.catalog_items ?? null;
  const limitReached = limit !== null && activeCount >= limit;

  return (
    <div className="space-y-8">
      <header className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div>
          <span className="inline-flex w-fit items-center gap-2 rounded-full bg-roicard-primary/15 px-3 py-1 text-xs font-medium text-roicard-accent">
            <ShoppingBag className="h-3.5 w-3.5" aria-hidden />
            Business catalog
          </span>
          <h1 className="mt-3 text-2xl font-bold text-roicard-text sm:text-3xl">
            Services and products
          </h1>
          <p className="mt-2 text-sm text-roicard-text-muted sm:text-base">
            Active items appear on your public profile and contribute to matching.
            {limit !== null && ` ${activeCount} of ${limit} active slots used.`}
          </p>
        </div>
        <div className="flex flex-wrap gap-2">
          {selectedProfile?.slug && (
            <Link href={`/${selectedProfile.slug}`} target="_blank">
              <Button variant="secondary" className="rounded-xl">
                <Eye className="h-4 w-4" />
                Preview public catalog
              </Button>
            </Link>
          )}
          <Button className="rounded-xl" onClick={openCreate}>
            <Plus className="h-4 w-4" />
            Add item
          </Button>
        </div>
      </header>

      <ProfileSwitcher
        profiles={profiles}
        defaultProfileId={defaultProfileId}
        selectedId={selectedId}
        onChange={(id) => {
          setSelectedId(id);
          setStatus(null);
          setError(null);
        }}
        label="Business profile"
      />

      {selectedProfile && (
        <CatalogAiDraftPanel
          profile={selectedProfile}
          categories={categories}
          onChanged={() => {
            if (selectedId) void load(selectedId);
          }}
        />
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
      {limitReached && (
        <p className="rounded-lg border border-amber-500/30 bg-amber-500/10 px-3 py-2 text-sm text-amber-300">
          Your plan&apos;s active catalog limit is reached. You can still add paused
          items, or deactivate/delete an existing item.
        </p>
      )}

      {isLoading ? (
        <div className="flex min-h-[30vh] items-center justify-center">
          <div className="h-8 w-8 animate-spin rounded-full border-2 border-roicard-border border-t-roicard-primary" />
        </div>
      ) : (catalog?.items.length ?? 0) === 0 ? (
        <div className="rounded-2xl border border-dashed border-roicard-border bg-roicard-bg-elevated p-8 text-center">
          <h2 className="text-lg font-semibold text-roicard-text">No catalog items yet</h2>
          <p className="mx-auto mt-2 max-w-md text-sm text-roicard-text-muted">
            Add a service or product with an optional price, images and a call to
            action.
          </p>
          <Button className="mt-5 rounded-xl" onClick={openCreate}>
            <Plus className="h-4 w-4" />
            Add your first item
          </Button>
        </div>
      ) : (
        <div className="grid gap-4 lg:grid-cols-2">
          {catalog?.items.map((item, index) => (
            <article
              key={item.id}
              className={cn(
                "glass-card rounded-2xl p-4 sm:p-5",
                !item.is_active && "border-dashed opacity-80"
              )}
            >
              <div className="flex items-start gap-4">
                {item.images[0] ? (
                  // eslint-disable-next-line @next/next/no-img-element
                  <img
                    src={item.images[0].url}
                    alt={item.title}
                    className="h-20 w-20 shrink-0 rounded-xl border border-roicard-border object-cover"
                  />
                ) : (
                  <div className="flex h-20 w-20 shrink-0 items-center justify-center rounded-xl border border-roicard-border bg-roicard-bg-muted text-roicard-text-muted">
                    {item.type === "product" ? (
                      <ShoppingBag className="h-6 w-6" />
                    ) : (
                      <Wrench className="h-6 w-6" />
                    )}
                  </div>
                )}

                <div className="min-w-0 flex-1">
                  <div className="flex items-start justify-between gap-2">
                    <h3 className="truncate font-semibold text-roicard-text">{item.title}</h3>
                    <span
                      className={cn(
                        "shrink-0 rounded-full px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wider",
                        item.is_active
                          ? "bg-emerald-500/15 text-emerald-400"
                          : "bg-amber-500/15 text-amber-400"
                      )}
                    >
                      {item.is_active ? "Active" : "Paused"}
                    </span>
                  </div>
                  <p className="mt-0.5 text-sm text-roicard-text-muted">
                    {item.type} · {item.price_label}
                  </p>
                  {item.category && (
                    <p className="text-xs text-roicard-accent">{item.category.name}</p>
                  )}
                  {item.description && (
                    <p className="mt-1 line-clamp-2 text-xs text-roicard-text-muted">
                      {item.description}
                    </p>
                  )}
                </div>
              </div>

              {item.images.length > 0 && (
                <div className="mt-3 flex flex-wrap gap-2">
                  {item.images.map((image) => (
                    <div key={image.id} className="group relative">
                      {/* eslint-disable-next-line @next/next/no-img-element */}
                      <img
                        src={image.url}
                        alt={image.name}
                        className="h-14 w-14 rounded-lg border border-roicard-border object-cover"
                      />
                      <button
                        type="button"
                        onClick={() => handleRemoveImage(item, image.id)}
                        className="absolute -right-1.5 -top-1.5 flex h-5 w-5 items-center justify-center rounded-full bg-rose-600 text-[10px] text-white opacity-0 transition-opacity group-hover:opacity-100"
                        aria-label="Remove image"
                      >
                        ×
                      </button>
                    </div>
                  ))}
                </div>
              )}

              <div className="mt-3 flex flex-wrap items-center gap-2 border-t border-roicard-border pt-3">
                <Button size="sm" variant="secondary" className="rounded-lg" onClick={() => openEdit(item)}>
                  <Pencil className="h-3.5 w-3.5" />
                  Edit
                </Button>
                <Button
                  size="sm"
                  variant="secondary"
                  className="rounded-lg"
                  onClick={() => toggleItem(item)}
                  disabled={busyItemId === item.id}
                >
                  {item.is_active ? "Deactivate" : "Activate"}
                </Button>
                <Button
                  size="sm"
                  variant="ghost"
                  className="rounded-lg"
                  onClick={() => move(item, -1)}
                  disabled={index === 0 || busyItemId === item.id}
                  aria-label="Move up"
                >
                  <ArrowUp className="h-3.5 w-3.5" />
                </Button>
                <Button
                  size="sm"
                  variant="ghost"
                  className="rounded-lg"
                  onClick={() => move(item, 1)}
                  disabled={index === (catalog?.items.length ?? 1) - 1 || busyItemId === item.id}
                  aria-label="Move down"
                >
                  <ArrowDown className="h-3.5 w-3.5" />
                </Button>
                <label className="inline-flex cursor-pointer items-center gap-1.5 rounded-lg border border-roicard-border px-2.5 py-1.5 text-xs font-medium text-roicard-text-muted hover:text-roicard-text">
                  <ImagePlus className="h-3.5 w-3.5" />
                  Images
                  <input
                    type="file"
                    accept="image/jpeg,image/png,image/webp,image/gif"
                    multiple
                    className="hidden"
                    onChange={(event) => {
                      void handleUploadImages(item, event.target.files);
                      event.target.value = "";
                    }}
                  />
                </label>
                <Button
                  size="sm"
                  variant="ghost"
                  className="ml-auto rounded-lg"
                  onClick={() => removeItem(item)}
                  disabled={busyItemId === item.id}
                >
                  <Trash2 className="h-3.5 w-3.5" />
                  Delete
                </Button>
              </div>
            </article>
          ))}
        </div>
      )}

      <section className="rounded-2xl border border-roicard-border bg-roicard-bg-elevated/40 p-4 sm:p-5">
        <div className="flex items-center justify-between gap-3">
          <h2 className="flex items-center gap-2 text-base font-semibold text-roicard-text">
            <Megaphone className="h-4 w-4 text-roicard-accent" />
            Recent enquiries
          </h2>
          <span className="text-xs text-roicard-text-muted">{enquiries.length} shown</span>
        </div>
        {enquiries.length === 0 ? (
          <p className="mt-3 text-sm text-roicard-text-muted">
            Enquiries sent to this business profile will appear here.
          </p>
        ) : (
          <div className="mt-3 space-y-3">
            {enquiries.slice(0, 10).map((enquiry) => (
              <div
                key={enquiry.id}
                className="rounded-xl border border-roicard-border bg-roicard-bg-muted/30 p-3"
              >
                <div className="flex flex-wrap items-center gap-2 text-sm">
                  <span className="font-medium text-roicard-text">{enquiry.name}</span>
                  <span className="text-xs text-roicard-text-muted">{enquiry.email}</span>
                  <span className="ml-auto rounded-full bg-roicard-bg-muted px-2 py-0.5 text-[10px] uppercase tracking-wider text-roicard-text-muted">
                    {enquiry.status}
                  </span>
                </div>
                <p className="mt-1 text-xs leading-relaxed text-roicard-text-muted">
                  {enquiry.item?.title ? `${enquiry.item.title}: ` : ""}
                  {enquiry.message}
                </p>
              </div>
            ))}
          </div>
        )}
      </section>

      <Modal
        isOpen={editor !== null}
        onClose={closeEditor}
        title={editor?.mode === "edit" ? "Edit catalog item" : "Add catalog item"}
        description="Catalog items are public when active and your business profile is published."
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
        <div className="space-y-4">
          <div className="grid gap-4 sm:grid-cols-2">
            <div>
              <label htmlFor="catalog-type" className="mb-2 block text-sm font-medium text-roicard-text">
                Type
              </label>
              <select
                id="catalog-type"
                value={form.type}
                onChange={(event) => setForm({ ...form, type: event.target.value as CatalogType })}
                className="h-11 w-full rounded-lg border border-roicard-border bg-roicard-bg-muted px-3 text-sm text-roicard-text"
              >
                <option value="service">Service</option>
                <option value="product">Product</option>
              </select>
            </div>
            <Input
              label="Title"
              value={form.title}
              error={formErrors.title}
              maxLength={255}
              onChange={(event) => setForm({ ...form, title: event.target.value })}
            />
          </div>

          <Textarea
            label="Description"
            rows={4}
            value={form.description}
            error={formErrors.description}
            maxLength={5000}
            onChange={(event) => setForm({ ...form, description: event.target.value })}
          />

          <div className="grid gap-4 sm:grid-cols-2">
            <div>
              <label htmlFor="catalog-category" className="mb-2 block text-sm font-medium text-roicard-text">
                Category
              </label>
              <select
                id="catalog-category"
                value={form.categoryId}
                onChange={(event) => setForm({ ...form, categoryId: event.target.value })}
                className="h-11 w-full rounded-lg border border-roicard-border bg-roicard-bg-muted px-3 text-sm text-roicard-text"
              >
                <option value="">No category</option>
                {categories.map((category) => (
                  <option key={category.id} value={String(category.id)}>
                    {category.name}
                  </option>
                ))}
              </select>
              {formErrors.category_id && (
                <p className="mt-1 text-sm text-red-400">{formErrors.category_id}</p>
              )}
            </div>
            <div>
              <label htmlFor="catalog-cta" className="mb-2 block text-sm font-medium text-roicard-text">
                Call to action
              </label>
              <select
                id="catalog-cta"
                value={form.ctaType}
                onChange={(event) => setForm({ ...form, ctaType: event.target.value as CatalogCta })}
                className="h-11 w-full rounded-lg border border-roicard-border bg-roicard-bg-muted px-3 text-sm text-roicard-text"
              >
                <option value="enquire">Enquire</option>
                <option value="request_meeting">Request meeting</option>
                <option value="call">Call</option>
              </select>
            </div>
          </div>

          <div className="grid gap-4 sm:grid-cols-3">
            <div>
              <label htmlFor="catalog-price-mode" className="mb-2 block text-sm font-medium text-roicard-text">
                Price mode
              </label>
              <select
                id="catalog-price-mode"
                value={form.priceMode}
                onChange={(event) => setForm({ ...form, priceMode: event.target.value as CatalogPriceMode })}
                className="h-11 w-full rounded-lg border border-roicard-border bg-roicard-bg-muted px-3 text-sm text-roicard-text"
              >
                <option value="on_request">On request</option>
                <option value="fixed">Fixed</option>
                <option value="from">From</option>
              </select>
            </div>
            <Input
              label="Price"
              type="number"
              min={0}
              step="0.01"
              value={form.price}
              disabled={form.priceMode === "on_request"}
              error={formErrors.price}
              onChange={(event) => setForm({ ...form, price: event.target.value })}
            />
            <Input
              label="Currency"
              value={form.currency}
              maxLength={3}
              error={formErrors.currency}
              onChange={(event) => setForm({ ...form, currency: event.target.value.toUpperCase() })}
            />
          </div>

          <div>
            <label className="mb-2 block text-sm font-medium text-roicard-text">
              Images (optional)
            </label>
            <input
              type="file"
              accept="image/jpeg,image/png,image/webp,image/gif"
              multiple
              onChange={(event) =>
                setPendingImages(Array.from(event.target.files ?? []))
              }
              className="block w-full text-sm text-roicard-text-muted file:mr-3 file:rounded-lg file:border-0 file:bg-roicard-bg-muted file:px-3 file:py-2 file:text-sm file:font-medium file:text-roicard-text"
            />
            <p className="mt-1 text-xs text-roicard-text-muted">
              Up to {catalog?.max_images ?? 5} images per item. Images are uploaded
              after the item is saved.
            </p>
            {pendingImages.length > 0 && (
              <p className="mt-1 text-xs text-roicard-accent">
                {pendingImages.length} image(s) selected.
              </p>
            )}
          </div>

          <label className="flex items-center gap-2 text-sm text-roicard-text">
            <input
              type="checkbox"
              checked={form.isActive}
              onChange={(event) => setForm({ ...form, isActive: event.target.checked })}
              className="h-4 w-4 rounded border-roicard-border"
            />
            Active — show publicly and include in matching
          </label>
        </div>
      </Modal>
    </div>
  );
}
