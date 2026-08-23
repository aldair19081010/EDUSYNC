/**
 * Script específico para la impresión del reporte de pagos
 * Versión: 2.0 - Corregido extracción de observaciones
 */
function printPaymentReport() {
    // Crear un iframe oculto para la impresión
    var printFrame = document.createElement('iframe');
    printFrame.style.position = 'fixed';
    printFrame.style.right = '0';
    printFrame.style.bottom = '0';
    printFrame.style.width = '0';
    printFrame.style.height = '0';
    printFrame.style.border = '0';

    document.body.appendChild(printFrame);

    var frameWindow = printFrame.contentWindow;
    var frameDocument = frameWindow.document;

    // Abrir el documento
    frameDocument.open();

    // Obtener la información de la tabla y del colegio
    var originalTable = document.getElementById('report-list-table');
    var printBtn = document.getElementById('print_btn');
    var schoolName = printBtn ? printBtn.getAttribute('data-school-name') : 'Institución Educativa';
    var schoolLogo = printBtn ? printBtn.getAttribute('data-school-logo') : '';

    var filterInfo = {
        schoolName: schoolName,
        schoolLogo: schoolLogo,
        reportTitle: 'Reporte de Pagos',
        periodLabel: document.querySelector('.period-label') ?
            document.querySelector('.period-label').textContent.trim() :
            'Todos los Pagos',
        nivel: document.getElementById('nivel_educativo') ?
            document.getElementById('nivel_educativo').options[document.getElementById('nivel_educativo').selectedIndex].text :
            'Todos',
        grado: document.getElementById('grado') ?
            document.getElementById('grado').options[document.getElementById('grado').selectedIndex].text :
            'Todos',
        seccion: document.getElementById('seccion') ?
            document.getElementById('seccion').options[document.getElementById('seccion').selectedIndex].text :
            'Todas'
    };

    // Crear contenido HTML para el iframe
    var printContent = `
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset="UTF-8">
        <title>Reporte de Pagos</title>
        <style>
            /* Estilos para impresión */
            @page { size: landscape; margin: 15mm; }
            body { 
                font-family: Arial, sans-serif;
                font-size: 10pt;
                margin: 0;
                padding: 0;
                color: #000;
            }
            .print-header {
                text-align: center;
                margin-bottom: 20px;
                border-bottom: 2px solid #333;
                padding-bottom: 15px;
            }
            .print-header-content {
                display: flex;
                align-items: center;
                justify-content: center;
                gap: 20px;
            }
            .print-header img {
                max-width: 80px;
                max-height: 80px;
                object-fit: contain;
            }
            .print-header-text {
                text-align: left;
            }
            .print-header h1 {
                margin: 0;
                font-size: 18pt;
                font-weight: bold;
                color: #333;
            }
            .print-header h2 {
                margin: 5px 0 0 0;
                font-size: 14pt;
                font-weight: bold;
                color: #666;
            }
            .print-header p {
                margin: 5px 0 0 0;
                font-size: 10pt;
                color: #666;
            }

            .print-filter-info {
                margin-bottom: 15px;
                font-size: 9pt;
            }
            table {
                width: 100%;
                border-collapse: collapse;
                font-family: Arial, sans-serif;
                margin-bottom: 20px;
                font-size: 9pt;
            }
            table th, table td {
                border: 1px solid #000;
                padding: 4px 6px;
                text-align: left;
                vertical-align: top;
            }
            table th {
                background-color: #f0f0f0;
                font-weight: bold;
                text-align: center;
            }
            .text-center { text-align: center; }
            .text-right { text-align: right; }
            .total-row { font-weight: bold; }
            .date-column {
                white-space: nowrap;
            }
            .observations-column {
                max-width: 200px;
                word-wrap: break-word;
                white-space: normal;
                font-size: 8pt;
            }
        </style>
    </head>
    <body>
        <div class="print-header">
            <div class="print-header-content">
                ${filterInfo.schoolLogo ? `<img src="${filterInfo.schoolLogo}" alt="Logo">` : ''}
                <div class="print-header-text">
                    <h1>${filterInfo.schoolName}</h1>
                    <h2>${filterInfo.reportTitle}</h2>
                    <p>${filterInfo.periodLabel}</p>
                </div>
            </div>
        </div>
        <div class="print-filter-info">
            <p>
                <strong>Filtros aplicados:</strong> 
                Nivel: ${filterInfo.nivel}, 
                Grado: ${filterInfo.grado}, 
                Sección: ${filterInfo.seccion}
            </p>
        </div>
        <table>
            <thead>
                ${originalTable.querySelector('thead').outerHTML}
            </thead>
            <tbody>
                ${getSimplifiedTableBody(originalTable)}
            </tbody>
            <tfoot>
                ${originalTable.querySelector('tfoot').outerHTML}
            </tfoot>
        </table>
    </body>
    </html>
    `;

    // Escribir el contenido al iframe
    frameDocument.write(printContent);
    frameDocument.close();

    // Esperar a que los estilos y contenido se carguen
    frameWindow.onload = function () {
        setTimeout(function () {
            // Imprimir
            frameWindow.focus();
            frameWindow.print();

            // Eliminar el iframe después de imprimir
            setTimeout(function () {
                document.body.removeChild(printFrame);
            }, 500);
        }, 250);
    };
}

