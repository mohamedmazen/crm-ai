# دليل تجربة كل مرحلة على النظام الفعلي (Phases 0 → 6)

> هذا الملف يشرح **خطوة بخطوة** إزاي تجرّب كل مرحلة من خلال واجهة الـ CRM الحقيقية على
> `https://crm.test` — من غير أي كود، بمجرد الدخول والتنقّل.
>
> المراحل 0–3 كانت موثّقة في `doc/phases-0-3-testing-guide.md`، والملف ده نسخة **كاملة وموحّدة**
> بتشمل كمان **Phase 4 (محرك الإيراد)** و **Phase 5 (نضج المنصة)** و **Phase 6 (الذكاء الاصطناعي للمبيعات)**.

---

## 0. تجهيز البيئة قبل أي اختبار

### 0.1 الرابط والحسابات

| نوع الحساب | الرابط | البريد | كلمة المرور | الباقة |
| :--- | :--- | :--- | :--- | :--- |
| **عميل (Tenant)** | `https://crm.test/login` | `client@spagreen.net` | `12345678` | **Business** (كل الميزات مفتوحة) |
| **إدارة (Super Admin)** | `https://crm.test/admin/login` | `admin@spagreen.net` | `12345678` | — |

> لو عايز تجرّب **اختبار الرفض (Negative Test)**: ادخل بباقة أقل (Starter/Pro) أو غيّر باقة العميل من
> `admin/clients` وشوف الميزة وهي مقفولة.

### 0.2 الخدمات اللي لازم تكون شغّالة

افتح Terminal وخلّي كل خدمة في نافذة منفصلة:

```bash
# 1) واجهة الواجهة الأمامية (مهم جداً — من غيرها هتلاقي ERR_CONNECTION_REFUSED على البورت 5173)
#    الأفضل تشغّلها من Herd: Herd → Processes → Vite → Start
#    (لأنها بتفضل شغّالة حتى لو اتقفلت نافذة الـ Terminal)
npm run dev

# 2) معالج الطوابير (لازم للمهام والتسلسلات وكل حاجة بتتبعت في الخلفية)
php artisan queue:work redis

# 3) المجدول (التقارير المجدولة، إيقاف التسلسلات، تنبيهات المهام، والمندوب الذكي)
php artisan schedule:work

# 4) (اختياري) سيرفر WebSockets للتحديثات اللحظية في الـ Inbox
php artisan reverb:start
```

> **مشكلة شائعة:** لو ظهر في الـ Console: `GET https://crm.test:5173/@vite/client net::ERR_CONNECTION_REFUSED`
> فمعناها إن `npm run dev` مش شغّال. شغّله، أو احذف ملف `public/hot` عشان النظام يستخدم ملفات البناء الجاهزة.

### 0.3 تجهيز البيانات والباقات

```bash
php artisan migrate
php artisan db:seed --class=PlanSeeder     # يحدّث ميزات الباقات (يضيف ai_sales و ai_sdr)
php artisan optimize:clear                 # يفرّغ الكاش بعد أي تغيير في الإعدادات
```

> 💡 بيانات نسخة التطوير الحالية فيها عميل تجريبي `client@spagreen.net` وباقة Business.
> لو لقيت أي وحدة فاضية (مهام/فورمات/تسلسلات)، أنشئ سجلاً يدوياً من الواجهة، أو شغّل
> `php artisan db:seed --class=DemoSeeder` **على قاعدة بيانات اختبار منفصلة** (مش قاعدة الإنتاج).

### 0.4 إعداد مزوّد الذكاء الاصطناعي (مطلوب لـ Phase 6 فقط)

1. ادخل بحساب العميل → القائمة الجانبية → **AI → مزوّدو AI** (`AI → AI Providers`).
2. أضف مفتاح **OpenAI** أو **Anthropic** أو **Gemini** واضغط **Save**.
3. من غير مفتاح صحيح، كل ميزات الذكاء الاصطناعي هترجّع رسالة "المزوّد غير مهيّأ" بدل النتيجة.

### 0.5 خريطة تفعيل الميزات حسب الباقة

