import { SignatureCardsView } from "@/components/signature/SignatureCardsView";
import type { Metadata } from "next";

export const metadata: Metadata = {
  title: "Signature cards",
};

export default function SignatureCardsPage() {
  return <SignatureCardsView />;
}
