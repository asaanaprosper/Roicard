/**
 * PlansManagementView
 *
 * Admin management of plans and the centralized entitlement matrix.
 * Route: /admin/plans
 *
 * Pricing and final entitlement values are founder-controlled data, not code
 * constants. Empty entitlement inputs mean "unlimited".
 */

"use client";

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
  createAdminPlan,
  deleteAdminPlan,
  getAdminPlans,
  updateAdminPlan,
  updateAdminPlanEntitlements,
  type AdminPlan,
} from "@/lib/api/admin";
import { cn } from "@/lib/cn";
import { Layers, Plus, SlidersHorizontal } from "lucide-react";
import { useCallback, useEffect, useMemo, useState } from "react";

type FormState = {
  key: string;
  name: string;
  description: string;
  price: string;
  currency: string;
  billing_interval: "" | "month" | "year";
  verification_mark: boolean;
  is_active: boolean;
  is_default: boolean;
  sort_order: string;
};

const EMPTY_FORM: FormState = {
  key: "",
  name: "",
  description: "",
  price: "",
  currency: "GHS",
  billing_interval: "",
  verification_mark: false,
  is_active: true,
  is_default: false,
  sort_order: "0",
};

export function PlansManagementView() {
  const { confirm } = useConfirm();
  const [plans, setPlans] = useState<AdminPlan[]>([]);
  const [limitKeys, setLimitKeys] = useState<string[]>([]);
  const [configuredDefaults, setConfiguredDefaults] = useState<Record<string, number | null>>({});
  const [defaultPlanKey, setDefaultPlanKey] = useState<string | null>(null);
  const [isLoading, setIsLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  const [modalOpen, setModalOpen] = useState(false);
  const [editing, setEditing] = useState<AdminPlan | null>(null);
  const [form, setForm] = useState<FormState>(EMPTY_FORM);
  const [formErrors, setFormErrors] = useState<Record<string, string>>({});
  const [isSaving, setIsSaving] = useState(false);
  const [busyId, setBusyId] = useState<number | null>(null);

  const [entitlementsFor, setEntitlementsFor] = useState<AdminPlan | null>(null);
  const [entitlementValues, setEntitlementValues] = useState<Record<string, string>>({});
  const [entitlementErrors, setEntitlementErrors] = useState<Record<string, string>>({});

  const load = useCallback(async () => {
    setIsLoading(true);
    setError(null);
    try {
      const response = await getAdminPlans();
      setPlans(response.plans);
      setLimitKeys(response.limit_keys);
      setConfiguredDefaults(response.configured_defaults);
      setDefaultPlanKey(response.default_plan_key);
    } catch {
      setError("Unable to load plans.");
    } finally {
      setIsLoading(false);
    }
  }, []);

  useEffect(() => {
    load();
  }, [load]);

  const openCreate = () => {
    setEditing(null);
    setForm(EMPTY_FORM);
    setFormErrors({});
    setModalOpen(true);
  };

  const openEdit = (plan: AdminPlan) => {
    setEditing(plan);
    setForm({
      key: plan.key,
      name: plan.name,
      description: plan.description ?? "",
      price: plan.price !== null && plan.price !== undefined ? String(plan.price) : "",
      currency: plan.currency ?? "GHS",
      billing_interval: plan.billing_interval ?? "",
      verification_mark: plan.verification_mark,
      is_active: plan.is_active,
      is_default: plan.is_default,
      sort_order: String(plan.sort_order),
    });
    setFormErrors({});
    setModalOpen(true);
  };

  const handleSave = async () => {
    setIsSaving(true);
    setFormErrors({});

    const payload = {
      key: form.key.trim().toUpperCase(),
      name: form.name.trim(),
      description: form.description.trim() || null,
      price: form.price.trim() === "" ? null : Number(form.price),
      currency: form.currency.trim() ? form.currency.trim().toUpperCase() : null,
      billing_interval: form.billing_interval || null,
      verification_mark: form.verification_mark,
      is_active: form.is_active,
      is_default: form.is_default,
      sort_order: Number(form.sort_order || 0),
    };

    try {
      if (editing) {
        await updateAdminPlan(editing.id, payload);
      } else {
        await createAdminPlan(payload);
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

  const handleDelete = async (plan: AdminPlan) => {
    const ok = await confirm({
      title: "Delete plan?",
      description: `"${plan.name}" can only be deleted when no members are assigned to it.`,
      confirmLabel: "Delete",
      variant: "danger",
    });
    if (!ok) return;

    setBusyId(plan.id);
    try {
      await deleteAdminPlan(plan.id);
      await load();
    } catch (e) {
      setError(
        (e as { response?: { data?: { message?: string } } })?.response?.data?.message ??
          "Unable to delete the plan."
      );
    } finally {
      setBusyId(null);
    }
  };

  const openEntitlements = (plan: AdminPlan) => {
    setEntitlementsFor(plan);
    const values: Record<string, string> = {};
    for (const key of limitKeys) {
      const row = plan.entitlements.find((item) => item.limit_key === key);
      values[key] = row && row.limit_value !== null ? String(row.limit_value) : "";
    }
    setEntitlementValues(values);
    setEntitlementErrors({});
  };

  const handleSaveEntitlements = async () => {
    if (!entitlementsFor) return;
    setEntitlementErrors({});

    const payload: Record<string, number | null> = {};
    for (const key of limitKeys) {
      const raw = entitlementValues[key]?.trim() ?? "";
      payload[key] = raw === "" ? null : Number(raw);
    }

    try {
      await updateAdminPlanEntitlements(entitlementsFor.id, payload);
      setEntitlementsFor(null);
      await load();
    } catch (e) {
      setEntitlementErrors({
        entitlements:
          (e as { response?: { data?: { message?: string } } })?.response?.data?.message ??
          "Unable to save entitlements.",
      });
    }
  };

  const columns = useMemo(
    () => [
      { key: "plan", label: "Plan" },
      { key: "key", label: "Key" },
      { key: "price", label: "Price" },
      { key: "members", label: "Members" },
      { key: "entitlements", label: "Entitlements" },
      { key: "status", label: "Status" },
      { key: "actions", label: "", className: "text-right" },
    ],
    []
  );

  return (
    <div className="space-y-8">
      <div className="flex flex-wrap items-start justify-between gap-4">
        <div>
          <h1 className="flex items-center gap-2 text-2xl font-bold text-roicard-text">
            <Layers className="h-6 w-6 text-roicard-accent" />
            Plans &amp; Entitlements
          </h1>
          <p className="mt-1 text-sm text-roicard-text-muted">
            Centralized plan limits. Feature code reads these values through the
            entitlement service; empty means unlimited.
          </p>
        </div>
        <Button onClick={openCreate} className="rounded-lg">
          <Plus className="mr-2 h-4 w-4" />
          New Plan
        </Button>
      </div>

      <div className="rounded-xl border border-roicard-border bg-roicard-bg-elevated p-4 text-sm text-roicard-text-muted">
        Configured fallback plan key: <span className="font-mono text-roicard-text">{defaultPlanKey ?? "—"}</span>.
        Pricing and final limit values are subject to founder confirmation.
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
      ) : plans.length === 0 ? (
        <div className="rounded-2xl border border-roicard-border bg-roicard-bg-elevated p-10 text-center">
          <p className="text-sm text-roicard-text-muted">
            No plans yet. Until one is created, members fall back to the
            configured limits in config/roicard.php.
          </p>
        </div>
      ) : (
        <DataTable columns={columns}>
          {plans.map((plan) => (
            <DataTableRow key={plan.id}>
              <DataTableCell className="font-medium text-roicard-text">
                <span className="block">{plan.name}</span>
                <span className="text-xs text-roicard-text-muted">
                  {plan.description ?? ""}
                </span>
              </DataTableCell>
              <DataTableCell className="font-mono text-xs">{plan.key}</DataTableCell>
              <DataTableCell>
                {plan.price === null || plan.price === undefined
                  ? "—"
                  : `${plan.currency ?? ""} ${Number(plan.price).toFixed(2)}`}
              </DataTableCell>
              <DataTableCell>{plan.users_count ?? 0}</DataTableCell>
              <DataTableCell>{plan.entitlements.length} overrides</DataTableCell>
              <DataTableCell>
                <span
                  className={cn(
                    "inline-flex rounded-full border px-2.5 py-0.5 text-xs font-medium",
                    plan.is_active
                      ? "border-emerald-500/30 bg-emerald-500/10 text-emerald-400"
                      : "border-slate-500/30 bg-slate-500/10 text-slate-400"
                  )}
                >
                  {plan.is_active ? "Active" : "Inactive"}
                  {plan.is_default ? " · default" : ""}
                </span>
              </DataTableCell>
              <DataTableCell className="text-right">
                <div className="flex justify-end gap-2">
                  <Button variant="ghost" size="sm" onClick={() => openEntitlements(plan)}>
                    <SlidersHorizontal className="mr-1 h-4 w-4" />
                    Limits
                  </Button>
                  <Button variant="ghost" size="sm" onClick={() => openEdit(plan)}>
                    Edit
                  </Button>
                  <Button
                    variant="danger"
                    size="sm"
                    disabled={busyId === plan.id}
                    onClick={() => handleDelete(plan)}
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
        title={editing ? "Edit plan" : "New plan"}
        description="Attributes are founder-confirmed data. Limits are edited separately."
        className="max-w-2xl"
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
          <div className="grid gap-4 sm:grid-cols-2">
            <Input
              label="Key"
              placeholder="e.g. MEMBER"
              value={form.key}
              error={formErrors.key}
              onChange={(e) => setForm((f) => ({ ...f, key: e.target.value.toUpperCase() }))}
            />
            <Input
              label="Name"
              value={form.name}
              error={formErrors.name}
              onChange={(e) => setForm((f) => ({ ...f, name: e.target.value }))}
            />
          </div>
          <Textarea
            label="Description (optional)"
            value={form.description}
            error={formErrors.description}
            onChange={(e) => setForm((f) => ({ ...f, description: e.target.value }))}
          />
          <div className="grid gap-4 sm:grid-cols-3">
            <Input
              label="Price (optional)"
              type="number"
              min={0}
              step="0.01"
              value={form.price}
              error={formErrors.price}
              hint="Leave empty until pricing is confirmed."
              onChange={(e) => setForm((f) => ({ ...f, price: e.target.value }))}
            />
            <Input
              label="Currency"
              maxLength={3}
              value={form.currency}
              error={formErrors.currency}
              onChange={(e) =>
                setForm((f) => ({ ...f, currency: e.target.value.toUpperCase() }))
              }
            />
            <div>
              <label htmlFor="plan-interval" className="mb-2 block text-sm font-medium text-roicard-text">
                Billing interval
              </label>
              <select
                id="plan-interval"
                value={form.billing_interval}
                onChange={(e) =>
                  setForm((f) => ({
                    ...f,
                    billing_interval: e.target.value as FormState["billing_interval"],
                  }))
                }
                className="h-11 w-full rounded-lg border border-roicard-border bg-roicard-bg-muted px-3 text-sm text-roicard-text"
              >
                <option value="">Not configured</option>
                <option value="month">Monthly</option>
                <option value="year">Yearly</option>
              </select>
            </div>
            <Input
              label="Sort order"
              type="number"
              min={0}
              value={form.sort_order}
              error={formErrors.sort_order}
              onChange={(e) => setForm((f) => ({ ...f, sort_order: e.target.value }))}
            />
          </div>
          <div className="flex flex-wrap gap-6">
            <label className="flex items-center gap-2 text-sm text-roicard-text">
              <input
                type="checkbox"
                checked={form.verification_mark}
                onChange={(e) => setForm((f) => ({ ...f, verification_mark: e.target.checked }))}
                className="h-4 w-4 rounded border-roicard-border"
              />
              Display verified-member mark on this plan
            </label>
            <label className="flex items-center gap-2 text-sm text-roicard-text">
              <input
                type="checkbox"
                checked={form.is_active}
                onChange={(e) => setForm((f) => ({ ...f, is_active: e.target.checked }))}
                className="h-4 w-4 rounded border-roicard-border"
              />
              Active
            </label>
            <label className="flex items-center gap-2 text-sm text-roicard-text">
              <input
                type="checkbox"
                checked={form.is_default}
                onChange={(e) => setForm((f) => ({ ...f, is_default: e.target.checked }))}
                className="h-4 w-4 rounded border-roicard-border"
              />
              Default plan for members without an assignment
            </label>
          </div>
        </div>
      </Modal>

      <Modal
        isOpen={entitlementsFor !== null}
        onClose={() => setEntitlementsFor(null)}
        title={entitlementsFor ? `Entitlements — ${entitlementsFor.name}` : "Entitlements"}
        description="Leave an input empty for unlimited. Zero disables the feature."
        className="max-w-3xl"
        footer={
          <>
            <Button variant="secondary" onClick={() => setEntitlementsFor(null)}>
              Cancel
            </Button>
            <Button onClick={handleSaveEntitlements}>Save entitlements</Button>
          </>
        }
      >
        {entitlementErrors.entitlements && (
          <p className="mb-3 rounded-lg bg-rose-500/10 px-3 py-2 text-sm text-rose-500">
            {entitlementErrors.entitlements}
          </p>
        )}
        <div className="grid gap-4 sm:grid-cols-2">
          {limitKeys.map((key) => (
            <Input
              key={key}
              label={key}
              type="number"
              min={0}
              placeholder={
                configuredDefaults[key] === null
                  ? "Unlimited"
                  : `Fallback: ${configuredDefaults[key]}`
              }
              value={entitlementValues[key] ?? ""}
              onChange={(e) =>
                setEntitlementValues((values) => ({ ...values, [key]: e.target.value }))
              }
            />
          ))}
        </div>
      </Modal>
    </div>
  );
}
