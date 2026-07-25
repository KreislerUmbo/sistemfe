<?php

namespace Database\Seeders;

use App\Models\Cash\PaymentMethod;
use Illuminate\Database\Seeder;

// Módulo Caja — Fase 0 (plan-modulo-caja.md §3). 'code' DEBE copiar exacto
// (mayúsculas/espacios) los valores que register.vue/edit.vue/client-detail.vue
// ya envían hoy en sales.payment_method, para que las ventas históricas sigan
// comparando igual sin migrar datos. Confirmado por grep contra el frontend
// real antes de escribir este seeder — no hay un 6to valor usado ahí.
//
// updateOrCreate por 'code' (idempotente, mismo patrón que DetractionCodeSeeder):
// re-correr este seeder no duplica filas ni pisa 'is_active' si un tenant ya
// desactivó un método manualmente — solo re-sincroniza 'name'/'sort_order'/
// 'affects_cash_count' (estos tres son definición del catálogo, no
// personalización del tenant, a diferencia de 'is_active').
//
// affects_cash_count (Fase 2 de caja, plan-modulo-caja.md §3 la anticipaba
// desde Fase 0/1): solo EFECTIVO cuenta como efectivo físico para el arqueo
// de cash_sessions — TRANSFERENCIA/YAPE/PLIN/TARJETA DE CREDITO quedan
// explícitamente en false, nunca entran al cálculo de expected_cash.
class PaymentMethodSeeder extends Seeder
{
    public function run(): void
    {
        $metodos = [
            ['code' => 'EFECTIVO', 'name' => '💵 Efectivo', 'sort_order' => 1, 'affects_cash_count' => true],
            ['code' => 'TRANSFERENCIA', 'name' => '🏦 Transferencia', 'sort_order' => 2, 'affects_cash_count' => false],
            ['code' => 'YAPE', 'name' => '📱 Yape', 'sort_order' => 3, 'affects_cash_count' => false],
            ['code' => 'PLIN', 'name' => '📱 Plin', 'sort_order' => 4, 'affects_cash_count' => false],
            ['code' => 'TARJETA DE CREDITO', 'name' => '💳 Tarjeta de Crédito', 'sort_order' => 5, 'affects_cash_count' => false],
        ];

        foreach ($metodos as $metodo) {
            PaymentMethod::updateOrCreate(
                ['code' => $metodo['code']],
                [
                    'name' => $metodo['name'],
                    'sort_order' => $metodo['sort_order'],
                    'affects_cash_count' => $metodo['affects_cash_count'],
                ]
            );
        }
    }
}
