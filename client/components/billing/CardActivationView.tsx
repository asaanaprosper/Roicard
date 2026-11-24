"use client";

import { Button } from "@/components/ui/Button";
import { Input } from "@/components/ui/Input";
import { useConfirm } from "@/components/ui/ConfirmProvider";
import {
  activateCard,
  deactivateCard,
  getCards,
  type MemberCard,
} from "@/lib/api/cards";
import { getProfiles, type Profile } from "@/lib/api/profile";
import { confirmMockPayment } from "@/lib/api/payments";
import { cn } from "@/lib/cn";
import { CreditCard, Radio, ShieldCheck } from "lucide-react";
import { useCallback, useEffect, useState } from "react";

export function CardActivationView() {
  const { confirm } = useConfirm();
  const [cards, setCards] = useState<MemberCard[]>([]);
  const [profiles, setProfiles] = useState<Profile[]>([]);
  const [profileId, setProfileId] = useState("");
  const [cardId, setCardId] = useState("");
  const [fee, setFee] = useState(75);
  const [currency, setCurrency] = useState("GHS");
  const [isLoading, setIsLoading] = useState(true);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [status, setStatus] = useState<string | null>(null);

  const load = useCallback(async () => {
    try {
      const [cardResponse, profileResponse] = await Promise.all([getCards(), getProfiles()]);
      setCards(cardResponse.cards);
      setProfiles(profileResponse.profiles);
      setFee(cardResponse.activation_fee);
      setCurrency(cardResponse.currency);
      setProfileId((current) => current || String(profileResponse.default_profile_id ?? profileResponse.profiles[0]?.id ?? ""));
    } catch {
      setError("Unable to load card status.");
    } finally {
      setIsLoading(false);
    }
  }, []);

  useEffect(() => {
    void load();
  }, [load]);

  const activate = async () => {
    if (!profileId || !cardId.trim()) {
      setError("Choose a profile and enter the card ID.");
      return;
    }

    const confirmed = await confirm({
      title: "Activate this physical card?",
      description: `${currency} ${fee.toFixed(2)} will be charged through the configured provider. The card is only bound after the server verifies payment.`,
      confirmLabel: "Continue to payment",
    });
    if (!confirmed) return;

    setBusy(true);
    setError(null);
    setStatus(null);
    try {
      const response = await activateCard({
        profile_id: Number(profileId),
        card_id: cardId.trim(),
      });
      const redirect = (response.redirect ?? {}) as {
        mock?: boolean;
        authorization_url?: string;
        checkout_url?: string;
        status?: string;
      };

      if (redirect.status === "already_active") {
        setStatus("This card is already active on the selected profile.");
        await load();
        return;
      }

      if (redirect.authorization_url || redirect.checkout_url) {
        window.location.assign(redirect.authorization_url ?? redirect.checkout_url ?? "/dashboard/card");
        return;
      }

      if (redirect.mock && response.payment?.provider_reference) {
        await confirmMockPayment(response.payment.provider_reference);
        setStatus("Payment confirmed by the server. Card activated.");
        setCardId("");
        await load();
        return;
      }

      setStatus("Payment started. Your card activates after provider confirmation.");
      await load();
    } catch (e) {
      const data = (e as { response?: { data?: { message?: string; errors?: Record<string, string[]> } } })?.response?.data;
      setError(Object.values(data?.errors ?? {})[0]?.[0] ?? data?.message ?? "Unable to activate the card.");
    } finally {
      setBusy(false);
    }
  };

  const deactivate = async (card: MemberCard) => {
    const confirmed = await confirm({
      title: "Deactivate this card?",
      description: "The card will be unbound from your profile and stop resolving to it.",
      confirmLabel: "Deactivate card",
      variant: "danger",
    });
    if (!confirmed) return;

    setBusy(true);
    try {
      const response = await deactivateCard(card.id);
      setStatus(response.message);
      await load();
    } catch (e) {
      const data = (e as { response?: { data?: { message?: string } } })?.response?.data;
      setError(data?.message ?? "Unable to deactivate the card.");
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

  return (
    <div className="space-y-8">
      <header>
        <span className="inline-flex items-center gap-2 rounded-full bg-roicard-primary/15 px-3 py-1 text-xs font-medium text-roicard-accent">
          <Radio className="h-3.5 w-3.5" />
          Physical card
        </span>
        <h1 className="mt-3 text-2xl font-bold text-roicard-text sm:text-3xl">Card activation</h1>
        <p className="mt-2 text-sm text-roicard-text-muted sm:text-base">
          Bind one physical ROICARD to one profile after a verified {currency} {fee.toFixed(2)} activation payment.
        </p>
      </header>

      {status && <p className="rounded-lg border border-emerald-500/30 bg-emerald-500/10 px-3 py-2 text-sm text-emerald-400">{status}</p>}
      {error && <p className="rounded-lg border border-rose-500/30 bg-rose-500/10 px-3 py-2 text-sm text-rose-400">{error}</p>}

      <section className="rounded-2xl border border-roicard-border bg-roicard-bg-elevated p-5">
        <h2 className="flex items-center gap-2 text-base font-semibold text-roicard-text">
          <CreditCard className="h-4 w-4 text-roicard-accent" />
          Activate a card
        </h2>
        <div className="mt-4 grid gap-4 sm:grid-cols-2">
          <div>
            <label htmlFor="card-profile" className="mb-2 block text-sm font-medium text-roicard-text">Bind to profile</label>
            <select
              id="card-profile"
              value={profileId}
              onChange={(event) => setProfileId(event.target.value)}
              className="h-11 w-full rounded-lg border border-roicard-border bg-roicard-bg-muted px-3 text-sm text-roicard-text"
            >
              {profiles.map((profile) => (
                <option key={profile.id} value={String(profile.id)}>
                  {profile.display_name || profile.title || profile.slug || `Profile ${profile.id}`} ({profile.profile_type})
                </option>
              ))}
            </select>
          </div>
          <Input
            label="Card ID printed on the card"
            value={cardId}
            onChange={(event) => setCardId(event.target.value)}
            placeholder="RC-2026-00001"
          />
        </div>
        <p className="mt-3 text-xs text-roicard-text-muted">
          One active card per profile. A previously active card must be deactivated or replaced by an administrator first.
        </p>
        <Button className="mt-4 rounded-xl" onClick={activate} isLoading={busy}>
          Activate for {currency} {fee.toFixed(2)}
        </Button>
      </section>

      <section className="space-y-3">
        <h2 className="text-lg font-semibold text-roicard-text">Your cards</h2>
        {cards.length === 0 ? (
          <div className="rounded-2xl border border-dashed border-roicard-border bg-roicard-bg-elevated p-6 text-sm text-roicard-text-muted">
            No physical cards linked to your account yet.
          </div>
        ) : (
          <div className="grid gap-3 sm:grid-cols-2">
            {cards.map((card) => (
              <article key={card.id} className="rounded-2xl border border-roicard-border bg-roicard-bg-elevated p-4">
                <div className="flex items-start justify-between gap-2">
                  <div>
                    <p className="font-mono text-sm font-semibold text-roicard-text">{card.card_id}</p>
                    <p className="text-xs text-roicard-text-muted">
                      {card.profile?.display_name || card.profile?.title || card.profile?.slug || "No profile"}
                    </p>
                  </div>
                  <span
                    className={cn(
                      "rounded-full px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wider",
                      card.binding_key
                        ? "bg-emerald-500/15 text-emerald-400"
                        : "bg-roicard-bg-muted text-roicard-text-muted"
                    )}
                  >
                    {card.binding_key ? "Active" : card.inventory_status}
                  </span>
                </div>
                <p className="mt-2 text-xs text-roicard-text-muted">
                  {card.activated_at ? `Activated ${new Date(card.activated_at).toLocaleDateString()}` : "Not activated"}
                </p>
                <div className="mt-3 flex items-center justify-between gap-2">
                  {card.binding_key && (
                    <span className="inline-flex items-center gap-1 text-xs text-emerald-400">
                      <ShieldCheck className="h-3.5 w-3.5" />
                      QR/card resolves to profile
                    </span>
                  )}
                  <Button
                    size="sm"
                    variant="ghost"
                    className="ml-auto rounded-lg"
                    disabled={!card.binding_key || busy}
                    onClick={() => deactivate(card)}
                  >
                    Deactivate
                  </Button>
                </div>
              </article>
            ))}
          </div>
        )}
      </section>
    </div>
  );
}
