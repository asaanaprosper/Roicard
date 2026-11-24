/**
 * CategoriesManagementView
 *
 * Admin-managed seeking/offering category taxonomy with parent/child
 * relationships and active/inactive status. Route: /admin/categories
 */

"use client";

import { AdminSelect } from "@/components/admin/AdminSelect";
import {
  DataTable,
  DataTableCell,
  DataTableRow,
} from "@/components/admin/DataTable";
import { Button } from "@/components/ui/Button";
import { useConfirm } from "@/components/ui/ConfirmProvider";
import { Input } from "@/components/ui/Input";
import { Modal } from "@/components/ui/Modal";
import { Textarea } from "@/components/ui/Textarea";
import {
  createAdminCategory,
  deleteAdminCategory,
  getAdminCategories,
  updateAdminCategory,
  type AdminCategory,
  type AdminCategoryKind,
} from "@/lib/api/admin";
import { cn } from "@/lib/cn";
import { Plus, Tags } from "lucide-react";
import { useCallback, useEffect, useMemo, useState } from "react";

type FormState = {
  kind: AdminCategoryKind;
  name: string;
  slug: string;
  parent_id: string;
  description: string;
  is_active: boolean;
  sort_order: string;
};

const EMPTY_FORM: FormState = {
  kind: "seeking",
  name: "",
  slug: "",
  parent_id: "",
  description: "",
  is_active: true,
  sort_order: "0",
};

function flatten(categories: AdminCategory[], parentId: number | null = null, depth = 0): Array<AdminCategory & { depth: number }> {
  return categories
    .filter((category) => category.parent_id === parentId)
    .sort((a, b) => a.sort_order - b.sort_order || a.name.localeCompare(b.name))
    .flatMap((category) => [
      { ...category, depth },
      ...flatten(categories, category.id, depth + 1),
    ]);
}

