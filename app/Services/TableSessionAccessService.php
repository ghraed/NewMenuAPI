<?php

namespace App\Services;

use App\Models\Restaurant;
use App\Models\TableGuestAccess;
use App\Models\TableSession;
use App\Models\TableWave;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class TableSessionAccessService
{
    private const MAX_PIN_ATTEMPTS = 5;
    private const PIN_LOCK_MINUTES = 10;
    private const TOKEN_HEADER = 'X-Guest-Access-Token';
    private const DEVICE_HEADER = 'X-Guest-Device-Id';

    public function __construct(
        private readonly GuestMenuSessionService $guestMenuSessionService
    ) {
    }

    public function verifyPinForTable(Request $request, int $tableNumber, string $pin): array
    {
        $context = $this->guestMenuSessionService->resolveTableContext($tableNumber, $request);
        /** @var TableSession|null $sessionFromContext */
        $sessionFromContext = $context['session'];

        if (! $sessionFromContext) {
            throw new HttpResponseException(response()->json([
                'message' => __('messages.table_sessions.not_active'),
            ], 409));
        }

        $sessionId = $sessionFromContext->id;

        return DB::transaction(function () use ($request, $context, $sessionId, $pin) {
            /** @var TableSession $session */
            $session = TableSession::query()
                ->with(['restaurant', 'restaurantTable'])
                ->whereKey($sessionId)
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertSessionCanBeUnlocked($session);

            if (! $session->pin_hash || ! Hash::check($pin, $session->pin_hash)) {
                $nextAttempts = $session->pin_attempts + 1;
                $lockedUntil = $nextAttempts >= self::MAX_PIN_ATTEMPTS
                    ? now()->addMinutes(self::PIN_LOCK_MINUTES)
                    : null;

                $session->update([
                    'pin_attempts' => $nextAttempts,
                    'pin_locked_until' => $lockedUntil,
                ]);

                throw new HttpResponseException(response()->json([
                    'message' => $lockedUntil
                        ? __('messages.table_sessions.pin_locked')
                        : __('messages.table_sessions.invalid_pin'),
                    'table_session' => $this->guestMenuSessionService->formatSession($session->fresh(['restaurantTable'])),
                ], $lockedUntil ? 423 : 422));
            }

            $token = Str::random(80);
            $now = now();
            $access = TableGuestAccess::query()->create([
                'table_session_id' => $session->id,
                'token_hash' => hash('sha256', $token),
                'device_fingerprint' => $this->resolveDeviceFingerprint($request),
                'joined_at' => $now,
                'last_seen_at' => $now,
                'expires_at' => null,
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ]);

            $session->update([
                'pin_attempts' => 0,
                'pin_locked_until' => null,
                'last_activity_at' => $now,
            ]);

            return [
                'restaurant' => $context['restaurant'],
                'table' => $context['table'],
                'session' => $session->fresh(['restaurantTable']),
                'guest_access' => $access->fresh(),
                'token' => $token,
            ];
        });
    }

    public function findRequestGuestAccess(Request $request, TableSession $expectedSession): ?TableGuestAccess
    {
        $token = $this->extractAccessToken($request);

        if (! $token) {
            return null;
        }

        return $this->resolveValidAccess($token, $expectedSession, false);
    }

    public function authorizeRequestForSession(Request $request, TableSession $expectedSession): TableGuestAccess
    {
        $token = $this->extractAccessToken($request);

        if (! $token) {
            throw $this->authorizationException();
        }

        $access = $this->resolveValidAccess($token, $expectedSession, true);

        if (! $access) {
            throw $this->authorizationException();
        }

        return $access;
    }

    public function authorizeRequestForRestaurant(
        Request $request,
        Restaurant $restaurant,
        ?string $expectedTableReference = null
    ): TableGuestAccess {
        $token = $this->extractAccessToken($request);

        if (! $token) {
            throw $this->authorizationException();
        }

        $access = TableGuestAccess::query()
            ->with(['tableSession.restaurant', 'tableSession.restaurantTable'])
            ->where('token_hash', hash('sha256', $token))
            ->first();

        if (! $access || ! $access->tableSession || $access->tableSession->restaurant_id !== $restaurant->id) {
            throw $this->authorizationException();
        }

        if ($access->revoked_at || ($access->expires_at && $access->expires_at->isPast())) {
            throw $this->authorizationException();
        }

        if ($expectedTableReference !== null && $access->tableSession->restaurantTable?->name !== trim($expectedTableReference)) {
            throw $this->authorizationException();
        }

        $this->assertSessionStillActive($access->tableSession);
        $this->touchAccess($access, $access->tableSession);

        return $access->fresh(['tableSession.restaurantTable']);
    }

    public function resetPin(TableSession $tableSession, ?int $staffUserId = null): array
    {
        return DB::transaction(function () use ($tableSession, $staffUserId) {
            /** @var TableSession $session */
            $session = TableSession::query()
                ->with('restaurantTable')
                ->whereKey($tableSession->id)
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertSessionStillActive($session);

            $pin = $this->guestMenuSessionService->generatePin();
            $now = now();

            $session->update([
                'pin_hash' => Hash::make($pin),
                'pin_attempts' => 0,
                'pin_locked_until' => null,
                'last_activity_at' => $now,
                'created_by_staff_id' => $session->created_by_staff_id ?: $staffUserId,
            ]);

            $this->revokeGuestAccesses($session, 'pin_reset');
            $this->guestMenuSessionService->rememberPlainPin($session, $pin);

            return [
                'session' => $session->fresh(['restaurantTable']),
                'pin' => $pin,
            ];
        });
    }

    public function finalize(TableSession $tableSession, ?int $staffUserId = null, string $reason = 'finalized'): TableSession
    {
        return DB::transaction(function () use ($tableSession, $staffUserId, $reason) {
            /** @var TableSession $session */
            $session = TableSession::query()
                ->with('restaurantTable')
                ->whereKey($tableSession->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($this->guestMenuSessionService->expireSessionIfNeeded($session)) {
                return $session->fresh(['restaurantTable']);
            }

            if ($session->status !== TableSession::STATUS_ACTIVE && $session->status !== TableSession::STATUS_SUSPENDED) {
                return $session;
            }

            $now = now();

            $session->update([
                'status' => TableSession::STATUS_CLOSED,
                'pin_hash' => null,
                'pin_attempts' => 0,
                'pin_locked_until' => null,
                'closed_at' => $now,
                'close_reason' => $reason,
                'finalized_by_staff_id' => $staffUserId,
                'expires_at' => $now,
                'last_activity_at' => $now,
            ]);

            $this->revokeGuestAccesses($session, $reason);

            $session->waves()
                ->where('status', TableWave::STATUS_PENDING)
                ->update([
                    'status' => TableWave::STATUS_RESOLVED,
                    'resolved_by' => $staffUserId,
                    'resolved_at' => $now,
                ]);

            $this->guestMenuSessionService->forgetPlainPin($session);

            return $session->fresh(['restaurantTable']);
        });
    }

    public function buildGuestAccessPayload(TableGuestAccess $access, string $token): array
    {
        return [
            'token' => $token,
            'verified' => true,
            'joined_at' => $access->joined_at?->toIso8601String(),
            'last_seen_at' => $access->last_seen_at?->toIso8601String(),
            'expires_at' => $access->expires_at?->toIso8601String(),
        ];
    }

    private function resolveValidAccess(string $token, TableSession $expectedSession, bool $touch): ?TableGuestAccess
    {
        $access = TableGuestAccess::query()
            ->with(['tableSession.restaurantTable'])
            ->where('token_hash', hash('sha256', $token))
            ->first();

        if (! $access || ! $access->tableSession || $access->table_session_id !== $expectedSession->id) {
            return null;
        }

        if ($access->revoked_at || ($access->expires_at && $access->expires_at->isPast())) {
            return null;
        }

        $this->assertSessionStillActive($access->tableSession);

        if ($touch) {
            $this->touchAccess($access, $access->tableSession);
            return $access->fresh(['tableSession.restaurantTable']);
        }

        return $access;
    }

    private function assertSessionCanBeUnlocked(TableSession $session): void
    {
        $this->assertSessionStillActive($session);

        if ($session->pin_locked_until && $session->pin_locked_until->isFuture()) {
            throw new HttpResponseException(response()->json([
                'message' => __('messages.table_sessions.pin_locked'),
                'table_session' => $this->guestMenuSessionService->formatSession($session),
            ], 423));
        }
    }

    private function assertSessionStillActive(TableSession $session): void
    {
        if ($this->guestMenuSessionService->expireSessionIfNeeded($session)) {
            throw new HttpResponseException(response()->json([
                'message' => __('messages.table_sessions.expired'),
            ], 409));
        }

        if ($session->status === TableSession::STATUS_SUSPENDED) {
            throw new HttpResponseException(response()->json([
                'message' => __('messages.table_sessions.suspended'),
            ], 409));
        }

        if ($session->status !== TableSession::STATUS_ACTIVE) {
            throw new HttpResponseException(response()->json([
                'message' => __('messages.table_sessions.closed'),
            ], 409));
        }
    }

    private function touchAccess(TableGuestAccess $access, TableSession $session): void
    {
        $now = now();

        $access->update([
            'last_seen_at' => $now,
        ]);

        $session->update([
            'last_activity_at' => $now,
        ]);
    }

    private function revokeGuestAccesses(TableSession $session, string $reason): void
    {
        $session->guestAccesses()
            ->whereNull('revoked_at')
            ->update([
                'revoked_at' => now(),
                'revoke_reason' => $reason,
            ]);
    }

    private function extractAccessToken(Request $request): ?string
    {
        $token = trim((string) $request->header(self::TOKEN_HEADER, ''));

        return $token === '' ? null : $token;
    }

    private function resolveDeviceFingerprint(Request $request): ?string
    {
        $rawDeviceId = trim((string) $request->header(self::DEVICE_HEADER, ''));

        if ($rawDeviceId === '') {
            return null;
        }

        return hash('sha256', $rawDeviceId);
    }

    private function authorizationException(): HttpResponseException
    {
        return new HttpResponseException(response()->json([
            'message' => __('messages.table_sessions.authorization_required'),
        ], 403));
    }
}
