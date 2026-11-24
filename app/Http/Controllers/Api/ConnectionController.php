<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ConnectionRequest;
use App\Models\Connection;
use App\Models\Profile;
use App\Models\User;
use App\Notifications\ConnectionApprovedNotification;
use App\Notifications\ConnectionRequestNotification;
use App\Services\AnalyticsService;
use App\Services\BlockService;
use App\Services\EntitlementService;
use App\Services\MemberConnectionService;
use App\Services\ProfileVisibilityService;
use App\Support\PlanLimitKey;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ConnectionController extends Controller
{
    public function __construct(
        protected AnalyticsService $analyticsService,
        protected ProfileVisibilityService $visibility,
        protected EntitlementService $entitlements,
        protected BlockService $blocks,
        protected MemberConnectionService $memberConnections,
    ) {}

    /**
     * List every connection row that involves the authenticated user — either
     * as the receiving member or as the requesting guest. Each row carries a
     * `direction` field so the client can render the other party correctly:
     * - "received": the auth user is the profile owner (member_id)
     * - "sent":     the auth user submitted the request (guest_user_id)
     *
     * After acceptance the established connection is visible from both sides,
     * and each side links through to the other party's real public profile.
     */
    public function index(): JsonResponse
    {
        $userId = auth()->id();
        $user = auth()->user();
        $blocked = $this->blocks->blockedUserIds($user);

        $connections = Connection::where(function ($query) use ($userId) {
            $query->where('member_id', $userId)->orWhere('guest_user_id', $userId);
        })
            ->when($blocked !== [], function ($query) use ($blocked) {
                $query->whereNotIn('member_id', $blocked)
                    ->where(function ($inner) use ($blocked) {
                        $inner->whereNull('guest_user_id')->orWhereNotIn('guest_user_id', $blocked);
                    });
            })
            ->with([
                'member:id,first_name,last_name,email',
                'member.profile:id,user_id,slug,title,organisation',
                'member.profile.media',
                'guestUser:id,first_name,last_name,email',
                'guestUser.profile:id,user_id,slug,title,organisation',
                'guestUser.profile.media',
                'senderProfile:id,user_id,slug,display_name,title,organisation',
                'recipientProfile:id,user_id,slug,display_name,title,organisation',
            ])
            ->orderBy('created_at', 'desc')
            ->paginate(20);

        $connections->getCollection()->transform(function (Connection $connection) use ($userId) {
            $connection->direction = $connection->member_id === $userId ? 'received' : 'sent';
            return $connection;
        });

        return response()->json(['connections' => $connections]);
    }

    public function store(ConnectionRequest $request): JsonResponse
    {
        $profile = Profile::where('slug', $request->slug)->firstOrFail();
        $member = $profile->user;
        $viewer = auth('sanctum')->user();

        // A signed-in member must use the member-request path even when they
        // submit the public guest form. This applies the per-profile request
        // policy, matching-only rule, block checks and member request limits
        // instead of letting the guest form bypass them.
        if ($viewer !== null) {
            $senderProfile = $viewer->defaultProfile();

            if (! $senderProfile) {
                return response()->json([
                    'message' => 'Create a profile before sending connection requests.',
                ], 422);
            }

            $connection = $this->memberConnections->sendRequest(
                $viewer,
                $profile,
                $request->guest_intent,
                null,
                [],
                $senderProfile,
            );

            return response()->json([
                'connection' => $connection->load(['senderProfile', 'recipientProfile']),
                'message' => 'Connection request sent',
            ], 201);
        }

        // Contactability and visibility are independent controls, but both
        // must pass before a connection request can reach the member.
        if (! $this->visibility->canContact($viewer, $profile)) {
            return response()->json([
                'message' => 'This member is not accepting connection requests right now.',
            ], 403);
        }

        // "No requests" applies to every entry point, including the public
        // guest form. Community/matching policies remain member-only rules
        // because a guest cannot prove membership or a signal match.
        if ($profile->connection_request_policy === 'none') {
            return response()->json([
                'message' => 'This member is not accepting connection requests right now.',
            ], 403);
        }

        // Guest requests are linked to an account when the supplied email
        // belongs to a member, so that account's inbox shows the request too.
        $guestUserId = User::where('email', $request->guest_email)->value('id');

        $this->assertGuestNotInDeclineCooldown(
            (int) $member->id,
            $guestUserId ? (int) $guestUserId : null,
            $request->guest_email,
        );

        $requesterUser = $viewer ?? User::find($guestUserId);
        if ($requesterUser && $this->blocks->isBlockedBetween($requesterUser, (int) $member->id)) {
            return response()->json([
                'message' => 'This member is not available for connection requests.',
            ], 422);
        }

        // Enforce the plan's daily connection-request allowance centrally.
        $requester = $viewer ?? User::find($guestUserId);
        if ($requester) {
            $todayCount = Connection::whereDate('created_at', now()->toDateString())
                ->where(function ($query) use ($requester, $request) {
                    $query->where('guest_user_id', $requester->id)
                        ->orWhere('guest_email', $request->guest_email);
                })
                ->count();

            $this->entitlements->ensureWithinLimit(
                $requester,
                PlanLimitKey::CONNECTION_REQUESTS_PER_DAY,
                $todayCount,
                1,
                'Your plan does not allow more connection requests today.',
            );
        }

        $connection = Connection::create([
            'member_id' => $member->id,
            'guest_user_id' => $guestUserId,
            'guest_name' => $request->guest_name,
            'guest_email' => $request->guest_email,
            'guest_phone' => $request->guest_phone,
            'guest_org' => $request->guest_org,
            'guest_meeting_context' => $request->guest_meeting_context,
            'guest_introduction' => $request->guest_introduction,
            'guest_intent' => $request->guest_intent,
            'status' => 'pending',
        ]);

        $member->notify(new ConnectionRequestNotification($connection));

        $this->analyticsService->record($member->id, 'connection_request', [
            'connection_id' => $connection->id,
            'from_member' => (bool) $guestUserId,
        ]);

        return response()->json([
            'connection' => $connection,
            'message' => 'Connection request sent',
        ], 201);
    }

    /**
     * Apply the same declined-request cooldown to the public guest form that
     * member-to-member requests use. Identity is the linked account when
     * available, otherwise the normalized guest email.
     */
    protected function assertGuestNotInDeclineCooldown(int $memberId, ?int $guestUserId, ?string $guestEmail): void
    {
        $cooldownDays = max(0, (int) config('roicard.connections.declined_cooldown_days', 30));

        if ($cooldownDays === 0) {
            return;
        }

        $email = mb_strtolower(trim((string) $guestEmail));

        if ($guestUserId === null && $email === '') {
            return;
        }

        $declined = Connection::query()
            ->where('member_id', $memberId)
            ->where('status', Connection::STATUS_DECLINED)
            ->whereNotNull('responded_at')
            ->where(function ($query) use ($guestUserId, $email) {
                if ($guestUserId !== null) {
                    $query->where('guest_user_id', $guestUserId);
                }

                if ($email !== '') {
                    $method = $guestUserId !== null ? 'orWhereRaw' : 'whereRaw';
                    $query->{$method}('LOWER(guest_email) = ?', [$email]);
                }
            })
            ->orderByDesc('responded_at')
            ->first();

        $canRetryAt = $declined?->responded_at?->copy()->addDays($cooldownDays);

        if ($canRetryAt && $canRetryAt->isFuture()) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'member' => 'This member declined a recent request. You can try again after '
                    . $canRetryAt->toFormattedDateString() . '.',
            ]);
        }
    }

    public function update(string $id, Request $request): JsonResponse
    {        // Scoped to the authenticated user (member_id): a caller can only act
        // on connection records addressed to their own inbox — foreign IDs
        // resolve to 404 and member_ids from the request body are ignored.
        $connection = Connection::where('member_id', auth()->id())
            ->findOrFail($id);

        $action = $request->input('action', '');

        if ($connection->isMemberRequest()) {
            $connection = match ($action) {
                'approve' => $this->memberConnections->accept($request->user(), $connection),
                'decline' => $this->memberConnections->decline($request->user(), $connection),
                'remove' => $this->memberConnections->remove($request->user(), $connection),
                default => null,
            };

            if (!$connection) {
                return response()->json(['message' => 'Invalid action'], 422);
            }

            return response()->json([
                'connection' => $connection,
                'message' => "Connection request {$action}d successfully",
            ]);
        }

        if ($action === 'approve') {
            $connection->approve();
            $connection->forceFill(['responded_at' => now()])->save();

            // Notify the sender that their request was accepted — this is the
            // person who submitted the request (guest), not the member who is
            // pressing approve.
            $connection->load('member:id,first_name,last_name');
            $connection->guestUser?->notify(new ConnectionApprovedNotification($connection));

            $this->analyticsService->record(auth()->id(), 'connection_accepted', [
                'connection_id' => $connection->id,
            ]);
        } elseif ($action === 'decline') {
            $connection->decline();
            $connection->forceFill(['responded_at' => now()])->save();
        } else {
            return response()->json(['message' => 'Invalid action'], 422);
        }

        return response()->json([
            'connection' => $connection,
            'message' => "Connection {$action}d successfully",
        ]);
    }
}