// Función para simplificar el contenido de la tabla para impresión
function getSimplifiedTableBody(originalTable) {
    var rows = originalTable.querySelectorAll('tbody tr');
    var simplifiedHTML = '';

    rows.forEach(function (row) {
        var cells = row.querySelectorAll('td');
        console.log('Fila tiene', cells.length, 'celdas');

        simplifiedHTML += '<tr>';

        // Procesar cada celda de la fila (ahora siempre son 9 columnas)
        cells.forEach(function (cell, index) {
            let cellContent = '';

            // Columna 1: Número (#)
            if (index === 0) {
                cellContent = cell.textContent.trim();
            }
            // Columna 2: Fecha de Pago
            else if (index === 1) {
                var dateElement = cell.querySelector('.date-main');
                cellContent = dateElement ? dateElement.textContent.trim() : cell.textContent.trim();
            }
            // Columna 3: ID Alumno
            else if (index === 2) {
                cellContent = cell.textContent.trim();
            }
            // Columna 4: Nombre Alumno
            else if (index === 3) {
                cellContent = cell.textContent.trim();
            }
            // Columna 5: Estado
            else if (index === 4) {
                cellContent = cell.textContent.trim();
            }
            // Columna 6: Concepto de Pago
            else if (index === 5) {
                cellContent = cell.textContent.trim();
            }
            // Columna 7: Monto Total
            else if (index === 6) {
                var badgeElement = cell.querySelector('.badge');
                cellContent = badgeElement ? badgeElement.textContent.trim() : cell.textContent.trim();
            }
            // Columna 8: Monto del Método (siempre presente)
            else if (index === 7) {
                var badgeElement = cell.querySelector('.badge-primary');
                if (badgeElement) {
                    cellContent = badgeElement.textContent.trim();
                } else {
                    var anyBadge = cell.querySelector('.badge');
                    cellContent = anyBadge ? anyBadge.textContent.trim() : cell.textContent.trim();
                }
            }
            // Columna 9: Observaciones (siempre en índice 8)
            else if (index === 8) {
                // Enfoque simple: capturar todo el texto visible de la celda
                var fullText = cell.textContent.trim();

                // Limpiar espacios múltiples y saltos de línea
                fullText = fullText.replace(/\s+/g, ' ').trim();

                if (fullText && fullText !== '') {
                    cellContent = fullText;
                } else {
                    cellContent = 'Sin observaciones';
                }
            }

            // Aplicar clase CSS específica para la columna de observaciones
            var cellClass = (index === 8) ? 'observations-column' : '';
            simplifiedHTML += `<td class="${cellClass}">${cellContent}</td>`;
        });

        simplifiedHTML += '</tr>';
    });

    return simplifiedHTML;
}

// Configurar el evento al cargar la página
document.addEventListener('DOMContentLoaded', function () {
    var printBtn = document.getElementById('print_btn');
    if (printBtn) {
        printBtn.addEventListener('click', printPaymentReport);
    }
});
