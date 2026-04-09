<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('address_book_private_working_set_links', function (Blueprint $table): void {
            $table->string('dismissed_suggestion_fingerprint')
                ->nullable()
                ->after('overridden_fields');
            $table->timestamp('dismissed_suggestion_at')
                ->nullable()
                ->after('dismissed_suggestion_fingerprint');
        });
    }

    public function down(): void
    {
        Schema::table('address_book_private_working_set_links', function (Blueprint $table): void {
            $table->dropColumn([
                'dismissed_suggestion_fingerprint',
                'dismissed_suggestion_at',
            ]);
        });
    }
};
