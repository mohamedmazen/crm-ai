# خطة تطوير WhatsMine CRM لمطوّر منفرد

مبنية على مقارنة WhatsMine مع LeadHub — أكتوبر 2026

## إزاي تقرا الخطة

- **P0:** لازم عشان المنتج يبقى Sales CRM حقيقي. **P1:** بيرفع قيمة المنتج وسعره. **P2:** نضج المنصة. **P3:** مؤجل.
- **الجهد:** S = 2–4 أيام، M = 1–2 أسبوع، L = 3–4 أسابيع. التقديرات لمطوّر واحد شغال فول تايم، وبتشمل الاختبارات (tests) وتظبيط الـ UI.
- **المدة الكلية:** حوالي 10–12 شهر لكل الميزات. لكن بعد حوالي **5–6 شهور** (نهاية Phase 2) يبقى عندك Sales CRM قابل للبيع، وباقي المراحل تحسينات فوقه.

## قواعد الشغل لوحدك

1. **الـ Schema الأول.** صمم جداول الـ CRM كلها قبل ما تكتب UI، لأن تغيير الجداول بعد وجود بيانات حقيقية مكلف.
2. **Feature flag لكل ميزة من أول يوم.** كل ميزة جديدة تتقفل وراها علامة تتحكم فيها الباقة، عشان متدّيش حاجات ببلاش.
3. **شغّل Vertical slices.** الميزة تخلص كاملة (Backend + UI + Test) قبل ما تبدأ اللي بعدها، ومتفتحش 3 ميزات نص خلصانة.
4. **Definition of Done.** الميزة مخلصة لما: Migration شغال ومعاه Backfill للبيانات القديمة، وفيه Tests، ومتقفلة بـ Feature flag، وفيها صفحة توثيق قصيرة.
5. **Modules منفصلة.** حط كل حاجة في `app/Modules/Leads` و`app/Modules/Sales` و`app/Modules/Reporting` عشان تفصل الـ CRM عن الرسائل.
6. **Release كل 2–3 أسابيع.** حتى لو صغير، عشان تاخد Feedback وتفضل شايف التقدم.

## ترتيب الأولويات الكامل

| # | الميزة | الأولوية | الجهد | المرحلة |
| --- | --- | --- | --- | --- |
| 0 | بنية Plan gates + Seat caps | P1 | S–M | Phase 0 |
| 1 | أكتر من Pipeline + بيانات الصفقة (Deal) + Win/Loss reasons + Lead status | P0 | M | Phase 1 |
| 2 | ملكية الـ Lead (Assigned user) + Round-robin | P0 | S | Phase 1 |
| 3 | المهام والتذكيرات (Tasks) | P0 | M | Phase 1 |
| 4 | Tags + Bulk actions + Import/Export للـ Leads | P0 | M | Phase 1 |
| 5 | حقول مخصصة للـ Leads (Custom fields) | P0 | M | Phase 1 |
| 6 | الشركات (Companies) | P0 | M | Phase 1 |
| 7 | كشف التكرار والدمج (Dedupe & Merge) | P0 | M | Phase 1 |
| 8 | Form builder + Embed widget | P0 | L | Phase 2 |
| 9 | UTM tracking + Source attribution | P1 | M | Phase 2 |
| 10 | صفحة حجز مواعيد عامة (Booking) | P0 | L | Phase 2 |
| 11 | مصادر Leads أساسية + IMAP + Source health | P1 | L | Phase 2 |
| 12 | تقارير المبيعات + تصدير + تقارير مجدولة | P1 | L | Phase 3 |
| 13 | Conversation Intelligence (ربط الـ Lead بالمحادثات) | P1 | M | Phase 3 |
| 14 | Lead scoring سلوكي | P1 | M | Phase 3 |
| 15 | Business hours + Date trigger + Lead triggers إضافية | P1 | S | Phase 3 |
| 16 | Email sequences | P1 | L | Phase 4 |
| 17 | منتجات + Quotes + Invoices | P1 | L | Phase 4 |
| 19 | Click-to-call + Call log | P1 | L | Phase 4 |
| 20 | GDPR suite (DPA, Cookie consent, Erasure, Retention) | P2 | M | Phase 5 |
| 21 | Subdomain لكل Tenant | P2 | M | Phase 5 |
| 22 | أدوار إضافية (Viewer) + Section hiding | P2 | M | Phase 5 |
| 23 | أمان وتشغيل: IP allowlist، Domain verification، Breach-check، Health endpoint، Backups UI | P2 | S لكل واحدة | Phase 5 |
| 24 | AI للمبيعات: Lead Coach، At-risk، Smart tags، AI SDR، Call summaries | P2 | M–L | Phase 6 |
| 25 | Affiliate، Marketplace، Shared-hosting installer، Industry packs، Customer portal، Feature requests | P3 | L | مؤجل |

