<?php

namespace App\Enums;

enum RuleExceptionStatus: string
{
    case PENDING = 'pending';
    case APPROVED = 'approved';
    case REJECTED = 'rejected';
    case CANCELLED = 'cancelled';
    case CONSUMED = 'consumed';
    case EXPIRED = 'expired';

    public function label(): string
    {
        return match ($this) {
            self::PENDING => 'Pending',
            self::APPROVED => 'Approved',
            self::REJECTED => 'Rejected',
            self::CANCELLED => 'Cancelled',
            self::CONSUMED => 'Used',
            self::EXPIRED => 'Expired',
        };
    }

    /**
     * The only legal transitions. Approved is terminal for excuse requests and
     * becomes consumed or expired for unlock grants.
     */
    public function canTransitionTo(self $next, string $type): bool
    {
        return match ($this) {
            self::PENDING => in_array($next, [self::APPROVED, self::REJECTED, self::CANCELLED], true),
            self::APPROVED => $type === 'unlock' && in_array($next, [self::CONSUMED, self::EXPIRED], true),
            default => false,
        };
    }

    /** Statuses that still occupy the "one open request per subject" slot. */
    public static function openValues(): array
    {
        return [self::PENDING->value, self::APPROVED->value];
    }
}