| الميزة | Starter | Pro | Business |
| :--- | :---: | :---: | :---: |
| `ai_sales` (Smart Tags / Coach / At-risk / Call Summaries) | ❌ | ✅ | ✅ |
| `ai_sdr` (المندوب الذكي — إرسال تلقائي) | ❌ | ❌ | ✅ |

---

## 1️⃣ Phase 0 — الأساس والتحكم في الميزات (Feature Gates)

### أ. فحص صلاحيات وميزات الباقات
1. ادخل بحساب السوبر أدمن → `https://crm.test/admin/plans`.
2. استعرض الباقات:
   * **Starter**: ميزات زي `multi_pipeline` و `sales_reports` و `companies` و `ai_sales` = `false`.
   * **Business**: كل الميزات مفتوحة.
3. حاول تفتح رابط ميزة مقفولة مباشرة (مثلاً `https://crm.test/app/reports/sales`) وأنت على باقة Starter؛
   النظام يعترضك عبر Middleware اسمه `RequireFeature` ويرجّعك لصفحة الباقات `client.pricing`.

### ب. تجربة الدخول كعميل (Impersonation)
1. من لوحة الأدمن → `https://crm.test/admin/clients`.
2. اضغط **Login as client** جنب أي عميل.
3. هيظهر **شريط علوي** مكتوب فيه `Impersonating: [Client Name]` مع زر **Return to Admin**.
4. اضغط **Return to Admin** → ترجع للوحة الأدمن، والعمليّة تتسجل في `audit_logs`.

---

## 2️⃣ Phase 1 — أساس الـ CRM (Core Sales CRM)

### أ. الـ Pipelines المتعددة وإدارة المراحل
1. القائمة الجانبية → **Leads → Pipeline** (`/app/leads/pipeline`).
2. من الـ Dropdown أعلى الصفحة بدّل بين مسارات المبيعات (Pipelines).
3. اضغط **Pipeline Settings** (`Leads → Pipelines`) لإنشاء مسار جديد وتخصيص مراحله وألوانه وترتيبه.

### ب. بيانات الصفقات وأسباب الإغلاق (Won / Lost)
1. افتح كارت أي Lead → تبويب **Deal & Economics**.
2. عدّل: قيمة الصفقة (`deal_value`)، العملة، نسبة الاحتمالية (`probability`)، تاريخ الإغلاق المتوقع.
3. اسحب الكارت (Drag & Drop) لمرحلة **Won** أو **Lost** → هتظهر نافذة تطلب **سبب الإغلاق** (`Close Reason`).

### ج. المهام والتذكيرات (Tasks)
1. القائمة الجانبية → **Leads → Tasks** (`/app/tasks`).
2. جرّب الفلاتر: **Today / Overdue / Upcoming**.
3. اضغط **Add Task** وأنشئ مهمة (Call / Email / Meeting / Todo) واربطها بـ Lead وموظف.
4. بعد الإنشاء، حقل `next_follow_up_at` في الـ Lead بيتحدّث تلقائياً.

### د. الشركات والربط التلقائي بالدومين (Companies)
1. **Leads → Companies** → **Add Company** (اسم + دومين، مثال `acme.com`).
2. أنشئ Lead إيميله بنفس الدومين (مثال `john@acme.com`).
3. النظام بيربطه بالشركة تلقائياً عبر `CompanyMatchingService`.

### هـ. كشف التكرار والدمج (Duplicates & Merge)
1. **Leads → Duplicates** (`/app/leads/duplicates`).
2. اضغط **Scan** لو مفيش مجموعات ظاهرة.
3. اضغط **Merge** → قارن السجلين واختر السجل الأساسي (Master) → Confirm.
4. تأكد إن الأنشطة والمهام والرسائل والوسوم انتقلت للسجل الأساسي واختفى المكرر.

### و. جدول العملاء والعمليات الجماعية
1. **Leads → Lead Scraper / Table View** (`/app/leads`).
2. حدّد Leads بمربعات الاختيار → يظهر شريط **Bulk Actions**:
   Assign Owner / Change Stage / Add Tags / Export CSV.

---

## 3️⃣ Phase 2 — الجذب والتحويل (Capture & Conversion)

