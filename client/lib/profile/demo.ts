/**
 * Demo profile module
 *
 * A rich, realistic "Alex Morgan" profile used to show visitors what a ROICARD
 * profile looks like after onboarding. The hero card, landing demo preview,
 * and the /alex-morgan route all share this same persona so the story is
 * consistent everywhere.
 *
 * The public route serves this data ONLY when no real member owns the slug, so
 * a real "alex-morgan" user would always take precedence once they exist.
 */

import type { PublicProfile } from "@/lib/api/profile";
import type { UserProfile } from "@/lib/profile/types";

/** Slug the demo persona lives at (also linked from the landing demo section). */
export const DEMO_SLUG = "alex-morgan";

/** UserProfile used by the hero floating card + landing demo preview card. */
export const DEMO_USER_PROFILE: UserProfile = {
  firstName: "Alex",
  lastName: "Morgan",
  email: "alex.morgan@example.com",
  profilePhotoUrl: "/images/demo-avatar-male.png",
  professionalTitle: "Product Designer",
  roleDescription:
    "Lead product designer crafting human-centred experiences that turn complex problems into simple, delightful tools.",
  organization: "Acme Inc.",
  bio: "Designing digital experiences that create meaningful impact. I partner with founders and teams to shape products people love — from first sketch to shipped feature.",
  phone: "+233 24 000 0000",
  whatsapp: "+233 24 000 0000",
  dateOfBirth: "",
  gender: "",
  location: "Accra, Ghana",
  social: {
    linkedin: "",
    instagram: "",
    twitter: "",
    facebook: "",
    youtube: "",
    tiktok: "",
    snapchat: "",
    website: "",
  },
  interests: ["Technology", "Entrepreneurship", "Leadership", "Personal Branding"],
  seeking:
    "Open to senior product roles, design-led startup collaborations, and mentorship opportunities in the African tech ecosystem.",
  offering:
    "Product design, UX research, design systems, and honest feedback on early-stage product ideas.",
  username: DEMO_SLUG,
  createdAt: new Date("2026-01-15T10:00:00.000Z").toISOString(),
  membershipStatus: "active",
};

/** PublicProfile served by the /[slug] route when the backend has no owner. */
export const DEMO_PUBLIC_PROFILE: PublicProfile = {
  id: 0,
  slug: DEMO_SLUG,
  profile_type: "personal",
  display_name: null,
  title: DEMO_USER_PROFILE.professionalTitle,
  role_description: DEMO_USER_PROFILE.roleDescription,
  organisation: DEMO_USER_PROFILE.organization,
  whatsapp_phone: DEMO_USER_PROFILE.whatsapp,
  phone: DEMO_USER_PROFILE.phone,
  contact_email: DEMO_USER_PROFILE.email,
  website_url: "",
  skills: ["Product design", "UX research", "Design systems"],
  services: ["Product strategy", "Design sprints"],
  date_of_birth: null,
  gender: null,
  interests: DEMO_USER_PROFILE.interests,
  location: DEMO_USER_PROFILE.location,
  bio: DEMO_USER_PROFILE.bio,
  seeking: DEMO_USER_PROFILE.seeking,
  offering: DEMO_USER_PROFILE.offering,
  seeking_items: [],
  offering_items: [],
  logo_url: null,
  cover_image_url: null,
  catalog_items: [],
  organizations: [],
  verified_member: false,
  verification_label: "Verified Roicard member, email and phone confirmed.",
  avatar: DEMO_USER_PROFILE.profilePhotoUrl,
  is_published: true,
  published_at: null,
  visibility: "public",
  is_searchable: true,
  can_contact: true,
  contact_details_visible: true,
  visible_contact_fields: {
    phone: true,
    whatsapp: true,
    email: true,
    website: true,
  },
  user: {
    first_name: DEMO_USER_PROFILE.firstName,
    last_name: DEMO_USER_PROFILE.lastName,
    email: DEMO_USER_PROFILE.email,
  },
  social_links: [],
  education: [],
  experience: [],
  achievements: [],
  cv: null,
  meeting_types: [],
};