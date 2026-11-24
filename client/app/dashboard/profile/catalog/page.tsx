import { CatalogManagerView } from "@/components/catalog/CatalogManagerView";
import type { Metadata } from "next";

export const metadata: Metadata = {
  title: "Business catalog",
};

export default function CatalogPage() {
  return <CatalogManagerView />;
}