### أ. منشئ النماذج (Forms)
1. **Lead Capture → Forms** (`/app/sales/forms`) → **Create Form**.
2. من واجهة السحب والإفلات أضف حقول (اسم/بريد/هاتف/نص) وفعّل حماية الـ Spam.
3. بعد النشر انسخ الرابط العام بصيغة `/f/{slug}`.
4. افتحه في **نافذة تصفّح خفي**، املأه وأرسله → ارجع لـ **Leads** وستجد العميل اتسجّل فوراً.

### ب. صفحات الحجز (Booking Pages)
1. **Lead Capture → Booking Pages** (`/app/sales/booking`).
2. اضبط **Meeting Types** وأوقات العمل (`Availability Rules`) وأيام العطل ومدة الاجتماع والبافر.
3. انسخ رابط الحجز العام (`/book/{workspace}/{slug}`).
4. افتحه كعميل خارجي، اختر يوماً وساعة، وأكمل الحجز → تظهر شاشة تأكيد + أزرار **Cancel / Reschedule**،
   ويتسجّل الحجز فوراً على ملف العميل.

### ج. مصادر العملاء و UTM
1. **Lead Capture → Lead Sources** (`/app/sales/lead-sources`).
2. راقب **Health Status** وتاريخ آخر Lead لكل قناة (Form / Webhook / WhatsApp / Meta Leads).
3. افتح رابطاً فيه `?utm_source=facebook&utm_campaign=summer_sale` → سكريبت `tracker.js` بيحفظها
   ويربطها بالـ Lead لحد إتمام الصفقة (لمعرفة الـ ROI).

---

## 4️⃣ Phase 3 — التقارير والذكاء (Reports & Intelligence)

### أ. تقارير المبيعات
القائمة الجانبية → **Reports → Sales Reports** (`/app/reports/sales`) — والتبويبات:

| التقرير | الرابط | ماذا يعرض |
| :--- | :--- | :--- |
| Pipeline Funnel | `/app/reports/sales/pipeline` | القمع ومعدل التحويل وأماكن التكدّس (Bottlenecks). |
| Lead Volume | `/app/reports/sales/volume` | حجم تدفّق العملاء ومقارنة الفترات. |
| Source Performance | `/app/reports/sales/sources` | أي مصدر حقّق أعلى تحويل وإيراد. |
| Source Attribution | `/app/reports/sales/attribution` | ربط الـ UTM بالإيراد المغلق فعلياً. |
| Win / Loss | `/app/reports/sales/win-loss` | توزيع أسباب كسب/خسارة الصفقات. |
| Agent Performance | `/app/reports/sales/agents` | ترتيب الموظفين (إيراد مغلق / Win Rate). |
| Response Time | `/app/reports/sales/response` | سرعة الرد الأول (الوسيط و 90th percentile). |

### ب. التقارير المجدولة
1. **Reports → Sales Reports → Schedules** (`/app/reports/sales/schedules`) → **Create Schedule**.
2. اختر نوع التقرير والتكرار (يومي/أسبوعي/شهري) وبريد المستلمين.
3. شغّل الأمر يدوياً لتجربته فوراً:

```bash
php artisan reports:process-scheduled
```

### ج. ذكاء المحادثات والخط الزمني الموحّد
1. افتح كارت أي Lead → تبويب **Conversation** → اقرأ المحادثة وردّ من غير ما تسيب صفحة المبيعات.
2. تبويب **History** بيعرض **Unified Timeline** (رسائل + مهام + طلبات متجر + تغييرات المرحلة والقيمة).

### د. النقاط السلوكية (Behavioral Scoring)
1. تبويب **Score** في كارت الـ Lead بيعرض تفصيل النقاط.
2. التفاعلات بتزيد النقاط تلقائياً (رد +10، حجز +20، فورم +10، طلب شراء +25)، والتصنيف يتحدّث
   إلى **Cold / Warm / Hot**.
3. لتجربة الانخفاض الليلي:

```bash
php artisan leads:decay-scores
```

---

## 5️⃣ Phase 4 — محرك الإيراد (Revenue Engine)

