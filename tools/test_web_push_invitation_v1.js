/**
 * Pruebas de interfaz de invitación Web Push, sin navegador ni Firebase real.
 * Ejecutar: node tools/test_web_push_invitation_v1.js
 */
'use strict';
const assert=require('node:assert/strict');
const fs=require('node:fs');
const path=require('node:path');
const vm=require('node:vm');

const source=fs.readFileSync(path.join(__dirname,'../edusync/js/web_push_fcm.js'),'utf8');
const diagnosticSource=fs.readFileSync(path.join(__dirname,'../edusync/js/web_push_diagnostic.js'),'utf8');
const sampleVapid=Buffer.from(Uint8Array.from([4,...new Array(64).fill(17)])).toString('base64url');
new vm.Script(diagnosticSource,{filename:'web_push_diagnostic.js'});
new vm.Script(source,{filename:'web_push_fcm.js'});

function scenario(permission,initial={},options={}){
    const items=[], calls=[],saved=new Map(Object.entries(initial));
    const session=new Map();
    const output={permissionRequests:0,registrations:0};
    let active;
    class Element{
        constructor(html=''){
            this.html=html;
            this.classes=(html.match(/class="([^"]*)"/)||[])[1]?.split(/\s+/)||[];
            this.children=[];this.handlers={};this.length=1;this.removed=false;
            this[0]={focus:()=>{active=this[0];}};
            items.push(this);
        }
        append(...children){this.children.push(...children);return this;}
        text(value){if(value===undefined)return this.value;this.value=value;return this;}
        on(event,listener){this.handlers[event]=listener;return this;}
        off(){this.handlers={};return this;}
        prop(property,value){if(property==='disabled')this.disabled=value;return this;}
        addClass(name){this.classes.push(name);return this;}
        removeClass(name){this.classes=this.classes.filter(x=>x!==name);return this;}
        toggleClass(){return this;}
        remove(){this.removed=true;return this;}
        trigger(event){
            if(event==='focus'){active=this[0];return this;}
            const handler=this.handlers[event];
            return handler?handler({stopPropagation(){},preventDefault(){}}):this;
        }
        find(selector){
            return items.find(x=>x.classes.includes(selector.replace('.',''))) || new Element();
        }
    }
    const body=new Element('<body>');
    const menu=new Element('<div class="edusync-notification-menu"></div>');
    const find=name=>items.find(x=>x.classes.includes(name));
    function $(value){
        if(typeof value==='function'){value();return body;}
        if(value==='.edusync-notification-menu')return menu;
        if(value==='.modal.show')return {length:0};
        if(value==='body')return body;
        if(typeof value==='string'&&value.startsWith('<'))return new Element(value);
        return find(String(value).replace('.',''))||new Element();
    }
    $.extend=Object.assign;
    $.getJSON=()=>({
        done(callback){
            callback({status:'ok',configured:options.configured!==false,
                push_ready:options.migrated!==false,csrf_token:'csrf',
                identity:'student:1:42',
                firebase:{apiKey:'public-web-config',
                    messagingSenderId:'123456789',appId:'1:123456789:web:abc'},
                vapid_key:options.invalidVapid?'invalid':' '+sampleVapid.trim()});
            return this;
        },
        fail(){return this;}
    });
    $.ajax=(args)=>({
        done(callback){
            calls.push(args.data.action);
            if(args.data.action==='register')output.registrations++;
            callback({status:'ok',message:'Registrado'});
            return this;
        },
        fail(){return this;}
    });

    const Notification={
        permission,
        requestPermission(){
            output.permissionRequests++;
            this.permission='granted';
            return Promise.resolve('granted');
        }
    };
    const localStorage={
        getItem:key=>saved.get(key)||null,
        setItem:(key,value)=>saved.set(key,String(value)),
        removeItem:key=>saved.delete(key)
    };
    const sessionStorage={
        getItem:key=>session.get(key)||null,
        setItem:(key,value)=>session.set(key,String(value))
    };
    const messaging=()=>({
        getToken:async()=>{
            if(options.pushError){
                const error=new Error('Registration failed - push service error');
                error.name='AbortError';
                throw error;
            }
            return 'browser-fcm-token-with-length-over-30';
        },
        deleteToken:async()=>{}
    });
    messaging.isSupported=async()=>true;
    const firebase={
        messaging,
        app(){throw new Error('App not initialized');},
        initializeApp(){return{};}
    };
    const window={
        jQuery:$,isSecureContext:true,Notification,
        PushManager:function(){},
        crypto:{getRandomValues(array){array.fill(42);}},
        atob(input){return Buffer.from(input,'base64').toString('binary');}
    };
    const document={
        activeElement:null,
        contains(){return false;},
        querySelector(){return null;},
        createElement(){return {dataset:{},addEventListener(){}};},
        head:{appendChild(element){
            window.firebase=firebase;
            element.onload();
        }}
    };
    document.activeElement=body[0];
    const activeRegistration={
        active:{},scope:'http://localhost/edusync/',
        pushManager:{
            getSubscription:async()=>options.hasSubscription?{endpoint:'https://example.invalid/hidden'}:null,
            subscribe:async()=> {
                output.nativeSubscribeCalls=(output.nativeSubscribeCalls||0)+1;
                if(options.nativePushError){
                    const error=new Error('Registration failed - push service error');
                    error.name='AbortError';
                    throw error;
                }
                return {endpoint:'https://example.invalid/token-value-should-never-appear'};
            }
        }
    };
    const navigator={
        serviceWorker:{
            register:async()=>activeRegistration,
            getRegistration:async()=>activeRegistration,
            ready:Promise.resolve(activeRegistration)
        },
        userAgent:'Chrome test'
    };
    window.navigator=navigator;
    const context={window,document,navigator,Notification,localStorage,
        sessionStorage,Uint8Array,Promise,firebase,console,setTimeout,clearTimeout};
    vm.runInNewContext(diagnosticSource,context,{filename:'web_push_diagnostic.js'});
    vm.runInNewContext(source,context,{filename:'web_push_fcm.js'});
    return {output,find,saved,Notification,calls,window};
}

