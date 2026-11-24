<?php

use App\Http\Controllers\Api\AdminAuditLogController;
use App\Http\Controllers\Api\AdminBillingController;
use App\Http\Controllers\Api\AdminCardController;
use App\Http\Controllers\Api\AdminCategoryController;
use App\Http\Controllers\Api\AdminCommunityController;
use App\Http\Controllers\Api\AdminController;
use App\Http\Controllers\Api\AdminMessageController;
use App\Http\Controllers\Api\AdminMetricsController;
use App\Http\Controllers\Api\AdminModerationController;
use App\Http\Controllers\Api\AdminOrganizationController;
use App\Http\Controllers\Api\AdminPlanController;
use App\Http\Controllers\Api\AdminReferralController;
use App\Http\Controllers\Api\AiController;
use App\Http\Controllers\Api\AnalyticsController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BillingController;
use App\Http\Controllers\Api\BlockController;
use App\Http\Controllers\Api\BusinessBrandingController;
use App\Http\Controllers\Api\BusinessCatalogController;
use App\Http\Controllers\Api\CardActivationController;
use App\Http\Controllers\Api\CatalogEnquiryController;
use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\CommunityController;
use App\Http\Controllers\Api\ConnectionController;
use App\Http\Controllers\Api\CvDownloadController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\DiscoverController;
use App\Http\Controllers\Api\DiscountCampaignController;
use App\Http\Controllers\Api\GoogleAuthController;
use App\Http\Controllers\Api\InterestOptionController;
use App\Http\Controllers\Api\MemberConnectionController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\NotificationPreferenceController;
use App\Http\Controllers\Api\OnboardingController;
use App\Http\Controllers\Api\OrganizationController;
use App\Http\Controllers\Api\PaymentController;
use App\Http\Controllers\Api\PlanController;
use App\Http\Controllers\Api\ProfileController;
use App\Http\Controllers\Api\ProfileDiscoveryController;
use App\Http\Controllers\Api\ProfileEnrichmentController;
use App\Http\Controllers\Api\ProfileSignalController;
use App\Http\Controllers\Api\PublicProfileController;
use App\Http\Controllers\Api\PushTokenController;
use App\Http\Controllers\Api\QRController;
use App\Http\Controllers\Api\RecommendationController;
use App\Http\Controllers\Api\ReferralController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\SearchController;
use App\Http\Controllers\Api\SignatureCardController;
use App\Http\Controllers\Api\TagController;
use App\Http\Controllers\Api\SmartCardController;
use App\Http\Controllers\Api\SocialAuthController;
use Illuminate\Support\Facades\Route;

