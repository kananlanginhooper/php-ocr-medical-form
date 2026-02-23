<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('global_state', function (Blueprint $table) {
            if (!Schema::hasColumn('global_state', 'gs_current_image_name')) {
                $table->string('gs_current_image_name', 255)->nullable()->after('id')->comment('Current image/document being processed');
            }
            if (!Schema::hasColumn('global_state', 'fp_firstname_human')) {
                $table->text('fp_firstname_human')->nullable();
            }
            if (!Schema::hasColumn('global_state', 'fp_lastname_human')) {
                $table->text('fp_lastname_human')->nullable();
            }
            if (!Schema::hasColumn('global_state', 'fp_dob_human')) {
                $table->text('fp_dob_human')->nullable();
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('global_state', function (Blueprint $table) {
            if (Schema::hasColumn('global_state', 'gs_current_image_name')) {
                $table->dropColumn('gs_current_image_name');
            }
            if (Schema::hasColumn('global_state', 'fp_firstname_human')) {
                $table->dropColumn('fp_firstname_human');
            }
            if (Schema::hasColumn('global_state', 'fp_lastname_human')) {
                $table->dropColumn('fp_lastname_human');
            }
            if (Schema::hasColumn('global_state', 'fp_dob_human')) {
                $table->dropColumn('fp_dob_human');
            }
        });
    }
};
