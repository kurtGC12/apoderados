# Ejemplos de Código - Aplicación Angular

Estos son ejemplos de código que puedes usar en tu aplicación Angular después de generarla.

## 1. Servicio de Autenticación

**Ubicación**: `frontend/src/app/services/auth.service.ts`

```typescript
import { Injectable } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { Observable, BehaviorSubject } from 'rxjs';
import { tap } from 'rxjs/operators';

export interface LoginRequest {
  email: string;
  password: string;
}

export interface LoginResponse {
  token: string;
  user: {
    id: string;
    name: string;
    email: string;
  };
}

@Injectable({ providedIn: 'root' })
export class AuthService {
  private apiUrl = 'http://localhost:8000/api/auth';
  private tokenKey = 'auth_token';
  private currentUserSubject = new BehaviorSubject<any>(null);
  public currentUser$ = this.currentUserSubject.asObservable();

  constructor(private http: HttpClient) {
    this.loadUser();
  }

  login(credentials: LoginRequest): Observable<LoginResponse> {
    return this.http.post<LoginResponse>(`${this.apiUrl}/login`, credentials).pipe(
      tap(response => {
        localStorage.setItem(this.tokenKey, response.token);
        this.currentUserSubject.next(response.user);
      })
    );
  }

  logout(): void {
    localStorage.removeItem(this.tokenKey);
    this.currentUserSubject.next(null);
  }

  getToken(): string | null {
    return localStorage.getItem(this.tokenKey);
  }

  isAuthenticated(): boolean {
    return !!this.getToken();
  }

  private loadUser(): void {
    const token = this.getToken();
    if (token) {
      // Cargar datos del usuario
    }
  }
}
```

## 2. Interceptor HTTP para Autenticación

**Ubicación**: `frontend/src/app/interceptors/auth.interceptor.ts`

```typescript
import { Injectable } from '@angular/core';
import {
  HttpRequest,
  HttpHandler,
  HttpEvent,
  HttpInterceptor,
  HttpErrorResponse
} from '@angular/common/http';
import { Observable, throwError } from 'rxjs';
import { catchError } from 'rxjs/operators';
import { AuthService } from '../services/auth.service';

@Injectable()
export class AuthInterceptor implements HttpInterceptor {
  constructor(private authService: AuthService) {}

  intercept(request: HttpRequest<unknown>, next: HttpHandler): Observable<HttpEvent<unknown>> {
    const token = this.authService.getToken();
    if (token) {
      request = request.clone({
        setHeaders: {
          Authorization: `Bearer ${token}`
        }
      });
    }

    return next.handle(request).pipe(
      catchError((error: HttpErrorResponse) => {
        if (error.status === 401) {
          this.authService.logout();
          // Redirigir a login
        }
        return throwError(error);
      })
    );
  }
}
```

## 3. Componente de Login

**Ubicación**: `frontend/src/app/components/auth/login/login.component.ts`

```typescript
import { Component } from '@angular/core';
import { FormBuilder, FormGroup, Validators } from '@angular/forms';
import { Router } from '@angular/router';
import { AuthService } from '../../../services/auth.service';

@Component({
  selector: 'app-login',
  templateUrl: './login.component.html',
  styleUrls: ['./login.component.scss']
})
export class LoginComponent {
  loginForm: FormGroup;
  loading = false;
  submitted = false;
  error = '';

  constructor(
    private formBuilder: FormBuilder,
    private authService: AuthService,
    private router: Router
  ) {
    this.loginForm = this.formBuilder.group({
      email: ['', [Validators.required, Validators.email]],
      password: ['', [Validators.required, Validators.minLength(6)]]
    });
  }

  get f() {
    return this.loginForm.controls;
  }

  onSubmit() {
    this.submitted = true;

    if (this.loginForm.invalid) {
      return;
    }

    this.loading = true;
    this.authService.login(this.loginForm.value).subscribe({
      next: () => {
        this.router.navigate(['/panel']);
      },
      error: (error) => {
        this.error = error.error?.message || 'Error en la autenticación';
        this.loading = false;
      }
    });
  }
}
```

**Template**: `frontend/src/app/components/auth/login/login.component.html`

