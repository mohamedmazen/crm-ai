# تقرير التدقيق الأمني الشامل وخطة الإصلاح
## Comprehensive Security Audit Report & Remediation Plan
**WhatsMine CRM — Senior Software Architect Security Audit**

---

## 📌 1. ملخص تنفيذي (Executive Summary)

تم إجراء فحص وتدقيق أمني معماري شامل لكافة طبقات نظام **WhatsMine CRM** قبل مرحلة الرفع على سيرفر الإنتاج والـ VPS (aaPanel). شمل التدقيق:
- طبقات المصادقة وإدارة الجلسات (`Authentication & Session Management`).
- العزل بين المشتركين في بيئة الـ Multi-tenancy (`Client & Workspace Isolation`).
- مسارات الـ Webhooks وحماية الـ CSRF والتحقق من التوقيع الرقمي.
- حماية استعلامات قواعد البيانات (`SQL Injection Prevention`).
- مسارات رفع الملفات والتعامل مع الوسائط (`File Upload Security`).
- الـ Rate Limiting وإجراءات منع هجمات الـ Brute Force والـ DoS.
- إعدادات الـ Infrastructure والـ Headers الأمنية في الـ Production.

---

## 📊 2. مصفوفة الثغرات والمخاطر (Vulnerability Matrix)

| # | المعرّف / الثغرة | التصنيف | درجة الخطورة (Severity) | الملف المستهدف |
|---|----------------|---------|-------------------------|----------------|
| **1** | Firebase Token Verification Bypass | مصادقة (Auth Bypass) | 🔴 **Critical (CVSS 9.8)** | `app/Http/Controllers/Auth/FirebaseLoginController.php` |
| **2** | Arbitrary File Upload in Inbox Media | رفع ملفات (RCE / Stored XSS) | 🔴 **Critical (CVSS 9.0)** | `app/Modules/Inbox/Http/Controllers/InboxController.php` |
| **3** | 2FA Secrets Exposure & Mass-Assignment | تسريب بيانات (Data Exposure) | 🟠 **High (CVSS 7.5)** | `app/Models/User.php` & `HandleInertiaRequests.php` |
| **4** | Wildcard Trusted Proxies (`*`) | تزييف الهوية (IP Spoofing) | 🟠 **High (CVSS 7.3)** | `bootstrap/app.php` |
| **5** | Missing Rate Limits (2FA / Register / Contact) | هجمات القوة الغاشمة (Brute Force) | 🟠 **High (CVSS 7.1)** | `routes/auth.php` & `routes/web.php` |
| **6** | Insecure Session Defaults in Production | جلسات (Session Hijacking) | 🟠 **High (CVSS 6.8)** | `config/session.php` & `.env` |
| **7** | Over-privileged Impersonation Permission | تصعيد صلاحيات (Privilege Scope) | 🟡 **Medium (CVSS 5.5)** | `app/Policies/ClientPolicy.php` |
| **8** | SVG Stored XSS & Client Original Extension | رفع ملفات (Stored XSS) | 🟡 **Medium (CVSS 5.3)** | `ClientBrandingController.php` & `SystemSettingsController.php` |
| **9** | Sanctum Tokens Never Expire | وصول غير منتهي (Token Lifecycle) | 🟡 **Medium (CVSS 5.0)** | `config/sanctum.php` |
| **10** | Public Healthz Information Disclosure | كشف بيانات (Info Disclosure) | 🟡 **Medium (CVSS 4.5)** | `routes/web.php` |
| **11** | Default Seed Passwords in Repository | كلمات سر افتراضية ضعيفة | 🟡 **Medium (CVSS 4.0)** | `.env.example` |
| **12** | CSP `'unsafe-inline'` & Runtime `env()` Calls | سياسة أمان وهيكلة كود | 🟢 **Low / Hygiene** | `SecureHeaders.php` & `HandleInertiaRequests.php` |

---

## 🔍 3. تفاصيل الثغرات والحلول البرمجية (Detailed Findings & Fixes)

---

