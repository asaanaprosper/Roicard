import { NetworkSearchView } from "@/components/search/NetworkSearchView";
import type { Metadata } from "next";

export const metadata: Metadata = {
  title: "Search the network",
};

export default function NetworkSearchPage() {
  return <NetworkSearchView />;
}