```html
<div class="login-container">
  <div class="login-card">
    <h1>Iniciar Sesión</h1>
    
    <form [formGroup]="loginForm" (ngSubmit)="onSubmit()">
      <div class="form-group">
        <label for="email">Email</label>
        <input 
          type="email" 
          id="email" 
          formControlName="email"
          class="form-control"
          [class.is-invalid]="submitted && f['email'].errors"
        >
        <div *ngIf="submitted && f['email'].errors" class="error-message">
          <span *ngIf="f['email'].errors['required']">Email es requerido</span>
          <span *ngIf="f['email'].errors['email']">Email inválido</span>
        </div>
      </div>

      <div class="form-group">
        <label for="password">Contraseña</label>
        <input 
          type="password" 
          id="password" 
          formControlName="password"
          class="form-control"
          [class.is-invalid]="submitted && f['password'].errors"
        >
        <div *ngIf="submitted && f['password'].errors" class="error-message">
          <span *ngIf="f['password'].errors['required']">Contraseña es requerida</span>
          <span *ngIf="f['password'].errors['minlength']">Mínimo 6 caracteres</span>
        </div>
      </div>

      <div *ngIf="error" class="alert alert-danger">
        {{ error }}
      </div>

      <button type="submit" class="btn btn-primary" [disabled]="loading">
        <span *ngIf="loading" class="spinner"></span>
        {{ loading ? 'Iniciando sesión...' : 'Iniciar Sesión' }}
      </button>
    </form>
  </div>
</div>
```

## 4. Servicio de Pagos

**Ubicación**: `frontend/src/app/services/payment.service.ts`

```typescript
import { Injectable } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { Observable } from 'rxjs';

export interface Payment {
  id: string;
  amount: number;
  description: string;
  status: 'pending' | 'completed' | 'failed';
  date: Date;
}

export interface SendPaymentRequest {
  amount: number;
  description: string;
  reference: string;
}

@Injectable({ providedIn: 'root' })
export class PaymentService {
  private apiUrl = 'http://localhost:8000/api/pagos';

  constructor(private http: HttpClient) {}

  getPayments(): Observable<Payment[]> {
    return this.http.get<Payment[]>(this.apiUrl);
  }

  sendPayment(payment: SendPaymentRequest): Observable<Payment> {
    return this.http.post<Payment>(`${this.apiUrl}/enviar`, payment);
  }

  getPaymentDetail(id: string): Observable<Payment> {
    return this.http.get<Payment>(`${this.apiUrl}/${id}`);
  }
}
```

## 5. Rutas de la Aplicación

**Ubicación**: `frontend/src/app/app.routes.ts`

```typescript
import { Routes } from '@angular/router';
import { LoginComponent } from './components/auth/login/login.component';
import { PanelComponent } from './components/apoderado/panel/panel.component';
import { PagosComponent } from './components/pagos/lista/lista.component';
import { TransferenciasComponent } from './components/transferencias/lista/lista.component';
import { UniformesComponent } from './components/uniformes/lista/lista.component';

export const routes: Routes = [
  { path: '', redirectTo: '/login', pathMatch: 'full' },
  { path: 'login', component: LoginComponent },
  { path: 'panel', component: PanelComponent },
  { path: 'pagos', component: PagosComponent },
  { path: 'transferencias', component: TransferenciasComponent },
  { path: 'uniformes', component: UniformesComponent },
  { path: '**', redirectTo: '/login' }
];
```

## 6. Configuración Principal

**Ubicación**: `frontend/src/app/app.config.ts`

```typescript
import { ApplicationConfig, importProvidersFrom } from '@angular/core';
import { provideRouter } from '@angular/router';
import { provideHttpClient, withInterceptors, HTTP_INTERCEPTORS } from '@angular/common/http';
import { provideAnimations } from '@angular/platform-browser/animations';
import { routes } from './app.routes';
import { AuthInterceptor } from './interceptors/auth.interceptor';

export const appConfig: ApplicationConfig = {
  providers: [
    provideRouter(routes),
    provideHttpClient(),
    provideAnimations(),
    { provide: HTTP_INTERCEPTORS, useClass: AuthInterceptor, multi: true }
  ]
};
```

## 7. Componente Principal

**Ubicación**: `frontend/src/app/app.component.ts`

```typescript
import { Component } from '@angular/core';
import { RouterOutlet } from '@angular/router';

@Component({
  selector: 'app-root',
  standalone: true,
  imports: [RouterOutlet],
  template: '<router-outlet></router-outlet>',
  styleUrls: ['./app.component.scss']
})
export class AppComponent {
  title = 'Apoderados App';
}
```

---

Estos son ejemplos base. Después de generar el proyecto con `ng new frontend`, puedes copiar estos ejemplos en los archivos correspondientes.

**Siguiente paso**: Ejecuta los comandos en `SETUP.md` para crear el proyecto completo.
