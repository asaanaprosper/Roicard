"use client";

import { cn } from "@/lib/cn";
import type { Profile } from "@/lib/api/profile";
import { Star } from "lucide-react";

type ProfileSwitcherProps = {
  profiles: Profile[];
  defaultProfileId?: number | null;
  selectedId: number | null;
  onChange: (id: number) => void;
  label?: string;
};

/** Shared personal/business profile switcher used by Discover and Signals. */
export function ProfileSwitcher({
  profiles,
  defaultProfileId,
  selectedId,
  onChange,
  label = "Working as",
}: ProfileSwitcherProps) {
  if (profiles.length <= 1) return null;

  return (
    <div>
      <p className="mb-2 text-xs font-semibold uppercase tracking-wider text-roicard-text-muted">
        {label}
      </p>
      <div className="flex flex-wrap gap-2">
        {profiles.map((profile) => {
          const isSelected = profile.id === selectedId;
          return (
            <button
              key={profile.id}
              type="button"
              onClick={() => onChange(profile.id)}
              className={cn(
                "flex items-center gap-2 rounded-xl border px-3 py-2 text-left text-sm transition-colors",
                isSelected
                  ? "border-roicard-primary/40 bg-roicard-primary/10 text-roicard-text"
                  : "border-roicard-border bg-roicard-bg-elevated text-roicard-text-muted hover:text-roicard-text"
              )}
            >
              <span className="flex h-7 w-7 items-center justify-center rounded-full bg-roicard-bg-muted text-[11px] font-bold text-roicard-accent">
                {(profile.display_name || profile.organisation || profile.title || "P")
                  .slice(0, 1)
                  .toUpperCase()}
              </span>
              <span>
                <span className="block font-medium">
                  {profile.display_name || profile.organisation || profile.title || "Profile"}
                </span>
                <span className="block text-[11px] capitalize text-roicard-text-muted">
                  {profile.profile_type}
                  {profile.id === defaultProfileId ? " · default" : ""}
                </span>
              </span>
              {profile.id === defaultProfileId && (
                <Star className="h-3 w-3 text-amber-400" aria-hidden />
              )}
            </button>
          );
        })}
      </div>
    </div>
  );
}
