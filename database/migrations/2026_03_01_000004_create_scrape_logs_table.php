<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scrape_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('category_id')->nullable()->constrained()->nullOnDelete();
            $table->string('command');
            $table->integer('products_found')->default(0);
            $table->integer('products_created')->default(0);
            $table->integer('products_updated')->default(0);
            $table->integer('errors')->default(0);
            $table->json('error_details')->nullable();
            $table->integer('duration_seconds')->nullable();
            $table->timestamp('created_at')->nullable();
        });
    }
};
