# Guía de Prueba del Navbar - EduSync

## 📋 Archivos Creados

### Estructura de Directorios
```
startbootstrap-sb-admin-2-gh-pages/
├── includes/
│   ├── navbar.php       ✅ Sidebar con menús por rol
│   ├── topbar.php       ✅ Barra superior
│   └── footer.php       ✅ Pie de página
├── pages/
│   └── home.php         ✅ Dashboard principal
├── index.php            ✅ Página principal con routing
├── login.php            ✅ Login con usuarios demo
└── logout.php           ✅ Cerrar sesión
```

## 🧪 Cómo Probar

### 1. Iniciar XAMPP
1. Abrir XAMPP Control Panel
2. Iniciar **Apache**
3. Asegurarse que esté corriendo en el puerto 80

### 2. Acceder al Sistema
Abrir en el navegador:
```
http://localhost/interfaz/startbootstrap-sb-admin-2-gh-pages/login.php
```

### 3. Usuarios de Prueba

Puedes probar el sistema con estos 4 usuarios diferentes:

#### 👨‍💼 Administrador
- **Usuario:** `admin`
- **Contraseña:** `admin123`
- **Características:**
  - Ve TODOS los menús
  - Acceso a: Estudiantes, Docentes, Cursos, Competencias, Pagos, Asistencia, Notas, Reportes, Usuarios

#### 👨‍🏫 Profesor
- **Usuario:** `profesor`
- **Contraseña:** `profesor123`
- **Características:**
  - Menús limitados a funciones académicas
  - Acceso a: Mis Cursos, Notas, Reporte de Notas, Competencias

#### 👨‍💻 Auxiliar
- **Usuario:** `auxiliar`
- **Contraseña:** `auxiliar123`
- **Características:**
  - Enfocado en asistencia
  - Acceso a: Asistencia, Reglas de Asistencia, Reporte de Asistencia

#### 🎓 Estudiante
- **Usuario:** `estudiante`
- **Contraseña:** `estudiante123`
- **Características:**
  - Vista personal del alumno
  - Acceso a: Mis Notas, Mi Horario, Mis Pagos, Mis Deudas, Mi Asistencia

## ✅ Verificación de Funcionalidades

### Navegación por Roles
- [ ] Login con usuario **admin** → Ver menú completo
- [ ] Login con usuario **profesor** → Ver solo opciones de profesor
- [ ] Login con usuario **auxiliar** → Ver solo opciones de asistencia
- [ ] Login con usuario **estudiante** → Ver solo opciones personales

### Sidebar
- [ ] Click en el botón de hamburguesa (toggle) → Sidebar se colapsa/expande
- [ ] En modo colapsado → Solo se ven iconos
- [ ] Click en cualquier enlace → Se marca como activo (azul)
- [ ] Menús desplegables (Pagos, Reportes) → Se expanden/contraen correctamente

### Topbar
- [ ] Se muestra el nombre del usuario logueado
- [ ] Dropdown de perfil funciona
- [ ] Click en "Cerrar Sesión" → Abre modal de confirmación
- [ ] Confirmar cierre → Redirige a login

### Responsividad
- [ ] Desktop (> 768px) → Sidebar visible, botón de colapso funciona
- [ ] Mobile (< 768px) → Sidebar oculto por defecto
- [ ] Mobile → Click en hamburguesa abre sidebar desde la izquierda

### Dashboard
- [ ] Cada rol muestra tarjetas diferentes en el dashboard
- [ ] Admin: 4 tarjetas (Estudiantes, Pagos, Asistencia, Deudas)
- [ ] Profesor: 3 tarjetas (Cursos, Estudiantes, Notas Pendientes)
- [ ] Estudiante: 2 tarjetas (Promedio, Deuda)

## 🔧 Solución de Problemas

### Error 404 - Página no encontrada
- Verificar que XAMPP Apache esté corriendo
- Verificar la ruta: debe ser `/interfaz/startbootstrap-sb-admin-2-gh-pages/`

### No se ve el sidebar
- Verificar que los archivos CSS se carguen correctamente
- Abrir DevTools (F12) → Console para ver errores

### Sesión no funciona
- Verificar que PHP esté habilitado en XAMPP
- Verificar que `session_start()` no arroje errores

## 🎨 Personalización Futura

Para adaptar más el sistema a tus necesidades:

1. **Conectar con tu base de datos:**
   - Editar `login.php` para validar usuarios desde MySQL
   - Reemplazar array `$demo_users` con consulta a BD

2. **Crear páginas reales:**
   - Agregar archivos en carpeta `pages/`
   - Ejemplo: `pages/students.php`, `pages/grades.php`, etc.

3. **Cambiar colores:**
   - Editar `scss/_variables.scss` y recompilar
   - O editar directamente `css/sb-admin-2.min.css`

4. **Agregar más menús:**
   - Editar `includes/navbar.php`
   - Agregar nuevos `<li>` items según el rol

---

**Estado:** ✅ Navbar completamente funcional y listo para pruebas
**Siguiente Paso:** Probar en navegador y migrar páginas individuales del sistema antiguo
