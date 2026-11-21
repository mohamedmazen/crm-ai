# Meta — الخدمات والمطلوب لبناء تطبيق

> ملف مرجعي داخلي. يوضّح **خدمات Meta التي يستخدمها AYDRA فعلًا**، و**كل ما يلزم لبناء تطبيق Meta واحد** يخدم كل العملاء.
> المحتوى مبني على الكود في هذا الريبو (المسارات مذكورة) وعلى إعدادات الـ config / .env الفعلية — لا افتراضات.
>
> آخر تحديث: نوفمبر 2026

---

## 1. خدمات Meta المستخدمة

| الخدمة | الغرض في AYDRA | المنتج في اللوحة | مثال Endpoint | الكود |
|---|---|---|---|---|
| **WhatsApp Cloud API** | إرسال واستقبال الرسائل، القوالب، إدارة الأرقام | WhatsApp | `POST /{phone_number_id}/messages` | `app/Modules/Whatsapp/Services/CloudApiClient.php` |
| **WhatsApp Coexistence** | رقم يبقى على تطبيق واتساب الأعمال **و** يعمل عبر Cloud API | WhatsApp (onboarding خاصة) | `POST /{waba_id}/subscribed_apps` + أحداث المزامنة | `app/Modules/Whatsapp/Services/WhatsappCoexistenceSyncService.php` |
| **Messenger Platform** | رسائل صفحة فيسبوك | Messenger | `POST /{page_id}/messages` | `app/Modules/Inbox/Services/MessengerDriver.php` |
| **Instagram API with Facebook Login** | رسائل Instagram المباشرة + النشر | Instagram | `/{ig_user_id}/messages` | `app/Modules/Inbox/Services/InstagramDriver.php` · `app/Modules/Social/Services/OAuth/OAuthManager.php` |
| **Meta Lead Ads** | استقبال الليدز من نماذج فيسبوك/إنستجرام | Webhooks (`leadgen`) | `GET /{leadgen_id}` | `app/Modules/Sales/Services/MetaLeadAdsService.php` |
| **Facebook Login for Business** | توليد `config_id` للربط الذاتي (Embedded Signup) | Facebook Login for Business | `FB.login({ config_id })` | `app/Modules/Inbox/Http/Controllers/InboxSetupController.php` |
| **Graph API** | كل نداءات Meta (نسخة واحدة مركزية) | — | `https://graph.facebook.com/v27.0` | `app/Support/MetaGraph.php` |
| **Webhooks** | استقبال أحداث Meta (رسائل، حالات، ليدز) | Webhooks | `GET` للتحقق / `POST` للأحداث | `routes/webhooks.php` |

**خدمات Meta غير مستخدمة حاليًا** (مهم عند التخطيط — لا تطلب صلاحياتها):

- Conversions API (CAPI)
- WhatsApp Flows
- WhatsApp Catalog / Commerce (الكتالوج والطلبات)
- Marketing API (إدارة الحملات)
- Business Asset API (كتابةً — `business_management` تُستخدم للقراءة فقط)

---

## 2. المطلوب لبناء التطبيق

### 2.1 الحسابات والأصول

| # | الأصل | لماذا | الحد الأدنى |
|---|---|---|---|
| ☐ | حساب فيسبوك شخصي | ملكية التطبيق والدخول للوحة المطوّر | حساب حقيقي + 2FA |
| ☐ | بريد شركة على الدومين | التحقق والإشعارات | `ops@your-domain.com` |
| ☐ | Business Portfolio | وعاء الأصول والفوترة والتحقق التجاري | واحد بالاسم القانوني |
| ☐ | صفحة فيسبوك | Messenger + ربط Instagram | صفحة رسمية باسم العلامة |
| ☐ | حساب Instagram احترافي | رسائل Instagram | Business / Creator **مرتبط بالصفحة** |
| ☐ | رقم واتساب مخصص | الإرسال والاستقبال | غير مسجّل على واتساب العادي |
| ☐ | دومين إنتاجي + SSL | روابط الويبهوك والسياسات | HTTPS صالح (ليس Self-signed) |
| ☐ | وسيلة دفع | فوترة WABA | بطاقة على الـ Portfolio |
| ☐ | صفحات سياسات | شرط النشر | Privacy · Terms · Data Deletion |

