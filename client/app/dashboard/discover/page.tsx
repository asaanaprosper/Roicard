import { DiscoverView } from "@/components/discover/DiscoverView";
import type { Metadata } from "next";

export const metadata: Metadata = {
  title: "Discover",
};

export default function DiscoverPage() {
  return <DiscoverView />;
}