## نقاط التحقق (Milestones)

| بعد | اللي يبقى عندك | تقدر تقول للعميل |
| --- | --- | --- |
| Phase 1 (≈ شهرين ونص) | CRM كامل بدون جذب | «عندنا Pipeline وصفقات ومهام وشركات» |
| Phase 2 (≈ 5–6 شهور) | جذب وحجز | «من الإعلان للفورم للحجز في مكان واحد» |
| Phase 3 (≈ 7 شهور) | تقارير + ذكاء من المحادثات | «بنعرف العميل اشترى إيه وسأل عن إيه على واتساب» |
| Phase 4 (≈ 10 شهور) | من Lead لحد الفلوس | «Sequences وعروض أسعار وفواتير ومكالمات» |

## Phase 0 — الأساس (أسبوع إلى أسبوعين)

**الهدف:** تجهز البنية اللي كل الميزات الجاية هتتركب عليها.

### 0.1 بنية Plan gates وSeat caps (S–M)

**الوضع الحالي:** عندك حدود كتير في الباقات لكن 6 بس متفعلة (campaigns, WhatsApp messages, AI tokens, knowledge bases, social posts, lead credits)، وميزة واحدة متقفلة هي `white_label`. حدّ المستخدمين (seat cap) مش بيتطبق.

**اللي تعمله:**

- اعمل Helper واحد، مثلاً `Feature::enabled('forms')`، يقرأ من الباقة الحالية للـ Client.
- اعمل Middleware اسمه `RequireFeature` تحطه على Routes أي ميزة جديدة.
- وسّع `EnforceLimit` ليغطي حد المستخدمين (users) وفي نقطة إنشاء/دعوة مستخدم تمنع لو الحد خلص.
- ضيف في `PlanSeeder` مفاتيح الميزات الجديدة: `multi_pipeline`, `tasks`, `forms`, `booking`, `sequences`, `quotes`, `sales_reports`, `api_access`، وكلها شغالة على الباقات حسب اختيارك.
- ضيف رسالة Upgrade واضحة في الـ UI لما العميل يضغط على ميزة مقفولة.

**يتقفل لما:** ميزة تجريبية تتقفل وتتفتح بتغيير الباقة، ومستخدم زيادة عن الحد بيتمنع، وفيه Test لكل ده.

### 0.2 تجهيز بيئة الشغل (S)

- اعمل Module Skeleton لـ `Sales` و`Reporting`.
- اعمل Seeder لبيانات تجريبية (Demo data): 3 pipelines و200 lead و20 شركة، عشان تختبر عليها كل ميزة.
- جهّز Backup قبل أي Migration على قاعدة بيانات حقيقية.

## Phase 1 — أساس الـ CRM (6–10 أسابيع)

**الهدف:** الـ Lead يتحول من «كارت متابعة» لسجل مبيعات حقيقي (system of record). الترتيب مهم لأن كل خطوة بتبني على اللي قبلها.

### 1.1 أكتر من Pipeline + بيانات الصفقة + Win/Loss (M، حوالي 8–10 أيام)

**Schema:**

- جدول جديد `lead_pipelines` (workspace\_id, name, is\_default, sort\_order).
- أضف `pipeline_id` و`default_probability` على جدول مراحل الـ Pipeline الحالي.
- أضف على `leads`: `pipeline_id`, `deal_value`, `currency`, `probability`, `expected_close_date`, `won_at`, `lost_at`, `close_reason_id`, و`status` (new / contacted / qualified / converted / lost) منفصل عن المرحلة.
- جدول `lead_close_reasons` (type: won أو lost، والاسم).

**Backfill:** لكل workspace قديم اعمل Pipeline افتراضي، واربط بيه المراحل والـ Leads الموجودة.