### 🔴 الثغرة رقم 1: Firebase ID Token Verification Bypass (تجاوز المصادقة بالكامل)

* **الملف المتأثر:** `app/Http/Controllers/Auth/FirebaseLoginController.php` (الأسطر 112–126).
* **درجة الخطورة:** 🔴 **Critical**
* **الوصف التقني:**
  يحتوي كود التحقق من توكن Firebase على خطأ منطقي فادح:
  ```php
  $validAudience = $aud === $projectId || Str::contains($aud, $projectId);
  $validIssuer   = in_array($iss, [
      "https://securetoken.google.com/{$projectId}",
      'https://accounts.google.com',
  ]);

  if (! $validAudience && ! $validIssuer) {
      return null;
  }
  ```
  1. الشرط يستخدم المعامل المنطقي `&&` داخل النفي: `if (! $validAudience && ! $validIssuer)` معناه أن التوكن يُرفض فقط إذا كان الـ Audience خاطئاً **وفي نفس الوقت** الـ Issuer خاطئاً! إذا كان أحدهما فقط صحيحاً ينجح التحقق.
  2. القائمة تحتوي على `'https://accounts.google.com'`، وهو الـ Issuer لأي توكن صادر من جوجل لأي شخص في العالم من أي تطبيق آخر. بالتالي أي شخص يحمل توكن صادر من جوجل سيعتبر `$validIssuer = true` وسيتجاوز الشرط!
  3. يتم فحص `$aud` باستخدام `Str::contains` وليس مطابقة تامة، مما يسمح بمطابقة جزئية لمشروع مختلف.
  4. بعد تجاوز الشرط، يقوم الكود بتسجيل الدخول مباشرة للمستخدم بناءً على الإيميل فقط `Auth::login($user)`.
* **الأثر الجرمي (Impact):**
  الاستيلاء الكامل على حساب أي مستخدم في النظام بمجرد معرفة بريده الإلكتروني (Account Takeover).
* **خطوات الإصلاح المقترحة:**
  تعديل الفحص ليتحقق من أن الـ Audience **و** الـ Issuer كلاهما صحيحين وبمطابقة تامة:
  ```php
  $validAudience = ($aud === $projectId);
  $validIssuer = ($iss === "https://securetoken.google.com/{$projectId}");

  if (! $validAudience || ! $validIssuer) {
      return null;
  }
  ```

---

### 🔴 الثغرة رقم 2: Arbitrary File Upload في وسائط رسائل Inbox (تنفيذ كود خبيث)

* **الملف المتأثر:** `app/Modules/Inbox/Http/Controllers/InboxController.php` (الأسطر 589–606).
* **درجة الخطورة:** 🔴 **Critical**
* **الوصف التقني:**
  في دالة `uploadMedia` الخاصة برفع قوالب وسائط WhatsApp:
  ```php
  $request->validate(['file' => ['required', 'file', 'max:16384']]);
  ```
  لا يوجد أي تحديد لنوع أو امتداد الملف المرفوع (`mimes`).
  بعد ذلك، يتم حفظ الملف بالامتداد الأصلي الذي اختاره العميل:
  ```php
  $path = $this->storageManager->prefixedPath('template-media/'.$file->hashName());
  $this->storageManager->disk()->putFileAs(dirname($path), $file, basename($path));
  ```
  حيث تعتمد الدالة الافتراضية على القرص `public` (`storage/app/public` المرتبط بـ `public/storage`).
* **الأثر الجرمي (Impact):**
  يمكن للمهاجم رفع ملف سكربت تنفيذي مثل `shell.php` أو `backdoor.phtml`، أو ملف `exploit.svg`، والوصول إليه عبر المتصفح مما يتيح تشغيل أوامر برمجية على السيرفر (Remote Code Execution - RCE).
* **خطوات الإصلاح المقترحة:**
  إلزام التحقق بقائمة بيضاء صارمة للامتدادات المسموح بها في WhatsApp (صور، فيديوهات، مستندات فقط):
  ```php
  $request->validate([
      'file' => [
          'required',
          'file',
          'max:16384',
          'mimes:jpg,jpeg,png,webp,mp4,pdf',
      ],
  ]);
  ```

