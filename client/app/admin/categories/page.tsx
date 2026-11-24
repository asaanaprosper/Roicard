import { CategoriesManagementView } from "@/components/admin";
import type { Metadata } from "next";

export const metadata: Metadata = {
  title: "Categories — Admin",
};

export default function AdminCategoriesPage() {
  return <CategoriesManagementView />;
}
