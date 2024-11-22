<?php

use Database\Support\Migrations\ProductReferenceTableMigration;

return new class extends ProductReferenceTableMigration
{
    protected function getTableName(): string
    {
        return 'inventory_categories';
    }
};
