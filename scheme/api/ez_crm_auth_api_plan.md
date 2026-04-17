# ez_crm 外部註冊 / 認證 API 落地方案

> **專案**：ez_crm (Laravel 10 Member Data Center)
> **分支建議**：`feature/member-auth-api`
> **預估工期**：8–10 天（單人）
> **目標**：完整實作前台會員註冊、登入、OAuth、個人資料管理與 SNS 綁定 API

---

## 📐 設計決策（已確認）

| 決策項 | 選擇 | 影響 |
|---|---|---|
| Email 驗證 | **OTP 6 碼（5 分鐘有效）** | 前端用輸入框，不用點 email 連結 |
| OAuth 新用戶 | **自動建立帳號** | Callback 時直接 create member + member_sns |
| OAuth email 衝突 | **自動綁定並登入** | 已存在的 email 直接把新 SNS 掛上去 |

### 核心認證架構

```
┌─────────────────────────────────────────────────┐
│  Guards                                         │
│    web      → users (Filament 後台)             │
│    sanctum  → users (後台 API，現有)            │
│    member   → members (前台 API，新增) ★        │
└─────────────────────────────────────────────────┘
```

---

## 🗂️ API 總覽

| # | Phase | Method | Endpoint | Auth | 狀態 |
|---|---|---|---|---|---|
| 1 | P1 | GET | `/api/v1/auth/register/schema` | ❌ | ⬜ |
| 2 | P2 | POST | `/api/v1/auth/register` | ❌ | ⬜ |
| 3 | P2 | POST | `/api/v1/auth/verify/email/send` | ❌ | ⬜ |
| 4 | P2 | POST | `/api/v1/auth/verify/email` | ❌ | ⬜ |
| 5 | P3 | POST | `/api/v1/auth/login` | ❌ | ⬜ |
| 6 | P3 | POST | `/api/v1/auth/password/forgot` | ❌ | ⬜ |
| 7 | P3 | POST | `/api/v1/auth/password/reset` | ❌ | ⬜ |
| 8 | P4 | GET | `/api/v1/auth/oauth/{provider}/redirect` | ❌ | ⬜ |
| 9 | P4 | GET | `/api/v1/auth/oauth/{provider}/callback` | ❌ | ⬜ |
| 10 | P4 | POST | `/api/v1/auth/oauth/{provider}/callback` | ❌ | ⬜ |
| 11 | P5 | GET | `/api/v1/me` | member | ⬜ |
| 12 | P5 | PUT | `/api/v1/me` | member | ⬜ |
| 13 | P5 | PUT | `/api/v1/me/profile` | member | ⬜ |
| 14 | P5 | PUT | `/api/v1/me/password` | member | ⬜ |
| 15 | P5 | POST | `/api/v1/me/email/change/request` | member | ⬜ |
| 16 | P5 | POST | `/api/v1/me/email/change/verify` | member | ⬜ |
| 17 | P5 | POST | `/api/v1/me/avatar` | member | ⬜ |
| 18 | P5 | POST | `/api/v1/me/logout` | member | ⬜ |
| 19 | P5 | POST | `/api/v1/me/logout-all` | member | ⬜ |
| 20 | P5 | DELETE | `/api/v1/me` | member | ⬜ |
| 21 | P6 | GET | `/api/v1/me/sns` | member | ⬜ |
| 22 | P6 | GET | `/api/v1/me/sns/{provider}/bind-url` | member | ⬜ |
| 23 | P6 | POST | `/api/v1/me/sns/{provider}/bind` | member | ⬜ |
| 24 | P6 | DELETE | `/api/v1/me/sns/{provider}` | member | ⬜ |

---

# Phase 0：前置準備

## 目標
建立 member guard、調整 Model、安裝必要套件。

## 0.1 安裝 Socialite

```bash
composer require laravel/socialite
```

## 0.2 建立 MemberVerification Model

```bash
php artisan make:model MemberVerification
```

