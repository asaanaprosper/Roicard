/**
 * CommunitiesManagementView
 *
 * Admin management of event communities, groups and organization
 * communities, including ownership and membership records.
 * Route: /admin/communities
 */

"use client";

import { AdminSelect } from "@/components/admin/AdminSelect";
import {
  DataTable,
  DataTableCell,
  DataTableRow,
} from "@/components/admin/DataTable";
import { useAdmin } from "@/components/admin/AdminProvider";
import { Button } from "@/components/ui/Button";
import { useConfirm } from "@/components/ui/ConfirmProvider";
import { Input } from "@/components/ui/Input";
import { Modal } from "@/components/ui/Modal";
import { Textarea } from "@/components/ui/Textarea";
import {
  addAdminCommunityMember,
  createAdminCommunity,
  deleteAdminCommunity,
  getAdminCommunities,
  getAdminCommunityMembers,
  removeAdminCommunityMember,
  updateAdminCommunity,
  updateAdminCommunityMember,
  type AdminCommunity,
  type AdminCommunityJoinMethod,
  type AdminCommunityMember,
  type AdminCommunityStatus,
  type AdminCommunityType,
} from "@/lib/api/admin";
import { cn } from "@/lib/cn";
import { Network, Plus, Users } from "lucide-react";
import { useCallback, useEffect, useState } from "react";

type FormState = {
  owner_user_id: string;
  name: string;
  slug: string;
  description: string;
  type: AdminCommunityType;
  join_method: AdminCommunityJoinMethod;
  status: AdminCommunityStatus;
  organisation_name: string;
  member_seat_limit: string;
  starts_at: string;
  ends_at: string;
};

const EMPTY_FORM: FormState = {
  owner_user_id: "",
  name: "",
  slug: "",
  description: "",
  type: "group",
  join_method: "open",
  status: "active",
  organisation_name: "",
  member_seat_limit: "",
  starts_at: "",
  ends_at: "",
};

function toLocalInput(iso: string | null): string {
  if (!iso) return "";
  const date = new Date(iso);
  if (Number.isNaN(date.getTime())) return "";
  const local = new Date(date.getTime() - date.getTimezoneOffset() * 60000);
  return local.toISOString().slice(0, 16);
}

function formatDate(iso: string | null): string {
  if (!iso) return "—";
  return new Date(iso).toLocaleDateString(undefined, { dateStyle: "medium" });
}

