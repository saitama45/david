<?php

namespace App\Http\Services\RuleExceptions;

/**
 * The one thing a business-rule exception applies to, as resolved and
 * validated by that rule's evaluator.
 */
final class RuleSubject
{
    /**
     * @param  int[]  $storeBranchIds  every store the requester and approver must be assigned to
     */
    public function __construct(
        public readonly int $storeBranchId,
        public readonly string $subjectKey,
        public readonly array $context = [],
        public readonly array $storeBranchIds = [],
    ) {}

    /** @return int[] */
    public function stores(): array
    {
        return $this->storeBranchIds ?: [$this->storeBranchId];
    }
}
