# Fubball KDS

Backend PHP + MySQL para gestionar pedidos de despacho de Fubball (tienda
de artículos deportivos). Fase actual: login y gestión de usuarios. Pedidos,
tablero KDS, cola de despacho y facturación llegan en fases siguientes.

Arquitectura reutiliza el patrón `core/db`, `core/auth`, `core/ui`,
`core/util` usado en otros proyectos internos, adaptada para correr en
hosting compartido tipo cPanel (sin SSH garantizado, sin asumir Composer).

## 1. Configurar el .env

El código es el mismo en local y en producción — lo único que cambia es
el archivo `.env`.

```bash
cp .env.example .env
```

Completa `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASS` con las
credenciales de tu MySQL (local o del hosting), y `APP_URL` con la URL
real donde vive el proyecto (con o sin subcarpeta). En producción, cambia
`APP_ENV=production` — esto desactiva la salida de errores PHP crudos al
navegador (siguen quedando en el log del servidor).

`.env` está en `.gitignore`: nunca se sube al repositorio. `.env.example`
sí se versiona, como plantilla.

El loader (`core/util/env.php`) no depende de Composer — funciona igual
si el hosting no lo soporta. Se carga una sola vez desde
`core/bootstrap.php`, que es el punto de entrada común de toda página:

```php
require_once __DIR__ . '/core/bootstrap.php';
```

`bootstrap.php` carga el `.env`, configura errores/zona horaria/sesión, y
deja disponibles las clases `Database` y `Auth`, las funciones de CSRF, y
el helper `baseUrl()`.

## 2. Base de datos

Corre el schema `fubball_kds_schema.sql` (en la raíz del proyecto) contra
tu base de datos:

```bash
mysql -u root -p fubball_kds < fubball_kds_schema.sql
```

O impórtalo vía phpMyAdmin si estás en un hosting compartido sin acceso
a la línea de comandos.

El código (`Auth.php`, `usuarios.php`, `usuarios_guardar.php`,
`scripts/crear_admin.php`) está escrito contra el schema real de
`usuarios`: `id, nombre, usuario, email, password_hash, rol, activo,
intentos_fallidos, bloqueado_hasta, ultimo_acceso, creado_en`, con
`rol ENUM('admin','almacen','despacho','ventas')`.

## 3. Crear el primer usuario admin

No hay ningún admin hardcodeado en el schema. La única forma de crear el
primer admin es con el script de línea de comandos:

```bash
php scripts/crear_admin.php
```

Pide nombre, usuario, email y contraseña por STDIN, valida los datos,
hashea la contraseña con `password_hash()` e inserta el registro con
`rol = 'admin'`.

En este entorno de desarrollo (XAMPP en macOS, sin `php` en el PATH por
defecto), el comando completo es:

```bash
/Applications/XAMPP/xamppfiles/bin/php scripts/crear_admin.php
```

## 4. Probar el login de punta a punta

1. Levanta MySQL (o el servidor de XAMPP) y confirma que `.env` apunta a
   la base correcta.
2. Corre `fubball_kds_schema.sql` (paso 2).
3. Crea el admin con `scripts/crear_admin.php` (paso 3).
4. Abre `APP_URL` en el navegador (ej. `http://localhost/fubball_kds/`) —
   redirige a `login.php`.
5. Inicia sesión con el usuario/contraseña recién creados → debe llevarte
   a `index.php` (dashboard placeholder) con el sidebar mostrando "Usuarios"
   (por ser admin).
6. Entra a "Usuarios", crea un segundo usuario con rol `operador`, cierra
   sesión y entra con ese usuario — el ítem "Usuarios" del sidebar no debe
   aparecer, y visitar `/usuarios.php` directo debe responder 403.

## Estructura

```
core/
  db/Database.php         Conexión PDO (singleton)
  auth/Auth.php            Login, logout, sesión, roles
  auth/csrf.php             Token CSRF por sesión
  ui/layout_header.php      Head + sidebar + topbar
  ui/layout_footer.php      Cierre de body/html
  util/env.php               Loader de .env sin dependencias
  bootstrap.php               Punto de entrada común
login.php
logout.php
index.php                  Dashboard placeholder
usuarios.php                Listado + alta/edición (solo admin)
usuarios_guardar.php         Procesa el POST de usuarios.php
scripts/crear_admin.php      CLI para crear el primer admin
```
