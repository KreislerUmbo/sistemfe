<?php

// T-060 (CRM C5, obs. 16): etiquetas de «tipo de viaje» para las preferencias del viajero
// (cliente_perfil_viajero.tipos_viaje). Catálogo del vertical Agencia que la agencia amplía desde
// Configuración: se agrega, renombra, ordena y desactiva, nunca se borra (lo referencian los
// perfiles). Las filas base las inserta esta misma migración, así cubre los tenants que ya existen
// y los nuevos (patrón de 2026_11_03_100000_create_crm_origenes_contacto).

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const BASE = ['Naturaleza', 'Aventura', 'Cultural', 'Gastronomía', 'Familia', 'Luna de miel', 'Relax'];

    public function up(): void
    {
        Schema::create('crm_tipos_viaje', function (Blueprint $table) {
            $table->comment('Tipos de viaje que le gustan a un cliente (CRM). Se desactiva, nunca se borra.');
            $table->id();
            $table->string('nombre', 60);
            $table->boolean('activo')->default(true);
            $table->unsignedSmallInteger('orden')->default(0);
            $table->timestamps();
        });
        // Único sin distinguir mayúsculas: «relax» y «Relax» son el mismo tipo.
        DB::statement('CREATE UNIQUE INDEX crm_tipos_viaje_nombre_unico ON crm_tipos_viaje (LOWER(nombre))');

        $filas = [];
        foreach (self::BASE as $i => $nombre) {
            $filas[] = ['nombre' => $nombre, 'activo' => true, 'orden' => $i + 1, 'created_at' => now(), 'updated_at' => now()];
        }
        DB::table('crm_tipos_viaje')->insert($filas);
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_tipos_viaje');
    }
};
