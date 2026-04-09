<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('address_book_private_working_set_configs', function (Blueprint $table): void {
            $table->boolean('include_owned_sharable_sources')
                ->nullable()
                ->after('hide_shared');
            $table->boolean('require_review_for_self_promotions')
                ->nullable()
                ->after('include_owned_sharable_sources');
        });
    }

    public function down(): void
    {
        Schema::table('address_book_private_working_set_configs', function (Blueprint $table): void {
            $table->dropColumn([
                'include_owned_sharable_sources',
                'require_review_for_self_promotions',
            ]);
        });
    }
};
