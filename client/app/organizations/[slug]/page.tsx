import { OrganizationPublicView } from "@/components/organizations/OrganizationPublicView";
import type { Metadata } from "next";

export const metadata: Metadata = {
  title: "Organization",
};

export default async function OrganizationPage({
  params,
}: {
  params: Promise<{ slug: string }>;
}) {
  const { slug } = await params;

  return <OrganizationPublicView slug={slug} />;
}
