"use client";

import {
  DataTable,
  DataTableCell,
  DataTableRow,
} from "@/components/admin/DataTable";
import { Button } from "@/components/ui/Button";
import { Input } from "@/components/ui/Input";
import { Modal } from "@/components/ui/Modal";
import { Textarea } from "@/components/ui/Textarea";
import {
  approveAdminOrganization,
  assignAdminOrganizationAdministrator,
  createAdminOrganization,
  getAdminOrganizations,
  rejectAdminOrganization,
  suspendAdminOrganization,
  type AdminOrganization,
} from "@/lib/api/admin";
import { cn } from "@/lib/cn";
import { Building2, Plus, ShieldCheck } from "lucide-react";
import { useCallback, useEffect, useState } from "react";

const STATUS_STYLES: Record<string, string> = {
  verified: "bg-emerald-500/15 text-emerald-400",
  pending: "bg-amber-500/15 text-amber-400",
  rejected: "bg-rose-500/15 text-rose-400",
  suspended: "bg-rose-500/15 text-rose-400",
  unverified: "bg-roicard-bg-muted text-roicard-text-muted",
};

export function AdminOrganizationsView() {
  const [organizations, setOrganizations] = useState<AdminOrganization[]>([]);
  const [isLoading, setIsLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [status, setStatus] = useState<string | null>(null);
  const [q, setQ] = useState("");
  const [verification, setVerification] = useState("pending");
  const [page, setPage] = useState(1);
  const [lastPage, setLastPage] = useState(1);
  const [total, setTotal] = useState(0);
  const [busy, setBusy] = useState(false);

  const [createOpen, setCreateOpen] = useState(false);
  const [createForm, setCreateForm] = useState({
    owner_user_id: "",
    name: "",
    industry: "",
    location: "",
    website_url: "",
    description: "",
    services: "",
  });

  const [adminTarget, setAdminTarget] = useState<AdminOrganization | null>(null);
  const [adminUserId, setAdminUserId] = useState("");
  const [rejectTarget, setRejectTarget] = useState<AdminOrganization | null>(null);
  const [rejectReason, setRejectReason] = useState("");

  const load = useCallback(async () => {
    setIsLoading(true);
    setError(null);
    try {
      const response = await getAdminOrganizations({
        q: q.trim() || undefined,
        verification_status: verification || undefined,
        page,
        per_page: 20,
      });
      setOrganizations(response.data);
      setLastPage(response.last_page ?? 1);
      setTotal(response.total ?? response.data.length);
    } catch {
      setError("Unable to load organizations.");
    } finally {
      setIsLoading(false);
    }
  }, [q, verification, page]);

  useEffect(() => {
    void load();
  }, [load]);

  const approve = async (organization: AdminOrganization) => {
    setBusy(true);
    try {
      const response = await approveAdminOrganization(organization.id);
      setStatus(response.message);
      await load();
    } catch {
      setError("Unable to approve this organization.");
    } finally {
      setBusy(false);
    }
  };

  const reject = async () => {
    if (!rejectTarget) return;
    setBusy(true);
    try {
      const response = await rejectAdminOrganization(rejectTarget.id, rejectReason.trim() || undefined);
      setStatus(response.message);
      setRejectTarget(null);
      setRejectReason("");
      await load();
    } catch {
      setError("Unable to reject this organization.");
    } finally {
      setBusy(false);
    }
  };

  const suspend = async (organization: AdminOrganization) => {
    if (!window.confirm(`Suspend ${organization.name}?`)) return;
    setBusy(true);
    try {
      const response = await suspendAdminOrganization(organization.id);
      setStatus(response.message);
      await load();
    } catch {
      setError("Unable to suspend this organization.");
    } finally {
      setBusy(false);
    }
  };

  const create = async () => {
    setBusy(true);
    setError(null);
    try {
      const response = await createAdminOrganization({
        owner_user_id: Number(createForm.owner_user_id),
        name: createForm.name.trim(),
        industry: createForm.industry.trim() || null,
        location: createForm.location.trim() || null,
        website_url: createForm.website_url.trim() || null,
        description: createForm.description.trim() || null,
        services: createForm.services.split(",").map((s) => s.trim()).filter(Boolean),
        verification_status: "pending",
        status: "inactive",
      });
      setStatus(response.message);
      setCreateOpen(false);
      setCreateForm({
        owner_user_id: "",
        name: "",
        industry: "",
        location: "",
        website_url: "",
        description: "",
        services: "",
      });
      await load();
    } catch (e) {
      const data = (e as { response?: { data?: { message?: string } } })?.response?.data;
      setError(data?.message ?? "Unable to create the organization.");
    } finally {
      setBusy(false);
    }
  };

  const assignAdmin = async () => {
    if (!adminTarget) return;
    setBusy(true);
    try {
      const response = await assignAdminOrganizationAdministrator(
        adminTarget.id,
        Number(adminUserId)
      );
      setStatus(response.message);
      setAdminTarget(null);
      setAdminUserId("");
      await load();
    } catch (e) {
      const data = (e as { response?: { data?: { message?: string } } })?.response?.data;
      setError(data?.message ?? "Unable to assign the administrator.");
    } finally {
      setBusy(false);
    }
  };

  return (
    <div className="space-y-8">
      <div className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div>
          <h1 className="flex items-center gap-2 text-2xl font-bold text-roicard-text">
            <Building2 className="h-6 w-6 text-roicard-accent" />
            Organizations
          </h1>
          <p className="mt-1 text-sm text-roicard-text-muted">
            Review registrations, approve or reject verification, create pilot
            organizations and assign administrators.
          </p>
        </div>
        <Button className="rounded-xl" onClick={() => setCreateOpen(true)}>
          <Plus className="h-4 w-4" />
          Create pilot organization
        </Button>
      </div>

      <div className="grid gap-3 rounded-2xl border border-roicard-border bg-roicard-bg-elevated p-4 sm:grid-cols-2 lg:grid-cols-4">
        <Input label="Search" placeholder="Name, industry, location" value={q} onChange={(e) => setQ(e.target.value)} />
        <div>
          <label htmlFor="org-verification-filter" className="mb-2 block text-sm font-medium text-roicard-text">
            Verification
          </label>
          <select
            id="org-verification-filter"
            value={verification}
            onChange={(e) => {
              setVerification(e.target.value);
              setPage(1);
            }}
            className="h-11 w-full rounded-lg border border-roicard-border bg-roicard-bg-muted px-3 text-sm text-roicard-text"
          >
            <option value="">All</option>
            <option value="pending">Pending</option>
            <option value="verified">Verified</option>
            <option value="rejected">Rejected</option>
            <option value="suspended">Suspended</option>
            <option value="unverified">Unverified</option>
          </select>
        </div>
        <div className="flex items-end gap-2">
          <Button variant="secondary" onClick={() => void load()} disabled={isLoading}>Refresh</Button>
          <span className="self-center text-xs text-roicard-text-muted">{total} organizations</span>
        </div>
      </div>

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
          <div className="h-8 w-8 animate-spin rounded-full border-2 border-roicard-border border-t-roicard-primary" />
        </div>
      ) : organizations.length === 0 ? (
        <div className="rounded-2xl border border-roicard-border bg-roicard-bg-elevated p-10 text-center text-sm text-roicard-text-muted">
          No organizations match these filters.
        </div>
      ) : (
        <>
          <DataTable
            columns={[
              { key: "organization", label: "Organization" },
              { key: "owner", label: "Owner" },
              { key: "status", label: "Status" },
              { key: "members", label: "Members" },
              { key: "actions", label: "Actions" },
            ]}
          >
            {organizations.map((organization) => (
              <DataTableRow key={organization.id}>
                <DataTableCell>
                  <span className="text-sm font-medium text-roicard-text">{organization.name}</span>
                  <span className="block text-xs text-roicard-text-muted">
                    {organization.industry || "No industry"} · {organization.location || "No location"}
                  </span>
                </DataTableCell>
                <DataTableCell className="text-xs">
                  {organization.owner
                    ? `${organization.owner.first_name} ${organization.owner.last_name}`
                    : `#${organization.owner_user_id}`}
                  <span className="block text-roicard-text-muted">{organization.owner?.email}</span>
                </DataTableCell>
                <DataTableCell>
                  <span className={cn(
                    "rounded-full px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wider",
                    STATUS_STYLES[organization.verification_status] ?? STATUS_STYLES.unverified
                  )}>
                    {organization.verification_status}
                  </span>
                  <span className="mt-1 block text-[11px] capitalize text-roicard-text-muted">{organization.status}</span>
                </DataTableCell>
                <DataTableCell className="text-xs">{organization.members_count}</DataTableCell>
                <DataTableCell>
                  <div className="flex flex-wrap gap-1.5">
                    {organization.verification_status !== "verified" && (
                      <Button size="sm" className="rounded-lg" onClick={() => approve(organization)} disabled={busy}>
                        <ShieldCheck className="h-3.5 w-3.5" />
                        Approve
                      </Button>
                    )}
                    {organization.verification_status === "pending" && (
                      <Button size="sm" variant="secondary" className="rounded-lg" onClick={() => setRejectTarget(organization)} disabled={busy}>
                        Reject
                      </Button>
                    )}
                    {organization.verification_status === "verified" && (
                      <Button size="sm" variant="ghost" className="rounded-lg" onClick={() => suspend(organization)} disabled={busy}>
                        Suspend
                      </Button>
                    )}
                    <Button size="sm" variant="ghost" className="rounded-lg" onClick={() => setAdminTarget(organization)}>
                      Assign admin
                    </Button>
                  </div>
                </DataTableCell>
              </DataTableRow>
            ))}
          </DataTable>

          <div className="flex items-center justify-between">
            <p className="text-xs text-roicard-text-muted">Page {page} of {lastPage}</p>
            <div className="flex gap-2">
              <Button variant="secondary" size="sm" disabled={page <= 1} onClick={() => setPage((v) => Math.max(1, v - 1))}>Previous</Button>
              <Button variant="secondary" size="sm" disabled={page >= lastPage} onClick={() => setPage((v) => Math.min(lastPage, v + 1))}>Next</Button>
            </div>
          </div>
        </>
      )}

      <Modal
        isOpen={createOpen}
        onClose={() => setCreateOpen(false)}
        title="Create pilot organization"
        description="Pilot organizations still enter the approval queue unless an administrator explicitly verifies them."
        className="max-w-2xl"
        footer={
          <>
            <Button variant="secondary" onClick={() => setCreateOpen(false)} disabled={busy}>Cancel</Button>
            <Button onClick={create} isLoading={busy}>Create organization</Button>
          </>
        }
      >
        <div className="space-y-4">
          <div className="grid gap-4 sm:grid-cols-2">
            <Input label="Owner user ID" value={createForm.owner_user_id} onChange={(e) => setCreateForm({ ...createForm, owner_user_id: e.target.value })} />
            <Input label="Organization name" value={createForm.name} onChange={(e) => setCreateForm({ ...createForm, name: e.target.value })} />
            <Input label="Industry" value={createForm.industry} onChange={(e) => setCreateForm({ ...createForm, industry: e.target.value })} />
            <Input label="Location" value={createForm.location} onChange={(e) => setCreateForm({ ...createForm, location: e.target.value })} />
            <Input label="Website" type="url" value={createForm.website_url} onChange={(e) => setCreateForm({ ...createForm, website_url: e.target.value })} />
            <Input label="Services (comma separated)" value={createForm.services} onChange={(e) => setCreateForm({ ...createForm, services: e.target.value })} />
          </div>
          <Textarea label="Description" rows={4} value={createForm.description} onChange={(e) => setCreateForm({ ...createForm, description: e.target.value })} />
        </div>
      </Modal>

      <Modal
        isOpen={adminTarget !== null}
        onClose={() => setAdminTarget(null)}
        title={`Assign administrator${adminTarget ? ` · ${adminTarget.name}` : ""}`}
        description="The user is activated as an organization administrator immediately and the action is audited."
        footer={
          <>
            <Button variant="secondary" onClick={() => setAdminTarget(null)} disabled={busy}>Cancel</Button>
            <Button onClick={assignAdmin} isLoading={busy}>Assign administrator</Button>
          </>
        }
      >
        <Input label="User ID" value={adminUserId} onChange={(e) => setAdminUserId(e.target.value)} />
      </Modal>

      <Modal
        isOpen={rejectTarget !== null}
        onClose={() => setRejectTarget(null)}
        title={`Reject${rejectTarget ? ` ${rejectTarget.name}` : ""}`}
        footer={
          <>
            <Button variant="secondary" onClick={() => setRejectTarget(null)} disabled={busy}>Cancel</Button>
            <Button variant="danger" onClick={reject} isLoading={busy}>Reject organization</Button>
          </>
        }
      >
        <Textarea label="Reason (optional)" rows={3} value={rejectReason} onChange={(e) => setRejectReason(e.target.value)} />
      </Modal>
    </div>
  );
}
