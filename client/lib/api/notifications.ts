import apiClient from "./client";

export interface Notification {
  id: string;
  type: string;
  data: {
    type?: string;
    title?: string;
    body?: string;
    connection_id?: number;
    card_id?: string;
    [key: string]: unknown;
  };
  read_at: string | null;
  created_at: string;
}

export async function getNotifications(): Promise<{
  notifications: Notification[];
  unread_count: number;
}> {
  const { data } = await apiClient.get("/notifications");
  return {
    notifications: data.notifications?.data ?? data.notifications ?? [],
    unread_count: data.unread_count ?? 0,
  };
}

export async function markNotificationRead(id: string): Promise<void> {
  await apiClient.patch(`/notifications/${id}/read`);
}

export type NotificationChannel = "database" | "mail" | "push";

export interface NotificationPreferenceType {
  type: string;
  label: string;
  description: string;
  mandatory: boolean;
  admin: boolean;
  channels: Partial<Record<NotificationChannel, boolean>>;
}

export interface NotificationPreferenceSettings {
  types: NotificationPreferenceType[];
  preferences: Record<string, Partial<Record<NotificationChannel, boolean>>>;
  daily_cap: number | null;
  sent_today: number;
}

export async function getNotificationPreferences(): Promise<NotificationPreferenceSettings> {
  const { data } = await apiClient.get("/notifications/preferences");
  return data;
}

export async function updateNotificationPreferences(
  preferences: Record<string, Partial<Record<NotificationChannel, boolean>>>
): Promise<NotificationPreferenceSettings["preferences"]> {
  const { data } = await apiClient.patch("/notifications/preferences", {
    preferences,
  });
  return data.preferences;
}

export async function registerPushToken(
  token: string,
  platform: "web" | "android" | "ios" = "web"
): Promise<void> {
  await apiClient.post("/notifications/push-tokens", { token, platform });
}

export async function removePushToken(token: string): Promise<void> {
  await apiClient.delete("/notifications/push-tokens", { data: { token } });
}