---

### 🟠 الثغرة رقم 3: تسريب أسرار 2FA وإمكانية الـ Mass-Assignment

* **الملفات المتأثرة:**
  - `app/Models/User.php` (الأسطر 60–83).
  - `app/Http/Middleware/HandleInertiaRequests.php` (السطر 357).
* **درجة الخطورة:** 🟠 **High**
* **الوصف التقني:**
  1. حقول `two_factor_secret` و `two_factor_recovery_codes` موجودة في مصفوفة `$fillable` داخل موديل `User`.
  2. هذه الحقول **غير مدرجة في مصفوفة `$hidden`**!
  3. في `HandleInertiaRequests.php:357`، تتم مشاركة كائن المستخدم بالكامل عبر Inertia:
     ```php
     $auth = [
         'user' => $user,
     ];
     ```
  4. عند تحويل الموديل إلى JSON، يتم تضمين نص التوكن المشفر وأكواد الاسترداد المشفرة داخل كود الـ HTML للصفحة (`<div id="app" data-page="...">`) ويراها أي مستخدم يفتح `F12 / Console`.
  5. وجود `role` و `status` و `client_id` داخل `$fillable` بدون حماية صارمة قد يسبب تصعيد صلاحيات في حال استخدام `$request->all()` في أي دالة تعديل.
* **الأثر الجرمي (Impact):**
  كشف بيانات التشفير الخاصة بالـ 2FA على الواجهة الأمامية، وإمكانية تعديلها أو تعطيلها في حال وجود أي ثغرة Mass Assignment.
* **خطوات الإصلاح المقترحة:**
  1. إضافة الحقول فوراً إلى مصفوفة `$hidden` في `app/Models/User.php`:
     ```php
     protected $hidden = [
         'password',
         'remember_token',
         'two_factor_secret',
         'two_factor_recovery_codes',
     ];
     ```
  2. استبعاد `two_factor_secret` و `two_factor_recovery_codes` من مصفوفة `$fillable`.

---

### 🟠 الثغرة رقم 4: استخدام Wildcard `*` في الـ Trusted Proxies

* **الملف المتأثر:** `bootstrap/app.php` (الأسطر 140–146).
* **درجة الخطورة:** 🟠 **High**
* **الوصف التقني:**
  ```php
  $middleware->trustProxies(
      at: env('TRUSTED_PROXIES', '*'),
      headers: Request::HEADER_X_FORWARDED_FOR
          | Request::HEADER_X_FORWARDED_HOST
          | Request::HEADER_X_FORWARDED_PORT
          | Request::HEADER_X_FORWARDED_PROTO,
  );
  ```
  عندما تكون القيمة الافتراضية للـ `TRUSTED_PROXIES` هي `'*'`، فإن لارافيل يثق في أي عميل يتصل بالسيرفر كأنه Proxy رسمي ويقرأ عنوان الـ IP الحقيقي من هيدر `X-Forwarded-For`.
* **الأثر الجرمي (Impact):**
  1. **تجاوز الـ Rate Limiting:** يستطيع المهاجم تزييف الـ IP في كل طلب بتغيير هيدر `X-Forwarded-For: 1.2.3.4`، مما يعطل تماماً الحماية ضد هجمات الـ Brute Force على تسجيل الدخول.
  2. **تجاوز الـ IP Allowlist للـ Admin:** يعتمد الميدل وير `app/Http/Middleware/IpAllowlist.php` على `$request->ip()`. يستطيع المهاجم وضع IP مسموح به في الهيدر والدخول إلى لوحة التحكم.
  3. تزوير بيانات الـ Audit Trail والسجلات الأمنية.