`app/Models/MemberVerification.php`：

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MemberVerification extends Model
{
    use HasFactory;

    const TYPE_EMAIL          = 'email';
    const TYPE_PHONE          = 'phone';
    const TYPE_PASSWORD_RESET = 'password_reset';
    const TYPE_EMAIL_CHANGE   = 'email_change';

    public $timestamps = false;

    protected $fillable = [
        'member_id', 'type', 'token',
        'expires_at', 'verified_at', 'created_at',
    ];

    protected $casts = [
        'expires_at'  => 'datetime',
        'verified_at' => 'datetime',
        'created_at'  => 'datetime',
    ];

    public function member()
    {
        return $this->belongsTo(Member::class);
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public function isVerified(): bool
    {
        return ! is_null($this->verified_at);
    }
}
```

## 0.3 改造 Member Model

`app/Models/Member.php` 調整重點：
- 加 `HasApiTokens`（Sanctum 發 token）
- 加 `Notifiable`（發驗證信）
- 密碼 cast 成 `hashed`
- 加 `verifications()` / `addresses()` / `loginHistories()` / `devices()` 關聯

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class Member extends Authenticatable   // ← 改繼承
{
    use HasApiTokens, HasFactory, Notifiable, SoftDeletes;

    const STATUS_ACTIVE    = 1;
    const STATUS_INACTIVE  = 0;
    const STATUS_SUSPENDED = 2;

    protected $fillable = [
        'uuid', 'member_group_id', 'name', 'nickname',
        'email', 'phone', 'password',
        'email_verified_at', 'phone_verified_at',
        'status', 'last_login_at',
    ];

    protected $hidden = ['password', 'remember_token'];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'phone_verified_at' => 'datetime',
        'last_login_at'     => 'datetime',
        'password'          => 'hashed',
    ];

    // Relations
    public function group()          { return $this->belongsTo(MemberGroup::class, 'member_group_id'); }
    public function profile()        { return $this->hasOne(MemberProfile::class); }
    public function sns()            { return $this->hasMany(MemberSns::class); }
    public function tags()           { return $this->belongsToMany(Tag::class, 'member_tag')->withPivot('created_at'); }
    public function verifications()  { return $this->hasMany(MemberVerification::class); }
    public function addresses()      { return $this->hasMany(MemberAddress::class); }
    public function loginHistories() { return $this->hasMany(MemberLoginHistory::class); }
    public function devices()        { return $this->hasMany(MemberDevice::class); }

    // Helpers
    public function hasVerifiedEmail(): bool
    {
        return ! is_null($this->email_verified_at);
    }

    public function markEmailAsVerified(): bool
    {
        return $this->forceFill(['email_verified_at' => now()])->save();
    }
}
```

> ⚠️ 其餘缺的 Model（`MemberAddress`、`MemberLoginHistory`、`MemberDevice`）用 `php artisan make:model` 建一下，`$fillable` 對應 migration 欄位即可。

## 0.4 設定 member guard

`config/auth.php`：

```php
'guards' => [
    'web' => [
        'driver' => 'session',
        'provider' => 'users',
    ],
    'sanctum' => [
        'driver' => 'sanctum',
        'provider' => 'users',
    ],
    'member' => [                        // ★ 新增
        'driver' => 'sanctum',
        'provider' => 'members',
    ],
],

'providers' => [
    'users' => [
        'driver' => 'eloquent',
        'model' => App\Models\User::class,
    ],
    'members' => [                       // ★ 新增
        'driver' => 'eloquent',
        'model' => App\Models\Member::class,
    ],
],

'passwords' => [
    // 保留原本的 users
    'members' => [                       // ★ 新增（備用，本專案走 OTP 不一定用得到）
        'provider' => 'members',
        'table' => 'password_reset_tokens',
        'expire' => 60,
        'throttle' => 60,
    ],
],
```

## 0.5 建立目錄結構

```bash
mkdir -p app/Http/Controllers/Api/V1/Auth
mkdir -p app/Http/Controllers/Api/V1/Me
mkdir -p app/Http/Requests/Api/V1/Auth
mkdir -p app/Http/Requests/Api/V1/Me
mkdir -p app/Http/Resources/Api/V1
mkdir -p app/Services/Auth
mkdir -p app/Services/OAuth
mkdir -p app/Notifications/Member
mkdir -p app/Enums
```

## 0.6 統一 API 回應格式

建立 `app/Http/Responses/ApiResponse.php`：

```php
<?php

namespace App\Http\Responses;

use Illuminate\Http\JsonResponse;

class ApiResponse
{
    public static function success($data = null, string $message = 'OK', int $status = 200): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => $message,
            'data'    => $data,
        ], $status);
    }

    public static function error(string $message, string $code = 'ERROR', int $status = 400, $errors = null): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $message,
            'code'    => $code,
            'errors'  => $errors,
        ], $status);
    }
}
```

## 0.7 驗收

- [ ] `composer show laravel/socialite` 看得到版本
- [ ] `php artisan tinker` → `App\Models\Member::first()->createToken('test')` 能成功發 token
- [ ] `config('auth.guards.member')` 有東西
- [ ] 目錄建好

---

# Phase 1：Register Schema API

## 目標
提供前端動態渲染註冊表單的 schema。這支很簡單，先暖身。

## 1.1 Controller

`app/Http/Controllers/Api/V1/Auth/RegisterSchemaController.php`：

```php
<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;

class RegisterSchemaController extends Controller
{
    public function __invoke()
    {
        return ApiResponse::success([
            'fields' => [
                [
                    'name'     => 'name',
                    'label'    => '姓名',
                    'type'     => 'text',
                    'required' => true,
                    'rules'    => ['required', 'string', 'max:100'],
                    'placeholder' => '請輸入真實姓名',
                ],
                [
                    'name'     => 'email',
                    'label'    => '電子郵件',
                    'type'     => 'email',
                    'required' => true,
                    'rules'    => ['required', 'email', 'unique:members,email', 'max:255'],
                ],
                [
                    'name'     => 'password',
                    'label'    => '密碼',
                    'type'     => 'password',
                    'required' => true,
                    'rules'    => ['required', 'min:8', 'regex:/[A-Z]/', 'regex:/[0-9]/'],
                    'hint'     => '至少 8 字，需含大寫與數字',
                ],
                [
                    'name'     => 'password_confirmation',
                    'label'    => '確認密碼',
                    'type'     => 'password',
                    'required' => true,
                ],
                [
                    'name'     => 'phone',
                    'label'    => '手機',
                    'type'     => 'tel',
                    'required' => false,
                    'rules'    => ['nullable', 'string', 'max:20'],
                ],
                [
                    'name'     => 'agree_terms',
                    'label'    => '我同意服務條款與隱私政策',
                    'type'     => 'checkbox',
                    'required' => true,
                    'rules'    => ['accepted'],
                ],
            ],
            'links' => [
                'terms'   => config('app.url') . '/terms',
                'privacy' => config('app.url') . '/privacy',
            ],
            'oauth_providers' => ['google', 'github', 'line', 'discord'],
        ]);
    }
}
```

## 1.2 路由

`routes/api.php` 加：

```php
use App\Http\Controllers\Api\V1\Auth\RegisterSchemaController;

Route::prefix('v1/auth')->group(function () {
    Route::get('register/schema', RegisterSchemaController::class);
});
```

## 1.3 驗收

```bash
curl http://localhost/api/v1/auth/register/schema
```

應該回傳 `success: true` + fields 陣列。

- [ ] 欄位結構符合需求
- [ ] 可切換 `oauth_providers` 開關未來要做的 provider

---

# Phase 2：註冊 + Email OTP 驗證

## 目標
完整的 email + 密碼註冊，以及 OTP 驗證流程。

## 2.1 OTP Service

`app/Services/Auth/OtpService.php`：

```php
<?php

namespace App\Services\Auth;

use App\Models\Member;
use App\Models\MemberVerification;
use Illuminate\Support\Str;

class OtpService
{
    const OTP_LENGTH      = 6;
    const OTP_EXPIRE_MINS = 5;
    const RESEND_COOLDOWN = 60; // 秒

    /**
     * 產生並儲存 OTP。會失效同 type 的舊 OTP。
     */
    public function generate(Member $member, string $type): MemberVerification
    {
        // 作廢同 member + type 的未驗證 OTP
        MemberVerification::where('member_id', $member->id)
            ->where('type', $type)
            ->whereNull('verified_at')
            ->update(['expires_at' => now()->subSecond()]);

        return MemberVerification::create([
            'member_id'  => $member->id,
            'type'       => $type,
            'token'      => $this->generateCode(),
            'expires_at' => now()->addMinutes(self::OTP_EXPIRE_MINS),
            'created_at' => now(),
        ]);
    }

    /**
     * 驗證 OTP，成功回傳 verification 物件，失敗回傳 null。
     */
    public function verify(Member $member, string $type, string $code): ?MemberVerification
    {
        $verification = MemberVerification::where('member_id', $member->id)
            ->where('type', $type)
            ->where('token', $code)
            ->whereNull('verified_at')
            ->where('expires_at', '>', now())
            ->latest('id')
            ->first();

        if (! $verification) {
            return null;
        }

        $verification->update(['verified_at' => now()]);

        return $verification;
    }

    /**
     * 檢查是否在冷卻時間內（避免短時間狂發）
     */
    public function isThrottled(Member $member, string $type): bool
    {
        $last = MemberVerification::where('member_id', $member->id)
            ->where('type', $type)
            ->latest('id')
            ->first();

        if (! $last) {
            return false;
        }

        return $last->created_at->diffInSeconds(now()) < self::RESEND_COOLDOWN;
    }

    private function generateCode(): string
    {
        return str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    }
}
```

## 2.2 Notification：發送 OTP Email

```bash
php artisan make:notification Member/SendOtpNotification
```

`app/Notifications/Member/SendOtpNotification.php`：

```php
<?php

namespace App\Notifications\Member;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class SendOtpNotification extends Notification
{
    use Queueable;

    public function __construct(
        public string $code,
        public string $type, // email / password_reset / email_change
        public int $expireMinutes = 5,
    ) {}

    public function via($notifiable): array
    {
        return ['mail'];
    }

    public function toMail($notifiable): MailMessage
    {
        $subjectMap = [
            'email'          => '驗證您的 Email',
            'password_reset' => '密碼重設驗證碼',
            'email_change'   => '變更 Email 驗證碼',
        ];

        return (new MailMessage)
            ->subject($subjectMap[$this->type] ?? '驗證碼')
            ->greeting("Hi {$notifiable->name}")
            ->line("您的驗證碼是：")
            ->line("**{$this->code}**")
            ->line("此驗證碼於 {$this->expireMinutes} 分鐘後失效。")
            ->line('若非本人操作請忽略此信。');
    }
}
```

## 2.3 Register Request

`app/Http/Requests/Api/V1/Auth/RegisterRequest.php`：

```php
<?php

namespace App\Http\Requests\Api\V1\Auth;

use Illuminate\Foundation\Http\FormRequest;

class RegisterRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'name'     => ['required', 'string', 'max:100'],
            'email'    => ['required', 'email', 'unique:members,email', 'max:255'],
            'password' => ['required', 'confirmed', 'min:8', 'regex:/[A-Z]/', 'regex:/[0-9]/'],
            'phone'    => ['nullable', 'string', 'max:20'],
            'agree_terms' => ['accepted'],
        ];
    }

    public function messages(): array
    {
        return [
            'password.regex'  => '密碼須包含大寫字母與數字',
            'agree_terms.accepted' => '請同意服務條款',
        ];
    }
}
```

## 2.4 Register Controller

`app/Http/Controllers/Api/V1/Auth/RegisterController.php`：

```php
<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Auth\RegisterRequest;
use App\Http\Responses\ApiResponse;
use App\Models\Member;
use App\Models\MemberProfile;
use App\Models\MemberVerification;
use App\Notifications\Member\SendOtpNotification;
use App\Services\Auth\OtpService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class RegisterController extends Controller
{
    public function __construct(private OtpService $otpService) {}

    public function __invoke(RegisterRequest $request)
    {
        $member = DB::transaction(function () use ($request) {
            $member = Member::create([
                'uuid'     => (string) Str::uuid(),
                'name'     => $request->name,
                'email'    => $request->email,
                'phone'    => $request->phone,
                'password' => $request->password,   // model cast 自動 hash
                'status'   => Member::STATUS_ACTIVE,
            ]);

            // 建立空的 profile
            MemberProfile::create([
                'member_id' => $member->id,
                'language' => 'zh-TW',
                'timezone' => 'Asia/Taipei',
            ]);

            return $member;
        });

        // 發送驗證 OTP
        $verification = $this->otpService->generate($member, MemberVerification::TYPE_EMAIL);
        $member->notify(new SendOtpNotification(
            $verification->token,
            MemberVerification::TYPE_EMAIL,
            OtpService::OTP_EXPIRE_MINS,
        ));

        return ApiResponse::success([
            'member_uuid'       => $member->uuid,
            'email'             => $member->email,
            'otp_expires_in'    => OtpService::OTP_EXPIRE_MINS * 60,
        ], '註冊成功，請至 Email 收取驗證碼', 201);
    }
}
```

## 2.5 Email 驗證 Controllers

### 重發 OTP：`SendEmailOtpController`

```php
<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\Member;
use App\Models\MemberVerification;
use App\Notifications\Member\SendOtpNotification;
use App\Services\Auth\OtpService;
use Illuminate\Http\Request;

class SendEmailOtpController extends Controller
{
    public function __construct(private OtpService $otpService) {}

    public function __invoke(Request $request)
    {
        $request->validate(['email' => ['required', 'email']]);

        $member = Member::where('email', $request->email)->first();

        if (! $member) {
            // 安全考量：不透露 email 是否存在
            return ApiResponse::success(null, '若 Email 存在，我們已重新寄送驗證碼');
        }

        if ($member->hasVerifiedEmail()) {
            return ApiResponse::error('此 Email 已驗證過', 'ALREADY_VERIFIED', 409);
        }

        if ($this->otpService->isThrottled($member, MemberVerification::TYPE_EMAIL)) {
            return ApiResponse::error('請稍候再試', 'THROTTLED', 429);
        }

        $verification = $this->otpService->generate($member, MemberVerification::TYPE_EMAIL);
        $member->notify(new SendOtpNotification(
            $verification->token,
            MemberVerification::TYPE_EMAIL,
        ));

        return ApiResponse::success([
            'otp_expires_in' => OtpService::OTP_EXPIRE_MINS * 60,
        ], '驗證碼已寄出');
    }
}
```

### 驗證 OTP：`VerifyEmailController`

```php
<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\Member;
use App\Models\MemberVerification;
use App\Services\Auth\OtpService;
use Illuminate\Http\Request;

class VerifyEmailController extends Controller
{
    public function __construct(private OtpService $otpService) {}

    public function __invoke(Request $request)
    {
        $request->validate([
            'email' => ['required', 'email'],
            'code'  => ['required', 'string', 'size:6'],
        ]);

        $member = Member::where('email', $request->email)->first();

        if (! $member) {
            return ApiResponse::error('驗證失敗', 'INVALID_CODE', 422);
        }

        $verification = $this->otpService->verify(
            $member,
            MemberVerification::TYPE_EMAIL,
            $request->code,
        );

        if (! $verification) {
            return ApiResponse::error('驗證碼錯誤或已過期', 'INVALID_CODE', 422);
        }

        $member->markEmailAsVerified();

        // 驗證成功後直接發 token 自動登入
        $token = $member->createToken('member-web')->plainTextToken;

        return ApiResponse::success([
            'token'  => $token,
            'member' => [
                'uuid'  => $member->uuid,
                'name'  => $member->name,
                'email' => $member->email,
            ],
        ], 'Email 驗證成功');
    }
}
```

## 2.6 路由

```php
Route::prefix('v1/auth')->group(function () {
    Route::get('register/schema', RegisterSchemaController::class);
    Route::post('register', RegisterController::class);
    Route::post('verify/email/send', SendEmailOtpController::class);
    Route::post('verify/email', VerifyEmailController::class);
});
```

## 2.7 驗收

- [ ] `.env` 設定好 `MAIL_MAILER=log` 或 Mailtrap
- [ ] 註冊 → 看 `storage/logs/laravel.log` 拿到 OTP
- [ ] 驗證成功後拿到 Sanctum token
- [ ] 重複驗證同一個 code 要失敗（已被標記 verified）
- [ ] 超過 5 分鐘的 code 要失敗
- [ ] email 已存在時註冊會被 validation 擋

---

# Phase 3：登入 + 密碼忘記/重設

## 3.1 LoginController

`app/Http/Controllers/Api/V1/Auth/LoginController.php`：

```php
<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\Member;
use App\Models\MemberLoginHistory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class LoginController extends Controller
{
    public function __invoke(Request $request)
    {
        $request->validate([
            'email'    => ['required', 'email'],
            'password' => ['required'],
            'device_name' => ['nullable', 'string', 'max:50'],
        ]);

        $member = Member::where('email', $request->email)->first();

        // 記錄登入歷史（即使失敗也記）
        $logLogin = function (?Member $m, bool $success, string $method = 'email') use ($request) {
            if ($m) {
                MemberLoginHistory::create([
                    'member_id'     => $m->id,
                    'ip_address'    => $request->ip(),
                    'user_agent'    => substr($request->userAgent() ?? '', 0, 512),
                    'platform'      => $request->input('platform', 'web'),
                    'login_method'  => $method,
                    'status'        => $success,
                ]);
            }
        };

        if (! $member || ! Hash::check($request->password, $member->password)) {
            $logLogin($member, false);
            throw ValidationException::withMessages([
                'email' => ['帳號或密碼錯誤'],
            ]);
        }

        if ($member->status !== Member::STATUS_ACTIVE) {
            return ApiResponse::error('帳號已停用', 'ACCOUNT_SUSPENDED', 403);
        }

        if (! $member->hasVerifiedEmail()) {
            return ApiResponse::error(
                'Email 尚未驗證',
                'EMAIL_NOT_VERIFIED',
                403,
                ['email' => $member->email]
            );
        }

        $token = $member->createToken($request->device_name ?? 'member-web')->plainTextToken;
        $member->update(['last_login_at' => now()]);
        $logLogin($member, true);

        return ApiResponse::success([
            'token'  => $token,
            'member' => [
                'uuid'  => $member->uuid,
                'name'  => $member->name,
                'email' => $member->email,
            ],
        ], '登入成功');
    }
}
```

## 3.2 密碼忘記/重設

### ForgotPasswordController

```php
<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\Member;
use App\Models\MemberVerification;
use App\Notifications\Member\SendOtpNotification;
use App\Services\Auth\OtpService;
use Illuminate\Http\Request;

class ForgotPasswordController extends Controller
{
    public function __construct(private OtpService $otpService) {}

    public function __invoke(Request $request)
    {
        $request->validate(['email' => ['required', 'email']]);

        $member = Member::where('email', $request->email)->first();

        if ($member && ! $this->otpService->isThrottled($member, MemberVerification::TYPE_PASSWORD_RESET)) {
            $verification = $this->otpService->generate($member, MemberVerification::TYPE_PASSWORD_RESET);
            $member->notify(new SendOtpNotification(
                $verification->token,
                MemberVerification::TYPE_PASSWORD_RESET,
            ));
        }

        // 安全考量：無論 email 是否存在都回傳成功訊息
        return ApiResponse::success(null, '若 Email 存在，我們已寄送重設驗證碼');
    }
}
```

### ResetPasswordController

```php
<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\Member;
use App\Models\MemberVerification;
use App\Services\Auth\OtpService;
use Illuminate\Http\Request;

class ResetPasswordController extends Controller
{
    public function __construct(private OtpService $otpService) {}

    public function __invoke(Request $request)
    {
        $request->validate([
            'email'    => ['required', 'email'],
            'code'     => ['required', 'string', 'size:6'],
            'password' => ['required', 'confirmed', 'min:8', 'regex:/[A-Z]/', 'regex:/[0-9]/'],
        ]);

        $member = Member::where('email', $request->email)->first();

        if (! $member) {
            return ApiResponse::error('驗證失敗', 'INVALID_CODE', 422);
        }

        $verification = $this->otpService->verify(
            $member,
            MemberVerification::TYPE_PASSWORD_RESET,
            $request->code,
        );

        if (! $verification) {
            return ApiResponse::error('驗證碼錯誤或已過期', 'INVALID_CODE', 422);
        }

        $member->update(['password' => $request->password]);

        // 重設密碼後撤銷所有舊 token，強制重新登入
        $member->tokens()->delete();

        return ApiResponse::success(null, '密碼已重設，請重新登入');
    }
}
```

## 3.3 路由

```php
Route::prefix('v1/auth')->group(function () {
    // ... Phase 2 的路由
    Route::post('login', LoginController::class);
    Route::post('password/forgot', ForgotPasswordController::class);
    Route::post('password/reset', ResetPasswordController::class);
});
```

## 3.4 驗收

- [ ] 正確密碼登入成功
- [ ] 錯誤密碼登入失敗，`member_login_histories` 有紀錄
- [ ] 未驗證 email 無法登入
- [ ] 忘記密碼 → 收信拿 OTP → 重設 → 舊 token 失效

---

# Phase 4：OAuth（Google + GitHub）

## 4.1 設定 services.php

`config/services.php` 新增：

```php
'google' => [
    'client_id'     => env('GOOGLE_CLIENT_ID'),
    'client_secret' => env('GOOGLE_CLIENT_SECRET'),
    'redirect'      => env('GOOGLE_REDIRECT_URI'),
],

'github' => [
    'client_id'     => env('GITHUB_CLIENT_ID'),
    'client_secret' => env('GITHUB_CLIENT_SECRET'),
    'redirect'      => env('GITHUB_REDIRECT_URI'),
],
```

`.env` 加：

```dotenv
GOOGLE_CLIENT_ID=
GOOGLE_CLIENT_SECRET=
GOOGLE_REDIRECT_URI=http://localhost/api/v1/auth/oauth/google/callback

GITHUB_CLIENT_ID=
GITHUB_CLIENT_SECRET=
GITHUB_REDIRECT_URI=http://localhost/api/v1/auth/oauth/github/callback
```

## 4.2 OAuth Service（核心邏輯）

`app/Services/OAuth/OAuthService.php`：

```php
<?php

namespace App\Services\OAuth;

use App\Models\Member;
use App\Models\MemberProfile;
use App\Models\MemberSns;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Socialite\Contracts\User as SocialiteUser;

class OAuthService
{
    const ALLOWED_PROVIDERS = ['google', 'github', 'line', 'discord'];

    /**
     * 處理 OAuth 登入/註冊的核心流程
     *
     * 情境：
     *   1. 此 SNS 已綁定會員 → 直接登入
     *   2. email 已存在但未綁定此 SNS → 自動綁定 + 登入
     *   3. 全新使用者 → 自動建立會員 + 綁定 + 登入
     *
     * @return array{member: Member, is_new: bool, bound: bool}
     */
    public function handleLogin(string $provider, SocialiteUser $socialUser): array
    {
        $this->assertProviderAllowed($provider);

        return DB::transaction(function () use ($provider, $socialUser) {

            // 情境 1：此 SNS 已綁定
            $sns = MemberSns::where('provider', $provider)
                ->where('provider_user_id', $socialUser->getId())
                ->first();

            if ($sns) {
                $this->refreshSnsTokens($sns, $socialUser);
                return [
                    'member' => $sns->member,
                    'is_new' => false,
                    'bound'  => false,
                ];
            }

            // 情境 2：email 已存在（自動綁定策略）
            if ($socialUser->getEmail()) {
                $existing = Member::where('email', $socialUser->getEmail())->first();
                if ($existing) {
                    $this->bindSns($existing, $provider, $socialUser);
                    return [
                        'member' => $existing,
                        'is_new' => false,
                        'bound'  => true,
                    ];
                }
            }

            // 情境 3：全新使用者 → 自動建立
            $member = $this->createMemberFromOAuth($provider, $socialUser);
            $this->bindSns($member, $provider, $socialUser);

            return [
                'member' => $member,
                'is_new' => true,
                'bound'  => true,
            ];
        });
    }

    private function createMemberFromOAuth(string $provider, SocialiteUser $socialUser): Member
    {
        // OAuth 可能沒有 email，用 placeholder
        $email = $socialUser->getEmail()
            ?? "{$provider}_{$socialUser->getId()}@oauth.local";

        $member = Member::create([
            'uuid'              => (string) Str::uuid(),
            'name'              => $socialUser->getName() ?? $socialUser->getNickname() ?? 'User',
            'nickname'          => $socialUser->getNickname(),
            'email'             => $email,
            'email_verified_at' => $socialUser->getEmail() ? now() : null, // OAuth 給的 email 視同已驗證
            'password'          => Str::random(60), // 隨機密碼，使用者要改需走忘記密碼流程
            'status'            => Member::STATUS_ACTIVE,
        ]);

        MemberProfile::create([
            'member_id' => $member->id,
            'avatar'    => $socialUser->getAvatar(),
            'language'  => 'zh-TW',
            'timezone'  => 'Asia/Taipei',
        ]);

        return $member;
    }

    private function bindSns(Member $member, string $provider, SocialiteUser $socialUser): MemberSns
    {
        return MemberSns::create([
            'member_id'        => $member->id,
            'provider'         => $provider,
            'provider_user_id' => $socialUser->getId(),
            'access_token'     => $socialUser->token ?? null,
            'refresh_token'    => $socialUser->refreshToken ?? null,
            'token_expires_at' => isset($socialUser->expiresIn)
                ? now()->addSeconds($socialUser->expiresIn)
                : null,
        ]);
    }

    private function refreshSnsTokens(MemberSns $sns, SocialiteUser $socialUser): void
    {
        $sns->update([
            'access_token'     => $socialUser->token ?? $sns->access_token,
            'refresh_token'    => $socialUser->refreshToken ?? $sns->refresh_token,
            'token_expires_at' => isset($socialUser->expiresIn)
                ? now()->addSeconds($socialUser->expiresIn)
                : $sns->token_expires_at,
        ]);
    }

    private function assertProviderAllowed(string $provider): void
    {
        if (! in_array($provider, self::ALLOWED_PROVIDERS, true)) {
            abort(422, "Provider {$provider} not supported");
        }
    }
}
```

## 4.3 OAuth Controller

`app/Http/Controllers/Api/V1/Auth/OAuthController.php`：

```php
<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\MemberLoginHistory;
use App\Services\OAuth\OAuthService;
use Illuminate\Http\Request;
use Laravel\Socialite\Facades\Socialite;

class OAuthController extends Controller
{
    public function __construct(private OAuthService $oauthService) {}

    /**
     * 取得授權 URL（給前端 SPA 呼叫）
     */
    public function redirect(string $provider)
    {
        $url = Socialite::driver($provider)
            ->stateless()
            ->redirect()
            ->getTargetUrl();

        return ApiResponse::success(['url' => $url]);
    }

    /**
     * 處理 callback
     */
    public function callback(string $provider, Request $request)
    {
        try {
            $socialUser = Socialite::driver($provider)->stateless()->user();
        } catch (\Throwable $e) {
            return ApiResponse::error('OAuth 授權失敗', 'OAUTH_FAILED', 400);
        }

        $result = $this->oauthService->handleLogin($provider, $socialUser);
        $member = $result['member'];

        // 登入歷史
        MemberLoginHistory::create([
            'member_id'    => $member->id,
            'ip_address'   => $request->ip(),
            'user_agent'   => substr($request->userAgent() ?? '', 0, 512),
            'platform'     => $request->input('platform', 'web'),
            'login_method' => $provider,
            'status'       => true,
        ]);

        $member->update(['last_login_at' => now()]);
        $token = $member->createToken("oauth-{$provider}")->plainTextToken;

        return ApiResponse::success([
            'token'  => $token,
            'member' => [
                'uuid'  => $member->uuid,
                'name'  => $member->name,
                'email' => $member->email,
            ],
            'is_new_account' => $result['is_new'],
            'newly_bound'    => $result['bound'],
        ], '登入成功');
    }
}
```

## 4.4 路由

```php
Route::prefix('v1/auth/oauth')->group(function () {
    Route::get('{provider}/redirect', [OAuthController::class, 'redirect']);
    Route::match(['get', 'post'], '{provider}/callback', [OAuthController::class, 'callback']);
});
```

## 4.5 驗收

- [ ] Google OAuth 測試流程通
- [ ] GitHub OAuth 測試流程通
- [ ] 同一個 Google 帳號第二次登入 → 不會建立新 member
- [ ] 用 email 註冊後再用該 email 的 Google 登入 → 自動綁定
- [ ] `member_sns` 表正確寫入

---

# Phase 5：/me 個人資料管理

## 5.1 路由群組

```php
Route::prefix('v1/me')->middleware('auth:member')->group(function () {
    Route::get('/', [MeController::class, 'show']);
    Route::put('/', [MeController::class, 'update']);
    Route::put('profile', [MeController::class, 'updateProfile']);
    Route::put('password', [MeController::class, 'updatePassword']);
    Route::post('email/change/request', [MeController::class, 'requestEmailChange']);
    Route::post('email/change/verify', [MeController::class, 'verifyEmailChange']);
    Route::post('avatar', [MeController::class, 'uploadAvatar']);
    Route::post('logout', [MeController::class, 'logout']);
    Route::post('logout-all', [MeController::class, 'logoutAll']);
    Route::delete('/', [MeController::class, 'destroy']);
});
```

## 5.2 MeController 骨架

`app/Http/Controllers/Api/V1/Me/MeController.php`：

```php
<?php

namespace App\Http\Controllers\Api\V1\Me;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\MemberResource;
use App\Http\Responses\ApiResponse;
use App\Models\MemberVerification;
use App\Notifications\Member\SendOtpNotification;
use App\Services\Auth\OtpService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class MeController extends Controller
{
    public function __construct(private OtpService $otpService) {}

    public function show(Request $request)
    {
        $member = $request->user()->load(['profile', 'sns', 'addresses', 'group']);
        return ApiResponse::success(new MemberResource($member));
    }

    public function update(Request $request)
    {
        $member = $request->user();

        $data = $request->validate([
            'name'     => ['sometimes', 'string', 'max:100'],
            'nickname' => ['sometimes', 'nullable', 'string', 'max:100'],
            'phone'    => ['sometimes', 'nullable', 'string', 'max:20'],
        ]);

        $member->update($data);
        return ApiResponse::success(new MemberResource($member->fresh()), '已更新');
    }

    public function updateProfile(Request $request)
    {
        $data = $request->validate([
            'gender'   => ['sometimes', 'nullable', 'integer', 'in:0,1,2'],
            'birthday' => ['sometimes', 'nullable', 'date'],
            'bio'      => ['sometimes', 'nullable', 'string', 'max:1000'],
            'language' => ['sometimes', 'nullable', 'string', 'max:10'],
            'timezone' => ['sometimes', 'nullable', 'string', 'max:50'],
        ]);

        $member = $request->user();
        $member->profile()->updateOrCreate(['member_id' => $member->id], $data);

        return ApiResponse::success(new MemberResource($member->load('profile')->fresh()), 'Profile 已更新');
    }

    public function updatePassword(Request $request)
    {
        $request->validate([
            'current_password' => ['required'],
            'password'         => ['required', 'confirmed', 'min:8', 'regex:/[A-Z]/', 'regex:/[0-9]/'],
        ]);

        $member = $request->user();

        if (! Hash::check($request->current_password, $member->password)) {
            throw ValidationException::withMessages(['current_password' => ['目前密碼錯誤']]);
        }

        $member->update(['password' => $request->password]);

        // 除了當前 token 外，其他全部撤銷
        $currentTokenId = $request->user()->currentAccessToken()->id;
        $member->tokens()->where('id', '!=', $currentTokenId)->delete();

        return ApiResponse::success(null, '密碼已更新');
    }

    public function requestEmailChange(Request $request)
    {
        $request->validate([
            'new_email' => ['required', 'email', 'unique:members,email'],
        ]);

        $member = $request->user();

        if ($this->otpService->isThrottled($member, MemberVerification::TYPE_EMAIL_CHANGE)) {
            return ApiResponse::error('請稍候再試', 'THROTTLED', 429);
        }

        $verification = $this->otpService->generate($member, MemberVerification::TYPE_EMAIL_CHANGE);

        // 把新 email 暫存到 session / cache（或擴展 verifications 表加 payload 欄位）
        // 這裡簡化：用 Cache 暫存 5 分鐘
        cache()->put("email_change:{$member->id}", $request->new_email, now()->addMinutes(5));

        // 寄到「新」email
        \Notification::route('mail', $request->new_email)
            ->notify(new SendOtpNotification($verification->token, MemberVerification::TYPE_EMAIL_CHANGE));

        return ApiResponse::success(null, '驗證碼已寄至新 Email');
    }

    public function verifyEmailChange(Request $request)
    {
        $request->validate([
            'code' => ['required', 'string', 'size:6'],
        ]);

        $member = $request->user();
        $newEmail = cache()->pull("email_change:{$member->id}");

        if (! $newEmail) {
            return ApiResponse::error('請重新申請', 'EXPIRED', 422);
        }

        $verification = $this->otpService->verify(
            $member,
            MemberVerification::TYPE_EMAIL_CHANGE,
            $request->code,
        );

        if (! $verification) {
            return ApiResponse::error('驗證碼錯誤或已過期', 'INVALID_CODE', 422);
        }

        $member->update([
            'email' => $newEmail,
            'email_verified_at' => now(),
        ]);

        return ApiResponse::success(['email' => $newEmail], 'Email 已變更');
    }

    public function uploadAvatar(Request $request)
    {
        $request->validate([
            'avatar' => ['required', 'image', 'max:2048'], // 2MB
        ]);

        $path = $request->file('avatar')->store('avatars', 'public');

        $member = $request->user();
        $member->profile()->updateOrCreate(
            ['member_id' => $member->id],
            ['avatar' => $path],
        );

        return ApiResponse::success(['avatar_url' => asset("storage/{$path}")], '頭像已上傳');
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();
        return ApiResponse::success(null, '已登出');
    }

    public function logoutAll(Request $request)
    {
        $request->user()->tokens()->delete();
        return ApiResponse::success(null, '已登出所有裝置');
    }

    public function destroy(Request $request)
    {
        $member = $request->user();
        $member->tokens()->delete();
        $member->delete(); // soft delete
        return ApiResponse::success(null, '帳號已註銷');
    }
}
```

## 5.3 MemberResource

`app/Http/Resources/Api/V1/MemberResource.php`：

```php
<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Resources\Json\JsonResource;

class MemberResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'uuid'              => $this->uuid,
            'name'              => $this->name,
            'nickname'          => $this->nickname,
            'email'             => $this->email,
            'phone'             => $this->phone,
            'status'            => $this->status,
            'email_verified_at' => $this->email_verified_at,
            'phone_verified_at' => $this->phone_verified_at,
            'last_login_at'     => $this->last_login_at,
            'group'             => $this->whenLoaded('group', fn() => [
                'id'   => $this->group->id,
                'name' => $this->group->name,
            ]),
            'profile' => $this->whenLoaded('profile', fn() => [
                'avatar'   => $this->profile?->avatar ? asset("storage/{$this->profile->avatar}") : null,
                'gender'   => $this->profile?->gender,
                'birthday' => $this->profile?->birthday?->format('Y-m-d'),
                'bio'      => $this->profile?->bio,
                'language' => $this->profile?->language,
                'timezone' => $this->profile?->timezone,
            ]),
            'sns_providers' => $this->whenLoaded('sns', fn() =>
                $this->sns->pluck('provider')->values()
            ),
        ];
    }
}
```

## 5.4 驗收

- [ ] `php artisan storage:link` 做好（avatar 上傳需要）
- [ ] GET /me 回傳完整結構
- [ ] 改密碼會撤銷其他 token
- [ ] 換 email OTP 寄到新 email
- [ ] DELETE /me 後該 member 變 soft deleted，token 全部失效

---

# Phase 6：SNS 綁定管理

## 6.1 SnsController

`app/Http/Controllers/Api/V1/Me/SnsController.php`：

```php
<?php

namespace App\Http\Controllers\Api\V1\Me;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\MemberSns;
use App\Services\OAuth\OAuthService;
use Illuminate\Http\Request;
use Laravel\Socialite\Facades\Socialite;

class SnsController extends Controller
{
    public function index(Request $request)
    {
        $sns = $request->user()->sns()->get(['provider', 'provider_user_id', 'created_at']);
        return ApiResponse::success($sns);
    }

    public function bindUrl(string $provider)
    {
        $url = Socialite::driver($provider)->stateless()->redirect()->getTargetUrl();
        return ApiResponse::success(['url' => $url]);
    }

    public function bind(string $provider, Request $request)
    {
        $member = $request->user();

        try {
            $socialUser = Socialite::driver($provider)->stateless()->user();
        } catch (\Throwable $e) {
            return ApiResponse::error('OAuth 授權失敗', 'OAUTH_FAILED', 400);
        }

        // 檢查這個 SNS 帳號是否已綁給「其他」會員
        $existing = MemberSns::where('provider', $provider)
            ->where('provider_user_id', $socialUser->getId())
            ->first();

        if ($existing && $existing->member_id !== $member->id) {
            return ApiResponse::error('此第三方帳號已綁定其他會員', 'SNS_ALREADY_BOUND', 409);
        }

        if ($existing && $existing->member_id === $member->id) {
            return ApiResponse::success(null, '已綁定過');
        }

        MemberSns::create([
            'member_id'        => $member->id,
            'provider'         => $provider,
            'provider_user_id' => $socialUser->getId(),
            'access_token'     => $socialUser->token ?? null,
            'refresh_token'    => $socialUser->refreshToken ?? null,
            'token_expires_at' => isset($socialUser->expiresIn)
                ? now()->addSeconds($socialUser->expiresIn) : null,
        ]);

        return ApiResponse::success(null, '綁定成功');
    }

    public function unbind(string $provider, Request $request)
    {
        $member = $request->user();

        // 安全檢查：如果沒密碼，且這是唯一的 SNS，不讓解綁
        $hasPassword = ! empty($member->password) && strlen($member->password) > 20;
        $snsCount = $member->sns()->count();

        if (! $hasPassword && $snsCount <= 1) {
            return ApiResponse::error(
                '無法解綁：此為您唯一的登入方式，請先設定密碼',
                'LAST_LOGIN_METHOD',
                409
            );
        }

        $deleted = $member->sns()->where('provider', $provider)->delete();

        if (! $deleted) {
            return ApiResponse::error('未綁定此 Provider', 'NOT_BOUND', 404);
        }

        return ApiResponse::success(null, '已解除綁定');
    }
}
```

> ⚠️ `$hasPassword` 判斷可以改用「有沒有跑過 `/me/password`」的 flag 更精準，這版先用簡易判斷。

## 6.2 路由

```php
Route::prefix('v1/me/sns')->middleware('auth:member')->group(function () {
    Route::get('/', [SnsController::class, 'index']);
    Route::get('{provider}/bind-url', [SnsController::class, 'bindUrl']);
    Route::post('{provider}/bind', [SnsController::class, 'bind']);
    Route::delete('{provider}', [SnsController::class, 'unbind']);
});
```

## 6.3 驗收

- [ ] 綁定 Google → 成功
- [ ] 重複綁定同個 Google 帳號 → 回「已綁定過」
- [ ] 用 A 帳號綁定後，切到 B 帳號再綁同個 Google → 回「已綁定其他會員」
- [ ] OAuth 註冊的會員解綁唯一 SNS → 被擋

---

# 🧪 附錄：測試與除錯

## Postman / curl 範例

### 註冊
```bash
curl -X POST http://localhost/api/v1/auth/register \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{
    "name":"測試",
    "email":"test@example.com",
    "password":"Test1234",
    "password_confirmation":"Test1234",
    "agree_terms":true
  }'
```

### 驗證 Email
```bash
curl -X POST http://localhost/api/v1/auth/verify/email \
  -H "Content-Type: application/json" \
  -d '{"email":"test@example.com","code":"123456"}'
```

### 取 Me
```bash
curl http://localhost/api/v1/me \
  -H "Authorization: Bearer {TOKEN}" \
  -H "Accept: application/json"
```

## 測試帳號清除腳本

`database/seeders/TestMemberCleanSeeder.php`（開發用）：

```php
Member::where('email', 'like', '%@example.com')->forceDelete();
```

## 常見問題

| 問題 | 檢查 |
|---|---|
| Sanctum token 401 | `.env` 的 `SANCTUM_STATEFUL_DOMAINS` |
| Member 無法發 token | `Member` 有沒有 `HasApiTokens` trait |
| OTP Email 沒收到 | `MAIL_MAILER=log` 先看 log，`storage/logs/laravel.log` |
| auth:member guard 不存在 | `config:clear` + 檢查 `config/auth.php` |
| OAuth redirect 跳錯 | `.env` 的 callback URI 必須跟 Google/GitHub Console 一模一樣 |

---

# 📌 開發順序總覽

```
Day 1    ─ Phase 0 前置準備
Day 1-2  ─ Phase 1 Schema + Phase 2 註冊/驗證
Day 3    ─ Phase 3 登入 + 密碼
Day 4-5  ─ Phase 4 OAuth (Google + GitHub)
Day 6-7  ─ Phase 5 /me 系列
Day 8    ─ Phase 6 SNS 綁定
Day 9    ─ 整體測試 + Swagger 文件補齊
Day 10   ─ 緩衝 / 修 bug
```

## 未來擴充（不在本次範圍）

- LINE / Discord OAuth（需處理自訂 provider）
- Email 驗證後發歡迎信
- 登入異常偵測（異地登入通知）
- 2FA / TOTP
- 地址管理 CRUD（Phase 7，獨立出去）
- Rate Limiting（`throttle:6,1` 套用到登入/註冊/OTP 發送）

---

**結束。開始前先 `git checkout -b feature/member-auth-api` 再動工。**
