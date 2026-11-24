/**
 * ConnectModal
 *
 * Explicit confirmation step for member-to-member connection requests.
 * Manual note entry only — no AI provider is integrated.
 */

"use client";

import { Button } from "@/components/ui/Button";
import { Modal } from "@/components/ui/Modal";
import { Textarea } from "@/components/ui/Textarea";
import { sendMemberConnectionRequest } from "@/lib/api/connections";
import { fullName, type NetworkProfile } from "@/lib/api/network";
import { Sparkles } from "lucide-react";
import { useEffect, useState } from "react";

type ConnectModalProps = {
  isOpen: boolean;
  onClose: () => void;
  profile: NetworkProfile | null;
  senderProfileId?: number;
  matchReason?: string | null;
  reasonCodes?: string[];
  matchScore?: number;
  onSent?: (message: string) => void;
};

function errorMessage(error: unknown): string {
  const data = (
    error as { response?: { data?: { message?: string; errors?: Record<string, string[]> } } }
  )?.response?.data;
  if (data?.errors) {
    const first = Object.values(data.errors)[0];
    if (first?.[0]) return first[0];
  }
  return data?.message ?? "Unable to send the connection request.";
}

export function ConnectModal({
  isOpen,
  onClose,
  profile,
  senderProfileId,
  matchReason,
  reasonCodes = [],
  matchScore,
  onSent,
}: ConnectModalProps) {
  const [note, setNote] = useState("");
  const [isSending, setIsSending] = useState(false);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    if (!isOpen) {
      setNote("");
      setError(null);
      setIsSending(false);
    }
  }, [isOpen]);

  if (!profile) return null;

  const name = fullName(profile);

  const handleSend = async () => {
    setIsSending(true);
    setError(null);
    try {
      const response = await sendMemberConnectionRequest({
        profile_id: profile.id,
        sender_profile_id: senderProfileId,
        note: note.trim() || undefined,
        match_reason: matchReason || undefined,
        reason_codes: reasonCodes.length > 0 ? reasonCodes : undefined,
        match_score: matchScore,
      });
      onSent?.(response.message || "Connection request sent.");
      onClose();
    } catch (e) {
      setError(errorMessage(e));
    } finally {
      setIsSending(false);
    }
  };

  return (
    <Modal
      isOpen={isOpen}
      onClose={onClose}
      title={`Connect with ${name}`}
      description="They will receive your request and can accept, decline, or block it."
      footer={
        <>
          <Button variant="secondary" onClick={onClose} disabled={isSending}>
            Cancel
          </Button>
          <Button onClick={handleSend} isLoading={isSending}>
            Send request
          </Button>
        </>
      }
    >
      <div className="space-y-4">
        {matchReason && (
          <div className="rounded-xl border border-roicard-primary/25 bg-roicard-primary/5 p-3">
            <p className="inline-flex items-center gap-1.5 text-xs font-semibold uppercase tracking-wide text-roicard-accent">
              <Sparkles className="h-3.5 w-3.5" aria-hidden />
              Match reason
            </p>
            <p className="mt-1.5 text-sm leading-relaxed text-roicard-text">{matchReason}</p>
          </div>
        )}

        <Textarea
          label="Connection note (optional)"
          rows={4}
          maxLength={1000}
          placeholder="Introduce yourself and explain why you would like to connect."
          value={note}
          onChange={(event) => setNote(event.target.value)}
          hint={`${note.length}/1000`}
        />

        {error && (
          <p className="rounded-lg border border-rose-500/30 bg-rose-500/10 px-3 py-2 text-sm text-rose-400">
            {error}
          </p>
        )}

        <p className="text-xs leading-relaxed text-roicard-text-muted">
          Your name and profile headline are shared with this member. Contact
          details are only unlocked according to each profile&apos;s privacy
          settings after they accept.
        </p>
      </div>
    </Modal>
  );
}