### أ. تسلسلات المتابعة (Sequences)
1. **Sales → Sequences** (`/app/sales/sequences`) → **Create Sequence** (اسم + حالة Draft).
2. افتح **Builder** وأضف خطوة: القناة (Email / WhatsApp)، عدد ساعات التأخير، الموضوع، النص.
3. فعّل التسلسل (غيّر الحالة إلى **Active**).
4. اضغط **Enroll** وأضف Leads (لازم يكون عندهم بريد/رقم صالح).
5. شغّل المعالجة: `php artisan sequences:process` ثم تأكد إن الـ Queue Worker شغّال للإرسال.
6. افتح كارت الـ Lead → تبويب **History** → هتلاقي الرسالة المرسلة ضمن الخط الزمني، وحالة الـ Enrollment
   في صفحة التسلسل (**Active / Completed**).

**اختبار "التوقّف عند الرد" (Stop on Reply):**
1. أرسل **رسالة وارِدة (Inbound)** من نفس جهة الاتصال (عبر محاكاة الويبهوك أو ربط واتساب).
2. المستمع `StopSequencesOnReply` بيوقف كل التسلسلات النشطة للـ Lead تلقائياً مع `stop_reason = replied`.
3. تأكد إن حالة الـ Enrollment بقت **Stopped** وإن مفيش رسائل جديدة بتتبعت.

### ب. المنتجات + عروض الأسعار + الفواتير
1. **Sales → Products** (`/app/sales/products`) → **Add Product** (اسم، SKU، سعر، ضريبة، وحدة).
2. **Sales → Quotes** (`/app/sales/quotes`) → **Create Quote**:
   * اختر العميل، أضف بنود من المنتجات، طبّق خصماً، حدّد **Valid Until**.
3. من صفحة العرض:
   * **Send** لإرساله بالبريد (رابط عام `public_token` للمشاهدة).
   * **Accept** / **Reject** لمحاكاة رد العميل.
   * **PDF** لتحميل الملف.
4. اضغط **Convert to Invoice** → يتحوّل لعرض فاتورة.
5. **Sales → Invoices** (`/app/sales/invoices`) → افتح الفاتورة → **Record Payment** (مبلغ + طريقة) →
   الحالة تبقى **Paid** ويتسجّل الدفع في `invoice_payments`.

### ج. المكالمات و Click-to-Call
1. **Sales → Call Log** (`/app/sales/calls`).
2. اضبط المزوّد من **Call Settings**: **Twilio** أو **Telnyx** (BYO credentials — التكلفة على حساب العميل).
3. لتجربة الـ Click-to-Call بدون مزوّد حقيقي: استخدم **Log call** لتسجيل مكالمة يدوياً
   (الاتجاه، المدة، الحالة، الملاحظات) — وستظهر على الـ Lead.
4. افتح كارت الـ Lead → تبويب **Calls** → تأكد إن المكالمة مسجّلة على العميل.

---

## 6️⃣ Phase 5 — نضج المنصة (Platform Maturity)

> ادخل بحساب **عميل Administrator** عشان تشوف صفحات الحساب.

### أ. الخصوصية و GDPR
1. **Account → Privacy & GDPR** (`/app/settings/gdpr`).
2. **Retention policies**: حدّد مدة الاحتفاظ لكل نوع بيانات واحفظ.
3. **Erasure request**: اطلب حذف بيانات جهة اتصال → تظهر في القائمة مع إمكانية **Cancel**.
   التنفيذ الفعلي بيتأجّل (30 يوم) عبر المهمة `PurgeErasureRequestJob`.
4. **DPA**: نزّل اتفاقية معالجة البيانات من `/app/settings/gdpr/dpa`.
5. **Cookie consent**: افتح الموقع التسويقي (الـ Landing) وتأكد إن بانر الموافقة بيظهر.

### ب. دور المشاهد (Viewer — قراءة فقط)
1. **Account → Team** (`/app/team`) → ادعُ عضو بـ role = **Viewer**.
2. ادخل بحسابه وسجّل دخول:
   * يقدر يتصفّح كل الصفحات.
   * أي محاولة **كتابة/حفظ** بترجّع `403` (رسالة: حسابك للقراءة فقط) عبر Middleware `RestrictViewerWrites`.

