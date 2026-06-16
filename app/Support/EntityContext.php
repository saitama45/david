<?php

namespace App\Support;

/**
 * Holds the active Entity for the current request / job lifecycle.
 *
 * Bound as a singleton in AppServiceProvider. The web middleware sets it from
 * the session; queued jobs must set it explicitly (no session is available
 * there). When no entity is set, scoped models are NOT filtered — this keeps
 * console/maintenance contexts working until they opt in.
 */
class EntityContext
{
    protected ?int $entityId = null;

    public function set(?int $entityId): void
    {
        $this->entityId = $entityId;
    }

    public function id(): ?int
    {
        return $this->entityId;
    }

    public function has(): bool
    {
        return ! is_null($this->entityId);
    }

    public function clear(): void
    {
        $this->entityId = null;
    }

    /**
     * Run a callback with a temporary active entity, restoring the previous one
     * afterwards. Useful for cross-entity admin work and queued jobs.
     */
    public function runAs(?int $entityId, callable $callback)
    {
        $previous = $this->entityId;
        $this->entityId = $entityId;

        try {
            return $callback();
        } finally {
            $this->entityId = $previous;
        }
    }
}