### 2.2 إنشاء التطبيق

| البند | القيمة / الملاحظة |
|---|---|
| النوع | **Business** (وليس Consumer) |
| الاسم الظاهر | `AYDRA` — يظهر للعميل في نافذة الصلاحيات |
| App domains | دومين الإنتاج فقط |
| Privacy / Terms / Data Deletion | روابط عامة تعمل بدون تسجيل دخول |
| الأيقونة | 1024×1024 PNG |
| الحالة | يبدأ **Development** (الصلاحيات تعمل مع أدوار التطبيق فقط) |
| الحد الأقصى | **15 تطبيقًا لكل حساب مطوّر** |
| نموذج الفوترة | **Tech Provider** — كل عميل يضيف وسيلة دفعه |

### 2.3 المنتجات التي تُضاف إلى التطبيق

| المنتج | لماذا | ما يولّده للـ AYDRA |
|---|---|---|
| **WhatsApp** | القناة الأساسية | WABA ID · Phone Number ID · Callback URL |
| **Messenger** | رسائل الصفحة | Page ID · Page token |
| **Instagram** | الرسائل + النشر | IG Account ID · الصلاحيات |
| **Facebook Login for Business** | الربط الذاتي | `config_id_whatsapp` · `config_id_social` |

### 2.4 الصلاحيات

| الصلاحية | القناة | تحتاج Advanced Access؟ |
|---|---|---|
| `whatsapp_business_management` | WhatsApp | نعم |
| `whatsapp_business_messaging` | WhatsApp | نعم |
| `business_management` | عام (القراءة) | غالبًا نعم |
| `pages_messaging` | Messenger | نعم |
| `pages_show_list` | Messenger / Instagram | غالبًا لا |
| `pages_read_engagement` | Messenger / Instagram | نعم |
| `pages_manage_metadata` | Messenger (اشتراكات) | نعم |
| `pages_manage_posts` | النشر على الصفحة | نعم |
| `instagram_basic` | Instagram | نعم |
| `instagram_manage_messages` | رسائل Instagram | نعم |
| `instagram_content_publish` | النشر على Instagram | نعم |
| `leads_retrieval` | Meta Lead Ads | نعم |

> **قاعدة:** اطلب ما تستخدمه فقط. كل صلاحية إضافية = سؤال إضافي في المراجعة وسبب رفض محتمل.

### 2.5 التوكنات والمفاتيح

| القيمة | من أين | ملاحظة |
|---|---|---|
| `app_id` | App settings → Basic | معرّف عام |
| `app_secret` | App settings → Basic | سر خطير — يُدوَّر كل 90 يومًا |
| `verify_token` | تختاره أنت (64 حرفًا) | يجب أن يطابق حرفيًا بين Meta و AYDRA |
| System User token | Business settings → System users | أصول AYDRA والقوالب |
| `config_id_whatsapp` | Facebook Login for Business | إعداد الواتساب |
| `config_id_social` | Facebook Login for Business | إعداد Instagram/Messenger |
| توكن العميل (BISU) | Embedded Signup | صالح 60 يومًا في القالب الشائع |

### 2.6 التحقق والمراجعة

| المرحلة | لماذا | الأثر |
|---|---|---|
| **Business Verification** | التحقق من هوية الشركة ومستنداتها | شرط للـ Advanced Access |
| **App Review** | مراجعة كل صلاحية على حدة | تشغيل الأذونات لعملاء خارج أدوار التطبيق |
| **Go Live** | نشر التطبيق | يعمل مع أي مستخدم، لا الأدوار فقط |

