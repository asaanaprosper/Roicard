"use client";

import { Button } from "@/components/ui/Button";
import { Input } from "@/components/ui/Input";
import { Modal } from "@/components/ui/Modal";
import { Textarea } from "@/components/ui/Textarea";
import { submitCatalogEnquiry } from "@/lib/api/catalog";
import type { PublicCatalogItem } from "@/lib/profile/types";
import { CheckCircle2 } from "lucide-react";
import { useEffect, useState } from "react";

type CatalogEnquiryModalProps = {
  isOpen: boolean;
  onClose: () => void;
  item: PublicCatalogItem | null;
  businessName: string;
  defaultName?: string;
  defaultEmail?: string;
};

export function CatalogEnquiryModal({
  isOpen,
  onClose,
  item,
  businessName,
  defaultName = "",
  defaultEmail = "",
}: CatalogEnquiryModalProps) {
  const [name, setName] = useState(defaultName);
  const [email, setEmail] = useState(defaultEmail);
  const [phone, setPhone] = useState("");
  const [message, setMessage] = useState("");
  const [isSending, setIsSending] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [success, setSuccess] = useState(false);

  useEffect(() => {
    if (!isOpen) {
      setPhone("");
      setMessage("");
      setError(null);
      setSuccess(false);
      setIsSending(false);
    } else {
      setName(defaultName);
      setEmail(defaultEmail);
    }
  }, [isOpen, defaultName, defaultEmail]);

  if (!item) return null;

  const submit = async () => {
    setIsSending(true);
    setError(null);
    try {
      await submitCatalogEnquiry(item.id, {
        name: name.trim(),
        email: email.trim(),
        phone: phone.trim() || undefined,
        message: message.trim(),
      });
      setSuccess(true);
    } catch (e) {
      const data = (e as { response?: { data?: { message?: string; errors?: Record<string, string[]> } } })
        ?.response?.data;
      setError(
        data?.errors?.message?.[0] ??
          data?.errors?.email?.[0] ??
          data?.message ??
          "Unable to send your enquiry."
      );
    } finally {
      setIsSending(false);
    }
  };

  return (
    <Modal
      isOpen={isOpen}
      onClose={onClose}
      title={success ? "Enquiry sent" : `Enquire about ${item.title}`}
      description={
        success
          ? `${businessName} received your enquiry and can reply using the contact details you provided.`
          : `Send a message to ${businessName}. Your contact details are shared only with this business.`
      }
      footer={
        success ? (
          <Button onClick={onClose}>Done</Button>
        ) : (
          <>
            <Button variant="secondary" onClick={onClose} disabled={isSending}>
              Cancel
            </Button>
            <Button onClick={submit} isLoading={isSending}>
              Send enquiry
            </Button>
          </>
        )
      }
    >
      {success ? (
        <div className="flex flex-col items-center gap-3 py-6 text-center">
          <CheckCircle2 className="h-10 w-10 text-emerald-400" />
          <p className="text-sm text-roicard-text-muted">
            The business has been notified. No private owner contact details are
            exposed here.
          </p>
        </div>
      ) : (
        <div className="space-y-4">
          <div className="grid gap-4 sm:grid-cols-2">
            <Input
              label="Your name"
              value={name}
              maxLength={120}
              onChange={(event) => setName(event.target.value)}
              required
            />
            <Input
              label="Email"
              type="email"
              value={email}
              maxLength={255}
              onChange={(event) => setEmail(event.target.value)}
              required
            />
          </div>
          <Input
            label="Phone (optional)"
            value={phone}
            maxLength={40}
            onChange={(event) => setPhone(event.target.value)}
          />
          <Textarea
            label="Message"
            rows={5}
            maxLength={3000}
            value={message}
            onChange={(event) => setMessage(event.target.value)}
            hint={`${message.length}/3000`}
            required
          />
          {error && (
            <p className="rounded-lg border border-rose-500/30 bg-rose-500/10 px-3 py-2 text-sm text-rose-400">
              {error}
            </p>
          )}
        </div>
      )}
    </Modal>
  );
}
