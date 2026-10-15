# EduSync · Comunicados para docentes

Rama Web: teacher-institutional-announcements-v1
Rama Flutter complementaria: teacher-announcement-inbox-v1

## Comportamiento
Administrador > Comunicados: botón Estudiantes / Docentes.
La pestaña de estudiantes y su API original (announcements_api.php) siguen
sin cambios en el procesamiento. Docentes permite enviar a todos los profesores
activos del colegio o seleccionarlos individualmente mediante Select2.
El título tiene 3 a 180 caracteres; el cuerpo, 3 a 5000.

Cada docente recibe su propio notification_events (tipo teacher_announcement),
independiente de disponer de un teléfono. Un registro de destinatario vincula
el evento con el comunicado completo y garantiza que no haya duplicados.
El resumen del push es corto porque notification_events.message es VARCHAR(500).
El texto completo se lee por un endpoint protegido dentro de la app Flutter y
por notification_detail.php en la web, con verificación de colegio y destinatario.

## Persistencia y permisos
Ejecutar manualmente en la base de datos local/de pruebas:
    sql/teacher_announcements_v1.sql

Crea teacher_announcements y teacher_announcement_recipients; no modifica
student_announcements ni las notas, pagos o políticas existentes.
Sólo administración (users.type=1) envía y consulta historial docente; cada
profesor (users.type=2) sólo puede consultar contenido vinculado a SU evento.
POST requiere CSRF, destinatarios se validan en servidor y request_key único
evita duplicados al reenviar accidentalmente el formulario. La auditoría
conserva autor, fecha y destinatarios.

## Firebase y estados
Con hasta 15 destinatarios, se encolan intentos inmediatos al terminar PHP.
Envíos mayores son despachados por el cron existente:
    edusync/cron/web_push_dispatch.php
Comprueba que ese cron funciona en el servidor antes de usar envíos masivos.

Las notificaciones usan la rama previa que diferencia Chrome del token
Flutter: no hace falta añadir un cron, suscripción o configuración Firebase
nuevos. La confirmación «registrado en bandeja» NO equivale a entrega
FCM. El historial permite revisar estado FCM y lectura por usuario.
«Aceptado FCM» tampoco confirma recepción visual ni lectura.

## Prueba local (PowerShell)
En el repositorio EduSync:
    cd C:\xampp\htdocs\interfaz
    git status --short
    git fetch origin
    git switch --track origin/teacher-institutional-announcements-v1
    C:\xampp\php\php.exe -l edusync/teacher_announcements_api.php
    C:\xampp\php\php.exe -l edusync/notification_detail.php
    C:\xampp\php\php.exe -l edusync/pages/announcements.php
    C:\xampp\php\php.exe tools/test_teacher_announcements_v1.php

En phpMyAdmin, ejecutar sql/teacher_announcements_v1.sql.
Probar envío a un solo docente de prueba, luego a todos, verificar
bandeja, detalle, lectura, permisos entre docentes y colegios y FCM.

En el repositorio de Flutter:
    cd C:\gestion_pagos_app
    git status --short
    git fetch origin
    git switch --track origin/teacher-announcement-inbox-v1
    flutter pub get
    flutter analyze
    flutter test test/teacher_notifications_unified_test.dart
    flutter run

Se necesita un APK recompilado con Firebase local correctamente configurado.
No fusionar a main ni instalar en producción antes de validar la migración,
la app y el cron de entregas en un entorno de pruebas.

## Archivos principales
- sql/teacher_announcements_v1.sql
- edusync/teacher_announcements_api.php
- edusync/pages/announcements.php
- edusync/notifications_api.php
- edusync/notification_detail.php
- tools/test_teacher_announcements_v1.php
- app Flutter: lib/services/teacher_notification_api.dart,
  lib/screens/teacher_notifications_screen.dart