### 2.7 روابط الويبهوك المطلوبة

من `routes/webhooks.php` (كلها عامة، محمية بالتحقق من التوقيع):

| الغرض | المسار | التحقق |
|---|---|---|
| واتساب — كل العملاء (Embedded Signup) | `GET/POST /webhooks/whatsapp/global` | `verify_token` من حقل `meta_app` + توقيع HMAC |
| واتساب — عميل محدد (ربط يدوي) | `GET/POST /webhooks/whatsapp/{token}` | توكن القناة |
| Messenger + Instagram | `GET/POST /webhooks/meta/{token}` | توكن الـ workspace |
| Meta Lead Ads | `GET/POST /webhooks/meta-leadgen/{token}` | توكن مستقل |

**حقول الاشتراك لواتساب** (من `config/whatsapp.php`) — تُسجّل كلها على الـ callback العام:

```
messages, message_template_status_update, phone_number_name_update,
phone_number_quality_update, account_update,
history, smb_app_state_sync, smb_message_echoes
```

> آخر ثلاثة حقول خاصة بـ **Coexistence** — تُضم في نفس الاشتراك حتى لا تُفقد أحداث الأرقام المربوطة أصلًا.

بعد ضبط الويبهوك، لازم **`POST /{waba_id}/subscribed_apps` لكل WABA** (و `POST /{page_id}/subscribed_apps` للصفحات) — بدونها لا تصلك أحداث ذلك العميل.

### 2.8 حقول الربط في AYDRA

`Admin → Integrations → Meta App` (المصدر: `app/Modules/Integrations/Models/IntegrationConfig.php`):

| الحقل | مطلوب | عند غيابه |
|---|---|---|
| `app_id` | نعم | كل طلبات Meta تفشل (403) |
| `app_secret` | نعم | فشل تبديل الكود + فشل التحقق من التوقيع |
| `system_user_token` | مستحسن | فشل عمليات النظام والقوالب |
| `verify_token` | مستحسن | فشل التحقق الأولي من ويبهوك واتساب |
| `config_id_whatsapp` | للربط الذاتي | زر الربط الذاتي لا يعمل (الربط اليدوي يبقى متاحًا) |
| `config_id_social` | للربط الذاتي | ربط Instagram/Messenger من داخل AYDRA لا يعمل |

### 2.9 إعدادات البيئة

| المتغير | الافتراضي | الوظيفة |
|---|---|---|
| `META_GRAPH_VERSION` | `v27.0` | نسخة Graph API لكل النداءات (`config/services.php` → `MetaGraph`) |
| `WHATSAPP_COEXISTENCE_ENABLED` | `true` | مفتاح تشغيل/إيقاف Coexistence على مستوى المنصة |
| `WHATSAPP_COEXISTENCE_HISTORY_WINDOW_HOURS` | `24` | نافذة طلب مزامنة السجل (مرة واحدة فقط) |
| `WHATSAPP_COEXISTENCE_MPS` | `18` | حد الإرسال الاحتياطي (Meta تسمح بـ 20 رسالة/ثانية) |

---

## 3. الحدود والمهل

| البند | القيمة |
|---|---|
| حد التطبيقات لكل حساب مطوّر | 15 |
| حد Onboarding قبل التحقق والمراجعة | 10 عملاء / 7 أيام |
| حد Onboarding بعد التحقق + المراجعة | 200 عميل / 7 أيام |
| حد القوالب (حساب غير مُتحقَّق) | 250 قالبًا لكل Messaging account |
| صلاحية كود Embedded Signup | 30 ثانية |
| صلاحية توكن العميل (القالب الشائع) | 60 يومًا |
| إعادة محاولة الويبهوك عند الفشل | حتى 7 أيام |
| أقصى حجم لحمولة الويبهوك | ~3 ميغابايت |
| نافذة خدمة العملاء | 24 ساعة |
| حد إرسال Coexistence | 20 رسالة/ثانية |
| **إيقاف Embedded Signup v2/v3** | **15 أكتوبر 2026** — استخدم v4 |
| حدود الرسائل لكل Business Portfolio | 250 → 2,000 → 10,000 → 100,000 → بلا حد |

