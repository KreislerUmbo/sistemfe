<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Logo vertical (ej. para documentos tipo carta/A4) y horizontal (ej.
// para headers de reportes/PDFs apaisados). Guarda el PATH relativo
// (mismo criterio que Proveedor::logo/fotos), nunca la URL resuelta —
// se resuelve en cada response vía StorageUrl::resolve(), tenant-aware.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->string('logo_vertical')->nullable()->after('n_document');
            $table->string('logo_horizontal')->nullable()->after('logo_vertical');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn(['logo_vertical', 'logo_horizontal']);
        });
    }
};
