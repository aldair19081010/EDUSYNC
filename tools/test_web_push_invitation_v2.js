/** Prueba aislada de la invitación Web Push sin ejecutar ni alterar Firebase. */
'use strict';
const assert=require('node:assert/strict');
const fs=require('node:fs');
const path=require('node:path');
const vm=require('node:vm');
const file=path.join(__dirname,'../edusync/js/web_push_invitation.js');
const source=fs.readFileSync(file,'utf8');
new vm.Script(source,{filename:'web_push_invitation.js'});

function scenario({permission='default',configured=true,migrated=true,
                   enabled=false,disabled=false,nowLater=false,
                   sharedLocal,sharedSession,identity='student:1:42'}={}) {
    const local=sharedLocal||new Map();
    const session=sharedSession||new Map();
    const key='edusync:webpush:enabled:'+identity;
    if(enabled)local.set(key,'1');
    if(nowLater)local.set('edusync:webpush:welcome-later:'+identity,String(Date.now()+86400000));
    const found=[];
    let active=null, clicks=0;
    class Element {
        constructor(html='') {
            this.html=html;
            this.classes=(html.match(/class="([^"]*)"/)||[])[1]?.split(/\s+/)||[];
            this.handlers={};this.children=[];this.removed=false;
            this.length=1;this.disabled=false;
            this[0]={focus:()=>{active=this[0];}};
            found.push(this);
        }
        first(){return this;}
        prop(key,value){if(value===undefined)return this[key];this[key]=value;return this;}
        append(...children){this.children.push(...children);return this;}
        addClass(value){this.classes.push(value);return this;}
        removeClass(value){this.classes=this.classes.filter(c=>c!==value);return this;}
        off(){this.handlers={};return this;}
        remove(){this.removed=true;return this;}
        text(value){if(value===undefined)return this.value;this.value=value;return this;}
        on(name,handler){this.handlers[name]=handler;return this;}
        trigger(name){
            if(name==='focus'){this[0].focus();return this;}
            if(this.handlers[name])return this.handlers[name]({
                stopPropagation(){},preventDefault(){},
                key:name,shiftKey:false
            });
            return this;
        }
    }
    const toggle=new Element('<button class="edwp-toggle"></button>');
    toggle.disabled=disabled;
    toggle[0].disabled=disabled;
    toggle[0].click=()=>{clicks++;local.set(key,'1');};
    const body=new Element('<body>');
    const modal={length:0};
    const $=value=>{
        if(typeof value==='function'){value();return body;}
        if(value==='.edusync-notification-menu .edwp-toggle')return toggle;
        if(value==='.modal.show')return modal;
        if(value==='body')return body;
        if(typeof value==='string'&&value[0]==='<')return new Element(value);
        throw new Error('Unexpected selector: '+String(value));
    };
    $.getJSON=()=>({done(fn){fn({
        status:'ok',configured,push_ready:migrated,identity
    });return this;}});
    const storage=map=>({
        getItem:key=>map.get(key)||null,
        setItem:(key,value)=>map.set(key,String(value))
    });
    const window={isSecureContext:true,jQuery:$,PushManager:function(){}};
    const Notification={permission};
    window.Notification=Notification;
    const navigator={serviceWorker:{}};
    const document={activeElement:null,contains:()=>false};
    document.activeElement=body[0];
    const context={window,Notification,navigator,document,
        localStorage:storage(local),sessionStorage:storage(session),Date};
    vm.runInNewContext(source,context,{filename:'web_push_invitation.js'});
    const get=name=>found.find(e=>e.classes.includes(name));
    return{get,clicks:()=>clicks,local,session,key,toggle};
}

const first=scenario();
assert(first.get('edwp-welcome-panel'),'First visit shows invitation');
assert.equal(first.clicks(),0,'Never attempt push on page load');
first.get('edwp-welcome-activate').trigger('click');
assert.equal(first.clicks(),1,'Delegates to working bell click exactly once');
assert(first.get('edwp-welcome-layer').removed,'Dialog closes after delegation');

const postponed=scenario();
postponed.get('edwp-welcome-later').trigger('click');
assert.equal(postponed.clicks(),0);
assert(Number(postponed.local.get('edusync:webpush:welcome-later:student:1:42'))>Date.now()+29*86400000);
assert.equal(scenario({sharedLocal:postponed.local}).get('edwp-welcome-panel'),undefined,
    'Do not insist when postponed');

const recurring=scenario();
assert.equal(scenario({sharedSession:recurring.session}).get('edwp-welcome-panel'),undefined,
    'Do not show again during same tab/session');

assert.equal(scenario({enabled:true}).get('edwp-welcome-panel'),undefined,
    'Already enabled: no dialog');
assert.equal(scenario({permission:'denied'}).get('edwp-welcome-panel'),undefined,
    'Blocked permission: no dialog');
assert.equal(scenario({configured:false}).get('edwp-welcome-panel'),undefined,
    'Missing Firebase config: no dialog');
assert.equal(scenario({migrated:false}).get('edwp-welcome-panel'),undefined,
    'Missing DB migration: no dialog');
assert.equal(scenario({disabled:true}).get('edwp-welcome-panel'),undefined,
    'Inactive bell: no dialog');

const granted=scenario({permission:'granted'});
assert(granted.get('edwp-welcome-panel'),'Granted but unregistered: show invitation');
assert.equal(granted.clicks(),0,'Existing browser permission cannot trigger automatic subscription');
granted.get('edwp-welcome-activate').trigger('click');
assert.equal(granted.clicks(),1);

const oneUser=scenario();
const another=scenario({sharedLocal:oneUser.local,sharedSession:oneUser.session,identity:'staff:1:7'});
assert(another.get('edwp-welcome-panel'),'Invitation state scoped per school/account');

const base=fs.readFileSync(path.join(__dirname,'../edusync/js/web_push_fcm.js'),'utf8');
assert(base.includes('toggle.on(\'click\''),'Existing bell handler remains present');
assert(!source.includes('getToken(') && !source.includes('requestPermission(') &&
       !source.includes('serviceWorker.register(') && !source.includes('pushManager.subscribe('),
       'Invitation must not create an alternative Firebase/Push flow');

process.stdout.write('OK: ventana, aceptación por campana, Ahora no, sesión, cuentas y permisos.\n');
