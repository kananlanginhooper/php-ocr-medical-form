<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('global_state')) {
            return;
        }
        
        Schema::create('global_state', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('record_id')->unique()->nullable()->comment('faxes_pending.id');
            $table->text('fp_firstname_human')->nullable();
            $table->text('fp_lastname_human')->nullable();
            $table->text('fp_dob_human')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('global_state');
    }
};