export function CategoriesManagementView() {
  const { confirm } = useConfirm();
  const [categories, setCategories] = useState<AdminCategory[]>([]);
  const [isLoading, setIsLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [kindFilter, setKindFilter] = useState<"" | AdminCategoryKind>("");

  const [modalOpen, setModalOpen] = useState(false);
  const [editing, setEditing] = useState<AdminCategory | null>(null);
  const [form, setForm] = useState<FormState>(EMPTY_FORM);
  const [formErrors, setFormErrors] = useState<Record<string, string>>({});
  const [isSaving, setIsSaving] = useState(false);
  const [busyId, setBusyId] = useState<number | null>(null);

  const load = useCallback(async () => {
    setIsLoading(true);
    setError(null);
    try {
      const { categories: list } = await getAdminCategories(
        kindFilter ? { kind: kindFilter } : undefined
      );
      setCategories(list);
    } catch {
      setError("Unable to load categories.");
    } finally {
      setIsLoading(false);
    }
  }, [kindFilter]);

  useEffect(() => {
    load();
  }, [load]);

  const parentOptions = useMemo(
    () =>
      categories.filter(
        (category) =>
          category.kind === form.kind && category.id !== editing?.id
      ),
    [categories, editing?.id, form.kind]
  );

  const openCreate = () => {
    setEditing(null);
    setForm(EMPTY_FORM);
    setFormErrors({});
    setModalOpen(true);
  };

  const openEdit = (category: AdminCategory) => {
    setEditing(category);
    setForm({
      kind: category.kind,
      name: category.name,
      slug: category.slug,
      parent_id: category.parent_id ? String(category.parent_id) : "",
      description: category.description ?? "",
      is_active: category.is_active,
      sort_order: String(category.sort_order),
    });
    setFormErrors({});
    setModalOpen(true);
  };

  const handleSave = async () => {
    setIsSaving(true);
    setFormErrors({});

    const payload = {
      kind: form.kind,
      name: form.name.trim(),
      slug: form.slug.trim() || null,
      parent_id: form.parent_id ? Number(form.parent_id) : null,
      description: form.description.trim() || null,
      is_active: form.is_active,
      sort_order: Number(form.sort_order || 0),
    };

    try {
      if (editing) {
        await updateAdminCategory(editing.id, payload);
      } else {
        await createAdminCategory(payload);
      }
      setModalOpen(false);
      await load();
    } catch (e) {
      const serverErrors = (
        e as { response?: { data?: { errors?: Record<string, string[]>; message?: string } } }
      )?.response?.data;
      if (serverErrors?.errors) {
        const flat: Record<string, string> = {};
        for (const [key, messages] of Object.entries(serverErrors.errors)) {
          flat[key] = messages[0];
        }
        setFormErrors(flat);
      } else {
        setFormErrors({ name: serverErrors?.message ?? "Unable to save." });
      }
    } finally {
      setIsSaving(false);
    }
  };

  const handleToggle = async (category: AdminCategory) => {
    setBusyId(category.id);
    try {
      await updateAdminCategory(category.id, { is_active: !category.is_active });
      await load();
    } catch {
      setError("Unable to update the category.");
    } finally {
      setBusyId(null);
    }
  };

  const handleDelete = async (category: AdminCategory) => {
    const ok = await confirm({
      title: "Delete category?",
      description: `"${category.name}" will be removed from the taxonomy. Child categories must be moved first.`,
      confirmLabel: "Delete",
      variant: "danger",
    });
    if (!ok) return;

    setBusyId(category.id);
    try {
      await deleteAdminCategory(category.id);
      await load();
    } catch (e) {
      setError(
        (e as { response?: { data?: { message?: string } } })?.response?.data
          ?.message ?? "Unable to delete the category."
      );
    } finally {
      setBusyId(null);
    }
  };

  const rows = useMemo(() => flatten(categories), [categories]);
  const columns = [
    { key: "name", label: "Category" },
    { key: "kind", label: "Taxonomy" },
    { key: "slug", label: "Slug" },
    { key: "usage", label: "Usage" },
    { key: "status", label: "Status" },
    { key: "actions", label: "", className: "text-right" },
  ];

  return (
    <div className="space-y-8">
      <div className="flex flex-wrap items-start justify-between gap-4">
        <div>
          <h1 className="flex items-center gap-2 text-2xl font-bold text-roicard-text">
            <Tags className="h-6 w-6 text-roicard-accent" />
            Categories
          </h1>
          <p className="mt-1 text-sm text-roicard-text-muted">
            Manage the seeking and offering taxonomies used for profile matching.
            Parent/child hierarchy is supported per taxonomy.
          </p>
        </div>
        <div className="flex flex-wrap items-end gap-3">
          <AdminSelect
            label="Taxonomy"
            value={kindFilter}
            onChange={(e) => setKindFilter(e.target.value as "" | AdminCategoryKind)}
            className="w-44"
          >
            <option value="">All taxonomies</option>
            <option value="seeking">Seeking</option>
            <option value="offering">Offering</option>
          </AdminSelect>
          <Button onClick={openCreate} className="rounded-lg">
            <Plus className="mr-2 h-4 w-4" />
            New Category
          </Button>
        </div>
      </div>

      {error && (
        <p className="rounded-lg bg-rose-500/10 px-3 py-2 text-sm text-rose-500">
          {error}
        </p>
      )}

      {isLoading ? (
        <div className="flex min-h-[30vh] items-center justify-center">
          <div className="h-8 w-8 animate-spin rounded-full border-2 border-roicard-border border-t-roicard-primary" />
        </div>
      ) : rows.length === 0 ? (
        <div className="rounded-2xl border border-roicard-border bg-roicard-bg-elevated p-10 text-center">
          <p className="text-sm text-roicard-text-muted">
            No categories yet. Create the first seeking or offering category.
          </p>
        </div>
      ) : (
        <DataTable columns={columns}>
          {rows.map((category) => (
            <DataTableRow key={category.id}>
              <DataTableCell className="font-medium text-roicard-text">
                <span style={{ paddingLeft: `${category.depth * 18}px` }}>
                  {category.depth > 0 ? "↳ " : ""}
                  {category.name}
                </span>
              </DataTableCell>
              <DataTableCell>
                <span
                  className={cn(
                    "inline-flex rounded-full border px-2.5 py-0.5 text-xs font-medium capitalize",
                    category.kind === "seeking"
                      ? "border-sky-500/30 bg-sky-500/10 text-sky-400"
                      : "border-violet-500/30 bg-violet-500/10 text-violet-400"
                  )}
                >
                  {category.kind}
                </span>
              </DataTableCell>
              <DataTableCell className="font-mono text-xs">{category.slug}</DataTableCell>
              <DataTableCell>
                {category.profiles_count ?? 0} profiles · {category.children_count ?? 0} children
              </DataTableCell>
              <DataTableCell>
                <span
                  className={cn(
                    "inline-flex rounded-full border px-2.5 py-0.5 text-xs font-medium",
                    category.is_active
                      ? "border-emerald-500/30 bg-emerald-500/10 text-emerald-400"
                      : "border-slate-500/30 bg-slate-500/10 text-slate-400"
                  )}
                >
                  {category.is_active ? "Active" : "Inactive"}
                </span>
              </DataTableCell>
              <DataTableCell className="text-right">
                <div className="flex justify-end gap-2">
                  <Button variant="ghost" size="sm" onClick={() => openEdit(category)}>
                    Edit
                  </Button>
                  <Button
                    variant="secondary"
                    size="sm"
                    disabled={busyId === category.id}
                    onClick={() => handleToggle(category)}
                  >
                    {category.is_active ? "Disable" : "Enable"}
                  </Button>
                  <Button
                    variant="danger"
                    size="sm"
                    disabled={busyId === category.id}
                    onClick={() => handleDelete(category)}
                  >
                    Delete
                  </Button>
                </div>
              </DataTableCell>
            </DataTableRow>
          ))}
        </DataTable>
      )}

      <Modal
        isOpen={modalOpen}
        onClose={() => setModalOpen(false)}
        title={editing ? "Edit category" : "New category"}
        description="Categories are grouped by taxonomy; a parent must belong to the same taxonomy."
        footer={
          <>
            <Button variant="secondary" onClick={() => setModalOpen(false)} disabled={isSaving}>
              Cancel
            </Button>
            <Button onClick={handleSave} disabled={isSaving}>
              {isSaving ? "Saving…" : editing ? "Save changes" : "Create"}
            </Button>
          </>
        }
      >
        <div className="space-y-4">
          <AdminSelect
            label="Taxonomy"
            value={form.kind}
            error={formErrors.kind}
            onChange={(e) => {
              const kind = e.target.value as AdminCategoryKind;
              setForm((f) => ({ ...f, kind, parent_id: "" }));
            }}
          >
            <option value="seeking">Seeking</option>
            <option value="offering">Offering</option>
          </AdminSelect>
          <Input
            label="Name"
            value={form.name}
            error={formErrors.name}
            onChange={(e) => setForm((f) => ({ ...f, name: e.target.value }))}
          />
          <Input
            label="Slug (optional)"
            placeholder="Generated from the name"
            value={form.slug}
            error={formErrors.slug}
            onChange={(e) => setForm((f) => ({ ...f, slug: e.target.value }))}
          />
          <AdminSelect
            label="Parent category (optional)"
            value={form.parent_id}
            error={formErrors.parent_id}
            onChange={(e) => setForm((f) => ({ ...f, parent_id: e.target.value }))}
          >
            <option value="">No parent (top level)</option>
            {parentOptions.map((category) => (
              <option key={category.id} value={category.id}>
                {category.name}
              </option>
            ))}
          </AdminSelect>
          <Textarea
            label="Description (optional)"
            value={form.description}
            error={formErrors.description}
            onChange={(e) => setForm((f) => ({ ...f, description: e.target.value }))}
          />
          <div className="grid gap-4 sm:grid-cols-2">
            <Input
              label="Sort order"
              type="number"
              min={0}
              value={form.sort_order}
              error={formErrors.sort_order}
              onChange={(e) => setForm((f) => ({ ...f, sort_order: e.target.value }))}
            />
            <label className="flex items-center gap-2 self-end pb-3 text-sm text-roicard-text">
              <input
                type="checkbox"
                checked={form.is_active}
                onChange={(e) => setForm((f) => ({ ...f, is_active: e.target.checked }))}
                className="h-4 w-4 rounded border-roicard-border"
              />
              Active
            </label>
          </div>
        </div>
      </Modal>
    </div>
  );
}
