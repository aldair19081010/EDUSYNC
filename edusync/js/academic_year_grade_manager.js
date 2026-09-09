(function(){
'use strict';
var current=document.currentScript;
var version='';
try{version=new URL(current&&current.src?current.src:window.location.href).searchParams.get('v')||String(Date.now());}catch(e){version=String(Date.now());}
function load(src,done){var s=document.createElement('script');s.src=src+'?v='+encodeURIComponent(version);s.onload=function(){if(done)done();};s.onerror=function(){if(window.console)console.error('No se pudo cargar '+src);};document.head.appendChild(s);}
load('js/academic_year_grade_manager_core.js',function(){load('js/institutional_bimester_close.js');});
})();
