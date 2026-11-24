"use client";

import { Button } from "@/components/ui/Button";
import { useConfirm } from "@/components/ui/ConfirmProvider";
import { Input } from "@/components/ui/Input";
import { Modal } from "@/components/ui/Modal";
import { Textarea } from "@/components/ui/Textarea";
import {
  acceptOrganizationAffiliation,
  acceptOrganizationInvitation,
  addOrganizationMember,
  approveOrganizationMember,
  getMyOrganizationInvitations,
  getMyOrganizations,
  getOrganizationWorkspace,
  inviteOrganizationMembers,
  registerOrganization,
  rejectOrganizationMember,
  removeOrganizationMember,
  revokeOrganizationInvitation,
  updateOrganization,
  uploadOrganizationCover,
  uploadOrganizationLogo,
  type Organization,
  type OrganizationInvitation,
  type OrganizationWorkspace,
} from "@/lib/api/organizations";
import { cn } from "@/lib/cn";
import {
  BadgeCheck,
  Building2,
  Clock,
  ImagePlus,
  MailPlus,
  Plus,
  ShieldCheck,
  Trash2,
  UserPlus,
  Users,
} from "lucide-react";
import { useCallback, useEffect, useState } from "react";

type RegisterForm = {
  name: string;
  description: string;
  industry: string;
  website_url: string;
  location: string;
  services: string;
  seeking: string;
  offering: string;
};

const EMPTY_REGISTER: RegisterForm = {
  name: "",
  description: "",
  industry: "",
  website_url: "",
  location: "",
  services: "",
  seeking: "",
  offering: "",
};

function errorMessage(error: unknown): string {
  const data = (
    error as { response?: { data?: { message?: string; errors?: Record<string, string[]> } } }
  )?.response?.data;
  if (data?.errors) {
    return Object.values(data.errors)[0]?.[0] ?? "Unable to save.";
  }
  return data?.message ?? "Unable to save.";
}

function servicesFromText(value: string): string[] {
  return value
    .split(",")
    .map((part) => part.trim())
    .filter(Boolean);
}

