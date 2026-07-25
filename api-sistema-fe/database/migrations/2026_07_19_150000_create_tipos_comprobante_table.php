<?php
// database/migrations/2026_07_19_150000_create_tipos_comprobante_table.php
//
// Módulo de series de comprobantes — catálogo de referencia. Central, no
// tenant: mismo criterio ya establecido para note_motivos/tax_configs/
// detraction_codes (catálogo legal SUNAT idéntico para todos los tenants,
// ver CentralConnection en esos modelos). 'NV' (nota de venta) es la única
// fila no-SUNAT de esta tabla, pero su definición tampoco varía por tenant
// (siempre es_documento_sunat=false/activo_greenter=false), así que vive
// junto al resto en vez de crear un mecanismo aparte.
//
// 'codigo' es la PK natural (string) — mismo patrón que note_motivos.codigo/
// detraction_codes.codigo, sin id numérico autoincremental adicional.
//
// activo_greenter=true SOLO en '01'/'03'/'07'/'08' — confirmado leyendo
// GreenterService::getInvoice()/getNote() (los únicos setTipoDoc() reales
// hoy: Invoice para 01/03, Note para 07/08). El resto del Catálogo 01 real
// no tiene ninguna implementación en GreenterService todavía.
//
// Catálogo SUNAT sembrado aquí: SOLO los 14 códigos que se confirmaron
// explícitamente (00, 01-14). El Catálogo 01 real de SUNAT continúa desde
// 15 (Recibo por espectáculos públicos) hasta 99 (Otros) — NO se completan
// esos códigos en esta migración por no tener certeza carácter por carácter
// de cada nombre/uso, y esta es una tabla de referencia legal, no un dato
// de negocio donde adivinar sea aceptable. PENDIENTE: completar 15+ cuando
// se confirme cada código contra la Tabla 10 / Catálogo 01 de SUNAT.
//
// Invariante activo_greenter=true → es_documento_sunat=true forzada con un
// CHECK real de Postgres (no solo confiar en el seeder) — es exactamente el
// caso que rompería el guard de enviarSunat() en SaleController::store().

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tipos_comprobante', function (Blueprint $table) {
            $table->string('codigo', 4)->primary(); // '01'..'14', 'NV'
            $table->string('nombre');
            $table->boolean('es_documento_sunat');
            $table->boolean('activo_greenter')->default(false);
            $table->timestamps();
        });

        DB::statement(
            'ALTER TABLE tipos_comprobante ' .
            'ADD CONSTRAINT chk_activo_greenter_requiere_sunat ' .
            'CHECK (NOT (activo_greenter AND NOT es_documento_sunat))'
        );

        $ahora = now();

        $catalogoSunat = [
            ['codigo' => '00', 'nombre' => 'Otros'],
            ['codigo' => '01', 'nombre' => 'Factura'],
            ['codigo' => '02', 'nombre' => 'Recibo por Honorarios'],
            ['codigo' => '03', 'nombre' => 'Boleta de Venta'],
            ['codigo' => '04', 'nombre' => 'Liquidación de compra'],
            ['codigo' => '05', 'nombre' => 'Boleto de compañía de aviación comercial'],
            ['codigo' => '06', 'nombre' => 'Carta de porte aéreo'],
            ['codigo' => '07', 'nombre' => 'Nota de crédito'],
            ['codigo' => '08', 'nombre' => 'Nota de débito'],
            ['codigo' => '09', 'nombre' => 'Guía de remisión - Remitente'],
            ['codigo' => '10', 'nombre' => 'Recibo por Arrendamiento'],
            ['codigo' => '11', 'nombre' => 'Póliza de Bolsa de Valores/Productos'],
            ['codigo' => '12', 'nombre' => 'Ticket o cinta de máquina registradora'],
            ['codigo' => '13', 'nombre' => 'Documento de bancos/financieras/seguros'],
            ['codigo' => '14', 'nombre' => 'Recibo por servicios públicos'],
        ];

        $activosGreenter = ['01', '03', '07', '08'];

        foreach ($catalogoSunat as $tipo) {
            DB::table('tipos_comprobante')->insert([
                'codigo'             => $tipo['codigo'],
                'nombre'             => $tipo['nombre'],
                'es_documento_sunat' => true,
                'activo_greenter'    => in_array($tipo['codigo'], $activosGreenter, true),
                'created_at'         => $ahora,
                'updated_at'         => $ahora,
            ]);
        }

        DB::table('tipos_comprobante')->insert([
            'codigo'             => 'NV',
            'nombre'             => 'Nota de venta',
            'es_documento_sunat' => false,
            'activo_greenter'    => false,
            'created_at'         => $ahora,
            'updated_at'         => $ahora,
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('tipos_comprobante');
    }
};