### ج. حماية لوحة الأدمن بالـ IP (IP Allowlist)
1. الإعداد بيتحكّم فيه من إعدادات النظام (System Settings):
   * `security.ip_allowlist_enabled` = `1`
   * `security.ip_allowlist` = قائمة IP/CIDR (مثال: `127.0.0.1,10.0.0.0/8`).
2. لما تتفعّل والقائمة غير فاضية: أي IP مش مسموح بيلاقي `403`. (قائمة فاضية = معطّل، عشان محدش يتقفل بالغلط).

### د. التحقق من الدومين (SPF / DKIM)
1. **Broadcasting → Email Server** (`/app/email-server`).
2. اضغط **Verify domain** → النظام بيفحص SPF و DKIM عبر `DomainVerifier` وبيعرض النتيجة.

### هـ. فحص كلمات المرور المسرّبة (Breach Check)
1. فعّل الفحص: `PASSWORD_BREACH_CHECK=true` في `.env`.
2. جرّب التسجيل/تغيير كلمة مرور بكلمة معروفة مسرّبة → النظام بيرفضها عبر قاعدة `NotBreachedPassword`.

### و. فحص الصحة (Health Endpoint)
افتح في المتصفح:

| الرابط | يفحص |
| :--- | :--- |
| `/up` | حالة التطبيق العامة |
| `/healthz/db` | قاعدة البيانات |
| `/healthz/redis` | Redis |
| `/healthz/queue` | الطوابير |

### ز. النسخ الاحتياطي (Backups)
1. من لوحة الأدمن → **Backups** (`/admin/backups`).
2. **Create** لإنشاء نسخة، ثم **Verify** للتحقق منها، و **Download** للتنزيل.

### ح. الدومين الفرعي / الدومين المخصّص (Subdomain / Custom Domain)
1. الإعداد عبر `config('tenancy.base_domain')` + ربط دومين العميل المخصّص.
2. افتح `https://<subdomain>.<base_domain>` → يحدّد الـ Tenant تلقائياً من الـ Host عبر `ResolveTenantDomain`.
3. **اختبار أمني:** سجّل دخول عميل، وحاول تفتح دومين عميل تاني → النظام يرجّع `403` (لا يمكن استخدام مساحة عمل لا تنتمي إليها).

---

## 7️⃣ Phase 6 — الذكاء الاصطناعي للمبيعات (AI for Sales)

> ⚠️ قبل البدء: لازم **مزوّد AI مهيّأ** (قسم 0.4) وإن باقة العميل **Business**.
> كل الميزات ماعدا At-risk بتستهلك من عدّاد الـ Tokens (`ai_tokens_per_month`) وبتتسجّل في `ai_runs`.

### أ. Smart Tags + اقتراح الوسوم
1. افتح كارت أي Lead → محرر الوسوم (Tags Editor).
2. اضغط **Suggest tags** (`Suggest with AI`) → النظام يقترح وسوماً من سياق الـ Lead والمحادثة.
3. الوسوم **لا تُحفظ تلقائياً** — راجعها واقبل اللي عاجبك → بتُحفظ بأسمائها كما وافقت عليها.
4. **اختبار الرقابة:** جرّب نفس الخطوات على باقة بدون `ai_sales` → الطلب يترفض.

### ب. المدرّب الذكي (AI Lead Coach)
1. افتح كارت أي Lead → تبويب **AI Coach** (`tab_coach`).
2. اضغط **Get advice** (`coach_button`) → هيظهر:
   * **Next step** (الخطوة الجاية المقترحة).
   * **Ready-to-send reply** (رد جاهز مع زر **Copy**).
   * **Why the score is what it is** (سبب النقاط).
   * لو العميل في خطر يظهر تنبيه **This lead is at risk of going cold**.
3. **Ask again** لإعادة التوليد، وكل استدعاء يُحتسب على العدّاد.

### ج. كشف العملاء المعرّضين للفقد (At-risk Detection)
* **حتمي بدون تكلفة AI** (مش بيعتمد على مكالمة نموذج)، وبيعتمد على إشارات حقيقية وسجل `lead_score_events`.
1. افتح **Leads → Pipeline**.
2. الـ Leads المعرّضة للخطر يظهر عليها شعار **At risk** بلون تحذيري.
3. افتح الكارت → تبويب **Score** → قسم **At-risk signals** بيعرض الأسباب (مثال: No activity / Score decay /
   Overdue follow-up / Stalled stage / Past close date / Cold band).
