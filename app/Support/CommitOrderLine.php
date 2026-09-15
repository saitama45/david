<?php

namespace App\Support;

/**
 * One store order line as the Adoption Rate commit report reads it.
 *
 * A declared class instead of the query builder's stdClass rows: a stdClass
 * keeps a hashtable per row, which at the tens of thousands of lines a
 * dashboard range pulls was most of the request's 128MB memory limit. Only
 * the four fields the report reads are kept.
 */
final class CommitOrderLine
{
    public function __construct(
        public readonly int $id,
        public readonly ?string $item_code,
        public readonly ?string $uom,
        public readonly ?string $committed_date,
    ) {}
}