**Backend:** عدّل `PipelineManager` يشتغل بـ `pipeline_id`. لما الـ Lead يتحرك لمرحلة Won أو Lost اطلب السبب وسجّل التاريخ. خلي الـ probability تتملى تلقائي من المرحلة. ابعت `pipeline_id` مع أحداث stage changed/won/lost الموجودة في الـ Automation.

**UI:**

- Dropdown لتبديل الـ Pipeline فوق البورد.
- قيمة الصفقة على الكارت، ومجموع القيمة وقيمة مرجحة (Weighted = value × probability) أسفل كل عمود.
- صفحة إعدادات لإدارة الـ Pipelines والمراحل والأسباب.
- Modal بيسأل عن السبب عند Won/Lost.

### 1.2 ملكية الـ Lead + Round-robin (S، 3–4 أيام)

- أضف `assigned_user_id` و`assigned_at` على `leads`.
- إعداد على كل Pipeline: وضع التوزيع (Manual / Round-robin / Load-balanced). جدول صغير يحفظ آخر مستخدم اتعيّن له.
- التوزيع بين مستخدمي الـ workspace اللي دورهم staff، ويتجاهل غير النشطين.
- فلتر «Leads بتاعتي» وفلتر بالمسؤول، وصورة المسؤول على الكارت.
- Notification للموظف لما يتعيّن له Lead، وتسجيل التعيين في Activities.
- اربط Action التعيين الموجود في الـ Automation بنفس الخدمة.

### 1.3 المهام والتذكيرات — Tasks (M، 8–10 أيام)

- جدول `lead_tasks`: lead\_id, assigned\_user\_id, title, description, type (call / email / meeting / todo), priority, due\_at, completed\_at, created\_by.
- متحذفش `next_follow_up_at`، خليه يتحسب من أقرب Task مفتوحة.
- **UI:** تبويب Tasks في صفحة الـ Lead، وصفحة «مهامي» بفلاتر (النهاردة / متأخرة / قادمة) وBadge للمتأخر. ابدأ بـ List view، والـ Calendar view تتأخر لحد آخر المرحلة.
- **Reminders:** Scheduled command بيشتغل كل 5 دقايق ويبعت إشعار (web push أو email) قبل الميعاد.
- ضيف Action اسمه «Create task» في الـ Automation.

### 1.4 Tags + Bulk actions + Import/Export (M، 8–10 أيام)

- **Tags:** جدول `lead_tags` وPivot، وخليها بنفس شكل tags الـ Contacts.
- **Table view:** شاشة جدول للـ Leads جنب الـ Kanban، بفلاتر وفرز وأعمدة قابلة للاختيار.
- **Bulk actions:** تحديد متعدد ثم (تعيين / Tag / نقل مرحلة / حذف / تشغيل Automation). نفّذها كـ Queued jobs بـ chunking مع Progress.
- **Import:** رفع CSV أو XLSX ← Mapping للأعمدة ← Preview أول 10 صفوف ← تنفيذ في Queue ← تقرير أخطاء يتحمّل. اختيار سلوك التكرار: Skip / Update / Create.
- **Export:** يحترم الفلاتر الحالية، ويتعمل في Queue ويوصل للمستخدم لينك تحميل.

### 1.5 الحقول المخصصة — Custom fields (M، 8–10 أيام)

- `custom_fields`: entity\_type (lead / company)، key، label، type (text / number / date / select / multiselect / checkbox / url)، options، required، sort\_order.
- `custom_field_values`: entity\_type، entity\_id، field\_id، value. **نصيحتي** تستخدم جدول قيم بدل JSON عشان الفلترة والتقارير بتبقى أسهل.
- UI: صفحة إعدادات للحقول، وظهورها في صفحة الـ Lead وفورم الإنشاء وكأعمدة وفلاتر في الـ Table.
- ادعمها في Import mapping وExport وشروط الـ Automation.
- حد أقصى لعدد الحقول حسب الباقة.

### 1.6 الشركات — Companies (M، 8–10 أيام)

- جدول `companies`: workspace\_id, name, domain, industry, size, phone, website, address, owner\_id.
- أضف `company_id` على `leads`.
- الحقول المخصصة من 1.5 تشتغل على الشركات كمان (لأنها Polymorphic).
- **UI:** قائمة الشركات وصفحة تفاصيل بتعرض كل الـ Leads والصفقات والمهام والنشاط. وفي فورم الـ Lead اختيار أو إنشاء شركة بـ Autocomplete.
- ربط ذكي: لو إيميل الـ Lead على دومين شركة موجودة، اقترح الربط.
- Import: عمود الشركة يتطابق بالاسم أو الدومين.

