/**
 * Public-safe network actions shared by Discover and Search.
 *
 * Contact details are only read from the public profile endpoint, which
 * already applies the owner's per-field contact privacy. No private field is
 * reconstructed on the client.
 */

import { getPublicProfile } from "@/lib/api/profile";
import { fullName, type NetworkProfile } from "@/lib/api/network";

function publicProfileUrl(slug: string | null): string | null {
  if (!slug) return null;
  if (typeof window === "undefined") return null;
  return `${window.location.origin}/${slug}`;
}

export async function shareNetworkProfile(profile: NetworkProfile): Promise<boolean> {
  const url = publicProfileUrl(profile.slug);
  if (!url) return false;

  const title = fullName(profile);

  if (typeof navigator !== "undefined" && navigator.share) {
    try {
      await navigator.share({ title, text: `Connect with ${title} on ROICARD`, url });
      return true;
    } catch {
      // User cancelled or sharing is unavailable — fall back to clipboard.
    }
  }

  if (typeof navigator !== "undefined" && navigator.clipboard) {
    await navigator.clipboard.writeText(url);
    return true;
  }

  return false;
}

function escapeVCard(value: string): string {
  return value.replace(/\\/g, "\\\\").replace(/\n/g, "\\n").replace(/,/g, "\\,").replace(/;/g, "\\;");
}

export async function saveNetworkContact(profile: NetworkProfile): Promise<boolean> {
  if (!profile.slug) return false;

  let details: Awaited<ReturnType<typeof getPublicProfile>>;
  try {
    details = await getPublicProfile(profile.slug);
  } catch {
    return false;
  }

  const name = fullName(profile);
  const [first, ...rest] = name.split(" ");
  const email = details.contact_email ?? "";
  const phone = details.phone || details.whatsapp_phone || "";
  const website = details.website_url ?? "";
  const organisation = details.organisation ?? "";
  const title = details.title ?? "";
  const profileUrl = publicProfileUrl(profile.slug) ?? "";

  const lines = [
    "BEGIN:VCARD",
    "VERSION:3.0",
    `N:${escapeVCard(rest.join(" "))};${escapeVCard(first)};;;`,
    `FN:${escapeVCard(name)}`,
    organisation ? `ORG:${escapeVCard(organisation)}` : "",
    title ? `TITLE:${escapeVCard(title)}` : "",
    email ? `EMAIL;TYPE=INTERNET:${escapeVCard(email)}` : "",
    phone ? `TEL;TYPE=CELL:${escapeVCard(phone)}` : "",
    website ? `URL:${escapeVCard(website)}` : "",
    profileUrl ? `URL:${escapeVCard(profileUrl)}` : "",
    `NOTE:${escapeVCard(title || organisation || "ROICARD member")}`,
    "END:VCARD",
  ].filter(Boolean);

  const blob = new Blob([lines.join("\r\n")], { type: "text/vcard;charset=utf-8" });
  const url = URL.createObjectURL(blob);
  const anchor = document.createElement("a");
  anchor.href = url;
  anchor.download = `${name.replace(/[^\w-]+/g, "-").toLowerCase() || "roicard-contact"}.vcf`;
  document.body.appendChild(anchor);
  anchor.click();
  anchor.remove();
  URL.revokeObjectURL(url);

  return true;
}
