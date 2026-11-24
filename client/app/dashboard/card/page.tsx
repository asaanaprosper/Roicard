import { CardActivationView } from "@/components/billing/CardActivationView";
import type { Metadata } from "next";

export const metadata: Metadata = {
  title: "Physical card",
};

export default function CardPage() {
  return <CardActivationView />;
}