### 1.7 كشف التكرار والدمج — Dedupe & Merge (M، 8–10 أيام)

- **المطابقة:** الإيميل (lowercase) والتليفون (يتحوّل لصيغة E.164)، واختياري تشابه الاسم + الشركة.
- **وقت الإنشاء/الاستيراد:** تحذير «فيه Lead مشابه» مع خيار فتحه أو الاستمرار.
- **صفحة «التكرارات»:** Command مجدول بيجمّع المجموعات المتشابهة وبتراجعها يدوي.
- **Merge:** اختار Lead أساسي، وقرر لكل حقل أنهي قيمة تتحفظ، وانقل الـ Activities والـ Tasks والـ Tags والمحادثات للأساسي. سجّل العملية في الـ Audit log.

### معيار الانتهاء للمرحلة

- Workspace قديم بيعدّي الـ Migrations من غير ما يخسر بيانات.
- كل صفحة جديدة وراها Feature flag.
- Seeder تجريبي بيغطي كل الحالات.
- جرّبته على 3–5 عملاء حقيقيين لمدة أسبوعين وجمعت ملاحظاتهم.

## Phase 2 — الجذب والتحويل (10–12 أسبوع)

**الهدف:** الـ Leads تدخل لوحدها من الإعلانات والفورمات، وتتحول لمواعيد من غير ما العميل يطلع برّه المنصة.

### 2.1 Form builder + Embed widget (L، 3–4 أسابيع)

**Schema:**

- `forms`: الاسم، slug، الحالة، وإعدادات (Pipeline والمرحلة المستهدفة، التعيين، Tags، رسالة الشكر أو Redirect، الحماية من السبام).
- `form_fields`: النوع، label، key، required، options، الترتيب، وربط الحقل بحقل Lead أو Custom field (`maps_to`).
- `form_submissions`: الـ payload، `lead_id`، IP، بيانات UTM، التاريخ.

**Public routes:**

- صفحة مستقلة `/f/{slug}`.
- Embed بـ iframe أو سطر JS: `/embed/f/{slug}.js`.
- POST للإرسال مع Rate limiting وحماية سبام. استخدم **Cloudflare Turnstile أو reCAPTCHA v3** مع Honeypot، وفعّل CORS للـ Embed بس.

**عند الإرسال:** أنشئ الـ Lead من نفس خدمة الإنشاء (فيها Dedupe من 1.7)، وطبّق التعيين (1.2) والـ Pipeline والـ Tags، وشغّل Trigger `form_submitted` الموجود عندك، وابعت إشعار.

**قسّمها لـ 3 إصدارات:**

1. **v1 (أسبوع ونص):** حقول أساسية (نص، إيميل، تليفون، textarea، select، checkbox، تاريخ) + صفحة مستقلة + iframe + إنشاء Lead.
2. **v2 (أسبوع):** سطر JS + رفع ملفات + Turnstile/reCAPTCHA + Floating widget للمواقع.
3. **v3 (أسبوع):** Multi-step + Conditional logic + Analytics (مشاهدات، إرسالات، نسبة التحويل) + QR code.

**بناء الـ Builder:** استخدم مكتبة Drag & Drop جاهزة (زي dnd-kit) ومعاينة حية جنب التعديل.

### 2.2 UTM tracking وAttribution (M، حوالي أسبوع)

- سكريبت صغير `tracker.js` يلقط `utm_source / medium / campaign / term / content` والـ referrer وصفحة الدخول، ويخزنها في Cookie لمدة 30 يوم ويحقنها في الفورم تلقائي.
- عمود `source` على الـ Lead + جدول أو JSON للـ Attribution (First touch وLast touch).
- ظهور المصدر في صفحة الـ Lead وفلتر بالمصدر في الـ Table.

### 2.3 صفحة الحجز العامة — Booking (L، حوالي 3 أسابيع)

**Schema:**

- `meeting_types`: الاسم، slug، المدة، Buffer قبل/بعد، نوع المكان (Google Meet / تليفون / حضوري)، حد أقصى يومي، أقل إشعار مسبق، نافذة الحجز (كام يوم قدام)، وأسئلة اختيارية.
- `availability_rules`: لكل مستخدم أيام وساعات الشغل وTimezone. و`availability_overrides` للإجازات.
- `bookings`: نوع الاجتماع، `lead_id`، المضيف، البداية والنهاية، الحالة، لينك Meet، وToken للإلغاء.

