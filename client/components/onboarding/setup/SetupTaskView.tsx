"use client";

import { Button } from "@/components/ui/Button";
import { getOnboardingStatus, updateOnboardingTask, type OnboardingStatus } from "@/lib/api/onboarding";
import { createProfileV2, getProfiles, type Profile } from "@/lib/api/profile";
import { useAuth } from "@/contexts/AuthContext";
import { cn } from "@/lib/cn";
import { ArrowLeft } from "lucide-react";
import Link from "next/link";
import { useParams, useRouter } from "next/navigation";
import { useCallback, useEffect, useMemo, useState } from "react";
import {
  BuildProfileForm,
  BusinessProfileForm,
  ContactForm,
  MatchForm,
  PhotoForm,
  PrivacyForm,
  PublishForm,
} from "@/components/onboarding/setup/SetupTaskForms";

const TASK_KEYS: Record<string, string> = {
  "build-profile": "build_profile",
  "add-photo": "add_photo",
  privacy: "set_privacy",
  publish: "publish",
  seeking: "seeking",
  offering: "offering",
  contact: "contact_options",
  business: "add_business_profile",
};

export function SetupTaskView() {
  const params = useParams<{ task: string }>();
  const router = useRouter();
  const { user } = useAuth();
  const taskKey = TASK_KEYS[params.task] ?? null;

  const [status, setStatus] = useState<OnboardingStatus | null>(null);
  const [profiles, setProfiles] = useState<Profile[]>([]);
  const [defaultProfileId, setDefaultProfileId] = useState<number | null>(null);
  const [isLoading, setIsLoading] = useState(true);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const load = useCallback(async () => {
    setIsLoading(true);
    setError(null);
    try {
      const [onboarding, profileResponse] = await Promise.all([
        getOnboardingStatus(),
        getProfiles(),
      ]);
      setStatus(onboarding);
      setProfiles(profileResponse.profiles);
      setDefaultProfileId(profileResponse.default_profile_id);
    } catch {
      setError("Unable to load this setup step.");
    } finally {
      setIsLoading(false);
    }
  }, []);

  useEffect(() => {
    if (!taskKey) {
      router.replace("/dashboard");
      return;
    }
    load();
  }, [load, router, taskKey]);

  const profile = useMemo(() => {
    if (profiles.length === 0) return null;
    return profiles.find((item) => item.id === defaultProfileId) ?? profiles[0];
  }, [defaultProfileId, profiles]);

  const task = useMemo(() => {
    if (!status || !taskKey) return null;
    return status.tasks.find((item) => item.key === taskKey) ?? null;
  }, [status, taskKey]);

  const complete = useCallback(
    async (nextStatus?: OnboardingStatus) => {
      if (!taskKey) return;
      setBusy(true);
      try {
        const updated = nextStatus ?? (await updateOnboardingTask(taskKey, "done"));
        setStatus(updated);
        router.push("/dashboard");
      } finally {
        setBusy(false);
      }
    },
    [router, taskKey]
  );

  const skip = useCallback(async () => {
    if (!taskKey) return;
    setBusy(true);
    try {
      await updateOnboardingTask(taskKey, "skipped");
      router.push("/dashboard");
    } finally {
      setBusy(false);
    }
  }, [router, taskKey]);

  const createFirstProfile = async () => {
    setBusy(true);
    setError(null);
    try {
      const response = await createProfileV2({
        profile_type: "personal",
        visibility: "public",
        is_searchable: true,
        is_contactable: true,
        contact_details_visibility: "connections",
      });
      setProfiles([response.profile]);
      setDefaultProfileId(response.profile.id);
    } catch (e) {
      setError(
        (e as { response?: { data?: { message?: string } } })?.response?.data?.message ??
          "Unable to create your profile."
      );
    } finally {
      setBusy(false);
    }
  };

  if (isLoading) {
    return (
      <div className="flex min-h-[40vh] items-center justify-center">
        <div className="h-8 w-8 animate-spin rounded-full border-2 border-roicard-border border-t-roicard-primary" />
      </div>
    );
  }

  if (!task || !status) {
    return (
      <div className="space-y-4">
        <p className="text-sm text-roicard-text-muted">This setup step is not available.</p>
        <Link href="/dashboard">
          <Button variant="secondary">Back to dashboard</Button>
        </Link>
      </div>
    );
  }

  if (!profile) {
    return (
      <div className="space-y-4 rounded-2xl border border-roicard-border bg-roicard-bg-elevated p-8 text-center">
        <h1 className="text-xl font-bold text-roicard-text">Create your first profile</h1>
        <p className="text-sm text-roicard-text-muted">
          Every Roicard account starts with one personal profile.
        </p>
        {error && <p className="text-sm text-rose-400">{error}</p>}
        <Button onClick={createFirstProfile} disabled={busy}>
          Create personal profile
        </Button>
      </div>
    );
  }

  const progress = status.progress;

  return (
    <div className="mx-auto max-w-3xl space-y-8">
      <div>
        <Link
          href="/dashboard"
          className="inline-flex items-center gap-1.5 text-sm font-medium text-roicard-text-muted hover:text-roicard-text"
        >
          <ArrowLeft className="h-4 w-4" />
          Back to setup
        </Link>
        <div className="mt-5 flex flex-wrap items-center gap-2">
          <span className="inline-flex rounded-full bg-roicard-primary/15 px-3 py-1 text-xs font-medium text-roicard-accent">
            {task.group_label}
          </span>
          {task.required ? (
            <span className="inline-flex rounded-full bg-amber-500/15 px-3 py-1 text-xs font-medium text-amber-400">
              Required
            </span>
          ) : (
            <span className="inline-flex rounded-full bg-roicard-bg-muted px-3 py-1 text-xs font-medium text-roicard-text-muted">
              Optional
            </span>
          )}
        </div>
        <h1 className="mt-3 text-2xl font-bold text-roicard-text sm:text-3xl">{task.title}</h1>
        <p className="mt-2 text-sm text-roicard-text-muted">{task.description}</p>
        <div className="mt-4 h-1.5 overflow-hidden rounded-full bg-roicard-bg-muted">
          <div
            className="h-full roicard-gradient"
            style={{ width: `${progress.percent}%` }}
          />
        </div>
      </div>

      {error && (
        <p className="rounded-lg border border-rose-500/30 bg-rose-500/10 px-3 py-2 text-sm text-rose-400">
          {error}
        </p>
      )}

      <div>
        {taskKey === "build_profile" && (
          <BuildProfileForm profile={profile} busy={busy} onSaved={complete} />
        )}
        {taskKey === "add_photo" && (
          <PhotoForm profile={profile} account={user} busy={busy} onSaved={complete} />
        )}
        {taskKey === "set_privacy" && (
          <PrivacyForm profile={profile} busy={busy} onSaved={complete} />
        )}
        {taskKey === "publish" && (
          <PublishForm
            profile={profile}
            account={user}
            status={status}
            busy={busy}
            onSaved={complete}
          />
        )}
        {(taskKey === "seeking" || taskKey === "offering") && (
          <MatchForm
            profile={profile}
            kind={taskKey}
            busy={busy}
            onSaved={complete}
          />
        )}
        {taskKey === "contact_options" && (
          <ContactForm profile={profile} busy={busy} onSaved={complete} />
        )}
        {taskKey === "add_business_profile" && (
          <BusinessProfileForm profile={profile} account={user} busy={busy} onSaved={complete} />
        )}
      </div>

      <div className="flex flex-wrap items-center justify-between gap-3 border-t border-roicard-border pt-5">
        <Link href="/dashboard">
          <Button variant="ghost">Save and return later</Button>
        </Link>
        {!task.required && (
          <Button
            variant="secondary"
            onClick={skip}
            disabled={busy}
            className={cn("rounded-xl")}
          >
            Skip for now
          </Button>
        )}
      </div>
    </div>
  );
}
