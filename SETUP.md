# 🚀 Apoderados App - Angular + PHP

Transformación de la aplicación PHP de apoderados a una arquitectura moderna con Angular frontend y PHP backend.

## 📋 Estructura del Proyecto

```
apoderados/
├── backend/                 # PHP existente (refactorizar en fases)
│   ├── api/                # Endpoints REST (nuevos)
│   ├── config/
│   ├── controllers/
│   ├── models/
│   └── *.php              # Archivos PHP existentes
├── frontend/              # Nueva aplicación Angular
│   ├── src/
│   │   ├── app/
│   │   │   ├── components/
│   │   │   ├── services/
│   │   │   ├── app.component.ts
│   │   │   └── app.routes.ts
│   │   ├── assets/
│   │   ├── styles/
│   │   └── main.ts
│   ├── angular.json
│   ├── package.json
│   └── tsconfig.json
├── docs/                  # Documentación
├── SETUP.md              # Este archivo
└── README.md
```

## 🛠️ Instalación Rápida

### Paso 1: Verificar requisitos
```bash
node -v  # Debe ser v18+
npm -v   # Debe ser v9+
```

### Paso 2: Instalar Angular CLI
```bash
npm install -g @angular/cli@latest
```

### Paso 3: Crear proyecto Angular
```bash
cd c:\Users\kurtg\Downloads\apoderados
ng new frontend --routing --style=scss --skip-git --package-manager=npm
```

### Paso 4: Instalar dependencias
```bash
cd frontend
npm install
```

### Paso 5: Generar componentes principales
```bash
# Componentes de UI
ng generate component components/layout/header --skip-tests
ng generate component components/layout/sidebar --skip-tests
ng generate component components/layout/layout --skip-tests

# Componentes de funcionalidad
ng generate component components/auth/login --skip-tests
ng generate component components/apoderado/panel --skip-tests
ng generate component components/pagos/lista --skip-tests
ng generate component components/pagos/detalle --skip-tests
ng generate component components/transferencias/lista --skip-tests
ng generate component components/uniformes/lista --skip-tests
ng generate component components/uniformes/comprar --skip-tests

# Servicios
ng generate service services/auth --skip-tests
ng generate service services/api --skip-tests
ng generate service services/payment --skip-tests
ng generate service services/transfer --skip-tests
ng generate service services/uniform --skip-tests
```

### Paso 6: Iniciar servidor de desarrollo
```bash
npm start
```

La aplicación estará disponible en: **http://localhost:4200**

## 📁 Funcionalidades por Componente

| Componente | Archivo PHP | Descripción |
|-----------|-----------|-----------|
| Login | login.php | Autenticación de apoderados |
| Panel | panel_apoderado.php | Dashboard principal |
| Pagos | enviar_pago_cobranzas.php, verificar_pago_admin.php | Gestión de pagos |
| Transferencias | autorizar_transferencia.php, procesar_transferencia.php | Transferencias entre cuentas |
| Uniformes | uniformes.php, procesar_uniforme.php | Compra de uniformes |
| Fichas | ficha_matricula.php, guardar_ficha_matricula.php | Fichas de matrícula |

## 🔌 Integración Backend

### Endpoints REST requeridos
```typescript
// Autenticación
POST   /api/auth/login
POST   /api/auth/logout
POST   /api/auth/refresh

// Apoderado
GET    /api/apoderado/profile
GET    /api/apoderado/dashboard

// Pagos
GET    /api/pagos
POST   /api/pagos/enviar
GET    /api/pagos/:id

// Transferencias
GET    /api/transferencias
POST   /api/transferencias
PUT    /api/transferencias/:id/autorizar

// Uniformes
GET    /api/uniformes
POST   /api/uniformes/comprar

// Fichas
GET    /api/fichas
POST   /api/fichas
PUT    /api/fichas/:id
```

## 🔐 Autenticación

La app usará **JWT (JSON Web Tokens)** para autenticación segura:

1. Usuario inicia sesión (login)
2. Backend PHP genera JWT
3. Frontend Angular almacena token
4. Todas las requests incluyen token en header Authorization

## 📦 Dependencias Principales

```json
{
  "@angular/core": "^17.0.0",
  "@angular/router": "^17.0.0",
  "@angular/forms": "^17.0.0",
  "@angular/common/http": "^17.0.0",
  "rxjs": "^7.8.0"
}
```

## 🚀 Próximos Pasos

- [ ] Crear estructura de servicios HTTP
- [ ] Configurar interceptores para autenticación
- [ ] Crear layouts y componentes principales
- [ ] Refactorizar PHP a arquitectura REST API
- [ ] Conectar frontend con backend
- [ ] Implementar manejo de errores
- [ ] Agregar validaciones en formularios
- [ ] Testing e2e

## 📚 Recursos Útiles

- [Angular Documentation](https://angular.io/docs)
- [Angular CLI](https://angular.io/cli)
- [RxJS](https://rxjs.dev/)
- [TypeScript](https://www.typescriptlang.org/)

## 👤 Autor

Generado automáticamente por Copilot

## 📝 Notas

- La aplicación usa **standalone components** (moderno Angular 14+)
- Estilo: **SCSS** para mejor organización de CSS
- Routing configurado para navegación SPA
- HTTP Client configurado para comunicación REST

---

**¿Necesitas ayuda?** Ejecuta los comandos del **Paso 3-6** en orden y avísame si hay errores.
