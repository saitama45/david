<?php

namespace App\Http\Services\RuleExceptions;

use App\Models\User;
use Carbon\Carbon;

/**
 * Knows one business rule: how to identify its subject, which of its rules can
 * never be waived, and whether an exception is actually needed right now.
 *
 * Denials are thrown as ValidationException so they surface as form errors.
 */
interface RuleEvaluator
{
    /** Validation rules for the subject fields a request must carry. */
    public function inputRules(): array;

    /**
     * Resolve and validate the subject. Throws when it does not exist, is out of
     * the user's reach, or breaks a rule an exception may never lift.
     */
    public function resolve(array $input, User $user): RuleSubject;

    /**
     * Whether the exception is needed right now: for unlock rules the action is
     * currently blocked by time; for excuse rules the item was scored late.
     * Returns null when needed, otherwise why it is not.
     */
    public function notNeededReason(RuleSubject $subject, User $user, Carbon $now): ?string;

    /** Latest moment a grant may stay valid given the subject (unlock only). */
    public function latestValidUntil(RuleSubject $subject, Carbon $now): ?Carbon;
}
