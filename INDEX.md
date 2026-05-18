# 📑 Índice de Archivos - Proyecto Apoderados

## 📍 Ubicación
`c:\Users\kurtg\Downloads\apoderados`

## 📄 Documentación (Nueva)

| Archivo | Propósito | Prioridad |
|---------|-----------|-----------|
| **QUICKSTART.md** | ⚡ Inicio rápido en 5 minutos | 🔴 LEER PRIMERO |
| **SETUP.md** | 📋 Guía paso a paso detallada | 🟠 Importante |
| **README_ANGULAR.md** | 📖 Documentación completa del proyecto | 🟡 Referencia |
| **EJEMPLOS_CODIGO.md** | 💻 Ejemplos de código listos para usar | 🟢 Para desarrollo |
| **setup.bat** | 🪟 Script automático (Windows) | 🟢 Facilita instalación |
| **install.sh** | 🐧 Script automático (Mac/Linux) | 🟢 Facilita instalación |

## 🔙 Archivos PHP Originales (Backend)

| Archivo | Funcionalidad |
|---------|-------------|
| `login.php` | Autenticación de apoderados |
| `panel_apoderado.php` | Dashboard principal |
| `logout.php` | Cierre de sesión |
| `enviar_pago_cobranzas.php` | Sistema de pagos |
| `verificar_pago_admin.php` | Verificación de pagos (admin) |
| `autorizar_transferencia.php` | Autorización de transferencias |
| `procesar_transferencia.php` | Procesamiento de transferencias |
| `rechazar_transferencia.php` | Rechazo de transferencias |
| `procesar_uniforme.php` | Procesamiento de compra de uniformes |
| `uniformes.php` | Catálogo de uniformes |
| `ficha_matricula.php` | Fichas de matrícula |
| `guardar_ficha_matricula.php` | Guardado de fichas |
| `guardar_contrato.php` | Guardado de contratos |
| `subir_comprobante.php` | Subida de comprobantes |
| `apoderado_msg.php` | Sistema de mensajes |
| `apoderado_gracias.php` | Página de confirmación |
| `_diag_panel.php` | Panel de diagnóstico |
| `webpay.php` | Integración Webpay |
| `index.php` | Punto de entrada |
| `.htaccess` | Configuración Apache |

## 🎨 Archivos Estáticos

| Archivo | Tipo |
|---------|------|
| `logo.png` | 🖼️ Logo en PNG |
| `logo.svg` | 🖼️ Logo en SVG (recomendado) |
| `escuela.mp4` | 🎥 Video |
| `docs/` | 📁 Carpeta de documentación |

## 🎯 Flujo de Trabajo Recomendado

### 1️⃣ Primero: Lee esto
```
QUICKSTART.md → 5 min de lectura rápida
```

### 2️⃣ Luego: Instala el proyecto
```
Ejecuta: setup.bat (Windows) o install.sh (Mac/Linux)
O sigue SETUP.md paso a paso
```

### 3️⃣ Desarrollo: Consulta ejemplos
```
EJEMPLOS_CODIGO.md → Copia y adapta código
```

### 4️⃣ Referencia: Documentación
```
README_ANGULAR.md → Para entender la arquitectura
```

## 📊 Estructura Final Esperada

Después de ejecutar setup:

```
apoderados/
├── 📄 Documentación (este nivel)
├── 🪟 setup.bat
├── 🐧 install.sh
├── 📁 backend/              (nuevo)
│   ├── api/                 (nuevo)
│   ├── config/              (nuevo)
│   ├── controllers/         (nuevo)
│   └── models/              (nuevo)
├── 📁 frontend/             (nuevo - Angular)
│   ├── src/
│   │   ├── app/
│   │   ├── assets/
│   │   └── styles/
│   ├── package.json
│   └── angular.json
├── 📁 docs/                 (existente)
└── *.php                    (archivos PHP existentes)
```

## 🔄 Migración de Funcionalidades

### De PHP a Angular

| Función PHP | Nuevo Componente Angular |
|------------|----------------------|
| `login.php` | `components/auth/login` |
| `panel_apoderado.php` | `components/apoderado/panel` |
| `enviar_pago_cobranzas.php` | `components/pagos/enviar` |
| `uniformes.php` | `components/uniformes/lista` |
| `ficha_matricula.php` | `components/fichas/form` |

## 🛠️ Herramientas de Setup

### Windows (Recomendado)
```bash
.\setup.bat
```

### Mac/Linux
```bash
chmod +x ./install.sh
./install.sh
```

### Manual (Todos)
```bash
npm install -g @angular/cli@latest
ng new frontend --routing --style=scss --skip-git --package-manager=npm
cd frontend && npm install && npm start
```

## 📞 Necesitas Ayuda?

1. **¿Cómo empiezo?** → Lee `QUICKSTART.md`
2. **¿Pasos detallados?** → Abre `SETUP.md`
3. **¿Ejemplos de código?** → Consulta `EJEMPLOS_CODIGO.md`
4. **¿Arquitectura?** → Lee `README_ANGULAR.md`
5. **¿Error?** → Revisa console del navegador (F12)

## ✨ Cambios Realizados

✅ **Documentación creada**:
- Guía de inicio rápido
- Guía paso a paso
- Ejemplos de código
- Documentación completa

✅ **Scripts de instalación**:
- setup.bat para Windows
- install.sh para Mac/Linux

✅ **Organización**:
- Estructura backend lista
- Carpeta frontend lista
- Configuración recomendada

## 🎓 Stack Tecnológico

```
Frontend:  Angular 17+ | TypeScript | SCSS | RxJS
Backend:   PHP 7.4+   | MySQL | REST API
Auth:      JWT Tokens | LocalStorage
HTTP:      REST Endpoints | CORS
```

## 🚀 ¡Próximo Paso!

Abre **QUICKSTART.md** y comienza la instalación. Tardará menos de 5 minutos. 🎯

---

**Versión**: 1.0.0  
**Fecha**: 2026-05-17  
**Estado**: 🟢 Listo para usar
