<?php
// database/migrations/xxxx_alter_sales_add_sunat_fields.php
//
// Agrega los campos que faltan en sales y sale_details
// para cumplir con lo que necesita Greenter y SUNAT.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
               // ════════════════════════════════════════════════
        //  TABLA: sales — campos nuevos
        // ════════════════════════════════════════════════
        Schema::table('sales', function (Blueprint $table) {
 
            // ── Destino del bien o servicio (Ley 27037 Amazonía) ─────────
            // Define si la operación aplica exoneración de IGV por ubicación
            // 'amazonia' → bienes/servicios entregados dentro de la zona Amazonía
            // 'nacional' → salen fuera de la zona, se grava con IGV normal
            // Default 'amazonia' porque el sistema opera en San Martín
            // En empresas fuera de Amazonía cambiar el default a 'nacional'
            $table->enum('destino', ['amazonia', 'nacional'])
                  ->default('amazonia')
                  ->after('is_exportacion');
 
            // ── Tipo de documento del cliente (Catálogo 06 SUNAT) ────────
            // Se copia del cliente al crear la venta para preservar el dato
            // aunque el cliente cambie su documento después
            // '0'=Sin documento  '1'=DNI  '4'=Carnet Extranjería
            // '6'=RUC            '7'=Pasaporte  'A'=Tarjeta Militar
            $table->string('cod_tipo_doc_cliente', 1)
                  ->default('1')
                  ->after('client_id');
 
            // ── Totales por tipo de operación ────────────────────────────
            // Calculados en el frontend y guardados para evitar recalcular
            // en el backend. Greenter los usa directamente en el Invoice.
            // Método Greenter: Invoice→setMtoOperGravadas()
            $table->decimal('mto_oper_gravadas', 12, 2)
                  ->default(0)
                  ->after('igv')
                  ->comment('Greenter: Invoice->setMtoOperGravadas()');
 
            // Método Greenter: Invoice→setMtoOperExoneradas()
            $table->decimal('mto_oper_exoneradas', 12, 2)
                  ->default(0)
                  ->after('mto_oper_gravadas')
                  ->comment('Greenter: Invoice->setMtoOperExoneradas()');
 
            // Método Greenter: Invoice→setMtoOperInafectas()
            $table->decimal('mto_oper_inafectas', 12, 2)
                  ->default(0)
                  ->after('mto_oper_exoneradas')
                  ->comment('Greenter: Invoice->setMtoOperInafectas()');
 
            // Método Greenter: Invoice→setMtoOperExportacion()
            $table->decimal('mto_oper_exportacion', 12, 2)
                  ->default(0)
                  ->after('mto_oper_inafectas')
                  ->comment('Greenter: Invoice->setMtoOperExportacion()');
 
            // ── Regímenes especiales ─────────────────────────────────────
 
            // Retención IGV 3% (solo agentes de retención designados por SUNAT)
            // Calcula el frontend según tax_configs→retencion_pct
            $table->decimal('total_retencion', 12, 2)
                  ->default(0)
                  ->after('retencion_igv');
 
            // Detracción SPOT — Res. 183-2004/SUNAT
            // Código del bien/servicio según Anexos 1,2,3 (ej: '014', '037')
            // Se copia del producto al registrar la venta
            $table->string('codigo_detraccion', 3)
                  ->nullable()
                  ->after('total_retencion');
 
            // Porcentaje de detracción según el código (ej: 0.04 = 4%)
            // Viene de detraction_codes→percentage
            $table->decimal('porcentaje_detraccion', 6, 4)
                  ->default(0)
                  ->after('codigo_detraccion');
 
            // Monto calculado de detracción = mto_imp_venta × porcentaje_detraccion
            $table->decimal('total_detraccion', 12, 2)
                  ->default(0)
                  ->after('porcentaje_detraccion');
 
            // Percepción — Res. 058-2006/SUNAT
            // Porcentaje según régimen: 0.5%, 1%, 2%, 3.5%, 5%
            // Viene de tax_configs→percepcion_pct_*
            $table->decimal('porcentaje_percepcion', 6, 4)
                  ->default(0)
                  ->after('total_detraccion');
 
            // Monto calculado de percepción = mto_imp_venta × porcentaje_percepcion
            $table->decimal('total_percepcion', 12, 2)
                  ->default(0)
                  ->after('porcentaje_percepcion');
        });

        // ══════════════════════════════════════════════════════════
        //  TABLA: sale_details
        //  Campos que Greenter necesita por línea de detalle
        // ══════════════════════════════════════════════════════════
        Schema::table('sale_details', function (Blueprint $table) {

            // Valor bruto de la línea ANTES del descuento = price_base × quantity
            // Greenter: setMtoValorVenta()
            // Diferente a subtotal (que ya tiene el descuento restado)
            $table->decimal('mto_valor_venta', 12, 6)->default(0)->after('subtotal');

            // Base neta sobre la que se calcula el IGV = subtotal (después de descuento)
            // Greenter: setMtoBaseIgv()
            $table->decimal('mto_base_igv', 12, 6)->default(0)->after('mto_valor_venta');

            // Porcentaje de IGV aplicado: 18, 4 (IVAP), o 0
            // Greenter: setPorcentajeIgv()
            $table->decimal('porcentaje_igv', 6, 2)->default(18)->after('mto_base_igv');

            // Total de impuestos por línea = igv + isc + icbper
            // Greenter: setTotalImpuestos() en SaleDetail
            $table->decimal('total_impuestos', 12, 6)->default(0)->after('porcentaje_igv');

            // Régimen del ISC (Catálogo 08 SUNAT)
            // '01' = Al valor (porcentaje sobre base)
            // '02' = Específico (monto fijo por unidad)
            // '03' = Al valor según precio de venta al público
            $table->string('tipo_isc', 2)->default('01')->after('percentage_isc');

            // Monto fijo de ISC por unidad — solo para régimen '02'
            // Ejemplo: S/ 1.23 por litro de combustible
            $table->decimal('monto_isc_fijo', 10, 4)->default(0)->after('tipo_isc');

            // tip_afe_igv ya existe pero aseguramos que sea string, no int
            // (Greenter usa strings: '10', '20', etc. — no enteros)
        });
    }

    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->dropColumn([
                'destino',
                'mto_oper_gravadas',
                'mto_oper_exoneradas',
                'mto_oper_inafectas',
                'mto_oper_exportacion',
                'mto_oper_gratuitas',
                'isc_total',
                'icbper_total',
                'ivap_total',
                'total_impuestos',
                'valor_venta',
                'mto_imp_venta',
                'redondeo',
                'monto_retencion',
                'monto_detraccion',
                'monto_percepcion',
                'codigo_detraccion',
                'porcentaje_detraccion',
                'porcentaje_percepcion',
                'n_transaction',
            ]);
        });

        Schema::table('sale_details', function (Blueprint $table) {
            $table->dropColumn([
                'mto_valor_venta',
                'mto_base_igv',
                'porcentaje_igv',
                'total_impuestos',
                'tipo_isc',
                'monto_isc_fijo',
            ]);
        });
    }
};