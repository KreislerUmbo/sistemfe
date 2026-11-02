<?php

// T-050 (CRM C1, obs. 13 y 16): historial de contactos con el cliente — envíos de la cotización,
// llamadas, visitas, notas. Vive en core (por cliente) para no cerrarle la puerta a otros giros;
// la referencia a la cotización o reserva va sin FK porque esas tablas son del vertical Agencia.
//
// Envíos por correo ya hechos: se pasan como actividad `envio`/`correo`, porque el historial del
// cotizador reemplaza a la lista "Envíos por correo" y si no desaparecerían de la pantalla. Solo
// en tenants con Agencia (cotizacion_envios existe).

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_actividades', function (Blueprint $table) {
            $table->comment('Contactos con el cliente: envíos, llamadas, WhatsApp, visitas, notas (CRM).');
            $table->id();
            $table->foreignId('cliente_id')->constrained('clients');
            $table->string('referencia_tipo', 20)->nullable()->comment("'cotizacion' | 'reserva' | null");
            $table->unsignedBigInteger('referencia_id')->nullable()->comment('Id de la cotización o reserva (sin FK: tablas del vertical)');
            $table->unsignedBigInteger('alternativa_id')->nullable()->comment('Alternativa enviada (sin FK: tabla del vertical)');
            $table->string('tipo', 20)->comment("'envio' | 'llamada' | 'whatsapp' | 'visita' | 'nota' | 'estado'");
            $table->string('canal', 20)->nullable()->comment("Solo envíos: 'correo' | 'whatsapp' | 'otro'");
            $table->text('nota')->nullable();
            $table->foreignId('usuario_id')->nullable()->constrained('users');
            $table->timestamps();

            $table->index(['cliente_id', 'created_at']);
            $table->index(['referencia_tipo', 'referencia_id']);
        });

        if (Schema::hasTable('cotizacion_envios') && Schema::hasTable('cotizaciones')) {
            DB::statement(<<<'SQL'
                INSERT INTO crm_actividades (cliente_id, referencia_tipo, referencia_id, alternativa_id, tipo, canal, nota, usuario_id, created_at, updated_at)
                SELECT c.cliente_id, 'cotizacion', e.cotizacion_id, e.alternativa_id, 'envio', 'correo',
                       'Para: ' || (SELECT string_agg(p, ', ') FROM json_array_elements_text(e.para::json) AS p),
                       (SELECT u.id FROM users u WHERE u.id = e.usuario_id), e.created_at, e.created_at
                FROM cotizacion_envios e
                JOIN cotizaciones c ON c.id = e.cotizacion_id
                WHERE e.estado = 'enviado' AND e.tipo = 'cotizacion'
                ORDER BY e.id
                SQL);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_actividades');
    }
};
