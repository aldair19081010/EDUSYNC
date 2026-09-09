(function(){
'use strict';
var current=document.currentScript;
var version='';
try{version=new URL(current&&current.src?current.src:window.location.href).searchParams.get('v')||String(Date.now());}catch(e){version=String(Date.now());}
function load(src,done){var s=document.createElement('script');s.src=src+'?v='+encodeURIComponent(version);s.onload=function(){if(done)done();};s.onerror=function(){if(window.console)console.error('No se pudo cargar '+src);};document.head.appendChild(s);}
function exposeToken(token){
    if(!token)return;
    window.EDUSYNC_CSRF=token;
    var input=document.getElementById('edusync-global-csrf');
    if(!input){input=document.createElement('input');input.type='hidden';input.id='edusync-global-csrf';input.name='csrf_token';document.body.appendChild(input);}
    input.value=token;
}
function boot(){load('js/academic_year_grade_manager_core.js',function(){load('js/institutional_bimester_close.js');});}
function loadCsrf(){
    if(typeof fetch!=='function'){boot();return;}
    fetch('csrf_context_api.php',{credentials:'same-origin',cache:'no-store',headers:{'X-Requested-With':'XMLHttpRequest'}})
      .then(function(r){return r.json();})
      .then(function(r){if(r&&Number(r.status)===1&&r.csrf_token)exposeToken(r.csrf_token);boot();})
      .catch(function(){boot();});
}
loadCsrf();
})();
