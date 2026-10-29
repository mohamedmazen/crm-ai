# Your CRM vs. LeadHub

**A feature, positioning, and gap analysis**

| | Your CRM | LeadHub SaaS |
|---|---|---|
| Product | WhatsMine / CRM SAAS | LeadHub — Multi-Tenant SaaS CRM |
| Version reviewed | 1.9.0 | 2.0.14 (updated Oct 7, 2026) |
| Stack | Laravel 12, PHP 8.2+, Inertia 2 + React 19, Vite 6 | Laravel 13, PHP 8.4+, Filament 4 + Livewire 3 |
| Category | Omnichannel messaging, AI, social, and ecommerce platform | Lead-to-revenue sales CRM |
| Review date | October 29, 2026 | October 29, 2026 |

> **Method:** Claims about your CRM were verified by reading the codebase — routes, models, migrations, services, and React pages. Claims about LeadHub come from its official documentation and product page, not from a hands-on trial. See [Sources & caveats](#sources--caveats).

---

## Table of contents

1. [Executive summary](#executive-summary)
2. [Quick scorecard](#quick-scorecard)
3. [Section 1 — How the two systems differ](#section-1--how-the-two-systems-differ)
4. [Section 2 — Strengths and weaknesses](#section-2--strengths-and-weaknesses)
5. [Section 3 — Features LeadHub has that your CRM lacks](#section-3--features-leadhub-has-that-your-crm-lacks)
6. [Strategic recommendations](#strategic-recommendations)
7. [Sources & caveats](#sources--caveats)

---

## Executive summary

These products overlap at the lead, pipeline, automation, and multi-tenancy layers, but they are **not direct substitutes**. They have different centers of gravity:

- **Your CRM is a conversational commerce suite.** Its strongest assets are a real omnichannel inbox (WhatsApp Cloud API, Messenger, Instagram), 15 SMS providers, email/SMS/WhatsApp broadcasts, social publishing to six networks, Shopify/WooCommerce/BigCommerce sync, RAG chatbots with knowledge bases, a 30+ node messaging automation engine, 14 payment gateways, and a mobile API with push notifications.
- **LeadHub is a lead-to-revenue sales CRM.** Its strongest assets are CRM depth (companies, unlimited pipelines, deal value/probability, custom fields, tasks, win/loss reasons), 19 native lead sources, form/landing-page/booking surfaces, email sequences with stop-on-reply, 13 sales reports, AI sales coaching, and a shared-hosting-friendly deployment model.

**The practical verdict:**

| If the buyer needs… | Better fit |
|---|---|
| WhatsApp-first sales and support, social media management, ecommerce order context, AI chatbots, global SMS/payment coverage, a mobile app | **Your CRM** |
| Sales pipeline discipline, lead capture from ads and forms, meeting booking, quotes/invoices, revenue reporting, minimal hosting ops | **LeadHub** |

**The biggest strategic risk is not channel breadth — it is CRM depth.** Your CRM already has a single pipeline with custom stages, drag-and-drop, lead activities, follow-up dates, and rule-based scoring. That is a credible start, but it is not yet a sales system of record: there are no companies, no deal economics, no multiple pipelines, no lead ownership, no tasks, no lead custom fields, no dedupe/merge, no quotes/invoices, and no lead/pipeline/revenue reporting.

**The biggest opportunity is the conversation graph.** Your access to WhatsApp threads, social engagement, ecommerce orders, and chatbot transcripts is something LeadHub cannot easily replicate. The winning move is not to clone LeadHub feature-for-feature; it is to build a **sales spine on top of your messaging core** and turn conversation data into deal intelligence.

### If you only do five things

1. **Add the CRM spine:** companies, unlimited pipelines, deal value/currency/probability/expected close, win/loss reasons, lead custom fields.
2. **Make leads ownable:** assigned user, round-robin, tasks/reminders, tags, bulk actions, duplicate detection and merge.
3. **Add capture and conversion surfaces:** a no-code form builder, a public booking calendar, and the 6–8 lead sources your customers actually use.
4. **Add sales reporting:** lead volume, source performance, funnel, bottlenecks, revenue by rep, response time, plus scheduled CSV/PDF exports.
5. **Enforce plan gates and seat caps** so the new features become upgrade levers rather than free add-ons.

---

## Quick scorecard

| Capability | Your CRM | LeadHub | Edge |
|---|---|---|---|
| Omnichannel shared inbox | WhatsApp, Messenger, Instagram | Lead-centric conversations (email replies/manual) | **Yours** |
| Messaging automation depth | 30+ node types, 15 triggers | 8 triggers, sales-focused actions | **Yours** |
| Social publishing | 6 networks | None | **Yours** |
| Ecommerce sync | Shopify, WooCommerce, BigCommerce | None | **Yours** |
| AI chatbots / RAG | Multi-provider, knowledge bases, Qdrant | LeadBot website chatbot | **Yours** |
| Payment gateway breadth | 14 gateways | 5 + manual transfer | **Yours** |
| Mobile API / push | Yes | Not documented | **Yours** |
| Lead capture breadth | Google Places, CSV contacts, API, widgets | 19 native sources + IMAP + forms | **LeadHub** |
| CRM data model | Contacts + leads, single pipeline | Leads, companies, deals, tasks, custom fields | **LeadHub** |
| Sales workflow | Pipeline + activities + follow-up date | Pipelines, deals, tasks, sequences, quotes/invoices | **LeadHub** |
| Forms / landing pages / booking | Platform-level pages; automation-only booking | Tenant form builder, landing pages, booking calendar | **LeadHub** |
| Sales reporting | Channel reports only | 13 lead/pipeline/revenue reports | **LeadHub** |
| SaaS operator controls | Plans, coupons, tax, 14 gateways | Feature gates, seat caps, affiliate, marketplace | **LeadHub** |
| Shared-hosting deployment | Requires CLI/queue | Browser installer, file queue, cron | **LeadHub** |
| Compliance packaging | 2FA, audit, data export | DPA, cookie consent, Article 17/20, retention | **LeadHub** |
| Localization | Dynamic locales, RTL, auto-translate | 6 languages incl. Arabic RTL | **Tie / Yours** |
| API and webhooks | REST API, mobile API, webhook deliveries | REST API, scoped keys, HMAC webhooks | **Tie** |

---

## Section 1 — How the two systems differ

### 1.1 Product positioning

**Your CRM** is built around conversations and channels. The mental model is: a contact messages you on WhatsApp, Messenger, or Instagram; the team replies from a shared inbox; automations and AI handle the repetitive work; campaigns and social posts drive demand; ecommerce orders provide context. Leads and a pipeline were added later, which is why the CRM layer is thinner than the messaging layer.

**LeadHub** is built around the sales process. The mental model is: a lead arrives from an ad, form, email, or API; it is scored and assigned; it moves through a pipeline with a deal value; sequences and tasks drive follow-up; a quote becomes an invoice; reports show what converted and who closed it. Messaging exists mainly as a follow-up channel, not as the product's center.

**Practical difference:** your product wins when the customer's problem is "we live in WhatsApp and need to manage conversations, campaigns, social, and orders." LeadHub wins when the problem is "we need a sales CRM our team will actually use, and we do not want to run a server."

### 1.2 Architecture and stack

| | Your CRM | LeadHub |
|---|---|---|
| Backend | Laravel 12, PHP 8.2+ | Laravel 13, PHP 8.4+ |
| Frontend | Inertia 2 + React 19, Vite 6, Tailwind | Filament 4 + Livewire 3, Tailwind |
| UI model | Client-rendered SPA | Server-driven admin panel |
| Tenancy | Client → one or more Workspaces | Tenant workspace (subdomain) |
| Queue | Database queue; Redis optional | File-based queue via `cron.php` |
| Realtime | Laravel Reverb or Pusher (optional) | Not a core requirement |
| API | Sanctum, REST API v1, mobile endpoints | Scoped REST API, per-tenant rate limits |

The stack difference is not just technical. **Your SPA gives you a more app-like inbox and builder experience**; LeadHub's Filament/Livewire stack gives it **very fast CRUD development and a consistent admin panel**. LeadHub's newer stack (Laravel 13 / PHP 8.4) narrows its compatible hosting slightly, but its browser installer and file queue make it far easier to deploy on cheap shared hosting.

### 1.3 Tenancy, roles, and workspace model

**Your CRM:**
- A `Client` is the tenant; a client can have multiple `Workspace` records.
- Users belong to a client and have a `client_role` of `administrator` or `staff`; workspaces also have an owner/member pivot.
- Routes are path-based under `/app`; the tenant is resolved from the authenticated user's `client_id`.
- A `custom_domain` field exists on the client and is editable in branding, but it is **not used to resolve tenants** — there is no subdomain/domain middleware.
- Plans belong to the client; usage meters are per workspace.

**LeadHub:**
- Each tenant gets an isolated workspace at `acme.yourdomain.com/admin`.
- Six roles: super-admin, tenant owner, tenant admin, staff, viewer, customer.
- Query-level tenant isolation; super-admin impersonation is time-boxed and audited.
- Plans carry feature gates, usage limits, and seat caps.

**Practical difference:** your multi-workspace model is a genuine advantage for agencies that separate brands or departments. LeadHub's subdomain-per-tenant and six-role model is more polished as a resellable SaaS, but it is one workspace per tenant.

### 1.4 Lead capture and CRM data model

**Your CRM:**
- **Leads** are primarily scraped from Google Places (keyword + location, max 60 results per scrape), with phone, email, website, address, category, rating, review count, score, stage, activities, and a follow-up date.
- Leads can be pushed to **Contacts**, which are the richer record: tags, static/dynamic segments, opt-ins, custom fields as JSON, source, and a link back to the lead.
- There is **no Company entity**; no lead owner; no lead tags; no lead custom-field definitions; no lead import/export; no bulk actions.
- Contacts support CSV import/export and segment management.

**LeadHub:**
- 19 native lead sources, including Facebook/Instagram/TikTok/LinkedIn lead ads, Google/YouTube/Microsoft Ads, WhatsApp/Telegram/Viber, X/Snapchat/Pinterest, Typeform/JotForm/Calendly, IMAP email parsing, web forms, manual entry, and API/webhooks.
- A full lead record: company, source, status, pipeline/stage, assigned user, score, deal value/currency, expected close date, tags, notes, custom fields, tasks, sequences, and activity timeline.
- CSV/Excel import with column mapping; duplicate detection with fuzzy email/phone matching and merge.

**Practical difference:** your CRM can find leads, but it cannot ingest them from the places most sales teams buy them. LeadHub's capture layer is its strongest structural advantage.

### 1.5 Pipeline and sales execution

**Your CRM:**
- One pipeline per workspace, with stages seeded as New → Contacted → Qualified → Won → Lost.
- Stages are editable (name, color, order, won/lost flags); the board supports drag-and-drop, per-column ordering, stage-change events, and lead activities (note, call, WhatsApp, email, meeting) with outcomes.
- Leads have a `next_follow_up_at` date and a "due" filter/badge.
- Lead scoring is rule-based and driven by Google Places signals (rating, reviews, website, phone, etc.), with configurable weights and hot/warm/cold bands. It is **not behavioral**.

**LeadHub:**
- Unlimited pipelines per tenant, each with custom stages, colors, win/loss flags, default pipeline, and an unassigned column.
- Deal value, currency, probability, expected close date, win/loss reasons.
- Round-robin/load-balanced assignment; tasks, reminders, and a task calendar; lead status lifecycle separate from pipeline stage.
- Lead scoring based on field, behavior, and engagement triggers, with AI explanations.

**Practical difference:** your pipeline is a good visual follow-up board for scraped leads. LeadHub's pipeline is a revenue forecast and accountability tool.

### 1.6 Communication channels and inbox

**Your CRM:**
- A true shared inbox for **WhatsApp Cloud API, Messenger, and Instagram**: conversation assignment, statuses, internal notes, labels, canned replies, handover, typing indicators, media, message templates, interactive messages, WhatsApp Flows, catalog sharing, and a 24-hour window check.
- Email, SMS, and WhatsApp **broadcasts** with open/click/unsubscribe tracking.
- 15 SMS providers: Twilio, Nexmo, MessageBird, Plivo, Telnyx, Infobip, ClickSend, SMSBD, REVE, BulkSMSBD, SMS.net.bd, MimSMS, Fast2SMS, MSG91, Amazon SNS.
- No inbound email/IMAP parsing; no click-to-call/telephony; no call recording.
- Mobile API for the inbox, plus web push and OneSignal.

**LeadHub:**
- A lead-centric **Communications** area: conversations for email replies and manual messages, a call log, and an activity timeline.
- Click-to-call and SMS through Twilio or Plivo, per workspace; Twilio calls support recording and AI transcription/summary.
- WhatsApp and SMS are used mainly as automation actions and lead sources, not as a multi-channel live-chat inbox.

**Practical difference:** this is your clearest win. LeadHub cannot replace a WhatsApp/Messenger/Instagram shared inbox.

### 1.7 Automation and sequences

**Your CRM:**
- A visual automation builder with **30+ node types and 15 triggers**.
- Nodes include WhatsApp text/template/media/sequence, quick replies, list messages, SMS, email, questions, conditions, waits, webhooks, subflows, AI replies, tags, contact updates, agent assignment, campaigns, CTA buttons, locations, polls, chatbots, appointments, Google Meet, WhatsApp Flows, WhatsApp catalog, WooCommerce/Shopify product messages, Google Sheets, Google Docs, and Google Forms.
- Triggers include contact created, tag added, message received, campaign sent, form submitted, webhook received, order placed/fulfilled/cancelled, cart abandoned, customer created, and lead stage changed/qualified/won/lost.
- An AI workflow generator turns a natural-language request into a validated graph.

**LeadHub:**
- Trigger → condition → action/delay workflows with **8 triggers**: new lead, stage changed, assigned, tag added, score threshold, no activity, form submitted, manual.
- Actions include send email/SMS/WhatsApp, assign lead, add tag, update score, send webhook, create task, move stage, and Slack notification.
- **Email sequences** are a first-class object: enrollment, delays, conditions, stop-on-reply, stop-on-won, open/click/reply tracking, and per-sequence reporting.
- Business-hours-aware automations and run logs.

**Practical difference:** your engine is broader and more channel-rich; LeadHub's is narrower but has stronger sales-sequence semantics (especially stop-on-reply and sequence analytics).

### 1.8 Marketing, social, ecommerce

**Your CRM:**
- Social publishing and scheduling to Facebook, Instagram, LinkedIn, X/Twitter, TikTok, and YouTube, with a calendar and post analytics.
- Ecommerce sync with Shopify, WooCommerce, and BigCommerce: stores, products, orders, customer sync, order/cart triggers, and product sharing inside conversations.
- Email/SMS/WhatsApp campaigns with audience preview, test sends, scheduling, and tracking.

**LeadHub:**
- No social publishing/scheduling.
- No ecommerce store sync.
- Marketing is lead-capture and email-sequence oriented, with forms, landing pages, UTMs, and 38+ outbound integrations (CRM, email marketing, automation platforms, communication, and data tools).

**Practical difference:** if the buyer needs social media management or store-aware messaging, LeadHub is not a substitute.

### 1.9 AI

**Your CRM:**
- Multi-provider LLM support: OpenAI, Anthropic Claude, Google Gemini.
- RAG knowledge bases with Qdrant, document indexing, and chatbots deployable on the website and messaging channels.
- AI email generation and subject improvement, AI reply nodes, AI workflow generation, and AI usage reports.

**LeadHub:**
- AI Lead Coach: next-best-action suggestions, draft replies, score reasoning.
- At-risk detection, smart tags, subject-line optimizer.
- LeadBot website chatbot for visitor qualification and lead capture.
- AI SDR agent that follows up new leads by email until a human replies.
- AI call summaries/transcription for Twilio recordings.
- Optional and plan-gated; the CRM works without AI keys.

**Practical difference:** your AI is a **conversational/knowledge platform**; LeadHub's AI is a **sales co-pilot**. They solve different jobs.

### 1.10 Forms, landing pages, and booking

**Your CRM:**
- No native form builder. There is a WhatsApp click-to-chat widget, WhatsApp Flows, and a Google Forms automation node.
- A platform-level landing page and CMS pages managed from the super-admin panel — not a per-tenant landing-page builder.
- Booking exists only as automation actions (`book_appointment`, `google_meet`) that create Google Calendar events/Meet links. There is no public booking page, meeting-type configuration, or calendar UI.

**LeadHub:**
- A no-code form builder: multi-step, conditional logic, file uploads, QR codes, reCAPTCHA v3, embedded/standalone/widget forms, field mapping, pipeline-stage assignment, thank-you/redirect, and form analytics.
- A per-tenant landing-page builder with SEO meta, OG image, JSON-LD, and multilingual pages.
- Meeting types, public booking pages, availability/buffer rules, max bookings per day, bookings, calendar view, Google/Outlook two-way sync, and email/SMS reminders.

**Practical difference:** LeadHub owns the capture-to-meeting journey. Your CRM owns the conversation after the lead arrives.

### 1.11 Reporting

**Your CRM:**
- Reports for inbox, campaigns, automations, AI usage, and social.
- Inbox reports include conversation volume, channel mix, agent leaderboard, first-response times, and resolution times.
- Campaign reports include delivery, opens, clicks, and opt-outs.
- CSV exports for contacts, campaign recipients, conversations, AI runs, and the audit log.
- A weekly digest email; no report builder, no per-report PDF, no scheduled report delivery.

**LeadHub:**
- 13 reports: lead volume, source performance, pipeline funnel, agent performance, automation stats, source attribution, form analytics, response time, email sequences, pipeline bottlenecks, revenue by rep, scheduled reports, and audit log.
- Date-range filters, CSV/PDF exports, and scheduled daily/weekly/monthly CSV/PDF email digests.

**Practical difference:** your reporting is channel-operations reporting; LeadHub's is sales-management reporting. Both are useful, but they answer different questions.

### 1.12 Billing and monetization

**Your CRM:**
- 14 payment gateways: Stripe, PayPal, Paddle, Razorpay, Cashfree, Tap, Paystack, Xendit, Paymob, MyFatoorah, Mollie, Square, iyzico, Mercado Pago.
- Plans with monthly/yearly pricing, coupons, tax rates, subscription invoices, recurring charges, trials, dunning events, and refunds.
- Plan limits exist for many resources (users, storage, WhatsApp accounts/templates/messages, campaigns, SMS, emails, social posts, AI tokens, knowledge bases, lead credits), but **only six meters are enforced in routes**: campaigns, WhatsApp messages, AI tokens, knowledge bases, social posts, and lead credits. Feature gating is limited to `white_label`; seat caps are not enforced.

**LeadHub:**
- 5 gateways: Stripe, PayPal, Razorpay, Paystack, and manual bank transfer.
- Plans with monthly/annual pricing, feature gates, usage limits, and seat caps; section-level hiding per plan.
- Coupons, trials, dunning, VAT/US sales tax, sequential invoice numbers, credit notes, and an affiliate engine with 20% recurring commission.
- A marketplace whose installs can be plan-gated.

**Practical difference:** you have far broader payment coverage; LeadHub has tighter SaaS monetization controls and a more complete operator toolkit.

### 1.13 Platform administration

**Your CRM (super admin):**
- Clients, plans, subscriptions, payments/refunds, 14 gateway configs, coupons, tax rates, locales/translations with auto-translate, currencies, email system/SMTP, system settings, license/updater, audit log, roles/permissions, platform landing page, CMS pages, support tickets, cron setup, queue monitor, Pusher settings, integrations, AI dashboard, and admin users.
- Client impersonation and per-client branding.

**LeadHub (super admin):**
- Platform dashboard with tenants, active subscriptions, MRR/ARR, churn, recent activity, and lead-source mix.
- Tenant create/suspend/activate/restore/hard-delete/refund, plan builder with feature gates and limits, coupons/tax, gateway credentials, SMTP/branding/landing-page/email-template settings, a templates library, affiliate payouts, audit logs with retention, verifiable database backups, outbound webhook delivery logs, and an IP/CIDR allowlist.

**Practical difference:** your admin is broader in localization, email, licensing, support, and gateway breadth. LeadHub's is more focused on SaaS revenue operations and tenant lifecycle.

### 1.14 Security, privacy, and compliance

**Your CRM:**
- TOTP two-factor authentication with recovery codes, session management, audit logs, secure headers, request IDs, Sentry, Sanctum API tokens with abilities, role/permission middleware, demo-mode PII masking, and a workspace data export.
- No documented DPA download, cookie-consent manager, Article 17 erasure workflow, or retention-policy engine.

**LeadHub:**
- TOTP 2FA with per-role enforcement, brute-force lockout, trusted-proxy support, super-admin IP allowlist, encrypted webhook secrets, HMAC/replay-safe webhooks, scoped API keys with rate limits, six roles, audit trail, breach-check password policy, email-domain DNS verification.
- GDPR suite: DPA download, cookie consent categories, Article 20 ZIP export, Article 17 30-day erasure with cool-off, and retention policies for audit logs/login attempts/webhook events.

**Practical difference:** both are security-conscious, but LeadHub packages compliance for B2B procurement more completely.

### 1.15 Deployment and operations

**Your CRM:**
- Requires PHP 8.2+, Composer, a built frontend, a queue worker, and cron. Redis/Reverb are optional. There is no browser installer.
- A Docker Compose file exists for queue workers.
- Better suited to a VPS, Laravel Herd/Cloud, or a managed environment than to cheap cPanel hosting.

**LeadHub:**
- Browser-driven installer; no SSH, Composer, npm, Redis, or Supervisor.
- File-based queue processed by `cron.php`; sync mail driver fallback; storage fallback for hosts without symlinks; verifiable backups; health endpoint.
- Designed for shared hosting and low-ops buyers.

**Practical difference:** LeadHub can be installed by a non-technical buyer in minutes; your CRM needs a technical operator. That is a real distribution advantage for LeadHub in the CodeCanyon market.

### 1.16 Extensibility, API, and ecosystem

**Your CRM:**
- REST API v1 with Sanctum abilities; endpoints for mobile auth, conversations, messages, contacts, segments, campaigns, automations, AI chatbots/knowledge bases, social posts, analytics, audit log, notifications, workspaces, and subscriptions.
- Outbound webhook endpoints with secret rotation, test delivery, and delivery logs.
- API docs via Scramble.
- Social login with Google, GitHub, and Microsoft.

**LeadHub:**
- REST API for leads, pipelines, deals, forms, automations, sequences, and webhooks; scoped API keys; per-tenant rate limits (60 req/min default); interactive docs.
- Outbound webhooks on `lead.created`, `lead.stage_changed`, `deal.won`, `form.submitted`, `automation.triggered`, with HMAC signatures, encrypted secrets, delivery logs, retries, and dead-letter alerts.
- 38+ native outbound integrations and a marketplace of templates.

**Practical difference:** your API is stronger for messaging/mobile use cases; LeadHub's is stronger for CRM objects and sales-event webhooks.

### 1.17 Localization and branding

**Your CRM:**
- A locale manager with English and Arabic seeded (RTL), the ability to add locales, per-user/workspace locale, translation management, and AI-assisted auto-translation.
- Per-client branding: logo, colors, app name, custom domain field, support email, and white-label gating.

**LeadHub:**
- Six languages: English, Spanish, Arabic with RTL, Hindi, French, Brazilian Portuguese; public pages and emails localized.
- Per-tenant logo, favicon, primary/accent colors, app name, footer, dark mode, per-tenant currency, and OG image.

**Practical difference:** your localization system is more flexible and operator-controlled; LeadHub's is more productized for public-facing pages.

---

## Section 2 — Strengths and weaknesses

### 2.1 Your CRM — strengths

1. **A real omnichannel inbox.** WhatsApp Cloud API, Messenger, and Instagram in one shared inbox with assignment, notes, labels, canned replies, handover, templates, and media. This is the hardest capability for LeadHub to match.
2. **Deep messaging automation.** 30+ node types, 15 triggers, interactive WhatsApp messages, Flows, catalogs, commerce product messages, Google Workspace actions, subflows, and an AI workflow generator.
3. **Channel breadth.** 15 SMS providers and six social networks, including publishing/scheduling and analytics.
4. **Ecommerce context.** Shopify, WooCommerce, and BigCommerce sync with order/cart triggers and product sharing inside conversations.
5. **RAG AI.** Multi-provider LLM support, Qdrant-backed knowledge bases, document indexing, and chatbots that can run on the website and messaging channels.
6. **Billing breadth.** 14 gateways covering Stripe/PayPal/Paddle plus strong regional coverage (Razorpay, Cashfree, Paystack, Xendit, Paymob, MyFatoorah, iyzico, Mercado Pago, Tap, Mollie, Square).
7. **Mobile and push.** A mobile API for the inbox plus web push and OneSignal.
8. **Multi-workspace tenancy.** Agencies can separate brands/departments under one client.
9. **Operator tooling.** Plans, coupons, tax, currencies, email templates/SMTP, license updater, support tickets, queue/cron monitoring, audit logs, and a platform CMS.
10. **Modern SPA UX.** React 19 + Inertia gives the inbox and automation builder a fast, app-like feel.
11. **Localization flexibility.** Dynamic locales, RTL, and auto-translation are stronger than a fixed six-language list.
12. **Segments and broadcasts.** Static/dynamic segments and multi-channel campaigns with tracking are more advanced than LeadHub's sequence-only marketing.

### 2.2 Your CRM — weaknesses

1. **CRM depth is shallow.** No companies, deals, products/quotes/invoices, tasks, lead custom fields, lead ownership, or multiple pipelines.
2. **Lead capture is narrow.** Google Places scraping, manual entry, contacts CSV, API, and widgets — but no native ad-platform ingestion, IMAP parsing, or form builder.
3. **The pipeline is single.** One board per workspace; no per-product/per-segment pipelines.
4. **No deal economics.** No deal value, probability, expected close date, weighted pipeline, or win/loss reasons.
5. **Leads are unassigned.** There is no `assigned_user_id` on leads; assignment exists for conversations and automation actions, not lead ownership.
6. **No task management.** `next_follow_up_at` and activity logs are useful, but there is no task entity, calendar, reminders, or completion workflow.
7. **No duplicate/merge workflow.** A read-only `inbox:duplicate-report` command exists for inbox conversations/contacts, but there is no lead dedupe/merge UI.
8. **Scoring is not behavioral.** It uses Google Places signals, not email opens, clicks, replies, page views, or conversation engagement.
9. **No lead import/export or bulk actions.** Contacts have CSV import/export; leads do not.
10. **No form builder, tenant landing pages, or public booking.** Google Forms, WhatsApp Flows, and automation-only calendar actions are partial workarounds.
11. **No email sequence object.** Campaigns and automation sends exist, but no enrollment, stop-on-reply, stop-on-won, or per-sequence analytics.
12. **No telephony.** No click-to-call, call recording, or transcription; call logging is a manual activity type.
13. **Sales reporting is missing.** No lead volume, source performance, funnel, bottlenecks, source attribution, revenue by rep, or lead response-time reports.
14. **Plan enforcement is incomplete.** Only `white_label` is feature-gated; seat caps and most limits are display-only or unenforced.
15. **Custom domain is not wired.** The field exists, but tenant routing is path-based.
16. **No shared-hosting installer.** Requires CLI, Composer build, and a queue worker.
17. **Compliance packaging is thinner.** No DPA, cookie consent, Article 17 erasure, or retention policies.
18. **No marketplace or affiliate engine.** No one-click templates or recurring referral commissions.
19. **No subdomain-per-tenant.** Harder to sell as a branded, isolated SaaS to non-technical buyers.
20. **No six-role model.** Two client roles plus workspace owner/member; no viewer or customer role.

### 2.3 LeadHub — strengths

1. **CRM depth.** Leads, companies, unlimited pipelines, deal value/probability/close date, win/loss reasons, custom fields, tasks, notes, tags, and activity timeline.
2. **Capture breadth.** 19 native lead sources, IMAP parsing, web forms, Calendly, Typeform, JotForm, ad platforms, and API/webhooks.
3. **Capture-to-meeting journey.** Form builder, landing pages, lead widgets, booking pages, meeting types, calendar sync, and reminders.
4. **Sales automation.** Email sequences with stop-on-reply/stop-on-won and engagement tracking; trigger/condition/action workflows; round-robin assignment; business hours.
5. **Sales reporting.** 13 reports covering volume, sources, funnel, agent performance, attribution, form analytics, response time, sequences, bottlenecks, and revenue by rep, plus scheduled CSV/PDF.
6. **Sales AI.** Lead Coach, at-risk detection, smart tags, subject optimizer, AI SDR, LeadBot, and call summaries.
7. **SaaS operator controls.** Feature gates, section hiding, seat caps, coupons, tax/VAT, dunning, affiliate engine, marketplace, backups, audit retention, IP allowlist.
8. **Shared-hosting deployment.** Browser installer, file queue, cron, no SSH/Composer/npm/Redis.
9. **Tenant isolation and branding.** Subdomain per tenant, six roles, white-label, per-tenant public pages.
10. **Compliance packaging.** DPA, cookie consent, Article 17/20, retention policies, breach-check passwords.
11. **Localization.** Six languages including Arabic RTL, with localized public pages and emails.
12. **Low entry price.** $34 one-time standard license (or $6/mo Pro billed yearly), 58 sales, 4.43/5 rating as of October 2026.

### 2.4 LeadHub — weaknesses

1. **Not an omnichannel inbox.** Communications is lead-centric: email replies, manual messages, and a call log. It does not replace a WhatsApp/Messenger/Instagram shared inbox.
2. **No social publishing.** No scheduling, calendar, or social analytics.
3. **No ecommerce store sync.** No Shopify/WooCommerce/BigCommerce order context or cart recovery.
4. **Narrower messaging stack.** WhatsApp/SMS via Twilio/Plivo; no 15-provider SMS coverage, no WhatsApp Flows/catalog depth, no Messenger/Instagram DMs.
5. **Fewer payment gateways.** Five plus manual transfer, versus your 14.
6. **Sales-centric automation.** Eight triggers and a sales action set; less rich for messaging/commerce workflows.
7. **AI is sales-focused.** LeadBot is a website lead-capture chatbot, not a RAG knowledge-base platform across channels.
8. **File-based queue.** Simple to deploy, but weaker for high-volume sending and real-time workloads.
9. **Newer stack, smaller ecosystem.** Laravel 13 / PHP 8.4 / Filament 4 narrows hosting and developer familiarity compared with Laravel 12 / PHP 8.2.
10. **Small commercial footprint.** 58 sales and 7 ratings; support depth and bus factor are risks.
11. **No documented mobile API.** Responsive admin, but no mobile inbox/app API.
12. **One workspace per tenant.** No multi-workspace model for agencies with multiple brands.
13. **Limited channel reporting.** No inbox agent leaderboard, campaign funnels, social analytics, or AI usage reports.
14. **No native helpdesk.** Feature requests exist, but no tenant support-ticket module.
15. **No social login documented.** Email/password and 2FA, but no Google/GitHub/Microsoft sign-in.

### 2.5 Feature matrix

Legend: ✅ present; ⚠️ partial/workaround; ❌ not found; — not documented.

| Capability | Your CRM | LeadHub | Edge |
|---|---|---|---|
| Multi-tenant SaaS | ✅ Client → Workspace | ✅ Tenant workspace | Tie |
| Subdomain per tenant | ❌ path-based `/app` | ✅ `tenant.domain.com` | LeadHub |
| Multiple workspaces per tenant | ✅ | ❌ one per tenant | Yours |
| Roles | ⚠️ administrator/staff + workspace owner/member | ✅ 6 roles | LeadHub |
| White-label per tenant | ✅ | ✅ | Tie |
| Plan feature gates | ⚠️ white-label + 6 usage meters | ✅ gates, section hiding, seat caps | LeadHub |
| Audit log | ✅ | ✅ | Tie |
| Impersonation | ✅ | ✅ time-boxed + audited | LeadHub |
| Contacts | ✅ tags, segments, custom fields, opt-ins | ✅ leads with tags/custom fields | Tie |
| Lead management | ✅ scraped leads + pipeline | ✅ full lead entity | LeadHub |
| Companies | ❌ | ✅ | LeadHub |
| Lead ownership/assignment | ❌ | ✅ + round-robin | LeadHub |
| Multiple pipelines | ❌ single | ✅ unlimited | LeadHub |
| Deal value/probability/close date | ❌ | ✅ | LeadHub |
| Win/loss reasons | ⚠️ won/lost stages only | ✅ reasons + reporting | LeadHub |
| Lead custom fields | ❌ | ✅ definitions + values | LeadHub |
| Tasks/reminders | ⚠️ follow-up date + activities | ✅ tasks + calendar + reminders | LeadHub |
| Duplicate detection/merge | ⚠️ read-only inbox report | ✅ lead dedupe + merge | LeadHub |
| Lead scoring | ✅ Google Places rules | ✅ field/behavior/engagement + AI | LeadHub |
| Lead import/export | ⚠️ contacts CSV; leads scraped | ✅ lead CSV/Excel mapping | LeadHub |
| Lead bulk actions | ❌ | ✅ assign/tag/delete/run automation | LeadHub |
| Native lead sources | ⚠️ Google Places + API/manual/widgets | ✅ 19 sources | LeadHub |
| IMAP email parsing | ❌ | ✅ | LeadHub |
| Form builder | ❌ | ✅ multi-step/conditional/files/QR/reCAPTCHA | LeadHub |
| Embeddable lead widget | ⚠️ WhatsApp click-to-chat | ✅ form/widget/JS snippet | LeadHub |
| Tenant landing pages | ❌ platform-level only | ✅ builder + SEO/OG/JSON-LD | LeadHub |
| Booking/meetings | ⚠️ Google Calendar/Meet automation actions | ✅ meeting types/bookings/calendar/2-way sync | LeadHub |
| Web tracking/UTM | ❌ | ✅ | LeadHub |
| Source health | ❌ | ✅ | LeadHub |
| Shared inbox (WhatsApp/Messenger/IG) | ✅ | ❌ email replies/manual | Yours |
| WhatsApp templates/interactive/flows/catalog | ✅ | ⚠️ templates via automation | Yours |
| SMS providers | ✅ 15 | ⚠️ Twilio/Plivo | Yours |
| Email campaigns/broadcast | ✅ | ⚠️ sequences/automation | Yours |
| Email sequences (stop-on-reply/won) | ❌ | ✅ | LeadHub |
| Click-to-call/telephony | ❌ | ✅ Twilio/Plivo + recording/transcription | LeadHub |
| Call log | ⚠️ manual activity type | ✅ + telephony | LeadHub |
| Social publishing | ✅ 6 networks | ❌ | Yours |
| Ecommerce sync | ✅ Shopify/Woo/BigCommerce | ❌ | Yours |
| Mobile API/push | ✅ | — | Yours |
| Support tickets | ✅ | ❌ feature requests only | Yours |
| Visual automation builder | ✅ | ✅ | Tie |
| Automation breadth | ✅ 30+ nodes/15 triggers | ⚠️ 8 triggers, sales actions | Yours |
| AI workflow generator | ✅ | ❌ | Yours |
| RAG chatbots/knowledge bases | ✅ | ⚠️ LeadBot website chatbot | Yours |
| AI sales coach/SDR | ❌ | ✅ | LeadHub |
| AI call summaries | ❌ | ✅ | LeadHub |
| Smart tags/at-risk/subject optimizer | ❌ | ✅ | LeadHub |
| Products/quotes/invoices (tenant) | ❌ ecommerce orders + subscription invoices | ✅ | LeadHub |
| Lead/pipeline/funnel reports | ❌ | ✅ | LeadHub |
| Source attribution/revenue by rep | ❌ | ✅ | LeadHub |
| Channel/inbox/campaign/social/AI reports | ✅ | ⚠️ automation/email reports | Yours |
| Scheduled report emails | ⚠️ weekly digest only | ✅ daily/weekly/monthly CSV/PDF | LeadHub |
| CSV/PDF exports | ⚠️ CSV; PDF for invoices | ✅ CSV/PDF per report | LeadHub |
| Payment gateways (platform billing) | ✅ 14 | ⚠️ 5 + manual | Yours |
| Coupons/tax/plans | ✅ | ✅ | Tie |
| Affiliate engine | ❌ | ✅ 20% recurring | LeadHub |
| Marketplace/templates | ❌ | ✅ | LeadHub |
| Backups UI/health | ⚠️ artisan command + `/up` | ✅ verifiable backups + health endpoint | LeadHub |
| GDPR suite | ⚠️ export/audit/2FA | ✅ DPA/cookie/Article 17/20/retention | LeadHub |
| Shared-hosting installer | ❌ | ✅ browser installer/file queue/cron | LeadHub |
| Redis/scalable queue | ✅ | ❌ file queue | Yours |
| Localization | ✅ dynamic locales + RTL + auto-translate | ✅ 6 languages incl. RTL | Tie/Yours |
| API/webhooks | ✅ | ✅ | Tie |
| Social login | ✅ Google/GitHub/Microsoft | — | Yours |
| Demo mode | ✅ | ✅ demo site | Tie |
| License updater | ✅ | ⚠️ browser updates | Yours |

### 2.6 Where each wins

| Scenario | Better fit | Why |
|---|---|---|
| WhatsApp-first sales/support team | **Your CRM** | Shared inbox, templates, Flows, catalog, 24-hour window handling. |
| Social media agency | **Your CRM** | Six-network publishing, calendar, and analytics. |
| Ecommerce brand | **Your CRM** | Store sync, order/cart triggers, product sharing, contact enrichment. |
| AI chatbot / knowledge-base product | **Your CRM** | Multi-provider RAG, Qdrant, document indexing, channel deployment. |
| Global SaaS needing local payments | **Your CRM** | 14 gateways vs. 5. |
| Sales team needing pipeline discipline | **LeadHub** | Deal values, probabilities, tasks, ownership, win/loss reasons. |
| Lead-gen agency buying ads | **LeadHub** | 19 sources, forms, UTMs, source attribution. |
| Consultancy selling meetings | **LeadHub** | Booking pages, calendar sync, reminders. |
| Small business on cPanel | **LeadHub** | Browser installer, file queue, no SSH. |
| Agency with multiple brands | **Your CRM** | Multiple workspaces per client. |
| Sales-led SaaS reseller | **LeadHub** | Plan gates, seat caps, affiliate, marketplace, subdomains. |

---

## Section 3 — Features LeadHub has that your CRM lacks

### 3.1 How to read this section

- **P0** — table stakes for competing as a sales CRM; without it, the pipeline feels like a follow-up board rather than a system of record.
- **P1** — materially improves win rate, ARPU, or sales credibility.
- **P2** — platform maturity, compliance, or enterprise readiness.
- **P3** — nice-to-have / later differentiation.
- **Effort** — S (under a sprint), M (1–3 sprints), L (3+ sprints). Directional, assuming one experienced Laravel/React engineer.

### 3.2 CRM core (P0)

| Gap | What LeadHub does | Why it matters | Effort | Priority |
|---|---|---|---|---|
| **Companies/organizations** | Company records linked to leads, with dedupe and association | B2B deals usually involve multiple people at one company; without it, the CRM cannot represent an account | M | P0 |
| **Multiple pipelines** | Unlimited pipelines per tenant, each with stages, default flag, and win/loss stages | Different products, segments, or onboarding/support flows need different boards | M | P0 |
| **Deal economics** | Deal value, currency, probability, expected close date | Makes the pipeline a forecast, not just a task board; enables weighted pipeline and revenue reporting | M | P0 |
| **Win/loss reasons** | Captured at close and reportable | Turns lost deals into product/process feedback | S | P0 |
| **Lead custom fields** | Field definitions + per-lead values + import mapping | Every sales team has its own qualification fields; JSON-only contact fields are not enough | M | P0 |
| **Lead ownership + round-robin** | Assigned user, load-balanced/round-robin distribution | Accountability and response-time SLAs; currently leads have no owner | M | P0 |
| **Tasks/reminders + calendar** | Task entity, due dates, completion, task calendar, reminders | Follow-up dates alone do not create a work queue; sales teams live in tasks | M | P0 |
| **Duplicate detection/merge** | Fuzzy email/phone matching, merge preserving activity | Prevents the same lead from being worked twice and corrupting reporting | M | P0 |
| **Lead tags + bulk actions** | Tag, assign, delete, run automation on selected leads | Operational hygiene at scale | S | P0 |
| **Lead import/export** | CSV/Excel import with column mapping; export | Migration from other CRMs and list purchases are common entry points | S | P0 |

### 3.3 Capture and conversion (P0/P1)

| Gap | What LeadHub does | Why it matters | Effort | Priority |
|---|---|---|---|---|
| **No-code form builder** | Multi-step, conditional, file upload, QR, reCAPTCHA, embed/standalone/widget, field mapping, pipeline stage, analytics | Forms are the lowest-friction lead capture surface; Google Forms/WhatsApp Flows are not a substitute for a branded, embeddable form | L | P0 |
| **Public booking/meeting types** | Meeting types, availability, buffers, max/day, booking page, calendar, Google/Outlook sync, reminders | Service businesses convert leads into meetings; without booking, the CRM stops before revenue | L | P0 |
| **Tenant landing pages** | Per-tenant builder with SEO/OG/JSON-LD, multilingual pages | Campaigns need a landing page that creates a lead, not just a platform marketing page | L | P1 |
| **Web tracking/UTM attribution** | Page views, UTM capture, source attribution | Without attribution, "where did this lead come from?" is guesswork | M | P1 |
| **Top native lead sources** | Facebook/Instagram/TikTok/LinkedIn/Google/YouTube/Microsoft Ads, WhatsApp/Telegram, Typeform/JotForm/Calendly, IMAP, API | You do not need all 19 on day one; the top 6–8 cover most demand | L | P1 |
| **IMAP email parsing** | Turns inbound email into leads/conversations | Many SMBs still receive enquiries by email; no IMAP means missing leads | M | P1 |
| **Source health** | Connection status, lead counts, errors per source | Silent source failures are a common support burden | S | P1 |
| **Lead widgets** | Floating/embedded capture widgets with branding | Converts website traffic without a full landing page | M | P1 |
| **Embeddable form JS snippet** | Cross-origin submit, brand colors, multi-step, files, reCAPTCHA | Needed for agencies and external websites | M | P1 (with forms) |

### 3.4 Sales and revenue (P1)

| Gap | What LeadHub does | Why it matters | Effort | Priority |
|---|---|---|---|---|
| **Products/quotes/invoices** | Product catalog, quote line items, discounts, PDF/share, quote→invoice, paid status | Closes the loop from lead to cash; today you only have subscription invoices and synced ecommerce orders | L | P1 |
| **Lead/pipeline/funnel reports** | Lead volume, source performance, funnel conversion, bottlenecks | Shows whether the pipeline is healthy and where deals stall | M | P1 |
| **Source attribution** | UTM source/campaign with conversion and revenue | Tells marketing which spend creates revenue, not just leads | M | P1 |
| **Revenue by rep / agent performance** | Won value, pipeline value, win rate per rep | Needed for sales coaching and compensation | M | P1 |
| **Response-time/SLA report** | Time-to-first-contact distribution (median, P90, buckets) | Speed-to-lead is one of the highest-leverage sales metrics | S | P1 |
| **Scheduled reports** | Daily/weekly/monthly CSV/PDF email digests | Keeps owners and clients informed without logging in | M | P1 |
| **Per-report CSV/PDF export** | Export respects filters | Required for client reporting and board decks | S | P1 |
| **Email sequences** | Enrollment, delays, conditions, stop-on-reply, stop-on-won, open/click/reply tracking, per-sequence report | Campaigns are one-shot; sequences nurture leads over time | L | P1 |
| **Click-to-call + call log** | Twilio/Plivo calling, recording, transcription, AI summary, call log | Sales teams still call; logging is currently manual | L | P1 |
| **Lead status lifecycle** | New/Contacted/Qualified/Converted/Lost separate from pipeline stage | Useful for reporting and automation, especially across multiple pipelines | S | P1 |

### 3.5 Engagement and AI (P1/P2)

| Gap | What LeadHub does | Why it matters | Effort | Priority |
|---|---|---|---|---|
| **AI Lead Coach** | Next-best-action, draft reply, score reasoning | Turns AI into a sales productivity tool, not just a chatbot | M | P2 |
| **At-risk detection** | Engagement decay, missed touchpoints, sentiment shifts | Proactive retention/revival, hard to build without behavioral data | M | P2 |
| **Smart tags / subject optimizer** | Suggests tags and A/B subject lines | Saves rep time and improves email performance | S | P2 |
| **AI SDR** | Autonomous email follow-up until a human replies | High marketing value; requires strong guardrails and deliverability | L | P2 |
| **AI call summaries** | Transcription + recap + recommended next action | Makes telephony actionable; depends on click-to-call | M | P2 |
| **LeadBot website chatbot** | Qualifies visitors and captures leads into the pipeline | You have RAG chatbots, but not a lead-capture-specific website bot | M | P2 |
| **Date-reached trigger** | Birthday/anniversary automations (LeadHub v2.0.10) | Simple lifecycle automation; your triggers are event-based only | S | P2 |
| **Business-hours-aware automations** | Skips triggers outside configured hours | Prevents off-hours sends and supports SLAs | S | P1 |

### 3.6 SaaS operator and compliance (P1/P2)

| Gap | What LeadHub does | Why it matters | Effort | Priority |
|---|---|---|---|---|
| **Enforced plan feature gates** | Per-plan access to sources, AI, marketplace, white-label, API, seats | Today only `white_label` is gated; new features would be given away | M | P1 |
| **Seat caps** | Plan-limited team size with upgrade prompt | Team seats are a natural pricing lever; currently not enforced | S | P1 |
| **Section-level plan hiding** | Hides Reports, Brand, Communications, Advanced, Tools per tier | Lets you package a lighter tier without code branches | M | P2 |
| **Affiliate engine** | Referral codes, 20% recurring commission, payout audit | A growth channel you do not have | M | P2 |
| **Marketplace/templates** | One-click automations, pipelines, forms, sequences | Speeds onboarding and creates a content moat | L | P2 |
| **GDPR suite** | DPA download, cookie consent, Article 17 erasure, Article 20 ZIP export, retention | Required for EU B2B procurement; export alone is not enough | M | P2 |
| **Email domain verification** | DNS TXT challenge | Reduces spoofing and improves deliverability | S | P2 |
| **IP/CIDR allowlist** | Super-admin access restriction with strict mode | Enterprise security ask | S | P2 |
| **Breach-check password policy** | Minimum length/complexity + breach database check | Stronger signup security | S | P2 |
| **Verifiable backups UI** | Downloadable backups with statement counts | Operational confidence without SSH | M | P2 |
| **System health endpoint** | Probes DB, cache, storage; 200/503 | Faster support triage | S | P2 |
| **Subdomain-per-tenant routing** | `tenant.domain.com` isolation | Branded, isolated SaaS experience; your custom domain field is not wired | M | P2 |
| **Shared-hosting installer** | Browser install, file queue, cron, no SSH/Composer/npm | Opens the CodeCanyon/shared-hosting market | L | P2 |
| **Six-role model** | Super-admin, owner, admin, staff, viewer, customer | Granular access for larger teams and client portals | M | P2 |
| **Customer role/portal** | A limited customer-facing role | Useful for agencies and client collaboration | M | P3 |

### 3.7 Nice-to-have (P3)

| Gap | What LeadHub does | Why it matters | Effort | Priority |
|---|---|---|---|---|
| **Feature requests tool** | Submit/vote on product ideas | Community engagement; not core to sales | S | P3 |
| **Industry packs** | Prebuilt pipelines/automations by vertical | Faster time-to-value for niche SaaS | M | P3 |
| **Per-tenant OG images** | Generated social preview images | Better sharing/SEO for tenant landing pages | S | P3 |
| **Multi-language public pages** | EN/ES/AR/HI/FR/PT public surfaces | You have dynamic locales; public-page localization is less complete | M | P3 |
| **Lead source health dashboard** | Real-time connection status and counts | Already listed as P1; keep if you add sources | S | P3 |

### 3.8 Top 10 by ROI

1. **Multiple pipelines + deal value/probability/expected close** — converts the board into a forecast.
2. **Lead custom fields + import/export + tags/bulk actions** — makes the CRM usable for real sales data.
3. **Lead ownership + round-robin + tasks/reminders** — creates accountability and a daily work queue.
4. **Companies** — unlocks B2B account management.
5. **Duplicate detection/merge** — protects data quality and reporting.
6. **Form builder + embeddable widget** — creates a native lead capture surface.
7. **Public booking/meeting types** — closes the lead-to-meeting gap.
8. **Lead/pipeline/funnel/source/revenue reports + scheduled exports** — proves ROI to buyers.
9. **Plan feature gates + seat caps** — monetizes the new capabilities.
10. **Email sequences** — turns one-shot campaigns into sustained nurture.

### 3.9 Suggested roadmap

**Phase 1 — CRM spine (4–6 weeks)**
- Companies, multiple pipelines, deal fields, win/loss reasons.
- Lead custom fields, ownership, round-robin, tags, bulk actions, import/export.
- Tasks/reminders, activity integration, duplicate detection/merge.
- Lead status lifecycle and lead-level filters.

**Phase 2 — Capture and conversion (4–6 weeks)**
- Form builder with embed/standalone/widget, conditional logic, files, reCAPTCHA.
- Public booking pages, meeting types, calendar sync, reminders.
- Tenant landing pages and UTM/web tracking.
- Top 6–8 lead sources + IMAP + source health.

**Phase 3 — Revenue and reporting (4–6 weeks)**
- Products, quotes, invoices, quote→invoice, PDF/share.
- Lead/pipeline/funnel/source/revenue/response-time reports.
- Scheduled CSV/PDF reports.
- Email sequences with stop-on-reply/stop-on-won and analytics.
- Click-to-call and call logging.

**Phase 4 — Platform maturity (ongoing)**
- Plan gates, seat caps, section hiding.
- Affiliate engine, marketplace, templates.
- GDPR suite, domain verification, IP allowlist, breach-check passwords.
- Subdomain routing, shared-hosting installer, backups UI/health.
- AI Lead Coach, at-risk, smart tags, AI SDR, call summaries.

> These phases assume one experienced full-stack engineer plus design/QA support. If the team is smaller, ship Phase 1 in slices: companies + deal fields first, then ownership/tasks, then custom fields/dedupe.

---

## Strategic recommendations

### 1. Do not clone LeadHub

Trying to match 19 sources, 38 integrations, a marketplace, an affiliate engine, and a shared-hosting installer would dilute your advantage and take years. LeadHub is cheaper and more focused on sales ops; you will not out-LeadHub LeadHub on its own turf.

### 2. Build the sales spine on top of the conversation graph

Your unfair advantage is the data LeadHub does not have:

- WhatsApp/Messenger/Instagram conversation history.
- Social engagement and campaign responses.
- Ecommerce orders, carts, and product interest.
- AI chatbot transcripts and knowledge-base topics.

Turn that into sales intelligence:

- **Conversation-based lead scoring:** reply speed, message sentiment, product questions, repeat visits.
- **AI next best action with context:** "This lead asked about pricing twice on WhatsApp and has a $240 cart; call now."
- **Deal value from commerce:** prefill deal value from cart/order totals.
- **Win/loss from conversations:** summarize why the lead went cold or bought.
- **Response-time SLA:** use the inbox's `first_response_at` data for lead-level SLA reporting.

That is a differentiated product LeadHub cannot easily copy.

### 3. Productize your differentiators

Package the things you already do well into named outcomes:

- **WhatsApp Commerce** — catalog, Flows, product messages, cart recovery, order status.
- **Conversational Support** — shared inbox, AI chatbot, SLA, CSAT (if added).
- **Social Command Center** — publishing, calendar, engagement, analytics.
- **AI Knowledge Cloud** — RAG chatbots trained on business content, deployed across channels.
- **Global Billing** — 14 gateways, multi-currency, tax.

These are stronger buying triggers than "we also have a pipeline."

### 4. Fix monetization before adding features

Your plan model has many limits, but only six are enforced, and only `white_label` is feature-gated. If you add forms, booking, sequences, quotes, and sales reports without gates, you train customers to expect everything on the cheapest plan.

Minimum viable enforcement:

- Seat caps (`users`).
- Storage.
- WhatsApp accounts/templates.
- SMS/email sends.
- Social accounts/posts.
- AI tokens/knowledge bases.
- Lead credits.
- Feature flags for forms, booking, sequences, quotes, sales reports, API access, white-label.

### 5. Reframe the comparison in sales conversations

Do not position as "LeadHub alternative." Position as:

> "The conversational commerce CRM: WhatsApp-first sales, support, social, and ecommerce in one platform — with a real pipeline, deals, and reporting."

Against LeadHub, emphasize:

- "We can see and act on the WhatsApp conversation; LeadHub only sees the lead record."
- "We connect to your store and know what the customer bought."
- "We publish and measure social; LeadHub does not."
- "We support 15 SMS providers and 14 payment gateways; LeadHub supports 2 SMS providers and 5 gateways."
- "We have a mobile API and push; LeadHub is admin-only."

Against your CRM, LeadHub will emphasize:

- "We have companies, deals, quotes, invoices, tasks, and 13 reports."
- "We install on shared hosting in minutes."
- "We have 19 lead sources and a form/landing/booking funnel."
- "We have plan gates, seat caps, affiliate, and a marketplace."

Close those gaps or neutralize them with partnerships/integrations.

### 6. Consider a "CRM Lite" vs "Sales CRM" packaging

Not every messaging customer needs companies, quotes, and revenue reporting. A tiered product avoids scaring messaging-only buyers while giving sales-led buyers a reason to upgrade:

- **Messaging** — inbox, campaigns, social, ecommerce, AI chatbots.
- **Sales** — pipeline, deals, tasks, forms, booking, sequences, reports.
- **Scale** — multi-workspace, SSO, audit, compliance, dedicated infrastructure.

### 7. Risks to watch

- **Scope creep:** adding a full CRM can slow the messaging roadmap. Keep the sales spine modular (`app/Modules/Leads`, `app/Modules/Sales`, `app/Modules/Reporting`).
- **Data model migration:** adding companies/deals/custom fields after leads exist requires careful backfills; design the schema now.
- **Plan enforcement:** if you add features without gates, future pricing changes will be painful.
- **Hosting:** if you target shared-hosting buyers, a browser installer is a large investment; if you target agencies/VPS, skip it and invest in CRM depth.
- **AI cost:** sales AI and call transcription can be expensive; meter and gate them from day one.

---

## Sources & caveats

### LeadHub sources

- LeadHub documentation home: <https://docs.themesic.com/docs/leadhub-saas/>
- Leads overview: <https://docs.themesic.com/docs/leadhub-saas/leads/overview>
- Pipeline overview: <https://docs.themesic.com/docs/leadhub-saas/pipeline/overview>
- Forms overview: <https://docs.themesic.com/docs/leadhub-saas/forms/overview>
- Meetings overview: <https://docs.themesic.com/docs/leadhub-saas/meetings/overview>
- Communications overview: <https://docs.themesic.com/docs/leadhub-saas/communications/overview>
- Sales overview: <https://docs.themesic.com/docs/leadhub-saas/sales/overview>
- Automations overview: <https://docs.themesic.com/docs/leadhub-saas/automations/overview>
- Tools overview: <https://docs.themesic.com/docs/leadhub-saas/tools/overview>
- Reports overview: <https://docs.themesic.com/docs/leadhub-saas/reports/overview>
- Product page: <https://themesic.com/product/leadhub-multi-tenant-saas-crm-with-lead-pipelines-forms-automations/>
- CodeCanyon listing: <https://codecanyon.net/item/leadhub-multitenant-saas-crm-with-lead-pipelines-forms-automations/63311759>

LeadHub is at **v2.0.14 (Oct 7, 2026)**, with 58 sales and a 4.43/5 rating (7 ratings) on the vendor product page at the time of review. Pricing: $34 one-time Standard, $6/mo Pro billed yearly.

### Your CRM sources (codebase)

- `composer.json`, `package.json`, `.env.example` — stack, versions, configuration.
- `app/Models/Client.php`, `app/Models/Workspace.php`, `app/Models/User.php`, `app/Models/Plan.php` — tenancy, roles, plans.
- `app/Http/Middleware/EnforceLimit.php` — usage-limit enforcement.
- `app/Services/Billing/BillingGatewayRegistry.php` — 14 payment gateways.
- `app/Modules/Broadcasting/Services/Sms/SmsDriverManager.php` — 15 SMS providers.
- `app/Modules/Social/database/migrations/2026_04_30_000700_create_social_tables.php` — six social networks.
- `app/Modules/Ecommerce/routes/web.php` — Shopify, WooCommerce, BigCommerce.
- `app/Modules/Automation/Services/WorkflowGenerator.php`, `AutomationEngine.php` — 30+ nodes and 15 triggers.
- `app/Modules/Leads/Models/Lead.php`, `LeadPipelineStage.php`, `LeadActivity.php` — single pipeline, stages, activities, follow-up date, scoring.
- `app/Modules/Leads/Services/PipelineManager.php` — stage seeding, drag-and-drop moves, scoring.
- `app/Modules/Leads/Services/GooglePlacesScraper.php` — Google Places lead scraping.
- `app/Modules/Inbox/routes/web.php`, `app/Modules/Whatsapp/routes/web.php` — shared inbox, templates, auto-replies, widgets.
- `app/Modules/AI/Services/*`, `app/Modules/AI/routes/web.php` — RAG chatbots, knowledge bases, providers.
- `routes/client.php`, `routes/admin.php`, `routes/reports.php`, `routes/api.php` — feature surface.
- `resources/js/Pages/Leads/*` — pipeline UI, lead detail, follow-up badge.
- `database/seeders/PlanSeeder.php` — plan limits and features.

### Caveats

- **LeadHub claims are vendor claims.** They were not independently tested. Some features are plan-gated or require third-party credentials (Meta, Google, Twilio, LLM keys, SMTP, etc.).
- **Your CRM's features were verified in code, not in a running deployment.** A feature may exist but be disabled, unconfigured, or plan-gated in a given installation.
- **"Not found" means no first-class module/route/model was found.** A workaround may exist through automation, API, Google Forms, WhatsApp Flows, or a custom integration.
- **Effort estimates are directional.** They assume an experienced Laravel/React engineer familiar with the codebase; actual effort depends on design, testing, migrations, and backward compatibility.
- **Pricing and ratings change.** The LeadHub figures are a snapshot of October 2026.

---

*Prepared for internal product planning. This document is a comparison, not a recommendation to copy another product's feature set.*