export function OrganizationWorkspaceView() {
  const { confirm } = useConfirm();
  const [organizations, setOrganizations] = useState<Organization[]>([]);
  const [selectedId, setSelectedId] = useState<number | null>(null);
  const [workspace, setWorkspace] = useState<OrganizationWorkspace | null>(null);
  const [myInvitations, setMyInvitations] = useState<OrganizationInvitation[]>([]);
  const [isLoading, setIsLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [status, setStatus] = useState<string | null>(null);
  const [registerOpen, setRegisterOpen] = useState(false);
  const [registerForm, setRegisterForm] = useState<RegisterForm>(EMPTY_REGISTER);
  const [editOpen, setEditOpen] = useState(false);
  const [editForm, setEditForm] = useState<RegisterForm>(EMPTY_REGISTER);
  const [inviteOpen, setInviteOpen] = useState(false);
  const [inviteEmails, setInviteEmails] = useState("");
  const [inviteRole, setInviteRole] = useState("member");
  const [addOpen, setAddOpen] = useState(false);
  const [addEmail, setAddEmail] = useState("");
  const [addRole, setAddRole] = useState("member");
  const [isBusy, setIsBusy] = useState(false);

  const selected = organizations.find((organization) => organization.id === selectedId) ?? null;

  const loadOrganizations = useCallback(async () => {
    const [mine, invitations] = await Promise.all([
      getMyOrganizations(),
      getMyOrganizationInvitations().catch(() => ({ invitations: [] })),
    ]);
    setOrganizations(mine.organizations);
    setMyInvitations(invitations.invitations);
    setSelectedId((current) => {
      if (current && mine.organizations.some((row) => row.id === current)) return current;
      return mine.organizations[0]?.id ?? null;
    });
  }, []);

  useEffect(() => {
    setIsLoading(true);
    loadOrganizations()
      .catch(() => setError("Unable to load your organizations."))
      .finally(() => setIsLoading(false));
  }, [loadOrganizations]);

  const loadWorkspace = useCallback(async (organization: Organization) => {
    if (!organization.can_manage) {
      setWorkspace(null);
      return;
    }
    try {
      setWorkspace(await getOrganizationWorkspace(organization.slug));
    } catch {
      setWorkspace(null);
    }
  }, []);

  useEffect(() => {
    if (selected) void loadWorkspace(selected);
  }, [selected, loadWorkspace]);

  const refresh = async () => {
    await loadOrganizations();
    if (selected) await loadWorkspace(selected);
  };

  const register = async () => {
    setIsBusy(true);
    setError(null);
    try {
      const response = await registerOrganization({
        name: registerForm.name.trim(),
        description: registerForm.description.trim() || null,
        industry: registerForm.industry.trim() || null,
        website_url: registerForm.website_url.trim() || null,
        location: registerForm.location.trim() || null,
        services: servicesFromText(registerForm.services),
        seeking: registerForm.seeking.trim() || null,
        offering: registerForm.offering.trim() || null,
      });
      setStatus(response.message);
      setRegisterOpen(false);
      setRegisterForm(EMPTY_REGISTER);
      await refresh();
    } catch (e) {
      setError(errorMessage(e));
    } finally {
      setIsBusy(false);
    }
  };

  const openEdit = () => {
    if (!selected) return;
    setEditForm({
      name: selected.name,
      description: selected.description ?? "",
      industry: selected.industry ?? "",
      website_url: selected.website_url ?? "",
      location: selected.location ?? "",
      services: (selected.services ?? []).join(", "),
      seeking: selected.seeking ?? "",
      offering: selected.offering ?? "",
    });
    setEditOpen(true);
  };

  const saveEdit = async () => {
    if (!selected) return;
    setIsBusy(true);
    try {
      const response = await updateOrganization(selected.slug, {
        name: editForm.name.trim(),
        description: editForm.description.trim() || null,
        industry: editForm.industry.trim() || null,
        website_url: editForm.website_url.trim() || null,
        location: editForm.location.trim() || null,
        services: servicesFromText(editForm.services),
        seeking: editForm.seeking.trim() || null,
        offering: editForm.offering.trim() || null,
      });
      setStatus(response.message);
      setEditOpen(false);
      await refresh();
    } catch (e) {
      setError(errorMessage(e));
    } finally {
      setIsBusy(false);
    }
  };

  const uploadImage = async (kind: "logo" | "cover", file: File | null) => {
    if (!selected || !file) return;
    setIsBusy(true);
    try {
      const response =
        kind === "logo"
          ? await uploadOrganizationLogo(selected.slug, file)
          : await uploadOrganizationCover(selected.slug, file);
      setStatus(response.message);
      await refresh();
    } catch (e) {
      setError(errorMessage(e));
    } finally {
      setIsBusy(false);
    }
  };

  const sendInvites = async () => {
    if (!selected) return;
    const emails = inviteEmails
      .split(/[\s,;]+/)
      .map((email) => email.trim())
      .filter(Boolean);
    if (emails.length === 0) {
      setError("Enter at least one email address.");
      return;
    }

    setIsBusy(true);
    try {
      const response = await inviteOrganizationMembers(selected.slug, {
        emails,
        role: inviteRole,
      });
      setStatus(response.message);
      setInviteOpen(false);
      setInviteEmails("");
      await refresh();
    } catch (e) {
      setError(errorMessage(e));
    } finally {
      setIsBusy(false);
    }
  };

  const addExisting = async () => {
    if (!selected) return;
    setIsBusy(true);
    try {
      const response = await addOrganizationMember(selected.slug, {
        email: addEmail.trim(),
        role: addRole,
      });
      setStatus(response.message);
      setAddOpen(false);
      setAddEmail("");
      await refresh();
    } catch (e) {
      setError(errorMessage(e));
    } finally {
      setIsBusy(false);
    }
  };

  const runMemberAction = async (membershipId: number, action: "approve" | "reject" | "remove" | "accept") => {
    if (!selected) return;
    if (action === "remove") {
      const ok = await confirm({
        title: "Remove this member?",
        description: "Their public affiliation will be removed and their seat freed.",
        confirmLabel: "Remove member",
        variant: "danger",
      });
      if (!ok) return;
    }

    setIsBusy(true);
    try {
      if (action === "approve") await approveOrganizationMember(selected.slug, membershipId);
      if (action === "reject") await rejectOrganizationMember(selected.slug, membershipId);
      if (action === "remove") await removeOrganizationMember(selected.slug, membershipId);
      if (action === "accept") await acceptOrganizationAffiliation(selected.slug, membershipId);
      setStatus("Membership updated.");
      await refresh();
    } catch (e) {
      setError(errorMessage(e));
    } finally {
      setIsBusy(false);
    }
  };

  const acceptInvitation = async (invitation: OrganizationInvitation) => {
    if (!invitation.token) return;
    setIsBusy(true);
    try {
      const response = await acceptOrganizationInvitation(invitation.token);
      setStatus(response.message);
      await refresh();
    } catch (e) {
      setError(errorMessage(e));
    } finally {
      setIsBusy(false);
    }
  };

  const revokeInvite = async (invitation: OrganizationInvitation) => {
    if (!selected) return;
    setIsBusy(true);
    try {
      await revokeOrganizationInvitation(selected.slug, invitation.id);
      setStatus("Invitation revoked.");
      await refresh();
    } catch (e) {
      setError(errorMessage(e));
    } finally {
      setIsBusy(false);
    }
  };

  if (isLoading) {
    return (
      <div className="flex min-h-[40vh] items-center justify-center">
        <div className="h-8 w-8 animate-spin rounded-full border-2 border-roicard-border border-t-roicard-primary" />
      </div>
    );
  }

  return (
    <div className="space-y-8">
      <header className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div>
          <span className="inline-flex items-center gap-2 rounded-full bg-roicard-primary/15 px-3 py-1 text-xs font-medium text-roicard-accent">
            <Building2 className="h-3.5 w-3.5" />
            Organization workspace
          </span>
          <h1 className="mt-3 text-2xl font-bold text-roicard-text sm:text-3xl">
            Your organizations
          </h1>
          <p className="mt-2 text-sm text-roicard-text-muted sm:text-base">
            Register an organization for approval, manage affiliations and invite
            members. Registration never grants automatic verification.
          </p>
        </div>
        <Button className="rounded-xl" onClick={() => setRegisterOpen(true)}>
          <Plus className="h-4 w-4" />
          Register organization
        </Button>      </header>

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

      {myInvitations.length > 0 && (
        <section className="rounded-2xl border border-roicard-accent/30 bg-roicard-primary/5 p-4">
          <h2 className="flex items-center gap-2 text-sm font-semibold text-roicard-text">
            <MailPlus className="h-4 w-4 text-roicard-accent" />
            Pending organization invitations
          </h2>
          <div className="mt-3 space-y-2">
            {myInvitations.map((invitation) => (
              <div key={invitation.id} className="flex flex-wrap items-center justify-between gap-2 rounded-xl border border-roicard-border bg-roicard-bg-elevated p-3">
                <div>
                  <p className="text-sm font-medium text-roicard-text">
                    {invitation.organization?.name ?? "Organization"}
                  </p>
                  <p className="text-xs capitalize text-roicard-text-muted">
                    {invitation.role} invitation
                  </p>
                </div>
                <Button size="sm" className="rounded-lg" onClick={() => acceptInvitation(invitation)} isLoading={isBusy}>
                  Accept affiliation
                </Button>
              </div>
            ))}
          </div>
        </section>
      )}

      {organizations.length === 0 ? (
        <div className="rounded-2xl border border-dashed border-roicard-border bg-roicard-bg-elevated p-8 text-center">
          <h2 className="text-lg font-semibold text-roicard-text">No organizations yet</h2>
          <p className="mx-auto mt-2 max-w-md text-sm text-roicard-text-muted">
            Register your organization to start managing members and services.
            Platform administrators must approve it before it appears publicly.
          </p>
          <Button className="mt-5 rounded-xl" onClick={() => setRegisterOpen(true)}>
            Register organization
          </Button>
        </div>
      ) : (
        <>
          <div className="flex flex-wrap gap-2">
            {organizations.map((organization) => (
              <button
                key={organization.id}
                type="button"
                onClick={() => setSelectedId(organization.id)}
                className={cn(
                  "flex items-center gap-2 rounded-xl border px-4 py-2.5 text-left text-sm transition-colors",
                  organization.id === selectedId
                    ? "border-roicard-primary/40 bg-roicard-primary/10 text-roicard-text"
                    : "border-roicard-border bg-roicard-bg-elevated text-roicard-text-muted hover:text-roicard-text"
                )}
              >
                <span className="flex h-8 w-8 items-center justify-center rounded-full bg-roicard-bg-muted text-xs font-bold text-roicard-accent">
                  {organization.name.slice(0, 1).toUpperCase()}
                </span>
                <span>
                  <span className="block font-medium">{organization.name}</span>
                  <span className="block text-[11px] capitalize text-roicard-text-muted">
                    {organization.verification_status}
                    {organization.can_manage ? " · manager" : " · member"}
                  </span>
                </span>
                {organization.verification_status === "verified" && (
                  <BadgeCheck className="h-4 w-4 text-emerald-400" />
                )}
              </button>
            ))}
          </div>

          {selected && (
            <div className="grid gap-6 lg:grid-cols-[minmax(0,1fr)_340px]">
              <div className="space-y-5">
                <section className="rounded-2xl border border-roicard-border bg-roicard-bg-elevated p-5">
                  <div className="flex flex-wrap items-start justify-between gap-3">
                    <div>
                      <h2 className="text-xl font-semibold text-roicard-text">{selected.name}</h2>
                      <p className="mt-1 text-sm text-roicard-text-muted">
                        {selected.industry || "No industry"} · {selected.location || "No location"}
                      </p>
                      <span
                        className={cn(
                          "mt-2 inline-flex rounded-full px-2.5 py-1 text-[10px] font-semibold uppercase tracking-wider",
                          selected.verification_status === "verified"
                            ? "bg-emerald-500/15 text-emerald-400"
                            : selected.verification_status === "pending"
                            ? "bg-amber-500/15 text-amber-400"
                            : "bg-rose-500/15 text-rose-400"
                        )}
                      >
                        {selected.verification_status}
                      </span>
                    </div>
                    {selected.can_manage && (
                      <Button variant="secondary" size="sm" className="rounded-lg" onClick={openEdit}>
                        Edit details
                      </Button>
                    )}
                  </div>
                  {selected.description && (
                    <p className="mt-3 text-sm leading-relaxed text-roicard-text-muted">{selected.description}</p>
                  )}
                  {selected.services.length > 0 && (
                    <div className="mt-3 flex flex-wrap gap-2">
                      {selected.services.map((service) => (
                        <span key={service} className="rounded-full bg-roicard-bg-muted px-2.5 py-1 text-xs text-roicard-text-muted">
                          {service}
                        </span>
                      ))}
                    </div>
                  )}
                </section>

                {selected.can_manage ? (
                  workspace ? (
                    <>
                      <section className="rounded-2xl border border-roicard-border bg-roicard-bg-elevated p-5">
                        <div className="flex flex-wrap items-center justify-between gap-3">
                          <h2 className="flex items-center gap-2 text-base font-semibold text-roicard-text">
                            <Users className="h-4 w-4 text-roicard-accent" />
                            Members
                          </h2>
                          <div className="flex flex-wrap gap-2">
                            <Button size="sm" className="rounded-lg" onClick={() => setInviteOpen(true)}>
                              <MailPlus className="h-3.5 w-3.5" />
                              Invite by email
                            </Button>
                            <Button size="sm" variant="secondary" className="rounded-lg" onClick={() => setAddOpen(true)}>
                              <UserPlus className="h-3.5 w-3.5" />
                              Add existing account
                            </Button>
                          </div>
                        </div>
                        <p className="mt-1 text-xs text-roicard-text-muted">
                          {workspace.counts.affiliated} publicly affiliated · {workspace.counts.pending} pending ·{" "}
                          {workspace.counts.invited} invited
                          {workspace.seat_limit !== null && ` · seat limit ${workspace.seat_limit}`}
                        </p>

                        <div className="mt-4 space-y-2">
                          {workspace.members.map((member) => {
                            const name = member.user
                              ? `${member.user.first_name} ${member.user.last_name}`.trim()
                              : `User #${member.user_id}`;
                            return (
                              <div key={member.id} className="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-roicard-border px-3 py-2.5">
                                <div className="min-w-0">
                                  <p className="truncate text-sm font-medium text-roicard-text">
                                    {name} <span className="text-xs capitalize text-roicard-text-muted">· {member.role}</span>
                                  </p>
                                  <p className="truncate text-xs text-roicard-text-muted">
                                    {member.user?.email} · {member.status}
                                    {member.publicly_affiliated ? " · public" : " · not public"}
                                  </p>
                                </div>
                                <div className="flex flex-wrap gap-2">
                                  {member.status === "pending" && (
                                    <>
                                      <Button size="sm" className="rounded-lg" onClick={() => runMemberAction(member.id, "approve")} isLoading={isBusy}>
                                        Confirm
                                      </Button>
                                      <Button size="sm" variant="ghost" className="rounded-lg" onClick={() => runMemberAction(member.id, "reject")}>
                                        Reject
                                      </Button>
                                    </>
                                  )}
                                  {(member.status === "invited" || (member.status === "active" && !member.publicly_affiliated)) && (
                                    <span className="text-xs text-roicard-text-muted">
                                      Awaiting member acceptance
                                    </span>
                                  )}
                                  {member.role !== "owner" && member.status !== "left" && (
                                    <Button size="sm" variant="ghost" className="rounded-lg" onClick={() => runMemberAction(member.id, "remove")}>
                                      <Trash2 className="h-3.5 w-3.5" />
                                    </Button>
                                  )}
                                </div>
                              </div>
                            );
                          })}
                        </div>
                      </section>

                      <section className="rounded-2xl border border-roicard-border bg-roicard-bg-elevated p-5">
                        <h2 className="flex items-center gap-2 text-base font-semibold text-roicard-text">
                          <Clock className="h-4 w-4 text-roicard-accent" />
                          Pending invitations
                        </h2>
                        {workspace.invitations.length === 0 ? (
                          <p className="mt-2 text-sm text-roicard-text-muted">No pending invitations.</p>
                        ) : (
                          <div className="mt-3 space-y-2">
                            {workspace.invitations.map((invitation) => (
                              <div key={invitation.id} className="flex flex-wrap items-center justify-between gap-2 rounded-xl border border-roicard-border px-3 py-2">
                                <div>
                                  <p className="text-sm text-roicard-text">{invitation.email}</p>
                                  <p className="text-xs capitalize text-roicard-text-muted">{invitation.role} · {invitation.status}</p>
                                </div>
                                <Button size="sm" variant="ghost" className="rounded-lg" onClick={() => revokeInvite(invitation)}>
                                  Revoke
                                </Button>
                              </div>
                            ))}
                          </div>
                        )}
                      </section>
                    </>
                  ) : (
                    <p className="rounded-2xl border border-dashed border-roicard-border p-5 text-sm text-roicard-text-muted">
                      Loading member workspace…
                    </p>
                  )
                ) : (
                  <p className="rounded-2xl border border-dashed border-roicard-border p-5 text-sm text-roicard-text-muted">
                    You are a member of this organization. Member management is
                    limited to organization administrators.
                  </p>
                )}
              </div>

              <aside className="space-y-4">
                <div className="rounded-2xl border border-roicard-border bg-roicard-bg-elevated p-4">
                  <p className="text-xs font-semibold uppercase tracking-wider text-roicard-text-muted">Logo</p>
                  <div className="mt-3 flex items-center gap-3">
                    {selected.logo_url ? (
                      // eslint-disable-next-line @next/next/no-img-element
                      <img src={selected.logo_url} alt={selected.name} className="h-16 w-16 rounded-xl border border-roicard-border bg-white object-contain p-1" />
                    ) : (
                      <span className="flex h-16 w-16 items-center justify-center rounded-xl bg-roicard-bg-muted text-lg font-bold text-roicard-accent">
                        {selected.name.slice(0, 1).toUpperCase()}
                      </span>
                    )}
                    {selected.can_manage && (
                      <label className="cursor-pointer rounded-lg border border-roicard-border px-3 py-1.5 text-xs font-medium text-roicard-text-muted hover:text-roicard-text">
                        <ImagePlus className="mr-1 inline h-3.5 w-3.5" />
                        Upload
                        <input type="file" accept="image/*" className="hidden" onChange={(event) => void uploadImage("logo", event.target.files?.[0] ?? null)} />
                      </label>
                    )}
                  </div>

                  <p className="mt-5 text-xs font-semibold uppercase tracking-wider text-roicard-text-muted">Cover image</p>
                  <div className="mt-3">
                    {selected.cover_url ? (
                      // eslint-disable-next-line @next/next/no-img-element
                      <img src={selected.cover_url} alt={`${selected.name} cover`} className="h-24 w-full rounded-xl border border-roicard-border object-cover" />
                    ) : (
                      <div className="flex h-24 w-full items-center justify-center rounded-xl border border-dashed border-roicard-border text-xs text-roicard-text-muted">
                        No cover image
                      </div>
                    )}
                    {selected.can_manage && (
                      <label className="mt-2 inline-flex cursor-pointer rounded-lg border border-roicard-border px-3 py-1.5 text-xs font-medium text-roicard-text-muted hover:text-roicard-text">
                        <ImagePlus className="mr-1 inline h-3.5 w-3.5" />
                        Upload cover
                        <input type="file" accept="image/*" className="hidden" onChange={(event) => void uploadImage("cover", event.target.files?.[0] ?? null)} />
                      </label>
                    )}
                  </div>
                </div>

                {selected.verification_status === "verified" && (
                  <a
                    href={`/organizations/${selected.slug}`}
                    target="_blank"
                    rel="noreferrer"
                    className="block rounded-2xl border border-roicard-border bg-roicard-bg-elevated p-4 text-sm font-medium text-roicard-accent hover:border-roicard-accent/40"
                  >
                    <ShieldCheck className="mr-1 inline h-4 w-4" />
                    View public organization page
                  </a>
                )}
              </aside>
            </div>
          )}
        </>
      )}

      <Modal
        isOpen={registerOpen}
        onClose={() => setRegisterOpen(false)}
        title="Register organization"
        description="Your organization enters a pending approval state. A platform administrator must verify it before it appears publicly."
        className="max-w-2xl"
        footer={
          <>
            <Button variant="secondary" onClick={() => setRegisterOpen(false)} disabled={isBusy}>Cancel</Button>
            <Button onClick={register} isLoading={isBusy}>Submit for approval</Button>
          </>
        }
      >
        <div className="space-y-4">
          <Input label="Organization name" value={registerForm.name} onChange={(e) => setRegisterForm({ ...registerForm, name: e.target.value })} />
          <Textarea label="Description" rows={3} value={registerForm.description} onChange={(e) => setRegisterForm({ ...registerForm, description: e.target.value })} />
          <div className="grid gap-4 sm:grid-cols-2">
            <Input label="Industry" value={registerForm.industry} onChange={(e) => setRegisterForm({ ...registerForm, industry: e.target.value })} />
            <Input label="Location" value={registerForm.location} onChange={(e) => setRegisterForm({ ...registerForm, location: e.target.value })} />
            <Input label="Website" type="url" value={registerForm.website_url} onChange={(e) => setRegisterForm({ ...registerForm, website_url: e.target.value })} />
            <Input label="Services (comma separated)" value={registerForm.services} onChange={(e) => setRegisterForm({ ...registerForm, services: e.target.value })} />
          </div>
          <div className="grid gap-4 sm:grid-cols-2">
            <Textarea label="Seeking" rows={3} value={registerForm.seeking} onChange={(e) => setRegisterForm({ ...registerForm, seeking: e.target.value })} />
            <Textarea label="Offering" rows={3} value={registerForm.offering} onChange={(e) => setRegisterForm({ ...registerForm, offering: e.target.value })} />
          </div>
        </div>
      </Modal>

      <Modal
        isOpen={editOpen}
        onClose={() => setEditOpen(false)}
        title="Edit organization"
        className="max-w-2xl"
        footer={
          <>
            <Button variant="secondary" onClick={() => setEditOpen(false)} disabled={isBusy}>Cancel</Button>
            <Button onClick={saveEdit} isLoading={isBusy}>Save changes</Button>
          </>
        }
      >
        <div className="space-y-4">
          <Input label="Organization name" value={editForm.name} onChange={(e) => setEditForm({ ...editForm, name: e.target.value })} />
          <Textarea label="Description" rows={3} value={editForm.description} onChange={(e) => setEditForm({ ...editForm, description: e.target.value })} />
          <div className="grid gap-4 sm:grid-cols-2">
            <Input label="Industry" value={editForm.industry} onChange={(e) => setEditForm({ ...editForm, industry: e.target.value })} />
            <Input label="Location" value={editForm.location} onChange={(e) => setEditForm({ ...editForm, location: e.target.value })} />
            <Input label="Website" type="url" value={editForm.website_url} onChange={(e) => setEditForm({ ...editForm, website_url: e.target.value })} />
            <Input label="Services (comma separated)" value={editForm.services} onChange={(e) => setEditForm({ ...editForm, services: e.target.value })} />
          </div>
          <div className="grid gap-4 sm:grid-cols-2">
            <Textarea label="Seeking" rows={3} value={editForm.seeking} onChange={(e) => setEditForm({ ...editForm, seeking: e.target.value })} />
            <Textarea label="Offering" rows={3} value={editForm.offering} onChange={(e) => setEditForm({ ...editForm, offering: e.target.value })} />
          </div>
        </div>
      </Modal>

      <Modal
        isOpen={inviteOpen}
        onClose={() => setInviteOpen(false)}
        title="Invite members"
        description="Enter one email per line or separate with commas. Existing accounts get an in-app invitation; new addresses get an email."
        footer={
          <>
            <Button variant="secondary" onClick={() => setInviteOpen(false)} disabled={isBusy}>Cancel</Button>
            <Button onClick={sendInvites} isLoading={isBusy}>Send invitations</Button>
          </>
        }
      >
        <div className="space-y-4">
          <Textarea label="Emails" rows={5} value={inviteEmails} onChange={(e) => setInviteEmails(e.target.value)} placeholder="member@example.com, another@example.com" />
          <div>
            <label htmlFor="invite-role" className="mb-2 block text-sm font-medium text-roicard-text">Role</label>
            <select id="invite-role" value={inviteRole} onChange={(e) => setInviteRole(e.target.value)} className="h-11 w-full rounded-lg border border-roicard-border bg-roicard-bg-muted px-3 text-sm text-roicard-text">
              <option value="member">Member</option>
              <option value="moderator">Moderator</option>
              <option value="manager">Organization administrator</option>
            </select>
          </div>
        </div>
      </Modal>

      <Modal
        isOpen={addOpen}
        onClose={() => setAddOpen(false)}
        title="Add existing account"
        description="The member must still accept the affiliation before it appears publicly."
        footer={
          <>
            <Button variant="secondary" onClick={() => setAddOpen(false)} disabled={isBusy}>Cancel</Button>
            <Button onClick={addExisting} isLoading={isBusy}>Send invitation</Button>
          </>
        }
      >
        <div className="space-y-4">
          <Input label="Account email" type="email" value={addEmail} onChange={(e) => setAddEmail(e.target.value)} />
          <div>
            <label htmlFor="add-role" className="mb-2 block text-sm font-medium text-roicard-text">Role</label>
            <select id="add-role" value={addRole} onChange={(e) => setAddRole(e.target.value)} className="h-11 w-full rounded-lg border border-roicard-border bg-roicard-bg-muted px-3 text-sm text-roicard-text">
              <option value="member">Member</option>
              <option value="moderator">Moderator</option>
              <option value="manager">Organization administrator</option>
            </select>
          </div>
        </div>
      </Modal>
    </div>
  );
}
