# Guía de Instalación - Sistema de Gestión de Estudiantes

## Resumen
Este es un sistema completo de gestión de estudiantes integrado a tu interfaz PHP existente. Incluye funcionalidades para:
- ✅ Crear, editar, ver y eliminar estudiantes
- ✅ Importar estudiantes desde archivos Excel
- ✅ Filtrar y buscar estudiantes
- ✅ Exportar datos a CSV
- ✅ Descargar formato de plantilla Excel

## Pasos de Instalación

### 1. Crear la Base de Datos
Ejecuta el script SQL proporcionado:

**Opción A: phpMyAdmin**
1. Accede a phpMyAdmin
2. Selecciona tu base de datos `escuela2`
3. Abre la pestaña "SQL"
4. Copia y pega el contenido de `sql_students_schema.sql`
5. Haz clic en "Ejecutar"

**Opción B: Línea de comandos MySQL**
```bash
mysql -u root -p escuela2 < sql_students_schema.sql
```

### 2. Archivos Creados

Se han creado los siguientes archivos en tu directorio raíz:

#### Archivos principales:
- **students.php** - Página principal de gestión de estudiantes con tabla, filtros y botones de acción
- **manage_student.php** - Formulario para crear/editar estudiantes
- **view_student.php** - Vista detallada de un estudiante

#### Archivos de API/Backend:
- **students_table_data.php** - API para DataTables (paginación y filtros)
- **students_export.php** - Exportar datos a CSV
- **download_format.php** - Descargar plantilla Excel para importación
- **ajax.php** - Se añadieron 3 nuevas acciones:
  - `save_student` - Guardar/actualizar estudiante
  - `delete_student` - Eliminar estudiante
  - `upload_excel` - Importar desde Excel

#### Base de datos:
- **sql_students_schema.sql** - Script para crear tablas (grados, secciones, students)

### 3. Integración en tu Sistema

Para integrar el módulo de estudiantes a tu interfaz:

#### Opción A: Agregar enlace en el menú
Edita tu archivo de navegación (probablemente `includes/navbar.php` o similar) y añade:

```html
<li class="nav-item">
    <a class="nav-link" href="students.php">
        <i class="fas fa-graduation-cap"></i>
        <span>Estudiantes</span>
    </a>
</li>
```

#### Opción B: Crear una página wrapper
Si prefieres mantener la estructura actual, crea un archivo `students_wrapper.php`:

```php
<?php
// students_wrapper.php
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['login_id'])) {
    header('location:login.php');
    exit();
}

include('includes/header.php');
?>

<div id="content-wrapper">
    <div class="container-fluid">
        <?php include('students.php'); ?>
    </div>
</div>

<?php include('includes/footer.php'); ?>
```

### 4. Estructura de la Base de Datos

#### Tabla: grados
- `id` - ID único
- `nombre` - Nombre del grado (1º, 2º, 3º, etc.)
- `nivel` - Nivel educativo (Inicial, Primaria, Secundaria)

#### Tabla: secciones
- `id` - ID único
- `nombre` - Nombre de la sección (A, B, C, etc.)

#### Tabla: students
- `id` - ID único
- `dni` - DNI del estudiante (único)
- `nombre` - Nombre
- `apellido` - Apellido
- `email` - Email (único)
- `telefono` - Teléfono de contacto
- `telefono_apoderado` - Teléfono del apoderado
- `fecha_nacimiento` - Fecha de nacimiento
- `genero` - Género (Masculino/Femenino)
- `direccion` - Dirección
- `nivel` - Nivel educativo
- `grado_id` - Referencia al grado
- `seccion_id` - Referencia a la sección
- `status` - Estado (Activo, Egresado, Retirado)
- `apoderado_nombre` - Nombre del apoderado
- `apoderado_parentesco` - Parentesco del apoderado
- `observaciones` - Observaciones adicionales
- `created_at` - Fecha de creación
- `updated_at` - Fecha de actualización

### 5. Funcionalidades Disponibles

#### Listar Estudiantes
- Tabla con DataTables (paginación, búsqueda)
- Filtros por nivel, estado, grado y sección
- Botones de acción (ver, editar, eliminar)

#### Crear Estudiante
- Formulario con validación
- Campos: DNI, nombre, apellido, email, nivel, grado, sección
- Información de contacto y apoderado

#### Editar Estudiante
- Cargar datos existentes
- Validación completa
- Actualizar todos los campos

#### Eliminar Estudiante
- Confirmación antes de eliminar
- Eliminación física de la base de datos

#### Importar desde Excel
- Cargar archivo .xls o .xlsx
- Validación automática
- Crear o actualizar estudiantes por DNI
- Descargar plantilla de formato

#### Exportar a CSV
- Exportar con filtros aplicados
- Compatible con Excel
- Incluye todos los campos

### 6. Uso de la Plantilla Excel

Para importar estudiantes masivamente:

1. Haz clic en "Filtros" → "Descargar Formato"
2. Completa el archivo Excel con los datos
3. Columnas requeridas: DNI, Nombre, Apellido, Email
4. Haz clic en "Agregar" → "Subir Excel"
5. Selecciona el archivo y confirma

### 7. Requisitos

- PHP 7.0+
- MySQL 5.7+
- Bootstrap 4.x
- jQuery 3.x
- DataTables 1.10.x
- PhpSpreadsheet (ya incluido en vendor/)
- FontAwesome (ya incluido)

### 8. Ajustes Necesarios

Según tu base de datos actual, podrías necesitar:

1. **Si tu tabla de estudiantes tiene otro nombre:**
   - Edita `students_table_data.php`
   - Reemplaza `FROM students` con tu nombre de tabla

2. **Si tu estructura es diferente:**
   - Ajusta los campos en `manage_student.php`
   - Actualiza `admin_class.php` (funciones save_student y upload_excel)

3. **Si tienes otra estructura de grados/secciones:**
   - Modifica la consulta en `students_table_data.php`
   - Actualiza los JOINs según tu estructura

### 9. Solución de Problemas

**Error: "Tabla no existe"**
- Ejecuta el script SQL nuevamente
- Verifica que estés en la base de datos correcta

**Error: "Permiso denegado"**
- Verifica que la sesión esté iniciada
- Comprueba `if (session_status() == PHP_SESSION_NONE)`

**Excel no se importa**
- Verifica que el archivo esté en formato .xlsx
- Comprueba que PhpOffice/PhpSpreadsheet esté instalado
- Revisa los permisos de escritura en la carpeta

**Datos no aparecen en la tabla**
- Abre la consola del navegador (F12)
- Verifica si hay errores de AJAX
- Comprueba que `students_table_data.php` se accede correctamente

### 10. Personalización

Puedes personalizar:

1. **Colores y estilos:** Edita los estilos CSS al inicio de `students.php`
2. **Campos del formulario:** Añade/elimina campos en `manage_student.php`
3. **Filtros:** Modifica los filtros en `students.php` y `students_table_data.php`
4. **Validaciones:** Ajusta las validaciones en `admin_class.php`

### 11. Seguridad

⚠️ **Importante:**
- Los archivos escapan correctamente las entradas SQL
- Implementa control de acceso según tus necesidades
- Considera usar prepared statements en producción
- Limita el tamaño de archivos Excel importados

### 12. Contacto y Soporte

Si tienes problemas:
1. Revisa los errores en el navegador (F12 → Console)
2. Verifica los logs de Apache/PHP
3. Comprueba la estructura de tu base de datos

---

**¡Sistema listo para usar!** 🚀

Accede a `students.php` para comenzar a gestionar estudiantes.
