<?php
// database/migrations/2026_07_10_090002_create_notes_table.php
//
// Nota de Crédito (tipo_doc='07') o Nota de Débito (tipo_doc='08').
// Tabla separada de sales/sale_details a propósito — reservarCorrelativo(),
// calcularTotalesComprobante(), isEditable(), scopeFilterMultiple(), y el
// manejo de stock en SaleController asumen que cada fila de sales es una
// venta real; meter notas ahí obligaría a auditar todos esos puntos. Ver
// plan para el detalle de por qué tampoco se dividió en credit_notes/
// debit_notes (Greenter modela NC y ND con la misma clase Note).
//
// Nota sobre llaves foráneas: siguiendo la convención ya usada en las
// migraciones de alter de sales/sale_details de este proyecto (que no usan
// ->constrained()/->foreign() en ningún lado), las referencias a client_id,
// product_id, sale_detail_id y user_id se dejan como columnas indexadas sin
// constraint de BD — la integridad se resuelve a nivel de aplicación, igual
// que el resto del sistema. Sí se agrega FK real a sales.id porque es la
// única relación donde una nota huérfana sería un problema serio de
// integridad, y Sale usa el id bigint autoincremental estándar de Laravel.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notes', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('sale_id');
            $table->foreign('sale_id')->references('id')->on('sales')->onDelete('restrict');

            // ── Identificación de la nota ───────────────────────────────
            $table->string('tipo_doc', 2); // '07' NC, '08' ND

            // ── Referencia al comprobante afectado (congelada al crear) ─
            $table->string('tipo_doc_afectado', 2); // '01' factura, '03' boleta
            $table->string('serie_afectada', 10);
            $table->unsignedInteger('correlativo_afectado');

            // ── Identificación propia de la nota ────────────────────────
            $table->string('serie', 10);
            $table->unsignedInteger('correlativo')->nullable(); // se asigna recién al enviar a SUNAT
            $table->string('n_operacion')->nullable();

            // ── Motivo (Catálogo 09 o 10 SUNAT — ver note_motivos) ──────
            $table->string('cod_motivo', 2);
            $table->string('des_motivo');

            $table->enum('tipo_afectacion', ['total', 'parcial']);

            // ── Cliente y moneda (copiados de la venta al crear) ────────
            $table->unsignedBigInteger('client_id');
            $table->string('cod_tipo_doc_cliente', 1);
            $table->string('currency', 3)->default('PEN');

            // ── Totales para Greenter — calculados en servidor, nunca ───
            // confiados del cliente (mismos nombres/precisión que sales)
            $table->decimal('mto_oper_gravadas', 12, 2)->default(0);
            $table->decimal('mto_oper_exoneradas', 12, 2)->default(0);
            $table->decimal('mto_oper_inafectas', 12, 2)->default(0);
            $table->decimal('mto_oper_exportacion', 12, 2)->default(0);
            $table->decimal('mto_oper_gratuitas', 12, 2)->default(0);
            $table->decimal('mto_igv', 12, 2)->default(0);
            $table->decimal('icbper_total', 12, 2)->default(0);
            $table->decimal('isc_total', 12, 2)->default(0);
            $table->decimal('ivap_total', 12, 2)->default(0);
            $table->decimal('total_impuestos', 12, 2)->default(0);
            $table->decimal('valor_venta', 12, 2)->default(0);
            $table->decimal('mto_imp_venta', 12, 2)->default(0);
            $table->decimal('redondeo', 12, 2)->default(0);

            // ── Reposición de stock ──────────────────────────────────────
            $table->boolean('reponer_stock')->default(false);

            // ── Estado y rechazo SUNAT ───────────────────────────────────
            // 'enviando' es el estado del "claim" atómico al inicio de
            // enviarNotaSunat() — evita que un doble clic dispare dos
            // intentos de envío sobre la misma fila (ver plan, "Idempotencia
            // de envío"). Una nota 'rechazada' nunca vuelve a 'pendiente':
            // hay que crear una nota nueva.
            $table->enum('status', ['pendiente', 'enviando', 'aceptado', 'rechazado'])
                ->default('pendiente');
            $table->string('sunat_error_code')->nullable();
            $table->text('sunat_error_message')->nullable();
            $table->timestamp('sunat_sent_at')->nullable();

            // ── Facturación electrónica ──────────────────────────────────
            $table->string('xml')->nullable();
            $table->string('cdr')->nullable();
            $table->string('hash_cpe')->nullable();

            // ── Auditoría ─────────────────────────────────────────────────
            $table->unsignedBigInteger('user_id'); // quién creó la nota
            $table->unsignedBigInteger('sunat_sent_by_user_id')->nullable(); // quién la envió a SUNAT

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tipo_doc', 'serie', 'correlativo']);
            $table->index('client_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notes');
    }
};
