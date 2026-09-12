(function (window, document, $) {
    'use strict';

    function initStudentGrades() {
        var app = document.getElementById('student-grades-app');
        if (!app || !$) return;

        var state = { data: null, yearData: null, year: '', bimester: null, view: 'bimester' };
        var endpoint = app.getAttribute('data-endpoint') || 'api/my_grades.php';
        var $year = $('#sg-year-filter');
        var $summary = $('#sg-summary');
        var $nav = $('#sg-bimester-nav');
        var $content = $('#sg-content');
        var $caption = $('#sg-panel-caption');

        function esc(value) { return $('<div>').text(value == null ? '' : String(value)).html(); }
        function roman(number) { return ({1:'I',2:'II',3:'III',4:'IV'})[Number(number)] || String(number || ''); }
        function levelFromNumeric(value) {
            var n = parseFloat(value);
            if (!isFinite(n)) return '';
            if (n >= 18) return 'AD';
            if (n >= 14) return 'A';
            if (n >= 11) return 'B';
            return 'C';
        }
        function resultLevel(entity) {
            if (!entity) return '';
            return entity.nivel_logro || levelFromNumeric(entity.promedio || entity.promedio_anual || entity.promedio_bimestre);
        }
        function resultText(entity) {
            if (!entity) return '—';
            if (entity.escala === 'literal') return entity.nivel_logro || entity.resultado || '—';
            return entity.promedio || entity.promedio_anual || entity.promedio_bimestre || entity.resultado || '—';
        }
        function gradeClass(entityOrLevel) {
            var level = typeof entityOrLevel === 'string' ? entityOrLevel : resultLevel(entityOrLevel);
            level = String(level || '').toUpperCase();
            if (level === 'AD') return 'sg-grade-ad';
            if (level === 'A') return 'sg-grade-a';
            if (level === 'B') return 'sg-grade-b';
            return 'sg-grade-c';
        }
        function gradePill(entity, compact) {
            var result = esc(resultText(entity));
            var level = resultLevel(entity);
            var extra = entity && entity.escala !== 'literal' && level ? '<span aria-hidden="true">·</span><span>' + esc(level) + '</span>' : '';
            return '<span class="' + (compact ? 'sg-mini-result' : 'sg-grade-pill') + ' ' + gradeClass(level) + '">' + result + extra + '</span>';
        }
        function trendMeta(trend) {
            if (trend === 'sube') return {cls:'sg-trend-up',icon:'fa-arrow-up',text:'Mejoró'};
            if (trend === 'baja') return {cls:'sg-trend-down',icon:'fa-arrow-down',text:'Por reforzar'};
            if (trend === 'estable') return {cls:'sg-trend-flat',icon:'fa-minus',text:'Se mantiene'};
            return {cls:'sg-trend-none',icon:'fa-circle',text:'Sin comparación'};
        }
        function trendPill(trend) {
            var meta = trendMeta(trend);
            return '<span class="sg-trend-pill ' + meta.cls + '"><i class="fas ' + meta.icon + '"></i>' + meta.text + '</span>';
        }
        function findAnnualCourse(name) {
            if (!state.yearData) return null;
            return (state.yearData.promedios_por_curso || []).find(function (item) { return String(item.curso) === String(name); }) || null;
        }
        function trendForCourse(courseName, bimester) {
            var annual = findAnnualCourse(courseName);
            if (!annual) return 'sin_datos';
            var detail = annual.detalle_bimestres || {};
            var current = parseFloat(detail[String(bimester)]);
            if (!isFinite(current)) return 'sin_datos';
            var previousKeys = Object.keys(detail).map(function (key) { return parseInt(key,10); })
                .filter(function (key) { return key < Number(bimester) && isFinite(parseFloat(detail[String(key)])); })
                .sort(function (a,b) { return b-a; });
            if (!previousKeys.length) return 'sin_datos';
            var previous = parseFloat(detail[String(previousKeys[0])]);
            var diff = current - previous;
            return diff > .5 ? 'sube' : (diff < -.5 ? 'baja' : 'estable');
        }
        function selectedBimesterData() {
            if (!state.yearData || !state.bimester) return null;
            return (state.yearData.bimestres || []).find(function (b) { return Number(b.numero) === Number(state.bimester); }) || null;
        }
        function summaryCard(icon, label, value, note) {
            return '<div class="sg-summary-card"><span class="sg-summary-icon"><i class="fas ' + icon + '"></i></span><div class="sg-summary-copy"><div class="sg-summary-label">' + esc(label) + '</div><div class="sg-summary-value">' + value + '</div>' + (note ? '<div class="sg-summary-note">' + esc(note) + '</div>' : '') + '</div></div>';
        }
        function renderSummary() {
            if (!state.yearData) {
                $summary.html(summaryCard('fa-star','Resultado','—','Sin notas publicadas') + summaryCard('fa-book-open','Cursos evaluados','0','') + summaryCard('fa-life-ring','Por reforzar','0','') + summaryCard('fa-calendar-alt','Avance','0 / 4','Bimestres publicados'));
                return;
            }
            if (state.view === 'annual') {
                var annualCourses = state.yearData.promedios_por_curso || [];
                var published = (state.yearData.bimestres || []).length;
                var attention = annualCourses.filter(function (course) { return ['B','C'].indexOf(resultLevel(course)) !== -1; }).length;
                $summary.html(summaryCard('fa-star','Resultado anual',gradePill(state.yearData,false),'Con los bimestres publicados') + summaryCard('fa-book-open','Cursos con notas',String(annualCourses.length),'En el año seleccionado') + summaryCard('fa-life-ring','Por reforzar',String(attention),'Cursos en nivel B o C') + summaryCard('fa-calendar-check','Avance',published + ' / 4','Bimestres publicados'));
                return;
            }
            var bim = selectedBimesterData();
            if (!bim) {
                $summary.html(summaryCard('fa-star','Resultado','—','Bimestre sin publicar') + summaryCard('fa-book-open','Cursos evaluados','0','') + summaryCard('fa-life-ring','Por reforzar','0','') + summaryCard('fa-calendar-alt','Bimestre',roman(state.bimester) + ' / IV','Aún sin calificaciones'));
                return;
            }
            $summary.html(summaryCard('fa-star','Resultado del bimestre',gradePill(bim,false),'Promedio de cursos evaluados') + summaryCard('fa-book-open','Cursos evaluados',String(bim.cursos_evaluados || (bim.cursos || []).length),'Con calificaciones publicadas') + summaryCard('fa-life-ring','Por reforzar',String(bim.cursos_por_reforzar || 0),'Cursos en nivel B o C') + summaryCard('fa-calendar-alt','Bimestre',roman(bim.numero) + ' / IV','Año ' + state.year));
        }
        function renderBimesterNav() {
            var available = {};
            (state.yearData && state.yearData.bimestres ? state.yearData.bimestres : []).forEach(function (bim) { available[Number(bim.numero)] = bim; });
            var html = '';
            for (var i=1;i<=4;i++) {
                var bim = available[i];
                var active = Number(state.bimester) === i && state.view === 'bimester';
                if (bim) html += '<button type="button" class="sg-bimester-btn' + (active ? ' active' : '') + '" data-bimester="' + i + '"><span><strong>' + roman(i) + ' Bimestre</strong><small>Publicado</small></span>' + gradePill(bim,true) + '</button>';
                else html += '<button type="button" class="sg-bimester-btn is-empty" disabled><span><strong>' + roman(i) + ' Bimestre</strong><small>Sin publicar</small></span><i class="far fa-clock"></i></button>';
            }
            $nav.html(html).toggleClass('d-none', state.view === 'annual');
        }
        function renderBimester() {
            var bim = selectedBimesterData();
            if (!bim) {
                $content.html('<div class="sg-empty-state"><i class="far fa-calendar"></i><strong>Bimestre sin calificaciones publicadas</strong><span>Cuando el colegio publique notas, aparecerán aquí.</span></div>');
                return;
            }
            var courses = bim.cursos || [];
            if (!courses.length) {
                $content.html('<div class="sg-empty-state"><i class="fas fa-book-open"></i><strong>No hay cursos evaluados</strong><span>Aún no se registran calificaciones en este bimestre.</span></div>');
                return;
            }
            var rows = '', cards = '';
            courses.forEach(function (course,index) {
                var area = typeof course.area === 'object' && course.area ? course.area.nombre : (course.area || 'Área General');
                var trend = trendForCourse(course.curso,bim.numero);
                rows += '<tr><td><div class="sg-course-name">' + esc(course.curso) + '</div></td><td><span class="sg-area-name">' + esc(area) + '</span></td><td class="sg-result-cell">' + gradePill(course,false) + '</td><td class="sg-trend-cell">' + trendPill(trend) + '</td><td class="sg-action-cell"><button type="button" class="sg-detail-btn" data-course-index="' + index + '"><i class="far fa-eye"></i>Detalle</button></td></tr>';
                cards += '<article class="sg-mobile-course"><div class="sg-mobile-course-top"><div><h3>' + esc(course.curso) + '</h3><div class="sg-area-name">' + esc(area) + '</div></div>' + gradePill(course,false) + '</div><div class="sg-mobile-course-bottom">' + trendPill(trend) + '<button type="button" class="sg-detail-btn" data-course-index="' + index + '"><i class="far fa-eye"></i>Detalle</button></div></article>';
            });
            $content.html('<div class="sg-desktop-table sg-table-wrap"><table class="table sg-table"><thead><tr><th>Curso</th><th>Área</th><th class="text-center">Resultado</th><th>Evolución</th><th></th></tr></thead><tbody>' + rows + '</tbody></table></div><div class="sg-mobile-list">' + cards + '</div>');
        }
        function annualCell(course,bimNumber) {
            var values = course.detalle_bimestres || {}, levels = course.detalle_niveles || {}, raw = values[String(bimNumber)];
            if (raw == null) return '<span class="text-muted">—</span>';
            var level = levels[String(bimNumber)] || levelFromNumeric(raw);
            return gradePill({promedio:String(raw),nivel_logro:level,escala:course.escala === 'literal' ? 'literal' : 'numerica'},true);
        }
        function renderAnnual() {
            var courses = state.yearData ? (state.yearData.promedios_por_curso || []) : [];
            if (!courses.length) {
                $content.html('<div class="sg-empty-state"><i class="fas fa-chart-line"></i><strong>Sin progreso anual disponible</strong><span>Se mostrará cuando existan calificaciones publicadas.</span></div>');
                return;
            }
            var rows = '', cards = '';
            courses.forEach(function (course) {
                var area = typeof course.area === 'object' && course.area ? course.area.nombre : (course.area || 'Área General');
                var annualEntity = {promedio:course.promedio_anual,nivel_logro:course.nivel_logro,escala:course.escala};
                rows += '<tr><td><div class="sg-course-name">' + esc(course.curso) + '</div><div class="sg-area-name">' + esc(area) + '</div></td><td class="sg-bim-col">' + annualCell(course,1) + '</td><td class="sg-bim-col">' + annualCell(course,2) + '</td><td class="sg-bim-col">' + annualCell(course,3) + '</td><td class="sg-bim-col">' + annualCell(course,4) + '</td><td class="sg-result-cell">' + gradePill(annualEntity,false) + '</td><td class="sg-trend-cell">' + trendPill(course.tendencia) + '</td></tr>';
                cards += '<article class="sg-mobile-course"><div class="sg-mobile-course-top"><div><h3>' + esc(course.curso) + '</h3><div class="sg-area-name">' + esc(area) + '</div></div>' + gradePill(annualEntity,false) + '</div><div class="sg-mobile-bim-grid"><span class="sg-area-name">I:</span>' + annualCell(course,1) + '<span class="sg-area-name ml-1">II:</span>' + annualCell(course,2) + '<span class="sg-area-name ml-1">III:</span>' + annualCell(course,3) + '<span class="sg-area-name ml-1">IV:</span>' + annualCell(course,4) + '</div><div class="sg-mobile-course-bottom">' + trendPill(course.tendencia) + '</div></article>';
            });
            $content.html('<div class="sg-desktop-table sg-table-wrap"><table class="table sg-table sg-annual-table"><thead><tr><th>Curso</th><th class="sg-bim-col">I</th><th class="sg-bim-col">II</th><th class="sg-bim-col">III</th><th class="sg-bim-col">IV</th><th class="text-center">Resultado</th><th>Evolución</th></tr></thead><tbody>' + rows + '</tbody></table></div><div class="sg-mobile-list">' + cards + '</div>');
        }
        function renderDetail(index) {
            var bim = selectedBimesterData();
            if (!bim || !bim.cursos || !bim.cursos[index]) return;
            var course = bim.cursos[index];
            var area = typeof course.area === 'object' && course.area ? course.area.nombre : (course.area || 'Área General');
            $('#sg-detail-title').text(course.curso || 'Detalle del curso');
            $('#sg-detail-meta').text(area + ' · ' + roman(bim.numero) + ' Bimestre · ' + state.year);
            var html = '<div class="sg-detail-summary"><div><strong>Resultado del curso</strong><span class="sg-area-name">' + esc(area) + '</span></div>' + gradePill(course,false) + '</div>';
            var competencies = course.competencias || [];
            if (!competencies.length) html += '<div class="sg-empty-state sg-detail-empty"><i class="fas fa-list-alt"></i><strong>Sin detalle de competencias</strong></div>';
            else competencies.forEach(function (comp) {
                var evaluations = comp.evaluaciones || comp.notas || [];
                var weight = parseFloat(comp.peso || 0);
                html += '<section class="sg-competency-card"><div class="sg-competency-head"><h4>' + esc(comp.nombre || comp.competencia || 'Competencia') + '</h4><div class="sg-competency-meta">' + (weight > 0 && weight < 1 ? '<span class="sg-weight-pill">Peso ' + Math.round(weight*100) + '%</span>' : '') + gradePill(comp,true) + '</div></div>';
                if (!evaluations.length) html += '<div class="p-3 text-muted small">Sin evaluaciones registradas.</div>';
                else {
                    html += '<div class="table-responsive"><table class="table sg-eval-table"><thead><tr><th>Evaluación</th><th class="text-center">Nota</th><th>Observación</th></tr></thead><tbody>';
                    evaluations.forEach(function (ev) {
                        var level = ev.tipo === 'literal' ? String(ev.nota || '') : levelFromNumeric(ev.numeric != null ? ev.numeric : ev.nota);
                        var noteEntity = {promedio:ev.nota,nivel_logro:level,escala:ev.tipo === 'literal' ? 'literal' : 'numerica'};
                        html += '<tr><td>' + esc(ev.evaluacion || ev.titulo || 'Evaluación') + '</td><td class="text-center">' + gradePill(noteEntity,true) + '</td><td><span class="sg-eval-note">' + esc(ev.observacion || '—') + '</span></td></tr>';
                    });
                    html += '</tbody></table></div>';
                }
                html += '</section>';
            });
            $('#sg-detail-body').html(html);
            $('#sg-detail-modal').modal('show');
        }
        function defaultBimester(yearData) {
            var list = (yearData && yearData.bimestres ? yearData.bimestres : []).map(function (b) { return Number(b.numero); }).filter(Boolean).sort(function (a,b) { return b-a; });
            return list.length ? list[0] : 1;
        }
        function renderAll() {
            renderSummary();
            renderBimesterNav();
            if (state.view === 'annual') { $caption.text('Compara el resultado de cada curso entre los cuatro bimestres.'); renderAnnual(); }
            else { $caption.text('Resultados del ' + roman(state.bimester) + ' bimestre y detalle por competencia.'); renderBimester(); }
        }
        function selectYear(year) {
            state.year = String(year || '');
            state.yearData = (state.data && state.data.años_academicos ? state.data.años_academicos : []).find(function (item) { return String(item.año) === state.year; }) || null;
            state.bimester = defaultBimester(state.yearData);
            renderAll();
        }
        function populateYears(data) {
            var years = data.años_disponibles || data.años_academicos || [], options = '';
            years.forEach(function (year) { options += '<option value="' + esc(year.año) + '">' + esc(year.año) + (year.es_activo ? ' · Actual' : '') + '</option>'; });
            if (!options) options = '<option value="">Sin años disponibles</option>';
            $year.html(options);
            var active = years.find(function (item) { return !!item.es_activo; });
            var fallback = (data.años_academicos || [])[0] || years[0];
            var selected = active || fallback;
            if (selected) { $year.val(String(selected.año)); selectYear(selected.año); }
            else { state.yearData = null; renderAll(); }
        }
        function hydrateStudent(data) {
            var parts = [];
            if (data.nivel) parts.push(data.nivel);
            if (data.grado) parts.push(data.grado);
            if (data.seccion) parts.push('Sección ' + data.seccion);
            $('#sg-student-meta').text(parts.length ? parts.join(' · ') : 'Información académica');
        }
        function showError(message) {
            $summary.empty(); $nav.empty();
            $content.html('<div class="sg-error-state"><i class="fas fa-exclamation-circle"></i><strong>No se pudieron cargar las calificaciones</strong><span>' + esc(message || 'Intenta nuevamente.') + '</span><button type="button" class="btn btn-sm btn-outline-primary" id="sg-retry">Reintentar</button></div>');
        }
        function loadGrades() {
            app.setAttribute('aria-busy','true');
            $('#sg-debt-alert').addClass('d-none');
            $.ajax({url:endpoint,method:'GET',dataType:'json',cache:false}).done(function (resp) {
                if (resp && resp.status === 'ok' && resp.data) { state.data = resp.data; hydrateStudent(resp.data); populateYears(resp.data); return; }
                if (resp && resp.reason === 'debt') {
                    $('#sg-debt-message').text((resp.message || 'Regulariza las cuotas pendientes para consultar las notas.') + (resp.total_pendiente_ultimas ? ' Monto pendiente considerado: S/ ' + resp.total_pendiente_ultimas + '.' : ''));
                    $('#sg-debt-alert').removeClass('d-none'); $summary.empty(); $nav.empty();
                    $content.html('<div class="sg-empty-state"><i class="fas fa-lock"></i><strong>Consulta de notas restringida</strong><span>Comunícate con la institución si necesitas más información.</span></div>'); return;
                }
                showError(resp && resp.message ? resp.message : 'Respuesta no válida del servidor.');
            }).fail(function () { showError('Error de conexión con el servidor.'); }).always(function () { app.setAttribute('aria-busy','false'); });
        }

        $year.on('change', function () { selectYear(this.value); });
        $(document).on('click.studentGrades','.sg-view-btn',function () { state.view = $(this).data('view') === 'annual' ? 'annual' : 'bimester'; $('.sg-view-btn').removeClass('active').attr('aria-pressed','false'); $(this).addClass('active').attr('aria-pressed','true'); renderAll(); });
        $(document).on('click.studentGrades','.sg-bimester-btn[data-bimester]',function () { state.view='bimester'; state.bimester=Number($(this).data('bimester')); $('.sg-view-btn').removeClass('active').attr('aria-pressed','false'); $('.sg-view-btn[data-view="bimester"]').addClass('active').attr('aria-pressed','true'); renderAll(); });
        $(document).on('click.studentGrades','.sg-detail-btn',function () { renderDetail(Number($(this).data('course-index'))); });
        $(document).on('click.studentGrades','#sg-retry',loadGrades);
        loadGrades();
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded',initStudentGrades,{once:true});
    else initStudentGrades();
})(window, document, window.jQuery);
