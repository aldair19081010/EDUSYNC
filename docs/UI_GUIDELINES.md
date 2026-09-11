# Guía visual de EduSync

Esta guía define el patrón visual común para los módulos web de EduSync. La base continúa siendo **Bootstrap 4 + SB Admin 2 + Font Awesome**. No se reemplaza el framework ni se crea un sistema paralelo.

## Principios

- Usar `#4285f4` como color primario de EduSync.
- Reservar verde para éxito/activo/pagado, amarillo para advertencia/pendiente, rojo para error/anulación/deuda y gris para estados secundarios o inactivos.
- Reutilizar Bootstrap antes de crear CSS nuevo.
- Evitar estilos `style="..."` cuando el mismo patrón pueda vivir en `css/custom.css`.
- Evitar crear nuevas familias de clases por módulo para encabezados, KPI, filtros y paneles.
- No aplicar animaciones de desplazamiento vertical diferentes entre módulos.

## Tokens globales

Los tokens están definidos en `edusync/css/custom.css`:

```css
--ed-primary
--ed-primary-hover
--ed-primary-active
--ed-heading
--ed-text
--ed-muted
--ed-border
--ed-border-soft
--ed-surface
--ed-surface-soft
--ed-success
--ed-warning
--ed-danger
--ed-info
--ed-radius
--ed-radius-sm
--ed-shadow
```

## Encabezado de página

Los módulos nuevos deben usar el patrón `ed-page-*`:

```html
<div class="ed-page-header">
    <div class="ed-page-heading">
        <div class="ed-page-icon"><i class="fas fa-users"></i></div>
        <div>
            <h1 class="ed-page-title">Estudiantes</h1>
            <p class="ed-page-subtitle">Administra alumnos y su información académica.</p>
        </div>
    </div>
    <div class="ed-page-actions">
        <button class="btn btn-primary btn-sm">
            <i class="fas fa-plus mr-1"></i>Nuevo estudiante
        </button>
    </div>
</div>
```

Un encabezado debe contener como máximo: icono, título, descripción breve y acciones principales.

## Indicadores

```html
<div class="ed-stat-card">
    <div class="ed-stat-label">Estudiantes activos</div>
    <strong class="ed-stat-value">320</strong>
    <div class="ed-stat-meta">Matrícula actual</div>
</div>
```

Los KPI no deben tener efectos de salto al pasar el mouse.

## Filtros

Preferir el grid de Bootstrap:

```html
<div class="ed-filter-card">
    <div class="row align-items-end">
        <div class="col-md-3 mb-2">
            <label class="small font-weight-bold">Año académico</label>
            <select class="form-control form-control-sm"></select>
        </div>
        <div class="col-md-3 mb-2">
            <label class="small font-weight-bold">Nivel</label>
            <select class="form-control form-control-sm"></select>
        </div>
    </div>
</div>
```

No crear un grid CSS nuevo para cada pantalla salvo que Bootstrap no pueda resolver la necesidad.

## Panel de contenido

```html
<div class="ed-content-card">
    <div class="ed-content-card-header">
        <strong><i class="fas fa-table text-primary mr-2"></i>Listado</strong>
        <div>
            <button class="btn btn-sm btn-outline-primary">Actualizar</button>
        </div>
    </div>
    <div class="ed-content-card-body">
        ...
    </div>
</div>
```

## Tablas

Patrón recomendado:

```html
<div class="table-responsive">
    <table class="table table-hover table-sm">
        <thead class="thead-light">...</thead>
        <tbody>...</tbody>
    </table>
</div>
```

Usar `table-bordered` solo cuando ayude a interpretar matrices densas, por ejemplo un libro de notas.

## Botones

- `btn-primary`: acción principal de la pantalla.
- `btn-outline-primary`: acción secundaria relacionada.
- `btn-light border`: limpiar, volver, cancelar no destructivo.
- `btn-success`: confirmación o acción de resultado positivo cuando tenga significado real.
- `btn-danger` / `btn-outline-danger`: acciones destructivas.
- Preferir `btn-sm` en toolbars, tablas y filtros.

## Modales

Usar la estructura Bootstrap estándar. Los tamaños admitidos por EduSync son `modal-sm`, `modal-lg`, `modal-xl` y `mid-large` cuando el contenido lo requiera. No crear cabeceras de modal con colores arbitrarios salvo que el color comunique un estado funcional importante.

## Compatibilidad con módulos existentes

`custom.css` contiene una capa de compatibilidad para las familias de clases antiguas (`py-*`, `am-*`, `cp-*`, `df-*`, `gr-*`, `ar-*`, etc.). Esto permite unificar la apariencia sin reescribir inmediatamente cada módulo.

Cuando se vuelva a trabajar un módulo, se recomienda reemplazar gradualmente sus estilos duplicados por los componentes `ed-*`. No se deben hacer conversiones masivas que mezclen cambios visuales con cambios de lógica.
