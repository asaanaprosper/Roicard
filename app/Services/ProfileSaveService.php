<?php

namespace App\Services;

use App\Models\InterestOption;
use App\Models\Profile;
use App\Models\User;

/**
 * Single source of truth for persisting validated profile payloads.
 *
 * Used by BOTH the member's own profile flow (ProfileController@update) and
 * the admin edit flow (AdminController@updateUserProfile) so admin edits go
 * through exactly the same validation (ProfileRequest) and application logic
 * as member edits — never a looser or stricter parallel path.
 */
class ProfileSaveService
{
    /**
     * Apply a validated payload (ProfileRequest::validated() shape) to a
     * profile. When no profile is supplied this resolves/creates the account's
     * default profile and also applies account name fields (MVP 1 behaviour).
     *
     * Secondary profiles pass an explicit $profile so account-level identity
     * is never overwritten from a business/personal sub-profile form.
     */
    public function save(User $user, array $data, ?Profile $profile = null): Profile
    {
        if ($profile === null) {
            // Update user name fields if provided (email is not changed here —
            // account email changes go through the dedicated account endpoint)
            $userFields = array_filter([
                'first_name' => $data['first_name'] ?? null,
                'last_name' => $data['last_name'] ?? null,
            ]);
            if ($userFields) {
                $user->update($userFields);
            }

            // Update profile (create if it doesn't exist yet)
            $profile = $user->profile()->firstOrCreate([]);
        }

        // Decode interests JSON string into an array (empty array if invalid)
        $interests = null;
        if (!empty($data['interests'])) {
            $decoded = json_decode($data['interests'], true);
            $interests = is_array($decoded) ? array_values(array_filter($decoded, 'is_string')) : [];
        }

        // Sync interest options pivot table — create missing options so every
        // saved interest becomes a selectable option for other members too.
        if (is_array($interests)) {
            $interestOptionIds = [];
            foreach ($interests as $name) {
                $option = InterestOption::firstOrCreate(
                    ['name' => $name],
                    ['sort_order' => InterestOption::max('sort_order') + 1]
                );
                $interestOptionIds[] = $option->id;
            }
            $profile->interestOptions()->sync($interestOptionIds);
        }

        $profileFields = array_filter([
            'profile_type' => $data['profile_type'] ?? null,
            'display_name' => $data['display_name'] ?? null,
            'title' => $data['title'] ?? null,
            'role_description' => $data['role_description'] ?? null,
            'organisation' => $data['organisation'] ?? null,
            'whatsapp_phone' => $data['whatsapp_phone'] ?? null,
            'phone' => $data['phone'] ?? null,
            'contact_email' => $data['contact_email'] ?? null,
            'website_url' => $data['website_url'] ?? null,
            'skills' => array_key_exists('skills', $data) ? array_values((array) $data['skills']) : null,
            'services' => array_key_exists('services', $data) ? array_values((array) $data['services']) : null,
            'contact_visibility' => array_key_exists('contact_visibility', $data) ? $data['contact_visibility'] : null,
            'date_of_birth' => $data['date_of_birth'] ?? null,
            'gender' => $data['gender'] ?? null,
            'interests' => is_array($interests) ? $interests : null,
            'location' => $data['location'] ?? null,
            'bio' => $data['bio'] ?? null,
            'seeking' => $data['seeking'] ?? null,
            'offering' => $data['offering'] ?? null,
            'visibility' => $data['visibility'] ?? null,
            'is_searchable' => $data['is_searchable'] ?? null,
            'is_contactable' => $data['is_contactable'] ?? null,
            'connection_request_policy' => $data['connection_request_policy'] ?? null,
            'contact_details_visibility' => $data['contact_details_visibility'] ?? null,
        ], fn ($value) => $value !== null);

        if (($data['confirm_privacy'] ?? false) === true) {
            $profileFields['privacy_confirmed_at'] = now();
        }
        if ($profileFields) {
            $profile->update($profileFields);
        }

        // Handle social links
        if (!empty($data['social_links'])) {
            $links = json_decode($data['social_links'], true);
            if (is_array($links)) {
                $profile->socialLinks()->delete();
                foreach ($links as $platform => $value) {
                    if (!empty($value)) {
                        $profile->socialLinks()->create([
                            'platform' => $platform,
                            'value' => $value,
                        ]);
                    }
                }
            }
        }

        // Replace-all entry collections (admin complete-setup flow). Only
        // touched when the key is present, so normal edits never wipe them.
        $this->syncEntries($profile, $data);

        // Keep legacy Seeking/Offering text and the new per-item signal table
        // in sync when older settings forms are used.
        app(ProfileSignalService::class)->syncLegacySignals($profile);

        $profile->recalculateCompletion();

        // Bust the public profile cache so changes are visible immediately
        $profile->bustPublicCache();

        return $profile->fresh() ?? $profile;
    }

    /**
     * Replace education/experience/achievement rows when the admin payload
     * includes them. Validation has already filtered the allowed keys.
     */
    protected function syncEntries(Profile $profile, array $data): void
    {
        if (array_key_exists('education', $data)) {
            $profile->education()->delete();
            foreach ((array) $data['education'] as $row) {
                $profile->education()->create([
                    'institution' => $row['institution'] ?? null,
                    'degree' => $row['degree'] ?? null,
                    'start_year' => $row['start_year'] ?? null,
                    'end_year' => $row['end_year'] ?? null,
                    'honours' => $row['honours'] ?? null,
                ]);
            }
        }

        if (array_key_exists('experience', $data)) {
            $profile->experience()->delete();
            foreach ((array) $data['experience'] as $row) {
                $profile->experience()->create([
                    'title' => $row['title'] ?? null,
                    'company' => $row['company'] ?? null,
                    'start_date' => $row['start_date'] ?? null,
                    'end_date' => $row['end_date'] ?? null,
                    'location' => $row['location'] ?? null,
                ]);
            }
        }

        if (array_key_exists('achievements', $data)) {
            $profile->achievements()->delete();
            foreach ((array) $data['achievements'] as $row) {
                $profile->achievements()->create([
                    'title' => $row['title'] ?? null,
                    'issuer' => $row['issuer'] ?? null,
                    'date' => $row['date'] ?? null,
                ]);
            }
        }
    }
}
