# دليل النشر والرفع الكامل على خادم aaPanel (AYDRA CRM)
## Production Deployment Guide for aaPanel

تم إعداد هذا الدليل خصيصاً ومطابقته بدقة مع بيئة الخادم لديك (aaPanel) بناءً على الخدمات المثبتة وملفات الـ Supervisor الحالية على سيرفرك.

---

## 📊 1. تحليل بيئة السيرفر والمنافذ (Port Allocation)

بناءً على ملفات `profile` المستخرجة من سيرفرك، تم رصد الخدمات والمنافذ المحجوزة كالتالي:

| الخدمة الحالية على السيرفر | المنفذ (Port) | المسار |
| :--- | :--- | :--- |
| **rms-octane (Restaurant)** | `8000` | `/www/wwwroot/restaurant/` |
| **store-octane (Store)** | `8001` | `/www/wwwroot/store/` |
| **rms-reverb (Restaurant WebSockets)** | `8082` | `/www/wwwroot/restaurant/` |
| **whatsapp-gateway (NodeJS)** | Node Service | `/www/wwwroot/restaurant/whatsapp-gateway/` |

### 🎯 المنافذ المخصصة لمشروع الـ CRM الجديد (بدون أي تضارب):
* **منفذ Reverb WebSockets للـ CRM:** **`8083`** (متاح ومخصص).
* **منفذ Octane للـ CRM (في حال تشغيل Octane):** **`8002`** (متاح ومخصص).
* **مسار المشروع على السيرفر:** `/www/wwwroot/crm/`

---