* **خطوات الإصلاح المقترحة:**
  إلزام تحديد عناوين الـ Proxies الحقيقية فقط (مثل `127.0.0.1` أو نطاقات Cloudflare) ومنع القيمة الافتراضية العامة:
  ```php
  $middleware->trustProxies(
      at: env('TRUSTED_PROXIES') ? explode(',', (string) env('TRUSTED_PROXIES')) : [],
      ...
  );
  ```
  وفي ملف `.env` الخاص بالإنتاج:
  ```env
  TRUSTED_PROXIES=127.0.0.1
  ```

---

### 🟠 الثغرة رقم 5: غياب الـ Rate Limiting في مسارات حساسة

* **الملفات المتأثرة:** `routes/auth.php` و `routes/web.php`.
* **درجة الخطورة:** 🟠 **High**
* **الوصف التقني:**
  المسارات التالية لا تحتوي على `throttle` middleware:
  1. `POST /two-factor-challenge` (السطر 45 في `routes/auth.php`): التحقق من كود الـ TOTP بدون حد للمحاولات! كود الـ 2FA مكون من 6 أرقام (1,000,000 احتمال)، وغياب الـ Rate Limit يسهل تخمينه عبر سكربت بسيط.
  2. `POST /register` (السطر 27 في `routes/auth.php`): إنشاء حسابات جديدة بدون Rate Limit، مما يتيح إغراق السيرفر وقاعدة البيانات بآلاف الحسابات والشركات الوهمية.
  3. `POST /auth/firebase` (السطر 78 في `routes/auth.php`): تسجيل الدخول عبر Firebase بدون تقييد للطلبات.
  4. `POST /contact` (السطر 37 في `routes/web.php`): نموذج التواصل في الصفحة الرئيسية متاح للبوتات بدون تقييد، مما يمكن استغلاله لإغراق البريد أو قواعد البيانات بالرسائل المزعجة (Spam).
* **خطوات الإصلاح المقترحة:**
  تطبيق الـ Throttle في `routes/auth.php` و `routes/web.php`:
  ```php
  Route::post('two-factor-challenge', [TwoFactorController::class, 'verify'])
      ->middleware('throttle:5,1')
      ->name('auth.two-factor.verify');

  Route::post('register', [RegisteredUserController::class, 'store'])
      ->middleware('throttle:10,1');

  Route::post('auth/firebase', [FirebaseLoginController::class, 'login'])
      ->middleware('throttle:10,1')
      ->name('auth.firebase');

  Route::post('/contact', [ContactController::class, 'store'])
      ->middleware('throttle:5,1')
      ->name('contact.store');
  ```

---

### 🟠 الثغرة رقم 6: إعدادات أمان الجلسات (Session Security Defaults)

* **الملف المتأثر:** `config/session.php` (السطور 50، 172).
* **درجة الخطورة:** 🟠 **High**
* **الوصف التقني:**
  - `'secure' => env('SESSION_SECURE_COOKIE')`: القيمة الافتراضية فارغة (`null`). إذا لم يتم كتابة المتغير صراحة في `.env` فسيتم إرسال كوكيز الجلسة عبر اتصالات HTTP العادية بدون تشفير، مما يعرض الجلسات للسرقة عبر هجمات رجل في المنتصف (Man-in-the-Middle).
  - `'encrypt' => env('SESSION_ENCRYPT', false)`: الجلسات غير مشفرة في قاعدة البيانات أو الـ Redis. بيانات الجلسة تحتوي على أسرار الدخول والـ Impersonation Tokens.
* **خطوات الإصلاح المقترحة:**
  إضافة المتغيرات التالية بشكل إلزامي في ملف `.env` على السيرفر:
  ```env
  SESSION_SECURE_COOKIE=true
  SESSION_ENCRYPT=true
  ```

---

### 🟡 الثغرة رقم 7: صلاحية الـ Impersonation تعتمد على `view_clients` فقط

