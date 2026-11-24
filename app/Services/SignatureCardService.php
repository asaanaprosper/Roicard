<?php

namespace App\Services;

use App\Models\Profile;
use App\Models\SignatureCard;
use App\Models\User;
use App\Support\PlanLimitKey;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
class SignatureCardService
{
    public function __construct(
        protected EntitlementService $entitlements,
        protected QRService $qr,
        protected ReferralService $referrals,
    ) {}

    public function assertFormat(string $format): void
    {
        if (! in_array($format, SignatureCard::formats(), true)) {
            throw ValidationException::withMessages(['format' => 'Unsupported signature card format.']);
        }
    }

    public function ensureEntitlement(User $user): void
    {
        // Only one built-in template exists. A limit of zero disables the
        // feature; a limit of one or more permits that template without
        // inventing additional template variants.
        $this->entitlements->ensureWithinLimit(
            $user,
            PlanLimitKey::SIGNATURE_CARD_TEMPLATES,
            0,
            1,
            'Your current plan does not include signature cards.',
        );
    }

    public function generate(Profile $profile, string $format, string $template = SignatureCard::TEMPLATE_CLASSIC): SignatureCard
    {
        $this->assertFormat($format);
        $this->ensureEntitlement($profile->user);

        if (! in_array($template, SignatureCard::templates(), true)) {
            throw ValidationException::withMessages(['template' => 'Unknown signature card template.']);
        }

        $snapshot = $this->snapshot($profile, $format, $template);
        $hash = hash('sha256', json_encode($snapshot));
        $cacheMinutes = max(1, (int) config('roicard.signature_cards.cache_minutes', 5));

        $card = SignatureCard::firstOrNew([
            'profile_id' => $profile->id,
            'format' => $format,
            'template' => $template,
        ]);

        $freshEnough = $card->exists
            && $card->hash === $hash
            && $card->generated_at
            && $card->generated_at->gt(now()->subMinutes($cacheMinutes))
            && Storage::disk('local')->exists($card->file_path);

        if ($freshEnough) {
            return $card;
        }

        [$width, $height] = SignatureCard::dimensions($format);
        $png = $this->render($snapshot, $format, $width, $height, $template);

        $path = "signature-cards/{$profile->id}/{$format}-{$template}-" . substr($hash, 0, 12) . '.png';
        Storage::disk('local')->put($path, $png);

        if ($card->exists && $card->file_path !== $path) {
            Storage::disk('local')->delete($card->file_path);
        }

        $card->fill([
            'file_path' => $path,
            'hash' => $hash,
            'alt_text' => $this->altText($snapshot, $profile),
            'generated_at' => now(),
        ])->save();

        return $card->fresh();
    }

