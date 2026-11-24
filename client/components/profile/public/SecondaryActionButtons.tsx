/**
 * SecondaryActionButtons
 *
 * Round poster-style actions beneath the primary Connect CTA: Save contact,
 * WhatsApp and optionally Schedule Meeting. Labels stay below the circular
 * controls for accessibility and mobile readability.
 */

"use client";

import { WhatsAppIcon } from "@/components/profile/public/BrandIcons";
import { Calendar, Download } from "lucide-react";
import type { ReactNode } from "react";

type SecondaryActionButtonsProps = {
  onSaveContact: () => void;
  /** Optional — omit to hide the WhatsApp action. */
  onWhatsApp?: () => void;
  /** Optional — omit to hide the Schedule Meeting action. */
  onScheduleMeeting?: () => void;
};

function RoundAction({
  label,
  onClick,
  children,
}: {
  label: string;
  onClick: () => void;
  children: ReactNode;
}) {
  return (
    <button
      type="button"
      onClick={onClick}
      className="group flex flex-col items-center gap-1.5 text-center"
    >
      <span className="flex h-14 w-14 items-center justify-center rounded-full border border-roicard-border bg-roicard-bg-elevated shadow-sm shadow-[var(--rc-shadow)] transition-all duration-300 group-hover:-translate-y-0.5 group-hover:border-roicard-accent/40 group-hover:shadow-md">
        {children}
      </span>
      <span className="text-[11px] font-medium text-roicard-text-muted">{label}</span>
    </button>
  );
}

export function SecondaryActionButtons({
  onSaveContact,
  onWhatsApp,
  onScheduleMeeting,
}: SecondaryActionButtonsProps) {
  return (
    <div className="flex items-start justify-center gap-8 py-1">
      <RoundAction label="Save contact" onClick={onSaveContact}>
        <Download className="h-5 w-5 text-roicard-accent" aria-hidden />
      </RoundAction>

      {onWhatsApp && (
        <RoundAction label="WhatsApp" onClick={onWhatsApp}>
          <WhatsAppIcon className="h-5 w-5 text-emerald-500" aria-hidden />
        </RoundAction>
      )}

      {onScheduleMeeting && (
        <RoundAction label="Meeting" onClick={onScheduleMeeting}>
          <Calendar className="h-5 w-5 text-roicard-primary" aria-hidden />
        </RoundAction>
      )}
    </div>
  );
}
