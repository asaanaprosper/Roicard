import apiClient from "./client";

export type CatalogType = "service" | "product";
export type CatalogPriceMode = "fixed" | "from" | "on_request";
export type CatalogCta = "enquire" | "request_meeting" | "call";

export interface CatalogImage {
  id: number;
  url: string;
  thumb_url?: string;
  name: string;
}

export interface CatalogItem {
  id: number;
  profile_id: number;
  type: CatalogType;
  title: string;
  description: string | null;
  category_id: number | null;
  category: { id: number; name: string; slug?: string | null } | null;
  price: number | null;
  price_mode: CatalogPriceMode;
  price_label: string;
  currency: string;
  cta_type: CatalogCta;
  is_active: boolean;
  sort_order: number;
  images: CatalogImage[];
  created_at?: string;
  updated_at?: string;
}

export interface CatalogListResponse {
  items: CatalogItem[];
  counts: { total: number; active: number };
  limits: { catalog_items: number | null };
  max_images: number;
  currency: string;
}

export interface CatalogItemPayload {
  type?: CatalogType;
  title?: string;
  description?: string | null;
  category_id?: number | null;
  price?: number | null;
  price_mode?: CatalogPriceMode;
  currency?: string;
  cta_type?: CatalogCta;
  is_active?: boolean;
  sort_order?: number;
}

export interface CatalogEnquiry {
  id: number;
  item: { id: number; title: string; type: CatalogType } | null;
  sender_user_id: number | null;
  sender_name: string | null;
  name: string;
  email: string;
  phone: string | null;
  message: string;
  status: "new" | "read" | "archived";
  source: "public" | "member";
  created_at: string;
}

export async function getCatalog(profileId: number): Promise<CatalogListResponse> {
  const { data } = await apiClient.get(`/profiles/${profileId}/catalog`);
  return data;
}

export async function createCatalogItem(
  profileId: number,
  payload: CatalogItemPayload
): Promise<{ item: CatalogItem; message: string }> {
  const { data } = await apiClient.post(`/profiles/${profileId}/catalog`, payload);
  return data;
}

export async function updateCatalogItem(
  profileId: number,
  itemId: number,
  payload: CatalogItemPayload
): Promise<{ item: CatalogItem; message: string }> {
  const { data } = await apiClient.patch(`/profiles/${profileId}/catalog/${itemId}`, payload);
  return data;
}

export async function deleteCatalogItem(
  profileId: number,
  itemId: number
): Promise<{ message: string }> {
  const { data } = await apiClient.delete(`/profiles/${profileId}/catalog/${itemId}`);
  return data;
}

export async function setCatalogItemActive(
  profileId: number,
  itemId: number,
  active: boolean
): Promise<{ item: CatalogItem; message: string }> {
  const { data } = await apiClient.post(
    `/profiles/${profileId}/catalog/${itemId}/${active ? "activate" : "deactivate"}`
  );
  return data;
}

export async function reorderCatalog(
  profileId: number,
  itemIds: number[]
): Promise<{ message: string }> {
  const { data } = await apiClient.post(`/profiles/${profileId}/catalog/reorder`, {
    items: itemIds,
  });
  return data;
}

export async function uploadCatalogImages(
  profileId: number,
  itemId: number,
  files: File[]
): Promise<{ item: CatalogItem; message: string }> {
  const form = new FormData();
  files.forEach((file) => form.append("images[]", file));
  const { data } = await apiClient.post(
    `/profiles/${profileId}/catalog/${itemId}/images`,
    form,
    { headers: { "Content-Type": "multipart/form-data" } }
  );
  return data;
}

export async function deleteCatalogImage(
  profileId: number,
  itemId: number,
  mediaId: number
): Promise<{ item: CatalogItem; message: string }> {
  const { data } = await apiClient.delete(
    `/profiles/${profileId}/catalog/${itemId}/images/${mediaId}`
  );
  return data;
}

export async function getCatalogEnquiries(profileId: number): Promise<{ enquiries: CatalogEnquiry[] }> {
  const { data } = await apiClient.get(`/profiles/${profileId}/catalog-enquiries`);
  return data;
}

export async function submitCatalogEnquiry(
  itemId: number,
  payload: { name: string; email: string; phone?: string; message: string }
): Promise<{ enquiry: { id: number; catalog_item_id: number; status: string }; message: string }> {
  const { data } = await apiClient.post(`/catalog/${itemId}/enquiries`, payload);
  return data;
}