**المنطق:**

- Service لتوليد الـ Slots المتاحة وبتحسب الـ Timezone صح (أهم نقطة وأكتر مكان فيه أخطاء).
- تقاطع الأوقات مع الحجوزات القائمة ومع Google Calendar (عندك OAuth وإجراءات `google_meet` جاهزة، فأعد استخدامها).
- الصفحة العامة `/book/{workspace}/{slug}`: اختيار يوم ← وقت ← بيانات ← تأكيد.
- لينكات إلغاء وإعادة جدولة.
- بعد الحجز: أنشئ أو حدّث الـ Lead، وأضف Activity وTask.
- **تذكيرات بالإيميل وبـ WhatsApp** (ده فرق مهم عن LeadHub، عشان عندك الواتساب).

**نصيحة:** ابدأ بـ Google Calendar بس. Outlook وRound-robin بين أكتر من مضيف ممكن يتأجلوا.

### 2.4 مصادر الـ Leads (L، حوالي 3 أسابيع)

متحاولش تعمل 19 مصدر. رتّبهم بالجهد والعائد:

| الترتيب | المصدر | الجهد | ملاحظة |
| --- | --- | --- | --- |
| 1 | Webhook/API endpoint عام + Field mapping | S | بيغطي Zapier وMake وTypeform وJotForm وCalendly وGoogle Ads وTikTok من غير تكامل مخصص |
| 2 | إنشاء Lead تلقائي من أي محادثة واتساب جديدة | S | سهل جداً لأن المحادثات عندك أصلاً، وده ميزة LeadHub مش هتقدر تقلدها |
| 3 | Meta Lead Ads (فيسبوك وإنستجرام) | M | محتاج Meta App Review، **قدّم عليه من أول أسبوع** لأنه بياخد وقت |
| 4 | IMAP email parsing | M | Command كل 5 دقايق يقرأ صندوق الـ workspace ويحوّل الإيميل لـ Lead، ويمنع التكرار بـ Message-ID |
| 5 | Source health | S | جدول `lead_sources` فيه الحالة وآخر استلام وآخر خطأ، وصفحة بتظهر المصدر المقطوع |

### معيار الانتهاء للمرحلة

- فورم منشور على موقع حقيقي بيدخل Lead بالـ UTM الصحيح.
- حجز كامل من صفحة الحجز لحد Google Meet والتذكير.
- المصادر الخمسة شغالة، وصفحة Source health بتبين الأعطال.
- كله وراه Feature flags وحدود باقات (عدد الفورمات، الـ Submissions الشهرية).

## Phase 3 — التقارير والذكاء من المحادثات (6–8 أسابيع)

**الهدف:** تثبت للعميل إن المنصة بتجيب فلوس، وتبني الميزة اللي LeadHub مش هيقدر ينسخها.

### 3.1 تقارير المبيعات (L، حوالي 3 أسابيع)

**شرط أساسي:** جدول `lead_stage_history` (lead\_id, from\_stage, to\_stage, user\_id, changed\_at). لازم يتسجل من Phase 1.1 عشان تقارير الـ Funnel والـ Bottlenecks تشتغل على بيانات قديمة. لو نسيته، ابدأ تسجيله فوراً.

رتّب التقارير من الأسهل للأصعب:

1. **Response time:** الوسيط وP90 وتوزيع بالـ Buckets، من بيانات `first_response_at` الموجودة (S).
2. **Lead volume:** عدد الـ Leads عبر الزمن (S).
3. **Source performance:** Leads والـ Won ونسبة التحويل والقيمة لكل مصدر (S).
4. **Win/Loss reasons** (S).
5. **Pipeline funnel:** نسبة الانتقال بين المراحل (M).
6. **Bottlenecks:** متوسط الأيام في كل مرحلة (M).
7. **Agent performance / Revenue by rep:** قيمة الـ Won وPipeline ونسبة الكسب ومتوسط دورة الصفقة (M).
8. **Source attribution:** من UTM للإيراد (M).

**كلهم بنفس الفلاتر:** فترة زمنية، Pipeline، موظف، مصدر.

**التصدير والجدولة:**