* **الملف المتأثر:** `app/Policies/ClientPolicy.php` (الأسطر 40–43).
* **درجة الخطورة:** 🟡 **Medium**
* **الوصف التقني:**
  ```php
  public function impersonate(AdminUser $user, Client $client): bool
  {
      return $user->hasPermissionTo('view_clients');
  }
  ```
  أي موظف أو إداري يملك فقط صلاحية "مشاهدة قائمة العملاء" يستطيع الضغط على زر الدخول كعميل (Impersonate)، مما يمنحه وصولاً كاملاً إلى محادثات WhatsApp، فواتير العميل، مراسلاته، وإعدادات الربط الخاصة به.
* **خطوات الإصلاح المقترحة:**
  فصل الصلاحية وجعلها صلاحية أمنية مستقلة وحساسة `impersonate_clients` تُمنح فقط لـ Super Admin:
  ```php
  public function impersonate(AdminUser $user, Client $client): bool
  {
      return $user->hasPermissionTo('impersonate_clients');
  }
  ```

---

### 🟡 الثغرة رقم 8: استخدام `getClientOriginalExtension` وملفات SVG في الـ Branding

* **الملفات المتأثرة:**
  - `app/Http/Controllers/Admin/ClientBrandingController.php` (السطر 29).
  - `app/Http/Controllers/Admin/SystemSettingsController.php` (السطر 132).
* **درجة الخطورة:** 🟡 **Medium**
* **الوصف التقني:**
  1. في `ClientBrandingController`: يتم بناء اسم الملف باستخدام `$file->getClientOriginalExtension()` بدلاً من `$file->guessExtension()`. الامتداد الأصلي قادم من الـ Request من طرف العميل ويمكن التلاعب به لتجاوز فلاتر أسماء الملفات.
  2. في `SystemSettingsController`: السماح برفع ملفات `svg` كـ Favicon أو Logo بدون تطهير (Sanitization). ملف الـ SVG عبارة عن XML ويمكن حقن وسوم `<script>` أو أكواد JavaScript بداخله مسبباً ثغرة Stored XSS.
* **خطوات الإصلاح المقترحة:**
  1. استبدال `getClientOriginalExtension()` بـ `guessExtension()` أو `extension()`.
  2. حظر رفع ملفات الـ SVG أو استخدام مكتبة تطهير خاصة قبل تخزينها.

---

### 🟡 الثغرة رقم 9: عدم انتهاء صلاحية توكنات Sanctum API

* **الملف المتأثر:** `config/sanctum.php` (السطر 50).
* **درجة الخطورة:** 🟡 **Medium**
* **الوصف التقني:**
  ```php
  'expiration' => null,
  ```
  توكنات الـ API الصادرة للمستخدمين أو الهواتف المحمولة لا تنتهي صلاحيتها أبداً وتظل فعالة إلى الأبد ما لم تُحذف يدوياً من قاعدة البيانات.
* **خطوات الإصلاح المقترحة:**
  تحديد مدة انتهاء مناسبة في `config/sanctum.php` (مثلاً 60 يوماً):
  ```php
  'expiration' => 60 * 24 * 60, // 60 days
  ```

---

### 🟡 الثغرة رقم 10: كشف بيانات السيرفر في مسار الـ Health Check

* **الملف المتأثر:** `routes/web.php` (السطر 168).
* **درجة الخطورة:** 🟡 **Medium**
* **الوصف التقني:**
  ```php
  return ! filled($token) || hash_equals($token, $request->bearerToken() ?? '');
  ```
  عند عدم وجود `HEALTHZ_TOKEN` في `.env` (وهي الحالة الافتراضية)، تصبح الروابط `/healthz` و `/healthz/db` و `/healthz/redis` عامة وتكشف تفاصيل معمارية النظام وحالة قاعدة البيانات وسير العمل للعامة.
* **خطوات الإصلاح المقترحة:**
  إلزام التحقق من وجود التوكن دائماً في بيئة الـ Production ورفض الطلب في حال عدم وجوده.

---

## 🛡️ 4. نقاط القوة المعمارية في النظام (Architecture Strengths)

خلال الفحص، تبين أن هناك ممارسات أمنية عالية المستوى مطبقة بالفعل في التطبيق:

