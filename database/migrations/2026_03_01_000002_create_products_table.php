<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('category_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('brand')->nullable();
            $table->string('asin')->nullable()->index();
            $table->string('barcode')->nullable()->index();
            $table->decimal('price', 8, 2)->nullable();
            $table->string('unit')->nullable();
            $table->string('image_url')->nullable();
            $table->string('whole_foods_url')->nullable();
            $table->timestamp('last_scraped_at')->nullable();
            $table->timestamps();

            $table->unique(['category_id', 'name', 'brand']);
        });
    }
};
