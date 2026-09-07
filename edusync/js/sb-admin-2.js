/* Fuente sincronizada con sb-admin-2.min.js para las mejoras de reportes. */

(function($){
  "use strict";

  $("#sidebarToggle, #sidebarToggleTop").on("click",function(e){
    e.preventDefault();
    $("body").toggleClass("sidebar-toggled");
    $(".sidebar").toggleClass("toggled");
    if($(".sidebar").hasClass("toggled")) $(".sidebar .collapse").collapse("hide");
  });
  $(window).resize(function(){
    if($(window).width()<768) $(".sidebar .collapse").collapse("hide");
    if($(window).width()<480 && !$(".sidebar").hasClass("toggled")){
      $("body").addClass("sidebar-toggled");
      $(".sidebar").addClass("toggled");
      $(".sidebar .collapse").collapse("hide");
    }
  });
  $("body.fixed-nav .sidebar").on("mousewheel DOMMouseScroll wheel",function(e){
    if($(window).width()>768){
      var ev=e.originalEvent,delta=ev.wheelDelta||-ev.detail;
      this.scrollTop+=30*(delta<0?1:-1);e.preventDefault();
    }
  });
  $(document).on("scroll",function(){
    $(this).scrollTop()>100?$(".scroll-to-top").fadeIn():$(".scroll-to-top").fadeOut();
  });
  $(document).on("click","a.scroll-to-top",function(e){
    var href=$(this).attr("href");
    if(href && href!=="#" && href.length>1){
      try{var $target=$(href);if($target.length)$("html, body").stop().animate({scrollTop:$target.offset().top},1000,"easeInOutExpo");}catch(err){}
    }
    e.preventDefault();
  });

  function parseGrade(value){
    if(value===null||value===undefined)return null;
    var text=String(value).trim().replace(",",".");
    if(!text||text==="—"||text==="-")return null;
    var num=parseFloat(text);return isNaN(num)?null:num;
  }
  function roundGrade(value){return value===null||value===undefined||isNaN(value)?null:Math.round(Number(value));}
  function statusFor(value){
    var score=roundGrade(value);
    if(score===null)return {letter:"—",label:"Sin datos",className:"ir-status-none",color:"#858796"};
    if(score>=18)return {letter:"AD",label:"Logro destacado",className:"ir-status-ad",color:"#13855c"};
    if(score>=14)return {letter:"A",label:"Logro esperado",className:"ir-status-a",color:"#1cc88a"};
    if(score>=11)return {letter:"B",label:"En proceso",className:"ir-status-b",color:"#f6c23e"};
    return {letter:"C",label:"En inicio",className:"ir-status-c",color:"#e74a3b"};
  }
  function badge(status,extra){return $("<span>",{class:"ir-status-badge "+status.className+(extra?" "+extra:""),text:status.letter+" · "+status.label});}
  function ensureIndividualStyles(){
    if(document.getElementById("grades-individual-status-styles"))return;
    var css=[
      ".individual-report .ir-result{min-width:190px;text-align:center;position:relative;overflow:hidden;transition:.2s ease}",
      ".individual-report .ir-result.ir-result-status{border-width:2px}",
      ".individual-report .ir-result .ir-final-status{display:inline-flex;margin-top:7px}",
      ".individual-report .ir-status-badge{display:inline-flex;align-items:center;justify-content:center;padding:5px 10px;border-radius:999px;font-size:.76rem;font-weight:700;line-height:1.1;border:1px solid transparent;white-space:nowrap}",
      ".individual-report .ir-status-ad{background:#d8f3e8;color:#0f6848;border-color:#9edfc8}",
      ".individual-report .ir-status-a{background:#e0f7ee;color:#107354;border-color:#abe8d2}",
      ".individual-report .ir-status-b{background:#fff5d6;color:#856404;border-color:#f5d77c}",
      ".individual-report .ir-status-c{background:#fde3e1;color:#a3281d;border-color:#f3aaa4}",
      ".individual-report .ir-status-none{background:#f1f3f5;color:#6c757d;border-color:#d8dde2}",
      ".individual-report .ir-summary-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px;margin:0 0 18px}",
      ".individual-report .ir-summary-item{border:1px solid #e3e6f0;border-radius:8px;padding:10px 12px;background:#fff}",
      ".individual-report .ir-summary-item small{display:block;color:#7b8499;font-size:.72rem;margin-bottom:3px}",
      ".individual-report .ir-summary-item strong{display:block;font-size:1.25rem;color:#344767}",
      ".individual-report .ir-summary-dot{display:inline-block;width:8px;height:8px;border-radius:50%;margin-right:6px}",
      ".individual-report .ir-comp-status{margin-left:auto}",
      ".individual-report .ir-card-header .badge+.ir-comp-status{margin-left:0}",
      ".individual-report .ir-trend-card{border:1px solid #e3e6f0;border-radius:9px;background:#fff;margin:0 0 18px;overflow:hidden}",
      ".individual-report .ir-trend-head{display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;padding:12px 14px;background:#f8f9fc;border-bottom:1px solid #e3e6f0}",
      ".individual-report .ir-trend-head h4{margin:0;font-size:.95rem;font-weight:700}",
      ".individual-report .ir-trend-head small{color:#7b8499}",
      ".individual-report .ir-trend-body{padding:14px}",
      ".individual-report .ir-trend-chart{width:100%;overflow-x:auto}",
      ".individual-report .ir-trend-chart svg{display:block;width:100%;min-width:640px;height:auto}",
      ".individual-report .ir-trend-metrics{display:grid;grid-template-columns:repeat(auto-fit,minmax(145px,1fr));gap:10px;margin-top:12px}",
      ".individual-report .ir-trend-metric{border:1px solid #e3e6f0;border-radius:8px;padding:9px 11px;background:#fff}",
      ".individual-report .ir-trend-metric small{display:block;color:#7b8499;font-size:.72rem;margin-bottom:2px}",
      ".individual-report .ir-trend-metric strong{display:block;color:#344767;font-size:1.08rem}",
      ".individual-report .ir-trend-note{margin-top:12px;padding:10px 12px;border-radius:7px;background:#f8f9fc;border-left:4px solid #4e73df;color:#596579;font-size:.84rem}",
      ".individual-report .ir-trend-loading{padding:24px;text-align:center;color:#7b8499}",
      ".individual-report .ir-var-up{color:#13855c!important}.individual-report .ir-var-down{color:#e74a3b!important}.individual-report .ir-var-flat{color:#858796!important}",
      "@media(max-width:767px){.individual-report .ir-summary-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.individual-report .ir-result{width:100%}}",
      "@media print{.individual-report .ir-status-badge,.individual-report .ir-summary-item,.individual-report .ir-result,.individual-report .ir-trend-card,.individual-report .ir-trend-metric{-webkit-print-color-adjust:exact!important;print-color-adjust:exact!important}.individual-report .ir-summary-grid{grid-template-columns:repeat(4,minmax(0,1fr))!important}.individual-report .ir-trend-chart{overflow:visible!important}.individual-report .ir-trend-chart svg{min-width:0!important}}"
    ].join("");
    $("<style>",{id:"grades-individual-status-styles",text:css}).appendTo("head");
  }
  function roundCalculationTables($report){
    $report.find(".ir-card table").each(function(){
      var $table=$(this),headers=[];
      $table.find("thead th").each(function(i){headers[i]=$.trim($(this).text()).toLowerCase();});
      $table.find("tbody tr").each(function(){
        $(this).find("td,th").each(function(i){if(headers[i]!=="promedio")return;var value=parseGrade($(this).text());if(value!==null)$(this).text(roundGrade(value));});
      });
      var $final=$table.find("tfoot tr").last().find("td,th").last();
      if($final.length){var f=parseGrade($final.text());if(f!==null)$final.text(roundGrade(f));}
    });
  }
  function buildSummary($report,states){
    if(!states.length||$report.find(".ir-summary-grid").length)return;
    var counts={good:0,process:0,start:0};
    states.forEach(function(s){if(s.letter==="AD"||s.letter==="A")counts.good++;else if(s.letter==="B")counts.process++;else if(s.letter==="C")counts.start++;});
    var items=[
      {label:"Competencias evaluadas",value:states.length,color:"#4e73df"},
      {label:"Logro esperado / destacado",value:counts.good,color:"#1cc88a"},
      {label:"En proceso",value:counts.process,color:"#f6c23e"},
      {label:"En inicio",value:counts.start,color:"#e74a3b"}
    ],$grid=$("<div>",{class:"ir-summary-grid","aria-label":"Resumen del desempeño"});
    items.forEach(function(item){var $box=$("<div>",{class:"ir-summary-item"});$box.append($("<small>").append($("<span>",{class:"ir-summary-dot"}).css("backgroundColor",item.color)).append(document.createTextNode(item.label)));$box.append($("<strong>",{text:item.value}));$grid.append($box);});
    $report.find(".ir-heading").first().after($grid);
  }
  function trendRequest(){return {report_view:"comparison",data_mode:"trend",academic_year_id:$("#academic_year_id").val()||"",course_id:$("#course_id").val()||"",student_id:$("#student_id").val()||"",bimestre:$("#bimestre").val()||"",level:$("#level").val()||"",grado:$("#grado").val()||"",seccion:$("#seccion").val()||""};}
  function scoreArray(obj){var arr=[];for(var i=1;i<=4;i++){var v=obj?obj[i]:null;arr.push(v===null||v===undefined?null:roundGrade(v));}return arr;}
  function annualAverage(scores){var valid=scores.filter(function(v){return v!==null;});return valid.length?roundGrade(valid.reduce(function(a,b){return a+b;},0)/valid.length):null;}
  function buildDualSvg(studentScores,classScores,selected){
    var width=780,height=300,left=58,right=28,top=42,bottom=48,plotW=width-left-right,plotH=height-top-bottom,xs=[0,1,2,3].map(function(i){return left+(plotW/3)*i;});
    function y(v){return top+plotH-(Math.max(0,Math.min(20,v))/20)*plotH;}
    var out=[];
    out.push('<svg viewBox="0 0 '+width+' '+height+'" role="img" aria-label="Comparación bimestral del alumno con el promedio del aula">','<rect x="0" y="0" width="'+width+'" height="'+height+'" rx="8" fill="#fff"/>','<line x1="'+left+'" y1="18" x2="'+(left+24)+'" y2="18" stroke="#4e73df" stroke-width="4"/><text x="'+(left+32)+'" y="22" font-size="11" fill="#596579">Alumno</text>','<line x1="'+(left+105)+'" y1="18" x2="'+(left+129)+'" y2="18" stroke="#858796" stroke-width="3" stroke-dasharray="7 5"/><text x="'+(left+137)+'" y="22" font-size="11" fill="#596579">Promedio del aula</text>');
    [0,5,10,15,20].forEach(function(mark){var yy=y(mark);out.push('<line x1="'+left+'" y1="'+yy+'" x2="'+(width-right)+'" y2="'+yy+'" stroke="#e9edf4" stroke-width="1"/><text x="'+(left-12)+'" y="'+(yy+4)+'" text-anchor="end" font-size="11" fill="#858796">'+mark+'</text>');});
    function drawLine(scores,color,dashed){for(var i=0;i<3;i++){if(scores[i]===null||scores[i+1]===null)continue;out.push('<line x1="'+xs[i]+'" y1="'+y(scores[i])+'" x2="'+xs[i+1]+'" y2="'+y(scores[i+1])+'" stroke="'+color+'" stroke-width="'+(dashed?3:4)+'" '+(dashed?'stroke-dasharray="7 5"':'')+' stroke-linecap="round"/>');}}
    drawLine(classScores,"#858796",true);drawLine(studentScores,"#4e73df",false);
    for(var i=0;i<4;i++){
      out.push('<text x="'+xs[i]+'" y="'+(height-18)+'" text-anchor="middle" font-size="12" font-weight="600" fill="#596579">'+(i+1)+'° Bim.</text>');
      if(classScores[i]!==null)out.push('<circle cx="'+xs[i]+'" cy="'+y(classScores[i])+'" r="6" fill="#858796" stroke="#fff" stroke-width="2"/><text x="'+(xs[i]+13)+'" y="'+(y(classScores[i])+4)+'" font-size="10" fill="#858796">'+classScores[i]+'</text>');
      if(studentScores[i]!==null){var st=statusFor(studentScores[i]),yy=y(studentScores[i]);if(selected===i+1)out.push('<circle cx="'+xs[i]+'" cy="'+yy+'" r="13" fill="none" stroke="'+st.color+'" stroke-width="2" opacity=".35"/>');out.push('<circle cx="'+xs[i]+'" cy="'+yy+'" r="8" fill="'+st.color+'" stroke="#fff" stroke-width="3"/><text x="'+xs[i]+'" y="'+(yy-15)+'" text-anchor="middle" font-size="13" font-weight="700" fill="#344767">'+studentScores[i]+'</text>');}
    }
    out.push("</svg>");return out.join("");
  }
  function trendText(studentScores,classScores){
    var valid=[];studentScores.forEach(function(v,i){if(v!==null)valid.push({bi:i+1,v:v});});var parts=[];
    if(valid.length>=2){var first=valid[0],last=valid[valid.length-1],delta=last.v-first.v;if(delta>=2)parts.push("Tendencia ascendente: mejora "+delta+" punto(s) entre el "+first.bi+"° y el "+last.bi+"° bimestre.");else if(delta<=-2)parts.push("Tendencia descendente: disminuye "+Math.abs(delta)+" punto(s) entre el "+first.bi+"° y el "+last.bi+"° bimestre.");else parts.push("Tendencia estable: la variación global es de "+(delta>0?"+":"")+delta+" punto(s).");}else parts.push("Aún no hay suficientes bimestres con notas para determinar una tendencia.");
    var selected=parseInt($("#bimestre").val(),10)||0;if(selected){var sv=studentScores[selected-1],cv=classScores[selected-1];if(sv!==null&&cv!==null){var diff=sv-cv;if(diff>0)parts.push("En el bimestre seleccionado está "+diff+" punto(s) por encima del promedio del aula.");else if(diff<0)parts.push("En el bimestre seleccionado está "+Math.abs(diff)+" punto(s) por debajo del promedio del aula.");else parts.push("En el bimestre seleccionado está igual al promedio del aula.");}}
    return parts.join(" ");
  }
  function renderTrend($report,data){
    var studentScores=scoreArray(data.student_scores),classScores=scoreArray(data.class_scores),selected=parseInt($("#bimestre").val(),10)||0,studentAnnual=annualAverage(studentScores),classAnnual=annualAverage(classScores),best=null,bestBi=null;
    studentScores.forEach(function(v,i){if(v!==null&&(best===null||v>best)){best=v;bestBi=i+1;}});
    var current=selected?studentScores[selected-1]:null,previous=selected>1?studentScores[selected-2]:null,variation=(current!==null&&previous!==null)?current-previous:null,classCurrent=selected?classScores[selected-1]:null,diff=(current!==null&&classCurrent!==null)?current-classCurrent:null,$card=$("<div>",{class:"ir-trend-card"}),$head=$("<div>",{class:"ir-trend-head"}),$body=$("<div>",{class:"ir-trend-body"});
    $head.append('<h4><i class="fas fa-chart-line text-primary mr-2"></i>Tendencia bimestral</h4><small>Alumno vs promedio del aula · notas enteras redondeadas</small>');
    $body.append($("<div>",{class:"ir-trend-chart",html:buildDualSvg(studentScores,classScores,selected)}));
    var metrics=[
      {label:"Promedio anual alumno",value:studentAnnual===null?"—":studentAnnual},
      {label:"Promedio anual aula",value:classAnnual===null?"—":classAnnual},
      {label:"Mejor bimestre",value:bestBi===null?"—":bestBi+"° ("+best+")"},
      {label:"Variación vs anterior",value:variation===null?"—":(variation>0?"↑ +"+variation:variation<0?"↓ "+variation:"= 0"),cls:variation>0?"ir-var-up":variation<0?"ir-var-down":"ir-var-flat"},
      {label:"Diferencia vs aula",value:diff===null?"—":(diff>0?"+"+diff:diff),cls:diff>0?"ir-var-up":diff<0?"ir-var-down":"ir-var-flat"}
    ],$metrics=$("<div>",{class:"ir-trend-metrics"});
    metrics.forEach(function(m){var $box=$("<div>",{class:"ir-trend-metric"});$box.append($("<small>",{text:m.label}));$box.append($("<strong>",{text:m.value,class:m.cls||""}));$metrics.append($box);});
    $body.append($metrics).append($("<div>",{class:"ir-trend-note",text:trendText(studentScores,classScores)}));$card.append($head).append($body);
    var $summary=$report.find(".ir-summary-grid").first();if($summary.length)$summary.after($card);else $report.find(".ir-heading").first().after($card);
  }
  function loadTrend($report){
    if($report.attr("data-trend-loading")==="1"||$report.attr("data-trend-loaded")==="1")return;
    if(!$("#student_id").val()||!$("#course_id").val()||!$("#academic_year_id").val())return;
    $report.attr("data-trend-loading","1");
    var $placeholder=$("<div>",{class:"ir-trend-card ir-trend-loading",text:"Cargando comparación bimestral..."}),$summary=$report.find(".ir-summary-grid").first();if($summary.length)$summary.after($placeholder);else $report.find(".ir-heading").first().after($placeholder);
    $.ajax({url:"grades_report_views.php",method:"POST",data:trendRequest(),dataType:"json"}).done(function(resp){$placeholder.remove();if(resp&&resp.status===1)renderTrend($report,resp);else $report.find(".ir-heading").first().after('<div class="alert alert-light border small">No se pudo cargar la comparación con el aula.</div>');}).fail(function(){$placeholder.remove();}).always(function(){$report.removeAttr("data-trend-loading").attr("data-trend-loaded","1");});
  }
  function enhanceIndividual(root){
    var $report=$(root);if(!$report.length||$report.attr("data-status-enhanced")==="1")return;
    ensureIndividualStyles();var states=[];
    $report.find(".ir-card").each(function(){var $card=$(this),$label=$card.find("td").filter(function(){return $.trim($(this).text()).toLowerCase()==="promedio de la competencia";}).first();if(!$label.length)return;var $cell=$label.next("td"),score=roundGrade(parseGrade($cell.text()));if(score===null)return;var $strong=$cell.find("strong").first();if($strong.length)$strong.text(score);else $cell.text(score);var st=statusFor(score);states.push(st);var $header=$card.find(".ir-card-header").first();if($header.length&&!$header.find(".ir-comp-status").length)$header.append(badge(st,"ir-comp-status"));$cell.find(".ir-status-badge").remove();$cell.append($("<div>",{class:"mt-1"}).append(badge(st)));});
    var $result=$report.find(".ir-result").first(),finalScore=roundGrade(parseGrade($result.find("strong").first().text()));if(!states.length&&finalScore===0)finalScore=null;var finalStatus=statusFor(finalScore);if($result.length){$result.find("strong").first().text(finalScore===null?"—":finalScore);$result.addClass("ir-result-status").css({borderColor:finalStatus.color,boxShadow:"inset 4px 0 0 "+finalStatus.color});$result.find(".ir-final-status").remove();$result.append(badge(finalStatus,"ir-final-status"));}
    roundCalculationTables($report);buildSummary($report,states);$report.attr("data-status-enhanced","1");loadTrend($report);
  }
  function scanReports(){$(".individual-report").each(function(){enhanceIndividual(this);});}
  function syncExportMenu(){
    $("#export-view-excel,#export-csv-report").remove();
    var isDetail=($("#report_view").val()||"detail")==="detail";
    $("#export-excel-report").toggle(isDetail);
  }

  $(function(){scanReports();syncExportMenu();});
  $(document).off("click.grExportView",".report-view").on("click.grExportView",".report-view",function(){setTimeout(syncExportMenu,0);});
  if(window.MutationObserver){new MutationObserver(function(mutations){var scan=false;mutations.forEach(function(m){if(m.addedNodes&&m.addedNodes.length)scan=true;});if(scan){scanReports();syncExportMenu();}}).observe(document.documentElement,{childList:true,subtree:true});}
})(jQuery);
