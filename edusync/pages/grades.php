<?php
include 'db_connect.php';
$loginType=(int)($_SESSION['login_type']??0);$teacherId=(int)($_SESSION['login_teacher_id']??0);$schoolId=(int)($_SESSION['login_school_id']??0);
if($loginType!==2||$teacherId<=0||$schoolId<=0){echo "<div class='py-5'><div class='alert alert-warning text-center'>Solo los docentes con una cuenta vinculada pueden gestionar evaluaciones.</div></div>";return;}
if(empty($_SESSION['csrf_token']))$_SESSION['csrf_token']=bin2hex(random_bytes(32));$csrf=$_SESSION['csrf_token'];
$years=[];$activeYear=0;$stmt=$conn->prepare('SELECT id,year,description,is_active,status FROM academic_year WHERE school_id=? ORDER BY is_active DESC,year DESC');$stmt->bind_param('i',$schoolId);$stmt->execute();$r=$stmt->get_result();while($y=$r->fetch_assoc()){$years[]=$y;if((int)$y['is_active']===1)$activeYear=(int)$y['id'];}$stmt->close();
$migrationReady=false;$column=$conn->query("SHOW COLUMNS FROM evaluations LIKE 'status'");if($column&&$column->num_rows)$migrationReady=true;
?>
<style>
.gr-header{background:#fff;border:1px solid #e3e6f0;border-left:4px solid #4e73df;border-radius:.45rem;padding:1rem 1.2rem}.gr-stat{background:#fff;border:1px solid #e3e6f0;border-radius:.45rem;padding:.85rem 1rem;height:100%}.gr-stat i{color:#4e73df}.gr-filter{background:#fff;border:1px solid #e3e6f0;border-radius:.45rem}.gr-tabs{gap:.35rem}.gr-tabs .btn{border-radius:1rem}.gr-progress{height:6px;min-width:100px}.gr-table td,.gr-table th{vertical-align:middle}.gr-title{min-width:190px}.gr-course{min-width:160px}.gr-actions{white-space:nowrap}.gr-label{font-size:.75rem;text-transform:uppercase;font-weight:700;color:#5a5c69}.gr-empty{padding:2.5rem;text-align:center;color:#858796}.gr-state{font-size:.72rem}.gr-migration{border-left:4px solid #f6c23e}
.gr-view-switch{background:#eef1f8;border-radius:.45rem;padding:.25rem;display:inline-flex;gap:.2rem}.gr-view-switch .btn{border:0}#gr-book-view{width:100%;max-width:100%;min-width:0;overflow:hidden}#gr-book-view>.card,#gr-book-view>.card>.card-body{width:100%;max-width:100%;min-width:0}#gb-kpis{margin-left:0;margin-right:0}.gb-sheet-wrap{display:block;width:100%;max-width:100%;min-width:0;max-height:66vh;overflow-x:auto;overflow-y:auto;overscroll-behavior-x:contain;contain:inline-size;border:1px solid #e3e6f0;-webkit-overflow-scrolling:touch}.gb-sheet{border-collapse:separate;border-spacing:0;min-width:100%;width:max-content;max-width:none}.gb-sheet th,.gb-sheet td{border-right:1px solid #dfe3eb;border-bottom:1px solid #dfe3eb;padding:.4rem;background:#fff;vertical-align:middle}.gb-sheet thead th{position:sticky;top:0;z-index:4;background:#f1f3f8;min-width:125px}.gb-sheet .gb-number{position:sticky;left:0;z-index:6;min-width:45px;width:45px;text-align:center}.gb-sheet .gb-student-head{position:sticky;left:45px;z-index:6;min-width:260px;max-width:260px}.gb-sheet .gb-student{position:sticky;left:45px;z-index:3;min-width:260px;max-width:260px;background:#fff}.gb-cell{width:78px;min-width:78px;margin:auto;text-align:center;font-weight:700;border:1px solid transparent}.gb-cell.gb-dirty{border-color:#f6c23e;background:#fff8df}.gb-cell.gb-invalid{border-color:#e74a3b;background:#fff1f0}.gb-cell:disabled{background:#f3f4f7;color:#6c757d}.gb-eval-title{display:block;max-width:150px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.gb-summary{width:100%;max-width:100%;min-width:0;background:#f8f9fc;border:1px solid #e3e6f0;border-radius:.4rem;padding:.7rem 1rem}.gb-readonly{border-left:4px solid #f6c23e}.gb-empty{padding:3rem;text-align:center;color:#858796}
.gb-sheet thead tr:first-child th{height:52px;background:#e9eff9;text-align:center;color:#2e3a59}.gb-sheet thead tr:nth-child(2) th{top:52px;background:#f8f9fc;text-align:center}.gb-sheet thead .gb-number,.gb-sheet thead .gb-student-head{background:#fff!important;text-align:left!important}.gb-group-title{font-size:.72rem;text-transform:uppercase;font-weight:800;max-width:260px;display:inline-block;vertical-align:middle;line-height:1.2}.gb-group-percent{display:inline-block;margin-left:.3rem;font-size:.7rem;color:#4e73df;background:#fff;border:1px solid #cfd9eb;border-radius:1rem;padding:.08rem .38rem}.gb-group-head{position:relative;padding-right:28px}.gb-add-eval{position:absolute;right:5px;top:50%;transform:translateY(-50%);width:24px;height:24px;padding:0!important;border-radius:50%}.gb-head-actions{height:20px;white-space:nowrap;margin-top:.15rem;opacity:.25;transition:opacity .15s}.gb-sheet th:hover .gb-head-actions{opacity:1}.gb-head-actions .btn{padding:.02rem .22rem;border:0;background:transparent}.gb-eval-title{font-size:.75rem;font-weight:700}.gb-comp-average,.gb-final-average{font-weight:800;text-align:center;background:#e9eff9!important;min-width:82px}.gb-placeholder{color:#858796;text-align:center;background:#fafafa!important;min-width:105px}.gb-filter-note{font-size:.75rem;color:#858796}.gb-sheet tbody tr:hover .gb-student,.gb-sheet tbody tr:hover td{background:#f8fbff}.gb-cell{border-radius:.25rem}
.gb-kpi{background:#fff;border:1px solid #e3e6f0;border-radius:.4rem;padding:.55rem .7rem;height:100%}.gb-kpi small{color:#858796}.gb-kpi strong{display:block;color:#2e3a59;font-size:1.05rem}.gb-toolbar{gap:.5rem;min-width:0}.gb-toolbar>*{flex:0 0 auto}.gb-sync{font-size:.75rem}.gb-low{background:#fff1f0!important;color:#b02a37}.gb-pending{background:#fffdf2!important}.gb-history-ready{cursor:pointer}.gb-sheet tbody tr.gb-row-hidden{display:none}@media(max-width:1199.98px){.gb-summary>.d-flex{align-items:flex-start!important}.gb-toolbar{width:100%;margin-top:.65rem!important}.gb-sheet .gb-student-head,.gb-sheet .gb-student{min-width:220px;max-width:220px}}@media(max-width:575.98px){.gb-toolbar{display:grid!important;grid-template-columns:1fr 1fr}.gb-toolbar #gb-student-filter{width:100%!important;grid-column:1/-1}.gb-sheet .gb-student-head,.gb-sheet .gb-student{min-width:185px;max-width:185px}.gb-sheet thead th{min-width:105px}}

/* Orden compacto: se mantienen los controles y colores de EduSync. */
.gr-filter-actions,.gb-filter-actions{gap:.45rem}
#gr-book-view .gb-compact-controls{padding:.5rem .7rem;background:#f8f9fc}
#gr-book-view .gb-compact-controls>.gb-toolbar{display:flex!important;align-items:center!important;flex-wrap:wrap!important;grid-template-columns:none!important;gap:.5rem;width:100%!important;min-width:0;margin:0!important}
#gr-book-view .gb-compact-controls #gb-student-filter{width:220px!important;min-width:180px;max-width:280px;grid-column:auto!important;flex:0 1 220px!important}
#gr-book-view .gb-compact-controls .gb-autosave-switch{display:none!important}
#gr-book-view .gb-compact-controls .gb-save-status{display:flex;align-items:center;flex:1 1 280px;flex-wrap:wrap;min-width:0;gap:.3rem .45rem;white-space:normal}
#gr-book-view .gb-compact-controls .gb-sync{display:inline-flex;align-items:center;min-width:0;font-size:.75rem;line-height:1.35;white-space:normal;overflow-wrap:break-word}
#gr-book-view .gb-compact-controls #gb-save-mode{flex:0 0 auto}#gr-book-view .gb-compact-controls #gb-change-count{flex:0 0 auto;white-space:nowrap}
#gr-book-view .gb-compact-controls .gb-save-actions{display:flex!important;flex:0 0 auto!important;align-items:center;gap:.35rem;min-width:74px;margin-left:auto}
#gr-book-view .gb-compact-controls .gb-save-actions .gb-icon-action{display:inline-flex;align-items:center;justify-content:center;width:34px!important;height:32px!important;min-width:34px;flex:0 0 34px!important;padding:0!important;white-space:nowrap}
@media(max-width:991.98px){
 #gr-book-view .gb-compact-controls #gb-student-filter{flex:1 1 190px!important;max-width:none}
 #gr-book-view .gb-compact-controls .gb-save-status{margin-left:0!important;flex:1 1 250px}
}
@media(max-width:575.98px){
 #gr-book-view .gb-compact-controls #gb-student-filter{width:100%!important;max-width:none;flex:1 1 100%!important}
 #gr-book-view .gb-compact-controls .gb-save-status{flex:1 1 190px;min-width:0}
 #gr-book-view .gb-compact-controls .gb-save-actions{margin-left:auto}
 .gr-filter-actions,.gb-filter-actions{width:100%;justify-content:flex-end}
}
.gr-pager{border-top:1px solid #e3e6f0;padding:.7rem 1rem}
.gr-pager-layout,.gr-pager-summary{gap:.35rem}
.gr-page-size{width:70px;min-width:70px}
.gr-pager .page-link{color:#4e73df}
.gr-pager .page-item.active .page-link{background:#4e73df;border-color:#4e73df;color:#fff}
@media(max-width:767.98px){.gr-pager-layout{gap:.75rem}.gr-pager-layout nav{width:100%}.gr-pager .pagination{justify-content:flex-start;flex-wrap:wrap;gap:.25rem}.gr-pager .page-item .page-link{border-radius:.25rem}}

/* Libro de notas: refinamiento exclusivo de la planilla, conservando la línea EduSync. */
#gr-book-view .gb-sheet-wrap{border:1px solid #dfe5ef;border-radius:.45rem;background:#fff;box-shadow:0 1px 3px rgba(58,59,69,.04);scrollbar-color:#cbd5e4 #f8f9fc}
#gr-book-view .gb-sheet{font-variant-numeric:tabular-nums}
#gr-book-view .gb-sheet th,#gr-book-view .gb-sheet td{border-right-color:#e3e8f1;border-bottom-color:#e9edf4}
#gr-book-view .gb-sheet thead tr:first-child th{height:48px;background:#e9eff9;color:#2e3a59}
#gr-book-view .gb-sheet thead tr:nth-child(2) th{top:48px;height:66px;min-width:116px;padding:.4rem .45rem;background:#f7f9fd;vertical-align:middle}
#gr-book-view .gb-sheet .gb-group-head{padding:.4rem .5rem .4rem .55rem;border-left:2px solid #cbd8ee;text-align:left!important}
#gr-book-view .gb-sheet .gb-group-inner{width:100%;min-width:0;display:flex;align-items:center;gap:.35rem}
#gr-book-view .gb-sheet .gb-group-inner .gb-group-title{flex:1 1 auto;min-width:0}
#gr-book-view .gb-sheet .gb-group-inner .gb-group-percent{flex:0 0 auto;margin:0}
#gr-book-view .gb-sheet .gb-group-inner .gb-add-eval{position:static!important;top:auto!important;right:auto!important;transform:none!important;flex:0 0 26px;width:26px!important;min-width:26px;height:26px!important;padding:0!important;display:inline-flex;align-items:center;justify-content:center;font-size:.72rem!important;line-height:1!important;border-radius:.35rem;margin-left:auto;vertical-align:middle}
#gr-book-view .gb-sheet .gb-group-inner{display:flex;align-items:center;gap:.3rem;min-width:0;padding-right:.1rem}
#gr-book-view .gb-sheet .gb-group-title{display:-webkit-box;-webkit-box-orient:vertical;-webkit-line-clamp:2;overflow:hidden;flex:1 1 auto;min-width:0;max-width:none;max-height:2.5em;line-height:1.25;font-size:.72rem;overflow-wrap:anywhere}
#gr-book-view .gb-sheet .gb-group-percent{flex:0 0 auto;white-space:nowrap;font-size:.68rem;padding:.1rem .35rem;margin-left:0}
#gr-book-view .gb-sheet .gb-add-eval{height:24px;width:24px;right:.4rem;border-radius:.35rem}
#gr-book-view .gb-sheet .gb-eval-head{width:145px;min-width:138px;max-width:165px;box-sizing:border-box}
#gr-book-view .gb-sheet .gb-eval-heading{display:grid;grid-template-columns:27px minmax(0,1fr) 27px;align-items:center;justify-content:center;gap:.2rem;min-width:0;width:100%;max-width:100%}
#gr-book-view .gb-sheet .gb-eval-heading::before{content:"";display:block;grid-column:1;width:27px}
#gr-book-view .gb-sheet .gb-eval-title{grid-column:2;display:-webkit-box;-webkit-box-orient:vertical;-webkit-line-clamp:2;min-width:0;max-width:100%;overflow:hidden;text-overflow:ellipsis;white-space:normal;overflow-wrap:anywhere;text-align:center!important;line-height:1.25;font-size:.72rem;color:#45536f}
#gr-book-view .gb-sheet .gb-head-actions{grid-column:3;align-self:center;justify-self:end;min-width:27px;max-width:27px;height:27px;margin:0;opacity:1}
#gr-book-view .gb-sheet .gb-eval-status{margin-top:.25rem;display:flex;justify-content:center;width:100%;min-width:0}
#gr-book-view .gb-sheet .gb-eval-annulled{display:inline-flex;align-items:center;gap:.2rem;max-width:100%;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;font-weight:600;font-size:.66rem;padding:.17rem .35rem;border-radius:.3rem}
#gr-book-view .gb-sheet .gb-eval-head:focus-within{z-index:9}
#gr-book-view .gb-sheet .gb-head-actions .btn{height:25px;width:27px;padding:0!important;border:1px solid transparent!important;border-radius:.35rem;color:#64748b}
#gr-book-view .gb-sheet .gb-head-actions .btn:hover,#gr-book-view .gb-sheet .gb-head-actions .btn:focus{background:#e8eef8;border-color:#cdd9eb!important;color:#4e73df}
#gr-book-view .gb-sheet .gb-comp-start{border-left:2px solid #cbd8ee}
#gr-book-view .gb-sheet thead .gb-comp-average{background:#edf2fb!important;color:#385580;font-size:.7rem;letter-spacing:.025em;min-width:85px}
#gr-book-view .gb-sheet thead .gb-final-average{background:#dfe9fa!important;color:#2b4d86;min-width:96px}
#gr-book-view .gb-sheet tbody td{height:42px;padding:.27rem .42rem}
#gr-book-view .gb-sheet tbody tr:nth-child(even)>td:not(.gb-comp-average):not(.gb-final-average){background:#fafbfd}
#gr-book-view .gb-sheet tbody tr:hover>td:not(.gb-comp-average):not(.gb-final-average),
#gr-book-view .gb-sheet tbody tr:focus-within>td:not(.gb-comp-average):not(.gb-final-average){background:#f0f5fd}
#gr-book-view .gb-sheet .gb-number{min-width:42px;width:42px;left:0;text-align:center!important;color:#7b879b;font-size:.75rem}
#gr-book-view .gb-sheet .gb-student-head,#gr-book-view .gb-sheet .gb-student{left:42px;min-width:230px;max-width:230px;text-align:left!important}
#gr-book-view .gb-sheet .gb-student{font-size:.82rem;line-height:1.3;padding-left:.65rem}
#gr-book-view .gb-sheet .gb-student strong{font-weight:700;color:#34415a}
#gr-book-view .gb-sheet .gb-student small{font-size:.68rem}
#gr-book-view .gb-sheet tbody tr:focus-within .gb-student strong{color:#244d95}
#gr-book-view .gb-sheet .gb-eval-data{text-align:center;min-width:116px}
#gr-book-view .gb-sheet .gb-cell{height:30px;width:69px;min-width:69px;max-width:90px;margin:auto;padding:.15rem .25rem;font-size:.83rem;font-weight:700;border:1px solid transparent;border-radius:.35rem;background:transparent;box-shadow:none;transition:border-color .12s,box-shadow .12s,background-color .12s}
#gr-book-view .gb-sheet .gb-cell.gb-pending{background:transparent!important;color:#8793a6}
#gr-book-view .gb-sheet .gb-cell::placeholder{font-weight:400;color:#a7afbc;opacity:1}
#gr-book-view .gb-sheet .gb-cell:not(:disabled):hover{border-color:#cdd8ea;background:#fff}
#gr-book-view .gb-sheet .gb-cell:focus{border-color:#4e73df;background:#fff;box-shadow:0 0 0 2px rgba(78,115,223,.15);outline:none}
#gr-book-view .gb-sheet .gb-cell.gb-dirty{border-color:#edc862;background:#fff9e8!important}
#gr-book-view .gb-sheet .gb-cell.gb-invalid{border-color:#e74a3b;background:#fff1f0!important}
#gr-book-view .gb-sheet .gb-cell.gb-low{background:#fff1f0!important;color:#b02a37}
#gr-book-view .gb-sheet .gb-cell:disabled{color:#7d8795;background:#f3f5f8!important}
#gr-book-view .gb-sheet .gb-cell.gb-invalid:focus{border-color:#e74a3b;box-shadow:0 0 0 2px rgba(231,74,59,.15)}
#gr-book-view .gb-sheet tbody td.gb-comp-average{min-width:85px;color:#46618b;font-size:.85rem;background:#f1f5fc!important}
#gr-book-view .gb-sheet tbody td.gb-final-average{min-width:96px;color:#274e8d;font-size:.9rem;background:#e8f0fc!important;border-left:2px solid #bbcae3}
@media(max-width:767.98px){
 #gr-book-view .gb-sheet-wrap{max-height:64vh;border-radius:.35rem}
 #gr-book-view .gb-sheet .gb-student-head,#gr-book-view .gb-sheet .gb-student{min-width:185px;max-width:185px}
 #gr-book-view .gb-sheet .gb-eval-head,#gr-book-view .gb-sheet .gb-eval-data{min-width:110px}
 #gr-book-view .gb-sheet tbody td{height:40px}
}
@media(prefers-reduced-motion:reduce){
 #gr-book-view .gb-sheet .gb-cell{transition:none}
}

/* Centrado real del título y porcentaje de cada competencia, con el + alineado a la derecha. */
#gr-book-view .gb-sheet .gb-group-head{text-align:center!important}
#gr-book-view .gb-sheet .gb-group-inner{display:grid!important;grid-template-columns:26px minmax(0,1fr) 26px;align-items:center;column-gap:.35rem;padding:0;width:100%;min-width:0}
#gr-book-view .gb-sheet .gb-group-inner::before{content:"";display:block;grid-column:1;width:26px}
#gr-book-view .gb-sheet .gb-group-label{grid-column:2;display:flex;flex-wrap:wrap;align-items:center;justify-content:center;gap:.25rem .4rem;min-width:0;text-align:center}
#gr-book-view .gb-sheet .gb-group-label .gb-group-title{flex:0 1 auto;text-align:center;min-width:0;max-width:100%}
#gr-book-view .gb-sheet .gb-group-label .gb-group-percent{flex:0 0 auto;margin:0}
#gr-book-view .gb-sheet .gb-group-inner .gb-add-eval{grid-column:3;justify-self:end;margin:0!important}
</style>
<div class="container-fluid py-4">
 <div class="gr-header d-md-flex align-items-center justify-content-between mb-3"><div><h1 class="h4 text-gray-800 mb-1"><i class="fas fa-clipboard-list text-primary mr-2"></i>Evaluaciones y notas</h1><p class="text-muted mb-0">Controla el avance de calificación de cada curso y bimestre.</p></div><div class="mt-3 mt-md-0"><button class="btn btn-primary btn-sm" id="gr-new"><i class="fas fa-plus mr-1"></i>Nueva evaluación</button></div></div>
 <?php if(!$migrationReady): ?><div class="alert alert-warning gr-migration"><i class="fas fa-database mr-2"></i>Para habilitar anulación y auditoría histórica, ejecuta manualmente <strong>sql/grades_module_upgrade.sql</strong>. El listado y registro de notas continúan disponibles.</div><?php endif; ?>
 <div class="d-flex justify-content-center mb-3"><div class="gr-view-switch"><button class="btn btn-primary btn-sm" id="gr-show-list"><i class="fas fa-list mr-1"></i>Lista de evaluaciones</button><button class="btn btn-light btn-sm" id="gr-show-book"><i class="fas fa-table mr-1"></i>Libro de notas</button></div></div>
 <div id="gr-list-view">

 <div class="gr-filter p-3 mb-3"><div class="row align-items-end">
  <div class="col-lg-2 col-sm-6 mb-2"><label class="gr-label">Año académico</label><select id="gr-year" class="form-control form-control-sm"><?php foreach($years as$y): ?><option value="<?php echo(int)$y['id']; ?>" <?php echo(int)$y['id']===$activeYear?'selected':''; ?>><?php echo htmlspecialchars($y['year']); ?><?php echo(int)$y['is_active']===1?' (Activo)':''; ?></option><?php endforeach; ?></select></div>
  <div class="col-lg-2 col-sm-6 mb-2"><label class="gr-label">Curso</label><select id="gr-course" class="form-control form-control-sm"><option value="">Todos</option></select></div>
  <div class="col-lg-2 col-sm-6 mb-2"><label class="gr-label">Nivel</label><select id="gr-level" class="form-control form-control-sm"><option value="">Todos</option></select></div>
  <div class="col-lg-1 col-sm-4 mb-2"><label class="gr-label">Grado</label><select id="gr-grade" class="form-control form-control-sm"><option value="">Todos</option></select></div>
  <div class="col-lg-1 col-sm-4 mb-2"><label class="gr-label">Sección</label><select id="gr-section" class="form-control form-control-sm"><option value="">Todas</option></select></div>
  <div class="col-lg-2 col-sm-6 mb-2"><label class="gr-label">Bimestre</label><select id="gr-bimester" class="form-control form-control-sm"><option value="">Todos</option><option value="1">1° Bimestre</option><option value="2">2° Bimestre</option><option value="3">3° Bimestre</option><option value="4">4° Bimestre</option></select></div>
  <div class="col-lg-2 col-sm-6 mb-2"><label class="gr-label">Buscar</label><input id="gr-search" class="form-control form-control-sm" placeholder="Título o descripción"></div>
 </div><div class="d-flex flex-wrap justify-content-between align-items-center mt-1"><div class="gr-tabs d-flex flex-wrap" id="gr-tabs"><button class="btn btn-primary btn-sm" data-state="all">Todas</button><button class="btn btn-outline-primary btn-sm" data-state="pending">Pendientes</button><button class="btn btn-outline-primary btn-sm" data-state="partial">En proceso</button><button class="btn btn-outline-primary btn-sm" data-state="complete">Completadas</button><button class="btn btn-outline-primary btn-sm" data-state="locked">Bloqueadas</button><button class="btn btn-outline-primary btn-sm" data-state="annulled">Anuladas</button></div><div class="d-flex flex-wrap align-items-center mt-2 mt-md-0 gr-filter-actions"><button type="button" class="btn btn-outline-success btn-sm gc-open-closure" data-gc-view="list" title="Revisar el cierre del curso y bimestre seleccionados"><i class="fas fa-lock mr-1"></i>Cierre bimestral</button><button class="btn btn-light btn-sm" id="gr-clear"><i class="fas fa-eraser mr-1"></i>Limpiar filtros</button></div></div></div>
 <div class="card shadow-sm"><div class="card-header bg-white d-flex justify-content-between align-items-center"><strong><i class="fas fa-list text-primary mr-2"></i>Mis evaluaciones</strong><span class="badge badge-light border" id="gr-count">0 registros</span></div><div class="card-body p-0"><div class="table-responsive"><table class="table table-hover gr-table mb-0" id="gr-table"><thead class="thead-light"><tr><th>Evaluación</th><th>Curso y aula</th><th>Competencia</th><th>Bimestre</th><th>Avance de notas</th><th>Estado</th><th>Fecha</th><th>Acciones</th></tr></thead><tbody><tr><td colspan="8" class="gr-empty"><i class="fas fa-spinner fa-spin mr-2"></i>Cargando evaluaciones...</td></tr></tbody></table></div></div><div class="card-footer bg-white gr-pager" id="gr-pager"><div class="d-flex flex-wrap align-items-center justify-content-between gr-pager-layout"><div class="d-flex flex-wrap align-items-center gr-pager-summary"><small class="text-muted" id="gr-page-info" aria-live="polite">0 evaluaciones</small><label for="gr-page-size" class="small text-muted mb-0 ml-3 mr-2">Mostrar</label><select class="form-control form-control-sm gr-page-size" id="gr-page-size" aria-label="Evaluaciones por página"><option value="10" selected>10</option><option value="25">25</option><option value="50">50</option></select><small class="text-muted ml-2">por página</small></div><nav aria-label="Paginación de evaluaciones"><ul class="pagination pagination-sm mb-0" id="gr-pagination"></ul></nav></div></div></div>
 </div>
 <div id="gr-book-view" style="display:none">
  <div class="gr-filter p-3 mb-3"><div class="row align-items-end">
   <div class="col-xl-2 col-md-4 col-sm-6 mb-2"><label class="gr-label">Año académico</label><select id="gb-year" class="form-control form-control-sm"><?php foreach($years as$y): ?><option value="<?php echo(int)$y['id']; ?>" <?php echo(int)$y['id']===$activeYear?'selected':''; ?>><?php echo htmlspecialchars($y['year']); ?><?php echo(int)$y['is_active']===1?' (Activo)':''; ?></option><?php endforeach; ?></select></div>
   <div class="col-xl-2 col-md-4 col-sm-6 mb-2"><label class="gr-label">Curso</label><select id="gb-course" class="form-control form-control-sm"><option value="">Selecciona</option></select></div>
   <div class="col-xl-2 col-md-4 col-sm-6 mb-2"><label class="gr-label">Nivel</label><select id="gb-level" class="form-control form-control-sm" disabled><option value="">Selecciona</option></select></div>
   <div class="col-xl-1 col-md-3 col-sm-6 mb-2"><label class="gr-label">Grado</label><select id="gb-grade" class="form-control form-control-sm" disabled><option value="">Selecciona</option></select></div>
   <div class="col-xl-1 col-md-3 col-sm-6 mb-2"><label class="gr-label">Sección</label><select id="gb-section" class="form-control form-control-sm" disabled><option value="">Selecciona</option></select></div>
   <div class="col-xl-2 col-md-3 col-sm-6 mb-2"><label class="gr-label">Bimestre</label><select id="gb-bimester" class="form-control form-control-sm"><option value="">Selecciona</option><option value="1">1° Bimestre</option><option value="2">2° Bimestre</option><option value="3">3° Bimestre</option><option value="4">4° Bimestre</option></select></div>
   <div class="col-xl-2 col-md-3 col-sm-6 mb-2"><label class="gr-label">Visualización</label><select id="gb-system" class="form-control form-control-sm"><option value="numeric">Notas numéricas</option><option value="letters">Notas literales</option></select></div>
  </div><input type="hidden" id="gb-assignment" value=""><div class="d-flex flex-wrap justify-content-end align-items-center mt-1"><div class="d-flex flex-wrap align-items-center gb-filter-actions"><button type="button" class="btn btn-outline-success btn-sm gc-open-closure" data-gc-view="book" title="Revisar el cierre del curso y bimestre seleccionados"><i class="fas fa-lock mr-1"></i>Cierre bimestral</button><button class="btn btn-outline-primary btn-sm" id="gb-load"><i class="fas fa-sync-alt mr-1"></i>Actualizar planilla</button></div></div></div>
  <div id="gb-readonly-banner" class="alert alert-warning gb-readonly" style="display:none"><i class="fas fa-lock mr-2"></i><span></span></div>
  <div id="gb-weight-warning" class="alert alert-danger" style="display:none"><i class="fas fa-exclamation-triangle mr-2"></i><strong>Configuración de competencias incompleta.</strong> <span></span></div>

  <div class="gb-summary gb-compact-controls mb-2">
   <div class="gb-toolbar d-flex flex-wrap align-items-center">
    <select id="gb-student-filter" class="form-control form-control-sm" aria-label="Filtrar estudiantes"><option value="all">Todos los estudiantes</option><option value="pending">Con notas pendientes</option><option value="complete">Calificación completa</option><option value="low">Con notas bajas</option></select>
    <div class="custom-control custom-switch gb-autosave-switch d-none"><input type="checkbox" class="custom-control-input" id="gb-autosave"><label class="custom-control-label small" for="gb-autosave">Autoguardado</label></div>
    <div class="gb-save-status d-flex align-items-center ml-auto"><span class="gb-sync text-muted" id="gb-sync-state" role="status" aria-live="polite">Sin cambios</span><span class="badge badge-light border" id="gb-change-count">0 cambios</span></div>
    <div class="gb-save-actions d-flex align-items-center"><button type="button" class="btn btn-outline-secondary btn-sm gb-icon-action" id="gb-discard" disabled title="Descartar cambios" aria-label="Descartar cambios"><i class="fas fa-undo"></i></button><button type="button" class="btn btn-primary btn-sm gb-icon-action" id="gb-save" disabled title="Guardar notas" aria-label="Guardar notas"><i class="fas fa-save"></i></button></div>
   </div>
  </div>
  <div class="card shadow-sm"><div class="card-body p-0" id="gb-container"><div class="gb-empty"><i class="fas fa-table fa-2x d-block mb-2"></i>Selecciona un curso y bimestre para abrir el libro de notas.</div></div></div>
 </div>
</div>
<script>
(function($){
 const csrf=<?php echo json_encode($csrf); ?>,migrationReady=<?php echo $migrationReady?'true':'false'; ?>;let state='all',timer=null;
 function esc(v){return $('<div>').text(v==null?'':v).html()}function grade(v){v=String(v||'').replace(/[°º]+/g,'').trim();return v+(v?'°':'')}
 const stateInfo={pending:['Sin iniciar','secondary','fa-hourglass-start'],partial:['En proceso','warning','fa-spinner'],complete:['Completa','success','fa-check'],locked:['Bloqueada','dark','fa-lock'],annulled:['Anulada','danger','fa-ban']};
 function filters(){return{academic_year_id:$('#gr-year').val(),level:$('#gr-level').val(),grado:$('#gr-grade').val(),seccion:$('#gr-section').val(),course:$('#gr-course').val(),bimestre:$('#gr-bimester').val(),search:$('#gr-search').val(),state:state}}

 let grContexts=[],grContextRequest=0,grListRequest=0;let grRows=[],grPage=1,grPageSize=10,grFilterSignature='';
 function grNorm(v){return String(v==null?'':v).trim().replace(/[°º]+/g,'')}
 function grUnique(rows,key){return [...new Set(rows.map(a=>grNorm(a[key]||(key==='seccion'?'U':''))).filter(Boolean))].sort((a,b)=>a.localeCompare(b,'es',{numeric:true}))}
 function grFill(selector,values,label,prior,isGrade,autoSelect){
   let chosen=values.includes(prior)?prior:(autoSelect!==false&&values.length===1?values[0]:'');
   let html='<option value="">'+label+'</option>';
   values.forEach(v=>html+='<option value="'+esc(v)+'"'+(v===chosen?' selected':'')+'>'+esc(isGrade?grade(v):v)+'</option>');
   $(selector).html(html).prop('disabled',values.length===0);
   return chosen;
 }
 function grChain(start,previous,autoSelect){
   previous=previous||{};
   let course=previous.course!==undefined?previous.course:$('#gr-course').val();
   grFill('#gr-course',grUnique(grContexts,'course_name'),'Todos los cursos',grNorm(course),false,autoSelect);
   let rows=grContexts.filter(a=>grNorm(a.course_name)===grNorm($('#gr-course').val()));
   let level=previous.level!==undefined?previous.level:$('#gr-level').val();
   grFill('#gr-level',grUnique(rows,'level'),'Todos los niveles',grNorm(level),false,autoSelect);
   rows=rows.filter(a=>grNorm(a.level)===grNorm($('#gr-level').val()));
   let grado=previous.grade!==undefined?previous.grade:$('#gr-grade').val();
   grFill('#gr-grade',grUnique(rows,'grado'),'Todos los grados',grNorm(grado),true,autoSelect);
   rows=rows.filter(a=>grNorm(a.grado)===grNorm($('#gr-grade').val()));
   let section=previous.section!==undefined?previous.section:$('#gr-section').val();
   grFill('#gr-section',grUnique(rows,'seccion'),'Todas las secciones',grNorm(section),false,autoSelect);
 }
 function loadOptions(){
   let ticket=++grContextRequest;
   let previous={course:$('#gr-course').val(),level:$('#gr-level').val(),grade:$('#gr-grade').val(),section:$('#gr-section').val()};
   $('#gr-course,#gr-level,#gr-grade,#gr-section').prop('disabled',true);
   $.getJSON('gradebook_api.php',{action:'contexts',academic_year_id:$('#gr-year').val()}).done(r=>{
     if(ticket!==grContextRequest)return;
     grContexts=r&&r.status==1?(r.assignments||[]):[];
     grChain(0,previous);load();
   }).fail(()=>{
     if(ticket!==grContextRequest)return;
     grContexts=[];grChain(0,{});load();
     if(typeof alert_toast==='function')alert_toast('No se pudieron consultar los cursos asignados.','warning');
   });
 }


 function load(){let signature=JSON.stringify(filters());if(signature!==grFilterSignature){grFilterSignature=signature;grPage=1}let ticket=++grListRequest;let $body=$('#gr-table tbody').html('<tr><td colspan="8" class="gr-empty"><i class="fas fa-spinner fa-spin mr-2"></i>Cargando...</td></tr>');$.post('ajax.php?action=get_teacher_evaluations',filters(),null,'json').done(r=>{if(ticket!==grListRequest)return;if(r.status!=1){grRows=[];updatePager();$body.html('<tr><td colspan="8" class="gr-empty text-danger">'+esc(r.msg||'No se pudo cargar.')+'</td></tr>');return}render(r.data||[]);$.each(r.summary||{},(k,v)=>$('[data-summary="'+k+'"]').text(v));}).fail(x=>{if(ticket!==grListRequest)return;grRows=[];updatePager();$body.html('<tr><td colspan="8" class="gr-empty text-danger">'+esc((x.responseJSON||{}).msg||'Error al consultar evaluaciones.')+'</td></tr>')})}
 
 function updatePager(){
   const total=grRows.length,pages=Math.max(1,Math.ceil(total/grPageSize)),from=total?(grPage-1)*grPageSize+1:0,to=Math.min(grPage*grPageSize,total);
   $('#gr-page-info').text(total?'Mostrando '+from+'–'+to+' de '+total+' evaluaciones':'0 evaluaciones');
   const nav=$('#gr-pagination');if(total===0||pages<=1){nav.empty();return;}
   let h='';
   function add(n,label,disabled,active){h+='<li class="page-item'+(disabled?' disabled':'')+(active?' active':'')+'"><button type="button" class="page-link" data-page="'+n+'"'+(disabled?' disabled':'')+(active?' aria-current="page"':'')+'>'+label+'</button></li>';}
   add(grPage-1,'Anterior',grPage===1,false);
   let begin=Math.max(1,grPage-2),end=Math.min(pages,begin+4);begin=Math.max(1,end-4);
   if(begin>1){add(1,'1',false,false);if(begin>2)h+='<li class="page-item disabled"><span class="page-link">…</span></li>';}
   for(let n=begin;n<=end;n++)add(n,String(n),false,n===grPage);
   if(end<pages){if(end<pages-1)h+='<li class="page-item disabled"><span class="page-link">…</span></li>';add(pages,String(pages),false,false);}
   add(grPage+1,'Siguiente',grPage===pages,false);nav.html(h);
 }
 $('#gr-page-size').on('change',function(){const n=Number(this.value);grPageSize=[10,25,50].includes(n)?n:10;grPage=1;render(grRows);});
 $('#gr-pagination').on('click','button[data-page]',function(){const n=Number(this.dataset.page),pages=Math.max(1,Math.ceil(grRows.length/grPageSize));if(!Number.isInteger(n)||n<1||n>pages||n===grPage)return;grPage=n;render(grRows);});
function render(rows){grRows=Array.isArray(rows)?rows:[];const pages=Math.max(1,Math.ceil(grRows.length/grPageSize));grPage=Math.min(Math.max(1,grPage),pages);const visibleRows=grRows.slice((grPage-1)*grPageSize,grPage*grPageSize);$('#gr-count').text(grRows.length+' registro'+(grRows.length===1?'':'s'));if(!grRows.length){$('#gr-table tbody').html('<tr><td colspan="8" class="gr-empty"><i class="far fa-folder-open fa-2x d-block mb-2"></i>No hay evaluaciones para estos filtros.</td></tr>');updatePager();return}let h='';visibleRows.forEach(e=>{let si=stateInfo[e.state]||stateInfo.pending,canWrite=e.is_active&&e.state!=='locked'&&e.state!=='annulled',has=+e.grades_count>0;h+='<tr><td class="gr-title"><strong>'+esc(e.title)+'</strong><br><small class="text-muted">'+esc(e.type||'Sin tipo')+'</small></td><td class="gr-course"><strong>'+esc(e.course_name)+'</strong><br><small class="text-muted">'+esc(e.level)+' · '+esc(grade(e.grado))+' '+esc(e.seccion||'U')+' · '+esc(e.academic_year)+'</small></td><td><small>'+esc(e.competency_name||'No oficial / sin competencia')+'</small></td><td><span class="badge badge-light border">'+esc(e.bimestre?e.bimestre+'° Bimestre':'Sin asignar')+'</span></td><td><div class="d-flex justify-content-between small"><span>'+e.graded_students+' de '+e.expected_students+'</span><strong>'+e.progress+'%</strong></div><div class="progress gr-progress"><div class="progress-bar" style="width:'+e.progress+'%"></div></div></td><td><span class="badge badge-'+si[1]+' gr-state"><i class="fas '+si[2]+' mr-1"></i>'+si[0]+'</span></td><td><small>'+formatDate(e.created_at)+'</small></td><td class="gr-actions">';if(canWrite)h+='<button class="btn btn-outline-primary btn-sm gr-edit" data-id="'+e.id+'" title="Editar"><i class="fas fa-edit"></i></button> ';if(canWrite)h+='<button class="btn btn-primary btn-sm gr-notes" data-id="'+e.id+'" title="'+(has?'Continuar notas':'Registrar notas')+'"><i class="fas fa-pen"></i></button> ';if(canWrite)h+='<button class="btn btn-outline-danger btn-sm gr-remove" data-id="'+e.id+'" data-has="'+(has?1:0)+'" title="'+(has?'Anular':'Eliminar')+'"><i class="fas '+(has?'fa-ban':'fa-trash')+'"></i></button>';else h+='<button class="btn btn-info btn-sm gr-view-notes" data-id="'+e.id+'" title="Ver notas en modo lectura"><i class="fas fa-eye"></i></button> ';h+='</td></tr>'});$('#gr-table tbody').html(h);updatePager()}
 window.formatDate=function(s){if(!s)return'—';let d=new Date(String(s).replace(' ','T'));return isNaN(d)?esc(s):d.toLocaleDateString('es-PE',{day:'2-digit',month:'2-digit',year:'numeric'})}
 $('#gr-new').click(()=>uni_modal('Nueva evaluación','manage_evaluation.php','modal-xl'));$(document).on('click','.gr-edit',function(){uni_modal('Editar evaluación','manage_evaluation.php?id='+this.dataset.id,'modal-xl')});$(document).on('click','.gr-notes',function(){uni_modal('Registrar notas','manage_evaluation_grades.php?evaluation_id='+this.dataset.id,'modal-xl')});$(document).on('click','.gr-view-notes',function(){uni_modal('Ver notas (Lectura)','manage_evaluation_grades.php?evaluation_id='+this.dataset.id+'&view_only=1','modal-xl')});
 $(document).on('click','.gr-remove',function(){let id=this.dataset.id,has=this.dataset.has==='1',reason='';if(has){if(!migrationReady){alert_toast('Ejecute sql/grades_module_upgrade.sql para anular conservando las notas.','warning');return}reason=prompt('Motivo de anulación (las notas se conservarán):','');if(reason===null)return;if(!reason.trim()){alert_toast('El motivo es obligatorio.','warning');return}}else if(!confirm('¿Eliminar esta evaluación? Solo es posible porque todavía no tiene notas.'))return;start_load();$.post('ajax.php?action=delete_evaluation',{id:id,reason:reason,csrf_token:csrf},null,'json').done(r=>{alert_toast(r.message||'Operación completada.',r.status==1?'success':'danger');if(r.status==1)load()}).always(end_load)});
 $('#gr-tabs button').click(function(){state=this.dataset.state;$('#gr-tabs button').removeClass('btn-primary').addClass('btn-outline-primary');$(this).removeClass('btn-outline-primary').addClass('btn-primary');load()});
 $('#gr-year').on('change',loadOptions);
 $('#gr-course').on('change',function(){grChain(1,{level:'',grade:'',section:''});load()});
 $('#gr-level').on('change',function(){grChain(2,{grade:'',section:''});load()});
 $('#gr-grade').on('change',function(){grChain(3,{section:''});load()});
 $('#gr-section,#gr-bimester').on('change',load);
 $('#gr-search').on('input',function(){clearTimeout(timer);timer=setTimeout(load,350)});
 $('#gr-clear').on('click',function(){
   $('#gr-course,#gr-level,#gr-grade,#gr-section,#gr-bimester,#gr-search').val('');
   grChain(0,{course:'',level:'',grade:'',section:''},false);
   state='all';$('#gr-tabs button').removeClass('btn-primary').addClass('btn-outline-primary').first().removeClass('btn-outline-primary').addClass('btn-primary');load();
 });
 $(document).on('evaluation:saved evaluation:gradesSaved',load);
 loadOptions();

 // Libro de notas: segunda vista sobre los mismos registros de evaluation_grades.
 let gradebook=null,gradebookChanges={},gradebookAssignments=[];
 function switchMainView(view){let book=view==='book';if(!book&&Object.keys(gradebookChanges).length&&!confirm('Hay notas modificadas sin guardar. ¿Deseas salir del libro?'))return;$('#gr-list-view').toggle(!book);$('#gr-book-view').toggle(book);$('#gr-show-list').toggleClass('btn-primary',!book).toggleClass('btn-light',book);$('#gr-show-book').toggleClass('btn-primary',book).toggleClass('btn-light',!book);if(book&&!gradebookAssignments.length)loadAssignments()}
 $('#gr-show-list').on('click',()=>switchMainView('list'));$('#gr-show-book').on('click',()=>switchMainView('book'));
 function uniqueValues(rows,key){return [...new Set(rows.map(r=>String(r[key]||'').trim()).filter(Boolean))].sort((a,b)=>a.localeCompare(b,'es',{numeric:true}))}
 function fillBookFilter(selector,values,previous,label){let selected=values.indexOf(previous)>=0?previous:(values.length===1?values[0]:'');let html='<option value="">'+label+'</option>';values.forEach(v=>html+='<option value="'+esc(v)+'"'+(v===selected?' selected':'')+'>'+esc(selector==='#gb-grade'?grade(v):v)+'</option>');$(selector).html(html).prop('disabled',!values.length);return selected}
 function populateBookCourses(previous){return fillBookFilter('#gb-course',uniqueValues(gradebookAssignments,'course_name'),previous||'','Selecciona un curso')}
 function populateBookLevels(previous){let course=$('#gb-course').val(),rows=gradebookAssignments.filter(a=>a.course_name===course);return fillBookFilter('#gb-level',uniqueValues(rows,'level'),previous||'','Selecciona un nivel')}
 function populateBookGrades(previous){let course=$('#gb-course').val(),level=$('#gb-level').val(),rows=gradebookAssignments.filter(a=>a.course_name===course&&a.level===level);return fillBookFilter('#gb-grade',uniqueValues(rows,'grado'),previous||'','Selecciona un grado')}
 function populateBookSections(previous){let course=$('#gb-course').val(),level=$('#gb-level').val(),grado=$('#gb-grade').val(),rows=gradebookAssignments.filter(a=>a.course_name===course&&a.level===level&&String(a.grado)===String(grado));let selected=fillBookFilter('#gb-section',uniqueValues(rows,'seccion'),previous||'','Selecciona una sección');resolveBookAssignment();return selected}
 function resolveBookAssignment(){let matches=gradebookAssignments.filter(a=>a.course_name===$('#gb-course').val()&&a.level===$('#gb-level').val()&&String(a.grado)===String($('#gb-grade').val())&&String(a.seccion)===String($('#gb-section').val()));$('#gb-assignment').val(matches.length===1?matches[0].id:'');if(matches.length===1&&$('#gb-bimester').val())loadGradebook()}
 function loadAssignments(){let previous={course:$('#gb-course').val(),level:$('#gb-level').val(),grade:$('#gb-grade').val(),section:$('#gb-section').val()};$('#gb-course,#gb-level,#gb-grade,#gb-section').prop('disabled',true);$.getJSON('gradebook_api.php',{action:'contexts',academic_year_id:$('#gb-year').val()}).done(r=>{gradebookAssignments=r.status==1?(r.assignments||[]).map(a=>Object.assign({},a,{course_name:String(a.course_name||'').trim(),level:String(a.level||'').trim(),grado:String(a.grado||'').trim(),seccion:String(a.seccion||'U').trim()||'U'})):[];populateBookCourses(previous.course);populateBookLevels(previous.level);populateBookGrades(previous.grade);populateBookSections(previous.section);if(!gradebookAssignments.length)resetGradebook('No tienes cursos asignados en el año seleccionado.')}).fail(()=>{gradebookAssignments=[];resetGradebook('No se pudieron cargar las asignaciones del docente.')})}
 $('#gb-year').on('change',function(){gradebook=null;gradebookChanges={};gradebookAssignments=[];$('#gb-assignment').val('');loadAssignments();resetGradebook('Selecciona curso, nivel, grado, sección y bimestre.')});
 $('#gb-course').on('change',function(){populateBookLevels('');populateBookGrades('');populateBookSections('')});$('#gb-level').on('change',function(){populateBookGrades('');populateBookSections('')});$('#gb-grade').on('change',function(){populateBookSections('')});$('#gb-section').on('change',resolveBookAssignment);
 function resetGradebook(message){$('#gb-container').html('<div class="gb-empty"><i class="fas fa-table fa-2x d-block mb-2"></i>'+esc(message)+'</div>');$('#gb-readonly-banner').hide();updateChangeCount()}
 $('#gb-load').on('click',loadGradebook);$('#gb-bimester').on('change',function(){if($('#gb-assignment').val())loadGradebook()});
 function loadGradebook(){if(Object.keys(gradebookChanges).length&&!confirm('Se descartarán los cambios no guardados. ¿Continuar?'))return;clearTimeout(autosaveTimer);autosaveTimer=null;let assignment=$('#gb-assignment').val(),bimester=$('#gb-bimester').val();if(!assignment||!bimester){resetGradebook('Selecciona un curso y un bimestre.');return}gradebookChanges={};updateChangeCount();$('#gb-container').html('<div class="gb-empty"><i class="fas fa-spinner fa-spin mr-2"></i>Cargando libro de notas...</div>');$.getJSON('gradebook_api.php',{action:'load',teacher_course_id:assignment,bimestre:bimester}).done(r=>{if(r.status!=1){resetGradebook(r.message||'No se pudo cargar el libro.');return}gradebook=r;renderGradebook()}).fail(x=>resetGradebook((x.responseJSON||{}).message||'No se pudo consultar el libro de notas.'))}
 function gradebookGroups(){let groups=[],evaluations=gradebook.evaluations||[];(gradebook.competencies||[]).forEach(c=>groups.push({id:+c.id,name:c.name,percentage:+c.percentage||0,evaluations:evaluations.filter(e=>+e.competencia_id===+c.id)}));let orphan=evaluations.filter(e=>!groups.some(g=>g.id===+e.competencia_id));if(orphan.length)groups.push({id:0,name:'Sin competencia',percentage:0,evaluations:orphan});return groups}
 function enhanceEvaluationMenus(){$('.gb-head-actions').each(function(){let edit=$(this).find('.gb-edit-eval'),remove=$(this).find('.gb-delete-eval');if(!edit.length||!remove.length)return;let id=edit.data('id'),evaluation=(gradebook.evaluations||[]).find(e=>+e.id===+id),comp=evaluation?evaluation.competencia_id:0,has=remove.data('has');$(this).css({opacity:1,height:'auto'}).html('<div class="dropdown"><button class="btn btn-light btn-sm" data-toggle="dropdown" title="Acciones de evaluación" aria-label="Acciones de evaluación"><i class="fas fa-ellipsis-h"></i></button><div class="dropdown-menu dropdown-menu-right shadow"><button class="dropdown-item gb-edit-eval" data-id="'+id+'"><i class="fas fa-edit text-primary mr-2"></i>Editar detalles</button><button class="dropdown-item gb-duplicate-eval" data-id="'+id+'" data-comp="'+comp+'"><i class="fas fa-copy text-info mr-2"></i>Duplicar evaluación</button><div class="dropdown-divider"></div><button class="dropdown-item text-danger gb-delete-eval" data-id="'+id+'" data-has="'+has+'"><i class="fas '+(has?'fa-ban':'fa-trash')+' mr-2"></i>'+(has?'Anular evaluación':'Eliminar evaluación')+'</button></div></div>')})}
 function renderGradebook(){let a=gradebook.assignment,students=gradebook.students||[],evaluations=gradebook.evaluations||[],groups=gradebookGroups();$('#gb-readonly-banner').toggle(!!gradebook.read_only).find('span').text(gradebook.read_only_reason||'Modo de solo lectura.');let weightTotal=Number(gradebook.competency_weight_total||0),weightsValid=!!gradebook.competency_weights_valid;$('#gb-weight-warning').toggle(!weightsValid).find('span').text('Las competencias oficiales suman '+weightTotal.toFixed(2)+'%. Deben sumar 100%. Mientras no se corrija, este curso no se usará en la Alerta Temprana Inteligente.');if(!groups.length){resetGradebook('Este curso todavía no tiene competencias configuradas. Configúralas antes de crear evaluaciones.');return}if(!students.length){resetGradebook('No hay estudiantes disponibles para esta asignación.');return}let h='<div class="gb-sheet-wrap"><table class="gb-sheet"><thead><tr><th class="gb-number" rowspan="2">#</th><th class="gb-student-head" rowspan="2">APELLIDOS Y NOMBRES</th>';groups.forEach(g=>{let evalColumns=Math.max(1,g.evaluations.length);h+='<th class="gb-group-head" colspan="'+(evalColumns+1)+'"><div class="gb-group-inner"><div class="gb-group-label"><span class="gb-group-title" title="'+esc(g.name)+'">'+esc(g.name)+'</span><span class="gb-group-percent">'+g.percentage.toFixed(2)+'%</span></div>';if(!gradebook.read_only&&g.id>0)h+='<button class="btn btn-primary btn-sm gb-add-eval" data-comp="'+g.id+'" title="Crear evaluación en esta competencia" aria-label="Crear evaluación en '+esc(g.name)+'"><i class="fas fa-plus"></i></button>';h+='</div></th>'});h+='<th class="gb-final-average" rowspan="2">PROMEDIO<br>FINAL</th></tr><tr>';groups.forEach(g=>{if(g.evaluations.length)g.evaluations.forEach((e,idx)=>{h+='<th class="gb-eval-head'+(idx===0?' gb-comp-start':'')+'" title="'+esc(e.title)+'"><div class="gb-eval-heading"><span class="gb-eval-title">'+esc(e.title)+'</span>';if(!gradebook.read_only&&!e.annulled){h+='<div class="gb-head-actions"><button class="btn btn-outline-primary btn-sm gb-edit-eval" data-id="'+e.id+'" title="Editar evaluación"><i class="fas fa-edit"></i></button> <button class="btn btn-outline-danger btn-sm gb-delete-eval" data-id="'+e.id+'" data-has="'+(e.grades_count>0?1:0)+'" title="'+(e.grades_count>0?'Anular evaluación':'Eliminar evaluación')+'"><i class="fas '+(e.grades_count>0?'fa-ban':'fa-trash')+'"></i></button></div>'}h+='</div>';if(e.annulled)h+='<div class="gb-eval-status"><span class="badge badge-danger gb-eval-annulled" title="Evaluación anulada"><i class="fas fa-ban" aria-hidden="true"></i>Anulada</span></div>';h+='</th>'});else h+='<th class="gb-placeholder gb-comp-start">Sin evaluaciones</th>';h+='<th class="gb-comp-average">PROMEDIO</th>'});h+='</tr></thead><tbody>';students.forEach((s,index)=>{h+='<tr data-student="'+s.id+'"><td class="gb-number">'+(index+1)+'</td><td class="gb-student"><strong>'+esc(s.name)+'</strong><br><small class="text-muted">'+esc(s.id_no||'Sin DNI')+'</small></td>';groups.forEach(g=>{if(g.evaluations.length)g.evaluations.forEach((e,idx)=>{let value=gradebook.grades[e.id]&&gradebook.grades[e.id][s.id]!=null?gradebook.grades[e.id][s.id]:'',disabled=gradebook.read_only||e.annulled||!e.competencia_id;h+='<td class="gb-eval-data'+(idx===0?' gb-comp-start':'')+'"><input type="text" inputmode="decimal" autocomplete="off" placeholder="—" class="form-control form-control-sm gb-cell" data-evaluation="'+e.id+'" data-competency="'+g.id+'" data-annulled="'+(e.annulled?1:0)+'" data-student="'+s.id+'" data-original="'+esc(value)+'" value="'+esc(value)+'" '+(disabled?'disabled':'')+' title="'+esc(e.title)+'"></td>'});else h+='<td class="gb-placeholder gb-comp-start">—</td>';h+='<td class="gb-comp-average" data-average-comp="'+g.id+'">—</td>'});h+='<td class="gb-final-average">—</td></tr>'});h+='</tbody></table></div>';$('#gb-container').html(h);updateAllAverages();updateChangeCount()}
 const renderGradebookBase=renderGradebook;renderGradebook=function(){renderGradebookBase();enhanceEvaluationMenus();updateGradebookSummary();applyStudentFilter(true)};
 function cellKey(cell){return cell.dataset.evaluation+':'+cell.dataset.student}function normalizeGrade(v){return String(v==null?'':v).trim().toUpperCase()}
 function validGrade(value){if(value==='')return true;if($('#gb-system').val()==='letters')return ['C','B','A','AD'].indexOf(value)>=0;let n=Number(value);return value!==''&&!isNaN(n)&&n>=0&&n<=20}
 function trackCell(cell){let value=normalizeGrade(cell.value),original=normalizeGrade(cell.dataset.original),key=cellKey(cell),dirty=value!==original;cell.value=value;$(cell).toggleClass('gb-invalid',dirty&&!validGrade(value));if(dirty){gradebookChanges[key]={evaluation_id:+cell.dataset.evaluation,student_id:+cell.dataset.student,grade:value,original:original};$(cell).addClass('gb-dirty')}else{delete gradebookChanges[key];$(cell).removeClass('gb-dirty')}updateRowAverage($(cell).closest('tr'));updateGradebookSummary();applyStudentFilter();updateChangeCount();gradebookEditRevision++;scheduleGradebookAutosave()}
 function updateChangeCount(){let count=Object.keys(gradebookChanges).length,invalid=$('.gb-cell.gb-invalid').length;$('#gb-change-count').text(count+' cambio'+(count===1?'':'s')).toggleClass('badge-warning',count>0).toggleClass('badge-light',count===0);$('#gb-save').prop('disabled',!count||invalid>0||!!(gradebook&&gradebook.read_only));$('#gb-discard').prop('disabled',!count);if(!gradebookSaving)$('#gb-sync-state').text(count?(invalid?'Corrige los errores':'Cambios pendientes'):'Sin cambios')}
 function numericGrade(value){value=normalizeGrade(value);if(value==='')return null;if(!isNaN(Number(value)))return Number(value);return{C:5,B:12,A:15.5,AD:19}[value]??null}function calculatedGrade(value){if(value==null)return'—';if($('#gb-system').val()==='letters'){if(value>=18)return'AD';if(value>=14)return'A';if(value>=11)return'B';return'C'}return String(Math.round(value))}
 function updateRowAverage(row){let weighted=0,hasWeight=false;gradebookGroups().forEach(g=>{let values=[];row.find('.gb-cell[data-competency="'+g.id+'"][data-annulled="0"]').each(function(){let value=numericGrade(this.value);if(value!=null)values.push(value)});let average=values.length?values.reduce((a,b)=>a+b,0)/values.length:null;row.find('[data-average-comp="'+g.id+'"]').text(calculatedGrade(average));if(average!=null&&g.percentage>0){weighted+=average*(g.percentage/100);hasWeight=true}});row.find('.gb-final-average').text(calculatedGrade(hasWeight?weighted:null)).attr('data-numeric',hasWeight?weighted:'')}
 function updateAllAverages(){$('.gb-sheet tbody tr').each(function(){updateRowAverage($(this))})}
 function updateGradebookSummary(){let cells=$('.gb-cell[data-annulled="0"]'),pending=0;cells.each(function(){let empty=normalizeGrade(this.value)==='',low=!empty&&numericGrade(this.value)<=10;pending+=empty?1:0;$(this).toggleClass('gb-pending',empty).toggleClass('gb-low',low).toggleClass('gb-history-ready',!!(gradebook&&gradebook.history_ready))});}
 function rowGradeState(row){let cells=row.find('.gb-cell[data-annulled="0"]'),pending=false,low=false;cells.each(function(){let value=normalizeGrade(this.value);if(value==='')pending=true;else if(numericGrade(value)<=10)low=true});return{pending:pending,complete:cells.length>0&&!pending,low:low}}
 // En "Con notas pendientes" se mantiene visible el grupo inicialmente filtrado.
 // Así el estudiante no desaparece al completar su última nota mientras se edita.
 let pendingStudentsSnapshot=null;
 function applyStudentFilter(refreshSnapshot){
   let filter=$('#gb-student-filter').val()||'all';
   if(filter==='pending'&&(refreshSnapshot===true||pendingStudentsSnapshot===null)){
     pendingStudentsSnapshot=new Set();
     $('.gb-sheet tbody tr').each(function(){
       if(rowGradeState($(this)).pending)pendingStudentsSnapshot.add(String(this.dataset.student));
     });
   }else if(filter!=='pending'){
     pendingStudentsSnapshot=null;
   }
   $('.gb-sheet tbody tr').each(function(){
     let visible=filter==='all'||(filter==='pending'
       ?pendingStudentsSnapshot.has(String(this.dataset.student))
       :!!rowGradeState($(this))[filter]);
     $(this).toggleClass('gb-row-hidden',!visible);
   });
 }
 $('#gb-student-filter').on('change',function(){applyStudentFilter(true)});
 $(document).on('input change','.gb-cell',function(){trackCell(this)});
 $(document).on('dblclick','.gb-cell',function(){if(!gradebook||!gradebook.history_ready){alert_toast('Ejecuta sql/gradebook_history_upgrade.sql para consultar el historial de cada nota.','warning');return}uni_modal('Historial de nota','grade_history.php?evaluation_id='+encodeURIComponent(this.dataset.evaluation)+'&student_id='+encodeURIComponent(this.dataset.student),'modal-lg')});
 $(document).on('keydown','.gb-cell',function(e){let row=$(this).closest('tr'),rows=$('.gb-sheet tbody tr'),rowIndex=rows.index(row),cells=row.find('.gb-cell'),colIndex=cells.index(this),target=null;if(e.key==='Enter'||e.key==='ArrowDown')target=rows.eq(rowIndex+1).find('.gb-cell').eq(colIndex);else if(e.key==='ArrowUp')target=rows.eq(rowIndex-1).find('.gb-cell').eq(colIndex);else if(e.key==='ArrowRight'&&this.selectionStart===this.value.length)target=cells.eq(colIndex+1);else if(e.key==='ArrowLeft'&&this.selectionStart===0)target=cells.eq(colIndex-1);if(target&&target.length&&!target.prop('disabled')){e.preventDefault();target.focus().select()}});
 $(document).on('paste','.gb-cell',function(e){let text=(e.originalEvent.clipboardData||window.clipboardData).getData('text');if(!text||text.indexOf('\t')<0&&text.indexOf('\n')<0)return;e.preventDefault();let matrix=text.replace(/\r/g,'').trimEnd().split('\n').map(r=>r.split('\t')),tableRows=$('.gb-sheet tbody tr'),startRow=tableRows.index($(this).closest('tr')),startCol=$(this).closest('tr').find('.gb-cell').index(this);matrix.forEach((values,r)=>values.forEach((value,c)=>{let target=tableRows.eq(startRow+r).find('.gb-cell').eq(startCol+c);if(target.length&&!target.prop('disabled'))target.val(value.trim()).trigger('input')}))});
 $('#gb-system').on('change',function(){$('.gb-cell').each(function(){trackCell(this)});updateAllAverages()});
 $(document).on('click','.gb-add-eval',function(){if(Object.keys(gradebookChanges).length){alert_toast('Guarda o descarta las notas modificadas antes de crear una evaluación.','warning');return}let url='quick_manage_evaluation.php?teacher_course_id='+encodeURIComponent($('#gb-assignment').val())+'&bimestre='+encodeURIComponent($('#gb-bimester').val())+'&competencia_id='+encodeURIComponent(this.dataset.comp);uni_modal('Crear evaluación',url,'modal-md')});
 $(document).on('click','.gb-duplicate-eval',function(){if(Object.keys(gradebookChanges).length){alert_toast('Guarda o descarta las notas modificadas antes de duplicar.','warning');return}let url='quick_manage_evaluation.php?teacher_course_id='+encodeURIComponent($('#gb-assignment').val())+'&bimestre='+encodeURIComponent($('#gb-bimester').val())+'&competencia_id='+encodeURIComponent(this.dataset.comp)+'&source_id='+encodeURIComponent(this.dataset.id);uni_modal('Duplicar evaluación',url,'modal-md')});
 $(document).on('click','.gb-edit-eval',function(){if(Object.keys(gradebookChanges).length){alert_toast('Guarda o descarta las notas modificadas antes de editar una evaluación.','warning');return}uni_modal('Editar evaluación','manage_evaluation.php?id='+encodeURIComponent(this.dataset.id),'modal-xl')});
 $(document).on('click','.gb-delete-eval',function(){if(Object.keys(gradebookChanges).length){alert_toast('Guarda o descarta las notas modificadas antes de eliminar una evaluación.','warning');return}let id=this.dataset.id,has=this.dataset.has==='1',reason='';if(has){if(!migrationReady){alert_toast('Ejecuta sql/grades_module_upgrade.sql para anular conservando las notas.','warning');return}reason=prompt('Esta evaluación tiene notas y será anulada. Indica el motivo:','');if(reason===null)return;if(!reason.trim()){alert_toast('El motivo es obligatorio para conservar la trazabilidad.','warning');return}}else if(!confirm('¿Eliminar esta evaluación sin notas?'))return;start_load();$.post('ajax.php?action=delete_evaluation',{id:id,reason:reason,csrf_token:csrf},null,'json').done(r=>{alert_toast(r.message||'Operación completada.',r.status==1?'success':'danger');if(r.status==1){loadGradebook();load()}}).fail(()=>alert_toast('No se pudo procesar la evaluación.','danger')).always(end_load)});
 $(document).on('evaluation:saved',function(){if($('#gr-book-view').is(':visible')&&$('#gb-assignment').val()&&$('#gb-bimester').val())loadGradebook()});
 $('#gb-discard').on('click',function(){clearTimeout(autosaveTimer);autosaveTimer=null;if(!Object.keys(gradebookChanges).length)return;$('.gb-cell.gb-dirty').each(function(){this.value=this.dataset.original;$(this).removeClass('gb-dirty gb-invalid')});gradebookChanges={};updateAllAverages();updateChangeCount()});
 let autosaveTimer=null,gradebookSaving=false,gradebookEditRevision=0;
 $('#gb-autosave').prop('checked',localStorage.getItem('edusync_gradebook_autosave')==='1').on('change',function(){
   localStorage.setItem('edusync_gradebook_autosave',this.checked?'1':'0');
   if(this.checked)scheduleGradebookAutosave();else{clearTimeout(autosaveTimer);autosaveTimer=null}
 });
 function scheduleGradebookAutosave(){
   clearTimeout(autosaveTimer);autosaveTimer=null;
   if(gradebookSaving||!$('#gb-autosave').prop('checked')||!gradebook||gradebook.read_only||!$('#gb-assignment').val()||!$('#gb-bimester').val()||!Object.keys(gradebookChanges).length||$('.gb-cell.gb-invalid').length)return;
   autosaveTimer=setTimeout(function(){autosaveTimer=null;saveGradebook(true)},1400);
 }
 function saveGradebook(automatic){
   if(automatic&&(!$('#gb-autosave').prop('checked')||!gradebook||gradebook.read_only))return;
   let changes=Object.values(gradebookChanges).map(c=>Object.assign({},c));
   if(!changes.length||gradebookSaving||!$('#gb-assignment').val()||!$('#gb-bimester').val())return;
   if($('.gb-cell.gb-invalid').length){if(!automatic)alert_toast('Corrige las notas marcadas antes de guardar.','warning');return}
   let requestRevision=gradebookEditRevision,savedSuccessfully=false;
   gradebookSaving=true;clearTimeout(autosaveTimer);autosaveTimer=null;
   $('#gb-sync-state').html('<i class="fas fa-circle-notch fa-spin mr-1"></i>Guardando...');
   if(!automatic)start_load();
   $.ajax({
     url:'gradebook_api.php?action=save',method:'POST',dataType:'json',
     data:{csrf_token:csrf,teacher_course_id:$('#gb-assignment').val(),bimestre:$('#gb-bimester').val(),grading_system:$('#gb-system').val(),notification_source:automatic?'autosave':'manual',changes:JSON.stringify(changes)}
   }).done(r=>{
     if(r.status==1){
       savedSuccessfully=true;
       changes.forEach(saved=>{
         let key=saved.evaluation_id+':'+saved.student_id,current=gradebookChanges[key],cell=$('.gb-cell[data-evaluation="'+saved.evaluation_id+'"][data-student="'+saved.student_id+'"]');
         if(current&&normalizeGrade(current.grade)===normalizeGrade(saved.grade)){
           cell.attr('data-original',cell.val()).removeClass('gb-dirty gb-invalid');
           delete gradebookChanges[key]
         }
       });
       updateGradebookSummary();applyStudentFilter();updateChangeCount();
       $('#gb-sync-state').html('<i class="fas fa-check text-success mr-1"></i>Guardado');
       $(document).trigger('evaluation:gradesSaved',[r])
     }else{
       alert_toast(r.message||'No se pudieron guardar las notas.','danger');
       $('#gb-sync-state').text('Error al guardar')
     }
   }).fail(x=>{
     alert_toast((x.responseJSON||{}).message||'No se pudieron guardar las notas.','danger');
     $('#gb-sync-state').text('Sin conexión')
   }).always(()=>{
     gradebookSaving=false;
     if(!automatic)end_load();
     // Evita reintentos infinitos durante la inactividad si el servidor falla.
     // Si el docente siguió editando durante un guardado correcto, programa lo nuevo.
     if(savedSuccessfully&&gradebookEditRevision>requestRevision&&Object.keys(gradebookChanges).length)scheduleGradebookAutosave()
   })
 }
 $('#gb-save').on('click',()=>saveGradebook(false));
 window.addEventListener('beforeunload',function(e){if(Object.keys(gradebookChanges).length){e.preventDefault();e.returnValue=''}});loadAssignments();
})(jQuery);
</script>
