{{-- Membrete de la agencia — estilos compartidos por la cotización y las
     condiciones generales (09-oct-2026). Va DENTRO de <style>. Variables:
     ver MembreteAgenciaPdfService::datos(). --}}
        @page {
            /* Hallazgo del usuario (06-sep-2026) — el margen top/bottom
               reserva exactamente el alto del header/footer FIJO (ver
               MembreteAgenciaPdfService::alturaHeaderMm()/alturaFooterMm()),
               para que el contenido normal nunca se superponga con la
               banda fija. */
            margin: {{ $alturaHeaderMm }}mm 12mm {{ $alturaFooterMm }}mm 12mm;
        }

        /* ── Header/footer FIJOS (hoja membretada real) ────────────
           position:fixed dentro del margen de @page se repite en CADA
           página en DomPDF (misma técnica ya usada en
           reporte-operativo.blade.php, ".marca-generacion"). Offset
           lateral negativo = bleed hasta el borde físico de la hoja,
           igual que el membrete real de la agencia (sin franja blanca
           a los costados). */
        .header-fijo {
            position: fixed;
            top: -{{ $alturaHeaderMm }}mm;
            left: -12mm;
            right: -12mm;
        }

        .footer-fijo {
            position: fixed;
            bottom: -{{ $alturaFooterMm }}mm;
            left: -12mm;
            right: -12mm;
        }

        .header-fijo img,
        .footer-fijo img {
            width: 100%;
            display: block;
        }

        .header-fijo .header-generado,
        .footer-fijo .footer-generado {
            padding: 0 12mm;
        }

        /* ── Header generado (sin membrete propio) ──────────────── */
        .header-wrap {
            border-bottom: 1px solid #111111;
            padding-bottom: 16px;
        }

        .logo-box {
            width: 170px;
            height: 70px;
            border: 1px dashed #cccccc;
            text-align: center;
            color: #999999;
            font-size: 11px;
            padding-top: 28px;
        }

        .empresa-nombre {
            font-family: 'Poppins-SemiBold', 'Poppins', sans-serif;
            font-weight: bold;
            font-size: 14px;
            text-transform: uppercase;
        }

        .empresa-datos {
            font-size: 12px;
            line-height: 1.5;
        }

        .afiliaciones-franja {
            margin-top: 8px;
            padding-top: 6px;
            border-top: 1px solid #cccccc;
            font-size: 9px;
            color: #666666;
            text-align: center;
        }

        .afiliaciones-franja img {
            max-height: 24px;
            margin: 0 6px;
            vertical-align: middle;
        }

        /* ── Footer ─────────────────────────────────────────────── */
        .footer-legal {
            margin-top: 18px;
            border: 1px solid #999999;
            padding: 10px 14px;
            font-size: 11px;
            line-height: 1.6;
            text-align: center;
            color: #444444;
        }

        .footer-marca {
            margin-top: 14px;
            text-align: center;
            font-size: 10px;
            color: #666666;
        }

        .footer-marca .eslogan {
            font-style: italic;
            margin-bottom: 2px;
        }
