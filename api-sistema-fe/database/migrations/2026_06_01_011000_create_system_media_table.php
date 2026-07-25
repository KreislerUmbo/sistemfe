<?php
// Migración correctiva — NO EJECUTAR sin revisión.
// 'system_media' existe en Postgres pero nunca tuvo Schema::create en
// disco. media_type tiene un CHECK real: ('image','video','emoji').

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('system_media', function (Blueprint $table) {
            $table->increments('id'); // real: int4/serial, no bigserial
            $table->unsignedInteger('system_id');
            $table->foreign('system_id')->references('id')->on('systems')->onDelete('cascade');
            $table->enum('media_type', ['image', 'video', 'emoji'])->nullable();
            $table->text('url')->nullable();
            $table->string('emoji_char', 10)->nullable();
            $table->string('title', 100)->nullable();
            $table->boolean('is_primary')->default(false)->nullable();
            $table->integer('display_order')->default(0)->nullable();
        });
        // enum() en pgsql crea varchar(255)+CHECK; el real es varchar(20).
        DB::statement('ALTER TABLE system_media ALTER COLUMN media_type TYPE VARCHAR(20)');
    }

    public function down(): void
    {
        Schema::dropIfExists('system_media');
    }
};
