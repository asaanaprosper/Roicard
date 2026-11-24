/**
 * PublicProfileCardStack
 *
 * Shared card stack for the public /[username] profile and owner previews
 * (onboarding review, dashboard hub). Renders ProfileHero, an optional
 * actions slot for visitor CTAs (Connect, Add Contact, WhatsApp), then the
 * detail cards. Omit `actions` when the member is previewing their own card.
 *
 * Layout is always a single vertical column — mobile-first, centered on larger
 * screens via the parent container (typically max-w-[480px]).
 */

import { GuestInviteCard } from "@/components/profile/public/GuestInviteCard";
import { BioText } from "@/components/profile/public/BioText";
import { InfoCard } from "@/components/profile/public/InfoCard";
import { InterestsCard } from "@/components/profile/public/InterestsCard";
import { OpportunitiesCard } from "@/components/profile/public/OpportunitiesCard";
import { OrganizationsCard } from "@/components/profile/public/OrganizationsCard";
import { ProfileHero } from "@/components/profile/public/ProfileHero";
import { SocialLinksCard } from "@/components/profile/public/SocialLinksCard";
import { WorkCard } from "@/components/profile/public/WorkCard";
import { cn } from "@/lib/cn";
import type { UserProfile } from "@/lib/profile/types";
import { Sparkles, User } from "lucide-react";
import { ReactNode } from "react";

type PublicProfileCardStackProps = {
  profile: UserProfile;
  /** Connect + secondary buttons — rendered between hero and detail cards. */
  actions?: ReactNode;
  /** Optional business catalog block rendered with the profile cards. */
  catalog?: ReactNode;
  /** Show the guest invitation card at the bottom (public profile only). */
  showGuestInvite?: boolean;
  className?: string;
};

export function PublicProfileCardStack({
  profile,
  actions,
  catalog,
  showGuestInvite = false,
  className,
}: PublicProfileCardStackProps) {
  return (
    <div className={cn("space-y-4", className)}>
      <ProfileHero profile={profile} />

      {actions}

      {catalog}

      {profile.bio.trim() && (
        <InfoCard icon={User} title="Professional Summary" collapsible defaultOpen>
          <BioText bio={profile.bio} />
        </InfoCard>
      )}

      {profile.organization && (
        <WorkCard
          organization={profile.organization}
          title={profile.professionalTitle || "—"}
          description={profile.roleDescription}
        />
      )}

      {(profile.skills?.length || profile.services?.length) && (
        <InfoCard icon={Sparkles} title="Skills & services" collapsible defaultOpen>
          <div className="space-y-4">
            {!!profile.skills?.length && (
              <div>
                <p className="text-[11px] font-semibold uppercase tracking-wider text-roicard-accent">Skills</p>
                <div className="mt-2 flex flex-wrap gap-2">
                  {profile.skills.map((skill) => (
                    <span key={skill} className="rounded-full border border-roicard-border bg-roicard-bg-muted px-3 py-1 text-xs text-roicard-text">
                      {skill}
                    </span>
                  ))}
                </div>
              </div>
            )}
            {!!profile.services?.length && (
              <div>
                <p className="text-[11px] font-semibold uppercase tracking-wider text-roicard-accent">Services</p>
                <div className="mt-2 flex flex-wrap gap-2">
                  {profile.services.map((service) => (
                    <span key={service} className="rounded-full border border-roicard-border bg-roicard-bg-muted px-3 py-1 text-xs text-roicard-text">
                      {service}
                    </span>
                  ))}
                </div>
              </div>
            )}
          </div>
        </InfoCard>
      )}

      <OpportunitiesCard
        variant="seeking"
        content={profile.seeking}
        items={profile.seekingItems}
      />
      <OpportunitiesCard
        variant="offering"
        content={profile.offering}
        items={profile.offeringItems}
      />

      <OrganizationsCard organizations={profile.organizations ?? []} />

      <InterestsCard interests={profile.interests} />

      <SocialLinksCard
        social={profile.social}
        email={profile.email}
        phone={profile.phone}
      />

      {showGuestInvite && <GuestInviteCard name={profile.firstName} />}
    </div>
  );
}