- CSV بـ Streaming عبر Queue، وPDF بنفس مكتبة فواتير الاشتراكات الحالية.
- جدول `report_schedules` (التقرير، الفلاتر، التكرار يومي/أسبوعي/شهري، المستلمين، الصيغة، `next_run_at`) وCommand بيرسل بالإيميل.
- المكان في الـ UI: قسم «Reports» جديد، متقفل بـ Feature `sales_reports`.

**الأداء:** حط Indexes على (workspace\_id, created\_at, status, pipeline\_id)، ولو التقارير تقلت اعمل جداول ملخصة تتحدّث بالليل.

### 3.2 Conversation Intelligence (M، حوالي أسبوعين)

ده اللي بيفرّقك عن أي CRM مبيعات تاني.

- **ربط الـ Lead بالمحادثة:** الـ Contact عنده رابط للـ Lead، فاعرض تبويب «المحادثة» جوه صفحة الـ Lead (معاينة + زر «افتح في الـ Inbox»)، وزر «حوّل لـ Lead» جوه الـ Inbox بضغطة واحدة.
- **Unified timeline:** رسائل واتساب، رسائل السوشيال، الحملات، الطلبات، والمهام في Feed واحد.
- **قيمة الصفقة من التجارة:** لو الـ Contact عنده سلة أو طلبات، املأ `deal_value` تلقائي واعرض تبويب «الطلبات».
- **SLA لكل Lead:** Badge لو العميل مستني رد أكتر من X ساعة، و Trigger جديد «مفيش رد بعد X ساعة».
- **Trigger «العميل رد»:** ينقل الـ Lead لمرحلة أو يعمل Task (اختياري لكل Pipeline).

### 3.3 Lead scoring سلوكي (M، حوالي أسبوع)

- جدول `lead_score_events` (lead\_id, event, points, reason, created\_at).
- نقاط من أحداث فعلية: رد على رسالة (+)، فتح أو ضغط في حملة (+)، سؤال عن السعر (+)، حجز موعد (+)، إرسال فورم (+)، طلب شراء (+)، عدم نشاط X يوم (−).
- Job ليلي لتقليل النقاط مع الوقت (Decay).
- في الـ UI: «ليه الـ Score ده؟» بتعرض آخر الأحداث. وتحافظ على نطاقات Hot / Warm / Cold الحالية.

### 3.4 تحسينات الـ Automation (S، 3–4 أيام)

- **Business hours:** جدول ساعات الشغل لكل workspace، والرسائل برّه الساعات تتأجل لحد ما يفتح.
- **Triggers جديدة:** Lead اتعيّن، Task متأخرة، Score عدّى حد معين، مفيش نشاط N يوم، والـ Date-reached (عيد ميلاد أو تاريخ في حقل مخصص).
- **Actions جديدة:** إنشاء Task، نقل لـ Pipeline مختلف، تعديل قيمة الصفقة.

### معيار الانتهاء للمرحلة

- 8 تقارير بتشتغل على بيانات حقيقية وبتتصدّر وبتتبعت بالجدولة.
- فتحت محادثة واتساب وحوّلتها لـ Lead وشفت Timeline كامل في مكان واحد.
- كل ده متقفل على باقة Sales.

## Phase 4 — محرك الإيراد (10–12 أسبوع)

**الهدف:** تقفل الدايرة من أول Lead لحد الفلوس.

### 4.1 Email sequences (L، حوالي 3 أسابيع)

**Schema:**

- `sequences`: الاسم، الحالة، `stop_on_reply`، `stop_on_won`، احترام ساعات الشغل.
- `sequence_steps`: الترتيب، التأخير (ساعات أو أيام)، العنوان، المحتوى، شرط اختياري.
- `sequence_enrollments`: lead\_id، sequence\_id، الخطوة الحالية، الحالة، `next_run_at`.
- `sequence_events`: sent / open / click / reply / bounce / unsubscribe.

**المحرك:**

- Command بيشتغل كل دقيقة، ياخد الـ Enrollments المستحقة ويرسل بالـ SMTP الخاص بالـ workspace.
- Tracking للفتح والضغط (أعد استخدام اللي عندك في Broadcasts).
- **كشف الرد:** بيعتمد على IMAP من 2.4، فلازم يكون جاهز قبل ما تبدأ.
- توقف تلقائي عند الرد أو عند Won (اربطه بحدث تغيير المرحلة).
- متغيرات زي `{{first_name}}` ولينك Unsubscribe إجباري.

