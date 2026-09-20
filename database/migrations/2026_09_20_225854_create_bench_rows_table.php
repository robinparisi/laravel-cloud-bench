<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bench_rows', function (Blueprint $table) {
            $table->id();
            $table->string('token')->index();
            $table->unsignedInteger('value');
            $table->text('payload');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bench_rows');
    }
};
