"use client";

import type { AiStatus } from "@/lib/api/ai";
import { AlertTriangle, Loader2, ShieldCheck, Sparkles } from "lucide-react";
import Link from "next/link";
import { useEffect, useState } from "react";

type Props = {
  status: AiStatus | null;
  loading?: boolean;
  error?: string | null;
  onAccept: () => Promise<unknown> | unknown;
  className?: string;
};

export function AiConsentNotice({ status, loading, error, onAccept, className }: Props) {
  const [accepted, setAccepted] = useState(false);
  const [busy, setBusy] = useState(false);

  useEffect(() => {
    setAccepted(false);
  }, [status?.consent_version]);

  if (loading && !status) {
    return (
      <div className={className}>
        <p className="flex items-center gap-2 text-xs text-roicard-text-muted">
          <Loader2 className="h-3.5 w-3.5 animate-spin" />
          Loading AI settings…
        </p>
      </div>
    );
  }

  if (error) {
    return (
      <p className={`rounded-lg border border-rose-500/30 bg-rose-500/10 px-3 py-2 text-xs text-rose-400 ${className ?? ""}`}>
        {error}
      </p>
    );
  }

  if (!status) return null;

  if (!status.configured) {
    return (
      <div
        className={`rounded-xl border border-amber-500/30 bg-amber-500/10 p-3 text-xs text-amber-200 ${className ?? ""}`}
      >
        <p className="flex items-center gap-2 font-medium">
          <AlertTriangle className="h-4 w-4" />
          AI drafting is not configured
        </p>
        <p className="mt-1 text-amber-100/80">
          {status.message ?? "You can continue manually, paste your text or upload a CV."}
        </p>
      </div>
    );
  }

  if (status.consent_required) {
    return (
      <div className={`rounded-xl border border-roicard-border bg-roicard-bg-muted/40 p-4 ${className ?? ""}`}>
        <p className="flex items-center gap-2 text-sm font-semibold text-roicard-text">
          <ShieldCheck className="h-4 w-4 text-roicard-accent" />
          AI data-processing notice
        </p>
        <p className="mt-2 text-xs leading-relaxed text-roicard-text-muted">{status.consent_text}</p>
        <label className="mt-3 flex cursor-pointer items-start gap-2 text-xs text-roicard-text">
          <input
            type="checkbox"
            checked={accepted}
            onChange={(event) => setAccepted(event.target.checked)}
            className="mt-0.5 h-4 w-4 rounded border-roicard-border"
          />
          <span>I have read and agree to this AI data-processing notice.</span>
        </label>
        <button
          type="button"
          disabled={!accepted || busy}
          onClick={async () => {
            setBusy(true);
            try {
              await onAccept();
            } catch {
              // The parent hook exposes the error next to the notice.
            } finally {
              setBusy(false);
            }
          }}
          className="mt-3 inline-flex items-center gap-2 rounded-lg bg-roicard-primary px-3 py-2 text-sm font-medium text-white disabled:cursor-not-allowed disabled:opacity-50"
        >
          {busy ? <Loader2 className="h-4 w-4 animate-spin" /> : <ShieldCheck className="h-4 w-4" />}
          Agree and enable AI
        </button>
      </div>
    );
  }

  if (status.remaining_today !== null && status.remaining_today <= 0) {
    return (
      <div className={`rounded-xl border border-amber-500/30 bg-amber-500/10 p-3 text-xs text-amber-200 ${className ?? ""}`}>
        <p className="font-medium">Daily AI limit reached</p>
        <p className="mt-1 text-amber-100/80">
          You can continue manually or try again tomorrow.{" "}
          <Link href="/dashboard/plan" className="underline">
            View plans
          </Link>
        </p>
      </div>
    );
  }

  return (
    <p className={`flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-roicard-text-muted ${className ?? ""}`}>
      <span className="inline-flex items-center gap-1.5">
        <Sparkles className="h-3.5 w-3.5 text-roicard-accent" />
        AI ready
      </span>
      {status.remaining_today !== null && (
        <span>
          {status.remaining_today} AI call{status.remaining_today === 1 ? "" : "s"} left today
        </span>
      )}
      {status.cost_alert_reached && (
        <span className="text-amber-300">Monthly AI budget alert reached.</span>
      )}
    </p>
  );
}
