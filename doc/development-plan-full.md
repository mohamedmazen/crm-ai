# خطة التطوير الشاملة — WhatsMine CRM → Sales CRM

> آخر تحديث: أكتوبر 2026
> مبنية على: فحص كامل للكود + مقارنة مع LeadHub + خطة التطوير الأولية

---

## الفهرس

1. [تحليل الوضع الحالي](#1-تحليل-الوضع-الحالي)
2. [تحليل الفجوات](#2-تحليل-الفجوات)
3. [تغييرات القوائم والتنقل](#3-تغييرات-القوائم-والتنقل)
4. [Phase 0 — الأساس](#4-phase-0--الأساس)
5. [Phase 1 — أساس الـ CRM](#5-phase-1--أساس-الـ-crm)
6. [Phase 2 — الجذب والتحويل](#6-phase-2--الجذب-والتحويل)
7. [Phase 3 — التقارير والذكاء](#7-phase-3--التقارير-والذكاء)
8. [Phase 4 — محرك الإيراد](#8-phase-4--محرك-الإيراد)
9. [Phase 5 — نضج المنصة](#9-phase-5--نضج-المنصة)
10. [Phase 6 — الذكاء الاصطناعي للمبيعات](#10-phase-6--الذكاء-الاصطناعي-للمبيعات)
11. [الجدول الزمني](#11-الجدول-الزمني)
12. [المخاطر](#12-المخاطر)

---

## 1. تحليل الوضع الحالي

### 1.1 البنية التقنية

| العنصر | التفاصيل |
|---|---|
| **Framework** | Laravel 12 + Inertia v2 + React 19 |
| **PHP** | 8.2 |
| **DB** | MySQL 8.4.7 (single-database multi-tenant) |
| **التعددية** | Client → Workspace (بدون stancl/tenancy) |
| **البنية** | Modular: `app/Modules/{Name}/` لكل وحدة |

### 1.2 الموديولات الموجودة (10 وحدات)

```
app/Modules/
├── AI/                  # روبوتات ذكية، قواعد معرفة، RAG
├── Automation/          # 30+ عقدة أتمتة، 15 مشغّل
├── Broadcasting/        # حملات (واتساب/SMS/إيميل)، عدادات الاستخدام
├── Ecommerce/           # متاجر (Shopify/WooCommerce/BigCommerce)
├── Inbox/               # صندوق وارد مشترك، محادثات، تعيينات
├── Integrations/        # إعدادات التكاملات (14 بوابة دفع، إلخ)
├── Leads/               # Pipeline واحد، Kanban، أنشطة، Scoring
├── Shared/              # جهات اتصال، شرائح، Tags
├── Social/              # نشر (6 شبكات)، تقويم، تحليلات
└── Whatsapp/            # قوالب، ردود تلقائية، Widget
```

### 1.3 هيكل جدول leads الحالي

```
leads
├── id, workspace_id
├── stage_id → lead_pipeline_stages
├── board_position, stage_changed_at
├── next_follow_up_at, last_activity_at
├── name, phone, email, website
├── address, city, country, lat, lng
├── category, rating, review_count
├── score, score_band (hot/warm/cold), score_breakdown, scored_at
├── google_place_id, whatsapp_status
├── pushed_to_contacts
└── created_at, updated_at
```

**الأعمدة الناقصة:** لا يوجد `pipeline_id`، `deal_value`، `currency`، `probability`، `expected_close_date`، `won_at`، `lost_at`، `status`، `assigned_user_id`، `company_id`، `source`، `close_reason_id`.

### 1.4 جدول lead_pipeline_stages الحالي

```
lead_pipeline_stages
├── id, workspace_id
├── name, color, position
├── is_won, is_lost
└── created_at, updated_at
```

**الناقص:** لا يوجد `pipeline_id` ولا `default_probability`.

### 1.5 جدول contacts الحالي

```
contacts
├── id, uuid, workspace_id
├── phone_e164, email, first_name, last_name
├── avatar, country, language
├── opt_in_whatsapp, opt_in_sms, opt_in_email
├── custom_fields (JSON)
├── last_seen_at, source, lead_id
└── created_at, updated_at, deleted_at
```

**ملاحظة:** الـ Contact عنده `lead_id` (ربط موجود) و `custom_fields` كـ JSON.

### 1.6 نظام الباقات الحالي

**3 باقات:** Starter (مجاني) → Pro ($29/شهر) → Business ($99/شهر)

**الحدود المعرّفة (في PlanSeeder):**
`users`, `storage`, `whatsapp_accounts`, `whatsapp_templates`, `whatsapp_messages_per_month`, `campaigns_per_month`, `sms_per_month`, `emails_per_month`, `inbox_agents`, `ai_tokens_per_month`, `knowledge_bases`, `chatbots`, `social_accounts`, `social_posts_per_month`, `lead_credits_per_month`, `automations`

**المُطبّقة فعلياً:** 6 فقط (campaigns, whatsapp messages, ai tokens, knowledge bases, social posts, lead credits) عبر `EnforceLimit` middleware + `UsageMeter`.

**الميزات المقفلة:** `white_label` فقط عبر `Plan::hasFeature()`.

### 1.7 القوائم الحالية (15 مجموعة)

```
Account          → Dashboard
Inbox            → Inbox, Channel Setup
Social Media     → Post Composer, Posts, Calendar, Social Accounts
Messaging        → Templates, Auto Replies, Chat Widget
Contacts         → Contacts, Segments
Broadcasting     → Campaigns, SMS Gateways, Email Server
Automations      → Automations
E-Commerce       → Orders, Products, Stores
AI               → Chatbots, Knowledge Bases, AI Providers
Leads            → Lead Scraper, Lead Pipeline
Reports          → Inbox, Campaigns, Automations, AI Usage, Social
Support          → Support Tickets, Help Docs
Billing          → Subscription, Billing, Plans
Developer        → API Tokens, Webhooks, API Docs, Media Library
Account          → Workspaces, Settings, Team, Audit Log
```

### 1.8 نقاط القوة الحالية (لا تُمس)

- ✅ صندوق وارد متعدد القنوات (واتساب + Messenger + Instagram + SMS + إيميل)
- ✅ 30+ عقدة أتمتة و15 مشغّل
- ✅ نشر على 6 شبكات اجتماعية
- ✅ تكامل تجارة إلكترونية (Shopify/WooCommerce/BigCommerce)
- ✅ روبوتات ذكية بـ RAG (OpenAI + Claude + Gemini)
- ✅ 14 بوابة دفع و15 مزود SMS
- ✅ API + Webhooks + Mobile API
- ✅ Lead Scraper من Google Places
- ✅ تسجيل النقاط (Rule-based scoring)

---

## 2. تحليل الفجوات

### 2.1 ميزات CRM مفقودة بالكامل (P0 — لازم تتعمل)

| # | الميزة | الوصع الحالي | التأثير |
|---|---|---|---|
| 1 | أكثر من Pipeline | Pipeline واحد لكل workspace | لا يصلح لفرق المبيعات |
| 2 | بيانات الصفقة (Deal) | لا يوجد deal_value أو probability | لا توجد توقعات إيراد |
| 3 | ملكية الـ Lead | لا يوجد assigned_user_id | لا مسؤولية فردية |
| 4 | المهام (Tasks) | لا يوجد جدول tasks | متابعة يدوية فقط |
| 5 | الشركات (Companies) | لا يوجد جدول companies | لا يدعم B2B |
| 6 | حقول مخصصة للـ Lead | لا يوجد (الـ Contact عنده JSON فقط) | لا مرونة |
| 7 | Tags للـ Leads | `contact_tags` موجود لكن لا يوجد `lead_tags` | لا تصنيف |
| 8 | كشف التكرار والدمج | لا يوجد | بيانات مكررة |
| 9 | Import/Export | لا يوجد | إدخال بيانات يدوي فقط |
| 10 | Bulk Actions | لا يوجد | عمليات فردية فقط |
| 11 | Lead Status Lifecycle | لا يوجد (المرحلة فقط) | لا تقارير دقيقة |

### 2.2 ميزات جذب مفقودة (P0-P1)

| # | الميزة | الوصع الحالي |
|---|---|---|
| 1 | Form Builder | لا يوجد |
| 2 | UTM Tracking | لا يوجد |
| 3 | صفحة حجز مواعيد | لا يوجد |
| 4 | مصادر Leads (Meta Lead Ads, IMAP) | لا يوجد |
| 5 | Lead Sources مع Attribution | لا يوجد |

### 2.3 ميزات تقارير مفقودة (P1)

| # | الميزة | الوصع الحالي |
|---|---|---|
| 1 | تقارير المبيعات (Pipeline/Funnel/Revenue) | لا يوجد |
| 2 | تقارير مجدولة (CSV/PDF) | لا يوجد |
| 3 | تقرير Response Time | البيانات موجودة (`first_response_at`) لكن لا تقرير |
| 4 | Conversation Intelligence | لا ربط مباشر بين Lead والمحادثة |
| 5 | Lead Scoring سلوكي | Scoring موجود لكن Rule-based فقط (Google Places) |

### 2.4 ميزات إيراد مفقودة (P1)

| # | الميزة | الوصع الحالي |
|---|---|---|
| 1 | Email Sequences | لا يوجد (الحملات one-shot فقط) |
| 2 | منتجات + عروض أسعار + فواتير (للعملاء) | لا يوجد (فواتير الاشتراكات فقط) |
| 3 | Landing Pages | لا يوجد |
| 4 | Click-to-Call | لا يوجد |

### 2.5 ميزات منصة مفقودة (P2)

| # | الميزة | الوصع الحالي |
|---|---|---|
| 1 | Feature Gates فعلية | `white_label` فقط |
| 2 | Seat Caps مُطبّقة | معرّفة لكن غير مُطبّقة |
| 3 | GDPR Suite | لا يوجد |
| 4 | Subdomain لكل Tenant | حقل `custom_domain` موجود لكن غير مفعّل |
| 5 | أدوار إضافية (Viewer) | owner + administrator + staff فقط |

---

## 3. تغييرات القوائم والتنقل

### 3.1 القوائم الجديدة المطلوبة


```
التغييرات على useClientNav.jsx بالترتيب:
```

| المرحلة | المجموعة | العنصر الجديد | Route المقترح |
|---|---|---|---|
| Phase 1 | **Leads** | Pipeline (تعديل الموجود) | `client.leads.pipeline.index` |
| Phase 1 | **Leads** | Table View (جديد) | `client.leads.table.index` |
| Phase 1 | **Leads** | Companies (جديد) | `client.leads.companies.index` |
| Phase 1 | **Leads** | Duplicates (جديد) | `client.leads.duplicates.index` |
| Phase 1 | **Leads** | Import/Export (جديد) | `client.leads.import.index` |
| Phase 1 | قائمة جديدة **Tasks** | My Tasks (جديد) | `client.tasks.index` |
| Phase 2 | قائمة جديدة **Capture** | Forms (جديد) | `client.forms.index` |
| Phase 2 | **Capture** | Booking Pages (جديد) | `client.booking.index` |
| Phase 2 | **Capture** | Lead Sources (جديد) | `client.lead-sources.index` |
| Phase 3 | **Reports** | Sales Pipeline (جديد) | `client.reports.sales.pipeline` |
| Phase 3 | **Reports** | Lead Volume (جديد) | `client.reports.sales.volume` |
| Phase 3 | **Reports** | Source Performance (جديد) | `client.reports.sales.sources` |
| Phase 3 | **Reports** | Win/Loss (جديد) | `client.reports.sales.winloss` |
| Phase 3 | **Reports** | Agent Performance (جديد) | `client.reports.sales.agents` |
| Phase 3 | **Reports** | Response Time (جديد) | `client.reports.sales.response` |
| Phase 4 | قائمة جديدة **Sales** | Sequences (جديد) | `client.sequences.index` |
| Phase 4 | **Sales** | Products (جديد) | `client.products.index` |
| Phase 4 | **Sales** | Quotes (جديد) | `client.quotes.index` |
| Phase 4 | **Sales** | Invoices (جديد) | `client.invoices.index` |
| Phase 4 | **Sales** | Call Log (جديد) | `client.calls.index` |
| Phase 4 | **Capture** | Landing Pages (جديد) | `client.landing-pages.index` |

### 3.2 الشكل النهائي للقائمة الجانبية (بعد كل المراحل)

```
Account              → Dashboard
Inbox                → Inbox, Channel Setup
Tasks ⭐ جديد        → My Tasks
Leads ⭐ موسّع       → Pipeline, Table View, Companies, Duplicates, Import
Capture ⭐ جديد      → Forms, Booking Pages, Landing Pages, Lead Sources
Sales ⭐ جديد        → Sequences, Products, Quotes, Invoices, Call Log
Social Media         → (بدون تغيير)
Messaging            → (بدون تغيير)
Contacts             → (بدون تغيير)
Broadcasting         → (بدون تغيير)
Automations          → (بدون تغيير)
E-Commerce           → (بدون تغيير)
AI                   → (بدون تغيير)
Reports ⭐ موسّع     → Inbox, Campaigns, Automations, AI, Social,
                       Sales Pipeline, Lead Volume, Source Performance,
                       Win/Loss, Agent Performance, Response Time
Support              → (بدون تغيير)
Billing              → (بدون تغيير)
Developer            → (بدون تغيير)
Account              → (بدون تغيير)
```

### 3.3 ترتيب القوائم الجديد (حسب تكرار الاستخدام)

```diff
  Account (Dashboard)
  Inbox
+ Tasks                    ← يومي: قائمة المهام
  Leads                    ← موسّع بالشركات والجدول
+ Capture                  ← فورمات وحجز ومصادر
+ Sales                    ← Sequences وعروض أسعار
  Social Media
  Messaging
  Contacts
  Broadcasting
  Automations
  E-Commerce
  AI
  Reports                  ← موسّع بتقارير المبيعات
  Support
  Billing
  Developer
  Account (Settings)
```

---

## 4. Phase 0 — الأساس (أسبوع إلى أسبوعين)

### 4.1 بنية Feature Gates

**الهدف:** كل ميزة جديدة تتقفل وراء Feature flag من أول يوم.

#### الملفات المطلوبة

| الملف | الوصف |
|---|---|
| `app/Services/FeatureGate.php` | Helper: `Feature::enabled('forms')` يقرأ من الباقة |
| `app/Http/Middleware/RequireFeature.php` | Middleware جديد يمنع الوصول لميزة مقفولة |
| تعديل `EnforceLimit.php` | إضافة فحص `users` عند إنشاء/دعوة مستخدم |
| تعديل `PlanSeeder.php` | إضافة مفاتيح الميزات الجديدة |

#### مفاتيح الميزات الجديدة في PlanSeeder

```php
// Phase 1
'multi_pipeline' => false,  // Starter: false, Pro: true, Business: true
'tasks'          => false,  // Starter: false, Pro: true, Business: true
'companies'      => false,  // Starter: false, Pro: true, Business: true
'custom_fields'  => 5,      // Starter: 5, Pro: 20, Business: null (unlimited)
'lead_import'    => false,  // Starter: false, Pro: true, Business: true

// Phase 2
'forms'               => 1,    // Starter: 1, Pro: 10, Business: null
'form_submissions'     => 100,  // Starter: 100/mo, Pro: 5000, Business: null
'booking'              => false, // Starter: false, Pro: true, Business: true

// Phase 3
'sales_reports'        => false, // Starter: false, Pro: true, Business: true

// Phase 4
'sequences'            => 0,    // Starter: 0, Pro: 5, Business: null
'quotes'               => false, // Starter: false, Pro: true, Business: true
'landing_pages'        => 0,    // Starter: 0, Pro: 5, Business: null
'api_access'           => false, // Starter: false, Pro: true, Business: true
```

#### الاستخدام في الـ Routes

```php
// routes/client.php أو routes الموديول
Route::middleware('feature:multi_pipeline')->group(function () {
    Route::resource('pipelines', PipelineSettingsController::class);
});

Route::middleware('feature:forms')->group(function () {
    Route::resource('forms', FormController::class);
});
```

#### الـ UI عند الميزة المقفلة

```jsx
// مكوّن React جديد: Components/UpgradeGate.jsx
// يعرض رسالة "قم بالترقية" مع زر يوجّه لصفحة الباقات
// يُستخدم في كل صفحة ميزة جديدة
```

#### تطبيق Seat Caps

```php
// تعديل في InvitationController أو TeamController
// قبل إنشاء الدعوة:
$plan = $client->activePlan();
$limit = $plan->limitValue('users');
if ($limit !== null && $client->users()->count() >= $limit) {
    abort(402, 'Seat limit reached');
}
```

**Definition of Done:**
- [ ] ميزة تجريبية تتقفل وتتفتح بتغيير الباقة
- [ ] مستخدم زيادة عن الحد بيتمنع
- [ ] رسالة Upgrade واضحة في الـ UI
- [ ] Tests لكل السيناريوهات

### 4.2 تجهيز بيئة الشغل

| المهمة | التفاصيل |
|---|---|
| Module Skeleton: `Sales` | `php artisan make:module Sales` أو إنشاء يدوي بنفس هيكل Leads |
| Module Skeleton: `Reporting` | نفس الهيكل |
| Demo Seeder | 3 pipelines, 200 lead, 20 company, 50 task |
| CI Pipeline | `php artisan test` + TypeScript check + ESLint + Pint |

#### هيكل الموديولات الجديدة

```
app/Modules/Sales/
├── SalesServiceProvider.php
├── Contracts/
├── database/migrations/
├── Http/Controllers/
├── Jobs/
├── Models/
├── routes/web.php
└── Services/

app/Modules/Reporting/
├── ReportingServiceProvider.php
├── database/migrations/
├── Http/Controllers/
├── routes/web.php
└── Services/
```

---

## 5. Phase 1 — أساس الـ CRM (6–10 أسابيع)

**الهدف:** الـ Lead يتحول من «كارت متابعة» لسجل مبيعات حقيقي.

### 5.1 أكثر من Pipeline + بيانات الصفقة (M — 8-10 أيام)

#### 5.1.1 الـ Schema

**جدول جديد: `lead_pipelines`**

```sql
CREATE TABLE lead_pipelines (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    workspace_id    BIGINT UNSIGNED NOT NULL,
    name            VARCHAR(128) NOT NULL,
    is_default      TINYINT(1) DEFAULT 0,
    sort_order      INT UNSIGNED DEFAULT 0,
    created_at      TIMESTAMP NULL,
    updated_at      TIMESTAMP NULL,
    INDEX (workspace_id),
    FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE
);
```

**تعديل جدول `lead_pipeline_stages`:**

```sql
ALTER TABLE lead_pipeline_stages
    ADD COLUMN pipeline_id BIGINT UNSIGNED AFTER workspace_id,
    ADD COLUMN default_probability TINYINT UNSIGNED DEFAULT NULL AFTER is_lost,
    ADD FOREIGN KEY (pipeline_id) REFERENCES lead_pipelines(id) ON DELETE CASCADE;
```

**جدول جديد: `lead_close_reasons`**

```sql
CREATE TABLE lead_close_reasons (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    workspace_id    BIGINT UNSIGNED NOT NULL,
    type            ENUM('won', 'lost') NOT NULL,
    name            VARCHAR(128) NOT NULL,
    sort_order      INT UNSIGNED DEFAULT 0,
    created_at      TIMESTAMP NULL,
    updated_at      TIMESTAMP NULL,
    INDEX (workspace_id, type)
);
```

**تعديل جدول `leads`:**

```sql
ALTER TABLE leads
    ADD COLUMN pipeline_id         BIGINT UNSIGNED AFTER workspace_id,
    ADD COLUMN status              ENUM('new','contacted','qualified','converted','lost')
                                   DEFAULT 'new' AFTER stage_changed_at,
    ADD COLUMN deal_value          DECIMAL(14,2) DEFAULT NULL,
    ADD COLUMN currency            VARCHAR(8) DEFAULT NULL,
    ADD COLUMN probability         TINYINT UNSIGNED DEFAULT NULL,
    ADD COLUMN expected_close_date DATE DEFAULT NULL,
    ADD COLUMN won_at              TIMESTAMP NULL,
    ADD COLUMN lost_at             TIMESTAMP NULL,
    ADD COLUMN close_reason_id     BIGINT UNSIGNED DEFAULT NULL,
    ADD FOREIGN KEY (pipeline_id) REFERENCES lead_pipelines(id) ON DELETE SET NULL,
    ADD FOREIGN KEY (close_reason_id) REFERENCES lead_close_reasons(id) ON DELETE SET NULL;
```

**جدول جديد: `lead_stage_history`** (مهم جداً للتقارير)

```sql
CREATE TABLE lead_stage_history (
    id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    lead_id      BIGINT UNSIGNED NOT NULL,
    from_stage   BIGINT UNSIGNED DEFAULT NULL,
    to_stage     BIGINT UNSIGNED NOT NULL,
    user_id      BIGINT UNSIGNED DEFAULT NULL,
    changed_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX (lead_id, changed_at),
    FOREIGN KEY (lead_id) REFERENCES leads(id) ON DELETE CASCADE
);
```

#### 5.1.2 Backfill Migration

```php
// لكل workspace قديم:
// 1. أنشئ Pipeline افتراضي
// 2. اربط المراحل الموجودة بالـ Pipeline
// 3. اربط الـ Leads الموجودة بالـ Pipeline
// 4. أدخل سجل أولي في lead_stage_history لكل Lead
```

#### 5.1.3 الملفات Backend

| الملف | النوع | الوصف |
|---|---|---|
| `Leads/Models/LeadPipeline.php` | Model جديد | علاقات: workspace, stages, leads |
| `Leads/Models/LeadCloseReason.php` | Model جديد | أسباب الربح/الخسارة |
| `Leads/Models/LeadStageHistory.php` | Model جديد | تاريخ تنقل المراحل |
| تعديل `Leads/Models/Lead.php` | تعديل | إضافة العلاقات والأعمدة الجديدة |
| تعديل `Leads/Models/LeadPipelineStage.php` | تعديل | إضافة `pipeline()` relationship |
| تعديل `Leads/Services/PipelineManager.php` | تعديل | دعم `pipeline_id`، تسجيل Stage History |
| `Leads/Http/Controllers/PipelineSettingsController.php` | Controller جديد | CRUD للـ Pipelines والمراحل والأسباب |
| تعديل `Leads/Http/Controllers/LeadPipelineController.php` | تعديل | فلتر بالـ Pipeline |

#### 5.1.4 صفحات React

| الملف | الوصف |
|---|---|
| تعديل `Pages/Leads/Pipeline/Index.jsx` | Dropdown لتبديل Pipeline، مجموع القيمة لكل عمود |
| تعديل `Pages/Leads/Pipeline/LeadCard.jsx` | عرض `deal_value` و`probability` على الكارت |
| `Pages/Leads/Pipeline/PipelineSettings.jsx` | جديد: إدارة Pipelines والمراحل والأسباب |
| `Pages/Leads/Pipeline/WonLostModal.jsx` | جديد: Modal السبب عند Won/Lost |

#### 5.1.5 تغييرات الـ Automation

```
أحداث جديدة يجب إطلاقها:
- lead.pipeline_changed  (lead انتقل لـ pipeline مختلف)
- lead.won               (lead تم كسبه)
- lead.lost              (lead خُسر)
- lead.deal_value_changed (قيمة الصفقة تغيرت)
```

---

### 5.2 ملكية الـ Lead + Round-robin (S — 3-4 أيام)

#### 5.2.1 الـ Schema

```sql
ALTER TABLE leads
    ADD COLUMN assigned_user_id BIGINT UNSIGNED DEFAULT NULL,
    ADD COLUMN assigned_at      TIMESTAMP NULL,
    ADD FOREIGN KEY (assigned_user_id) REFERENCES users(id) ON DELETE SET NULL;
```

**جدول جديد: `lead_assignment_configs`**

```sql
CREATE TABLE lead_assignment_configs (
    id             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    pipeline_id    BIGINT UNSIGNED NOT NULL UNIQUE,
    mode           ENUM('manual', 'round_robin', 'load_balanced') DEFAULT 'manual',
    last_assigned_user_id BIGINT UNSIGNED DEFAULT NULL,
    created_at     TIMESTAMP NULL,
    updated_at     TIMESTAMP NULL,
    FOREIGN KEY (pipeline_id) REFERENCES lead_pipelines(id) ON DELETE CASCADE
);
```

#### 5.2.2 الملفات

| الملف | الوصف |
|---|---|
| `Leads/Services/LeadAssignmentService.php` | جديد: منطق التوزيع (Round-robin/Load-balanced) |
| تعديل `Leads/Models/Lead.php` | إضافة `assignedUser()` relationship |
| تعديل `LeadPipelineController.php` | فلتر «Leads بتاعتي» وفلتر بالمسؤول |

#### 5.2.3 الـ UI

- صورة المسؤول على الكارت في الـ Kanban
- فلتر «My Leads» وDropdown فلتر بالمسؤول
- إعداد وضع التوزيع في صفحة Pipeline Settings
- Notification للموظف عند التعيين

---

### 5.3 المهام والتذكيرات — Tasks (M — 8-10 أيام)

#### 5.3.1 الـ Schema

```sql
CREATE TABLE lead_tasks (
    id               BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    workspace_id     BIGINT UNSIGNED NOT NULL,
    lead_id          BIGINT UNSIGNED NOT NULL,
    assigned_user_id BIGINT UNSIGNED DEFAULT NULL,
    title            VARCHAR(256) NOT NULL,
    description      TEXT DEFAULT NULL,
    type             ENUM('call', 'email', 'whatsapp', 'meeting', 'todo') DEFAULT 'todo',
    priority         ENUM('low', 'medium', 'high') DEFAULT 'medium',
    due_at           TIMESTAMP NULL,
    completed_at     TIMESTAMP NULL,
    created_by       BIGINT UNSIGNED DEFAULT NULL,
    created_at       TIMESTAMP NULL,
    updated_at       TIMESTAMP NULL,
    INDEX (workspace_id, assigned_user_id, due_at),
    INDEX (lead_id, completed_at),
    FOREIGN KEY (lead_id) REFERENCES leads(id) ON DELETE CASCADE,
    FOREIGN KEY (assigned_user_id) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
);
```

#### 5.3.2 الملفات

| الملف | النوع | الوصف |
|---|---|---|
| `Leads/Models/LeadTask.php` | Model جديد | علاقات: lead, assignedUser, creator |
| `Leads/Http/Controllers/LeadTaskController.php` | Controller جديد | CRUD + mark complete |
| `Leads/Http/Controllers/TaskDashboardController.php` | Controller جديد | صفحة «مهامي» |
| `Leads/Jobs/SendTaskReminderJob.php` | Job جديد | إرسال تذكير قبل الموعد |
| `Leads/Console/ProcessTaskReminders.php` | Command جديد | يشتغل كل 5 دقايق |

#### 5.3.3 صفحات React

| الملف | الوصف |
|---|---|
| `Pages/Leads/Pipeline/Show.jsx` (تعديل) | تبويب Tasks في صفحة الـ Lead |
| `Pages/Tasks/Index.jsx` | جديد: صفحة «مهامي» بفلاتر (النهاردة/متأخرة/قادمة) |
| `Components/TaskBadge.jsx` | جديد: Badge للمهام المتأخرة |

#### 5.3.4 ربط `next_follow_up_at`

```php
// في LeadTask observer أو في TaskService:
// عند إنشاء/تعديل/حذف/إكمال Task:
$lead->update([
    'next_follow_up_at' => $lead->tasks()
        ->whereNull('completed_at')
        ->orderBy('due_at')
        ->value('due_at'),
]);
```

---

### 5.4 Tags + Bulk Actions + Import/Export (M — 8-10 أيام)

#### 5.4.1 الـ Schema

```sql
CREATE TABLE lead_tags (
    id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    workspace_id BIGINT UNSIGNED NOT NULL,
    name         VARCHAR(64) NOT NULL,
    color        VARCHAR(16) DEFAULT NULL,
    created_at   TIMESTAMP NULL,
    updated_at   TIMESTAMP NULL,
    UNIQUE (workspace_id, name)
);

CREATE TABLE lead_tag_pivot (
    lead_id BIGINT UNSIGNED NOT NULL,
    tag_id  BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (lead_id, tag_id),
    FOREIGN KEY (lead_id) REFERENCES leads(id) ON DELETE CASCADE,
    FOREIGN KEY (tag_id) REFERENCES lead_tags(id) ON DELETE CASCADE
);
```

#### 5.4.2 الملفات

| الملف | النوع | الوصف |
|---|---|---|
| `Leads/Models/LeadTag.php` | Model جديد | بنفس شكل `ContactTag` |
| `Leads/Http/Controllers/LeadTableController.php` | Controller جديد | Table view بفلاتر وفرز |
| `Leads/Http/Controllers/LeadBulkActionController.php` | Controller جديد | تعيين/Tag/نقل/حذف |
| `Leads/Http/Controllers/LeadImportController.php` | Controller جديد | رفع CSV ← Mapping ← Queue |
| `Leads/Http/Controllers/LeadExportController.php` | Controller جديد | تصدير بالفلاتر الحالية |
| `Leads/Jobs/ImportLeadsJob.php` | Job جديد | معالجة الاستيراد في الخلفية |
| `Leads/Jobs/ExportLeadsJob.php` | Job جديد | تصدير في الخلفية + رابط تحميل |

#### 5.4.3 صفحات React

| الملف | الوصف |
|---|---|
| `Pages/Leads/Table/Index.jsx` | جديد: جدول Leads بأعمدة قابلة للاختيار |
| `Pages/Leads/Import/Index.jsx` | جديد: رفع → Mapping → Preview → تنفيذ |
| `Components/BulkActionBar.jsx` | جديد: شريط العمليات الجماعية |

---

### 5.5 الحقول المخصصة — Custom Fields (M — 8-10 أيام)

#### 5.5.1 الـ Schema

```sql
CREATE TABLE custom_fields (
    id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    workspace_id BIGINT UNSIGNED NOT NULL,
    entity_type  ENUM('lead', 'company') NOT NULL,
    key          VARCHAR(64) NOT NULL,
    label        VARCHAR(128) NOT NULL,
    type         ENUM('text','number','date','select','multiselect','checkbox','url') NOT NULL,
    options      JSON DEFAULT NULL,
    required     TINYINT(1) DEFAULT 0,
    sort_order   INT UNSIGNED DEFAULT 0,
    created_at   TIMESTAMP NULL,
    updated_at   TIMESTAMP NULL,
    UNIQUE (workspace_id, entity_type, key)
);

CREATE TABLE custom_field_values (
    id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    entity_type  VARCHAR(64) NOT NULL,
    entity_id    BIGINT UNSIGNED NOT NULL,
    field_id     BIGINT UNSIGNED NOT NULL,
    value        TEXT DEFAULT NULL,
    INDEX (entity_type, entity_id),
    INDEX (field_id),
    FOREIGN KEY (field_id) REFERENCES custom_fields(id) ON DELETE CASCADE
);
```

> **ملاحظة:** استخدام جدول `custom_field_values` بدل JSON أفضل للفلترة والتقارير.

#### 5.5.2 الملفات

| الملف | النوع | الوصف |
|---|---|---|
| `Shared/Models/CustomField.php` | Model جديد | Polymorphic (lead/company) |
| `Shared/Models/CustomFieldValue.php` | Model جديد | القيم |
| `Shared/Http/Controllers/CustomFieldController.php` | Controller جديد | CRUD |
| `Shared/Services/CustomFieldService.php` | Service جديد | التحقق والحفظ |

---

### 5.6 الشركات — Companies (M — 8-10 أيام)

#### 5.6.1 الـ Schema

```sql
CREATE TABLE companies (
    id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    workspace_id BIGINT UNSIGNED NOT NULL,
    name         VARCHAR(256) NOT NULL,
    domain       VARCHAR(256) DEFAULT NULL,
    industry     VARCHAR(128) DEFAULT NULL,
    size         VARCHAR(64) DEFAULT NULL,
    phone        VARCHAR(32) DEFAULT NULL,
    website      VARCHAR(512) DEFAULT NULL,
    address      TEXT DEFAULT NULL,
    owner_id     BIGINT UNSIGNED DEFAULT NULL,
    created_at   TIMESTAMP NULL,
    updated_at   TIMESTAMP NULL,
    INDEX (workspace_id),
    INDEX (workspace_id, domain),
    FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE,
    FOREIGN KEY (owner_id) REFERENCES users(id) ON DELETE SET NULL
);

ALTER TABLE leads
    ADD COLUMN company_id BIGINT UNSIGNED DEFAULT NULL,
    ADD FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE SET NULL;
```

#### 5.6.2 الملفات

| الملف | النوع | الوصف |
|---|---|---|
| `Leads/Models/Company.php` | Model جديد | علاقات: workspace, leads, owner, customFields |
| `Leads/Http/Controllers/CompanyController.php` | Controller جديد | CRUD + قائمة + تفاصيل |

#### 5.6.3 صفحات React

| الملف | الوصف |
|---|---|
| `Pages/Leads/Companies/Index.jsx` | قائمة الشركات |
| `Pages/Leads/Companies/Show.jsx` | تفاصيل الشركة + Leads + Tasks + Activities |
| تعديل `Pages/Leads/Pipeline/LeadForm.jsx` | Autocomplete لاختيار/إنشاء شركة |

#### 5.6.4 الربط الذكي

```php
// في LeadService عند إنشاء Lead:
// لو الإيميل على دومين شركة موجودة، اقترح الربط
$domain = Str::after($lead->email, '@');
$company = Company::where('workspace_id', $lead->workspace_id)
    ->where('domain', $domain)
    ->first();
if ($company) {
    // اقترح الربط في الـ Response
}
```

---

### 5.7 كشف التكرار والدمج — Dedupe & Merge (M — 8-10 أيام)

#### 5.7.1 الـ Schema

```sql
CREATE TABLE lead_duplicate_groups (
    id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    workspace_id BIGINT UNSIGNED NOT NULL,
    status       ENUM('pending', 'merged', 'dismissed') DEFAULT 'pending',
    created_at   TIMESTAMP NULL,
    updated_at   TIMESTAMP NULL,
    INDEX (workspace_id, status)
);

CREATE TABLE lead_duplicate_group_members (
    group_id BIGINT UNSIGNED NOT NULL,
    lead_id  BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (group_id, lead_id),
    FOREIGN KEY (group_id) REFERENCES lead_duplicate_groups(id) ON DELETE CASCADE,
    FOREIGN KEY (lead_id) REFERENCES leads(id) ON DELETE CASCADE
);
```

#### 5.7.2 الملفات

| الملف | النوع | الوصف |
|---|---|---|
| `Leads/Services/DuplicateDetector.php` | Service جديد | مطابقة بالإيميل والتليفون |
| `Leads/Services/LeadMergeService.php` | Service جديد | دمج + نقل Activities/Tasks/Tags |
| `Leads/Http/Controllers/DuplicateController.php` | Controller جديد | عرض المجموعات ودمجها |
| `Leads/Jobs/DetectDuplicatesJob.php` | Job جديد | Command مجدول لاكتشاف التكرارات |

#### 5.7.3 صفحات React

| الملف | الوصف |
|---|---|
| `Pages/Leads/Duplicates/Index.jsx` | جديد: قائمة المجموعات المتكررة |
| `Pages/Leads/Duplicates/MergeModal.jsx` | جديد: اختيار الأساسي وتحديد القيم |

### 5.8 ملخص Phase 1 — قائمة الملفات الكاملة

```
الملفات الجديدة (تقريباً):
├── Migrations:          6 ملفات
├── Models:              8 ملفات جديدة + 2 تعديل
├── Controllers:         8 ملفات جديدة + 2 تعديل
├── Services:            4 ملفات جديدة + 1 تعديل
├── Jobs:                4 ملفات جديدة
├── React Pages:         ~12 ملف جديد + 3 تعديل
├── Tests:               ~15-20 ملف
└── المجموع التقريبي:    ~60 ملف
```

**معيار الانتهاء:**
- [ ] Workspace قديم يعدّي الـ Migrations بدون خسارة بيانات
- [ ] كل صفحة جديدة وراها Feature flag
- [ ] Seeder تجريبي يغطي كل الحالات
- [ ] `lead_stage_history` بيسجل من أول يوم
- [ ] Tests شاملة لكل الميزات

---

## 6. Phase 2 — الجذب والتحويل (10–12 أسبوع)

**الهدف:** الـ Leads تدخل لوحدها من الإعلانات والفورمات، وتتحول لمواعيد.

### 6.1 Form Builder + Embed Widget (L — 3-4 أسابيع)

#### 6.1.1 الـ Schema

```sql
CREATE TABLE forms (
    id               BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    uuid             CHAR(36) NOT NULL UNIQUE,
    workspace_id     BIGINT UNSIGNED NOT NULL,
    name             VARCHAR(128) NOT NULL,
    slug             VARCHAR(128) NOT NULL,
    status           ENUM('draft', 'published', 'archived') DEFAULT 'draft',
    pipeline_id      BIGINT UNSIGNED DEFAULT NULL,
    target_stage_id  BIGINT UNSIGNED DEFAULT NULL,
    assignment_mode  ENUM('none', 'specific', 'round_robin') DEFAULT 'none',
    assigned_user_id BIGINT UNSIGNED DEFAULT NULL,
    auto_tags        JSON DEFAULT NULL,
    thank_you_type   ENUM('message', 'redirect') DEFAULT 'message',
    thank_you_value  TEXT DEFAULT NULL,
    spam_protection  ENUM('none', 'turnstile', 'recaptcha') DEFAULT 'turnstile',
    settings         JSON DEFAULT NULL,
    views_count      INT UNSIGNED DEFAULT 0,
    created_at       TIMESTAMP NULL,
    updated_at       TIMESTAMP NULL,
    INDEX (workspace_id),
    UNIQUE (workspace_id, slug)
);

CREATE TABLE form_fields (
    id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    form_id      BIGINT UNSIGNED NOT NULL,
    type         ENUM('text','email','phone','textarea','select',
                      'checkbox','date','file','hidden','number') NOT NULL,
    label        VARCHAR(256) NOT NULL,
    field_key    VARCHAR(64) NOT NULL,
    required     TINYINT(1) DEFAULT 0,
    options      JSON DEFAULT NULL,
    maps_to      VARCHAR(64) DEFAULT NULL,
    sort_order   INT UNSIGNED DEFAULT 0,
    created_at   TIMESTAMP NULL,
    updated_at   TIMESTAMP NULL,
    FOREIGN KEY (form_id) REFERENCES forms(id) ON DELETE CASCADE
);

CREATE TABLE form_submissions (
    id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    form_id      BIGINT UNSIGNED NOT NULL,
    lead_id      BIGINT UNSIGNED DEFAULT NULL,
    payload      JSON NOT NULL,
    ip           VARCHAR(45) DEFAULT NULL,
    utm_source   VARCHAR(128) DEFAULT NULL,
    utm_medium   VARCHAR(128) DEFAULT NULL,
    utm_campaign VARCHAR(128) DEFAULT NULL,
    utm_term     VARCHAR(128) DEFAULT NULL,
    utm_content  VARCHAR(128) DEFAULT NULL,
    referrer     VARCHAR(512) DEFAULT NULL,
    created_at   TIMESTAMP NULL,
    updated_at   TIMESTAMP NULL,
    INDEX (form_id, created_at),
    FOREIGN KEY (form_id) REFERENCES forms(id) ON DELETE CASCADE,
    FOREIGN KEY (lead_id) REFERENCES leads(id) ON DELETE SET NULL
);
```

#### 6.1.2 الملفات

| الملف | الموديول | الوصف |
|---|---|---|
| `Sales/Models/Form.php` | Sales | Model |
| `Sales/Models/FormField.php` | Sales | Model |
| `Sales/Models/FormSubmission.php` | Sales | Model |
| `Sales/Http/Controllers/FormController.php` | Sales | CRUD + Builder |
| `Sales/Http/Controllers/FormPublicController.php` | Sales | Public routes (GET/POST) |
| `Sales/Http/Controllers/FormEmbedController.php` | Sales | JS embed endpoint |
| `Sales/Services/FormSubmissionService.php` | Sales | إنشاء Lead + Dedupe + Assignment |

#### 6.1.3 الـ Routes

```php
// Public routes (بدون auth)
Route::get('/f/{slug}', [FormPublicController::class, 'show']);
Route::post('/f/{slug}', [FormPublicController::class, 'submit'])
    ->middleware('throttle:30,1');
Route::get('/embed/f/{slug}.js', [FormEmbedController::class, 'script']);

// Authenticated routes
Route::middleware('feature:forms')->group(function () {
    Route::resource('forms', FormController::class);
});
```

#### 6.1.4 صفحات React

| الملف | الوصف |
|---|---|
| `Pages/Forms/Index.jsx` | قائمة الفورمات + إحصائيات |
| `Pages/Forms/Builder.jsx` | Drag & Drop builder (استخدم dnd-kit) |
| `Pages/Forms/Preview.jsx` | معاينة حية |
| `Pages/Forms/Analytics.jsx` | مشاهدات + إرسالات + نسبة التحويل |
| `resources/js/public/form.jsx` | الفورم العام (Standalone page) |

#### 6.1.5 خطة الإصدارات

| الإصدار | المدة | المحتوى |
|---|---|---|
| v1 | أسبوع ونص | حقول أساسية + صفحة مستقلة + iframe + إنشاء Lead |
| v2 | أسبوع | سطر JS + رفع ملفات + Turnstile + Floating widget |
| v3 | أسبوع | Multi-step + Conditional logic + Analytics + QR code |

---

### 6.2 UTM Tracking + Source Attribution (M — أسبوع)

#### 6.2.1 الـ Schema

```sql
ALTER TABLE leads
    ADD COLUMN source       VARCHAR(64)  DEFAULT NULL,
    ADD COLUMN utm_source   VARCHAR(128) DEFAULT NULL,
    ADD COLUMN utm_medium   VARCHAR(128) DEFAULT NULL,
    ADD COLUMN utm_campaign VARCHAR(128) DEFAULT NULL,
    ADD COLUMN utm_term     VARCHAR(128) DEFAULT NULL,
    ADD COLUMN utm_content  VARCHAR(128) DEFAULT NULL,
    ADD COLUMN referrer     VARCHAR(512) DEFAULT NULL,
    ADD COLUMN landing_page VARCHAR(512) DEFAULT NULL;

CREATE TABLE lead_sources (
    id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    workspace_id  BIGINT UNSIGNED NOT NULL,
    name          VARCHAR(64) NOT NULL,
    type          ENUM('form','webhook','api','whatsapp','meta_ads',
                       'imap','manual','scraper','import') NOT NULL,
    status        ENUM('active','error','inactive') DEFAULT 'active',
    last_lead_at  TIMESTAMP NULL,
    last_error    TEXT DEFAULT NULL,
    config        JSON DEFAULT NULL,
    created_at    TIMESTAMP NULL,
    updated_at    TIMESTAMP NULL,
    INDEX (workspace_id)
);
```

#### 6.2.2 الملفات

| الملف | الوصف |
|---|---|
| `resources/js/public/tracker.js` | سكريبت UTM: يلقط المعلمات → Cookie 30 يوم → يحقنها في الفورم |
| `Sales/Http/Controllers/LeadSourceController.php` | CRUD + Source health |
| `Sales/Models/LeadSource.php` | Model |

---

### 6.3 صفحة الحجز — Booking Pages (L — 3 أسابيع)

#### 6.3.1 الـ Schema

```sql
CREATE TABLE meeting_types (
    id               BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    uuid             CHAR(36) NOT NULL UNIQUE,
    workspace_id     BIGINT UNSIGNED NOT NULL,
    name             VARCHAR(128) NOT NULL,
    slug             VARCHAR(128) NOT NULL,
    duration_minutes INT UNSIGNED NOT NULL DEFAULT 30,
    buffer_before    INT UNSIGNED DEFAULT 0,
    buffer_after     INT UNSIGNED DEFAULT 0,
    location_type    ENUM('google_meet', 'phone', 'in_person', 'custom') DEFAULT 'google_meet',
    max_per_day      INT UNSIGNED DEFAULT NULL,
    min_notice_hours INT UNSIGNED DEFAULT 24,
    booking_window_days INT UNSIGNED DEFAULT 14,
    questions        JSON DEFAULT NULL,
    status           ENUM('active', 'inactive') DEFAULT 'active',
    created_at       TIMESTAMP NULL,
    updated_at       TIMESTAMP NULL,
    UNIQUE (workspace_id, slug)
);

CREATE TABLE availability_rules (
    id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id    BIGINT UNSIGNED NOT NULL,
    day_of_week TINYINT UNSIGNED NOT NULL,
    start_time TIME NOT NULL,
    end_time   TIME NOT NULL,
    timezone   VARCHAR(64) NOT NULL DEFAULT 'UTC',
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE availability_overrides (
    id        BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id   BIGINT UNSIGNED NOT NULL,
    date      DATE NOT NULL,
    available TINYINT(1) DEFAULT 0,
    start_time TIME DEFAULT NULL,
    end_time   TIME DEFAULT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE bookings (
    id               BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    uuid             CHAR(36) NOT NULL UNIQUE,
    meeting_type_id  BIGINT UNSIGNED NOT NULL,
    lead_id          BIGINT UNSIGNED DEFAULT NULL,
    host_user_id     BIGINT UNSIGNED NOT NULL,
    starts_at        TIMESTAMP NOT NULL,
    ends_at          TIMESTAMP NOT NULL,
    status           ENUM('confirmed', 'cancelled', 'completed', 'no_show') DEFAULT 'confirmed',
    meet_link        VARCHAR(512) DEFAULT NULL,
    cancel_token     VARCHAR(64) NOT NULL,
    reschedule_token VARCHAR(64) NOT NULL,
    guest_name       VARCHAR(256) DEFAULT NULL,
    guest_email      VARCHAR(256) DEFAULT NULL,
    guest_phone      VARCHAR(32) DEFAULT NULL,
    answers          JSON DEFAULT NULL,
    created_at       TIMESTAMP NULL,
    updated_at       TIMESTAMP NULL,
    INDEX (meeting_type_id, starts_at),
    FOREIGN KEY (meeting_type_id) REFERENCES meeting_types(id) ON DELETE CASCADE,
    FOREIGN KEY (lead_id) REFERENCES leads(id) ON DELETE SET NULL,
    FOREIGN KEY (host_user_id) REFERENCES users(id) ON DELETE CASCADE
);
```

#### 6.3.2 الملفات

| الملف | الوصف |
|---|---|
| `Sales/Models/MeetingType.php` | Model |
| `Sales/Models/AvailabilityRule.php` | Model |
| `Sales/Models/AvailabilityOverride.php` | Model |
| `Sales/Models/Booking.php` | Model |
| `Sales/Services/AvailabilityService.php` | حساب الأوقات المتاحة + Timezone |
| `Sales/Services/BookingService.php` | إنشاء الحجز + Lead + Google Calendar |
| `Sales/Http/Controllers/MeetingTypeController.php` | CRUD |
| `Sales/Http/Controllers/BookingPublicController.php` | الصفحة العامة |
| `Sales/Jobs/SendBookingReminderJob.php` | تذكير بالإيميل + واتساب |

#### 6.3.3 الـ Routes العامة

```php
Route::get('/book/{workspace}/{slug}', [BookingPublicController::class, 'show']);
Route::post('/book/{workspace}/{slug}', [BookingPublicController::class, 'store']);
Route::get('/book/cancel/{token}', [BookingPublicController::class, 'cancel']);
Route::get('/book/reschedule/{token}', [BookingPublicController::class, 'reschedule']);
```

#### 6.3.4 ميزة تفرّقك عن LeadHub

```
تذكيرات الحجز عبر واتساب (مش إيميل بس)
→ استخدم WhatsApp Template Message الموجود عندك
→ LeadHub مش عنده واتساب أصلاً
```

---

### 6.4 مصادر الـ Leads (L — 3 أسابيع)

ترتيب حسب الجهد والعائد:

| # | المصدر | الجهد | الملفات المطلوبة |
|---|---|---|---|
| 1 | **Webhook/API endpoint** | S | `Sales/Http/Controllers/LeadWebhookController.php` |
| 2 | **Auto-create من واتساب** | S | تعديل `Inbox/Services/ConversationService.php` |
| 3 | **Meta Lead Ads** | M | `Sales/Services/MetaLeadAdsService.php` + webhook |
| 4 | **IMAP email parsing** | M | `Sales/Services/ImapLeadParser.php` + Command |
| 5 | **Source health** | S | صفحة في `Pages/LeadSources/Index.jsx` |

> **مهم:** قدّم طلب Meta App Review من أول أسبوع في Phase 2 لأنه بياخد وقت.

**معيار الانتهاء:**
- [ ] فورم منشور على موقع حقيقي يدخّل Lead بالـ UTM الصحيح
- [ ] حجز كامل من الصفحة العامة حتى Google Meet والتذكير
- [ ] المصادر الخمسة شغالة + Source health
- [ ] كله وراه Feature flags

---

## 7. Phase 3 — التقارير والذكاء (6–8 أسابيع)

**الهدف:** إثبات ROI للعميل + بناء ميزة Conversation Intelligence.

### 7.1 تقارير المبيعات (L — 3 أسابيع)

#### 7.1.1 الترتيب (من الأسهل للأصعب)

| # | التقرير | الجهد | الوصف |
|---|---|---|---|
| 1 | Response Time | S | الوسيط و P90 من `first_response_at` |
| 2 | Lead Volume | S | عدد Leads عبر الزمن |
| 3 | Source Performance | S | Leads + Won + نسبة التحويل + القيمة لكل مصدر |
| 4 | Win/Loss Reasons | S | توزيع الأسباب |
| 5 | Pipeline Funnel | M | نسبة الانتقال بين المراحل (من `lead_stage_history`) |
| 6 | Stage Bottlenecks | M | متوسط الأيام في كل مرحلة |
| 7 | Agent Performance | M | قيمة Won + Pipeline + Win rate لكل موظف |
| 8 | Source Attribution | M | من UTM للإيراد |

#### 7.1.2 الـ Schema

```sql
CREATE TABLE report_schedules (
    id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    workspace_id  BIGINT UNSIGNED NOT NULL,
    report_type   VARCHAR(64) NOT NULL,
    filters       JSON DEFAULT NULL,
    frequency     ENUM('daily', 'weekly', 'monthly') NOT NULL,
    format        ENUM('csv', 'pdf') DEFAULT 'csv',
    recipients    JSON NOT NULL,
    next_run_at   TIMESTAMP NULL,
    created_at    TIMESTAMP NULL,
    updated_at    TIMESTAMP NULL,
    INDEX (next_run_at)
);
```

#### 7.1.3 الملفات

| الملف | الوصف |
|---|---|
| `Reporting/Services/SalesReportService.php` | Service: 8 تقارير بنفس الفلاتر |
| `Reporting/Http/Controllers/SalesReportController.php` | Controller |
| `Reporting/Http/Controllers/ReportScheduleController.php` | CRUD للجدولة |
| `Reporting/Jobs/SendScheduledReportJob.php` | إرسال التقرير بالإيميل |
| `Reporting/Jobs/ExportReportJob.php` | تصدير CSV/PDF |
| `Reporting/Console/ProcessScheduledReports.php` | Command مجدول |

#### 7.1.4 صفحات React

| الملف | الوصف |
|---|---|
| `Pages/Reports/Sales/Pipeline.jsx` | Funnel chart |
| `Pages/Reports/Sales/Volume.jsx` | Line/bar chart |
| `Pages/Reports/Sales/Sources.jsx` | Table + chart |
| `Pages/Reports/Sales/WinLoss.jsx` | Pie/bar chart |
| `Pages/Reports/Sales/Agents.jsx` | Table + comparison |
| `Pages/Reports/Sales/Response.jsx` | Distribution chart |

#### 7.1.5 الفلاتر المشتركة

```
كل التقارير تدعم: فترة زمنية + Pipeline + موظف + مصدر
```

---

### 7.2 Conversation Intelligence (M — أسبوعين)

**ده اللي بيفرّقك عن أي CRM مبيعات تاني.**

#### 7.2.1 المطلوب

| الميزة | التفاصيل | الملفات |
|---|---|---|
| ربط Lead بالمحادثة | Contact عنده `lead_id`، اعرض تبويب «المحادثة» في صفحة Lead | تعديل `Pages/Leads/Pipeline/Show.jsx` |
| زر «حوّل لـ Lead» | في Inbox: ضغطة واحدة تنشئ Lead من Contact | تعديل `Inbox/Http/Controllers/ConversationController.php` |
| Unified Timeline | رسائل + حملات + طلبات + مهام في Feed واحد | `Leads/Services/UnifiedTimelineService.php` |
| قيمة الصفقة من التجارة | لو Contact عنده سلة/طلبات، املأ `deal_value` | تعديل `LeadService.php` |
| SLA Badge | Badge لو العميل مستني رد أكتر من X ساعة | `Components/SlaBadge.jsx` |
| Trigger «العميل رد» | Automation trigger جديد | تعديل `AutomationEngine.php` |

---

### 7.3 Lead Scoring سلوكي (M — أسبوع)

#### 7.3.1 الـ Schema

```sql
CREATE TABLE lead_score_events (
    id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    lead_id    BIGINT UNSIGNED NOT NULL,
    event      VARCHAR(64) NOT NULL,
    points     SMALLINT NOT NULL,
    reason     VARCHAR(256) DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX (lead_id, created_at),
    FOREIGN KEY (lead_id) REFERENCES leads(id) ON DELETE CASCADE
);
```

#### 7.3.2 الأحداث ونقاطها

| الحدث | النقاط |
|---|---|
| رد على رسالة | +10 |
| فتح حملة إيميل | +3 |
| ضغط في حملة | +5 |
| سؤال عن السعر | +15 |
| حجز موعد | +20 |
| إرسال فورم | +10 |
| طلب شراء | +25 |
| عدم نشاط 7 أيام | -5 |
| عدم نشاط 30 يوم | -15 |

#### 7.3.3 الملفات

| الملف | الوصف |
|---|---|
| `Leads/Services/Scoring/BehavioralScorer.php` | جديد: يحسب النقاط من الأحداث |
| `Leads/Jobs/DecayLeadScoresJob.php` | Job ليلي لتقليل النقاط |
| `Leads/Models/LeadScoreEvent.php` | Model |

---

### 7.4 تحسينات الـ Automation (S — 3-4 أيام)

#### 7.4.1 الـ Schema

```sql
CREATE TABLE workspace_business_hours (
    id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    workspace_id  BIGINT UNSIGNED NOT NULL UNIQUE,
    timezone      VARCHAR(64) NOT NULL DEFAULT 'UTC',
    schedule      JSON NOT NULL,
    created_at    TIMESTAMP NULL,
    updated_at    TIMESTAMP NULL
);
-- schedule example: {"mon":{"start":"09:00","end":"17:00"}, "tue":...}
```

#### 7.4.2 Triggers جديدة

```
lead.assigned          → Lead اتعيّن لموظف
task.overdue           → Task تجاوزت موعدها
lead.score_threshold   → Score عدّى حد معين
lead.inactive          → مفيش نشاط N يوم
lead.date_reached      → تاريخ في حقل مخصص وصل
lead.customer_replied  → العميل رد على رسالة
```

#### 7.4.3 Actions جديدة

```
create_task            → إنشاء Task
move_to_pipeline       → نقل لـ Pipeline مختلف
update_deal_value      → تعديل قيمة الصفقة
enroll_in_sequence     → تسجيل في Sequence (Phase 4)
```

**معيار الانتهاء:**
- [ ] 8 تقارير شغالة بتتصدّر وبتتبعت بالجدولة
- [ ] فتحت محادثة واتساب وحوّلتها لـ Lead وشفت Timeline كامل
- [ ] Scoring سلوكي شغال مع Decay
- [ ] كل ده متقفل على Feature `sales_reports`

---

## 8. Phase 4 — محرك الإيراد (10–12 أسبوع)

**الهدف:** تقفل الدايرة من Lead لحد الفلوس.

### 8.1 Email Sequences (L — 3 أسابيع)

#### 8.1.1 الـ Schema

```sql
CREATE TABLE sequences (
    id                BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    uuid              CHAR(36) NOT NULL UNIQUE,
    workspace_id      BIGINT UNSIGNED NOT NULL,
    name              VARCHAR(128) NOT NULL,
    status            ENUM('draft', 'active', 'paused') DEFAULT 'draft',
    stop_on_reply     TINYINT(1) DEFAULT 1,
    stop_on_won       TINYINT(1) DEFAULT 1,
    respect_hours     TINYINT(1) DEFAULT 1,
    created_at        TIMESTAMP NULL,
    updated_at        TIMESTAMP NULL,
    INDEX (workspace_id)
);

CREATE TABLE sequence_steps (
    id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    sequence_id   BIGINT UNSIGNED NOT NULL,
    position      INT UNSIGNED NOT NULL,
    channel       ENUM('email', 'whatsapp') DEFAULT 'email',
    delay_hours   INT UNSIGNED DEFAULT 24,
    subject       VARCHAR(256) DEFAULT NULL,
    body          TEXT NOT NULL,
    condition     JSON DEFAULT NULL,
    created_at    TIMESTAMP NULL,
    updated_at    TIMESTAMP NULL,
    FOREIGN KEY (sequence_id) REFERENCES sequences(id) ON DELETE CASCADE
);

CREATE TABLE sequence_enrollments (
    id             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    sequence_id    BIGINT UNSIGNED NOT NULL,
    lead_id        BIGINT UNSIGNED NOT NULL,
    current_step   INT UNSIGNED DEFAULT 0,
    status         ENUM('active', 'completed', 'stopped', 'bounced') DEFAULT 'active',
    stop_reason    VARCHAR(64) DEFAULT NULL,
    next_run_at    TIMESTAMP NULL,
    created_at     TIMESTAMP NULL,
    updated_at     TIMESTAMP NULL,
    UNIQUE (sequence_id, lead_id),
    INDEX (next_run_at, status),
    FOREIGN KEY (sequence_id) REFERENCES sequences(id) ON DELETE CASCADE,
    FOREIGN KEY (lead_id) REFERENCES leads(id) ON DELETE CASCADE
);

CREATE TABLE sequence_events (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    enrollment_id   BIGINT UNSIGNED NOT NULL,
    step_position   INT UNSIGNED NOT NULL,
    type            ENUM('sent', 'open', 'click', 'reply', 'bounce', 'unsubscribe') NOT NULL,
    created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX (enrollment_id),
    FOREIGN KEY (enrollment_id) REFERENCES sequence_enrollments(id) ON DELETE CASCADE
);
```

**ميزة تفرّقك:** الخطوة تقدر تكون **واتساب Template** مش إيميل بس → Sequence متعدد القنوات.

#### 8.1.2 الملفات

| الملف | الوصف |
|---|---|
| `Sales/Models/Sequence.php` | Model + علاقات |
| `Sales/Models/SequenceStep.php` | Model |
| `Sales/Models/SequenceEnrollment.php` | Model |
| `Sales/Models/SequenceEvent.php` | Model |
| `Sales/Services/SequenceEngine.php` | المحرك: إرسال + tracking + stop logic |
| `Sales/Http/Controllers/SequenceController.php` | CRUD + Builder |
| `Sales/Console/ProcessSequenceSteps.php` | Command كل دقيقة |
| `Sales/Jobs/SendSequenceStepJob.php` | إرسال خطوة واحدة |

---

### 8.2 منتجات + عروض أسعار + فواتير (L — 3-4 أسابيع)

#### 8.2.1 الـ Schema

```sql
CREATE TABLE products (
    id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    workspace_id  BIGINT UNSIGNED NOT NULL,
    name          VARCHAR(256) NOT NULL,
    sku           VARCHAR(64) DEFAULT NULL,
    price         DECIMAL(14,2) NOT NULL,
    currency      VARCHAR(8) DEFAULT NULL,
    tax_rate      DECIMAL(5,2) DEFAULT 0,
    unit          VARCHAR(32) DEFAULT NULL,
    description   TEXT DEFAULT NULL,
    status        ENUM('active', 'archived') DEFAULT 'active',
    created_at    TIMESTAMP NULL,
    updated_at    TIMESTAMP NULL,
    INDEX (workspace_id)
);

CREATE TABLE quotes (
    id               BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    uuid             CHAR(36) NOT NULL UNIQUE,
    workspace_id     BIGINT UNSIGNED NOT NULL,
    number           VARCHAR(32) NOT NULL,
    lead_id          BIGINT UNSIGNED DEFAULT NULL,
    company_id       BIGINT UNSIGNED DEFAULT NULL,
    status           ENUM('draft','sent','viewed','accepted','rejected','expired') DEFAULT 'draft',
    valid_until      DATE DEFAULT NULL,
    subtotal         DECIMAL(14,2) DEFAULT 0,
    discount_type    ENUM('percent','fixed') DEFAULT NULL,
    discount_value   DECIMAL(14,2) DEFAULT 0,
    tax_total        DECIMAL(14,2) DEFAULT 0,
    total            DECIMAL(14,2) DEFAULT 0,
    currency         VARCHAR(8) DEFAULT NULL,
    notes            TEXT DEFAULT NULL,
    terms            TEXT DEFAULT NULL,
    public_token     VARCHAR(64) NOT NULL UNIQUE,
    sent_at          TIMESTAMP NULL,
    viewed_at        TIMESTAMP NULL,
    accepted_at      TIMESTAMP NULL,
    rejected_at      TIMESTAMP NULL,
    created_by       BIGINT UNSIGNED DEFAULT NULL,
    created_at       TIMESTAMP NULL,
    updated_at       TIMESTAMP NULL,
    INDEX (workspace_id, status),
    FOREIGN KEY (lead_id) REFERENCES leads(id) ON DELETE SET NULL,
    FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE SET NULL
);

CREATE TABLE quote_items (
    id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    quote_id    BIGINT UNSIGNED NOT NULL,
    product_id  BIGINT UNSIGNED DEFAULT NULL,
    description VARCHAR(256) NOT NULL,
    quantity    DECIMAL(10,2) DEFAULT 1,
    unit_price  DECIMAL(14,2) NOT NULL,
    tax_rate    DECIMAL(5,2) DEFAULT 0,
    total       DECIMAL(14,2) NOT NULL,
    sort_order  INT UNSIGNED DEFAULT 0,
    FOREIGN KEY (quote_id) REFERENCES quotes(id) ON DELETE CASCADE,
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE SET NULL
);

CREATE TABLE invoices (
    id             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    uuid           CHAR(36) NOT NULL UNIQUE,
    workspace_id   BIGINT UNSIGNED NOT NULL,
    number         VARCHAR(32) NOT NULL,
    quote_id       BIGINT UNSIGNED DEFAULT NULL,
    lead_id        BIGINT UNSIGNED DEFAULT NULL,
    company_id     BIGINT UNSIGNED DEFAULT NULL,
    status         ENUM('draft','sent','viewed','paid','overdue','cancelled') DEFAULT 'draft',
    subtotal       DECIMAL(14,2) DEFAULT 0,
    tax_total      DECIMAL(14,2) DEFAULT 0,
    total          DECIMAL(14,2) DEFAULT 0,
    currency       VARCHAR(8) DEFAULT NULL,
    due_date       DATE DEFAULT NULL,
    paid_at        TIMESTAMP NULL,
    notes          TEXT DEFAULT NULL,
    public_token   VARCHAR(64) NOT NULL UNIQUE,
    created_by     BIGINT UNSIGNED DEFAULT NULL,
    created_at     TIMESTAMP NULL,
    updated_at     TIMESTAMP NULL,
    INDEX (workspace_id, status),
    FOREIGN KEY (quote_id) REFERENCES quotes(id) ON DELETE SET NULL,
    FOREIGN KEY (lead_id) REFERENCES leads(id) ON DELETE SET NULL
);

CREATE TABLE invoice_payments (
    id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    invoice_id  BIGINT UNSIGNED NOT NULL,
    amount      DECIMAL(14,2) NOT NULL,
    method      VARCHAR(32) DEFAULT NULL,
    reference   VARCHAR(256) DEFAULT NULL,
    notes       TEXT DEFAULT NULL,
    paid_at     TIMESTAMP NOT NULL,
    created_at  TIMESTAMP NULL,
    updated_at  TIMESTAMP NULL,
    FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE CASCADE
);
```

#### 8.2.2 الملفات

| الملف | الوصف |
|---|---|
| `Sales/Models/Product.php` | Model |
| `Sales/Models/Quote.php` | Model + ترقيم تسلسلي آمن |
| `Sales/Models/QuoteItem.php` | Model |
| `Sales/Models/Invoice.php` | Model |
| `Sales/Models/InvoicePayment.php` | Model |
| `Sales/Services/QuoteService.php` | إنشاء + PDF + إرسال |
| `Sales/Services/InvoiceService.php` | تحويل من Quote + PDF |
| `Sales/Http/Controllers/ProductController.php` | CRUD |
| `Sales/Http/Controllers/QuoteController.php` | CRUD + Public |
| `Sales/Http/Controllers/InvoiceController.php` | CRUD + Public |

---

### 8.3 Landing Pages (L — 3 أسابيع)

#### 8.3.1 الـ Schema

```sql
CREATE TABLE landing_pages (
    id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    uuid          CHAR(36) NOT NULL UNIQUE,
    workspace_id  BIGINT UNSIGNED NOT NULL,
    name          VARCHAR(128) NOT NULL,
    slug          VARCHAR(128) NOT NULL,
    template      VARCHAR(64) DEFAULT NULL,
    blocks        JSON NOT NULL,
    meta_title    VARCHAR(256) DEFAULT NULL,
    meta_desc     VARCHAR(512) DEFAULT NULL,
    og_image      VARCHAR(512) DEFAULT NULL,
    status        ENUM('draft', 'published') DEFAULT 'draft',
    views_count   INT UNSIGNED DEFAULT 0,
    created_at    TIMESTAMP NULL,
    updated_at    TIMESTAMP NULL,
    UNIQUE (workspace_id, slug)
);
```

#### 8.3.2 Builder Blocks

```
hero, text, image, form (من 6.1), cta, testimonials, faq, video, features, pricing
```

ابدأ بـ 3 قوالب جاهزة فقط. Tracker.js مدمج تلقائياً.

---

### 8.4 Click-to-Call + Call Log (L — 2-3 أسابيع)

#### 8.4.1 الـ Schema

```sql
CREATE TABLE call_logs (
    id             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    workspace_id   BIGINT UNSIGNED NOT NULL,
    lead_id        BIGINT UNSIGNED DEFAULT NULL,
    user_id        BIGINT UNSIGNED DEFAULT NULL,
    direction      ENUM('outbound', 'inbound') NOT NULL,
    from_number    VARCHAR(32) DEFAULT NULL,
    to_number      VARCHAR(32) DEFAULT NULL,
    duration_secs  INT UNSIGNED DEFAULT 0,
    status         ENUM('initiated','ringing','answered','completed','failed','no_answer') DEFAULT 'initiated',
    recording_url  VARCHAR(512) DEFAULT NULL,
    notes          TEXT DEFAULT NULL,
    provider_sid   VARCHAR(64) DEFAULT NULL,
    created_at     TIMESTAMP NULL,
    updated_at     TIMESTAMP NULL,
    INDEX (workspace_id, lead_id),
    FOREIGN KEY (lead_id) REFERENCES leads(id) ON DELETE SET NULL
);
```

> **BYO Credentials:** تكلفة المكالمات على حساب العميل مش عليك.

**معيار الانتهاء:**
- [ ] Sequence كامل يوقف لوحده لما العميل يرد
- [ ] عرض سعر اتبعت واتقبل واتحول لفاتورة
- [ ] صفحة هبوط بفورم دخّلت Lead بالـ UTM
- [ ] مكالمة اتسجلت على الـ Lead

---

## 9. Phase 5 — نضج المنصة (مستمر)

> متبدأش في ده كله مرة واحدة. اشتغل على اللي عميل حقيقي طلبه.

| الميزة | التفاصيل | الجهد | الملفات الرئيسية |
|---|---|---|---|
| **GDPR Suite** | DPA PDF + Cookie consent + Erasure (30 يوم) + ZIP export + Retention policies | M | `Shared/Services/GdprService.php`, `Shared/Jobs/PurgeErasureRequestJob.php` |
| **Subdomain routing** | Wildcard DNS + SSL + Middleware يحدد Tenant من Host + تفعيل `custom_domain` | M | `App/Http/Middleware/ResolveTenantDomain.php` |
| **أدوار إضافية** | دور Viewer (قراءة فقط) + Section hiding حسب الباقة | M | تعديل `RoleSeeder.php` + `useClientNav.jsx` |
| **IP Allowlist** | قائمة IP/CIDR للـ Super-admin مع وضع صارم | S | `App/Http/Middleware/IpAllowlist.php` |
| **Domain Verification** | فحص SPF/DKIM قبل الإرسال | S | `Broadcasting/Services/DomainVerifier.php` |
| **Breach-check** | فحص k-anonymity API (Have I Been Pwned) | S | `App/Rules/NotBreachedPassword.php` |
| **Health Endpoint** | توسيع `/up` ليفحص DB + Cache + Storage | S | تعديل `routes/web.php` |
| **Backups UI** | تحميل والتحقق من لوحة Super-admin | M | `Admin/Http/Controllers/BackupController.php` |

---

## 10. Phase 6 — الذكاء الاصطناعي للمبيعات

> أعد استخدام وحدة الـ AI الموجودة (OpenAI + Claude + Gemini + عدّاد Tokens).
> **كلها تمر على Plan gate وعدّاد استهلاك من أول يوم.**

| # | الميزة | الجهد | الوصف |
|---|---|---|---|
| 1 | **Smart Tags + Subject Optimizer** | S | اقتراح Tags و A/B subject lines |
| 2 | **AI Lead Coach** | M | يقترح الخطوة الجاية + رد جاهز + سبب الـ Score **باستخدام سياق المحادثة** |
| 3 | **Call Summaries** | M | تفريغ وتلخيص المكالمات (يحتاج 8.4) |
| 4 | **At-risk Detection** | M | يعتمد على `lead_score_events` من 7.3 |
| 5 | **AI SDR** | L | متابعة تلقائية بالإيميل/واتساب حتى رد بشري |

> **AI Lead Coach هو أقوى ميزة تنافسية** لأن بياناتك أغنى من LeadHub:
> محادثات واتساب + طلبات تجارة إلكترونية + تفاعل سوشيال + RAG chatbot transcripts.

---

## 11. الجدول الزمني

### 11.1 جدول المراحل

| المرحلة | الأسابيع | التاريخ التقريبي | الناتج |
|---|---|---|---|
| **Phase 0** | 1–2 | الأسبوع 1-2 | Feature gates + Seat caps + بيئة الشغل |
| **Phase 1** | 3–12 | الأسبوع 3-12 | CRM كامل (Pipelines + Deals + Tasks + Companies + Custom Fields) |
| **Phase 2** | 13–24 | الأسبوع 13-24 | فورمات + حجز + مصادر + UTM |
| **Phase 3** | 25–32 | الأسبوع 25-32 | 8 تقارير + Conversation Intelligence + Scoring سلوكي |
| **Phase 4** | 33–45 | الأسبوع 33-45 | Sequences + عروض أسعار + فواتير + Landing Pages + مكالمات |
| **Phase 5** | مستمر | بعد 45 | حسب طلب العملاء |
| **Phase 6** | بعد Phase 4 | بعد 45 | AI للمبيعات |

### 11.2 نقاط البيع (متى تقدر تبيع)

```
بعد Phase 1 (≈ شهرين ونص):
  "عندنا Pipeline وصفقات ومهام وشركات"

بعد Phase 2 (≈ 5-6 شهور):     ← أول نقطة بيع حقيقية
  "من الإعلان للفورم للحجز في مكان واحد"

بعد Phase 3 (≈ 7 شهور):
  "بنعرف العميل اشترى إيه وسأل عن إيه على واتساب"

بعد Phase 4 (≈ 10 شهور):
  "Sequences وعروض أسعار وفواتير ومكالمات"
```

### 11.3 طريقة الشغل الأسبوعية

| اليوم | المهمة |
|---|---|
| الأحد - الثلاثاء | بناء الميزة الحالية (3 أيام) |
| الأربعاء | Tests + تصليح أخطاء |
| الخميس | Release + Staging + تواصل مع عملاء |

### 11.4 قواعد الشغل

- ✅ Branch لكل ميزة + CI قبل Merge
- ✅ Vertical slices: الميزة تخلص كاملة (Backend + UI + Test) قبل ما تبدأ اللي بعدها
- ✅ Definition of Done: Migration + Backfill + Tests + Feature flag + توثيق قصير
- ✅ Release كل 2-3 أسابيع
- ✅ يوم كل أسبوعين لتحسينات الـ Inbox والـ Automation (قوتك الأساسية)

---

## 12. المخاطر

| الخطر | الاحتمال | الحل |
|---|---|---|
| التقديرات أقل من الواقع | عالي | ضيف 30% على كل تقدير. لو اتأخرت، اقطع من الـ Scope مش الجودة |
| Migrations على بيانات حقيقية | متوسط | Backup + Staging + اختبار Backfill على نسخة |
| Meta App Review بياخد وقت | عالي | ابدأ الإجراءات بدري (أول أسبوع في Phase 2) |
| أخطاء Timezone في الحجز | عالي | خزّن كل حاجة UTC، جرّب تغيير التوقيت الصيفي |
| Deliverability الإيميل | متوسط | SPF/DKIM + حد إرسال يومي لكل workspace |
| تكلفة AI والمكالمات | متوسط | عدّاد + حد في الباقة + BYO credentials |
| التشتت وفتح ميزات كتير | عالي | الميزة مش خلصت قبل Definition of Done |
| إهمال جانب الرسائل | متوسط | يوم كل أسبوعين لتحسينات Inbox/Automation |
| Plan enforcement متأخر | عالي | Phase 0 أولاً — لو ضفت ميزات بدون gates هتندم |

---

## ملحق: إحصائيات المشروع

### عدد الجداول الجديدة المطلوبة (تقريبي)

| المرحلة | جداول جديدة | أعمدة مضافة لجداول قائمة |
|---|---|---|
| Phase 0 | 0 | تعديل PlanSeeder فقط |
| Phase 1 | 8 | ~15 عمود على `leads` + `lead_pipeline_stages` |
| Phase 2 | 8 | ~8 أعمدة على `leads` |
| Phase 3 | 3 | 0 |
| Phase 4 | 10 | 0 |
| Phase 5 | 1 | تعديلات طفيفة |
| **المجموع** | **~30 جدول** | **~23 عمود** |

### عدد الملفات الجديدة التقريبي

| النوع | العدد التقريبي |
|---|---|
| Migrations | ~15 |
| Models | ~25 |
| Controllers | ~20 |
| Services | ~15 |
| Jobs/Commands | ~12 |
| React Pages | ~35 |
| React Components | ~15 |
| Tests | ~40 |
| **المجموع** | **~177 ملف** |

---

## أول أسبوعين: Checklist

- [ ] Backup + بيئة Staging + CI شغال
- [ ] Module skeletons لـ `Sales` و `Reporting`
- [ ] بنية Feature flags و Seat caps (Phase 0)
- [ ] Seeder بيانات تجريبية (3 Pipelines + 200 Lead + 20 شركة)
- [ ] ارسم Schema بتاع Phase 1 كله وراجعه
- [ ] ابدأ 5.1 (Multiple Pipelines) وسجّل `lead_stage_history` من أول يوم
- [ ] حضّر طلب Meta App Review

---

> **ملاحظة مهمة:** الخطة دي مبنية على فحص كامل للكود الحالي في أكتوبر 2026.
> أي تغييرات في الكود بعد هذا التاريخ ممكن تأثر على التفاصيل التقنية.
> راجع الخطة كل شهر وعدّل حسب اللي اتغير.

*تم إعداد هذه الخطة بناءً على فحص كامل لقاعدة الكود ومقارنة تفصيلية مع LeadHub SaaS CRM.*