// Incluye un cambio de turno de Node: la carga simulada de Firebase,
 // el registro del service worker y los límites de tiempo encadenan
 // varias promesas. Contar 20 microtareas no garantiza su finalización.
const flush=async()=>{
    for(let i=0;i<40;i++)await Promise.resolve();
    await new Promise(resolve=>setTimeout(resolve,0));
};
(async()=>{
    const newUser=scenario('default');
    assert(newUser.find('edwp-invite-dialog'),'First session must show invitation');
    assert.equal(newUser.output.permissionRequests,0,'No permission prompt on page load');
    assert.equal(newUser.output.registrations,0,'Do not subscribe without consent');
    await newUser.find('edwp-invite-accept').trigger('click');
    await flush();
    assert.equal(newUser.output.permissionRequests,1,'Permission requested on click');
    assert.equal(newUser.output.registrations,1,'Firebase token registered');
    assert.equal(newUser.saved.get('edusync:webpush:enabled:student:1:42'),'1');
    assert(newUser.find('edwp-invite-layer').removed,'Dialog closes on success');

    const later=scenario('default');
    later.find('edwp-invite-later').trigger('click');
    const key='edusync:webpush:invite-dismissed:student:1:42';
    assert(Number(later.saved.get(key))>Date.now()+29*24*60*60*1000);
    const revisiting=scenario('default',Object.fromEntries(later.saved));
    assert.equal(revisiting.find('edwp-invite-dialog'),undefined,
        'Do not insist at next login while postponed');

    // Posponer no bloquea la activación manual de la campana.
    await revisiting.find('edwp-toggle').trigger('click');
    await flush();
    assert.equal(revisiting.output.permissionRequests,1);
    assert.equal(revisiting.output.registrations,1);

    const granted=scenario('granted');
    await flush();
    assert.equal(granted.find('edwp-invite-dialog'),undefined,
        'Previously granted permission leaves activation in the bell without popup');
    assert.equal(granted.output.permissionRequests,0);
    assert.equal(granted.output.registrations,0,
        'Do not subscribe automatically just because browser permission is granted');

    const known=scenario('granted',
        {'edusync:webpush:enabled:student:1:42':'1'}, {hasSubscription:true});
    await flush();
    assert.equal(known.output.registrations,1,'Renew only existing opted-in subscription');

    const stale=scenario('granted',
        {'edusync:webpush:enabled:student:1:42':'1'}, {hasSubscription:false});
    await flush();
    assert.equal(stale.output.registrations,0,'Never subscribe silently if subscription is missing');
    assert.equal(stale.find('edwp-invite-dialog'),undefined,
        'Do not interrupt login with popup after browser already granted permission');
    assert(stale.find('edwp-info').value.includes('campana'),
        'Explain that a missing subscription must be renewed manually');

    const disabled=scenario('granted',{'edusync:webpush:opted-out:student:1:42':'1'});
    await flush();
    assert.equal(disabled.output.registrations,0,'Honor manual deactivation');
    await disabled.find('edwp-toggle').trigger('click');
    await flush();
    assert.equal(disabled.output.registrations,1,'Allow explicit reactivation from bell');
    assert.equal(disabled.saved.get('edusync:webpush:opted-out:student:1:42'),undefined);

    const broken=scenario('default',{}, {pushError:true});
    await broken.find('edwp-invite-accept').trigger('click');
    await flush();
    assert.equal(broken.output.permissionRequests,1);
    assert.equal(broken.output.registrations,0,'Do not register token after failed push service');
    assert(broken.find('edwp-invite-diagnose').classes.includes('edwp-invite-diagnose'));
    assert(broken.find('edwp-invite-feedback').value.includes('servicio Push'),
        'Failure translated to Spanish with helpful guidance');
    assert(Number(broken.saved.get('edusync:webpush:failed-until:student:1:42'))>Date.now(),
        'Auto retry is postponed');
    await broken.find('edwp-invite-diagnose').trigger('click');
    await flush();
    const diagnosis=broken.find('edwp-invite-report').value||'';
    assert(diagnosis.includes('Suscripción Push'),'Push subscription status must be reported');
    assert(diagnosis.includes('AbortError'),'Browser error must appear in report');
    assert(!diagnosis.includes('browser-fcm-token'),'Never include user token in report');
    const afterFailure=scenario('granted',Object.fromEntries(broken.saved));
    await flush();
    assert.equal(afterFailure.output.registrations,0,'Skip automatic retry during cooldown');

    const invalid=scenario('granted',{}, {invalidVapid:true});
    await invalid.find('edwp-toggle').trigger('click');
    await flush();
    assert.equal(invalid.output.registrations,0,'Invalid VAPID blocked before FCM');
    assert(invalid.find('edwp-info').value.includes('VAPID'), 'Explain invalid VAPID');

    // Un error de red no debe dejar el botón desactivado para siempre.
    const slow=scenario('default',{}, {pushError:true});
    await slow.find('edwp-invite-accept').trigger('click');
    await flush();
    assert.equal(slow.find('edwp-toggle').disabled,false,
        'The activation button must recover after a failed attempt');

    // Aislar suscripción del navegador del registro Firebase.
    const nativeSuccess=scenario('granted',{'edusync:webpush:opted-out:student:1:42':'1'});
    const publicConfig={
        configured:true, push_ready:true,
        firebase:{messagingSenderId:'123456789',appId:'1:123456789:web:abc'},
        vapid_key:sampleVapid
    };
    const nativeActive=await nativeSuccess.window.navigator.serviceWorker.getRegistration('./');
    const nativeOk=await nativeSuccess.window.EduSyncWebPushDiagnostic.probe(publicConfig,nativeActive);
    assert.equal(nativeOk.ok,true,'Native Push API succeeds without Firebase');
    assert.equal(nativeSuccess.output.nativeSubscribeCalls,1);
    assert.equal(nativeSuccess.output.registrations,0,'Native test should not register FCM tokens');
    assert(!nativeOk.message.includes('token-value'),'Push endpoint must not be logged');

    const nativeFailure=scenario('granted',{}, {nativePushError:true});
    const nativeBrokenReg=await nativeFailure.window.navigator.serviceWorker.getRegistration('./');
    const nativeBad=await nativeFailure.window.EduSyncWebPushDiagnostic.probe(publicConfig,nativeBrokenReg);
    assert.equal(nativeBad.ok,false,'Native Push API reports browser failure');
    assert(nativeBad.message.includes('AbortError'),'Browser error should be reported');
    assert(nativeBad.message.includes('antes de Firebase'),'Distinguish native browser failure');
    assert(!nativeBad.message.includes('token-value'),'No endpoint in error report');

    const denied=scenario('denied');
    await flush();
    assert.equal(denied.find('edwp-invite-dialog'),undefined);
    assert.equal(denied.output.permissionRequests,0);

    const notMigrated=scenario('default',{}, {migrated:false});
    assert.equal(notMigrated.find('edwp-invite-dialog'),undefined);
    const notConfigured=scenario('default',{}, {configured:false});
    assert.equal(notConfigured.find('edwp-invite-dialog'),undefined);

    process.stdout.write('OK: consentimiento explícito, renovación previa, Firebase/VAPID y Push nativo, sin secretos.\n');
})().catch(error=>{console.error(error);process.exitCode=1;});
