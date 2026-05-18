# 📱 Apoderados - Aplicación Angular + PHP

Sistema de gestión de apoderados (guardianes) transformado a una arquitectura moderna con **Angular** como frontend y **PHP** como backend.

## 🎯 Objetivo

Modernizar la aplicación PHP monolítica existente en:
- **Frontend**: Aplicación SPA (Single Page Application) con Angular
- **Backend**: API REST en PHP (existente, refactorizado en fases)

## ✨ Características

- ✅ **Autenticación segura** con JWT
- ✅ **Gestión de pagos** y transferencias
- ✅ **Compra de uniformes** online
- ✅ **Fichas de matrícula** digitales
- ✅ **Dashboard** personalizado por apoderado
- ✅ **Interfaz responsiva** (móvil, tablet, desktop)
- ✅ **API REST** para escalabilidad

## 📂 Estructura del Proyecto

```
apoderados/
├── frontend/                      # Aplicación Angular
│   ├── src/
│   │   ├── app/
│   │   │   ├── components/        # Componentes de UI
│   │   │   ├── services/          # Servicios HTTP
│   │   │   ├── interceptors/      # Interceptores (auth, errores)
│   │   │   ├── models/            # Interfaces TypeScript
│   │   │   ├── app.component.ts
│   │   │   └── app.routes.ts      # Rutas de la app
│   │   ├── assets/                # Imágenes, logos, etc.
│   │   ├── styles/                # SCSS global
│   │   └── main.ts                # Entry point
│   ├── angular.json               # Config Angular
│   ├── package.json
│   ├── tsconfig.json
│   └── README.md
│
├── backend/                       # PHP (existente)
│   ├── api/                       # Nuevos endpoints REST
│   ├── config/
│   ├── controllers/
│   ├── models/
│   ├── *.php                      # Archivos existentes
│   └── README.md
│
├── docs/                          # Documentación
├── SETUP.md                       # Guía de instalación
├── EJEMPLOS_CODIGO.md             # Ejemplos de código
└── README.md                      # Este archivo
```

## 🚀 Inicio Rápido

### 1. Requisitos

```bash
node -v         # v18+
npm -v          # v9+
php -v          # 7.4+
```

### 2. Instalar Angular CLI

```bash
npm install -g @angular/cli@latest
```

### 3. Crear proyecto Angular

```bash
cd apoderados
ng new frontend --routing --style=scss --skip-git --package-manager=npm
cd frontend
npm install
```

### 4. Iniciar servidor

```bash
npm start
```

**URL**: http://localhost:4200

## 🏗️ Arquitectura

### Frontend (Angular)

```
User Interface (Browser)
    ↓
Angular Components & Services
    ↓
HTTP Client (RxJS)
    ↓
API REST Backend (PHP)
    ↓
Database
```

### Stack Tecnológico

| Capa | Tecnología |
|------|-----------|
| **Frontend** | Angular 17+, TypeScript, RxJS, SCSS |
| **Backend** | PHP 7.4+, MySQL |
| **Auth** | JWT (JSON Web Tokens) |
| **HTTP** | REST API |

## 📦 Dependencias Principales

```json
{
  "@angular/core": "^17.0.0",
  "@angular/router": "^17.0.0",
  "@angular/forms": "^17.0.0",
  "@angular/common/http": "^17.0.0",
  "rxjs": "^7.8.0",
  "typescript": "~5.2.0"
}
```

## 🔐 Seguridad

- **JWT Tokens**: Autenticación stateless
- **CORS**: Control de origen cruzado
- **HTTPS**: Conexiones seguras (producción)
- **Token Storage**: LocalStorage con encriptación
- **Interceptores**: Validación automática de tokens

## 📋 Funcionalidades

### Autenticación
- [x] Login/Logout
- [ ] Recuperación de contraseña
- [ ] Registro de nuevos apoderados
- [ ] 2FA (en futuro)

### Panel de Apoderado
- [x] Dashboard
- [ ] Perfil
- [ ] Historial de acciones
- [ ] Notificaciones