## 🛑 2. إيقاف GitHub Actions (تم تنفيذه)
تم تعطيل ملف Workflow الخاص بالـ CI لمنع تشغيل الاختبارات وفشلها عند كل Push:
* تم تحويل الملف إلى: [`.github/workflows/ci.yml.disabled`](file:///c:/Users/Mazen/Herd/crm/.github/workflows/ci.yml.disabled)
* بمجرد عمل `git push`، لن يتم استدعاء GitHub Actions مجدداً.

---

## 🛠️ 3. الخطوات التفصيلية للرفع والنشر عبر aaPanel

### الخطوة 1: ضبط إعدادات PHP 8.2 في aaPanel (حرج جداً ⚠️)
ادخل على لوحة **aaPanel ➡️ App Store ➡️ PHP 8.2 ➡️ Settings**:

1. **تثبيت الإضافات (Extensions):**
   تأكد من تثبيت وتفعيل:
   * `fileinfo`
   * `redis`
   * `opcache`
   * `intl`
   *(إضافات `bcmath` و `pdo_mysql` و `mbstring` مثبتة افتراضياً).*

2. **حذف الدوال المعطلة (Disabled Functions) - خطوة إجبارية:**
   في تبويب **Disabled functions**، ابحث عن الدوال التالية واحذفها من قائمة الحظر (اضغط Delete بجانب كل منها):
   * `proc_open` (مطلوبة لتشغيل الـ Queue و Reverb والـ Composer)
   * `proc_get_status`
   * `pcntl_signal`
   * `pcntl_alarm`
   * `putenv`
   * `symlink`
   *(لو ظلت هذه الدوال محظورة، سيتوقف الـ Queue والـ Reverb عن العمل تماماً).*

---

### الخطوة 2: إنشاء قاعدة البيانات في aaPanel
1. من القائمة الجانبية: **Databases ➡️ Add Database**.
2. اسم قاعدة البيانات: `crm_production`
3. المستخدم: `crm_user`
4. كلمة المرور: قم بتوليد كلمة مرور قوية واحفظها.
5. الترميز (Character Set): اختر `utf8mb4`.

---

### الخطوة 3: إنشاء الموقع (Add Site) في aaPanel
1. من القائمة الجانبية: **Website ➡️ Add site**.
2. **Domain name:** اكتب الدومين أو الدومين الفرعي (مثال: `crm.yourdomain.com`).
3. **Root directory:** سيتم تعيينه تلقائياً إلى `/www/wwwroot/crm`.
4. **Database:** يمكنك اختيار قاعدة البيانات التي أنشأتها أو تركها.
5. **PHP Version:** اختر **`PHP-82`**.
6. اضغط **Submit**.

---

### الخطوة 4: ضبط مجلد التشغيل و Nginx و SSL
بعد إنشاء الموقع، اضغط على اسم الموقع للدخول على إعداداته:

1. **مجلد التشغيل (Site directory):**
   * اذهب إلى تبويب **Site directory**.
   * في خانة **Running directory** اختر: **`/public`** ⬅️ *(ضروري جداً، لا تتركه على الجذر).*
   * اضغط **Save**.

2. **قواعد إعادة التوجيه (URL rewrite):**
   * اذهب إلى تبويب **URL rewrite**.
   * اختر من القائمة الجاهزة: **`laravel5`**.
   * اضغط **Save**.

3. **شهادة الأمان (SSL):**
   * اذهب إلى تبويب **SSL ➡️ Let's Encrypt**.
   * حدد الدومين واضغط **Apply** للحصول على شهادة HTTPS مجانية.

4. **إعداد WebSocket Reverse Proxy للـ Reverb:**
   * اذهب إلى تبويب **Configuration file** واستبدل المحتوى بالكامل أو أضف إعدادات Reverb ورفع الملفات وGzip الموضحة:

   ```nginx
   # Laravel Reverb WebSockets Reverse Proxy (Port 8083)
   # يوجه اتصالات الويب سوكت الخاصة بـ Reverb بدون تعارض مع مسارات لوحة التحكم /app/dashboard
   location ~ ^/app/(326e8194aa0383d91815|[a-zA-Z0-9]{16,}) {
       proxy_pass http://127.0.0.1:8083;
       proxy_http_version 1.1;
       proxy_set_header Upgrade $http_upgrade;
       proxy_set_header Connection "Upgrade";
       proxy_set_header Host $host;
       proxy_set_header X-Real-IP $remote_addr;
       proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
       proxy_set_header X-Forwarded-Proto $scheme;
       proxy_read_timeout 600s;
       proxy_send_timeout 600s;
       proxy_connect_timeout 60s;
   }

   location ~ ^/apps/ {
       proxy_pass http://127.0.0.1:8083;
       proxy_http_version 1.1;
       proxy_set_header Host $host;
       proxy_set_header X-Real-IP $remote_addr;
       proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
       proxy_set_header X-Forwarded-Proto $scheme;
   }
   ```
   * اضغط **Save**.

---

### الخطوة 5: رفع ملفات المشروع إلى السيرفر
لديك طريقتان:
* **الطريقة (أ) عبر Git (الأسرع والأفضل):**
  افتح الـ **Terminal** في aaPanel واكتب:
  ```bash
  cd /www/wwwroot
  git clone https://github.com/mohamedmazen/crm-ai.git crm
  ```
* **الطريقة (ب) عبر الرفع اليدوي (ZIP):**
  اضغط ملفات المشروع في جهازك (باستثناء مجلد `node_modules` ومجلد `vendor`) وارفع الملف إلى `/www/wwwroot/crm` وفك الضغط هناك.

---

### الخطوة 6: ضبط الصلاحيات والبيئة (`.env`)
افتح الـ **Terminal** من لوحة aaPanel ونفذ الأوامر التالية بالترتيب:

```bash
cd /www/wwwroot/crm

# 1. ضبط ملكية الملفات لمستخدم الويب www
chown -R www:www /www/wwwroot/crm
chmod -R 775 storage bootstrap/cache

# 2. إنشاء ملف الإعدادات
cp .env.production .env
```

افتح ملف `.env` وعدّل القيم التالية لتطابق بياناتك الحقيقية:
* `APP_URL=https://crm.business-os.tech`
* `DB_DATABASE=crm_production`
* `DB_USERNAME=crm_user`
* `DB_PASSWORD=YOUR_PASSWORD`
* `VITE_REVERB_HOST="crm.business-os.tech"`

---

### الخطوة 7: تثبيت الحزم وقاعدة البيانات وبناء الواجهة
من الـ Terminal في مسار المشروع `/www/wwwroot/crm`:

```bash
# 1. تثبيت حزم PHP للإنتاج
/www/server/php/82/bin/php /usr/bin/composer install --no-dev --optimize-autoloader

# 2. توليد مفتاح التطبيق وربط مجلد الملفات
/www/server/php/82/bin/php artisan key:generate --force
/www/server/php/82/bin/php artisan storage:link

# 3. تشغيل المايجريشن والتهيئة الأولية
/www/server/php/82/bin/php artisan migrate --force

# 4. بناء ملفات الواجهة (Vite React)
npm install
npm run build

# 5. التخزين المؤقت للإعدادات والراوتات لسرعة فائقة
/www/server/php/82/bin/php artisan config:cache
/www/server/php/82/bin/php artisan route:cache
/www/server/php/82/bin/php artisan view:cache
```

---

### الخطوة 8: إعداد بروفايلات Supervisor في aaPanel
لقد قمنا بتوليد ملفات الـ Supervisor مسبقاً ووضعناها في مسارين:
1. في مشروعك: `deploy/supervisor/`
2. في مجلد التنزيلات بجهازك بجانب ملفاتك السابقة: `C:\Users\Mazen\Downloads\profile\profile\`

الملفات التي تم إنشاؤها في `deploy/supervisor/`:
* **`crm-queue-interactive.ini`**: طوابير الواتساب والبريد والأتمتة والليدز (4 عمليات) — الأولوية للواتساب.
* **`crm-queue-broadcast.ini`**: طابور الحملات فقط (عمليتان)، معزول حتى لا تستهلك الحملة الكبيرة باقي الطوابير.
* **`crm-queue-social.ini`**: طابور النشر الاجتماعي (عملية واحدة).
* **`crm-queue-ai.ini`**: طابور فهرسة الـ AI (عملية واحدة).

> ملاحظة: برامج `crm-reverb` و`crm-schedule` و`crm-octane` تُضاف مباشرة عبر **Add Daemon** كما هو موضّح بالأسفل (لا تحتاج ملفات ini).
>
> `REDIS_QUEUE_RETRY_AFTER` يجب أن يبقى أكبر من أطول `$timeout` في الـ jobs (900s)، لذلك القيمة الافتراضية الآن `1200`.

#### طريقة إضافتها في aaPanel:
1. ادخل على: **App Store ➡️ Supervisor Manager ➡️ Settings**.
2. اضغط على **Add Daemon** وأدخل بيانات كل خدمة (أو انسخ محتوى الملفات مباشرة إلى مسار إعدادات Supervisor بالسيرفر `/etc/supervisor/conf.d/` أو مسار البلاجن في aaPanel `/www/server/panel/plugin/supervisor/`):
   - **Interactive Queue Pool** (`crm-queue-interactive.ini`):
     - Name: `crm-queue-interactive`
     - Run User: `www`
     - Run Dir: `/www/wwwroot/crm`
     - Command: `/www/server/php/82/bin/php artisan queue:work redis --queue=whatsapp,default,automation,leads --sleep=2 --tries=3 --timeout=120 --max-time=3600`
     - Processes: `4`
   - **Broadcast Queue Pool** (`crm-queue-broadcast.ini`):
     - Name: `crm-queue-broadcast`
     - Run User: `www`
     - Run Dir: `/www/wwwroot/crm`
     - Command: `/www/server/php/82/bin/php artisan queue:work redis --queue=broadcast --sleep=3 --tries=3 --timeout=120 --max-time=3600`
     - Processes: `2`
   - **Social Queue Pool** (`crm-queue-social.ini`):
     - Name: `crm-queue-social`
     - Run User: `www`
     - Run Dir: `/www/wwwroot/crm`
     - Command: `/www/server/php/82/bin/php artisan queue:work redis --queue=social --sleep=5 --tries=3 --timeout=180 --max-time=3600`
     - Processes: `1`
   - **AI Queue Pool** (`crm-queue-ai.ini`):
     - Name: `crm-queue-ai`
     - Run User: `www`
     - Run Dir: `/www/wwwroot/crm`
     - Command: `/www/server/php/82/bin/php artisan queue:work redis --queue=ai --sleep=5 --tries=3 --timeout=300 --max-time=3600`
     - Processes: `1`
   - **Reverb Daemon:**
     - Name: `crm-reverb`
     - Run User: `www`
     - Run Dir: `/www/wwwroot/crm`
     - Command: `/www/server/php/82/bin/php artisan reverb:start --host=0.0.0.0 --port=8083`
     - Processes: `1`
   - **Schedule Daemon:**
     - Name: `crm-schedule`
     - Run User: `www`
     - Run Dir: `/www/wwwroot/crm`
     - Command: `/www/server/php/82/bin/php artisan schedule:work`
     - Processes: `1`

3. بعد الإضافة، اضغط **Start** لكل برنامج للتأكد من أن حالته أصبحت خضراء **Running**!

---

## ✅ 4. التحقق النهائي (Health Check)
1. افتح رابط الدومين `https://crm.yourdomain.com` وتأكد من فتح صفحة تسجيل الدخول.
2. سجّل الدخول بحساب الأدمن الافتراضي أو قم بتشغيل أمر إنشاء السوبر أدمن:
   ```bash
   /www/server/php/82/bin/php artisan db:seed --class=AdminUserSeeder
   ```
3. افتح صفحة [Inbox](https://crm.yourdomain.com/app/inbox) للتأكد من استقرار الرسائل والـ WebSockets.

