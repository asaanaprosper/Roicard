/**
 * ReportMemberModal
 *
 * Captures a report category and optional description. The backend stores the
 * report privately for administrator review; reporters never see moderation
 * details.
 */

"use client";

import { Button } from "@/components/ui/Button";
import { Modal } from "@/components/ui/Modal";
import { Textarea } from "@/components/ui/Textarea";
import { REPORT_REASONS, reportMember, type ReportReason } from "@/lib/api/safety";
import { useEffect, useState } from "react";

type ReportMemberModalProps = {
  isOpen: boolean;
  onClose: () => void;
  memberName: string;
  profileId?: number;
  connectionId?: number;
  onReported?: (message: string) => void;
};

export function ReportMemberModal({
  isOpen,
  onClose,
  memberName,
  profileId,
  connectionId,
  onReported,
}: ReportMemberModalProps) {
  const [reason, setReason] = useState<ReportReason>("spam");
  const [description, setDescription] = useState("");
  const [isSending, setIsSending] = useState(false);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    if (!isOpen) {
      setReason("spam");
      setDescription("");
      setError(null);
      setIsSending(false);
    }
  }, [isOpen]);

  const handleSubmit = async () => {
    setIsSending(true);
    setError(null);
    try {
      const response = await reportMember({
        profileId,
        connectionId,
        reasonCategory: reason,
        description: description.trim() || undefined,
      });
      onReported?.(response.message || "Report submitted for review.");
      onClose();
    } catch (e) {
      const data = (e as { response?: { data?: { message?: string } } })?.response?.data;
      setError(data?.message ?? "Unable to submit the report.");
    } finally {
      setIsSending(false);
    }
  };

  return (
    <Modal
      isOpen={isOpen}
      onClose={onClose}
      title={`Report ${memberName}`}
      description="Reports are private and reviewed by platform administrators."
      footer={
        <>
          <Button variant="secondary" onClick={onClose} disabled={isSending}>
            Cancel
          </Button>
          <Button variant="danger" onClick={handleSubmit} isLoading={isSending}>
            Submit report
          </Button>
        </>
      }
    >
      <div className="space-y-4">
        <div>
          <label
            htmlFor="report-reason"
            className="mb-2 block text-sm font-medium text-roicard-text"
          >
            Reason
          </label>
          <select
            id="report-reason"
            value={reason}
            onChange={(event) => setReason(event.target.value as ReportReason)}
            className="h-11 w-full rounded-lg border border-roicard-border bg-roicard-bg-muted px-3 text-sm text-roicard-text"
          >
            {REPORT_REASONS.map((option) => (
              <option key={option.value} value={option.value}>
                {option.label}
              </option>
            ))}
          </select>
        </div>

        <Textarea
          label="What happened? (optional)"
          rows={4}
          maxLength={2000}
          value={description}
          onChange={(event) => setDescription(event.target.value)}
          hint={`${description.length}/2000`}
        />

        {error && (
          <p className="rounded-lg border border-rose-500/30 bg-rose-500/10 px-3 py-2 text-sm text-rose-400">
            {error}
          </p>
        )}
      </div>
    </Modal>
  );
}