    /**
     * @return array<string, mixed>
     */
    public function snapshot(Profile $profile, string $format, string $template): array
    {
        $profile->loadMissing(['user', 'media']);
        $user = $profile->user;
        $visible = app(ProfileVisibilityService::class)->visibleContactFields(null, $profile);

        $name = trim((string) ($profile->display_name
            ?: trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? ''))));
        $name = $name !== '' ? $name : 'ROICARD member';

        $media = $profile->isBusiness()
            ? ($profile->getFirstMedia('logo') ?: $profile->getFirstMedia('avatar'))
            : $profile->getFirstMedia('avatar');
        $logo = $profile->isBusiness() ? $profile->getFirstMedia('logo') : null;

        $referralCode = null;
        if ($user->referral_signature_enabled) {
            $referralCode = $this->referrals->codeFor($user);
        }

        $slug = $profile->slug;
        $frontend = rtrim(config('app.frontend_url', config('app.url')), '/');
        $profileUrl = $slug ? $frontend . '/' . $slug : $frontend;
        $scanUrl = $slug
            ? $frontend . '/api/qr/' . $slug . '?source=signature'
                . ($referralCode ? '&ref=' . urlencode($referralCode) : '')
            : $profileUrl;

        $shortLink = $slug
            ? preg_replace('#^https?://#', '', $profileUrl)
            : 'myroicard.com';

        return [
            'profile_id' => $profile->id,
            'name' => $name,
            'title' => $profile->title,
            'organisation' => $profile->organisation,
            'location' => $profile->location,
            'profile_type' => $profile->profile_type,
            'short_link' => $shortLink,
            'profile_url' => $profileUrl,
            'scan_url' => $scanUrl,
            'email' => $visible['email'] ? ($profile->contact_email ?: $user->email) : null,
            'phone' => $visible['phone'] ? $profile->phone : null,
            'avatar_path' => $this->safeMediaPath($media),
            'avatar_updated_at' => $media?->updated_at?->toIso8601String(),
            'logo_path' => $this->safeMediaPath($logo),
            'logo_updated_at' => $logo?->updated_at?->toIso8601String(),
            'referral_code' => $referralCode,
            'format' => $format,
            'template' => $template,
            'profile_updated_at' => $profile->updated_at?->toIso8601String(),
            'user_updated_at' => $user->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    protected function render(array $snapshot, string $format, int $width, int $height, string $template): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagealphablending($image, true);

        $colors = config('roicard.signature_cards.colors', []);
        $primary = $this->allocate($image, $colors['primary'] ?? '#E63946');
        $accent = $this->allocate($image, $colors['accent'] ?? '#FF8C42');
        $dark = $this->allocate($image, $colors['background'] ?? '#111214');
        $white = $this->allocate($image, $colors['text'] ?? '#FFFFFF');
        $muted = $this->allocate($image, $colors['muted'] ?? '#D6D3D1');

        imagefilledrectangle($image, 0, 0, $width, $height, $dark);

        // Existing poster-style brand gradient.
        for ($y = 0; $y < $height; $y++) {
            $ratio = $y / max(1, $height - 1);
            $r = (int) round(230 + (255 - 230) * $ratio);
            $g = (int) round(57 + (140 - 57) * $ratio);
            $b = (int) round(70 + (66 - 70) * $ratio);
            $line = imagecolorallocatealpha($image, $r, $g, $b, 88);
            imageline($image, 0, $y, $width, $y, $line);
        }

        imagefilledellipse($image, (int) ($width * 0.92), (int) ($height * 0.08), (int) ($width * 0.55), (int) ($height * 0.34), $this->alpha($image, $accent, 80));
        imagefilledellipse($image, (int) ($width * 0.08), (int) ($height * 0.95), (int) ($width * 0.5), (int) ($height * 0.3), $this->alpha($image, $primary, 90));

        $qrSize = $format === SignatureCard::FORMAT_EMAIL_BANNER ? 110 : ($format === SignatureCard::FORMAT_STORY ? 300 : 220);
        $qrPadding = $format === SignatureCard::FORMAT_EMAIL_BANNER ? 12 : 22;
        $qrBox = $qrSize + $qrPadding * 2;

        if ($format === SignatureCard::FORMAT_EMAIL_BANNER) {
            $this->drawBanner($image, $snapshot, $width, $height, $white, $muted, $qrSize, $qrBox);
        } elseif ($format === SignatureCard::FORMAT_STORY) {
            $this->drawStory($image, $snapshot, $width, $height, $white, $muted, $accent, $qrSize, $qrBox);
        } else {
            $this->drawSquare($image, $snapshot, $width, $height, $white, $muted, $accent, $qrSize, $qrBox);
        }

        ob_start();
        imagepng($image);
        $contents = ob_get_clean();
        imagedestroy($image);

        return $contents ?: '';
    }

    protected function drawSquare($image, array $s, int $w, int $h, int $white, int $muted, int $accent, int $qrSize, int $qrBox): void
    {
        $avatarSize = 210;
        $this->drawAvatar($image, $s, 80, 90, $avatarSize, $accent);

        $x = 80 + $avatarSize + 55;
        $y = 105;
        $y = $this->text($image, $s['name'], $x, $y, 48, $white, $w - $x - 70, true);
        if ($s['title']) {
            $y = $this->text($image, $s['title'], $x, $y + 18, 27, $muted, $w - $x - 70, true);
        }
        if ($s['organisation']) {
            $y = $this->text($image, $s['organisation'], $x, $y + 10, 24, $accent, $w - $x - 70, true);
        }

        $linkY = max($y + 80, 520);
        $this->text($image, $s['short_link'], 80, $linkY, 24, $white, $w - 160, false);
        $this->drawQr($image, $s['scan_url'], $w - $qrBox - 70, $h - $qrBox - 70, $qrSize, $qrBox);
    }

    protected function drawStory($image, array $s, int $w, int $h, int $white, int $muted, int $accent, int $qrSize, int $qrBox): void
    {
        $avatarSize = 360;
        $avatarX = (int) (($w - $avatarSize) / 2);
        $this->drawAvatar($image, $s, $avatarX, 180, $avatarSize, $accent);

        $x = 90;
        $max = $w - 180;
        $y = 590;
        $y = $this->text($image, $s['name'], $x, $y, 56, $white, $max, true, 'center');
        if ($s['title']) {
            $y = $this->text($image, $s['title'], $x, $y + 24, 30, $muted, $max, true, 'center');
        }
        if ($s['organisation']) {
            $y = $this->text($image, $s['organisation'], $x, $y + 14, 27, $accent, $max, true, 'center');
        }

        $this->text($image, $s['short_link'], $x, $h - 430, 26, $white, $max, true, 'center');
        $qrX = (int) (($w - $qrBox) / 2);
        $this->drawQr($image, $s['scan_url'], $qrX, $h - $qrBox - 110, $qrSize, $qrBox);
    }

    protected function drawBanner($image, array $s, int $w, int $h, int $white, int $muted, int $qrSize, int $qrBox): void
    {
        $avatarSize = 112;
        $this->drawAvatar($image, $s, 28, 44, $avatarSize, $white);

        $x = 168;
        $max = $w - $x - $qrBox - 36;
        $y = 46;
        $y = $this->text($image, $s['name'], $x, $y, 22, $white, $max, true, 'left', true);
        if ($s['title'] || $s['organisation']) {
            $line = trim(($s['title'] ?: '') . ($s['organisation'] ? ' · ' . $s['organisation'] : ''));
            $y = $this->text($image, $line, $x, $y + 4, 14, $muted, $max, true, 'left', true);
        }
        $this->text($image, $s['short_link'], $x, $y + 4, 12, $white, $max, false, 'left', true);

        $this->drawQr($image, $s['scan_url'], $w - $qrBox - 20, (int) (($h - $qrBox) / 2), $qrSize, $qrBox);
    }

    protected function drawAvatar($image, array $s, int $x, int $y, int $size, int $border): void
    {
        $path = $s['logo_path'] ?: $s['avatar_path'];

        if ($path && is_file($path)) {
            $contents = @file_get_contents($path);
            $source = $contents ? @imagecreatefromstring($contents) : null;

            if ($source) {
                $srcW = imagesx($source);
                $srcH = imagesy($source);
                $scale = max($size / $srcW, $size / $srcH);
                $cropW = (int) ($size / $scale);
                $cropH = (int) ($size / $scale);
                $srcX = (int) (($srcW - $cropW) / 2);
                $srcY = (int) (($srcH - $cropH) / 2);

                imagecopyresampled($image, $source, $x, $y, $srcX, $srcY, $size, $size, $cropW, $cropH);
                imagedestroy($source);
                return;
            }
        }

        $initials = '';
        foreach (preg_split('/\s+/', trim((string) $s['name'])) ?: [] as $part) {
            if ($part !== '') {
                $initials .= mb_strtoupper(mb_substr($part, 0, 1));
            }
            if (mb_strlen($initials) >= 2) {
                break;
            }
        }

        imagefilledellipse($image, $x + (int) ($size / 2), $y + (int) ($size / 2), $size, $size, $border);
        if ($initials !== '') {
            $this->text($image, $initials, $x, $y + (int) ($size * 0.24), (int) ($size * 0.36), imagecolorallocate($image, 255, 255, 255), $size, true, 'center');
        }
    }

    protected function drawQr($image, string $url, int $x, int $y, int $size, int $box): void
    {
        $png = $this->qr->generatePng($url, $size);
        $qr = @imagecreatefromstring($png);

        if (! $qr) {
            return;
        }

        imagefilledrectangle($image, $x, $y, $x + $box, $y + $box, imagecolorallocate($image, 255, 255, 255));
        imagecopyresampled($image, $qr, $x + (int) (($box - $size) / 2), $y + (int) (($box - $size) / 2), 0, 0, $size, $size, imagesx($qr), imagesy($qr));
        imagedestroy($qr);
    }

    protected function text(
        $image,
        ?string $text,
        int $x,
        int $y,
        int $size,
        int $color,
        int $maxWidth,
        bool $wrap = false,
        string $align = 'left',
        bool $small = false,
    ): int {
        $text = trim((string) $text);
        if ($text === '') {
            return $y;
        }

        $font = config('roicard.signature_cards.font_path');
        if (! $font || ! is_file($font)) {
            $font = null;
        }

        $lines = $wrap ? $this->wrap($text, $maxWidth, $size, $font) : [$text];
        $lineHeight = $font ? (int) ($size * 1.35) : 18;

        foreach (array_slice($lines, 0, $small ? 2 : 4) as $line) {
            if ($font) {
                $bbox = imagettfbbox($size, 0, $font, $line);
                $lineWidth = abs($bbox[2] - $bbox[0]);
                $drawX = match ($align) {
                    'center' => $x + (int) (($maxWidth - $lineWidth) / 2),
                    'right' => $x + $maxWidth - $lineWidth,
                    default => $x,
                };
                imagettftext($image, $size, 0, $drawX, $y + $size, $color, $font, $line);
                $y += $lineHeight;
            } else {
                $fontNumber = $small ? 3 : 5;
                $charWidth = imagefontwidth($fontNumber);
                $lineWidth = $charWidth * strlen($line);
                $drawX = match ($align) {
                    'center' => $x + (int) (($maxWidth - $lineWidth) / 2),
                    'right' => $x + $maxWidth - $lineWidth,
                    default => $x,
                };
                imagestring($image, $fontNumber, $drawX, $y, $line, $color);
                $y += 20;
            }
        }

        return $y;
    }

    /**
     * @return array<int, string>
     */
    protected function wrap(string $text, int $maxWidth, int $size, ?string $font): array
    {
        $words = preg_split('/\s+/', $text) ?: [];
        $lines = [];
        $current = '';

        foreach ($words as $word) {
            $candidate = $current === '' ? $word : $current . ' ' . $word;
            $width = $font
                ? abs(imagettfbbox($size, 0, $font, $candidate)[2] - imagettfbbox($size, 0, $font, $candidate)[0])
                : imagefontwidth(5) * strlen($candidate);

            if ($width > $maxWidth && $current !== '') {
                $lines[] = $current;
                $current = $word;
            } else {
                $current = $candidate;
            }
        }

        if ($current !== '') {
            $lines[] = $current;
        }

        return $lines;
    }

    /**
     * Media library can use a remote disk in production. Signature rendering
     * only supports local paths; return null rather than throwing.
     */
    protected function safeMediaPath(?Media $media): ?string
    {
        if (! $media) {
            return null;
        }

        try {
            $path = $media->getPath();
        } catch (\Throwable $e) {
            return null;
        }

        return $path && is_file($path) ? $path : null;
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    protected function altText(array $snapshot, Profile $profile): string
    {
        $name = $snapshot['name'];
        $role = $snapshot['title'] ? ', ' . $snapshot['title'] : '';
        $org = $snapshot['organisation'] ? ' at ' . $snapshot['organisation'] : '';

        return "Signature card for {$name}{$role}{$org}. QR code opens {$snapshot['profile_url']} with the signature entry source.";
    }

    protected function allocate($image, string $hex): int
    {
        $hex = ltrim($hex, '#');
        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }

        return imagecolorallocate(
            $image,
            hexdec(substr($hex, 0, 2)),
            hexdec(substr($hex, 2, 2)),
            hexdec(substr($hex, 4, 2)),
        );
    }

    protected function alpha($image, int $color, int $alpha): int
    {
        $r = ($color >> 16) & 0xFF;
        $g = ($color >> 8) & 0xFF;
        $b = $color & 0xFF;

        return imagecolorallocatealpha($image, $r, $g, $b, $alpha);
    }
}