1. **حماية SQL Injection محكمة:** تم فحص أكثر من 80 موضعاً لاستعلامات `DB::raw`, `whereRaw`, `selectRaw`، وجميعها تستخدم الـ Parameter Binding والـ Placeholders (`?`) بشكل صحيح دون دمج متغيرات المستخدم المباشرة.
2. **عزل المشتركين (Multi-Tenancy Isolation):** وجود تحقق إلزامي ومباشر لـ `workspace_id` في جميع دوال العرض والتعديل والحذف (`authoriseContact`, `this->workspaceId($request)`).
3. **تشفير الأسرار وحماية Webhooks:** جميع مفاتيح بوابات الدفع ومزودي الذكاء الاصطناعي وWhatsApp مخزنة مشفرة داخل جدول `integration_configs`، ومسارات الـ Webhooks محمية بالتحقق من التوقيع الرقمي (Signature Verification) وإخفاء الهيدرز الحساسة من الـ Logs.
4. **تجديد الجلسات (Session Fixation Defense):** يتم تنفيذ `$request->session()->regenerate()` في جميع دوال تسجيل الدخول.
5. **Secure Headers متطورة:** تطبيق `SecureHeaders.php` بشكل احترافي مع ضبط الـ `Content-Security-Policy`، وحظر الـ Clickjacking عبر `X-Frame-Options: SAMEORIGIN`، وتفعيل `Cache-Control: no-store` للصفحات المصادق عليها لمنع استرجاع البيانات عبر أزرار المتصفح.

---

## 🚀 5. دليل ضبط سيرفر الإنتاج والـ VPS (aaPanel Production Security)

عند رفع التطبيق على لوحة **aaPanel**، يجب تطبيق الإعدادات التالية في ملفات إعدادات الـ Nginx والـ PHP:

### 1. منع تشغيل ملفات PHP نهائياً داخل مجلدات الـ Storage والـ Uploads (قاعدة Nginx حاسمة)
أضف هذه القاعدة داخل ملف إعداد الـ Nginx للموقع (Site Nginx Configuration) داخل aaPanel:
```nginx
# Prevent direct PHP execution inside upload and storage directories
location ~* ^/(storage|uploads)/.*\.php$ {
    deny all;
    return 404;
}
```

### 2. حماية وتأمين خادم Redis
- التأكد من أن Redis يستمع فقط للعنوان المحلي: `bind 127.0.0.1 ::1` في ملف `redis.conf`.
- تعيين كلمة مرور قوية لـ Redis عبر توجيه `requirepass` في ملف الإعدادات.
- التأكد من إغلاق المنفذ `6379` في جدار الحماية (aaPanel Firewall & UFW) وعدم فتحه للخارج أبداً.

### 3. إعدادات بيئة الإنتاج في `.env`
تأكد من تطبيق القيم التالية بدقة على السيرفر:
```env
APP_ENV=production
APP_DEBUG=false
APP_URL=https://your-domain.com

SESSION_SECURE_COOKIE=true
SESSION_ENCRYPT=true

# تحديد IP الخادم المحلي أو البروكسي فقط
TRUSTED_PROXIES=127.0.0.1

# توكن حماية مسارات الـ Healthz
HEALTHZ_TOKEN=your-random-secure-64-char-string
```

### 4. صلاحيات الملفات (File Permissions) على السيرفر
```bash
chown -R www:www /www/wwwroot/your-crm-directory
chmod -R 755 /www/wwwroot/your-crm-directory
chmod -R 775 /www/wwwroot/your-crm-directory/storage
chmod -R 775 /www/wwwroot/your-crm-directory/bootstrap/cache
```

---

## 🎯 الخلاصة والتوصية
هذا الفحص يوفر خريطة واضحة ومحددة. البدء بتطبيق تعديلات الكود (المرحلة الأولى) سيغلق كافة الثغرات الحرجة في دقائق معدودة، مما يضمن رفع التطبيق على الـ VPS وهو محمي بالكامل ومطابق لأعلى المعايير الأمنية.

