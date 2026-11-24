/**
 * OpportunitiesCard
 *
 * Displays a Seeking or Offering block: the original free-text summary plus
 * any structured, active items with their categories. Hidden when empty.
 */

import { ProfileCard } from "@/components/profile/public/ProfileCard";
import type { OpportunityItem } from "@/lib/profile/types";
import { HandHeart, Search, type LucideIcon } from "lucide-react";

type OpportunitiesVariant = "seeking" | "offering";

type OpportunitiesCardProps = {
  variant: OpportunitiesVariant;
  content: string;
  items?: OpportunityItem[];
};

const VARIANT_CONFIG: Record<
  OpportunitiesVariant,
  { title: string; icon: LucideIcon }
> = {
  seeking: { title: "Seeking", icon: Search },
  offering: { title: "Offering", icon: HandHeart },
};

export function OpportunitiesCard({
  variant,
  content,
  items = [],
}: OpportunitiesCardProps) {
  if (!content.trim() && items.length === 0) return null;

  const { title, icon: Icon } = VARIANT_CONFIG[variant];

  return (
    <ProfileCard>
      <div className="border-b border-roicard-border/60 px-5 pb-3 pt-4">
        <span className="flex items-center gap-2.5">
          <span className="flex h-8 w-8 items-center justify-center rounded-xl bg-roicard-primary/10 text-roicard-accent">
            <Icon className="h-4 w-4" aria-hidden />
          </span>
          <span className="text-sm font-semibold text-roicard-text">
            {title}
          </span>
        </span>
      </div>

      <div className="space-y-3 px-5 pb-5 pt-4">
        {content.trim() && (
          <p className="whitespace-pre-line text-justify text-sm leading-relaxed text-roicard-text-muted">
            {content}
          </p>
        )}

        {items.map((item) => (
          <div
            key={item.id}
            className="rounded-xl border border-roicard-border/70 bg-roicard-bg-muted/30 px-3.5 py-3"
          >
            <p className="text-sm leading-relaxed text-roicard-text">
              {item.description}
            </p>
            {item.categories.length > 0 && (
              <div className="mt-1.5 flex flex-wrap gap-1.5">
                {item.categories.map((category) => (
                  <span
                    key={category.id}
                    className="rounded-full bg-roicard-bg-muted px-2 py-0.5 text-[11px] text-roicard-text-muted"
                  >
                    {category.name}
                  </span>
                ))}
              </div>
            )}
          </div>
        ))}
      </div>
    </ProfileCard>
  );
}
