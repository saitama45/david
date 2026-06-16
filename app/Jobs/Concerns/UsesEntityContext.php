<?php

namespace App\Jobs\Concerns;

use App\Support\EntityContext;

/**
 * Lets a queued job carry the active entity across the queue boundary.
 *
 * Queue workers have no session, so the global entity scope would otherwise be
 * inactive inside a job — meaning created records get a NULL entity_id and reads
 * are unscoped. Call captureEntityContext() in the job constructor (which runs
 * inside the web request or a parent job, where the context IS set) to snapshot
 * the active entity, then wrap handle()'s body in runWithEntityContext().
 */
trait UsesEntityContext
{
    public ?int $entityId = null;

    protected function captureEntityContext(): void
    {
        $this->entityId = app(EntityContext::class)->id();
    }

    /**
     * Run the callback with the job's entity active. Falls back to $default
     * (e.g. a related ImportLog's entity_id) when the job was constructed
     * without a context, such as console-triggered recovery.
     */
    protected function runWithEntityContext(callable $callback, ?int $default = null)
    {
        return app(EntityContext::class)->runAs($this->entityId ?? $default, $callback);
    }
}