// Global baseline throttle for all API traffic
Route::middleware('throttle:api')->group(function () {

/*
|--------------------------------------------------------------------------
| Public Routes (no auth)
|--------------------------------------------------------------------------
*/
Route::post('/auth/register', [AuthController::class, 'register'])->middleware('throttle:register');
Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:login');
Route::get('/auth/google/redirect', [GoogleAuthController::class, 'redirect']);
Route::get('/auth/google/callback', [GoogleAuthController::class, 'callback']);
Route::get('/auth/social/{provider}/redirect', [SocialAuthController::class, 'redirect']);
Route::get('/auth/social/{provider}/callback', [SocialAuthController::class, 'callback']);
Route::get('/auth/verify-email/{id}/{hash}', [AuthController::class, 'verifyEmail'])->name('verification.verify');
Route::post('/auth/email/resend', [AuthController::class, 'resendVerification'])->middleware('throttle:forgot-password');
Route::post('/auth/verify-email/code', [AuthController::class, 'verifyEmailCode'])->middleware('throttle:verify-email');
Route::post('/auth/forgot-password', [AuthController::class, 'forgotPassword'])->middleware('throttle:forgot-password');
Route::post('/auth/reset-password', [AuthController::class, 'resetPassword'])->middleware('throttle:forgot-password');
Route::post('/auth/reactivate', [AuthController::class, 'reactivate'])->middleware('throttle:login');

// Interest options (public)
Route::get('/interests', [InterestOptionController::class, 'index'])->middleware('cache.get');

// Active category and tag taxonomy (public)
Route::get('/categories', [CategoryController::class, 'index'])->middleware('cache.get');
Route::get('/tags', [TagController::class, 'index'])->middleware('cache.get');

// Public profile. Deliberately not wrapped in the page cache: visibility and
// contact-detail redaction are viewer- and state-dependent, so a shared page
// cache could serve a stale or over-permissive response after a privacy
// change. The controller's internal cache stores only the shared base data
// and applies redaction on every request.
Route::get('/public/{slug}', [PublicProfileController::class, 'show']);
Route::get('/public-sitemap', [PublicProfileController::class, 'sitemap']);
Route::post('/public/{slug}/event', [PublicProfileController::class, 'trackEvent']);

// Private/authorized CV download. Stored media is never served by its disk URL.
Route::get('/profiles/{profile}/cv/{media}', [CvDownloadController::class, 'download']);

// Connection request (public, no auth). Throttled beyond the global API limit
// because it creates a row and sends a notification.
Route::post('/connections', [ConnectionController::class, 'store'])->middleware('throttle:connection-request');

// Public organization discovery and directory
Route::get('/organizations', [OrganizationController::class, 'index']);
Route::get('/organizations/{organization:slug}', [OrganizationController::class, 'show']);

// Public catalog enquiry (guests and signed-in members)
Route::post('/catalog/{item}/enquiries', [CatalogEnquiryController::class, 'store'])
    ->middleware('throttle:catalog-enquiry');

// Public meeting booking flow (no auth, rate-limited)
Route::get('/public/{slug}/meeting-types', [\App\Http\Controllers\Api\PublicBookingController::class, 'meetingTypes'])->middleware('throttle:public-booking');
Route::get('/public/{slug}/meeting-types/{meetingType}/slots', [\App\Http\Controllers\Api\PublicBookingController::class, 'slots'])->middleware('throttle:public-booking');
Route::post('/public/{slug}/meeting-types/{meetingType}/book', [\App\Http\Controllers\Api\PublicBookingController::class, 'book'])->middleware('idempotency');

// Public booking cancellation (guest uses token, rate-limited to prevent brute-force)
Route::post('/meetings/cancel/{token}', [\App\Http\Controllers\Api\MeetingBookingController::class, 'cancelByToken'])->middleware('throttle:guest-cancellation');

// QR code (public)
// /qr/image/{slug} serves the SVG for display; /qr/{slug} is the scan entry
// point that records a qr_scan and redirects to the profile. The scan route
// must NOT be cached, or repeated scans would be served from cache and never
// recorded.
Route::get('/qr/image/{slug}', [QRController::class, 'image'])->middleware('cache.get');
Route::get('/qr/{slug}', [QRController::class, 'show']);

// Signed signature-card images (no login needed, short-lived signed URL).
Route::get('/signature-cards/{profile}/{format}/{version}', [SignatureCardController::class, 'image'])
    ->name('signature-cards.image')
    ->middleware('signed');

// Payment webhook (no auth, signature-verified)
Route::post('/payments/webhook/{provider}', [PaymentController::class, 'webhook']);

// Active discount campaigns (public — used by register form)
Route::get('/discount-campaigns/active', [DiscountCampaignController::class, 'active']);

/*
|--------------------------------------------------------------------------
| Authenticated Routes
|--------------------------------------------------------------------------
*/
// Signed notification unsubscribe link. Kept outside auth so it works from an
// email client; the signature binds the member, type and channel.
Route::get('/notifications/unsubscribe/{user}/{type}/{channel}', [NotificationPreferenceController::class, 'unsubscribe'])
    ->middleware('signed')
    ->name('notifications.unsubscribe');

Route::middleware(['auth:sanctum', 'full.access', 'prevent_leak'])->group(function () {
    // Current authenticated user (used to validate the cached session on boot)
    Route::get('/me', [AuthController::class, 'me']);

    // Account & security
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::post('/auth/two-factor/verify', [AuthController::class, 'verifyTwoFactor'])->middleware('throttle:two-factor');
    Route::post('/auth/change-password', [AuthController::class, 'changePassword'])->middleware('throttle:login');
    Route::put('/auth/account', [AuthController::class, 'updateAccount']);
    Route::get('/auth/two-factor/status', [AuthController::class, 'twoFactorStatus']);
    Route::post('/auth/two-factor/setup', [AuthController::class, 'twoFactorSetup'])->middleware('throttle:two-factor');
    Route::post('/auth/two-factor/confirm', [AuthController::class, 'twoFactorConfirm'])->middleware('throttle:two-factor');
    Route::post('/auth/two-factor/disable', [AuthController::class, 'twoFactorDisable'])->middleware('throttle:two-factor');
    Route::post('/auth/deactivate', [AuthController::class, 'deactivate']);
    Route::get('/auth/account/export', [AuthController::class, 'exportData'])->middleware('throttle:account-export');
    Route::delete('/auth/account', [AuthController::class, 'deleteAccount']);

    // AI status, consent and Section 7 workflows (Gemini, server-only key).
    Route::get('/ai/status', [AiController::class, 'status']);
    Route::post('/ai/consent', [AiController::class, 'consent']);

    // Profile (MVP 1 default-profile endpoints)
    Route::post('/profile', [ProfileController::class, 'store']);
    Route::put('/profile', [ProfileController::class, 'update']);
    Route::post('/profile/complete', [ProfileController::class, 'complete']);
    Route::get('/profile', [ProfileController::class, 'show']);
    Route::post('/profile/avatar', [ProfileController::class, 'uploadAvatar']);

    // Multiple profiles (MVP 2)
    Route::get('/profiles', [ProfileController::class, 'index']);
    Route::post('/profiles', [ProfileController::class, 'storeProfile']);
    Route::get('/profiles/discover', [ProfileDiscoveryController::class, 'index'])->middleware('throttle:search');
    Route::get('/profiles/{profile}', [ProfileController::class, 'showProfile'])->middleware('owns');
    Route::patch('/profiles/{profile}', [ProfileController::class, 'updateProfile'])->middleware('owns');
    Route::put('/profiles/{profile}', [ProfileController::class, 'updateProfile'])->middleware('owns');
    Route::delete('/profiles/{profile}', [ProfileController::class, 'destroyProfile'])->middleware('owns');
    Route::post('/profiles/{profile}/default', [ProfileController::class, 'setDefaultProfile'])->middleware('owns');
    Route::post('/profiles/{profile}/avatar', [ProfileController::class, 'uploadProfileAvatar'])->middleware('owns');
    Route::post('/profiles/{profile}/logo', [BusinessBrandingController::class, 'uploadLogo'])->middleware('owns');
    Route::post('/profiles/{profile}/cover', [BusinessBrandingController::class, 'uploadCover'])->middleware('owns');    Route::post('/profiles/{profile}/publish', [ProfileController::class, 'publish'])->middleware('owns');
    Route::post('/profiles/{profile}/unpublish', [ProfileController::class, 'unpublish'])->middleware('owns');
    Route::post('/profiles/{profile}/ai-draft/request', [ProfileController::class, 'requestAiDraft'])->middleware(['owns', 'throttle:ai']);
    Route::patch('/profiles/{profile}/ai-draft/approve', [ProfileController::class, 'approveAiDraft'])->middleware('owns');
    Route::post('/profiles/{profile}/ai-draft/reuse', [AiController::class, 'suggestReuse'])->middleware(['owns', 'throttle:ai']);
    Route::post('/profiles/{profile}/ai-draft/catalog', [AiController::class, 'requestCatalogDraft'])->middleware(['owns', 'throttle:ai']);
    Route::patch('/profiles/{profile}/ai-draft/catalog/items/{itemId}', [AiController::class, 'updateCatalogDraftItem'])->middleware('owns');
    Route::delete('/profiles/{profile}/ai-draft/catalog/items/{itemId}', [AiController::class, 'deleteCatalogDraftItem'])->middleware('owns');
    Route::post('/profiles/{profile}/ai-draft/catalog/approve', [AiController::class, 'approveCatalogDraftItems'])->middleware('owns');
    Route::put('/profiles/{profile}/categories', [ProfileController::class, 'updateCategories'])->middleware('owns');

    // Seeking / Offering signal items
    Route::get('/profiles/{profile}/signals', [ProfileSignalController::class, 'index'])->middleware('owns');
    Route::post('/profiles/{profile}/signals', [ProfileSignalController::class, 'store'])->middleware('owns');
    Route::patch('/profiles/{profile}/signals/{signal}', [ProfileSignalController::class, 'update'])->middleware('owns');
    Route::delete('/profiles/{profile}/signals/{signal}', [ProfileSignalController::class, 'destroy'])->middleware('owns');
    Route::post('/profiles/{profile}/signals/{signal}/pause', [ProfileSignalController::class, 'pause'])->middleware('owns');
    Route::post('/profiles/{profile}/signals/{signal}/resume', [ProfileSignalController::class, 'resume'])->middleware('owns');

    // Business catalog (owner)
    Route::get('/profiles/{profile}/catalog', [BusinessCatalogController::class, 'index'])->middleware('owns');
    Route::post('/profiles/{profile}/catalog', [BusinessCatalogController::class, 'store'])->middleware('owns');
    Route::post('/profiles/{profile}/catalog/reorder', [BusinessCatalogController::class, 'reorder'])->middleware('owns');
    Route::patch('/profiles/{profile}/catalog/{item}', [BusinessCatalogController::class, 'update'])->middleware('owns');
    Route::delete('/profiles/{profile}/catalog/{item}', [BusinessCatalogController::class, 'destroy'])->middleware('owns');
    Route::post('/profiles/{profile}/catalog/{item}/activate', [BusinessCatalogController::class, 'activate'])->middleware('owns');
    Route::post('/profiles/{profile}/catalog/{item}/deactivate', [BusinessCatalogController::class, 'deactivate'])->middleware('owns');
    Route::post('/profiles/{profile}/catalog/{item}/images', [BusinessCatalogController::class, 'uploadImages'])->middleware('owns');
    Route::delete('/profiles/{profile}/catalog/{item}/images/{media}', [BusinessCatalogController::class, 'destroyImage'])->middleware('owns');
    Route::get('/profiles/{profile}/catalog-enquiries', [BusinessCatalogController::class, 'enquiries'])->middleware('owns');
    Route::patch('/profiles/{profile}/catalog-enquiries/{enquiry}/read', [BusinessCatalogController::class, 'markEnquiryRead'])->middleware('owns');

    // Discover, recommendations, search
    Route::get('/discover', [DiscoverController::class, 'index'])->middleware('throttle:search');
    Route::get('/recommendations', [RecommendationController::class, 'index']);
    Route::post('/recommendations/{recommended}/dismiss', [RecommendationController::class, 'dismiss']);
    Route::post('/recommendations/{recommended}/not-relevant', [RecommendationController::class, 'notRelevant']);
    Route::get('/search', [SearchController::class, 'index'])->middleware('throttle:search');
    Route::post('/search/interpret', [AiController::class, 'interpretSearch'])->middleware(['throttle:search', 'throttle:ai']);

    // Plans, billing, cards, referrals and signature cards
    Route::get('/plan', [PlanController::class, 'index']);
    Route::post('/billing/subscribe', [BillingController::class, 'subscribe'])->middleware('throttle:payment-initiate');
    Route::post('/billing/cancel', [BillingController::class, 'cancel']);
    Route::post('/billing/resume', [BillingController::class, 'resume']);
    Route::get('/billing/history', [BillingController::class, 'history']);

    Route::get('/cards/status', [CardActivationController::class, 'status']);
    Route::post('/cards/activate', [CardActivationController::class, 'initiate'])->middleware('throttle:payment-initiate');
    Route::post('/cards/{smartCard}/deactivate', [CardActivationController::class, 'deactivate']);

    Route::get('/referrals', [ReferralController::class, 'index']);
    Route::patch('/referrals/settings', [ReferralController::class, 'settings']);

    Route::get('/profiles/{profile}/signature-cards', [SignatureCardController::class, 'index'])->middleware('owns');
    Route::post('/profiles/{profile}/signature-cards/{format}', [SignatureCardController::class, 'generate'])
        ->middleware(['owns', 'throttle:signature-card']);
    Route::patch('/profiles/{profile}/signature-cards/settings', [SignatureCardController::class, 'settings'])->middleware('owns');

    // Member-to-member connection requests
    Route::post('/member-connections', [MemberConnectionController::class, 'store'])->middleware('throttle:connection-request');
    Route::patch('/member-connections/{connection}/accept', [MemberConnectionController::class, 'accept']);
    Route::patch('/member-connections/{connection}/decline', [MemberConnectionController::class, 'decline']);
    Route::patch('/member-connections/{connection}/withdraw', [MemberConnectionController::class, 'withdraw']);
    Route::delete('/member-connections/{connection}', [MemberConnectionController::class, 'remove']);

    // Blocking and reporting
    Route::get('/blocks', [BlockController::class, 'index']);
    Route::post('/blocks', [BlockController::class, 'store'])->middleware('throttle:block');
    Route::delete('/blocks/{userId}', [BlockController::class, 'destroy'])->middleware('throttle:block');
    Route::post('/reports', [ReportController::class, 'store'])->middleware('throttle:report');

    // Phase 2 setup tasks (server-backed progress)
    Route::get('/onboarding', [OnboardingController::class, 'status']);
    Route::patch('/onboarding/tasks/{task}', [OnboardingController::class, 'updateTask']);
    Route::post('/onboarding/goals', [OnboardingController::class, 'saveGoals']);
    Route::delete('/onboarding/goals', [OnboardingController::class, 'dismissGoals']);
    Route::post('/onboarding/setup-card/dismiss', [OnboardingController::class, 'dismissSetupCard']);

    // Communities (members)
    Route::get('/communities', [CommunityController::class, 'index']);
    Route::get('/communities/mine', [CommunityController::class, 'mine']);
    Route::get('/communities/{community}', [CommunityController::class, 'show']);
    Route::post('/communities/{community}/join', [CommunityController::class, 'join']);
    Route::patch('/communities/{community}/membership', [CommunityController::class, 'updateMembership']);
    Route::delete('/communities/{community}/leave', [CommunityController::class, 'leave']);

    // Organizations (registration + workspace)
    Route::get('/my-organizations', [OrganizationController::class, 'mine']);
    Route::post('/organizations', [OrganizationController::class, 'store']);
    Route::patch('/organizations/{organization:slug}', [OrganizationController::class, 'update']);
    Route::post('/organizations/{organization:slug}/logo', [OrganizationController::class, 'uploadLogo']);
    Route::post('/organizations/{organization:slug}/cover', [OrganizationController::class, 'uploadCover']);
    Route::get('/organizations/{organization:slug}/members', [OrganizationController::class, 'members']);
    Route::post('/organizations/{organization:slug}/members', [OrganizationController::class, 'addMember'])
        ->middleware('throttle:organization-invite');
    Route::delete('/organizations/{organization:slug}/members/{membership}', [OrganizationController::class, 'removeMember']);
    Route::post('/organizations/{organization:slug}/members/{membership}/approve', [OrganizationController::class, 'approveMembership']);
    Route::post('/organizations/{organization:slug}/members/{membership}/reject', [OrganizationController::class, 'rejectMembership']);
    Route::post('/organizations/{organization:slug}/members/{membership}/accept', [OrganizationController::class, 'acceptAffiliation']);
    Route::post('/organizations/{organization:slug}/invitations', [OrganizationController::class, 'invite'])
        ->middleware('throttle:organization-invite');
    Route::delete('/organizations/{organization:slug}/invitations/{invitation}', [OrganizationController::class, 'revokeInvitation']);
    Route::post('/organizations/{organization:slug}/join', [OrganizationController::class, 'requestJoin']);
    Route::get('/organization-invitations', [OrganizationController::class, 'myInvitations']);
    Route::post('/organization-invitations/{token}/accept', [OrganizationController::class, 'acceptInvitation']);

    // Profile Enrichment (Screens 12-14)
    Route::post('/profile/cv', [ProfileEnrichmentController::class, 'uploadCv']);
    Route::delete('/profile/cv/{media}', [ProfileEnrichmentController::class, 'deleteCv'])->middleware('owns');
    Route::post('/profile/education', [ProfileEnrichmentController::class, 'storeEducation']);
    Route::patch('/profile/education/{education}', [ProfileEnrichmentController::class, 'updateEducation'])->middleware('owns');
    Route::delete('/profile/education/{education}', [ProfileEnrichmentController::class, 'destroyEducation'])->middleware('owns');
    Route::post('/profile/experience', [ProfileEnrichmentController::class, 'storeExperience']);
    Route::patch('/profile/experience/{experience}', [ProfileEnrichmentController::class, 'updateExperience'])->middleware('owns');
    Route::delete('/profile/experience/{experience}', [ProfileEnrichmentController::class, 'destroyExperience'])->middleware('owns');
    Route::post('/profile/achievements', [ProfileEnrichmentController::class, 'storeAchievement']);
    Route::patch('/profile/achievements/{achievement}', [ProfileEnrichmentController::class, 'updateAchievement'])->middleware('owns');
    Route::delete('/profile/achievements/{achievement}', [ProfileEnrichmentController::class, 'destroyAchievement'])->middleware('owns');
    Route::put('/profile/social-links', [ProfileEnrichmentController::class, 'updateSocialLinks']);

    // Payments
    Route::post('/payments/initiate', [PaymentController::class, 'initiate'])->middleware(['throttle:payment-initiate', 'idempotency']);
    Route::post('/payments/mock/confirm', [PaymentController::class, 'confirmMock'])->middleware('throttle:payment-initiate');
    Route::get('/payments/status/{reference}', [PaymentController::class, 'status']);

    // Smart Cards
    Route::post('/smart-cards/delivery', [SmartCardController::class, 'storeDelivery']);

    // Dashboard
    Route::get('/dashboard', [DashboardController::class, 'index']);

    // Connections
    Route::get('/connections', [ConnectionController::class, 'index']);
    Route::patch('/connections/{id}', [ConnectionController::class, 'update']);

    // Notifications
    Route::get('/notifications', [NotificationController::class, 'index']);
    Route::patch('/notifications/{id}/read', [NotificationController::class, 'markRead']);
    Route::get('/notifications/preferences', [NotificationPreferenceController::class, 'show']);
    Route::patch('/notifications/preferences', [NotificationPreferenceController::class, 'update']);
    Route::post('/notifications/push-tokens', [PushTokenController::class, 'store'])->middleware('throttle:block');
    Route::delete('/notifications/push-tokens', [PushTokenController::class, 'destroy'])->middleware('throttle:block');

    // Analytics
    Route::get('/analytics/summary', [AnalyticsController::class, 'summary']);
    Route::post('/analytics/events', [AnalyticsController::class, 'store']);

    // Meetings - Owner
    Route::get('/meeting-types', [\App\Http\Controllers\Api\MeetingTypeController::class, 'index']);
    Route::post('/meeting-types', [\App\Http\Controllers\Api\MeetingTypeController::class, 'store']);
    Route::get('/meeting-types/{meetingType}', [\App\Http\Controllers\Api\MeetingTypeController::class, 'show'])->middleware('owns');
    Route::patch('/meeting-types/{meetingType}', [\App\Http\Controllers\Api\MeetingTypeController::class, 'update'])->middleware('owns');
    Route::delete('/meeting-types/{meetingType}', [\App\Http\Controllers\Api\MeetingTypeController::class, 'destroy'])->middleware('owns');
    Route::get('/meeting-types/{meetingType}/slots', [\App\Http\Controllers\Api\MeetingTypeController::class, 'slots'])->middleware('owns');

    // Meetings - Bookings (owner)
    Route::get('/meetings', [\App\Http\Controllers\Api\MeetingBookingController::class, 'index']);
    Route::get('/meetings/{meetingBooking}', [\App\Http\Controllers\Api\MeetingBookingController::class, 'show'])->middleware('owns');
    Route::patch('/meetings/{meetingBooking}/confirm', [\App\Http\Controllers\Api\MeetingBookingController::class, 'confirm'])->middleware('owns');
    Route::patch('/meetings/{meetingBooking}/decline', [\App\Http\Controllers\Api\MeetingBookingController::class, 'decline'])->middleware('owns');
    Route::patch('/meetings/{meetingBooking}/cancel', [\App\Http\Controllers\Api\MeetingBookingController::class, 'cancel'])->middleware('owns');
    Route::post('/meetings/{meetingBooking}/reschedule', [\App\Http\Controllers\Api\MeetingBookingController::class, 'proposeReschedule'])->middleware('owns');
    Route::patch('/meetings/{meetingBooking}/reschedule/{rescheduleRequest}/accept', [\App\Http\Controllers\Api\MeetingBookingController::class, 'acceptReschedule'])->middleware('owns');
    Route::patch('/meetings/{meetingBooking}/reschedule/{rescheduleRequest}/decline', [\App\Http\Controllers\Api\MeetingBookingController::class, 'declineReschedule'])->middleware('owns');
    Route::get('/meetings/{meetingBooking}/ics', [\App\Http\Controllers\Api\MeetingBookingController::class, 'downloadIcs'])->middleware('owns');

    // Meetings - Blocked dates (owner)
    Route::get('/blocked-dates', [\App\Http\Controllers\Api\MeetingBlockedDateController::class, 'index']);
    Route::post('/blocked-dates', [\App\Http\Controllers\Api\MeetingBlockedDateController::class, 'store']);
    Route::delete('/blocked-dates/{meetingBlockedDate}', [\App\Http\Controllers\Api\MeetingBlockedDateController::class, 'destroy'])->middleware('owns');

    /*
    |--------------------------------------------------------------------------
    | Admin Routes
    |--------------------------------------------------------------------------
    */
    Route::middleware('is_admin')->prefix('admin')->group(function () {
        Route::get('/stats', [AdminController::class, 'stats']);
        Route::get('/stats/trends', [AdminController::class, 'trends']);
        Route::get('/metrics', [AdminMetricsController::class, 'index']);
        Route::get('/users', [AdminController::class, 'users']);
        Route::get('/users/{id}', [AdminController::class, 'showUser']);
        Route::post('/users', [AdminController::class, 'storeUser']);
        Route::patch('/users/{id}', [AdminController::class, 'updateUser']);
        Route::patch('/users/{id}/profile', [AdminController::class, 'updateUserProfile']);
        Route::post('/users/{id}/password-reset', [AdminController::class, 'sendPasswordReset']);
        Route::post('/users/{id}/avatar', [AdminController::class, 'uploadUserAvatar']);
        Route::delete('/users/{id}/avatar', [AdminController::class, 'deleteUserAvatar']);
        Route::delete('/users/{id}', [AdminController::class, 'deleteUser']);
        Route::get('/smart-cards', [AdminController::class, 'smartCards']);
        Route::post('/smart-cards', [AdminController::class, 'registerCard']);
        Route::patch('/smart-cards/{id}/assign', [AdminController::class, 'assignCard']);
        Route::patch('/smart-cards/{id}/unassign', [AdminController::class, 'unassignCard']);
        Route::patch('/smart-cards/{id}/activate', [AdminController::class, 'activateCard']);
        Route::patch('/smart-cards/{id}/deactivate', [AdminController::class, 'deactivateCard']);
        Route::patch('/smart-cards/{id}/dispatch', [AdminController::class, 'dispatchCard']);
        Route::patch('/smart-cards/{id}/deliver', [AdminController::class, 'deliverCard']);
        Route::get('/connections', [AdminController::class, 'connections']);
        Route::get('/activity-log', [AdminController::class, 'activityLog']);
        Route::get('/meetings', [AdminController::class, 'meetings']);

        // Discount campaigns (member pricing)
        Route::get('/discount-campaigns', [DiscountCampaignController::class, 'index']);
        Route::post('/discount-campaigns', [DiscountCampaignController::class, 'store']);
        Route::patch('/discount-campaigns/{id}', [DiscountCampaignController::class, 'update']);

        // MVP 2 categories taxonomy
        Route::get('/categories', [AdminCategoryController::class, 'index']);
        Route::post('/categories', [AdminCategoryController::class, 'store']);
        Route::patch('/categories/{category}', [AdminCategoryController::class, 'update']);
        Route::delete('/categories/{category}', [AdminCategoryController::class, 'destroy']);

        // MVP 2 communities and memberships
        Route::get('/communities', [AdminCommunityController::class, 'index']);
        Route::post('/communities', [AdminCommunityController::class, 'store']);
        Route::get('/communities/{community}', [AdminCommunityController::class, 'show']);
        Route::patch('/communities/{community}', [AdminCommunityController::class, 'update']);
        Route::delete('/communities/{community}', [AdminCommunityController::class, 'destroy']);
        Route::get('/communities/{community}/members', [AdminCommunityController::class, 'members']);
        Route::post('/communities/{community}/members', [AdminCommunityController::class, 'addMember']);
        Route::patch('/communities/{community}/members/{membership}', [AdminCommunityController::class, 'updateMember']);
        Route::delete('/communities/{community}/members/{membership}', [AdminCommunityController::class, 'removeMember']);

        // Organization approval and administration
        Route::get('/organizations', [AdminOrganizationController::class, 'index']);
        Route::post('/organizations', [AdminOrganizationController::class, 'store']);
        Route::get('/organizations/{organization}', [AdminOrganizationController::class, 'show']);
        Route::patch('/organizations/{organization}', [AdminOrganizationController::class, 'update']);
        Route::post('/organizations/{organization}/approve', [AdminOrganizationController::class, 'approve']);
        Route::post('/organizations/{organization}/reject', [AdminOrganizationController::class, 'reject']);
        Route::post('/organizations/{organization}/suspend', [AdminOrganizationController::class, 'suspend']);
        Route::post('/organizations/{organization}/administrators', [AdminOrganizationController::class, 'assignAdministrator']);

        // Billing review
        Route::get('/billing/payments', [AdminBillingController::class, 'payments']);
        Route::get('/billing/subscriptions', [AdminBillingController::class, 'subscriptions']);
        Route::get('/billing/events', [AdminBillingController::class, 'events']);

        // Referral review
        Route::get('/referrals', [AdminReferralController::class, 'index']);
        Route::get('/referrals/{referral}', [AdminReferralController::class, 'show']);
        Route::post('/referrals/{referral}/approve', [AdminReferralController::class, 'approve']);
        Route::post('/referrals/{referral}/void', [AdminReferralController::class, 'void']);
        Route::post('/referrals/{referral}/mark-paid', [AdminReferralController::class, 'markPaid']);

        // Physical card replacement
        Route::post('/smart-cards/{smartCard}/replace', [AdminCardController::class, 'replace']);

        // MVP 2 plans and entitlements
        Route::get('/plans', [AdminPlanController::class, 'index']);
        Route::post('/plans', [AdminPlanController::class, 'store']);
        Route::get('/plans/{plan}', [AdminPlanController::class, 'show']);
        Route::patch('/plans/{plan}', [AdminPlanController::class, 'update']);
        Route::delete('/plans/{plan}', [AdminPlanController::class, 'destroy']);
        Route::put('/plans/{plan}/entitlements', [AdminPlanController::class, 'updateEntitlements']);

        // MVP 2 audit trail
        Route::get('/audit-logs', [AdminAuditLogController::class, 'index']);

        // Safety moderation queue
        Route::get('/reports', [AdminModerationController::class, 'index']);
        Route::get('/reports/{report}', [AdminModerationController::class, 'show']);
        Route::patch('/reports/{report}', [AdminModerationController::class, 'update']);

        // Manual member messages — admins only.
        Route::get('/messages/recipients', [AdminMessageController::class, 'recipients']);
        Route::get('/messages/quota', [AdminMessageController::class, 'quota']);
        Route::get('/messages/duplicate-check', [AdminMessageController::class, 'duplicateCheck']);
        Route::post('/messages/preview', [AdminMessageController::class, 'preview'])->middleware('throttle:admin-message-test');
        Route::post('/messages/test', [AdminMessageController::class, 'sendTest'])->middleware('throttle:admin-message-test');
        Route::post('/messages', [AdminMessageController::class, 'store'])
            ->middleware(['throttle:admin-message-send', 'idempotency']);
        Route::get('/messages', [AdminMessageController::class, 'index']);
        Route::get('/messages/{id}', [AdminMessageController::class, 'show']);
        Route::post('/messages/{id}/retry', [AdminMessageController::class, 'retry'])
            ->middleware(['throttle:admin-message-send', 'idempotency']);
    });
});

});