export function CommunitiesManagementView() {
  const { users } = useAdmin();
  const { confirm } = useConfirm();

  const [communities, setCommunities] = useState<AdminCommunity[]>([]);
  const [isLoading, setIsLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [statusFilter, setStatusFilter] = useState<"" | AdminCommunityStatus>("");
  const [typeFilter, setTypeFilter] = useState<"" | AdminCommunityType>("");
  const [search, setSearch] = useState("");

  const [modalOpen, setModalOpen] = useState(false);
  const [editing, setEditing] = useState<AdminCommunity | null>(null);
  const [form, setForm] = useState<FormState>(EMPTY_FORM);
  const [formErrors, setFormErrors] = useState<Record<string, string>>({});
  const [isSaving, setIsSaving] = useState(false);
  const [busyId, setBusyId] = useState<number | null>(null);

  const [membersFor, setMembersFor] = useState<AdminCommunity | null>(null);
  const [members, setMembers] = useState<AdminCommunityMember[]>([]);
  const [membersLoading, setMembersLoading] = useState(false);
  const [memberUserId, setMemberUserId] = useState("");
  const [memberRole, setMemberRole] = useState<AdminCommunityMember["role"]>("member");
  const [memberStatus, setMemberStatus] = useState<AdminCommunityMember["status"]>("active");
  const [memberBusy, setMemberBusy] = useState(false);

  const load = useCallback(async () => {
    setIsLoading(true);
    setError(null);
    try {
      const response = await getAdminCommunities({
        per_page: 100,
        status: statusFilter || undefined,
        type: typeFilter || undefined,
        q: search.trim() || undefined,
      });
      setCommunities(response.data);
    } catch {
      setError("Unable to load communities.");
    } finally {
      setIsLoading(false);
    }
  }, [statusFilter, typeFilter, search]);

  useEffect(() => {
    load();
  }, [load]);

  const openCreate = () => {
    setEditing(null);
    setForm(EMPTY_FORM);
    setFormErrors({});
    setModalOpen(true);
  };

  const openEdit = (community: AdminCommunity) => {
    setEditing(community);
    setForm({
      owner_user_id: String(community.owner_user_id),
      name: community.name,
      slug: community.slug,
      description: community.description ?? "",
      type: community.type,
      join_method: community.join_method,
      status: community.status,
      organisation_name: community.organisation_name ?? "",
      member_seat_limit:
        community.member_seat_limit !== null ? String(community.member_seat_limit) : "",
      starts_at: toLocalInput(community.starts_at),
      ends_at: toLocalInput(community.ends_at),
    });
    setFormErrors({});
    setModalOpen(true);
  };

  const handleSave = async () => {
    setIsSaving(true);
    setFormErrors({});

    const payload = {
      owner_user_id: Number(form.owner_user_id),
      name: form.name.trim(),
      slug: form.slug.trim() || null,
      description: form.description.trim() || null,
      type: form.type,
      join_method: form.join_method,
      status: form.status,
      organisation_name: form.organisation_name.trim() || null,
      member_seat_limit: form.member_seat_limit ? Number(form.member_seat_limit) : null,
      starts_at: form.starts_at || null,
      ends_at: form.ends_at || null,
    };

    try {
      if (editing) {
        await updateAdminCommunity(editing.id, payload);
      } else {
        await createAdminCommunity(payload);
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

  const handleDelete = async (community: AdminCommunity) => {
    const ok = await confirm({
      title: "Delete community?",
      description: `"${community.name}" and all of its membership records will be removed.`,
      confirmLabel: "Delete",
      variant: "danger",
    });
    if (!ok) return;

    setBusyId(community.id);
    try {
      await deleteAdminCommunity(community.id);
      await load();
    } catch (e) {
      setError(
        (e as { response?: { data?: { message?: string } } })?.response?.data?.message ??
          "Unable to delete the community."
      );
    } finally {
      setBusyId(null);
    }
  };

  const openMembers = async (community: AdminCommunity) => {
    setMembersFor(community);
    setMembers([]);
    setMembersLoading(true);
    setMemberUserId("");
    try {
      const response = await getAdminCommunityMembers(community.id, { per_page: 100 });
      setMembers(response.data);
    } catch {
      setError("Unable to load community members.");
    } finally {
      setMembersLoading(false);
    }
  };

  const reloadMembers = async (communityId: number) => {
    const response = await getAdminCommunityMembers(communityId, { per_page: 100 });
    setMembers(response.data);
  };

  const handleAddMember = async () => {
    if (!membersFor || !memberUserId) return;
    setMemberBusy(true);
    try {
      await addAdminCommunityMember(membersFor.id, {
        user_id: Number(memberUserId),
        role: memberRole,
        status: memberStatus,
        join_method: "admin",
      });
      setMemberUserId("");
      await reloadMembers(membersFor.id);
      await load();
    } catch (e) {
      setError(
        (e as { response?: { data?: { message?: string } } })?.response?.data?.message ??
          "Unable to add the member."
      );
    } finally {
      setMemberBusy(false);
    }
  };

  const handleMemberUpdate = async (
    membership: AdminCommunityMember,
    patch: Partial<Pick<AdminCommunityMember, "role" | "status">>
  ) => {
    if (!membersFor) return;
    setMemberBusy(true);
    try {
      await updateAdminCommunityMember(membersFor.id, membership.id, patch);
      await reloadMembers(membersFor.id);
      await load();
    } catch (e) {
      setError(
        (e as { response?: { data?: { message?: string } } })?.response?.data?.message ??
          "Unable to update the membership."
      );
    } finally {
      setMemberBusy(false);
    }
  };

  const handleRemoveMember = async (membership: AdminCommunityMember) => {
    if (!membersFor) return;
    const ok = await confirm({
      title: "Remove member?",
      description: "The membership record will be deleted. They can rejoin if the community is open.",
      confirmLabel: "Remove",
      variant: "danger",
    });
    if (!ok) return;

    setMemberBusy(true);
    try {
      await removeAdminCommunityMember(membersFor.id, membership.id);
      await reloadMembers(membersFor.id);
      await load();
    } catch (e) {
      setError(
        (e as { response?: { data?: { message?: string } } })?.response?.data?.message ??
          "Unable to remove the member."
      );
    } finally {
      setMemberBusy(false);
    }
  };

  const columns = [
    { key: "name", label: "Community" },
    { key: "type", label: "Type" },
    { key: "join", label: "Join method" },
    { key: "owner", label: "Owner" },
    { key: "members", label: "Members" },
    { key: "dates", label: "Dates" },
    { key: "status", label: "Status" },
    { key: "actions", label: "", className: "text-right" },
  ];

  return (
    <div className="space-y-8">
      <div className="flex flex-wrap items-start justify-between gap-4">
        <div>
          <h1 className="flex items-center gap-2 text-2xl font-bold text-roicard-text">
            <Network className="h-6 w-6 text-roicard-accent" />
            Communities
          </h1>
          <p className="mt-1 text-sm text-roicard-text-muted">
            Manage event communities, groups and organization communities,
            including ownership, join methods and membership roles.
          </p>
        </div>
        <div className="flex flex-wrap items-end gap-3">
          <Input
            placeholder="Search communities…"
            value={search}
            onChange={(e) => setSearch(e.target.value)}
            className="w-52"
          />
          <AdminSelect
            label="Type"
            value={typeFilter}
            onChange={(e) => setTypeFilter(e.target.value as "" | AdminCommunityType)}
            className="w-40"
          >
            <option value="">All types</option>
            <option value="event">Event</option>
            <option value="group">Group</option>
            <option value="organization">Organization</option>
          </AdminSelect>
          <AdminSelect
            label="Status"
            value={statusFilter}
            onChange={(e) => setStatusFilter(e.target.value as "" | AdminCommunityStatus)}
            className="w-40"
          >
            <option value="">All statuses</option>
            <option value="active">Active</option>
            <option value="inactive">Inactive</option>
            <option value="archived">Archived</option>
          </AdminSelect>
          <Button onClick={openCreate} className="rounded-lg">
            <Plus className="mr-2 h-4 w-4" />
            New Community
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
      ) : communities.length === 0 ? (
        <div className="rounded-2xl border border-roicard-border bg-roicard-bg-elevated p-10 text-center">
          <p className="text-sm text-roicard-text-muted">No communities yet.</p>
        </div>
      ) : (
        <DataTable columns={columns}>
          {communities.map((community) => (
            <DataTableRow key={community.id}>
              <DataTableCell className="font-medium text-roicard-text">
                <span className="block">{community.name}</span>
                <span className="font-mono text-xs text-roicard-text-muted">{community.slug}</span>
              </DataTableCell>
              <DataTableCell className="capitalize">{community.type}</DataTableCell>
              <DataTableCell className="capitalize">{community.join_method}</DataTableCell>
              <DataTableCell>
                {community.owner
                  ? `${community.owner.first_name} ${community.owner.last_name}`
                  : `#${community.owner_user_id}`}
              </DataTableCell>
              <DataTableCell>
                {community.active_members_count ?? 0} active / {community.members_count ?? 0}
              </DataTableCell>
              <DataTableCell className="text-xs">
                <span className="block">Start: {formatDate(community.starts_at)}</span>
                <span className="block">End: {formatDate(community.ends_at)}</span>
              </DataTableCell>
              <DataTableCell>
                <span
                  className={cn(
                    "inline-flex rounded-full border px-2.5 py-0.5 text-xs font-medium capitalize",
                    community.status === "active"
                      ? "border-emerald-500/30 bg-emerald-500/10 text-emerald-400"
                      : "border-slate-500/30 bg-slate-500/10 text-slate-400"
                  )}
                >
                  {community.status}
                </span>
              </DataTableCell>
              <DataTableCell className="text-right">
                <div className="flex justify-end gap-2">
                  <Button variant="ghost" size="sm" onClick={() => openMembers(community)}>
                    <Users className="mr-1 h-4 w-4" />
                    Members
                  </Button>
                  <Button variant="ghost" size="sm" onClick={() => openEdit(community)}>
                    Edit
                  </Button>
                  <Button
                    variant="danger"
                    size="sm"
                    disabled={busyId === community.id}
                    onClick={() => handleDelete(community)}
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
        title={editing ? "Edit community" : "New community"}
        description="Ownership is enforced server-side. A new owner receives an owner-role membership."
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
          <AdminSelect
            label="Owner"
            value={form.owner_user_id}
            error={formErrors.owner_user_id}
            onChange={(e) => setForm((f) => ({ ...f, owner_user_id: e.target.value }))}
          >
            <option value="">Select an owner…</option>
            {users.map((user) => (
              <option key={user.id} value={user.id}>
                {user.firstName} {user.lastName} ({user.email})
              </option>
            ))}
            {form.owner_user_id &&
              !users.some((user) => user.id === form.owner_user_id) && (
                <option value={form.owner_user_id}>User #{form.owner_user_id}</option>
              )}
          </AdminSelect>
          <div className="grid gap-4 sm:grid-cols-2">
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
          </div>
          <Textarea
            label="Description (optional)"
            value={form.description}
            error={formErrors.description}
            onChange={(e) => setForm((f) => ({ ...f, description: e.target.value }))}
          />
          <div className="grid gap-4 sm:grid-cols-3">
            <AdminSelect
              label="Type"
              value={form.type}
              error={formErrors.type}
              onChange={(e) => setForm((f) => ({ ...f, type: e.target.value as AdminCommunityType }))}
            >
              <option value="event">Event</option>
              <option value="group">Group</option>
              <option value="organization">Organization</option>
            </AdminSelect>
            <AdminSelect
              label="Join method"
              value={form.join_method}
              error={formErrors.join_method}
              onChange={(e) =>
                setForm((f) => ({
                  ...f,
                  join_method: e.target.value as AdminCommunityJoinMethod,
                }))
              }
            >
              <option value="open">Open</option>
              <option value="request">Request approval</option>
              <option value="invite">Invite only</option>
            </AdminSelect>
            <AdminSelect
              label="Status"
              value={form.status}
              error={formErrors.status}
              onChange={(e) => setForm((f) => ({ ...f, status: e.target.value as AdminCommunityStatus }))}
            >
              <option value="active">Active</option>
              <option value="inactive">Inactive</option>
              <option value="archived">Archived</option>
            </AdminSelect>
          </div>
          {form.type === "organization" && (
            <div className="grid gap-4 sm:grid-cols-2">
              <Input
                label="Organization name"
                value={form.organisation_name}
                error={formErrors.organisation_name}
                onChange={(e) => setForm((f) => ({ ...f, organisation_name: e.target.value }))}
              />
              <Input
                label="Member seat limit (optional)"
                type="number"
                min={1}
                value={form.member_seat_limit}
                error={formErrors.member_seat_limit}
                hint="Also constrained by the plan ORG_MEMBER_SEATS entitlement."
                onChange={(e) => setForm((f) => ({ ...f, member_seat_limit: e.target.value }))}
              />
            </div>
          )}
          <div className="grid gap-4 sm:grid-cols-2">
            <Input
              label="Starts at (optional)"
              type="datetime-local"
              value={form.starts_at}
              error={formErrors.starts_at}
              onChange={(e) => setForm((f) => ({ ...f, starts_at: e.target.value }))}
            />
            <Input
              label="Ends at (optional)"
              type="datetime-local"
              value={form.ends_at}
              error={formErrors.ends_at}
              onChange={(e) => setForm((f) => ({ ...f, ends_at: e.target.value }))}
            />
          </div>
        </div>
      </Modal>

      <Modal
        isOpen={membersFor !== null}
        onClose={() => setMembersFor(null)}
        title={membersFor ? `Members — ${membersFor.name}` : "Community members"}
        description="Approve pending requests, manage roles, or add a member directly."
        className="max-w-3xl"
        footer={
          <Button variant="secondary" onClick={() => setMembersFor(null)}>
            Close
          </Button>
        }
      >
        <div className="space-y-5">
          <div className="grid gap-3 rounded-xl border border-roicard-border bg-roicard-bg-muted/40 p-3 sm:grid-cols-4">
            <AdminSelect
              label="Member"
              value={memberUserId}
              onChange={(e) => setMemberUserId(e.target.value)}
            >
              <option value="">Select user…</option>
              {users.map((user) => (
                <option key={user.id} value={user.id}>
                  {user.firstName} {user.lastName}
                </option>
              ))}
            </AdminSelect>
            <AdminSelect
              label="Role"
              value={memberRole}
              onChange={(e) => setMemberRole(e.target.value as AdminCommunityMember["role"])}
            >
              <option value="member">Member</option>
              <option value="moderator">Moderator</option>
              <option value="manager">Manager</option>
            </AdminSelect>
            <AdminSelect
              label="Status"
              value={memberStatus}
              onChange={(e) => setMemberStatus(e.target.value as AdminCommunityMember["status"])}
            >
              <option value="active">Active</option>
              <option value="pending">Pending</option>
              <option value="invited">Invited</option>
            </AdminSelect>
            <Button
              className="self-end"
              onClick={handleAddMember}
              disabled={!memberUserId || memberBusy}
            >
              Add member
            </Button>
          </div>

          {membersLoading ? (
            <div className="flex min-h-[20vh] items-center justify-center">
              <div className="h-7 w-7 animate-spin rounded-full border-2 border-roicard-border border-t-roicard-primary" />
            </div>
          ) : members.length === 0 ? (
            <p className="text-sm text-roicard-text-muted">No membership records yet.</p>
          ) : (
            <div className="divide-y divide-roicard-border/60 rounded-xl border border-roicard-border">
              {members.map((membership) => (
                <div
                  key={membership.id}
                  className="flex flex-wrap items-center justify-between gap-3 p-3"
                >
                  <div className="min-w-0">
                    <p className="truncate text-sm font-medium text-roicard-text">
                      {membership.user
                        ? `${membership.user.first_name} ${membership.user.last_name}`
                        : `User #${membership.user_id}`}
                    </p>
                    <p className="truncate text-xs text-roicard-text-muted">
                      {membership.user?.email} · joined {formatDate(membership.joined_at)}
                    </p>
                  </div>
                  <div className="flex flex-wrap items-center gap-2">
                    <AdminSelect
                      value={membership.role}
                      disabled={memberBusy || membership.role === "owner"}
                      onChange={(e) =>
                        handleMemberUpdate(membership, {
                          role: e.target.value as AdminCommunityMember["role"],
                        })
                      }
                      className="h-9 w-32"
                    >
                      <option value="member">Member</option>
                      <option value="moderator">Moderator</option>
                      <option value="manager">Manager</option>
                      {membership.role === "owner" && <option value="owner">Owner</option>}
                    </AdminSelect>
                    <span
                      className={cn(
                        "inline-flex rounded-full border px-2.5 py-0.5 text-xs font-medium capitalize",
                        membership.status === "active"
                          ? "border-emerald-500/30 bg-emerald-500/10 text-emerald-400"
                          : "border-amber-500/30 bg-amber-500/10 text-amber-400"
                      )}
                    >
                      {membership.status}
                    </span>
                    {membership.status !== "active" && membership.role !== "owner" && (
                      <Button
                        variant="secondary"
                        size="sm"
                        disabled={memberBusy}
                        onClick={() => handleMemberUpdate(membership, { status: "active" })}
                      >
                        Approve
                      </Button>
                    )}
                    {membership.role !== "owner" && (
                      <Button
                        variant="danger"
                        size="sm"
                        disabled={memberBusy}
                        onClick={() => handleRemoveMember(membership)}
                      >
                        Remove
                      </Button>
                    )}
                  </div>
                </div>
              ))}
            </div>
          )}
        </div>
      </Modal>
    </div>
  );
}