4. **إشارات تجريبية لتفعيل الحالة:** Lead نشاطه قديم، أو متأخر في نفس المرحلة، أو تاريخ إغلاقه عدّى، أو تقييمه Cold.

### د. ملخّصات المكالمات (Call Summaries)
> ملاحظة: النظام **مش بيفرّغ الصوت تلقائياً**. التفريغ (Transcript) بتلصقه/تحفظه يدوياً، والـ AI بيلخّصه.
1. **Sales → Call Log** (`/app/sales/calls`) → افتح مكالمة (أو سجّل واحدة).
2. اضغط **Summarise this call** → الصق نص المكالمة في خانة **Transcript**.
3. اضغط **Generate summary** → يظهر ملخص + **Action items** + **Sentiment** (Positive/Neutral/Negative)،
   ويتسجّل تاريخ التوليد (**Regenerate** لإعادة التلخيص).
4. نفس اللوحة موجودة في كارت الـ Lead → تبويب **Calls**.
5. التفريغ والملخص بيلبسوا تنقيح (masking) في وضع الديمو.

### هـ. المندوب الذكي (AI SDR) — المتابعة التلقائية
> بيمسك Leads تم التواصل معهم **ولم يردّوا**، ويكتب ويبعت المتابعة التالية تلقائياً (بريد/واتساب) — لحد ما يرد إنسان.
1. القائمة الجانبية → **AI → المندوب الذكي** (`AI → AI SDR`, `/app/sales/ai-sdr`).
2. راجع الجدارات (Guard-rails) وعدّلها:
   * **Kill switch** (مفتاح التشغيل/الإيقاف).
   * **Channel** (Email / WhatsApp).
   * **Daily cap** (حد الرسائل اليومي).
   * **Per-lead cap** (حد الرسائل للعميل الواحد).
   * **Hours between messages** (فترة الهدوء قبل المتابعة التالية).
3. اضغط **Save settings**.
4. **من الشروط قبل التشغيل:**
   * عند العميل رسالة **صادرة (Outbound)** ولم يرد عليها برسالة **واردة (Inbound)** أحدث.
   * القناة مفعّلة عند العميل (Opt-in)، وفيها بريد صالح لو القناة Email.
   * **ساعات العمل مفتوحة** (`WorkspaceBusinessHours`) و **Queue Worker شغّال**.
5. اضغط **Run now** (أو استنى المجدول) → يتدرّج Run في الطابور، وتظهر المتابعة في **History** بتاع العميل
   ومع سبب الإرسال (reason).
6. **اختبار الرقابة (Guard-rails):**
   * اقفل الـ Kill switch → **Run now** ما يبعتش حاجة.
   * خلّي العميل في حالة Won/Lost → يتخطّى.
   * عميل ردّ (`Inbound` أحدث) → يتخطّى.
   * برّه ساعات العمل → لا يُرسل شيء.
   * تجاوز الحد اليومي/حد العميل أو فترة الهدوء → يتخطّى.
7. **الشريط المخفي:** التسلسل النظامي للمندوب (`source = ai_sdr`) **لا يظهر** في صفحة Sequences ولا
   في عدّاد باقة العميل — لوحده بمعدّل التسليم (SMTP + صندوق الوارد + إلغاء الاشتراك).

**أوامر مفيدة للمندوب الذكي:**

```bash
php artisan ai-sdr:run                 # يدرج Runs لكل مساحة عمل مفعّلة
php artisan ai-sdr:run --workspace=1   # لمساحة عمل محدّدة
```

---

## 8. جدول تحقق سريع (Checklist)

