<?php

namespace App\Services;

use App\Models\Profile;
use App\Models\User;
use App\Services\Ai\ProfileAiService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class ProfilePublishingService
{
    public function __construct(
        protected ProfileAiService $ai,
    ) {}

    /**
     * Publish a profile after enforcing the Phase 2 minimum requirements:
     * name, headline, an explicit privacy choice, and approval of every
     * AI-generated field.
     */
    public function publish(User $user, Profile $profile): Profile
    {
        $this->assertOwns($user, $profile);

        $displayName = trim((string) ($profile->display_name ?: $user->first_name . ' ' . $user->last_name));
        $headline = trim((string) $profile->title);

        $errors = [];
        if ($displayName === '') {
            $errors['display_name'] = 'Add your name before publishing.';
        }
        if ($headline === '') {
            $errors['title'] = 'Add a headline before publishing.';
        }
        if ($profile->privacy_confirmed_at === null) {
            $errors['privacy'] = 'Choose and confirm your privacy settings before publishing.';
        }
        if (!$profile->hasApprovedAiDraft()) {
            $errors['ai_draft'] = 'Approve every AI-generated field before publishing.';
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        DB::transaction(function () use ($profile) {
            $profile->forceFill([
                'is_published' => true,
                'published_at' => now(),
                'is_live' => true,
            ])->save();
        });

        $profile->bustPublicCache();

        return $profile->fresh() ?? $profile;
    }

    public function unpublish(User $user, Profile $profile): Profile
    {
        $this->assertOwns($user, $profile);

        DB::transaction(function () use ($profile) {
            $profile->forceFill([
                'is_published' => false,
                'published_at' => null,
                'is_live' => false,
            ])->save();
        });

        $profile->bustPublicCache();

        return $profile->fresh() ?? $profile;
    }

    /**
     * Save an AI/manual draft for field-by-field review. Member-provided
     * profile text is not modified until each field is approved.
     *
     * @param  array<string, mixed>  $draft
     */
    public function saveDraftForReview(User $user, Profile $profile, array $draft, string $source = 'manual'): Profile
    {
        $this->assertOwns($user, $profile);

        $allowed = ['headline', 'about', 'skills', 'services'];
        $clean = [];
        foreach ($allowed as $field) {
            if (array_key_exists($field, $draft)) {
                $clean[$field] = $draft[$field];
            }
        }

        $profile->forceFill([
            'ai_draft' => array_merge($clean, [
                'source' => $source,
                'generated_at' => now()->toISOString(),
            ]),
            'ai_approved_fields' => [],
        ])->save();

        return $profile->fresh() ?? $profile;
    }

    /**
     * Apply only the approved draft fields to the live profile.
     *
     * @param  array<int, string>  $fields
     */
    public function approveDraftFields(User $user, Profile $profile, array $fields): Profile
    {
        $this->assertOwns($user, $profile);

        $allowed = ProfileAiService::PROFILE_FIELDS;
        $draft = $profile->ai_draft ?? [];

        if (($draft['source'] ?? null) !== 'ai') {
            throw ValidationException::withMessages([
                'ai_draft' => 'There is no AI-generated draft to approve.',
            ]);
        }

        $fields = array_values(array_unique($fields));
        $invalid = array_values(array_diff($fields, $allowed));
        if ($invalid !== []) {
            throw ValidationException::withMessages([
                'fields' => 'Unknown AI draft field.',
            ]);
        }

        foreach ($fields as $field) {
            if (!array_key_exists($field, $draft)) {
                throw ValidationException::withMessages([
                    'fields' => "The AI draft does not contain {$field}.",
                ]);
            }
        }

        $updates = [];
        $experience = [];

        foreach ($fields as $field) {
            $value = $draft[$field];

            if ($field === 'experience') {
                $experience = $this->validatedExperience($value);
                continue;
            }

            $updates[match ($field) {
                'headline' => 'title',
                'about' => 'bio',
                default => $field,
            }] = $value;
        }

        if ($updates !== []) {
            Validator::make($updates, [
                'title' => ['sometimes', 'required', 'string', 'max:255'],
                'bio' => ['sometimes', 'nullable', 'string'],
                'skills' => ['sometimes', 'nullable', 'array'],
                'skills.*' => ['string', 'max:100'],
                'services' => ['sometimes', 'nullable', 'array'],
                'services.*' => ['string', 'max:150'],
                'seeking' => ['sometimes', 'nullable', 'string', 'max:1000'],
                'offering' => ['sometimes', 'nullable', 'string', 'max:1000'],
            ])->validate();
        }

        $approved = array_values(array_unique(array_merge(
            $profile->ai_approved_fields ?? [],
            $fields
        )));

        DB::transaction(function () use ($profile, $updates, $experience, $approved) {
            if ($updates !== []) {
                $profile->forceFill($updates)->save();
            }

            foreach ($experience as $row) {
                $profile->experience()->firstOrCreate(
                    [
                        'title' => $row['title'],
                        'company' => $row['company'],
                        'start_date' => $row['start_date'],
                    ],
                    [
                        'end_date' => $row['end_date'] ?? null,
                        'location' => $row['location'] ?? null,
                    ],
                );
            }

            $profile->forceFill(['ai_approved_fields' => $approved])->save();
        });

        if (array_intersect($fields, ['seeking', 'offering']) !== []) {
            app(ProfileSignalService::class)->syncLegacySignals($profile);
        }

        $profile->recalculateCompletion();
        $profile->bustPublicCache();
        app(MatchmakingService::class)->invalidate();

        return $profile->fresh() ?? $profile;
    }

    /**
     * @param  mixed  $value
     * @return array<int, array<string, mixed>>
     */
    protected function validatedExperience(mixed $value): array
    {
        if (! is_array($value)) {
            throw ValidationException::withMessages([
                'experience' => 'The AI experience draft is not readable.',
            ]);
        }

        $validated = [];

        foreach ($value as $row) {
            if (! is_array($row)) {
                continue;
            }

            $row = Validator::make($row, [
                'title' => ['required', 'string', 'max:255'],
                'company' => ['required', 'string', 'max:255'],
                'start_date' => ['required', 'date'],
                'end_date' => ['nullable', 'date', 'after:start_date'],
                'location' => ['nullable', 'string', 'max:255'],
            ])->validate();

            $validated[] = $row;
        }

        return $validated;
    }

    /**
     * Request AI profile drafting. Generation lives in ProfileAiService; this
     * method keeps the existing publishing service API used by clients.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function requestAiDraft(User $user, Profile $profile, array $input = []): array
    {
        return $this->ai->profileDraft($user, $profile, $input);
    }

    public function approveAllDraftFields(User $user, Profile $profile): Profile
    {
        $draft = $profile->ai_draft ?? [];

        return $this->approveDraftFields(
            $user,
            $profile,
            array_values(array_intersect(ProfileAiService::PROFILE_FIELDS, array_keys($draft)))
        );
    }

    protected function assertOwns(User $user, Profile $profile): void
    {
        abort_unless(
            (string) $profile->user_id === (string) $user->getKey(),
            403,
            'Profile does not belong to this account.'
        );
    }
}
