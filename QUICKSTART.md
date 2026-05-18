# 🚀 Inicio Rápido - Aplicación Apoderados Angular

## ⚡ En 5 minutos

### 1️⃣ Windows - Ejecutar script

```bash
# Abre PowerShell como Administrador en esta carpeta y ejecuta:
.\setup.bat
```

O manualmente:

```bash
npm install -g @angular/cli@latest
cd apoderados
ng new frontend --routing --style=scss --skip-git --package-manager=npm
cd frontend
npm install
npm start
```

### 2️⃣ Mac/Linux - Ejecutar script

```bash
chmod +x ./install.sh
./install.sh
```

O manualmente:

```bash
npm install -g @angular/cli@latest
cd apoderados
ng new frontend --routing --style=scss --skip-git --package-manager=npm
cd frontend
npm install
npm start
```

### 3️⃣ Acceder a la app

Abre tu navegador en: **http://localhost:4200**

## 📁 Archivos Principales

| Archivo | Descripción |
|---------|-----------|
| `SETUP.md` | 📋 Guía detallada paso a paso |
| `EJEMPLOS_CODIGO.md` | 💻 Ejemplos listos para copiar |
| `README_ANGULAR.md` | 📖 Documentación completa |
| `setup.bat` | 🪟 Script automático (Windows) |
| `install.sh` | 🐧 Script automático (Mac/Linux) |

## ✅ Checklist

- [ ] Node.js instalado (v18+)
- [ ] Angular CLI instalado
- [ ] Ejecuté `ng new frontend ...`
- [ ] Ejecuté `npm install` en carpeta frontend
- [ ] El servidor está corriendo (`npm start`)
- [ ] Puedo acceder a http://localhost:4200

## 🎯 Próximos Pasos

Después de que el servidor esté corriendo:

1. **Abre DevTools** (F12)
2. **Inspecciona** la estructura del proyecto en `frontend/src/app`
3. **Lee** `EJEMPLOS_CODIGO.md` para crear componentes
4. **Conecta** con el backend PHP

## 🔧 Comandos Útiles

```bash
# Generar componente
ng generate component components/mi-componente

# Generar servicio
ng generate service services/mi-servicio

# Build de producción
ng build --configuration production

# Ejecutar tests
ng test

# Lint del código
ng lint
```

## ⚠️ Problemas Comunes

### "ng command not found"
```bash
npm install -g @angular/cli@latest
```

### Puerto 4200 en uso
```bash
ng serve --port 4300
```

### Error de permisos (Windows)
Ejecuta PowerShell como Administrador

### Dependencias no se instalan
```bash
rm -rf node_modules package-lock.json
npm install
```

## 📞 Ayuda

**Si hay errores**, revisa:
1. Node.js version: `node -v` (debe ser v18+)
2. npm version: `npm -v` (debe ser v9+)
3. Angular CLI: `ng version`
4. Errores en consola (Ctrl+Shift+K en VS Code)

## 📚 Documentación

- [Angular Docs](https://angular.io)
- [TypeScript](https://www.typescriptlang.org/docs)
- [npm Scripts](https://docs.npmjs.com/cli/run-script)

---

**¿Listo?** ⏱️ Ejecuta `setup.bat` o `install.sh` ahora mismo!
