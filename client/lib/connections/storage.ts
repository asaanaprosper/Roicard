/**
 * Connection persistence (API-backed).
 *
 * Sources the authenticated user's guest and member connection requests plus
 * established connections from the ROICARD backend.
 */

import {
  acceptMemberConnection,
  createConnection,
  declineMemberConnection,
  getConnections as fetchApiConnections,
  removeMemberConnection,
  updateConnection,
  withdrawMemberConnection,
  type Connection as ApiConnection,
} from "@/lib/api/connections";
import type { ConnectionRequestData } from "@/lib/profile/types";
import type {
  Connection,
  ConnectionPerson,
  ConnectionSummary,
  IncomingConnectionRequest,
} from "@/lib/connections/types";

function splitName(name: string): string[] {
  return name.trim().split(/\s+/).filter(Boolean);
}

/**
 * Resolve the "other party" for a connection row relative to the viewer.
 * Member rows carry explicit sender/recipient profile ids; guest rows fall
 * back to the linked account or the guest name submitted with the request.
 */
function toConnectionPerson(api: ApiConnection): ConnectionPerson {
  const direction = api.direction ?? "received";

  if (api.request_type === "member") {
    if (direction === "sent") {
      const member = api.member;
      const profile = api.recipient_profile ?? member?.profile ?? null;
      return {
        id: String(api.id),
        username: profile?.slug ?? undefined,
        firstName: member?.first_name ?? "",
        lastName: member?.last_name ?? "",
        profilePhotoUrl: member?.profile?.avatar_url ?? profile?.avatar_url ?? null,
        professionalTitle: profile?.title ?? "",
        organization: profile?.organisation ?? "",
        email: member?.email,
        userId: api.member_id ? String(api.member_id) : undefined,
      };
    }

    const sender = api.guest_user;
    const profile = api.sender_profile ?? sender?.profile ?? null;
    const fallback = splitName(api.guest_name);
    return {
      id: String(api.id),
      username: profile?.slug ?? undefined,
      firstName: sender?.first_name ?? fallback[0] ?? "",
      lastName: sender?.last_name ?? fallback.slice(1).join(" "),
      profilePhotoUrl: sender?.profile?.avatar_url ?? profile?.avatar_url ?? null,
      professionalTitle: profile?.title ?? "",
      organization: profile?.organisation ?? api.guest_org ?? "",
      email: sender?.email ?? api.guest_email,
      userId: api.guest_user_id ? String(api.guest_user_id) : undefined,
    };
  }

  if (direction === "sent" && api.member) {
    const member = api.member;
    return {
      id: String(api.id),
      username: member.profile?.slug ?? undefined,
      firstName: member.first_name,
      lastName: member.last_name,
      profilePhotoUrl: member.profile?.avatar_url || null,
      professionalTitle: member.profile?.title ?? "",
      organization: member.profile?.organisation ?? "",
      email: member.email,
      userId: String(api.member_id),
      guestUserId: String(api.member_id),
      meetingContext: api.guest_meeting_context || undefined,
      introduction: api.guest_introduction || undefined,
      intent: api.guest_intent || undefined,
    };
  }

  // "received" (or fallback): the other party is the requesting guest.
  const [firstName, ...rest] = splitName(api.guest_name);
  return {
    id: String(api.id),
    username: api.guest_user?.profile?.slug ?? undefined,
    firstName,
    lastName: rest.join(" "),
    profilePhotoUrl: api.guest_user?.profile?.avatar_url || null,
    professionalTitle: api.guest_user?.profile?.title ?? "",
    organization: api.guest_org || api.guest_user?.profile?.organisation || "",
    email: api.guest_email,
    phone: api.guest_phone || undefined,
    userId: api.guest_user_id ? String(api.guest_user_id) : undefined,
    guestUserId: api.guest_user_id ? String(api.guest_user_id) : undefined,
    meetingContext: api.guest_meeting_context || undefined,
    introduction: api.guest_introduction || undefined,
    intent: api.guest_intent || undefined,
  };
}

function toRequest(api: ApiConnection): IncomingConnectionRequest {
  return {
    id: String(api.id),
    person: toConnectionPerson(api),
    meetingContext: api.guest_meeting_context || undefined,
    introduction: api.guest_introduction || undefined,
    intent: api.guest_intent || undefined,
    memberNote: api.member_note || undefined,
    matchReason: api.match_reason || undefined,
    requestType: api.request_type,
    requestedAt: api.created_at,
  };
}

async function fetchList(): Promise<ApiConnection[]> {
  const { connections } = await fetchApiConnections();
  return Array.isArray(connections) ? connections : connections.data;
}

/** Pending requests addressed to the current user (awaiting accept/decline). */
export async function getIncomingRequests(): Promise<
  IncomingConnectionRequest[]
> {
  const list = await fetchList();
  return list
    .filter((c) => c.status === "pending" && c.direction === "received")
    .map(toRequest);
}

/** Connection requests the current user has sent that are still pending. */
export async function getSentRequests(): Promise<IncomingConnectionRequest[]> {
  const list = await fetchList();
  return list
    .filter((c) => c.status === "pending" && c.direction === "sent")
    .map(toRequest);
}

/** Established (approved) connections for the current user, both directions. */
export async function getConnections(): Promise<Connection[]> {
  const list = await fetchList();
  return list
    .filter((c) => c.status === "approved")
    .map((c) => ({
      id: String(c.id),
      person: toConnectionPerson(c),
      connectedAt: c.updated_at,
      requestType: c.request_type,
    }));
}

/** Accepts a request — dispatches to the member or guest flow. */
export async function acceptRequest(
  requestId: string,
  requestType: "guest" | "member" = "guest"
): Promise<void> {
  if (requestType === "member") {
    await acceptMemberConnection(Number(requestId));
    return;
  }
  await updateConnection(Number(requestId), "approve");
}

/** Declines a request — dispatches to the member or guest flow. */
export async function declineRequest(
  requestId: string,
  requestType: "guest" | "member" = "guest"
): Promise<void> {
  if (requestType === "member") {
    await declineMemberConnection(Number(requestId));
    return;
  }
  await updateConnection(Number(requestId), "decline");
}

/** Withdraws a pending member-to-member request. */
export async function withdrawRequest(requestId: string): Promise<void> {
  await withdrawMemberConnection(Number(requestId));
}

/** Removes an accepted member-to-member connection. */
export async function removeConnection(connectionId: string): Promise<void> {
  await removeMemberConnection(Number(connectionId));
}

/**
 * Guest public profile submit — posts the request to the profile owner's
 * connection inbox via the backend.
 */
export async function addGuestConnectionRequest(
  slug: string,
  data: ConnectionRequestData
): Promise<void> {
  await createConnection({
    slug,
    guest_name: data.name,
    guest_email: data.email,
    guest_phone: data.phone || undefined,
    guest_org: data.organization || undefined,
    guest_meeting_context: data.meetingContext || undefined,
    guest_introduction: data.introduction || undefined,
    guest_intent: data.intent || undefined,
  });
}

/** Summary stats for dashboard cards. */
export async function getConnectionSummary(): Promise<ConnectionSummary> {
  const [requests, connections] = await Promise.all([
    getIncomingRequests(),
    getConnections(),
  ]);

  return {
    pendingCount: requests.length,
    totalConnections: connections.length,
    recentConnections: [...connections]
      .sort(
        (a, b) =>
          new Date(b.connectedAt).getTime() - new Date(a.connectedAt).getTime()
      )
      .slice(0, 3),
  };
}