**UI:** Builder بخطوات متتالية، وتسجيل Lead في Sequence (يدوي أو Bulk أو من Automation)، وتقرير لكل Sequence (مسجلين، مرسل، Open rate، Reply rate).

**ميزة تفرّقك:** خلّي الخطوة تقدر تكون **رسالة WhatsApp (Template)** مش إيميل بس. Sequence متعدد القنوات ده LeadHub مش عنده.

### 4.2 المنتجات وعروض الأسعار والفواتير (L، 3–4 أسابيع)

**Schema (منفصل عن فواتير الاشتراكات الحالية):**

- `products`: الاسم، SKU، السعر، العملة، الضريبة، الوحدة.
- `quotes`: رقم تسلسلي لكل workspace، `lead_id`، `company_id`، الحالة (draft / sent / viewed / accepted / rejected / expired)، `valid_until`، الخصم، الملاحظات والشروط، وToken للصفحة العامة.
- `quote_items`، و`invoices` (مربوطة بـ `quote_id`، تاريخ الاستحقاق، `paid_at`)، و`invoice_payments` لتسجيل الدفعات يدوي.

**المنطق:**

- ترقيم تسلسلي آمن (استخدم Lock لتفادي تكرار الأرقام).
- PDF بنفس المكتبة المستخدمة في فواتير الاشتراكات.
- صفحة عامة بـ Token يشوف منها العميل العرض ويضغط قبول أو رفض.
- الإرسال بالإيميل أو واتساب.
- زر «حوّل لفاتورة»، وعند قبول العرض تتحدث `deal_value` ويقترح نقل الصفقة لـ Won.
- الدفع الأونلاين عن طريق بواباتك الـ 14 يتأجل لإصدار تاني.

### 4.4 Click-to-call وCall log (L، 2–3 أسابيع)

- Twilio Voice بمفاتيح كل workspace (**BYO credentials**، عشان تكلفة المكالمات تتحاسب على حساب العميل مش عليك).
- زر اتصال في صفحة الـ Lead: بيرن على موبايل الموظف الأول وبعدين يوصل بالعميل (أبسط من الاتصال من المتصفح).
- `call_logs`: الاتجاه، من/إلى، المدة، الحالة، رابط التسجيل، الملاحظات. وWebhook للـ status callback.
- Call log اليدوي الحالي يفضل شغال.

### معيار الانتهاء للمرحلة

- Sequence كامل بيوقف لوحده لما العميل يرد.
- عرض سعر اتبعت واتقبل واتحول لفاتورة اتسجل دفعها، وقيمة الصفقة اتحدّثت.
- مكالمة اتسجلت على الـ Lead.

## Phase 5 — نضج المنصة (مستمر، حسب طلب العملاء)

متبدأش في ده كله مرة واحدة. اشتغل على اللي عميل حقيقي طلبه أو اللي بيمنع صفقة.

| الميزة | التفاصيل | الجهد |
| --- | --- | --- |
| GDPR suite | صفحة DPA تتحمّل PDF، Cookie consent للفورمات، طلب الحذف (Erasure) بفترة انتظار 30 يوم ثم Purge، تصدير بيانات Lead/Contact ZIP، وسياسات الاحتفاظ بالـ Audit logs والـ Login attempts بأمر مجدول | M |
| Subdomain لكل Tenant | Wildcard DNS وSSL، Middleware يحدد الـ Tenant من الـ Host، وفعّل حقل `custom_domain` الموجود، وخلّي `/app` كبديل، وكل اللينكات العامة (فورمات وحجز) تتولّد من دومين الـ Tenant | M |
| أدوار إضافية | دور Viewer (قراءة فقط) وSection hiding حسب الباقة (إخفاء Reports أو Sales) | M |
| IP allowlist للـ Super-admin | قائمة IP/CIDR مع وضع صارم | S |
| Domain verification | فحص SPF وDKIM قبل السماح بالإرسال من دومين العميل | S |
| Breach-check للباسوردات | فحص بـ k-anonymity API (زي Have I Been Pwned) | S |
| Health endpoint | وسّع `/up` ليفحص DB وCache وStorage ويرجّع 503 عند العطل | S |
| Backups UI | تحميل Backup والتحقق منه من لوحة الـ Super-admin | M |

## Phase 6 — الذكاء الاصطناعي للمبيعات

