<?php
// database/migrations/tenant/verticals/agencia-viajes/2026_07_27_190100_create_proveedor_tarifas_table.php
//
// Sesión 5 del vertical Agencia de Viajes — plan-modulo-proveedores.md §2.6
// + plan-modulo-cotizaciones-reservas.md §2.2/§2.3 (reconciliación real:
// proveedor_tarifas cuelga de proveedor_servicio_id, NO de proveedor_id
// directo — único cambio sobre lo que dice §2.2, el resto de esa sección
// sigue vigente sin cambios).
//
// temporada_id: referencia temporadas/temporada_ocurrencias (CENTRAL) — sin
// FK real cross-DB, mismo criterio que proveedor_tipos_id en otras tablas
// de este vertical.
//
// tip_afe_igv: mismo tipo de columna que sale_details.tip_afe_igv
// (2026_06_01_010500_create_sale_details_table.php) — string(2), Catálogo
// 07 SUNAT ('10' gravado, '17' IVAP, '20' exonerado, '30' inafecto, '40'
// exportación), sin cast especial, se compara siempre como string. No se
// inventa un enum nuevo, se reutiliza el mismo tipo ya validado en el core
// de facturación.
//
// edad_max_infante: nullable a propósito, sin default de columna — el
// default real (configuracion_agencia.edad_max_infante) se resuelve a
// nivel de aplicación al crear la tarifa (Sesión 6+, CRUD real).

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('proveedor_tarifas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('proveedor_servicio_id')->constrained('proveedor_servicios');
            $table->string('tipo_tarifa'); // 'corporativa' | 'grupal' | 'publica'
            $table->string('modalidad'); // 'compartido' | 'privado'
            $table->string('moneda'); // 'PEN' | 'USD'
            $table->json('diferenciador')->nullable();
            $table->decimal('precio_costo', 10, 2);
            $table->string('margen_tipo'); // 'porcentaje' | 'fijo'
            $table->decimal('margen_valor', 10, 2);
            $table->decimal('descuento_maximo_pct', 5, 2)->nullable();
            $table->decimal('margen_minimo_pct', 5, 2)->nullable();
            $table->decimal('precio_venta_adulto', 10, 2);
            $table->decimal('precio_venta_nino', 10, 2)->nullable();
            $table->decimal('precio_venta_infante', 10, 2)->nullable();
            $table->unsignedSmallInteger('edad_min_nino')->nullable();
            $table->unsignedSmallInteger('edad_max_nino')->nullable();
            $table->unsignedSmallInteger('edad_max_infante')->nullable();
            $table->unsignedBigInteger('temporada_id')->nullable(); // temporadas.id (central, concepto reutilizable — no una ocurrencia de año puntual) — sin FK real cross-DB. null = tarifa regular/todo el año
            $table->date('vigente_desde');
            $table->date('vigente_hasta')->nullable();
            $table->string('tip_afe_igv', 2); // Catálogo 07 SUNAT, mismo tipo que sale_details.tip_afe_igv
            $table->string('destino_tributario'); // 'amazonia' | 'nacional' | 'extranjero'
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('proveedor_tarifas');
    }
};