---

## 4. روابط رسمية

| الموضوع | الرابط |
|---|---|
| التسجيل كمطوّر | https://developers.facebook.com/documentation/development/register |
| إنشاء تطبيق | https://developers.facebook.com/documentation/development/create-an-app |
| الإعدادات الأساسية | https://developers.facebook.com/documentation/development/create-an-app/app-dashboard/basic-settings |
| بدء WhatsApp Cloud API | https://developers.facebook.com/documentation/business-messaging/whatsapp/get-started |
| Embedded Signup | https://developers.facebook.com/documentation/business-messaging/whatsapp/embedded-signup/overview |
| إصدارات Embedded Signup | https://developers.facebook.com/documentation/business-messaging/whatsapp/embedded-signup/versions |
| Webhooks | https://developers.facebook.com/documentation/business-messaging/whatsapp/webhooks/overview |
| Access Tokens | https://developers.facebook.com/documentation/business-messaging/whatsapp/access-tokens |
| Tech Provider | https://developers.facebook.com/documentation/business-messaging/whatsapp/solution-providers/get-started-for-tech-providers |
| App Review للواتساب | https://developers.facebook.com/documentation/business-messaging/whatsapp/solution-providers/app-review |
| Business Verification | https://developers.facebook.com/documentation/development/release/business-verification |
| حدود الرسائل | https://developers.facebook.com/documentation/business-messaging/whatsapp/messaging-limits |
| أكواد أخطاء واتساب | https://developers.facebook.com/documentation/business-messaging/whatsapp/support/error-codes |
| Facebook Login for Business | https://developers.facebook.com/documentation/facebook-login/facebook-login-for-business |
| Messenger Platform | https://developers.facebook.com/documentation/business-messaging/messenger-platform/create-an-app |
| Instagram Platform | https://developers.facebook.com/documentation/instagram-platform/create-an-instagram-app |

---

## 5. نقاط خاصة بـ AYDRA

1. **تطبيق واحد لكل العملاء** — لا تطبيق لكل عميل. الأصول (WABA / Page / IG) منفصلة لكل عميل، والعزل داخل AYDRA على مستوى الـ workspace.
2. **سر مخصص لكل WABA** — الـ webhook يدعم `app_secret_override` لحسابات لها App مختلف أو سر مختلف.
3. **مسارات ويبهوك مختلفة حسب نوع الربط** — الربط الذاتي يستخدم المسار العام، والربط اليدوي يستخدم مسار بتوكن القناة.
4. **التحقق من التوقيع على الجسم الخام** — HMAC-SHA256 لـ `X-Hub-Signature-256` قبل أي تعديل على الطلب.
5. **`subscribed_apps` شرط مطلق** — الربط ينجح في اللوحة ولا تصل رسائل بدونها.
6. **Coexistence يحتاج نمط معالجة مختلف** — الحدث يعود بـ `FINISH_WHATSAPP_BUSINESS_APP_ONBOARDING` لا `FINISH`، ومزامنة السجل متاحة **24 ساعة فقط** بعد الربط.
7. **التطبيق يميل للفشل الصريح** — عند نقص إعدادات Meta يظهر `Meta credentials not configured` (403) بدلًا من الفشل الصامت.
8. **لا تفترض أن توكن العميل دائم** — جهّز تنبيهًا قبل انتهاء الـ 60 يومًا.
9. **الفوترة على العميل** — في نموذج Tech Provider كل عميل يضيف بطاقته؛ لا تمرّ رسوم Meta عبر AYDRA.
10. **حقوق النشر مطلوبة فقط إن كنت تنشر فعلًا** — احذف `instagram_content_publish` و `pages_manage_posts` من الإعداد إن لم تكن تستخدم النشر.