أعد استخدام وحدة الـ AI الموجودة (OpenAI وClaude وGemini + عدّاد الـ Tokens). **قاعدة:** كلها تمر على Plan gate وعدّاد استهلاك من أول يوم.

1. **Smart tags + Subject-line optimizer** (S).
2. **AI Lead Coach** (M): بيقترح الخطوة الجاية ورد جاهز وسبب الـ Score، **باستخدام سياق المحادثة الفعلي**. ده تفوّق على LeadHub لأن بياناته أغنى.
3. **Call summaries** (M): تفريغ وتلخيص المكالمات (محتاج 4.4).
4. **At-risk detection** (M): بيعتمد على `lead_score_events` من 3.3.
5. **AI SDR** (L): آخر حاجة، لأنه محتاج حواجز أمان (Guardrails) واهتمام بـ Deliverability، وتسليم لإنسان أول ما العميل يرد.

## مؤجل (P3)

Affiliate engine، Marketplace، Installer للاستضافة المشتركة، Industry packs، Customer portal، Feature requests، Outlook sync، Round-robin بين أكتر من مضيف في الحجز. ده كله إما سوق مختلف أو مش هيفرق في قرار الشراء دلوقتي. ارجع له بعد ما يكون عندك عملاء بيدفعوا.

## الجدول الزمني التقريبي

| المرحلة | الأسابيع | الناتج |
| --- | --- | --- |
| Phase 0 | 1–2 | Plan gates وSeat caps وبيئة الشغل |
| Phase 1 | 3–12 | CRM كامل |
| Phase 2 | 13–24 | جذب وحجز |
| Phase 3 | 25–32 | تقارير وذكاء المحادثات |
| Phase 4 | 33–45 | Sequences وعروض أسعار ومكالمات |
| Phase 5 | مستمر | حسب الطلب |
| Phase 6 | بعد 45 | AI للمبيعات |

## طريقة الشغل الأسبوعية

- **3 أيام بناء** للميزة الحالية.
- **يوم Tests وتصليح** (Unit وFeature tests، وتجربة يدوية على الـ Seeder).
- **يوم Release وتواصل:** نشر على Staging ثم Production، وتحديث Changelog، وكلام مع عميل أو اتنين.
- Branch لكل ميزة، وCI بيشغّل `php artisan test` وفحص TypeScript وESLint قبل أي Merge.
- لو دعم العملاء بيأكل وقتك، حدّد له ساعتين ثابتين في اليوم.

## المخاطر وإزاي تتعامل معاها

| الخطر | الحل |
| --- | --- |
| التقديرات أقل من الواقع | ضيف 30% على كل تقدير. لو اتأخرت أسبوعين، اقطع من الـ Scope مش من الجودة. |
| Migrations على بيانات حقيقية | Backup + Staging + اختبار Backfill على نسخة من الداتا قبل Production. |
| Meta App Review بياخد وقت | ابدأ الإجراءات بدري، حتى قبل Phase 2. |
| أخطاء الـ Timezone في الحجز | خزّن كل حاجة UTC، وجرّب حالات تغيير التوقيت الصيفي. |
| Deliverability الإيميل | تحقق من SPF/DKIM وحدّ إرسال يومي لكل workspace. |
| تكلفة الـ AI والمكالمات | عدّاد وحد في الباقة، وBYO credentials للمكالمات. |
| التشتت وفتح ميزات كتير | الميزة مش بتتحسب خلصت قبل Definition of Done. ومتفتحش Phase 5 قبل ما عميل يطلبه. |
| إهمال جانب الرسائل (قوتك الأساسية) | خصّص يوم كل أسبوعين لتحسينات وأخطاء الـ Inbox والـ Automation. |

## أول أسبوعين: Checklist

1. Backup + بيئة Staging + CI شغال.
2. Module skeletons لـ `Sales` و`Reporting`.
3. بنية Feature flags وSeat caps (Phase 0).
4. Seeder بيانات تجريبية (3 Pipelines و200 Lead و20 شركة).
5. ارسم الـ Schema بتاع Phase 1 كله على ورقة (pipelines, deal fields, tasks, custom fields, companies, **lead\_stage\_history**) وراجعه قبل أول Migration.
6. ابدأ 1.1 وسجّل `lead_stage_history` من أول يوم.
7. حضّر طلب Meta App Review لو هتعتمد على Lead Ads.
