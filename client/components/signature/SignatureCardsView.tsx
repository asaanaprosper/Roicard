"use client";

import { ProfileSwitcher } from "@/components/network/ProfileSwitcher";
import { Button } from "@/components/ui/Button";
import { getProfiles, type Profile } from "@/lib/api/profile";
import {
  generateSignatureCard,
  getSignatureCards,
  updateSignatureCardSettings,
  type SignatureCardMetadata,
  type SignatureCardRecord,
} from "@/lib/api/signatureCards";
import { cn } from "@/lib/cn";
import { Download, Image, Share2, Sparkles } from "lucide-react";
import { useCallback, useEffect, useState } from "react";

export function SignatureCardsView() {
  const [profiles, setProfiles] = useState<Profile[]>([]);
  const [defaultProfileId, setDefaultProfileId] = useState<number | null>(null);
  const [selectedId, setSelectedId] = useState<number | null>(null);
  const [metadata, setMetadata] = useState<SignatureCardMetadata | null>(null);
  const [format, setFormat] = useState<SignatureCardRecord["format"]>("square");
  const [card, setCard] = useState<SignatureCardRecord | null>(null);
  const [isLoading, setIsLoading] = useState(true);
  const [isGenerating, setIsGenerating] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [status, setStatus] = useState<string | null>(null);

  useEffect(() => {
    Promise.all([getProfiles()])
      .then(([response]) => {
        setProfiles(response.profiles);
        setDefaultProfileId(response.default_profile_id);
        setSelectedId(response.default_profile_id ?? response.profiles[0]?.id ?? null);
      })
      .catch(() => {
        setError("Unable to load your profiles.");
        setIsLoading(false);
      });
  }, []);

  const load = useCallback(async (profileId: number) => {
    setIsLoading(true);
    setError(null);
    try {
      const response = await getSignatureCards(profileId);
      setMetadata(response);
      const existing = response.existing.find((item) => item.format === format);
      setCard(existing ?? null);
    } catch {
      setError("Unable to load signature card settings.");
    } finally {
      setIsLoading(false);
    }
  }, [format]);

  useEffect(() => {
    if (selectedId) void load(selectedId);
  }, [selectedId, load]);

  const generate = async () => {
    if (!selectedId) return;
    setIsGenerating(true);
    setError(null);
    try {
      const response = await generateSignatureCard(selectedId, format);
      setCard(response.card);
      setStatus(response.message);
      setMetadata((current) => current
        ? {
            ...current,
            existing: [
              ...current.existing.filter((item) => item.format !== response.card.format),
              response.card,
            ],
          }
        : current);
    } catch (e) {
      const data = (e as { response?: { data?: { message?: string } } })?.response?.data;
      setError(data?.message ?? "Unable to generate the signature card.");
    } finally {
      setIsGenerating(false);
    }
  };

  const download = async () => {
    if (!card) return;
    try {
      const response = await fetch(card.preview_url);
      if (!response.ok) throw new Error("download failed");
      const blob = await response.blob();
      const url = URL.createObjectURL(blob);
      const anchor = document.createElement("a");
      anchor.href = url;
      anchor.download = `roicard-${format}.png`;
      document.body.appendChild(anchor);
      anchor.click();
      anchor.remove();
      URL.revokeObjectURL(url);
      setStatus("Signature card downloaded.");
    } catch {
      setError("Unable to download the image. Generate it again and retry.");
    }
  };

  const share = async () => {
    if (!card) return;
    try {
      const response = await fetch(card.preview_url);
      const blob = await response.blob();
      const file = new File([blob], `roicard-${format}.png`, { type: "image/png" });
      if (navigator.canShare?.({ files: [file] })) {
        await navigator.share({ files: [file], title: "My ROICARD signature card" });
      } else {
        await navigator.clipboard.writeText(card.preview_url);
        setStatus("Image link copied. The link is short-lived.");
      }
    } catch {
      setError("Unable to share this image.");
    }
  };

  const toggleReferral = async (enabled: boolean) => {
    if (!selectedId || !metadata) return;
    try {
      const response = await updateSignatureCardSettings(selectedId, {
        referral_signature_enabled: enabled,
      });
      setStatus(response.message);
      setCard(null);
      await load(selectedId);
    } catch (e) {
      const data = (e as { response?: { data?: { message?: string } } })?.response?.data;
      setError(data?.message ?? "Unable to update the referral setting.");
    }
  };

  const selected = profiles.find((profile) => profile.id === selectedId) ?? null;

  return (
    <div className="space-y-8">
      <header className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div>
          <span className="inline-flex items-center gap-2 rounded-full bg-roicard-primary/15 px-3 py-1 text-xs font-medium text-roicard-accent">
            <Sparkles className="h-3.5 w-3.5" />
            Signature cards
          </span>
          <h1 className="mt-3 text-2xl font-bold text-roicard-text sm:text-3xl">
            Profile signature images
          </h1>
          <p className="mt-2 text-sm text-roicard-text-muted sm:text-base">
            Generate square, story and email-banner images with a QR code. Sharing
            these images is separate from sharing your profile link.
          </p>
        </div>
      </header>

      <ProfileSwitcher
        profiles={profiles}
        defaultProfileId={defaultProfileId}
        selectedId={selectedId}
        onChange={(id) => {
          setSelectedId(id);
          setCard(null);
          setStatus(null);
        }}
      />

      {status && <p className="rounded-lg border border-emerald-500/30 bg-emerald-500/10 px-3 py-2 text-sm text-emerald-400">{status}</p>}
      {error && <p className="rounded-lg border border-rose-500/30 bg-rose-500/10 px-3 py-2 text-sm text-rose-400">{error}</p>}

      {isLoading ? (
        <div className="flex min-h-[30vh] items-center justify-center">
          <div className="h-8 w-8 animate-spin rounded-full border-2 border-roicard-border border-t-roicard-primary" />
        </div>
      ) : !metadata ? (
        <p className="rounded-2xl border border-dashed border-roicard-border p-6 text-sm text-roicard-text-muted">
          Create a profile to generate a signature card.
        </p>
      ) : !metadata.enabled ? (
        <div className="rounded-2xl border border-dashed border-roicard-border bg-roicard-bg-elevated p-6">
          <h2 className="text-lg font-semibold text-roicard-text">Signature cards are not included</h2>
          <p className="mt-1 text-sm text-roicard-text-muted">
            Your current plan does not include a signature-card template.
          </p>
          <a href="/dashboard/plan" className="mt-3 inline-block text-sm font-medium text-roicard-accent hover:underline">
            View plans
          </a>
        </div>
      ) : (
        <div className="grid gap-6 lg:grid-cols-[minmax(0,1fr)_340px]">
          <div className="space-y-4">
            <div className="flex flex-wrap gap-2">
              {metadata.formats.map((option) => (
                <button
                  key={option.key}
                  type="button"
                  onClick={() => {
                    setFormat(option.key);
                    setCard(metadata.existing.find((item) => item.format === option.key) ?? null);
                  }}
                  className={cn(
                    "rounded-xl border px-4 py-2 text-left text-sm",
                    format === option.key
                      ? "border-roicard-primary/50 bg-roicard-primary/10 text-roicard-text"
                      : "border-roicard-border bg-roicard-bg-elevated text-roicard-text-muted hover:text-roicard-text"
                  )}
                >
                  <span className="block font-medium">{option.label}</span>
                  <span className="text-[11px] text-roicard-text-muted">
                    {option.width} × {option.height}
                  </span>
                </button>
              ))}
            </div>

            <div className="rounded-2xl border border-roicard-border bg-roicard-bg-elevated p-4">
              {card ? (
                <div className="space-y-3">
                  {/* eslint-disable-next-line @next/next/no-img-element */}
                  <img
                    src={card.preview_url}
                    alt={card.alt_text}
                    className="mx-auto max-h-[65vh] w-auto rounded-xl border border-roicard-border bg-black object-contain"
                  />
                  <p className="text-xs text-roicard-text-muted">{card.alt_text}</p>
                </div>
              ) : (
                <div className="flex min-h-[260px] flex-col items-center justify-center gap-3 rounded-xl border border-dashed border-roicard-border text-center">
                  <Image className="h-8 w-8 text-roicard-text-muted" />
                  <p className="max-w-sm text-sm text-roicard-text-muted">
                    Generate the {format.replace("_", " ")} card for {selected?.display_name || selected?.title || "this profile"}.
                  </p>
                  <Button onClick={generate} isLoading={isGenerating} className="rounded-xl">
                    Generate preview
                  </Button>
                </div>
              )}
            </div>

            {card && (
              <div className="flex flex-wrap gap-2">
                <Button variant="secondary" className="rounded-xl" onClick={download}>
                  <Download className="h-4 w-4" />
                  Download PNG
                </Button>
                <Button variant="secondary" className="rounded-xl" onClick={share}>
                  <Share2 className="h-4 w-4" />
                  Share image
                </Button>
                <Button variant="ghost" className="rounded-xl" onClick={generate} isLoading={isGenerating}>
                  Regenerate
                </Button>
              </div>
            )}
          </div>

          <aside className="space-y-4">
            <div className="rounded-2xl border border-roicard-border bg-roicard-bg-elevated p-4">
              <p className="text-sm font-semibold text-roicard-text">QR attribution</p>
              <label className="mt-3 flex items-start gap-2 text-sm text-roicard-text">
                <input
                  type="checkbox"
                  checked={metadata.settings.referral_signature_enabled}
                  onChange={(event) => void toggleReferral(event.target.checked)}
                  className="mt-0.5 h-4 w-4 rounded border-roicard-border"
                />
                <span>
                  Include my referral code in signature-card QR scans
                  <span className="mt-1 block text-xs text-roicard-text-muted">
                    Off by default. Only applies to signature images; profile links are unchanged.
                  </span>
                </span>
              </label>
            </div>

            <div className="rounded-2xl border border-roicard-border bg-roicard-bg-elevated p-4 text-xs leading-relaxed text-roicard-text-muted">
              Images are generated server-side and cached for a few minutes. Profile,
              logo, contact-visibility and referral-setting changes trigger
              regeneration. Phone and email appear only when your public contact
              settings permit them.
            </div>
          </aside>
        </div>
      )}
    </div>
  );
}
