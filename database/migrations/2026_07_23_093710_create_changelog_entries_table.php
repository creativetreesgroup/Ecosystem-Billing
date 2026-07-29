<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(config('changelog.table', 'changelog_entries'), function (Blueprint $table) {
            $table->id();
            $table->string('project')->nullable()->index();
            $table->string('version')->index();
            $table->date('released_at')->nullable();
            $table->boolean('is_released')->default(false);
            $table->string('type'); // added, changed, deprecated, removed, fixed, security
            $table->text('description');
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();

            $table->index(['project', 'version', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(config('changelog.table', 'changelog_entries'));
    }
};