### Pagos
- [ ] Ver pagos pendientes
- [ ] Registrar comprobantes
- [ ] Historial de pagos
- [ ] Descargar recibos

### Transferencias
- [ ] Solicitar transferencia
- [ ] Autorizar transferencia
- [ ] Historial

### Uniformes
- [ ] Catálogo de uniformes
- [ ] Carrito de compra
- [ ] Realizar pedido
- [ ] Rastrear pedido

## 🔗 API Endpoints

### Autenticación
```
POST   /api/auth/login             # Iniciar sesión
POST   /api/auth/logout            # Cerrar sesión
POST   /api/auth/refresh           # Refrescar token
GET    /api/auth/me                # Datos del usuario
```

### Apoderado
```
GET    /api/apoderado/profile      # Perfil del apoderado
GET    /api/apoderado/dashboard    # Dashboard
PUT    /api/apoderado/profile      # Actualizar perfil
```

### Pagos
```
GET    /api/pagos                  # Listar pagos
GET    /api/pagos/:id              # Detalle de pago
POST   /api/pagos/enviar           # Enviar pago
GET    /api/pagos/recibo/:id       # Descargar recibo
```

### Transferencias
```
GET    /api/transferencias         # Listar transferencias
POST   /api/transferencias         # Crear transferencia
PUT    /api/transferencias/:id/autorizar  # Autorizar
GET    /api/transferencias/:id     # Detalle
```

### Uniformes
```
GET    /api/uniformes              # Catálogo
POST   /api/uniformes/comprar      # Realizar compra
GET    /api/pedidos                # Mis pedidos
GET    /api/pedidos/:id            # Detalle del pedido
```

### Fichas
```
GET    /api/fichas                 # Listar fichas
POST   /api/fichas                 # Crear ficha
PUT    /api/fichas/:id             # Actualizar ficha
GET    /api/fichas/:id/descargar   # Descargar PDF
```

## 🧪 Testing

```bash
# Tests unitarios
npm test

# Tests E2E
npm run e2e

# Cobertura
npm test -- --code-coverage
```

## 📦 Build & Deploy

### Desarrollo
```bash
npm start
```

### Producción
```bash
npm run build:prod
# Archivos compilados en: dist/apoderados-app/
```

### Docker (opcional)
```bash
docker build -t apoderados-app .
docker run -p 4200:80 apoderados-app
```

## 📚 Documentación Adicional

- [Angular Docs](https://angular.io/docs)
- [TypeScript Handbook](https://www.typescriptlang.org/docs/)
- [RxJS Guide](https://rxjs.dev/guide/overview)
- [REST API Best Practices](https://restfulapi.net/)

Ver también:
- `SETUP.md` - Guía paso a paso de instalación
- `EJEMPLOS_CODIGO.md` - Ejemplos de código

## 🤝 Contribución

Los cambios deben:
1. Mantener la estructura de carpetas
2. Seguir las convenciones de Angular
3. Incluir pruebas
4. Documentar cambios significativos

## 📞 Soporte

- **Frontend Issues**: Ver `frontend/README.md`
- **Backend Issues**: Ver `backend/README.md`
- **Documentación**: Ver archivos `.md` en raíz

## 📄 Licencia

Privado - Todos los derechos reservados

## 📝 Notas Importantes

- Esta es una **migración progresiva** del PHP al Angular
- El backend PHP se mantiene por ahora
- Se refactorizará a API REST en fases
- Los datos históricos se preservarán
- No hay downtime en la transición

## 🗓️ Roadmap

### Fase 1 (Actual)
- [x] Estructura de proyecto Angular
- [ ] Componentes principales
- [ ] Servicios HTTP
- [ ] Autenticación básica

### Fase 2
- [ ] Integración con backend
- [ ] Dashboard funcional
- [ ] Gestión de pagos

### Fase 3
- [ ] Testing completo
- [ ] Optimización de rendimiento
- [ ] Documentación

### Fase 4
- [ ] Deployment
- [ ] Monitoreo
- [ ] Mantenimiento

---

**Última actualización**: 2026-05-17  
**Versión**: 1.0.0  
**Estado**: En desarrollo 🚀
