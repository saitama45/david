<?php

namespace Database\Support\Migrations;

use Illuminate\Database\Migrations\Migration; 
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

abstract class ProductReferenceTableMigration extends Migration
{
    abstract protected function getTableName(): string;
    
    protected function additionalFields(Blueprint $table): void
    {
        
    }

    public function up(): void
    {
        Schema::create($this->getTableName(), function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('remarks')->nullable();
            $this->additionalFields($table);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->getTableName());
    }
}