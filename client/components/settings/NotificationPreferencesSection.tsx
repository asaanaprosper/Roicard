"use client";

import { SettingsSection } from "@/components/settings/SettingsSection";
import {
  getNotificationPreferences,
  updateNotificationPreferences,
  type NotificationChannel,
  type NotificationPreferenceSettings,
} from "@/lib/api/notifications";
import { useEffect, useState } from "react";

const CHANNEL_LABELS: Record<NotificationChannel, string> = {
  database: "In-app",
  mail: "Email",
  push: "Push",
};

export function NotificationPreferencesSection() {
  const [settings, setSettings] = useState<NotificationPreferenceSettings | null>(null);
  const [preferences, setPreferences] = useState<NotificationPreferenceSettings["preferences"]>({});
  const [isLoading, setIsLoading] = useState(true);
  const [isSaving, setIsSaving] = useState(false);
  const [message, setMessage] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    let cancelled = false;

    getNotificationPreferences()
      .then((response) => {
        if (!cancelled) {
          setSettings(response);
          setPreferences(response.preferences);
        }
      })
      .catch(() => {
        if (!cancelled) setError("Unable to load notification preferences.");
      })
      .finally(() => {
        if (!cancelled) setIsLoading(false);
      });

    return () => {
      cancelled = true;
    };
  }, []);

  const toggle = async (type: string, channel: NotificationChannel, enabled: boolean) => {
    const previous = preferences;
    const next = {
      ...preferences,
      [type]: { ...(preferences[type] ?? {}), [channel]: enabled },
    };

    setPreferences(next);
    setIsSaving(true);
    setMessage(null);
    setError(null);

    try {
      const saved = await updateNotificationPreferences(next);
      setPreferences(saved);
      setMessage("Notification preferences saved.");
    } catch {
      setPreferences(previous);
      setError("Unable to save notification preferences.");
    } finally {
      setIsSaving(false);
    }
  };

  return (
    <SettingsSection
      title="Notifications"
      description="Choose which Appendix C notifications you receive and how. Security-sensitive account messages are not affected."
    >
      {isLoading ? (
        <p className="text-sm text-roicard-text-muted">Loading notification preferences…</p>
      ) : !settings ? (
        <p className="text-sm text-red-400">{error ?? "Notification preferences are unavailable."}</p>
      ) : (
        <div className="space-y-4">
          {settings.types.map((type) => (
            <div
              key={type.type}
              className="rounded-xl border border-roicard-border bg-roicard-bg-muted/30 p-4"
            >
              <div className="flex flex-wrap items-start justify-between gap-3">
                <div>
                  <p className="text-sm font-medium text-roicard-text">{type.label}</p>
                  <p className="mt-0.5 text-xs text-roicard-text-muted">{type.description}</p>
                </div>
                {type.mandatory && (
                  <span className="rounded-full border border-roicard-border px-2 py-0.5 text-[10px] uppercase tracking-wide text-roicard-text-muted">
                    Important
                  </span>
                )}
              </div>

              <div className="mt-3 flex flex-wrap gap-4">
                {(Object.keys(type.channels) as NotificationChannel[]).map((channel) => (
                  <label key={channel} className="inline-flex items-center gap-2 text-sm text-roicard-text">
                    <input
                      type="checkbox"
                      checked={preferences[type.type]?.[channel] ?? true}
                      disabled={isSaving}
                      onChange={(event) => void toggle(type.type, channel, event.target.checked)}
                      className="h-4 w-4 rounded border-roicard-border"
                    />
                    {CHANNEL_LABELS[channel]}
                  </label>
                ))}
              </div>
            </div>
          ))}

          {settings.daily_cap !== null && (
            <p className="text-xs text-roicard-text-muted">
              Daily non-important notification cap: {settings.daily_cap}. Sent today: {settings.sent_today}.
            </p>
          )}

          {message && <p className="text-sm text-emerald-400" role="status">{message}</p>}
          {error && <p className="text-sm text-red-400" role="alert">{error}</p>}
        </div>
      )}
    </SettingsSection>
  );
}
