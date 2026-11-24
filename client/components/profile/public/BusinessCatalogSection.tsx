/**
 * BusinessCatalogSection
 *
 * Public, guest-browsable business catalog. Actions reuse the existing
 * enquiry and meeting flows; no payment or cart behaviour is provided.
 */

"use client";

import { Button } from "@/components/ui/Button";
import type { PublicCatalogItem } from "@/lib/profile/types";
import {
  CalendarClock,
  HandHeart,
  Phone,
  ShoppingBag,
  Wrench,
} from "lucide-react";

type BusinessCatalogSectionProps = {
  items: PublicCatalogItem[];
  hasMeetingTypes: boolean;
  phone: string | null;
  onEnquire: (item: PublicCatalogItem) => void;
  onRequestMeeting: (item: PublicCatalogItem) => void;
};

export function BusinessCatalogSection({
  items,
  hasMeetingTypes,
  phone,
  onEnquire,
  onRequestMeeting,
}: BusinessCatalogSectionProps) {
  if (items.length === 0) return null;

  return (
    <section className="space-y-4">
      <div className="rounded-3xl border border-roicard-border/60 bg-roicard-bg-elevated px-5 py-4">
        <h2 className="flex items-center gap-2 text-base font-semibold text-roicard-text">
          <ShoppingBag className="h-5 w-5 text-roicard-accent" aria-hidden />
          Catalog
        </h2>
        <p className="mt-1 text-sm text-roicard-text-muted">
          Services and products offered by this business.
        </p>
      </div>

      <div className="grid gap-4 sm:grid-cols-2">
        {items.map((item) => (
          <article
            key={item.id}
            className="glass-card overflow-hidden rounded-2xl"
          >
            {item.images.length > 0 && (
              <div className={item.images.length === 1 ? "grid" : "grid grid-cols-2 gap-0.5"}>
                {item.images.slice(0, 4).map((image, index) => (
                  <div
                    key={image.id}
                    className={
                      item.images.length === 1
                        ? "aspect-[16/10]"
                        : index === 0
                        ? "col-span-2 aspect-[16/9]"
                        : "aspect-square"
                    }
                  >
                    {/* eslint-disable-next-line @next/next/no-img-element */}
                    <img
                      src={image.url}
                      alt={item.title}
                      className="h-full w-full object-cover"
                    />
                  </div>
                ))}
              </div>
            )}

            <div className="space-y-3 p-4">
              <div className="flex items-start justify-between gap-2">
                <div>
                  <span className="inline-flex items-center gap-1 rounded-full bg-roicard-primary/10 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wider text-roicard-accent">
                    {item.type === "product" ? (
                      <ShoppingBag className="h-3 w-3" aria-hidden />
                    ) : (
                      <Wrench className="h-3 w-3" aria-hidden />
                    )}
                    {item.type}
                  </span>
                  <h3 className="mt-2 text-base font-semibold text-roicard-text">
                    {item.title}
                  </h3>
                </div>
                {item.price_mode === "on_request" ? (
                  <span className="shrink-0 rounded-full bg-roicard-bg-muted px-2.5 py-1 text-xs font-medium text-roicard-text-muted">
                    On request
                  </span>
                ) : (
                  <span className="shrink-0 rounded-full bg-roicard-bg-muted px-2.5 py-1 text-xs font-semibold text-roicard-text">
                    {item.price_label}
                  </span>
                )}
              </div>

              {item.category && (
                <p className="text-xs font-medium text-roicard-accent">
                  {item.category.name}
                </p>
              )}

              {item.description && (
                <p className="text-sm leading-relaxed text-roicard-text-muted">
                  {item.description}
                </p>
              )}

              <div className="flex flex-wrap gap-2 pt-1">
                {item.cta_type === "call" && phone ? (
                  <a href={`tel:${phone}`} className="inline-flex">
                    <Button size="sm" className="rounded-xl">
                      <Phone className="h-4 w-4" />
                      Call
                    </Button>
                  </a>
                ) : item.cta_type === "request_meeting" && hasMeetingTypes ? (
                  <Button
                    size="sm"
                    className="rounded-xl"
                    onClick={() => onRequestMeeting(item)}
                  >
                    <CalendarClock className="h-4 w-4" />
                    Request meeting
                  </Button>
                ) : (
                  <Button
                    size="sm"
                    className="rounded-xl"
                    onClick={() => onEnquire(item)}
                  >
                    <HandHeart className="h-4 w-4" />
                    Enquire
                  </Button>
                )}

                {item.cta_type !== "enquire" &&
                  (item.cta_type !== "request_meeting" || hasMeetingTypes) &&
                  (item.cta_type !== "call" || Boolean(phone)) && (
                  <Button
                    size="sm"
                    variant="secondary"
                    className="rounded-xl"
                    onClick={() => onEnquire(item)}
                  >
                    Enquire
                  </Button>
                )}
              </div>
            </div>
          </article>
        ))}
      </div>
    </section>
  );
}
