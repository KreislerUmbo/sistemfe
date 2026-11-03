<?php

// T-059 (CRM C4, obs. 16): por dónde llegó un contacto (WhatsApp, Facebook, recomendación…). Va en
// core porque retail lo usará después (respuesta 5 de la obs. 16); por ahora solo lo usa Agencia.
// La agencia lo amplía desde Configuración: se agrega, renombra, ordena y desactiva, nunca se borra
// (lo referencian clientes y cotizaciones). Las filas base las inserta esta misma migración, así
// cubre los tenants que ya existen y los nuevos (patrón de 2026_11_02_300000_create_crm_motivos_resultado).

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const BASE = [
        'WhatsApp', 'Facebook', 'Instagram', 'TikTok', 'Página web', 'Recomendación',
        'Pasó por la oficina', 'Llamada', 'Cliente antiguo', 'Otro',
    ];

    public function up(): void
    {
        Schema::create('crm_origenes_contacto', function (Blueprint $table) {
            $table->comment('Por dónde llegó un contacto (CRM). Se desactiva, nunca se borra.');
            $table->id();
            $table->string('nombre', 80);
            $table->boolean('activo')->default(true);
            $table->unsignedSmallInteger('orden')->default(0);
            $table->timestamps();
        });
        // Único sin distinguir mayúsculas: «whatsapp» y «WhatsApp» son el mismo origen.
        DB::statement('CREATE UNIQUE INDEX crm_origenes_contacto_nombre_unico ON crm_origenes_contacto (LOWER(nombre))');

        $filas = [];
        foreach (self::BASE as $i => $nombre) {
            $filas[] = ['nombre' => $nombre, 'activo' => true, 'orden' => $i + 1, 'created_at' => now(), 'updated_at' => now()];
        }
        DB::table('crm_origenes_contacto')->insert($filas);
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_origenes_contacto');
    }
};