| # | المرحلة | أسرع طريقة للتأكد | ✅ |
| :--- | :--- | :--- | :---: |
| 0 | Feature Gates | دخول كعميل بباقة Starter ومحاولة فتح Sales Reports → ترجيع للـ Pricing | ☐ |
| 0 | Impersonation | زر **Login as client** + شريط **Return to Admin** | ☐ |
| 1 | Pipeline / Deals | سحب كارت لـ Won/Lost واختيار سبب الإغلاق | ☐ |
| 1 | Tasks | إنشاء مهمة وظهور تنبيه/تحديث `next_follow_up_at` | ☐ |
| 1 | Companies | إيميله بنفس الدومين → ربط تلقائي | ☐ |
| 1 | Duplicates | Scan + Merge ونقل الأنشطة | ☐ |
| 2 | Forms | إرسال الفورم من رابط عام → Lead جديد فوراً | ☐ |
| 2 | Booking | حجز موعد → تأكيد + Cancel/Reschedule | ☐ |
| 2 | Lead Sources | ظهور UTM على الـ Lead القادم من رابط إعلاني | ☐ |
| 3 | Reports | فتح تقارير Sales السبعة + Schedules | ☐ |
| 3 | Scoring | تفاعل → زيادة النقاط وتغيّر Cold/Warm/Hot | ☐ |
| 4 | Sequences | إرسال خطوة + توقّف عند الرد (Stopped/replied) | ☐ |
| 4 | Quotes → Invoices | عرض → قبول → تحويل لفاتورة → تسجيل دفع | ☐ |
| 4 | Calls | تسجيل مكالمة وظهورها على الـ Lead | ☐ |
| 5 | GDPR | طلب حذف + Retention + تنزيل DPA | ☐ |
| 5 | Viewer | عضو Viewer → أي كتابة ترجّع 403 | ☐ |
| 5 | Health | فتح `/up` و `/healthz/*` | ☐ |
| 5 | Backups | إنشاء نسخة + Verify + Download | ☐ |
| 6 | Smart Tags | Suggest tags + قبول الوسوم يدوياً | ☐ |
| 6 | AI Coach | Get advice → Next step + Reply + سبب النقاط | ☐ |
| 6 | At-risk | شعار At risk على الكارت + أسباب في تبويب Score | ☐ |
| 6 | Call Summaries | لصق Transcript → Generate summary + Action items | ☐ |
| 6 | AI SDR | تشغيل → متابعة في الـ History + كل الرقابات | ☐ |

---

## 9. مشاكل شائعة وحلولها

| المشكلة | السبب | الحل |
| :--- | :--- | :--- |
| `ERR_CONNECTION_REFUSED` على `:5173` | Vite مش شغّال (أو `public/hot` قديم) | شغّل Vite من **Herd → Processes → Vite → Start** (أو `npm run dev`) |
| `Unable to locate file in Vite manifest` | ملفات البناء قديمة | `npm run build` |
| رسالة "ميزة غير متوفرة في باقتك" (402) | الـ Plan Gate قافل الميزة | رقّي الباقة أو عدّل `feature_flags` للعميل |
| ميزات AI بترجّع "المزوّد غير مهيّأ" | مفيش مفتاح AI | أضف مفتاح من **AI → مزوّدو AI** |
| التسلسل/المندوب ما بيبعتش | Queue Worker مش شغّال | `php artisan queue:work redis` |
| التقارير المجدولة ما بتوصلش | المجدول مش شغّال | `php artisan schedule:work` أو شغّل الأمر يدوياً |
| تغييرات الإعدادات مش ظاهرة | كاش الإعدادات | `php artisan optimize:clear` |
| المندوب الذكي مش بيكتب | العميل ردّ / برّه ساعات العمل / تجاوز الحدود | راجع الشروط في قسم 7-هـ |

---

## 10. تشغيل الاختبارات الآلية (اختياري للمطوّر)

```bash
# كامل مجموعة الاختبارات (تأكد من تشغيل عملية PHPUnit واحدة فقط في الوقت نفسه)
php artisan test --compact

# اختبارات الذكاء الاصطناعي فقط
php artisan test --compact tests/Feature/AI

# اختبارات المبيعات فقط
php artisan test --compact tests/Feature/Sales

# تنسيق الكود
vendor/bin/pint --dirty
```

> **ملاحظة:** الاختبارات على قاعدة بيانات منفصلة (`wm3_test`) — متشغّلش عمليتَي PHPUnit في نفس الوقت.
