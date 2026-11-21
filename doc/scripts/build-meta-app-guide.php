<?php

/**
 * Generates the Arabic operator guide for the single Meta app that serves all
 * AYDRA customers (WhatsApp + Messenger + Instagram).
 *
 * Usage: php doc/scripts/build-meta-app-guide.php [output-path]
 *
 * Default output: AYDRA_Meta_App_Guide.docx (project root, never committed).
 */

require __DIR__.'/../../vendor/autoload.php';

use PhpOffice\PhpWord\Element\AbstractContainer;
use PhpOffice\PhpWord\Element\Section;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\Settings;

Settings::setDefaultRtl(true);
Settings::setOutputEscapingEnabled(true);

const AR_FONT = 'Segoe UI';
const MONO_FONT = 'Consolas';
const C_PRIMARY = '467235';
const C_PRIMARY_DARK = '2F4F22';
const C_ACCENT = 'F9A61A';
const C_TEXT = '1F2937';
const C_MUTED = '6B7280';
const C_BORDER = 'D9E2D3';
const CONTENT_W = 9600;

// ─── Style helpers ───────────────────────────────────────────────────────────

function pStyle(array $over = []): array
{
    return array_merge([
        'bidi' => true,
        'alignment' => 'both',
        'spaceAfter' => 120,
        'lineHeight' => 1.45,
    ], $over);
}

function fStyle(array $over = []): array
{
    return array_merge([
        'name' => AR_FONT,
        'size' => 11,
        'rtl' => true,
        'color' => C_TEXT,
    ], $over);
}

/**
 * Adds a paragraph that understands **bold** and `inline code`.
 */
function para(AbstractContainer $c, string $text, array $pOver = [], array $fOver = []): void
{
    $run = $c->addTextRun(pStyle($pOver));
    addInline($run, $text, fStyle($fOver));
}

/**
 * Splits a string on **bold** and `code` markers and adds it to a text run.
 */
function addInline($run, string $text, array $font): void
{
    $parts = preg_split('/(\*\*.+?\*\*|`[^`]+`)/u', $text, -1, PREG_SPLIT_DELIM_CAPTURE);

    foreach ($parts ?: [$text] as $part) {
        if ($part === '') {
            continue;
        }

        if (strlen($part) > 4 && str_starts_with($part, '**') && str_ends_with($part, '**')) {
            $run->addText(mb_substr($part, 2, -2), array_merge($font, ['bold' => true]));
        } elseif (strlen($part) > 2 && $part[0] === '`' && str_ends_with($part, '`')) {
            $run->addText(mb_substr($part, 1, -1), array_merge($font, [
                'name' => MONO_FONT,
                'size' => max(8.0, ($font['size'] ?? 11) - 1.5),
                'color' => 'B4232A',
                'rtl' => false,
            ]));
        } else {
            $run->addText($part, $font);
        }
    }
}

function h1(AbstractContainer $c, string $num, string $title): void
{
    static $first = true;

    if (! $first) {
        $c->addPageBreak();
    }
    $first = false;

    $c->addText($num.'. '.$title, fStyle([
        'size' => 19,
        'bold' => true,
        'color' => C_PRIMARY,
    ]), pStyle([
        'alignment' => 'right',
        'spaceBefore' => 0,
        'spaceAfter' => 180,
        'borderBottomSize' => 16,
        'borderBottomColor' => C_PRIMARY,
    ]));
}

function h2(AbstractContainer $c, string $title): void
{
    $c->addText($title, fStyle([
        'size' => 14,
        'bold' => true,
        'color' => C_PRIMARY_DARK,
    ]), pStyle([
        'alignment' => 'right',
        'spaceBefore' => 260,
        'spaceAfter' => 90,
        'keepNext' => true,
    ]));
}

function h3(AbstractContainer $c, string $title): void
{
    $c->addText($title, fStyle([
        'size' => 11.5,
        'bold' => true,
        'color' => '111827',
    ]), pStyle([
        'alignment' => 'right',
        'spaceBefore' => 170,
        'spaceAfter' => 50,
        'keepNext' => true,
    ]));
}

/**
 * Bullet list rendered with a right-side hanging indent (RTL safe).
 *
 * @param  array<int, string>  $items
 */
function bullets(AbstractContainer $c, array $items, int $level = 0): void
{
    $indent = 380 + ($level * 320);

    foreach ($items as $item) {
        $run = $c->addTextRun(pStyle([
            'alignment' => 'right',
            'indentRight' => $indent,
            'hanging' => 260,
            'spaceAfter' => 60,
            'lineHeight' => 1.4,
        ]));
        $marker = $level === 0 ? '•' : '–';
        $run->addText($marker.'  ', fStyle(['size' => 10, 'color' => C_PRIMARY, 'bold' => true]));
        addInline($run, $item, fStyle(['size' => 10.5]));
    }
}

/**
 * Numbered list, manual numbering so RTL indentation stays on the right.
 *
 * @param  array<int, string>  $items
 */
function numbered(AbstractContainer $c, array $items, int $start = 1): void
{
    foreach (array_values($items) as $i => $item) {
        $run = $c->addTextRun(pStyle([
            'alignment' => 'right',
            'indentRight' => 420,
            'hanging' => 320,
            'spaceAfter' => 60,
            'lineHeight' => 1.4,
        ]));
        $run->addText(($start + $i).'.  ', fStyle(['size' => 10.5, 'bold' => true, 'color' => C_PRIMARY]));
        addInline($run, $item, fStyle(['size' => 10.5]));
    }
}

function note(AbstractContainer $c, string $kind, string $title, string $text): void
{
    $palette = [
        'tip' => ['F1F8EE', C_PRIMARY],
        'warn' => ['FFF8E6', 'C77700'],
        'danger' => ['FDF0F0', 'B4232A'],
        'info' => ['EEF4FB', '2F6FB0'],
    ];
    [$bg, $accent] = $palette[$kind] ?? $palette['info'];

    $table = $c->addTable([
        'borderSize' => 0,
        'cellMargin' => 130,
        'bidiVisual' => true,
    ]);
    $table->addRow();
    $cell = $table->addCell(CONTENT_W, [
        'bgColor' => $bg,
        'borderRightSize' => 24,
        'borderRightColor' => $accent,
        'noWrap' => false,
    ]);

    $cell->addText($title, fStyle([
        'size' => 10,
        'bold' => true,
        'color' => $accent,
    ]), pStyle([
        'alignment' => 'right',
        'spaceAfter' => 40,
        'lineHeight' => 1.2,
    ]));

    $run = $cell->addTextRun(pStyle([
        'alignment' => 'right',
        'spaceAfter' => 0,
        'lineHeight' => 1.4,
    ]));
    addInline($run, $text, fStyle(['size' => 10]));
}

function spacer(AbstractContainer $c, int $points = 6): void
{
    $c->addTextBreak(1, fStyle(['size' => 2]), pStyle([
        'spaceAfter' => 0,
        'spaceBefore' => 0,
        'lineHeight' => 1.0,
    ]));
}

/**
 * Monospace block, always left-to-right.
 */
function code(AbstractContainer $c, string $code, ?string $caption = null): void
{
    if ($caption !== null) {
        $c->addText($caption, fStyle([
            'size' => 9,
            'color' => C_MUTED,
            'italic' => true,
        ]), pStyle([
            'alignment' => 'right',
            'spaceBefore' => 120,
            'spaceAfter' => 40,
            'lineHeight' => 1.2,
        ]));
    }

    $table = $c->addTable([
        'borderSize' => 4,
        'borderColor' => 'D0D7DE',
        'cellMargin' => 120,
        'bidiVisual' => true,
    ]);
    $table->addRow();
    $cell = $table->addCell(CONTENT_W, ['bgColor' => 'F6F8FA', 'noWrap' => false]);

    foreach (explode("\n", rtrim($code, "\n")) as $line) {
        $cell->addText($line === '' ? ' ' : $line, [
            'name' => MONO_FONT,
            'size' => 8.5,
            'rtl' => false,
            'color' => '24292F',
        ], [
            'bidi' => false,
            'alignment' => 'left',
            'spaceAfter' => 0,
            'lineHeight' => 1.15,
        ]);
    }
}

/**
 * @param  array<int, string>  $headers
 * @param  array<int, array<int, string>>  $rows
 * @param  array<int, float|int>  $widthsPct
 */
function table(AbstractContainer $c, array $headers, array $rows, array $widthsPct = []): void
{
    $count = count($headers);
    $widths = $widthsPct ?: array_fill(0, $count, 100 / $count);

    $table = $c->addTable([
        'borderSize' => 4,
        'borderColor' => C_BORDER,
        'cellMargin' => 90,
        'bidiVisual' => true,
    ]);

    $table->addRow();
    foreach (array_values($headers) as $i => $header) {
        $cell = $table->addCell((int) round(CONTENT_W * ($widths[$i] ?? 100 / $count) / 100), [
            'bgColor' => C_PRIMARY,
            'valign' => 'center',
            'noWrap' => false,
        ]);
        $cell->addText($header, fStyle([
            'size' => 9.5,
            'bold' => true,
            'color' => 'FFFFFF',
        ]), pStyle([
            'alignment' => 'right',
            'spaceAfter' => 0,
            'lineHeight' => 1.2,
        ]));
    }

    foreach (array_values($rows) as $r => $row) {
        $table->addRow();
        $values = array_values($row);
        for ($i = 0; $i < $count; $i++) {
            $cell = $table->addCell((int) round(CONTENT_W * ($widths[$i] ?? 100 / $count) / 100), [
                'bgColor' => $r % 2 === 0 ? 'F5F9F3' : 'FFFFFF',
                'valign' => 'center',
                'noWrap' => false,
            ]);
            $run = $cell->addTextRun(pStyle([
                'alignment' => 'right',
                'spaceAfter' => 0,
                'lineHeight' => 1.25,
            ]));
            addInline($run, (string) ($values[$i] ?? ''), fStyle(['size' => 9.5]));
        }
    }
}

// ─── Document setup ──────────────────────────────────────────────────────────

$phpWord = new PhpWord;
$phpWord->setDefaultFontName(AR_FONT);
$phpWord->setDefaultFontSize(11);

$phpWord->getDocInfo()
    ->setTitle('دليل إنشاء وتشغيل تطبيق Meta واحد لجميع عملاء AYDRA')
    ->setSubject('Meta app للمشغّل: WhatsApp + Messenger + Instagram')
    ->setCreator('AYDRA')
    ->setCompany('AYDRA')
    ->setDescription('دليل تشغيلي عربي خطوة بخطوة لإنشاء تطبيق Meta موحّد وربطه بـ AYDRA.');

$sectionStyle = [
    'pageSizeW' => 11906,
    'pageSizeH' => 16838,
    'marginTop' => 1134,
    'marginBottom' => 1134,
    'marginLeft' => 1134,
    'marginRight' => 1134,
    'headerHeight' => 500,
    'footerHeight' => 500,
];

// ─── Cover ───────────────────────────────────────────────────────────────────

$cover = $phpWord->addSection($sectionStyle);

for ($i = 0; $i < 5; $i++) {
    $cover->addTextBreak();
}

$cover->addText('A Y D R A', fStyle([
    'size' => 15,
    'bold' => true,
    'color' => C_PRIMARY,
]), pStyle([
    'alignment' => 'center',
    'spaceAfter' => 60,
]));

$cover->addText('دليل تشغيلي داخلي', fStyle([
    'size' => 10,
    'color' => C_MUTED,
]), pStyle([
    'alignment' => 'center',
    'spaceAfter' => 400,
]));

$cover->addText('تطبيق Meta واحد', fStyle([
    'size' => 34,
    'bold' => true,
    'color' => C_PRIMARY,
]), pStyle([
    'alignment' => 'center',
    'spaceAfter' => 0,
    'lineHeight' => 1.1,
]));

$cover->addText('لجميع عملاء AYDRA', fStyle([
    'size' => 26,
    'bold' => true,
    'color' => '111827',
]), pStyle([
    'alignment' => 'center',
    'spaceAfter' => 200,
    'lineHeight' => 1.2,
]));

$cover->addText('من إنشاء صفحة فيسبوك حتى النشر (Go Live)', fStyle([
    'size' => 13,
    'color' => C_MUTED,
]), pStyle([
    'alignment' => 'center',
    'spaceAfter' => 40,
]));

$cover->addText('WhatsApp  •  Messenger  •  Instagram', fStyle([
    'size' => 11,
    'color' => C_ACCENT,
    'bold' => true,
]), pStyle([
    'alignment' => 'center',
    'spaceAfter' => 700,
]));

$infoTable = $cover->addTable([
    'borderSize' => 0,
    'cellMargin' => 110,
    'bidiVisual' => true,
]);
$infoRow = [
    ['الإصدار', '1.0'],
    ['التاريخ', 'نوفمبر 2026'],
    ['الجمهور', 'فريق تشغيل AYDRA (مالك التطبيق)'],
    ['النطاق', 'تطبيق واحد يخدم كل الـ workspaces'],
];
foreach ($infoRow as [$label, $value]) {
    $infoTable->addRow();
    $l = $infoTable->addCell(2400, ['bgColor' => 'F1F8EE', 'valign' => 'center', 'noWrap' => false]);
    $l->addText($label, fStyle(['size' => 9.5, 'bold' => true, 'color' => C_PRIMARY_DARK]), pStyle([
        'alignment' => 'right', 'spaceAfter' => 0,
    ]));
    $v = $infoTable->addCell(4800, ['valign' => 'center', 'noWrap' => false]);
    $v->addText($value, fStyle(['size' => 9.5]), pStyle([
        'alignment' => 'right', 'spaceAfter' => 0,
    ]));
}

spacer($cover, 20);

note($cover, 'warn', 'قبل أن تبدأ', 'هذا الملف مرجع تشغيلي داخلي ولا يُنشر للعملاء. واجهة Meta تتغير باستمرار؛ لذلك ذُكرت أسماء القوائم بالإنجليزية كما تظهر في لوحة التطبيق، وقد تختلف تفاصيل الشاشات قليلًا. اعتمد دائمًا على ما تراه في اللوحة، وعلى روابط التوثيق الرسمية في الملحق د.');

// ─── Content section ─────────────────────────────────────────────────────────

$section = $phpWord->addSection($sectionStyle);

$header = $section->addHeader();
$header->addText('AYDRA — دليل تشغيل تطبيق Meta موحّد', fStyle([
    'size' => 8.5,
    'color' => C_MUTED,
]), pStyle([
    'alignment' => 'right',
    'spaceAfter' => 40,
    'borderBottomSize' => 4,
    'borderBottomColor' => C_BORDER,
]));

$footer = $section->addFooter();
$footer->addPreserveText('AYDRA  •  دليل تطبيق Meta  •  صفحة {PAGE} من {NUMPAGES}', fStyle([
    'size' => 8,
    'color' => C_MUTED,
]), pStyle([
    'alignment' => 'center',
    'spaceAfter' => 0,
]));

// ─── Table of contents (manual, reliable) ────────────────────────────────────

$section->addText('المحتويات', fStyle([
    'size' => 22,
    'bold' => true,
    'color' => C_PRIMARY,
]), pStyle([
    'alignment' => 'right',
    'spaceAfter' => 100,
    'borderBottomSize' => 12,
    'borderBottomColor' => C_PRIMARY,
]));

para($section, 'الخريطة التالية تعطيك الفصل وهدفه والناتج الذي يجب أن تحصل عليه قبل الانتقال للفصل الذي يليه. الفصول 1 و2 نظرية وقرارات، وما بعدها تنفيذي.', [], ['size' => 10.5, 'color' => C_MUTED]);

spacer($section, 8);

table($section, ['#', 'الفصل', 'ماذا تنجز فيه'], [
    ['1', 'المفاهيم والنموذج المعماري', 'تفهم لماذا تطبيق واحد، وما الفرق بين WABA وPage وBusiness Portfolio'],
    ['2', 'المتطلبات المسبقة', 'تجهّز الحسابات والأصول والصفحات ووسيلة الدفع قبل لمس لوحة Meta'],
    ['3', 'تسجيل مطوّر Meta', 'حساب مطوّر موثّق ببريد الشركة و2FA'],
    ['4', 'إنشاء التطبيق', 'تطبيق Business باسم AYDRA + App ID وApp Secret'],
    ['5', 'المنتجات والإعدادات', 'WhatsApp وMessenger وInstagram وFacebook Login for Business'],
    ['6', 'المفاتيح والتوكنات', 'System User token دائم + Verify Token + تخزين آمن'],
    ['7', 'التحقق من النشاط التجاري', 'Business Verification مقبول (شرط Advanced Access)'],
    ['8', 'مراجعة التطبيق', 'App Review يُقبل بالصلاحيات المطلوبة فقط'],
    ['9', 'Embedded Signup', 'ربط العملاء بأنفسهم عبر config_id وأحداث FINISH/CANCEL/ERROR'],
    ['10', 'ربط الحقول في AYDRA', 'كل حقل في admin/integrations/meta_app ومصدره من Meta'],
    ['11', 'الـ Webhooks', 'Callback URL وتحقق التوقيع والاشتراك في الحقول'],
    ['12', 'الانتقال إلى Live', 'تطبيق منشور يستخدمه عملاء خارج أدوار التطبيق'],
    ['13', 'حدود الرسائل والتوسّع', 'حدود 250 ← 2,000 ← 10,000 ← 100,000 ← بلا حد'],
    ['14', 'استكشاف الأخطاء', 'جداول أعراض وأسباب وحلول لكل ما قد يتعطل'],
    ['15', 'الأمان والحوكمة', 'ملكية التطبيق والأدوار وتدوير الأسرار والاستجابة للحوادث'],
    ['16', 'دليل التشغيل اليومي', 'إضافة عميل، تعطيل عميل، تدوير توكن، مراقبة دورية'],
    ['17', 'الملاحق', 'صلاحيات، أكواد أخطاء، نصوص جاهزة، روابط رسمية، أسئلة ومصطلحات'],
], [6, 30, 64]);

note($section, 'tip', 'أسرع مسار للقراءة', 'إن كنت مستعجلًا: اقرأ الفصل 2 ثم 4 ثم 5 ثم 6 ثم 10 ثم 11. هذه الفصول الستة تكفي لتشغيل التطبيق وربط أول عميل. الفصول 7 و8 و9 لازمة فقط عندما تريد خدمة عملاء خارج فريقك (أي الإنتاج الحقيقي).');

// ═══════════════════════════════════════════════════════════════════════════
// 1. المفاهيم والنموذج المعماري
// ═══════════════════════════════════════════════════════════════════════════

h1($section, '1', 'المفاهيم والنموذج المعماري');

para($section, 'قبل أي خطوة تقنية، يجب أن يكون واضحًا أن AYDRA لا تنشئ تطبيق Meta لكل عميل. هناك **تطبيق واحد** تملكه أنت (المشغّل)، وتحته أصول كل عميل منفصلة: رقم واتساب خاص به، وحساب Messaging خاص به، وصفحة فيسبوك وحساب Instagram خاصان به. التطبيق هو "المحرّك"، والأصول هي "بيانات كل عميل".');

h2($section, '1.1 الفكرة في سطر واحد');

note($section, 'tip', 'الملخص', 'تطبيق Meta واحد باسم AYDRA + صلاحيات مُراجعة (App Review) + تحقق تجاري (Business Verification) = يمكنه خدمة أي عدد من العملاء، كل واحد بأصوله وتوكنه وطريقة دفعه، مع عزل كامل داخل AYDRA على مستوى الـ workspace.');

h2($section, '1.2 لماذا تطبيق واحد وليس تطبيقًا لكل عميل');

para($section, 'من الممكن تقنيًا إنشاء تطبيق لكل عميل، لكنه خيار سيئ لأربعة أسباب ملموسة:');

table($section, ['المعيار', 'تطبيق واحد لكل العملاء', 'تطبيق لكل عميل'], [
    ['مراجعة Meta', 'مرة واحدة فقط، ثم تنطبق على كل العملاء', 'مراجعة منفصلة لكل عميل، وكل مراجعة قد تُرفض'],
    ['التحقق التجاري', 'مرة واحدة على حساب AYDRA', 'يجب ربط كل تطبيق بحساب أعمال موثّق'],
    ['إدارة الأسرار', 'App Secret واحد لتدويره وحمايته', 'عشرات الأسرار، واحتمال تسريب أو نسيان تدوير'],
    ['حد المنصة', 'حد 15 تطبيقًا لكل حساب مطوّر لا يُمثّل مشكلة', 'تصل للحد سريعًا وتضطر لحذف تطبيقات قديمة نهائيًا'],
], [22, 40, 38]);

para($section, 'الحد الأقصى هو **15 تطبيقًا لكل حساب مطوّر**. إذا وصلت للحد ستظهر رسالة تمنع الإنشاء، والحل الوحيد هو حذف تطبيق قديم (حذف نهائي) أو استخدام حساب مطوّر آخر. لذلك لا تُنشئ تطبيقًا إلا لغرض واضح: تطبيق الإنتاج، وربما تطبيق تجريبي واحد.');

h2($section, '1.3 ماذا يعني "تطبيق واحد" عمليًا');

bullets($section, [
    '**App ID وApp Secret** واحدان، يُخزَّنان في إعدادات AYDRA على مستوى النظام، وليس لكل workspace.',
    '**Callback URL واحد** لكل نوع webhook، وكل حدث يصل يحمل معرّف الأصل (WABA ID أو Page ID)، وAYDRA توجّهه للـ workspace المالك لهذا الأصل.',
    '**كل عميل يمنح الصلاحية بنفسه** عبر Embedded Signup، أو يسلّمك توكنًا طويل الأمد يدويًا.',
    '**كل عميل يدفع لـ Meta بنفسه** في نموذج Tech Provider، لأنك لا تملك خط ائتمان (Credit Line).',
    '**عزل البيانات داخل AYDRA** على مستوى الـ workspace: لا يرى عميل محادثات أو أرقام عميل آخر.',
]);

note($section, 'warn', 'فرق مهم', 'تطبيق واحد لا يعني "حساب واتساب واحد". كل عميل له WABA (Messaging account) ورقم هاتف مستقل. التطبيق هو من يتصل بهذه الحسابات، وليس من يدمجها.');

h2($section, '1.4 المصطلحات التي ستقابلها');

table($section, ['المصطلح', 'المعنى العملي'], [
    ['Meta App', 'التطبيق الذي تنشئه في developers.facebook.com. له App ID وApp Secret، وهو الذي يملك الصلاحيات ويستقبل الـ webhooks.'],
    ['Business Portfolio', 'حساب الأعمال في Meta Business Suite (business.facebook.com). يحتوي الصفحات والأصول والفوترة. التحقق التجاري يحدث على مستواه.'],
    ['Facebook Page', 'صفحة فيسبوك. مطلوبة لـ Messenger، ولربط Instagram، ولظهور التطبيق للعملاء.'],
    ['WABA / Messaging account', 'حساب واتساب للأعمال. يحمل القوالب والفوترة واشتراك الـ webhooks. الاسم القديم WABA ما زال مستخدمًا في الـ API.'],
    ['WAAC', 'WhatsApp account: حساب الرقم الواحد وهوية النشاط التجاري على واتساب. جزء من تطوّر نموذج الحسابات الجديد.'],
    ['Phone Number ID', 'معرّف رقم الواتساب داخل الـ API (وليس رقم الهاتف نفسه). يُستخدم في إرسال الرسائل وفي تسجيل الرقم.'],
    ['Embedded Signup', 'نافذة Meta التي تربط بها أصول العميل بتطبيقك بضغطة زر، بدلًا من نسخ توكنات يدويًا.'],
    ['config_id', 'معرّف إعداد في Facebook Login for Business يحدد الصلاحيات والأصول التي ستُطلب من العميل.'],
    ['System User Token', 'توكن طويل الأمد يمثّل خدمتك الآلية، ويُستخدم للوصول لأصول تملكها أنت أو تم منحها لك.'],
    ['BISU / Business Token', 'توكن عميل يُستخرج من كود Embedded Signup، صالح لمدة محدودة (60 يومًا في قالب Meta الشائع).'],
    ['App Review', 'مراجعة Meta لصلاحيات تطبيقك قبل استخدامها مع أشخاص خارج أدوار التطبيق.'],
    ['Business Verification', 'تحقق Meta من هوية شركتك ومستنداتها. شرط للـ Advanced Access ولخدمة أعمال أخرى.'],
    ['Standard / Advanced Access', 'Standard: الصلاحية تعمل فقط مع أدوار التطبيق. Advanced: تعمل مع أي مستخدم بعد الموافقة.'],
    ['Webhook', 'طلب HTTP يرسله Meta لخادمك عند وصول رسالة أو تغيّر حالة.'],
    ['Verify Token', 'سلسلة تختارها أنت، يرسلها Meta في طلب التحقق الأول لتثبت أن الخادم لك.'],
    ['HMAC / X-Hub-Signature-256', 'توقيع لكل طلب webhook يُحسب بـ App Secret، للتأكد أن الطلب من Meta فعلًا.'],
    ['Tech Provider', 'تعريف Meta للشركة التي تبني حلًا وتُدخل عملاءها على المنصة، دون خط ائتمان.'],
    ['Solution Partner', 'شريك Meta رسمي، يملك خط ائتمان يمكنه مشاركته مع العملاء. يتطلب برنامج شركاء.'],
], [26, 74]);

h2($section, '1.5 كيف تتدفق الرسالة من عميل AYDRA إلى منصة Meta');

numbered($section, [
    'عميلك يفتح صفحة الربط في AYDRA ويضغط "ربط واتساب".',
    'AYDRA تفتح نافذة Embedded Signup باستخدام `config_id` الخاص بواتساب.',
    'العميل يختار أو ينشئ Business Portfolio وWABA ورقمًا، ثم يضيف طريقة دفع.',
    'Meta تُرجع لـ AYDRA: `waba_id` و`phone_number_id` وكودًا قابلًا للتبديل بتوكن.',
    'AYDRA تبدّل الكود بتوكن، تشترك في الأحداث (`subscribed_apps`)، وتسجّل الرقم.',
    'AYDRA تحفظ العلاقة بين الـ workspace وهذه الأصول.',
    'عندما يرسل شخص رسالة على واتساب، يرسل Meta webhook إلى `/webhooks/whatsapp/global`.',
    'AYDRA تتحقق من التوقيع، تقرأ `waba_id` و`phone_number_id`، وتحدد الـ workspace، ثم تعالج الرسالة.',
], 1);

note($section, 'info', 'نفس المبدأ لبقية القنوات', 'Messenger وInstagram يستخدمان الصفحة (Page) كأصل. الحدث يحمل `page_id` أو `instagram_account_id`، وAYDRA توجّهه للـ workspace الذي ربط هذه الصفحة. تطبيق Meta واحد يبقى ثابتًا في الحالتين.');

h2($section, '1.6 ما لا يفعله هذا النموذج');

bullets($section, [
    'لا يجعل أرقام العملاء مشتركة، ولا يسمح لعميل برؤية بيانات عميل آخر.',
    'لا يعفيك من Business Verification وApp Review إذا أردت خدمة عملاء خارج فريقك.',
    'لا يمنحك خط ائتمان؛ كل عميل Tech Provider يضيف وسيلة دفع خاصة به.',
    'لا يلغي الحاجة لمراقبة انتهاء توكنات العملاء (خصوصًا BISU ذات الـ 60 يومًا).',
    'لا يحل مشكلة حدود الرسائل: كل Business Portfolio له حدّه الخاص، وليس حدًا مشتركًا.',
]);

h2($section, '1.7 أي نوع حساب تختار؟');

para($section, 'Meta تقسّم المطوّرين إلى ثلاث فئات. اختيار الفئة الخاطئ يعني إعادة بناء لاحقًا، فاقرأ الجدول بتمعّن:');

table($section, ['النوع', 'مناسب لمن؟', 'الفوترة', 'التوصية لـ AYDRA'], [
    ['Direct Developer', 'شركة تبني لنفسها فقط (رقم واحد أو أرقام قليلة)', 'تدفع هي لـ Meta', 'غير مناسب: لا يخدم عملاء خارجيين'],
    ['Tech Provider', 'شركة SaaS تربط عملاءها بأصولهم، وكل عميل يدفع بنفسه', 'كل عميل يضيف بطاقته', '**هذا هو الخيار الموصى به**'],
    ['Solution Partner', 'شريك Meta رسمي يريد مشاركة خط ائتمان مع العملاء', 'خط ائتمان مشترك', 'لاحقًا فقط إذا احتجت فوترة موحّدة'],
], [20, 40, 20, 20]);

note($section, 'warn', 'قرار لا رجعة فيه بسهولة', 'لا تبدأ كـ Solution Partner لمجرد أن الاسم "أقوى". البرنامج يتطلب شروط شراكة وتدقيقًا، وقد يؤخر الإطلاق. ابدأ Tech Provider، وكل عميل يضيف طريقة دفعه. يمكنك الترقية لاحقًا دون تغيير التطبيق نفسه.');

// ═══════════════════════════════════════════════════════════════════════════
// 2. المتطلبات المسبقة
// ═══════════════════════════════════════════════════════════════════════════

h1($section, '2', 'المتطلبات المسبقة');

para($section, 'كل بند في هذا الفصل سيُطلب منك لاحقًا داخل لوحة Meta. تجهيزه الآن يوفّر عليك ساعات من التوقف في منتصف خطوات لا يمكن حفظها جزئيًا.');

h2($section, '2.1 قائمة الأصول المطلوبة');

table($section, ['الأصل', 'لماذا نحتاجه', 'الحد الأدنى'], [
    ['حساب فيسبوك شخصي', 'الدخول للوحة المطوّر وإدارة التطبيق', 'حساب حقيقي باسم واضح، وليس حسابًا وهميًا'],
    ['بريد شركة على الدومين', 'تحقق الحساب وإشعارات Meta', '`ops@your-domain.com` أو ما شابه'],
    ['Business Portfolio', 'وعاء الأصول والفوترة والتحقق التجاري', 'واحد باسم AYDRA القانوني'],
    ['صفحة فيسبوك', 'Messenger + ربط Instagram + ظهور التطبيق', 'صفحة واحدة رسمية باسم AYDRA'],
    ['حساب Instagram احترافي', 'رسائل Instagram', 'Business أو Creator، مرتبط بالصفحة'],
    ['دومين إنتاجي + شهادة SSL', 'Callback URL وروابط السياسات', 'HTTPS صالح، وليس Self-signed'],
    ['رقم واتساب مخصص', 'إرسال واستقبال الرسائل', 'رقم غير مسجل على واتساب عادي'],
    ['وسيلة دفع', 'فوترة WABA', 'بطاقة على Business Portfolio (لك)، وبطاقة لكل عميل'],
], [24, 40, 36]);

h2($section, '2.2 إنشاء Business Portfolio');

numbered($section, [
    'افتح `business.facebook.com` وسجّل الدخول بحساب الشركة.',
    'من **Business settings → Accounts → Business portfolios** اضغط **Create** (أو **Add**).',
    'أدخل **الاسم القانوني الكامل** كما في السجل التجاري. لا تستخدم اسمًا مختصرًا أو علامة تجارية فقط.',
    'أضف **العنوان القانوني** و**رقم الهاتف** و**الموقع الإلكتروني** بدقة. هذه البيانات ستُقارن بمستندات التحقق لاحقًا.',
    'أضف بريد الشركة كبريد أساسي، وفعّل التحقق منه.',
    'أضف أعضاء الفريق من **Users → People** وامنح كل واحد أقل دور يحتاجه.',
]);

note($section, 'danger', 'سبب الرفض الأول للتحقق التجاري', 'اختلاف الاسم أو العنوان بين Business Portfolio والموقع الإلكتروني والسجل التجاري. اكتب الاسم القانوني حرفيًا، حتى لو كان طويلًا أو يحتوي على صيغة قانونية مثل "LLC" أو "ذ.م.م".');

h2($section, '2.3 الصفحة وحساب Instagram');

numbered($section, [
    'أنشئ صفحة فيسبوك رسمية باسم AYDRA (أو استخدم الصفحة القائمة).',
    'من **Business settings → Accounts → Pages** أضف الصفحة إلى الـ portfolio وامنح فريقك دور **Manage Page**.',
    'حوّل حساب Instagram إلى **Professional** (Business أو Creator) من إعدادات التطبيق.',
    'من إعدادات الصفحة اربط حساب Instagram بالصفحة (**Settings → Linked accounts → Instagram**).',
    'تأكد أنك تستطيع النشر من الصفحة ومن Instagram قبل المتابعة.',
]);

note($section, 'info', 'لماذا الربط مطلوب؟', 'مسار Instagram API with Facebook Login يعتمد على الصفحة المرتبطة بالحساب الاحترافي. إذا لم يكن الحساب احترافيًا أو غير مرتبط، لن تظهر رسائل Instagram في AYDRA حتى لو أضفت المنتج داخل Meta.');

h2($section, '2.4 الموقع والسياسات');

para($section, 'Meta ترفض نشر التطبيق إذا لم تجد روابط عامة تعمل دون تسجيل دخول. جهّز هذه الصفحات على دومين AYDRA:');

table($section, ['الصفحة', 'المحتوى المطلوب', 'ملاحظة'], [
    ['Privacy Policy', 'ما تجمعه، لماذا، مع من تشاركه، مدة الاحتفاظ، حقوق المستخدم، طريقة التواصل', 'يجب أن تذكر Meta/WhatsApp/Facebook صراحة'],
    ['Terms of Service', 'شروط الاستخدام والمسؤوليات وإلغاء الحساب', 'صفحة عامة مستقرة'],
    ['Data Deletion', 'خطوات حذف البيانات أو رابط/كولباك حذف', 'مطلوب في Basic Settings'],
    ['App Landing / Website', 'وصف المنتج وروابط التواصل', 'يُستخدم في المراجعة'],
], [22, 50, 28]);

h2($section, '2.5 رقم الواتساب');

bullets($section, [
    'استخدم رقمًا مخصصًا للنشاط، وليس رقمًا شخصيًا مستخدمًا على تطبيق واتساب العادي.',
    'يمكنك البدء بالرقم التجريبي (Test number) الذي توفره Meta للتطوير، لكنه يرسل لعدد محدود من الأرقام المُتحقَّق منها فقط.',
    'للإنتاج: أضف رقمًا جديدًا أو انقل رقمًا موجودًا. النقل يتطلب إلغاء تسجيله من تطبيق واتساب العادي أولًا.',
    'جهّز رمز التحقق (OTP) الذي سيصل برسالة SMS أو مكالمة صوتية.',
    'اختر اسمًا معروضًا (Display name) مطابقًا للعلامة التجارية؛ Meta تراجعه وقد ترفضه إن كان مضللًا.',
]);

note($section, 'warn', 'الرقم التجريبي ليس للإنتاج', 'رقم Meta التجريبي يعمل فقط مع أرقام أضفتها كـ recipients في لوحة التطبيق، ولا يصلح لعملاء حقيقيين. لا تبنِ عليه تجربة العميل النهائي.');

h2($section, '2.6 وسيلة الدفع');

para($section, 'في نموذج Tech Provider، **كل عميل يضيف وسيلة دفعه** داخل Embedded Signup أو من Business Suite بعد الربط. أنت تحتاج بطاقة فقط لتجربة WABA الخاصة بك ولأي رسائل تجريبية. لا تفترض أن بطاقتك ستُستخدم لعملائك: هذا يخالف النموذج ويحمّلك تكاليف لا تخصك.');

h2($section, '2.7 الأدوار والفريق');

table($section, ['الدور', 'من يشغله', 'ما يحتاجه'], [
    ['App Admin', 'مالك المنتج أو مسؤول تقني أول', 'صلاحية كاملة على التطبيق، و2FA إلزامي'],
    ['Developer', 'مهندس التكامل', 'صلاحية على التطبيق وعلى الـ repository، لا يملك الفوترة'],
    ['Billing', 'المالية', 'إدارة وسيلة الدفع والتقارير فقط'],
    ['Support', 'الدعم', 'لا يرى App Secret، يتعامل مع AYDRA فقط'],
], [20, 34, 46]);

note($section, 'tip', 'قاعدة الحد الأدنى', 'لا تمنح أحدًا دور Admin لمجرد أنه يحتاج تجربة الربط. أضفه كـ Developer أو Tester. Admin يعني قدرة على تغيير App Secret وحذف التطبيق.');

h2($section, '2.8 قائمة تحقق قبل الفصل 3');

table($section, ['✓', 'البند'], [
    ['☐', 'Business Portfolio منشأ بالاسم القانوني والعنوان الصحيحين'],
    ['☐', 'بريد الشركة مُتحقَّق منه على الـ portfolio'],
    ['☐', 'صفحة فيسبوك رسمية مضافة للـ portfolio'],
    ['☐', 'حساب Instagram احترافي مرتبط بالصفحة'],
    ['☐', 'دومين HTTPS يعمل، وصفحات الخصوصية والشروط والحذف منشورة'],
    ['☐', 'رقم واتساب مخصص جاهز (أو خطة واضحة للرقم التجريبي)'],
    ['☐', 'بطاقة دفع مضافة للـ portfolio'],
    ['☐', '2FA مفعّل على حساب مالك التطبيق'],
], [8, 92]);

// ═══════════════════════════════════════════════════════════════════════════
// 3. تسجيل مطوّر Meta
// ═══════════════════════════════════════════════════════════════════════════

h1($section, '3', 'تسجيل مطوّر Meta');

para($section, 'التسجيل مجاني ويستغرق دقائق، لكنه يفشل كثيرًا لأسباب بسيطة: حساب فيسبوك جديد، بريد غير مُتحقَّق، أو محاولة التسجيل من شبكة/دولة لا تطابق الحساب.');

h2($section, '3.1 الخطوات');

numbered($section, [
    'سجّل الدخول إلى فيسبوك بالحساب الذي سيملك التطبيق (يُفضّل حساب مرتبط ببريد الشركة).',
    'افتح `developers.facebook.com/async/registration` أو `developers.facebook.com` ثم **Get Started**.',
    'اقبل **Platform Terms** و**Developer Policies**. اقرأهما مرة واحدة على الأقل؛ المخالفة تؤدي لإيقاف التطبيق.',
    'أكّد **رقم الهاتف** و**البريد الإلكتروني** بالرموز التي تصلك. استخدم بيانات تستطيع الوصول إليها دائمًا.',
    'اختر **الوظيفة (Occupation)** الأقرب لعملك، مثل Developer أو Business Owner.',
    'بعد الانتهاء ستُفتح لوحة التطبيقات. أكمل خطوة إنشاء Business Portfolio إن طُلبت منك.',
]);

h2($section, '3.2 تأمين الحساب قبل أي شيء آخر');

bullets($section, [
    'فعّل **Two-Factor Authentication** على حساب فيسبوك من **Settings → Security and login**.',
    'أضف **بريدًا احتياطيًا** ورقم هاتف احتياطيًا.',
    'لا تشارك كلمة المرور مع أي عضو فريق؛ استخدم الأدوار داخل التطبيق والـ portfolio.',
    'راجع **Security Center** في Business Suite بانتظام.',
]);

note($section, 'danger', 'حساب واحد فقط للملكية', 'لا تنشئ التطبيق على حساب موظف قد يغادر الشركة. ملكية التطبيق يجب أن تكون لحساب مؤسسي تتحكم به الشركة، مع نقل الملكية موثقًا عند تغيير المسؤول.');

h2($section, '3.3 ربط Business Portfolio');

para($section, 'أثناء التسجيل أو بعده مباشرة، اربط التطبيق بالـ portfolio الصحيح. إن أنشأت portfolio جديدًا من داخل التسجيل، ارجع إلى `business.facebook.com` وأكمل بياناته القانونية قبل المتابعة.');

h2($section, '3.4 مشاكل التسجيل الشائعة');

table($section, ['العرض', 'السبب المرجّح', 'الحل'], [
    ['زر التسجيل لا يعمل', 'حساب جديد أو بريد غير مُتحقَّق', 'أكّد البريد والهاتف، وانتظر 24 ساعة إن لزم'],
    ['"We couldn\'t verify your account"', 'بيانات غير متطابقة أو شبكة مشبوهة', 'استخدم شبكة الشركة، وأكمل التحقق بهوية حقيقية'],
    ['الحساب مقفل', 'نشاط مريب أو بلاغات', 'استخدم مركز المساعدة لاستعادة الحساب قبل المتابعة'],
    ['لا تظهر لوحة التطبيقات', 'التسجيل لم يكتمل', 'أعد فتح `developers.facebook.com/apps` وأكمل الخطوات الناقصة'],
    ['رسالة عن الحد الأقصى للتطبيقات', 'وصلت 15 تطبيقًا', 'احذف تطبيقًا غير مستخدم نهائيًا أو استخدم حسابًا مؤسسيًا آخر'],
], [30, 36, 34]);

// ═══════════════════════════════════════════════════════════════════════════
// 4. إنشاء التطبيق
// ═══════════════════════════════════════════════════════════════════════════

h1($section, '4', 'إنشاء التطبيق');

para($section, 'هنا يُولد الأصل الأهم: التطبيق. خذ وقتك في الاسم والتصنيف والصفحات العامة، لأن تغييرها لاحقًا بعد المراجعة يربك العملاء وقد يعيد بعض المراجعات.');

h2($section, '4.1 قبل الضغط على Create');

bullets($section, [
    'اعرف **الاسم الظاهر** الذي سيظهر للعميل في نافذة الصلاحيات: اجعله "AYDRA" أو "AYDRA CRM".',
    'جهّز **أيقونة 1024×1024 PNG** بخلفية واضحة، بدون نص صغير.',
    'جهّز روابط **Privacy Policy** و**Terms** و**Data Deletion** النهائية (وليست مسودات).',
    'اعرف دومين الإنتاج الذي ستستضيف عليه AYDRA، مثل `app.your-domain.com`.',
]);

h2($section, '4.2 خطوات الإنشاء');

numbered($section, [
    'افتح `developers.facebook.com/apps` واضغط **Create app**.',
    'اختر **Use case** الأساسي: **WhatsApp** (إن ظهر خيار "Manage business messaging" فاختره).',
    'أدخل **App name** = AYDRA، و**App contact email** = بريد الفريق.',
    'اختر **Business portfolio** الخاص بـ AYDRA. إن لم يظهر، اضغط **Create a business portfolio** وأكمل بياناته.',
    'اضغط **Create app** وأكمل أي تحقق إضافي (كلمة مرور أو OTP).',
    'ستُفتح لوحة التطبيق. احفظ **App ID** من أعلى الصفحة أو من **App settings → Basic**.',
]);

note($section, 'info', 'هل أختار WhatsApp أم Messenger أم Instagram؟', 'اختيار Use case واحد لا يمنع إضافة الباقي. أنشئ التطبيق بـ WhatsApp ثم أضف المنتجات الأخرى من **Dashboard → Use cases → Edit/Add**. التطبيق الواحد يمكنه حمل WhatsApp وMessenger وInstagram وFacebook Login for Business معًا.');

h2($section, '4.3 إضافة بقية الـ use cases');

numbered($section, [
    'من **Dashboard** افتح قسم **Use cases**.',
    'اضغط **Edit** أو **Add use case**.',
    'أضف **Engage with customers on Messenger**.',
    'أضف **Manage everything on your Instagram** (أو Use case الرسائل المناسب لحساب احترافي).',
    'أضف **Facebook Login for Business** إن لم يكن موجودًا؛ ستحتاجه لإنشاء `config_id`.',
]);

note($section, 'warn', 'لا تحذف صلاحية إلزامية', 'داخل كل use case توجد صلاحيات إلزامية لا يمكن إزالتها. لا تحاول "تنظيف" القائمة بحذفها؛ Meta ستمنعك. احذف فقط الصلاحيات الاختيارية التي لا تستخدمها فعلًا، لأن طلب صلاحيات غير مستخدمة سبب شائع لرفض App Review.');

h2($section, '4.4 إعدادات Basic Settings');

para($section, 'افتح **App settings → Basic** واملأ الجدول التالي. القيم الناقصة أو غير المتطابقة تمنع النشر أو تسبب رفض المراجعة.');

table($section, ['الحقل', 'ماذا تكتب', 'إلزامي؟'], [
    ['App ID', 'يُولَّد تلقائيًا. انسخه إلى AYDRA', 'نعم'],
    ['App Secret', 'اضغط Show وانسخه إلى AYDRA. لا تضعه في Git أو الشات', 'نعم'],
    ['Display name', 'AYDRA (نفس اسم العلامة التجارية)', 'نعم'],
    ['App domains', 'دومين AYDRA فقط، مثل `app.your-domain.com`', 'نعم'],
    ['Privacy Policy URL', 'رابط عام يعمل بدون تسجيل دخول', 'نعم'],
    ['Terms of Service URL', 'رابط عام', 'نعم'],
    ['Data Deletion', 'Callback URL أو Instructions URL (يفضّل الاثنان)', 'نعم'],
    ['Category', 'Business / Productivity حسب الأنسب', 'نعم'],
    ['App icon', '1024×1024 PNG', 'نعم للنشر'],
    ['Contact email', 'بريد فريق التشغيل', 'نعم'],
    ['Website', 'صفحة المنتج', 'مستحسن'],
], [26, 62, 12]);

h2($section, '4.5 حذف البيانات (Data Deletion)');

para($section, 'Meta تطلب مسارًا واضحًا لحذف بيانات المستخدم. لديك خياران، ويُفضّل تنفيذهما معًا:');

bullets($section, [
    '**Instructions URL**: صفحة تشرح للمستخدم كيف يطلب الحذف، وما الذي سيُحذف، ومدة التنفيذ. هذا هو الأسهل والأقل عرضة للأخطاء.',
    '**Callback URL**: endpoint يستقبل `signed_request` من Meta، يتحقق من التوقيع، ثم يعيد رابطًا لحالة الحذف. مناسب إذا أردت أتمتة الحذف.',
]);

note($section, 'tip', 'ماذا يحدث بعد الحذف؟', 'احذف بيانات المستخدم من AYDRA (المحادثات، جهات الاتصال، الوسائط) وأبلغ Meta بالنتيجة. لا تحتفظ بسجلات تحتوي بيانات شخصية بحجة "التحليلات"؛ احتفظ فقط بعدّادات مجهولة إن لزم.');

h2($section, '4.6 App ID وApp Secret');

para($section, 'الاثنان في **App settings → Basic**. الفرق بينهما جوهري:');

table($section, ['القيمة', 'هل هي سر؟', 'أين تُستخدم؟'], [
    ['App ID', 'لا، معرف عام', 'SDK، روابط OAuth، إعدادات AYDRA'],
    ['App Secret', 'نعم، سر خطير', 'تبديل الكود بتوكن، التحقق من توقيع الـ webhooks، طلبات الخادم'],
    ['Verify Token', 'نعم، تختاره أنت', 'التحقق الأولي من الـ webhook فقط'],
], [22, 26, 52]);

note($section, 'danger', 'تسريب App Secret', 'إن تسرب، يستطيع أي شخص انتحال تطبيقك واستدعاء الـ API. الإجراء الفوري: **App settings → Basic → Reset** لـ App Secret، ثم تحديث AYDRA، ثم إبطال التوكنات القديمة ومراجعة السجلات. تدوير السر لا يوقف التطبيق، لكنه يوقف كل تكامل لم يُحدَّث.');

h2($section, '4.7 Development أم Live؟');

para($section, 'أي تطبيق جديد يبدأ في **Development**. في هذا الوضع تعمل الصلاحيات فقط مع من لديه دور على التطبيق (Admin/Developer/Tester). هذا هو الوضع الصحيح للبناء والاختبار. لا تحوّله إلى **Live** إلا بعد اكتمال الفصلين 7 و8 وقائمة الفصل 12.');

h2($section, '4.8 حد التطبيقات الـ 15');

bullets($section, [
    'الحد لكل **حساب مطوّر**، وليس لكل Business Portfolio.',
    'التطبيق المحذوف لا يمكن استعادته، وتُفقد بياناته ومراجعاته.',
    'لا تُنشئ تطبيقًا ثانيًا "للتجربة"؛ استخدم نفس التطبيق في وضع Development وأضف المختبرين كأدوار.',
    'إن كنت قريبًا من الحد، راجع التطبيقات القديمة واحذف ما لا تملكه أو لا تستخدمه.',
]);

h2($section, '4.9 قائمة تحقق الفصل 4');

table($section, ['✓', 'البند'], [
    ['☐', 'التطبيق منشأ باسم AYDRA ومربوط بالـ portfolio الصحيح'],
    ['☐', 'App ID وApp Secret محفوظان في مكان آمن (مدير أسرار، لا ملف نصي)'],
    ['☐', 'Privacy Policy وTerms وData Deletion مضافة وتعمل'],
    ['☐', 'App domains مطابق للدومين الفعلي'],
    ['☐', 'الأيقونة 1024×1024 مرفوعة'],
    ['☐', 'Use cases المطلوبة مضافة (WhatsApp + Messenger + Instagram + FB Login)'],
    ['☐', 'التطبيق ما زال في Development'],
], [8, 92]);

// ═══════════════════════════════════════════════════════════════════════════
// 5. المنتجات والإعدادات
// ═══════════════════════════════════════════════════════════════════════════

h1($section, '5', 'المنتجات والإعدادات');

para($section, 'داخل التطبيق الواحد ستضيف أربعة منتجات. كل منتج له شاشته وقواعده، لكنها تشترك في نفس App ID وApp Secret. أضفها بالترتيب التالي لأن واتساب هو الأكثر تعقيدًا ويحتاج تحققًا ومراجعة.');

table($section, ['المنتج', 'لماذا نضيفه', 'الأثر على AYDRA'], [
    ['WhatsApp', 'رسائل واتساب عبر Cloud API + Embedded Signup', 'القناة الأساسية: WABA + Phone Number ID + webhooks'],
    ['Messenger', 'رسائل صفحة فيسبوك', 'Page ID + Page token + webhook object=page'],
    ['Instagram', 'رسائل ورسائل مباشرة + النشر', 'IG Account ID + Page token + webhook object=instagram'],
    ['Facebook Login for Business', 'إنشاء إعدادات الربط `config_id`', 'زر الربط الذاتي داخل AYDRA'],
], [26, 40, 34]);

// ─── 5.1 WhatsApp ────────────────────────────────────────────────────────────

h2($section, '5.1 منتج WhatsApp');

numbered($section, [
    'من **Dashboard → Products** ابحث عن **WhatsApp** واضغط **Set up**.',
    'ستُفتح صفحة **API Setup**. راجع الحقول الظاهرة فيها (انظر الجدول أدناه).',
    'للتطوير: استخدم **Test number** وأضف أرقامك في **To** كـ recipients مُتحقَّق منها.',
    'للإنتاج: اضغط **Add phone number**، أدخل الاسم المعروض والفئة والوصف، ثم تحقق بالـ OTP.',
    'انسخ **WhatsApp Business Account ID** و**Phone number ID** إلى AYDRA عند الربط اليدوي.',
    'من **Configuration** اضبط **Callback URL** و**Verify token** واشترك في الحقول (التفاصيل في الفصل 11).',
]);

table($section, ['ما تراه في API Setup', 'المعنى', 'أين يُستخدم'], [
    ['Test number', 'رقم تجريبي من Meta', 'اختبار الإرسال فقط، لا يصل لعملاء حقيقيين'],
    ['WhatsApp Business Account ID', 'معرّف WABA / Messaging account', 'ربط العميل، الاشتراك في الأحداث، القوالب'],
    ['Phone number ID', 'معرّف الرقم داخل الـ API (ليس رقم الهاتف)', 'إرسال الرسائل، تسجيل الرقم'],
    ['Temporary access token', 'توكن قصير (24 ساعة)', 'تجربة سريعة فقط، لا يصلح للإنتاج'],
    ['App Secret', 'سر التطبيق', 'توقيع الطلبات والتحقق من الـ webhooks'],
], [30, 44, 26]);

note($section, 'danger', 'لا تستخدم Temporary token في AYDRA', 'توكن الـ 24 ساعة ينتهي ويوقف القناة فجأة. للإنتاج استخدم System User token دائم (الفصل 6)، وللعملاء استخدم توكن Embedded Signup أو توكنًا طويل الأمد يدويًا.');

h3($section, 'Embedded Signup Builder');

para($section, 'داخل **WhatsApp → Embedded Signup Builder** يمكنك تجربة إعداد الربط قبل نشره، واختبار `extras` الخاص بك. استخدم خيار **Check a launch config** للتحقق من توافق إصدار Embedded Signup قبل الترقية، خصوصًا أن الإصدارين v2 وv3 سيُوقفان في **15 أكتوبر 2026**.');

// ─── 5.2 Messenger ───────────────────────────────────────────────────────────

h2($section, '5.2 منتج Messenger');

numbered($section, [
    'من **Products** أضف **Messenger** ثم افتح **Messenger → Settings**.',
    'في **Access Tokens** اضغط **Connect** واختر صفحة AYDRA (يجب أن تكون Admin على الصفحة).',
    'اضغط **Generate Token** لصفحة العميل، وانسخ التوكن. هذا هو **Page access token**.',
    'في **Webhooks** اضغط **Configure**، وأدخل Callback URL الخاص بـ AYDRA وVerify Token.',
    'اشترك في الحقول المطلوبة (الجدول أدناه).',
    'أضف اشتراك التطبيق في الصفحة عبر **Add subscriptions**، أو عبر `POST /{page-id}/subscribed_apps`.',
]);

table($section, ['حقل Messenger', 'لماذا نستخدمه'], [
    ['`messages`', 'استقبال الرسائل الجديدة وحالات التسليم/القراءة'],
    ['`messaging_postbacks`', 'نتائج الأزرار والقوائم'],
    ['`message_deliveries`', 'إشعارات تسليم الرسائل'],
    ['`message_reads`', 'إشعارات قراءة الرسائل'],
    ['`messaging_optins`', 'اشتراكات المستخدمين (إن استخدمت opt-in)'],
    ['`messaging_referrals`', 'معرفة مصدر المحادثة (Ads / m.me links)'],
], [30, 70]);

note($section, 'info', 'التوكن لكل صفحة', 'Page token يخص صفحة واحدة. لا تستخدم توكن صفحة AYDRA لعملاء آخرين. في AYDRA يُحفظ توكن كل صفحة على حدة، وwebhook يوجّه الحدث حسب `page_id`.');

// ─── 5.3 Instagram ───────────────────────────────────────────────────────────

h2($section, '5.3 منتج Instagram');

para($section, 'AYDRA يعتمد مسار **Instagram API with Facebook Login** (أي أن الحساب الاحترافي مرتبط بصفحة فيسبوك). هذا يسمح باستخدام نفس بنية الصلاحيات والتوكنات، لكنه يشترط ربط الحساب بالصفحة مسبقًا.');

numbered($section, [
    'تأكد أن حساب Instagram **Professional** ومرتبط بصفحة AYDRA.',
    'من **Products** أضف **Instagram** ثم اختر **API setup with Facebook login**.',
    'اضغط **Generate token** وحدد الحساب الاحترافي والصفحة.',
    'انسخ **Instagram account ID** والتوكن، وأضفهما في إعدادات القناة داخل AYDRA.',
    'من **Webhooks** اضبط Callback URL نفسه، واشترك في `messages` و`messaging_postbacks` على object = `instagram`.',
    'أضف اشتراك التطبيق في الحساب عبر `POST /{ig-user-id}/subscribed_apps`.',
]);

table($section, ['الصلاحية', 'الغرض'], [
    ['`instagram_basic`', 'قراءة بيانات الحساب الأساسية'],
    ['`instagram_manage_messages`', 'قراءة والرد على الرسائل المباشرة'],
    ['`instagram_content_publish`', 'النشر (إن كنت تستخدم ميزة النشر من AYDRA)'],
    ['`pages_show_list`', 'عرض الصفحات المرتبطة'],
    ['`pages_read_engagement`', 'قراءة محتوى الصفحة'],
    ['`business_management`', 'الوصول للأصول عبر Business Portfolio'],
], [38, 62]);

note($section, 'warn', 'لا تطلب صلاحيات نشر إن لم تكن تستخدمها', 'إن كانت AYDRA تنشر فعليًا، اذكر ذلك في المراجعة وأظهره في الفيديو. إن لم تكن تنشر، احذف `instagram_content_publish` و`pages_manage_posts` من الإعداد لتقليل أسباب الرفض.');

// ─── 5.4 Facebook Login for Business ─────────────────────────────────────────

h2($section, '5.4 Facebook Login for Business');

para($section, 'هذا المنتج هو الذي يولّد `config_id`. الإعداد هو "وصفة" تحدد ما سيُطلب من العميل: أي أصول، وأي صلاحيات، ونوع التوكن.');

numbered($section, [
    'افتح **Facebook Login for Business → Configurations**.',
    'لإعداد واتساب: اضغط **Create from template** واختر **WhatsApp Embedded Signup Configuration With 60 Expiration Token**.',
    'أو اضغط **Create configuration** لإنشاء إعداد مخصص، واختر **Login variation = WhatsApp Embedded Signup**.',
    'أضف الأصول التي تحتاجها فقط: WABA، والصفحات إن كنت تربط Messenger/Instagram من نفس الإعداد.',
    'أضف الصلاحيات المطلوبة فقط، ثم أكمل الإنشاء.',
    'انسخ **Configuration ID**. هذا هو `config_id_whatsapp`.',
    'كرر لإنشاء إعداد ثانٍ للقنوات الاجتماعية، وانسخه كـ `config_id_social`.',
]);

table($section, ['الإعداد', 'أين يوضع في AYDRA', 'الصلاحيات المقترحة'], [
    ['WhatsApp Embedded Signup', '`config_id_whatsapp`', '`whatsapp_business_management`, `whatsapp_business_messaging`, `business_management`'],
    ['Social / Instagram / Messenger', '`config_id_social`', '`pages_show_list`, `pages_read_engagement`, `pages_manage_posts`, `instagram_basic`, `instagram_content_publish`, وأضف صلاحيات الرسائل إن لزم'],
], [26, 24, 50]);

note($section, 'danger', 'خطأ شائع: config_id في الحقل الخطأ', 'إذا وضعت معرّف إعداد واتساب في `config_id_social` فلن تظهر الصفحات في نافذة الربط، والعكس صحيح. كل إعداد مرتبط بنوع الأصول التي اخترتها أثناء الإنشاء.');

h3($section, 'Allowed domains وOAuth');

para($section, 'قبل أن يعمل Embedded Signup، افتح **Facebook Login for Business → Settings → Client OAuth settings** وفعّل:');

bullets($section, [
    '**Client OAuth login** = Yes',
    '**Web OAuth login** = Yes',
    '**Enforce HTTPS** = Yes',
    '**Embedded Browser OAuth Login** = Yes',
    '**Use Strict Mode for redirect URIs** = Yes',
    '**Login with the JavaScript SDK** = Yes',
]);

para($section, 'ثم أضف دومين AYDRA في **Allowed domains** و**Valid OAuth redirect URIs**. لا تعمل النافذة إذا كان الدومين غير مسجل، حتى لو كان الكود صحيحًا. أضف دومين التطوير أيضًا إن كنت تختبر محليًا، بشرط HTTPS.');

// ─── 5.5 Webhooks at product level ───────────────────────────────────────────

h2($section, '5.5 اشتراكات الـ Webhooks لكل منتج');

para($section, 'Meta لا ترسل كل الأحداث تلقائيًا. يجب الاشتراك في الحقول التي تحتاجها. الاشتراك في حقول كثيرة يزيد الحمل وأخطاء المعالجة، فاشترك فيما تستخدمه AYDRA فعلًا.');

table($section, ['Object', 'الحقول الأساسية', 'ملاحظة'], [
    ['`whatsapp_business_account`', '`messages`, `message_template_status_update`, `message_template_quality_update`, `account_update`, `account_alerts`, `phone_number_quality_update`', 'الرسائل وحالات القوالب والجودة'],
    ['`page`', '`messages`, `messaging_postbacks`, `message_deliveries`, `message_reads`, `messaging_optins`, `messaging_referrals`', 'Messenger'],
    ['`instagram`', '`messages`, `messaging_postbacks`, `message_reactions`', 'رسائل Instagram'],
], [24, 58, 18]);

note($section, 'tip', 'الاشتراك على مستويين', 'الاشتراك في الحقول يحدث على مستوى التطبيق (في اللوحة). أما ربط تطبيقك بحساب العميل فيحدث عبر `subscribed_apps` لكل WABA أو صفحة. الاثنان مطلوبان: بدون الثاني لن تصلك أحداث ذلك العميل.');

// ═══════════════════════════════════════════════════════════════════════════
// 6. المفاتيح والأسرار
// ═══════════════════════════════════════════════════════════════════════════

h1($section, '6', 'المفاتيح والأسرار والتوكنات');

para($section, 'هذا الفصل هو أكثر ما يسبب أعطالًا إنتاجية. القاعدة الذهبية: **كل قيمة سرية لها مكان واحد للقراءة، وآلية تدوير، وتاريخ انتهاء معروف**. لا تحفظ توكنًا في رسالة واتساب أو ملف Excel على سطح المكتب.');

h2($section, '6.1 خريطة القيم الست');

table($section, ['القيمة', 'من أين تأتي؟', 'أين تُستخدم؟', 'التدوير'], [
    ['App ID', 'App settings → Basic', 'SDK، OAuth، إعدادات AYDRA', 'ثابت'],
    ['App Secret', 'App settings → Basic', 'تبديل الكود، توقيع الـ webhooks', 'كل 90 يومًا أو عند الاشتباه'],
    ['Verify Token', 'أنت تختاره', 'التحقق الأولي من الـ webhook', 'سنويًا أو عند تغيير الفريق'],
    ['System User Token', 'Business settings → System users', 'أصول AYDRA نفسها والقوالب', 'حسب مدة الانتهاء'],
    ['config_id_whatsapp', 'Facebook Login for Business', 'زر ربط واتساب', 'ثابت ما لم تغيّر الصلاحيات'],
    ['config_id_social', 'Facebook Login for Business', 'زر ربط Messenger/Instagram', 'ثابت ما لم تغيّر الصلاحيات'],
], [24, 28, 30, 18]);

h2($section, '6.2 توليد Verify Token');

para($section, 'هو مجرد نص عشوائي طويل تختاره أنت. لا تولّده Meta. استخدم 32 بايت عشوائية على الأقل:');

code($section, <<<'CODE'
# Linux / macOS / Git Bash
openssl rand -hex 32

# PowerShell
-join (1..64 | ForEach-Object { '{0:x}' -f (Get-Random -Maximum 16) })
CODE, 'أي طريقة تعطيك سلسلة عشوائية بطول 64 حرفًا تكفي.');

note($section, 'warn', 'نفس القيمة في مكانين', 'القيمة التي تكتبها في Meta → WhatsApp → Configuration → Verify token يجب أن تطابق حرفيًا حقل `verify_token` في AYDRA. أي مسافة أو حرف مختلف يسبب فشل التحقق (403).');

h2($section, '6.3 إنشاء System User');

numbered($section, [
    'افتح `business.facebook.com` ثم **Business settings → Users → System users**.',
    'اضغط **Add**، واختر اسمًا واضحًا مثل `aydra-meta-service`، والدور **Admin** (أو Employee مع صلاحيات الأصول فقط).',
    'اضغط **Assign assets** وامنحه: التطبيق (Develop app)، والـ WABA، والصفحات التي تحتاجها.',
    'اضغط **Generate new token**، واختر التطبيق.',
    'اختر مدة الانتهاء: **Never** إن كانت متاحة، وإلا 60 يومًا مع تذكير تدوير.',
    'اختر الصلاحيات (الجدول التالي)، ثم انسخ التوكن فورًا. لن يظهر مرة أخرى.',
]);

h2($section, '6.4 الصلاحيات المطلوبة');

table($section, ['الصلاحية', 'لماذا'], [
    ['`business_management`', 'الوصول لأصول Business Portfolio (نادرًا ما تحتاجها برمجيًا)'],
    ['`whatsapp_business_management`', 'إدارة القوالب، الأرقام، الاشتراكات، وقراءة بيانات WABA'],
    ['`whatsapp_business_messaging`', 'إرسال الرسائل واستقبال أحداثها'],
    ['`pages_show_list`', 'عرض صفحات العميل'],
    ['`pages_read_engagement`', 'قراءة محتوى الصفحة'],
    ['`pages_manage_metadata`', 'إدارة اشتراكات الـ webhooks للصفحة'],
    ['`pages_messaging`', 'إرسال واستقبال رسائل Messenger'],
    ['`instagram_basic`', 'قراءة بيانات حساب Instagram'],
    ['`instagram_manage_messages`', 'إدارة رسائل Instagram'],
], [36, 64]);

note($section, 'info', 'اطلب ما تحتاجه فقط', 'كل صلاحية إضافية تعني سؤالًا إضافيًا في المراجعة وسببًا إضافيًا للرفض. ابدأ بالحد الأدنى، وأضف لاحقًا عند الحاجة الفعلية.');

h2($section, '6.5 توكنات العملاء (BISU)');

para($section, 'في Embedded Signup، لا تستخدم System User token الخاص بك للوصول لأصول العميل. بل تستخرج **Business Integration System User token** من الكود الذي يعيده Meta:');

code($section, <<<'CODE'
GET https://graph.facebook.com/v27.0/oauth/access_token
  ?client_id={APP_ID}
  &client_secret={APP_SECRET}
  &code={CODE_FROM_EMBEDDED_SIGNUP}
CODE, 'الكود صالح 30 ثانية فقط، فبدّله فورًا على الخادم.');

bullets($section, [
    'التوكن الناتج يخص العميل وصلاحياته، ولا يمنحك وصولًا لأصول عميل آخر.',
    'قالب Meta الشائع يسميه "60 Expiration Token"، أي أن صلاحيته 60 يومًا.',
    'خزّنه مشفّرًا، وسجّل تاريخ الانتهاء، وجهّز تنبيهًا قبل 7 أيام.',
    'عند الانتهاء، أعد الربط أو حدّث التوكن حسب ما تسمح به Meta؛ لا تفترض أنه دائم.',
]);

note($section, 'danger', 'لا تشارك توكن العميل بين workspaces', 'كل توكن مربوط بأصول عميل محدد. استخدامه في workspace آخر يسبب أخطاء صلاحيات (code 200/100) وقد يوقف الحساب. اربط التوكن بالـ workspace الذي أنشأه.');

h2($section, '6.6 التخزين والتدوير');

bullets($section, [
    'تخزين AYDRA: الحقول من نوع `password` تُحفظ مشفّرة، لكن هذا لا يغني عن تشفير النسخ الاحتياطية وتقييد الوصول لقاعدة البيانات.',
    'مدير أسرار للإنتاج (Vault / Parameter Store / Secrets Manager) للقيم التي تحتاجها العمليات الآلية خارج قاعدة البيانات.',
    'صلاحية قراءة الأسرار لعدد محدود من الخدمات، وليس لكل التطبيق.',
    'سجّل من قرأ السر ومتى (Audit Log) إن كان لديك نظام مركزي.',
    'عند تدوير App Secret: حدّث AYDRA أولًا، ثم أعد توليد توكنات System User إن كانت مرتبطة، ثم راقب الأخطاء 24 ساعة.',
    'عند مغادرة عضو فريق: أزل دوره، أعد توليد التوكنات التي كان يصل إليها، وراجع سجلات الوصول.',
]);

h2($section, '6.7 أخطاء التوكنات الشائعة');

table($section, ['الخطأ', 'المعنى', 'الحل'], [
    ['Code 190', 'توكن غير صالح أو منتهٍ', 'أعد توليد التوكن، وتأكد أنك تستخدم توكن التطبيق الصحيح'],
    ['Code 102', 'Session منتهية', 'أعد المصادقة (يخص توكنات المستخدم)'],
    ['Code 463 / 467', 'انتهاء صلاحية أو إبطال', 'أعد الربط / التوليد'],
    ['Code 200', 'لا تملك صلاحية على الأصل', 'تحقق من Advanced Access وBusiness Verification ومنح الأصل للـ System User'],
    ['Code 100', 'معامل غير صحيح', 'راجع IDs والصلاحيات المطلوبة للـ endpoint'],
    ['"Invalid OAuth access token"', 'توكن من تطبيق آخر أو مسافات زائدة', 'أعد النسخ بدون مسافات، وتأكد من App ID'],
], [26, 34, 40]);

// ═══════════════════════════════════════════════════════════════════════════
// 7. التحقق من النشاط التجاري
// ═══════════════════════════════════════════════════════════════════════════

h1($section, '7', 'التحقق من النشاط التجاري (Business Verification)');

para($section, 'هذا ليس اختياريًا في نموذجك. أي تطبيق يخدم أعمالًا أخرى ويطلب Advanced Access يجب أن يكون مرتبطًا بحساب أعمال **مُتحقَّق منه**. بدونه تظل الصلاحيات محدودة بأدوار التطبيق، ويصبح Embedded Signup محدودًا جدًا.');

h2($section, '7.1 متى تحتاجه؟');

bullets($section, [
    'قبل طلب **Advanced Access** لأي صلاحية (واتساب، ماسنجر، إنستجرام).',
    'قبل رفع حد Embedded Signup من 10 عملاء/7 أيام إلى 200.',
    'قبل رفع حد القوالب من 250 إلى الحد الأعلى في الحساب المُتحقَّق.',
    'قبل أن يستطيع عملاء خارج فريقك منح تطبيقك الصلاحيات.',
]);

h2($section, '7.2 جهّز البيانات قبل البدء');

table($section, ['البيان', 'القاعدة'], [
    ['الاسم القانوني', 'مطابق حرفيًا للسجل التجاري في Business Portfolio والموقع والفواتير'],
    ['العنوان', 'نفس العنوان في كل مكان، بما في ذلك صيغة الشارع والرمز البريدي'],
    ['رقم الهاتف', 'رقم يمكن الرد عليه أثناء التحقق'],
    ['الموقع الإلكتروني', 'يعمل، يحتوي اسم الشركة والعنوان ووسيلة تواصل، ومتوافق مع الدومين'],
    ['البريد', 'بريد على دومين الشركة، وليس Gmail شخصيًا'],
    ['المستندات', 'سارية، واضحة، وغير معدّلة رقميًا'],
], [24, 76]);

h2($section, '7.3 خطوات التقديم');

numbered($section, [
    'افتح **Business settings → Security Center**.',
    'اضغط **Start verification** بجانب حساب الأعمال.',
    'اختر نوع النشاط (شركة / مؤسسة فردية / منظمة).',
    'أدخل البيانات القانونية كما هي في المستندات.',
    'ارفع المستندات المطلوبة. صوّر الأصل كاملًا بإضاءة جيدة، دون قص للحواف.',
    'أرسل الطلب وانتظر. قد تُطلب مستندات إضافية أو مكالمة تحقق.',
    'تابع الحالة من Security Center؛ لا تُعد الإرسال قبل انتهاء المراجعة إلا إذا طُلب منك ذلك.',
]);

h2($section, '7.4 المستندات المقبولة');

table($section, ['المستند', 'ماذا يثبت', 'ملاحظات'], [
    ['السجل التجاري', 'الاسم القانوني والنشاط', 'الأقوى قبولًا؛ يجب أن يكون ساريًا'],
    ['البطاقة الضريبية', 'الاسم والعنوان', 'مقبولة في معظم الدول'],
    ['فاتورة مرافق (كهرباء/ماء/إنترنت)', 'العنوان', 'يجب أن تحمل اسم الشركة والعنوان'],
    ['كشف بنكي', 'اسم الشركة والعنوان', 'احجب الأرقام الحساسة إن أمكن'],
    ['فاتورة هاتف أرضي', 'العنوان ورقم التواصل', 'مفيدة عندما يكون الرقم أرضيًا'],
    ['خطاب من جهة حكومية', 'النشاط والعنوان', 'حسب الدولة'],
], [30, 30, 40]);

h2($section, '7.5 أسباب الرفض وكيف تصلحها');

table($section, ['سبب الرفض', 'ما حدث فعلًا', 'الإصلاح'], [
    ['الاسم غير متطابق', 'الاسم في Portfolio مختصر أو مختلف عن السجل', 'وحّد الاسم القانوني في كل الأصول ثم أعد التقديم'],
    ['العنوان غير متطابق', 'الموقع يعرض عنوانًا آخر أو لا يعرض عنوانًا', 'أضف العنوان الكامل في الموقع والتواصل'],
    ['مستند غير واضح', 'صورة مقطوعة أو مائلة أو منخفضة الدقة', 'أعد التصوير بكامل الحواف وبدقة عالية'],
    ['مستند منتهي', 'تاريخ انتهاء قديم', 'استخدم مستندًا ساريًا'],
    ['الموقع لا يعمل', 'دومين معطل أو صفحة قيد الإنشاء', 'انشر موقعًا حقيقيًا بصفحات تعمل'],
    ['البريد لا يطابق الدومين', 'استخدام Gmail', 'أنشئ بريدًا على دومين الشركة وأضفه للـ portfolio'],
    ['نشاط غير واضح', 'لا يوجد وصف لما تبيعه الشركة', 'أضف صفحة "من نحن" ووصفًا للنشاط'],
    ['رفض متكرر', 'تقديم نفس المستندات دون تغيير', 'غيّر المستند/البيانات، ولا تكرر نفس الطلب'],
], [24, 40, 36]);

note($section, 'danger', 'لا تقدّم باسم "AYDRA" فقط إن لم تكن شركة مسجلة', 'إن كان النشاط فرديًا، اختر النوع الصحيح (Sole Proprietorship / Individual) وقدّم المستندات المطلوبة لذلك. ادّعاء شركة غير مسجلة يؤدي لرفض وإيقاف محتمل.');

h2($section, '7.6 المدة والمتابعة');

para($section, 'المدة تتراوح عادة من يوم إلى عدة أسابيع حسب الدولة واكتمال المستندات. لا يوجد "تسريع" موثوق عبر الدعم إلا في حالات تعطل واضح. إن تجاوزت أسبوعين بدون تحديث، افتح تذكرة من **Meta Business Help Center** واذكر رقم الحالة، ولا تُرسل طلبًا جديدًا بالتوازي.');

h2($section, '7.7 بعد الموافقة');

bullets($section, [
    'ستظهر شارة **Verified** بجانب حساب الأعمال.',
    'يمكنك الآن طلب Advanced Access في App Review.',
    'ارفع حد القوالب وحد Embedded Signup حسب الأهلية.',
    'أعد التحقق إذا تغيّر الاسم القانوني أو العنوان أو النشاط.',
]);

// ═══════════════════════════════════════════════════════════════════════════
// 8. مراجعة التطبيق
// ═══════════════════════════════════════════════════════════════════════════

h1($section, '8', 'مراجعة التطبيق (App Review)');

para($section, 'App Review هي بوابتك لاستخدام الصلاحيات مع عملاء حقيقيين. الفكرة بسيطة: Meta تريد أن ترى التطبيق **يعمل فعلًا** ويستخدم كل صلاحية يطلبها، لا أن يطلبها "للمستقبل".');

h2($section, '8.1 Standard مقابل Advanced');

table($section, ['المستوى', 'من يستطيع استخدام الصلاحية؟', 'متى يكفيك؟'], [
    ['Standard', 'فقط من لديه دور على التطبيق (Admin/Developer/Tester)', 'التطوير والاختبار الداخلي'],
    ['Advanced', 'أي مستخدم يمنح الصلاحية بعد موافقة المراجعة', 'خدمة عملاء حقيقيين — وهذا ما تحتاجه'],
], [18, 50, 32]);

para($section, 'تطبيقات الأعمال تحصل تلقائيًا على Standard Access لكل الصلاحيات المتاحة لنوع Business، لذلك يمكنك الاختبار قبل المراجعة. لكن Standard لا يكفي لعملائك.');

h2($section, '8.2 الصلاحيات التي ستطلبها');

table($section, ['الصلاحية', 'التبرير في الطلب'], [
    ['`whatsapp_business_management`', 'إدارة أرقام وقوالب حساب واتساب الخاص بالعميل واشتراكات الـ webhooks'],
    ['`whatsapp_business_messaging`', 'إرسال ردود العملاء واستقبال رسائلهم عبر AYDRA'],
    ['`business_management`', 'قراءة أصول العميل التي منحها أثناء الربط (فقط ما يلزم)'],
    ['`pages_messaging`', 'الرد على رسائل صفحة العميل عبر AYDRA'],
    ['`pages_show_list`', 'اختيار الصفحة أثناء الربط'],
    ['`pages_read_engagement`', 'قراءة بيانات الصفحة اللازمة للمحادثة'],
    ['`pages_manage_metadata`', 'إدارة اشتراك الصفحة في الـ webhooks'],
    ['`instagram_basic`', 'ربط حساب Instagram الاحترافي'],
    ['`instagram_manage_messages`', 'الرد على رسائل Instagram'],
], [34, 66]);

h2($section, '8.3 خطوات التقديم');

numbered($section, [
    'تأكد أن Business Verification مكتمل.',
    'افتح **App Review → Permissions and features**.',
    'اختر الصلاحية واضغط **Request advanced access**.',
    'أكمل **Verification** إن طُلب (تأكيد النشاط، وصف الاستخدام).',
    'أضف **فيديو Screencast** يوضح الاستخدام الكامل.',
    'أضف **وصفًا مكتوبًا** وخطوات إعادة الإنتاج وبيانات اختبار.',
    'أرسل الطلب. لا تُرسل طلبات متعددة متوازية لنفس الصلاحية.',
]);

h2($section, '8.4 فيديو المراجعة: السكربت العملي');

para($section, 'الفيديو أهم عنصر. اجعله 2–5 دقائق، بصوت واضح أو تعليق نصي إنجليزي، وبدون مونتاج يخفي الخطوات. أرِ التطبيق الحقيقي وليس Slides.');

table($section, ['المشهد', 'ما تعرضه', 'المدة'], [
    ['1', 'فتح AYDRA، تسجيل الدخول، اسم التطبيق ظاهر', '15 ثانية'],
    ['2', 'الضغط على "ربط واتساب" وفتح نافذة Meta', '20 ثانية'],
    ['3', 'اختيار Business Portfolio / WABA / الرقم وإكمال الصلاحيات', '40 ثانية'],
    ['4', 'عودة النافذة إلى AYDRA وظهور رسالة النجاح', '15 ثانية'],
    ['5', 'إرسال رسالة من هاتف إلى الرقم، وظهورها في صندوق الوارد', '30 ثانية'],
    ['6', 'الرد من AYDRA ووصول الرد للهاتف', '30 ثانية'],
    ['7', 'عرض قالب/إشعار/حالة تسليم إن كانت الصلاحية تخصها', '20 ثانية'],
    ['8', 'ختام: شرح مختصر لمن يستخدم التطبيق ولماذا', '15 ثانية'],
], [10, 74, 16]);

note($section, 'warn', 'لا تستخدم بيانات وهمية فقط', 'المراجع يريد رؤية تدفق حقيقي. استخدم رقم اختبار حقيقيًا وأرسل رسالة فعلية. الشاشات المزيفة أو الفيديو المقصوص عند لحظة الحساسية تسبب رفضًا فوريًا.');

h2($section, '8.5 الوصف المكتوب');

para($section, 'اكتب وصفًا مباشرًا يجيب على أربعة أسئلة: ماذا يفعل التطبيق؟ من يستخدمه؟ أين تُستخدم كل صلاحية؟ كيف يعيد المراجع الخطوات؟');

code($section, <<<'TEXT'
AYDRA is a multi-tenant CRM used by businesses to manage customer conversations.
Business owners connect their own WhatsApp Business Account, Facebook Page and
Instagram professional account to AYDRA. AYDRA then displays incoming messages
and sends replies on their behalf.

whatsapp_business_messaging: send replies and receive messages for the connected WABA.
whatsapp_business_management: list phone numbers and manage message templates for that WABA.
pages_messaging: send and receive Messenger conversations for the connected Page.
instagram_manage_messages: send and receive Instagram direct messages.
business_management: read the assets the customer selected during Embedded Signup.

How to test:
1. Open https://app.example.com and log in with the test account below.
2. Go to Inbox > Setup > Connect WhatsApp.
3. Complete the Meta popup with the test business portfolio.
4. Send "hello" from the test phone to the connected number.
5. The message appears in Inbox; reply from the composer.
TEXT, 'استبدل النطاق وبيانات الاختبار ببياناتك الفعلية.');

h2($section, '8.6 بيانات الاختبار');

bullets($section, [
    'أنشئ مستخدم اختبار بدور Tester على التطبيق، وأضفه كـ Test user في Business Suite.',
    'أرسل بريدًا/كلمة مرور في خانة الملاحظات، مع خطوات واضحة.',
    'تأكد أن الحساب يستطيع إكمال الربط دون أن يكون Admin على التطبيق، وإلا فلن تختبر السيناريو الحقيقي.',
    'أضف أرقام اختبار مُتحقَّق منها مسبقًا.',
    'اذكر أي خطوة تحتاج انتظارًا (مثل وصول OTP).',
]);

h2($section, '8.7 أسباب الرفض الشائعة');

table($section, ['سبب الرفض', 'كيف تتجنبه'], [
    ['صلاحية غير مستخدمة في الفيديو', 'اطلب فقط ما يظهر فعليًا في التطبيق'],
    ['فيديو لا يوضح منح الصلاحية', 'أظهر نافذة Meta كاملة أثناء الموافقة'],
    ['تعذر على المراجع تسجيل الدخول', 'بيانات اختبار صحيحة ودائمة، وليست مؤقتة'],
    ['التطبيق لا يعمل أو صفحة بيضاء', 'اختبر من شبكة خارجية قبل الإرسال'],
    ['وصف عام ("CRM يساعد الأعمال")', 'اذكر كل صلاحية ووظيفتها بدقة'],
    ['استخدام علامة تجارية ليست لك', 'لا تضع شعارات Meta/WhatsApp كأنها منتجك'],
    ['طلب صلاحيات نشر بلا نشر فعلي', 'احذفها أو أظهر النشر'],
    ['بيانات وهمية بلا تدفق حقيقي', 'استخدم رقمًا حقيقيًا ورسالة حقيقية'],
], [40, 60]);

h2($section, '8.8 بعد الموافقة');

bullets($section, [
    'اختبر الصلاحية بحساب **خارج** أدوار التطبيق للتأكد أنها تعمل.',
    'راقب **App Review → Requests** لأي ملاحظات أو طلبات إضافية.',
    'احتفظ بالفيديو والوصف؛ قد تحتاجهما عند إعادة المراجعة أو إضافة صلاحية.',
    'أي تغيير جوهري في طريقة الاستخدام قد يتطلب مراجعة جديدة.',
    'لا تحذف الصلاحيات من التطبيق بعد الموافقة؛ قد يؤدي ذلك لإعادة المراجعة.',
]);

// ═══════════════════════════════════════════════════════════════════════════
// 9. Embedded Signup
// ═══════════════════════════════════════════════════════════════════════════

h1($section, '9', 'Embedded Signup: ربط العملاء بأنفسهم');

para($section, 'بدلًا من أن يرسل لك كل عميل WABA ID وتوكنًا يدويًا، تفتح له نافذة Meta الرسمية داخل AYDRA. يختار أصوله، يضيف بطاقته، ويُكمل. أنت تستلم المعرّفات وتكمل الربط على الخادم. هذا هو المسار الذي يجعل "تطبيقًا واحدًا لعشرات العملاء" قابلًا للتشغيل.');

h2($section, '9.1 المتطلبات');

table($section, ['المتطلب', 'لماذا'], [
    ['Tech Provider (أو Solution Partner)', 'Embedded Signup مخصص للشركاء، وليس للمطوّر المباشر'],
    ['Business Verification', 'شرط لرفع الحد وللـ Advanced Access'],
    ['App Review للصلاحيات', 'بدونها يعمل الربط فقط مع أدوار التطبيق'],
    ['Allowed domains + HTTPS', 'النافذة ترفض العمل على دومين غير مسجل'],
    ['`config_id` صحيح', 'يحدد الأصول والصلاحيات التي ستظهر للعميل'],
    ['Callback URL يعمل', 'لاكتمال الاشتراك وأحداث الحساب'],
], [36, 64]);

h2($section, '9.2 الكود الكامل (الإصدار v4)');

para($section, 'الكود التالي هو الحد الأدنى الذي يعمل. ضع `APP_ID` و`CONFIG_ID` ونسخة Graph API الصحيحة. لاحظ أن الكود يستمع لرسائل النافذة (session logging) ويستلم كود التبديل من `FB.login`:');

code($section, <<<'HTML'
<!-- 1) تحميل SDK -->
<script async defer crossorigin="anonymous"
        src="https://connect.facebook.net/en_US/sdk.js"></script>

<script>
  // 2) تهيئة SDK
  window.fbAsyncInit = function () {
    FB.init({
      appId: '<APP_ID>',
      autoLogAppEvents: true,
      xfbml: true,
      version: '<GRAPH_API_VERSION>' // مثال: v27.0
    });
  };

  // 3) استماع رسائل النافذة: FINISH / CANCEL / ERROR
  window.addEventListener('message', function (event) {
    if (!event.origin.endsWith('facebook.com')) return;
    try {
      var data = JSON.parse(event.data);
      if (data.type === 'WA_EMBEDDED_SIGNUP') {
        // أرسل الحدث للخادم ليسجل waba_id / phone_number_id / current_step
        fetch('/integrations/meta/embedded-signup/event', {
          method: 'POST',
          headers: {'Content-Type': 'application/json'},
          body: JSON.stringify(data)
        });
      }
    } catch (e) {
      console.log('message event', event.data);
    }
  });

  // 4) استلام الكود القابل للتبديل
  function fbLoginCallback(response) {
    if (response.authResponse && response.authResponse.code) {
      fetch('/integrations/meta/embedded-signup/exchange', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({code: response.authResponse.code})
      });
    } else {
      console.log('cancelled', response);
    }
  }

  // 5) فتح النافذة
  function launchWhatsAppSignup() {
    FB.login(fbLoginCallback, {
      config_id: '<CONFIGURATION_ID>',
      response_type: 'code',
      override_default_response_type: true,
      extras: {setup: {}}
    });
  }
</script>

<button onclick="launchWhatsAppSignup()">ربط واتساب</button>
HTML, 'استبدل القيم بين الأقواس. لا تضع App Secret في JavaScript أبدًا.');

h2($section, '9.3 الأحداث التي ستصلك');

table($section, ['الحدث', 'متى يحدث؟', 'ماذا تفعل؟'], [
    ['`FINISH`', 'أكمل العميل ربط WABA ورقم', 'اقرأ `waba_id` و`phone_number_id` واحفظهما'],
    ['`FINISH_ONLY_WABA`', 'أكمل WABA بدون رقم', 'احفظ WABA، واطلب إضافة الرقم لاحقًا'],
    ['`FINISH_WHATSAPP_BUSINESS_APP_ONBOARDING`', 'ربط رقم تطبيق واتساب الأعمال (Coexistence)', 'تعامل مع الحالة كربط coexistence وليس رقمًا جديدًا'],
    ['`FINISH_GRANT_ONLY_API_ACCESS`', 'منح صلاحية فقط دون إنشاء أصول', 'حدّث الصلاحيات دون إنشاء WABA جديد'],
    ['`CANCEL`', 'أغلق العميل النافذة قبل النهاية', 'اقرأ `data.current_step` لتعرف أين توقف'],
    ['`ERROR`', 'خطأ أبلغ عنه العميل', 'سجّل `error_code` و`session_id` وتواصل مع الدعم إن تكرر'],
], [30, 34, 36]);

note($section, 'info', 'الفرق بين FINISH وCANCEL', 'إغلاق النافذة على الشاشة الأخيرة يُعد نجاحًا ويُرسل بيانات الأصول. الإلغاء قبل الشاشة الأخيرة فقط هو `CANCEL`، وفيه لا تصلك معرّفات.');

h2($section, '9.4 إكمال الربط على الخادم (Tech Provider)');

para($section, 'العميل أكمل النافذة، لكن أصوله ليست جاهزة بعد. نفّذ هذه الخطوات بالترتيب. الكود صالح 30 ثانية فقط، لذلك الخطوة 1 يجب أن تكون فورية:');

code($section, <<<'BASH'
# 1) تبديل الكود بتوكن العميل
curl -G "https://graph.facebook.com/v27.0/oauth/access_token" \
  -d "client_id={APP_ID}" \
  -d "client_secret={APP_SECRET}" \
  -d "code={CODE}"

# 2) اشتراك تطبيقك في أحداث WABA
curl -X POST "https://graph.facebook.com/v27.0/{WABA_ID}/subscribed_apps" \
  -d "access_token={CUSTOMER_TOKEN}"

# 3) تسجيل رقم الهاتف (إن لم يكن مسجلًا)
curl -X POST "https://graph.facebook.com/v27.0/{PHONE_NUMBER_ID}/register" \
  -d "messaging_product=whatsapp" \
  -d "pin={6_DIGITS}" \
  -d "access_token={CUSTOMER_TOKEN}"

# 4) تحقق من الرقم وحالته
curl -G "https://graph.facebook.com/v27.0/{PHONE_NUMBER_ID}" \
  -d "fields=display_phone_number,verified_name,quality_rating,status" \
  -d "access_token={CUSTOMER_TOKEN}"
BASH, 'استخدم توكن العميل، لا توكن System User الخاص بك.');

note($section, 'warn', 'الخطوة 2 ليست اختيارية', 'إذا لم تشترك في `subscribed_apps`، فلن تصلك رسائل ذلك العميل حتى لو نجح الربط وظهر في اللوحة. هذا من أكثر أسباب "الرسائل لا تصل" بعد Embedded Signup.');

h2($section, '9.5 حدود Onboarding');

table($section, ['الحالة', 'الحد'], [
    ['قبل التحقق التجاري والمراجعة', '10 عملاء كل 7 أيام'],
    ['بعد Business Verification + App Review', '200 عميل كل 7 أيام'],
    ['التوكن الناتج', 'صالح 60 يومًا في القالب الشائع'],
    ['كود التبديل', '30 ثانية فقط'],
], [50, 50]);

para($section, 'خطّط للإطلاق على دفعات. إن كان لديك 30 عميلًا في الأسبوع الأول، ستحتاج التحقق والمراجعة قبل البدء، وإلا ستتوقف عند 10.');

h2($section, '9.6 Coexistence: العميل الذي يستخدم تطبيق واتساب الأعمال');

para($section, 'يمكنك تفعيل خيار يتيح للعميل استخدام رقمه الحالي على تطبيق WhatsApp Business مع الاستمرار في استخدام التطبيق بعد الربط. هذا مفيد للعملاء الذين لا يريدون رقمًا جديدًا، لكنه يغيّر سلوك الحساب:');

bullets($section, [
    'يظل العميل قادرًا على إرسال رسائل فردية من تطبيق واتساب الأعمال.',
    'Meta تزامن سجل الرسائل بين التطبيق والـ API.',
    'الحدث يعود بـ `FINISH_WHATSAPP_BUSINESS_APP_ONBOARDING` وليس `FINISH`.',
    'لا تتعامل معه كرقم جديد؛ تحقق من الحالة قبل تسجيل الرقم.',
    'بعض ميزات الأعمال (مثل الكتالوج) قد تتأثر؛ راجع توثيق Coexistence قبل تفعيله لعملاء لديهم كتالوج.',
]);

h2($section, '9.7 أخطاء Embedded Signup الشائعة');

table($section, ['العرض', 'السبب', 'الحل'], [
    ['النافذة لا تفتح', 'Allowed domains ناقص أو الدومين ليس HTTPS', 'أضف الدومين في إعدادات Facebook Login'],
    ['النافذة تفتح ثم بيضاء', 'config_id خاطئ أو الصلاحيات غير متاحة', 'تحقق من نوع الإعداد ومن Advanced Access'],
    ['"App not set up"', 'التطبيق في Development والعميل ليس له دور', 'أضفه كـ Tester أو انشر التطبيق'],
    ['لا تظهر الصفحات/الحسابات', 'أصول غير مختارة في الإعداد، أو المستخدم ليس Admin', 'راجع أصول الإعداد، واطلب من العميل دور Admin'],
    ['خطأ بعد FINISH', 'الكود انتهى (30 ثانية) أو استُخدم مرتين', 'بدّل الكود فورًا وعلى الخادم فقط'],
    ['لا تصل رسائل بعد الربط', 'لم يتم `subscribed_apps`', 'نفّذ الاشتراك وأعد الاختبار'],
    ['"رقم مسجل بالفعل"', 'الرقم على تطبيق واتساب عادي', 'ألغِ تسجيله أو استخدم Coexistence'],
    ['"Verified name violates guidelines"', 'الاسم المعروض مخالف', 'غيّره لاسم مطابق للعلامة التجارية'],
    ['العميل يكمل ولا يظهر في AYDRA', 'الحفظ فشل بعد النافذة، أو webhook غير مضبوط', 'راجع سجلات الخادم وحدث `account_update`'],
], [26, 34, 40]);

note($section, 'tip', 'استخدم Embedded Signup Builder', 'قبل اختبار العميل، جرّب الإعداد من **WhatsApp → Embedded Signup Builder** وتأكد أن النافذة تفتح وتعيد `FINISH`. هذا يعزل مشاكل الإعداد عن مشاكل كود AYDRA.');

// ═══════════════════════════════════════════════════════════════════════════
// 10. ربط الحقول في AYDRA
// ═══════════════════════════════════════════════════════════════════════════

h1($section, '10', 'ربط الحقول في AYDRA');

para($section, 'كل ما سبق ينتهي عند شاشة واحدة: **Admin → Integrations → Meta App**. الحقول الستة التالية هي جسر AYDRA إلى تطبيق Meta. لا تدخل قيمة في الحقل الخطأ.');

h2($section, '10.1 جدول الحقول');

table($section, ['حقل AYDRA', 'من أين تجلبه؟', 'مثال', 'مطلوب؟'], [
    ['`app_id`', 'App settings → Basic → App ID', '1234567890123456', 'نعم'],
    ['`app_secret`', 'App settings → Basic → App secret', 'a1b2c3…', 'نعم'],
    ['`system_user_token`', 'Business settings → System users → Generate token', 'EAAG…', 'مستحسن'],
    ['`verify_token`', 'تختاره أنت (نفس القيمة في Meta)', '64 حرفًا عشوائيًا', 'مستحسن'],
    ['`config_id_whatsapp`', 'Facebook Login for Business → إعداد واتساب', '9876543210', 'لـ Embedded Signup'],
    ['`config_id_social`', 'Facebook Login for Business → إعداد القنوات الاجتماعية', '1029384756', 'لـ Messenger/Instagram'],
], [22, 42, 26, 10]);

h2($section, '10.2 ماذا يفعّل كل حقل؟');

table($section, ['الحقل', 'الأثر عند غيابه'], [
    ['`app_id` + `app_secret`', 'كل طلبات Meta تفشل. ستظهر رسالة AYDRA: "Meta credentials not configured" (403)'],
    ['`system_user_token`', 'تفشل عمليات النظام: إدارة القوالب، قراءة أصول AYDRA، بعض فحوص الصحة'],
    ['`verify_token`', 'يفشل التحقق الأولي من webhook واتساب (403)'],
    ['`config_id_whatsapp`', 'زر الربط الذاتي لواتساب لا يعمل؛ يبقى الربط اليدوي متاحًا'],
    ['`config_id_social`', 'ربط Messenger/Instagram من داخل AYDRA لا يعمل'],
], [28, 72]);

note($section, 'danger', 'الحقول الحساسة', '`app_secret` و`system_user_token` لا يظهران بعد الحفظ. عند تدويرهما، الصق القيمة الجديدة كاملة ثم احفظ. لا تضع مسافات أو أسطرًا جديدة؛ فهي سبب شائع لخطأ 190.');

h2($section, '10.3 العلاقة بين config_id والصلاحيات');

para($section, 'إعداد `config_id_social` في AYDRA مستخدم أيضًا لربط حسابات النشر الاجتماعي، وليس فقط الرسائل. لذلك يجب أن يحتوي على صلاحيات النشر إن كنت تستخدم هذه الميزة:');

bullets($section, [
    '`pages_show_list` و`pages_read_engagement` لاختيار الصفحة وقراءتها.',
    '`pages_manage_posts` للنشر على الصفحة.',
    '`instagram_basic` و`instagram_content_publish` للنشر على Instagram.',
    '`instagram_manage_messages` و`pages_messaging` إن أردت الرسائل من نفس الإعداد.',
]);

para($section, 'إن كان لديك فريقان مختلفان (تسويق وخدمة عملاء)، يمكنك إنشاء إعدادين منفصلين واستخدام `config_id` مختلف لكل زر. لكن AYDRA يحفظ حقلين فقط حاليًا، فالأبسط أن تجمع الصلاحيات في `config_id_social` ما دمت تستخدمها فعلًا.');

h2($section, '10.4 الربط اليدوي كبديل');

para($section, 'إذا لم يكن Embedded Signup جاهزًا، يمكن ربط العميل يدويًا. هذا مفيد للعملاء الأوائل وللاختبار:');

numbered($section, [
    'اطلب من العميل: WABA ID، وPhone number ID (إن وجد)، وتوكنًا دائمًا من System User لديه.',
    'أضف التوكن في قناة واتساب الخاصة بالـ workspace، مع WABA ID.',
    'اضبط webhook الـ WABA على `/webhooks/whatsapp/{token}` الخاص بالـ workspace، أو استخدم المسار العام إن كان العميل يشارك التطبيق.',
    'اختبر بإرسال رسالة من رقم العميل والتأكد من وصولها.',
    'وثّق تاريخ الربط ومدة التوكن في سجل العميل.',
]);

note($section, 'warn', 'الربط اليدوي لا يعفي من الملكية', 'التوكن اليدوي يجب أن يكون من System User يملك أصول العميل، وليس توكنك الشخصي. إن استخدمت توكنك، ستفقد الوصول عند تغيير كلمة مرورك أو انتهاء الجلسة.');

h2($section, '10.5 أخطاء الربط الشائعة');

table($section, ['العرض', 'السبب', 'الحل'], [
    ['403 "Meta credentials not configured"', '`app_id` أو `app_secret` فارغ', 'املأ الحقلين من Basic Settings'],
    ['403 عند التحقق من webhook', '`verify_token` مختلف عن Meta', 'انسخ نفس القيمة حرفيًا'],
    ['"Invalid appsecret_proof"', 'App Secret خاطئ', 'أعد نسخ السر وتأكد من عدم وجود مسافات'],
    ['زر الربط لا يظهر', '`config_id` فارغ', 'أضف الإعداد أو استخدم الربط اليدوي'],
    ['الربط ينجح لكن لا قناة', 'لم يُحفظ WABA/Phone ID في الـ workspace', 'أكمل خطوة الحفظ، وتحقق من سجل الأحداث'],
    ['رسائل تذهب لعميل آخر', 'توجيه خاطئ حسب WABA/Page', 'راجع ربط الأصول بالـ workspace، فهذا خطر عزل'],
], [30, 30, 40]);

// ═══════════════════════════════════════════════════════════════════════════
// 11. الـ Webhooks
// ═══════════════════════════════════════════════════════════════════════════

h1($section, '11', 'الـ Webhooks في AYDRA');

para($section, 'الـ webhook هو العصب الحسي للتطبيق: بدونه لن تعرف أن رسالة وصلت إلا لو سألت Meta باستمرار (وهذا مكلف وبطيء). AYDRA تستقبل الأحداث على مسارات محددة، تتحقق من التوقيع، ثم تعالجها في الطابور.');

h2($section, '11.1 المسارات');

table($section, ['الغرض', 'المسار', 'التحقق'], [
    ['واتساب (كل العملاء)', '`/webhooks/whatsapp/global`', 'Verify Token العام + توقيع App Secret'],
    ['واتساب (عميل محدد)', '`/webhooks/whatsapp/{token}`', 'توكن القناة + توقيع App Secret أو سر مخصص'],
    ['Messenger + Instagram', '`/webhooks/meta/{token}`', 'توكن الـ workspace + توقيع App Secret'],
    ['Meta Lead Ads', '`/webhooks/meta-leadgen/{token}`', 'توكن مستقل'],
], [26, 44, 30]);

note($section, 'info', 'ماذا يعني الرمز {token}؟', 'ليس توكن Meta، بل معرف تحقق خاص بـ AYDRA يُستخدم لتحديد الـ workspace بأمان. لكل workspace قيمته، ولا يجب تخمينها أو إعادة استخدامها بين العملاء.');

h2($section, '11.2 التحقق الأولي (GET)');

para($section, 'عندما تحفظ Callback URL وVerify Token في لوحة Meta، يرسل Meta طلب GET فورًا. يجب أن يعيد الخادم قيمة `hub.challenge` كنص خام، وليس JSON أو صفحة HTML:');

code($section, <<<'HTTP'
GET /webhooks/whatsapp/global
  ?hub.mode=subscribe
  &hub.verify_token=YOUR_VERIFY_TOKEN
  &hub.challenge=1234567890

# الرد المطلوب: 200 مع النص 1234567890 فقط
HTTP);

para($section, 'أي رد غير 200 أو نص مختلف يعني فشل الحفظ في Meta. تحقق من السجلات فورًا عند الضغط على Verify and Save.');

h2($section, '11.3 التحقق من التوقيع (POST)');

para($section, 'كل طلب POST يحمل ترويسة `X-Hub-Signature-256`، وهي HMAC-SHA256 للجسم الخام (raw body) باستخدام App Secret. لا تتحقق من JSON بعد إعادة ترميزه، لأن أي تغيير في المسافات أو الترتيب يغيّر التوقيع:');

code($section, <<<'PHP'
$raw = $request->getContent(); // الجسم الخام كما وصل
$expected = 'sha256='.hash_hmac('sha256', $raw, $appSecret);

if (! hash_equals($expected, (string) $request->header('X-Hub-Signature-256'))) {
    abort(403, 'Invalid signature');
}
PHP, 'نفس المنطق مستخدم داخل AYDRA، مع دعم سر مخصص لكل WABA عند الحاجة (app_secret_override).');

bullets($section, [
    'لا تعتمد على IP المصدر؛ التوقيع هو الضمان.',
    'احسب التوقيع قبل أي middleware يعدّل الجسم.',
    'ارفض الطلب فورًا عند عدم التطابق، وسجّل عنوان المصدر للمراجعة.',
    'إن كنت تستخدم أكثر من App Secret (قديم/جديد أثناء التدوير)، جرّب الاثنين ثم احذف القديم.',
]);

h2($section, '11.4 الاشتراك في الأحداث');

numbered($section, [
    'في **WhatsApp → Configuration** أدخل Callback URL وVerify Token، ثم **Verify and Save**.',
    'اشترك في حقول `whatsapp_business_account` المطلوبة (الفصل 5).',
    'في **Messenger → Settings → Webhooks** كرر العملية، واشترك في حقول `page`.',
    'في **Instagram → Webhooks** اشترك في `messages` و`messaging_postbacks` على object = `instagram`.',
    'لكل عميل: نفّذ `POST /{waba-id}/subscribed_apps` أو `POST /{page-id}/subscribed_apps`.',
    'أرسل رسالة اختبار وتأكد من وصول الحدث في السجلات.',
]);

h2($section, '11.5 إعادة المحاولة والتكرار');

bullets($section, [
    'Meta تعيد إرسال الحدث حتى 7 أيام إذا لم تستجب بـ 2xx. هذا يعني أن التكرار وارد جدًا.',
    'استخدم معرّف الرسالة (`messages[].id`) لمنع المعالجة المزدوجة (Unique index أو Cache lock).',
    'أرسل 200 بسرعة ثم عالج في طابور. المعالجة الثقيلة داخل الطلب تسبب Timeout وإعادة إرسال.',
    'الحد الأقصى لحجم الحمولة 3 ميغابايت تقريبًا؛ لا تفترض حمولات صغيرة للوسائط.',
    'بعض الأحداث لا تُرسل إلا حين يكون التطبيق Live، خصوصًا ما يتعلق بالحساب والقوالب.',
]);

h2($section, '11.6 أمر التحقق داخل AYDRA');

para($section, 'يوفّر AYDRA أمرًا يطبع رابط الـ callback وقيمة تحقق مختصرة، مفيد عند إعداد عميل جديد:');

code($section, <<<'BASH'
php artisan whatsapp:register-webhook
BASH, 'شغّله من جذر المشروع. راجع المخرجات وضع الرابط في Meta.');

h2($section, '11.7 اختبار عملي');

numbered($section, [
    'اختبر التحقق: افتح الرابط في المتصفح مع `hub.mode=subscribe` و`hub.verify_token` الصحيح، وتأكد من ظهور الـ challenge.',
    'اختبر التوقيع: أرسل POST وهميًا بتوقيع صحيح وتأكد من 200، ثم بتوقيع خاطئ وتأكد من 403.',
    'أرسل رسالة حقيقية من واتساب إلى رقم العميل.',
    'راقب `storage/logs/laravel.log` أو جدول الأحداث.',
    'تأكد من ظهور المحادثة في الـ workspace الصحيح، وليس في workspace آخر.',
]);

code($section, <<<'PHP'
// توليد ترويسة توقيع لاختبار محلي
$payload = '{"object":"whatsapp_business_account","entry":[]}';
$secret  = 'YOUR_APP_SECRET';
echo 'sha256='.hash_hmac('sha256', $payload, $secret);
PHP, 'استخدم نفس الجسم الخام بالضبط في طلب الاختبار.');

h2($section, '11.8 لا تصل الأحداث: تشخيص مرتب');

table($section, ['الفحص', 'إن كان الجواب لا'], [
    ['هل التحقق الأولي نجح في Meta؟', 'أصلح Verify Token أو الرد على GET'],
    ['هل الـ WABA/Page مشترك عبر `subscribed_apps`؟', 'نفّذ الاشتراك لكل عميل'],
    ['هل التطبيق Live؟', 'بعض الأحداث لا تُرسل في Development'],
    ['هل الحقول مشتركة في اللوحة؟', 'اشترك في `messages` على الأقل'],
    ['هل الخادم يستجيب 200 بسرعة؟', 'انقل المعالجة للطابور'],
    ['هل التوقيع صحيح؟', 'أعد نسخ App Secret وتحقق من الجسم الخام'],
    ['هل الـ URL عام وشهادة SSL صالحة؟', 'استخدم HTTPS صالحًا وليس Self-signed'],
    ['هل الرسالة دخلت فعليًا؟', 'اختبر من رقم آخر وتأكد من نافذة الخدمة'],
], [46, 54]);

// ═══════════════════════════════════════════════════════════════════════════
// 12. الانتقال إلى Live
// ═══════════════════════════════════════════════════════════════════════════

h1($section, '12', 'الانتقال إلى Live (Go Live)');

para($section, 'الانتقال إلى Live ليس زرًا واحدًا؛ هو تأكيد أن كل ما بنيته جاهز للعمل مع أشخاص لا تعرفهم ولا يملكون أدوارًا على تطبيقك. إن نشرت مبكرًا، ستنكسر قنوات العملاء في أسوأ وقت.');

h2($section, '12.1 ما الذي يتغير؟');

bullets($section, [
    'الصلاحيات المُراجعة تعمل مع أي مستخدم، وليس فقط مع أدوار التطبيق.',
    'Embedded Signup يعمل مع عملاء حقيقيين خارج فريقك.',
    'أحداث الـ webhooks المرتبطة بالحساب تبدأ بالوصول.',
    'أي خطأ في الإعدادات سيؤثر على عملاء فعليين، وليس على بيانات اختبار.',
]);

h2($section, '12.2 قائمة ما قبل النشر');

table($section, ['✓', 'البند', 'كيف تتأكد'], [
    ['☐', 'App icon 1024×1024', 'مرفوع في Basic Settings'],
    ['☐', 'Privacy Policy URL', 'يفتح في نافذة خاصة بدون تسجيل دخول'],
    ['☐', 'Terms of Service URL', 'يفتح ويعمل'],
    ['☐', 'Data Deletion', 'Instructions أو Callback يعمل'],
    ['☐', 'App domains', 'يطابق دومين الإنتاج'],
    ['☐', 'Business Verification', 'Verified في Security Center'],
    ['☐', 'App Review', 'الصلاحيات الأساسية Approved'],
    ['☐', 'System User token', 'دائم أو مُدار بانتهاء معروف'],
    ['☐', 'Verify Token', 'مطابق بين Meta وAYDRA'],
    ['☐', 'Callback URL', 'HTTPS ويعيد 200'],
    ['☐', '`subscribed_apps`', 'مفعل لكل WABA/Page إنتاجي'],
    ['☐', 'اختبار من خارج الفريق', 'مستخدم تجريبي بلا دور يكمل الربط'],
    ['☐', 'المراقبة والتنبيهات', 'أخطاء 4xx/5xx وتأخر الطابور'],
    ['☐', 'خطة تراجع', 'ماذا تفعل إن تعطلت القناة بعد النشر'],
], [6, 36, 58]);

h2($section, '12.3 خطوات النشر');

numbered($section, [
    'افتح **App Dashboard → App settings → Basic**.',
    'تأكد من اكتمال كل الحقول الإلزامية (ستظهر بجانبها علامة ناقصة إن وجدت).',
    'انتقل إلى أعلى الصفحة واضغط **App Mode: Development → Live** (أو **Switch to Live**).',
    'أكمل أي نافذة تأكيد (قد تُطلب مراجعة أخيرة للسياسات).',
    'أعد تحميل الصفحة وتأكد من ظهور **Live**.',
    'نفّذ اختبار الربط والإرسال من حساب خارج الأدوار.',
    'راقب السجلات والأخطاء لمدة 24 ساعة على الأقل.',
]);

note($section, 'danger', 'لا تنشر قبل اختبار خارجي', 'اختبر بحساب ليس Admin/Developer/Tester. هذا هو الاختبار الوحيد الذي يكشف مشاكل Advanced Access وحدود المعدل ورفض الصلاحيات.');

h2($section, '12.4 بعد النشر');

bullets($section, [
    'راقب **App Dashboard → Alerts** وأي بريد من Meta.',
    'تابع معدل التسليم وجودة الأرقام (`quality_rating`).',
    'تأكد من أن التوكنات لا تنتهي قريبًا، خصوصًا توكنات العملاء (60 يومًا).',
    'احتفظ بنسخة من إعدادات الإنتاج (بدون أسرار) لاستعادة الوضع عند الطوارئ.',
    'لا تغيّر الصلاحيات أو الـ config_id أثناء ساعات العمل دون نافذة صيانة.',
]);

h2($section, '12.5 التراجع الآمن');

para($section, 'إعادة التطبيق إلى Development ستوقف الخدمة عن كل العملاء فورًا. لا تفعلها إلا كخيار أخير. البديل الأفضل:');

numbered($section, [
    'أوقف الميزة المتأثرة داخل AYDRA (Feature Flag) بدلًا من إيقاف التطبيق كله.',
    'إن كانت المشكلة في صلاحية واحدة، اطلب من Meta إعادة المراجعة ولا تنشر تغييرًا جديدًا.',
    'إن كان السبب تسريب App Secret، دوّره فورًا وحدّث AYDRA؛ هذا لا يوقف التطبيق.',
    'أبلغ العملاء المتأثرين برسالة واضحة ووقت متوقع للحل.',
]);

h2($section, '12.6 عوائق النشر المتكررة');

table($section, ['العائق', 'الحل'], [
    ['"App is not eligible to go live"', 'أكمل Privacy/ToS/Data Deletion/Icon أولًا'],
    ['زر Live غير مفعّل', 'راجع الصلاحيات المعلقة أو تحقق من الحساب'],
    ['الصلاحية Approved لكنها لا تعمل', 'اختبر بحساب خارج الأدوار؛ قد تكون Advanced لم تُمنح بعد'],
    ['الموقع يرفض الدومين', 'أضفه في App domains وValid OAuth redirect URIs'],
    ['الأحداث لا تصل بعد النشر', 'أعد الاشتراك في الحقول وأعد `subscribed_apps`'],
], [40, 60]);

// ═══════════════════════════════════════════════════════════════════════════
// 13. حدود الرسائل والتوسّع
// ═══════════════════════════════════════════════════════════════════════════

h1($section, '13', 'حدود الرسائل والتوسّع');

para($section, 'حد الرسائل ليس حدًا للتطبيق، بل حد **Business Portfolio**. هذا فرق جوهري: كل عميل Tech Provider له portfolio خاص، وبالتالي حدّه الخاص. لا يستهلك عميل من حد عميل آخر، ولا تستطيع تجميع الحدود.');

h2($section, '13.1 معنى الحد');

para($section, 'هو أقصى عدد **أرقام واتساب فريدة** يمكنك إرسال رسائل إليها خارج نافذة خدمة العملاء (24 ساعة) خلال 24 ساعة متحركة. الرسائل داخل النافذة لا تُحسب على الحد بنفس الطريقة، لكنها تخضع لحدود المعدل.');

h2($section, '13.2 مستويات الحد');

table($section, ['المستوى', 'كيف تصل إليه؟', 'ملاحظة'], [
    ['250', 'الافتراضي لأي portfolio جديد', 'نقطة البداية لكل عميل'],
    ['2,000', 'إكمال مسار التوسّع (Scaling Path)', 'يتطلب نشاطًا وجودة وتحقّقًا'],
    ['10,000', 'توسّع تلقائي مع الاستخدام المستقر', 'لا تحتاج طلبًا يدويًا غالبًا'],
    ['100,000', 'توسّع تلقائي إضافي', 'يعتمد على الجودة والحجم'],
    ['بلا حد', 'توسّع تلقائي للنشاط الكبير', 'ليس هدفًا مبكرًا'],
], [16, 44, 40]);

note($section, 'info', 'لا تشترِ "رفع حد"', 'لا يوجد بائع يرفع حدك. الحد يُمنح تلقائيًا أو عبر مسار التوسّع الرسمي. أي عرض خارجي لرفع الحد مقابل مال هو احتيال.');

h2($section, '13.3 جودة الرقم');

bullets($section, [
    'لكل رقم **Quality Rating**: Green / Yellow / Red. الأحمر يعني شكاوى وحظرًا محتملًا.',
    'راقب التقييم من **WhatsApp Manager → Phone numbers**.',
    'انخفاض الجودة يقلل الحد ويزيد التأخير.',
    'السبب الأول للانخفاض: رسائل تسويقية بلا موافقة (Opt-in) واضحة.',
    'أوقف الحملة فورًا عند ظهور Red، وراجع قائمة المستلمين والمحتوى.',
]);

h2($section, '13.4 القوالب والتصنيفات');

table($section, ['النوع', 'الاستخدام', 'ملاحظة'], [
    ['Marketing', 'عروض وإعلانات ومحتوى ترويجي', 'الأكثر حساسية للجودة والحد'],
    ['Utility', 'تحديث طلب، فاتورة، موعد', 'مرتبط بعملية بدأها العميل'],
    ['Authentication', 'رموز تحقق OTP', 'لا تُستخدم للترويج'],
], [22, 44, 34]);

para($section, 'حد القوالب يعتمد على حالة التحقق: الحساب غير المُتحقَّق محدود بـ 250 قالبًا لكل Messaging account، ويرتفع بعد التحقق. القوالب تُراجع آليًا وبشريًا، ورفضها يعني عدم إمكانية الإرسال بها.');

h2($section, '13.5 نافذة خدمة العملاء');

para($section, 'عندما يرسل العميل رسالة، تُفتح نافذة 24 ساعة يمكنك خلالها الرد بأي نوع رسالة. بعد انتهائها، لا يمكنك بدء محادثة إلا بقالب معتمد. هذا ليس عيبًا في AYDRA، بل قاعدة المنصة، وأي محاولة إرسال خارج النافذة ستعيد خطأ `131047`.');

h2($section, '13.6 معدل الإرسال');

para($section, 'بالإضافة إلى حد الأرقام الفريدة، هناك حد لمعدل الطلبات (Messages per second). القيمة الافتراضية تكفي معظم العملاء، وترتفع تلقائيًا أو بطلب حسب الحجم. إن كنت ترسل حملة كبيرة، وزّعها على دفعات وراقب أخطاء `130429` و`131048`.');

h2($section, '13.7 التسعير');

para($section, 'Meta انتقلت إلى تسعير لكل رسالة (Per-message) بدلًا من تسعير المحادثات في معظم الأسواق، مع اختلاف السعر حسب نوع الرسالة والدولة. لا تعتمد على أرقام قديمة؛ راجع جدول الأسعار الرسمي قبل تسعير خططك لعملائك، وتذكّر أن الفاتورة تُصدر على حساب العميل في نموذج Tech Provider.');

h2($section, '13.8 خطة نمو عملية');

table($section, ['مرحلتك', 'ما تفعله'], [
    ['أول 10 عملاء', 'أكمل التحقق والمراجعة قبل التوسّع؛ الحد 10/7 أيام'],
    ['10–50 عميلًا', 'راقب جودة كل رقم، ووثّق Opt-in لكل عميل'],
    ['50–200 عميلًا', 'أتمتة مراقبة الجودة والتنبيهات، وخطط لدعم متعدد المستويات'],
    ['أكثر من 200', 'راجع الحاجة لـ Solution Partner أو لفريق تشغيل مخصص'],
], [26, 74]);

// ═══════════════════════════════════════════════════════════════════════════
// 14. استكشاف الأخطاء
// ═══════════════════════════════════════════════════════════════════════════

h1($section, '14', 'استكشاف الأخطاء');

para($section, 'هذا الفصل مرتب حسب الأعراض. ابدأ دائمًا بالفحص التشخيصي السريع، ثم انتقل للجدول المختص. لا تغيّر أكثر من شيء واحد في كل محاولة، وإلا لن تعرف السبب الحقيقي.');

h2($section, '14.1 الفحص التشخيصي السريع');

numbered($section, [
    'هل التطبيق في الوضع الصحيح (Development/Live)؟',
    'هل App ID وApp Secret صحيحان في AYDRA؟',
    'هل التوكن صالح وغير منتهٍ؟ (جرّب `GET /me` أو `debug_token`)',
    'هل الأصل (WABA/Page) مشترك عبر `subscribed_apps`؟',
    'هل Callback URL يستجيب 200 وVerify Token مطابق؟',
    'هل التوقيع صحيح؟ (راجع السجلات)',
    'هل الرسالة داخل نافذة 24 ساعة؟',
    'هل الرقم مسجل (`registered`) وحالته Connected؟',
]);

h2($section, '14.2 أكواد أخطاء Cloud API');

table($section, ['الكود', 'المعنى', 'الحل العملي'], [
    ['100', 'معامل غير صحيح', 'راجع IDs والصيغة والصلاحيات المطلوبة'],
    ['190', 'توكن غير صالح/منتهٍ', 'أعد توليد التوكن أو الربط'],
    ['200', 'لا تملك صلاحية على الأصل', 'Advanced Access + Business Verification + منح الأصل'],
    ['368', 'حظر مؤقت بسبب مخالفة سياسة', 'راجع المحتوى، أوقف الحملة، وقدم استئنافًا'],
    ['130429', 'تجاوز حد معدل الإرسال', 'أعد المحاولة بتراجع أسّي ووزّع الحملة'],
    ['131026', 'تعذر تسليم الرسالة', 'الرقم غير صالح أو لا يستخدم واتساب أو حظرك'],
    ['131047', 'خارج نافذة 24 ساعة', 'استخدم قالبًا معتمدًا أو انتظر رسالة العميل'],
    ['131048', 'حد الرسائل التسويقية', 'قلل الحملة وراجع الجودة والموافقة'],
    ['131049', 'لم تُسلَّم بسبب حدود التسويق لكل مستخدم', 'لا تحاول التكرار؛ احترم الحد'],
    ['131051', 'نوع رسالة غير مدعوم', 'راجع `type` والوسائط'],
    ['131053', 'خطأ رفع وسائط', 'تحقق من الصيغة والحجم والرابط العام'],
    ['131056', 'حد المحادثات الزوجية', 'أعد المحاولة لاحقًا'],
    ['132000', 'عدد معاملات القالب غير مطابق', 'طابق عدد المتغيرات بدقة'],
    ['132001', 'القالب غير موجود', 'تأكد من الاسم واللغة والحالة Approved'],
    ['132005', 'نص مترجم طويل', 'اختصر النص أو استخدم لغة أخرى'],
    ['132007', 'محتوى القالب يخالف السياسة', 'أعد صياغة المحتوى'],
    ['132012', 'صيغة معامل غير صحيحة', 'استخدم نصًا أو قيمة مطابقة للمتغير'],
    ['132015', 'القالب موقوف مؤقتًا', 'راجع الجودة وانتظر أو أصلح المحتوى'],
    ['132016', 'القالب معطّل', 'أنشئ قالبًا جديدًا'],
    ['133010', 'الرقم غير مسجل', 'نفّذ `/register` أو أعد ربط الرقم'],
    ['131042', 'مشكلة في الفوترة', 'اطلب من العميل إضافة/تحديث طريقة الدفع'],
    ['131031', 'الحساب مقفل', 'راجع WhatsApp Manager وحالة الحساب'],
    ['80007', 'تجاوز حد المعدل', 'تراجع أسّي ومراقبة'],
], [12, 38, 50]);

note($section, 'warn', 'الأكواد تتغير وتتوسع', 'هذا الجدول للأخطاء الأكثر شيوعًا وليس مرجعًا كاملًا. اعتمد على `code` و`error_data.details` في الاستجابة، وليس على نص الرسالة. النصوص تتغير، والأكواد أثبت.');

h2($section, '14.3 أخطاء التوكن والصلاحيات');

table($section, ['العرض', 'السبب', 'الحل'], [
    ['`(#200) Permissions error`', 'الصلاحية Standard فقط أو الأصل غير ممنوح', 'أكمل التحقق والمراجعة، ثم امنح الأصل للـ System User'],
    ['`(#10) Application does not have permission`', 'لم تُوافق على الصلاحية أو الإعداد خاطئ', 'راجع `config_id` والصلاحيات الممنوحة'],
    ['`Invalid OAuth access token`', 'توكن من تطبيق آخر أو منتهٍ', 'أعد التوليد من نفس التطبيق'],
    ['`Session has expired`', 'توكن مستخدم وليس System User', 'استخدم System User token للإنتاج'],
    ['`(#100) Unsupported get request`', 'ID خاطئ أو كائن محذوف', 'تحقق من WABA/Phone ID'],
], [38, 32, 30]);

h2($section, '14.4 مشاكل الـ Webhooks');

table($section, ['العرض', 'السبب', 'الحل'], [
    ['فشل Verify and Save', 'Verify Token مختلف أو GET لا يعيد challenge', 'طابق القيمة وأعد النص الخام'],
    ['403 عند كل POST', 'App Secret خاطئ أو الجسم معدّل', 'أعد نسخ السر واحسب HMAC على raw body'],
    ['تصل أحداث قديمة مرارًا', 'عدم إرسال 200 أو Timeout', 'أرسل 200 فورًا وعالج في الطابور'],
    ['لا تصل أي أحداث', 'لا اشتراك في الحقول أو لا `subscribed_apps`', 'أكمل الاشتراك على المستويين'],
    ['تصل رسائل عميل في workspace آخر', 'توجيه خاطئ حسب الأصل', 'راجع خريطة WABA/Page → workspace فورًا'],
    ['توقيع صحيح لكن 403', 'Middleware يعدّل الجسم أو يقرأه مسبقًا', 'تحقق من التوقيع قبل التعديل'],
], [32, 34, 34]);

h2($section, '14.5 مشاكل Instagram وMessenger');

table($section, ['العرض', 'السبب', 'الحل'], [
    ['لا تظهر رسائل Instagram', 'الحساب ليس Professional أو غير مرتبط بالصفحة', 'اربط الحساب من إعدادات الصفحة'],
    ['زر الربط لا يعرض الصفحة', 'المستخدم ليس Admin على الصفحة', 'امنحه دور Admin على الصفحة'],
    ['Page token ينتهي', 'استخدام توكن مستخدم بدل توكن صفحة', 'ولّد Page token دائم عبر System User'],
    ['لا تصل رسائل الصفحة', 'لم يتم `subscribed_apps` للصفحة', 'نفّذ الاشتراك وأعد الاختبار'],
    ['الرد يرفض بـ 403', '`pages_messaging` غير مُراجعة', 'أكمل App Review'],
], [30, 36, 34]);

h2($section, '14.6 أخطاء خاصة بـ AYDRA');

table($section, ['العرض', 'السبب', 'الحل'], [
    ['403 "Meta credentials not configured"', 'حقول Meta فارغة في التكامل', 'املأ `app_id` و`app_secret` على الأقل'],
    ['403 على `/webhooks/whatsapp/global`', '`verify_token` فارغ أو مختلف', 'اضبطه في AYDRA وفي Meta'],
    ['"Webhook signature mismatch"', 'App Secret خاطئ أو سر مخصص خاطئ', 'صحح السر، أو اضبط `app_secret_override` للـ WABA'],
    ['قناة متصلة لكن لا رسائل', 'WABA غير مشترك أو الرقم غير مسجل', 'نفّذ `/subscribed_apps` و`/register`'],
    ['تكرار الرسائل', 'إعادة إرسال Meta دون Dedup', 'أضف قيدًا فريدًا على message id'],
    ['رسائل لا تظهر في الواجهة', 'الطابور متوقف', 'شغّل عامل الطابور وراقب `failed_jobs`'],
    ['خطأ في تشفير الحقول', 'تغيير `APP_KEY` بعد الحفظ', 'أعد إدخال الأسرار بعد استعادة المفتاح'],
], [34, 32, 34]);

h2($section, '14.7 متى تتواصل مع دعم Meta؟');

para($section, 'بعد استنفاد الأسباب أعلاه، افتح تذكرة من **Meta Developer Support** أو **Business Help Center**. التذكرة الجيدة تتضمن:');

bullets($section, [
    'App ID واسم التطبيق.',
    'WABA ID / Phone Number ID / Page ID المتأثر.',
    'الوقت الدقيق مع المنطقة الزمنية.',
    'معرّف الطلب (`fbtrace_id`) من الاستجابة إن وجد.',
    'خطوات إعادة الإنتاج، وماذا توقعت وماذا حدث.',
    'لقطة شاشة أو تسجيل قصير للمشكلة.',
    'تأكيد أن التحقق والمراجعة مكتملان.',
]);

// ═══════════════════════════════════════════════════════════════════════════
// 15. الأمان والحوكمة
// ═══════════════════════════════════════════════════════════════════════════

h1($section, '15', 'الأمان والحوكمة');

para($section, 'تطبيقك يملك وصولًا إلى محادثات عملائك. أي إهمال في الأمان ليس حادثًا تقنيًا فقط، بل خرق تعاقدي وسمعة. هذا الفصل ليس "إجراءات مثالية"، بل الحد الأدنى قبل الإنتاج.');

h2($section, '15.1 الملكية والأدوار');

table($section, ['الأصل', 'المالك', 'من يصل إليه؟'], [
    ['Meta App', 'الشركة (حساب مؤسسي)', 'Admin واحد أساسي + بديل موثق'],
    ['App Secret', 'مدير الأسرار', 'خدمة AYDRA فقط'],
    ['Business Portfolio', 'الشركة', 'Finance + Admin'],
    ['توكنات العملاء', 'AYDRA (نيابة عن العميل)', 'الخدمة المعالجة فقط، مشفرة'],
    ['سجلات المحادثات', 'AYDRA / العميل حسب العقد', 'فريق الدعم المصرح فقط'],
], [26, 34, 40]);

h2($section, '15.2 إدارة الأسرار');

bullets($section, [
    'لا تضع App Secret في كود الواجهة أو Git أو رسائل الدردشة.',
    'استخدم مدير أسرار، ومرّر القيم عبر متغيرات بيئة مشفرة.',
    'قيّد الوصول إلى قاعدة البيانات، وفعّل تشفير النسخ الاحتياطية.',
    'دوّر الأسرار عند مغادرة أي عضو، أو عند أي اشتباه، أو كل 90 يومًا.',
    'احتفظ بسجل تغييرات (من غيّر ماذا ومتى).',
    'لا تشارك لقطات شاشة تحتوي App Secret أو توكنات كاملة.',
]);

h2($section, '15.3 مبدأ الحد الأدنى');

numbered($section, [
    'كل خدمة تأخذ الصلاحيات التي تحتاجها فقط.',
    'فريق الدعم لا يرى App Secret ولا توكنات العملاء.',
    'المطوّر لا يملك صلاحية الفوترة.',
    'المالية لا تملك صلاحية تغيير إعدادات التطبيق.',
    'أي وصول إداري يحتاج 2FA، ويُراجع كل ربع سنة.',
]);

h2($section, '15.4 السجلات والمراقبة');

table($section, ['ما تسجله', 'لماذا', 'مدة الاحتفاظ'], [
    ['طلبات الـ webhook الفاشلة', 'تشخيص فقدان الرسائل', '14–30 يومًا'],
    ['أخطاء API وأكوادها', 'معرفة الأعطال المتكررة', '30 يومًا'],
    ['تغييرات الإعدادات', 'تحقيق داخلي', 'سنة'],
    ['عمليات قراءة الأسرار', 'كشف التسريب', 'سنة'],
    ['محاولات التوجيه الخاطئ بين workspaces', 'خطر عزل البيانات', 'سنة'],
], [34, 36, 30]);

h2($section, '15.5 خطة الاستجابة لحادث');

table($section, ['المرحلة', 'الإجراء'], [
    ['الاكتشاف', 'تنبيه من مراقبة الأخطاء أو بلاغ عميل أو تنبيه من Meta'],
    ['الاحتواء', 'عزل القناة/الـ workspace، إيقاف الميزة، تدوير الأسرار إن لزم'],
    ['التقييم', 'ما البيانات المتأثرة؟ أي عملاء؟ هل هناك التزام إبلاغ؟'],
    ['الإصلاح', 'معالجة السبب الجذري، لا الأعراض فقط'],
    ['الإبلاغ', 'إبلاغ العملاء والجهات حسب العقد والقانون'],
    ['المراجعة', 'تقرير ما بعد الحادث وإجراءات منع التكرار'],
], [20, 80]);

h2($section, '15.6 حماية بيانات العملاء');

bullets($section, [
    'لا تخلط بيانات العملاء في نفس جداول القناة دون مفتاح workspace واضح.',
    'أي استعلام يدوي يجب أن يقيّد بالـ workspace، وليس بالمعرّف فقط.',
    'احذف البيانات عند انتهاء العقد أو عند طلب العميل، ووثّق الحذف.',
    'لا تستخدم محادثات العملاء لتدريب نماذج دون إذن صريح.',
    'وقّع اتفاقية معالجة بيانات (DPA) مع العملاء الذين يحتاجونها.',
    'راجع مزوّدي الخدمات (استضافة، تخزين، ذكاء اصطناعي) والتزامهم بالخصوصية.',
]);

h2($section, '15.7 الامتثال لسياسات Meta');

bullets($section, [
    'احصل على موافقة (Opt-in) واضحة قبل الرسائل التسويقية.',
    'احترم إلغاء الاشتراك فورًا، ولا تعِد إضافة الرقم.',
    'لا ترسل محتوى محظورًا (ترويج غير قانوني، مخدرات، أسلحة، احتيال).',
    'لا تستخدم واتساب لإرسال رسائل حكومية حساسة دون تصريح.',
    'حافظ على نسبة شكاوى منخفضة، وراقب جودة القوالب.',
    'أي مخالفة قد تؤدي لحظر الرقم أو إيقاف التطبيق، وقد يشمل العملاء.',
]);

// ═══════════════════════════════════════════════════════════════════════════
// 16. دليل التشغيل اليومي
// ═══════════════════════════════════════════════════════════════════════════

h1($section, '16', 'دليل التشغيل اليومي');

para($section, 'هذه إجراءات جاهزة للنسخ في نظام التذاكر الداخلي. كل إجراء له "تم" واضح، حتى لا يعتمد النجاح على ذاكرة أحد.');

h2($section, '16.1 إضافة عميل واتساب (Embedded Signup)');

numbered($section, [
    'أنشئ الـ workspace وتأكد من صلاحيات المستخدم.',
    'افتح **Inbox → Setup → Connect WhatsApp**.',
    'أرسل للعميل رابط الربط واطلب منه تجهيز: Business Portfolio، رقم غير مسجل، وبطاقة دفع.',
    'تابع النافذة حتى `FINISH`، وتأكد من ظهور WABA وPhone ID.',
    'تحقق من `subscribed_apps` ومن حالة الرقم.',
    'أرسل رسالة اختبار من رقم العميل، وتأكد من وصولها في الصندوق.',
    'رد على الرسالة وتأكد من وصول الرد.',
    'وثّق تاريخ الربط، ونوع التوكن، وتاريخ انتهائه.',
], 1);

h2($section, '16.2 إضافة عميل واتساب (يدوي)');

numbered($section, [
    'اطلب WABA ID وPhone Number ID وتوكن System User دائم.',
    'أضف القناة في الـ workspace واملأ الحقول.',
    'اضبط الـ webhook على المسار الصحيح (عام أو توكن القناة).',
    'أضف الرقم للاختبار وأرسل رسالة.',
    'تحقق من الحالة والجودة، وسجّل تاريخ الانتهاء.',
]);

h2($section, '16.3 إضافة Messenger أو Instagram');

numbered($section, [
    'تأكد من أن الصفحة/الحساب يملكه العميل وأنه Admin.',
    'ولّد Page token من المصدر الصحيح (System User للاستمرارية).',
    'أضف القناة في AYDRA مع Page ID / IG Account ID.',
    'نفّذ `subscribed_apps` للصفحة أو الحساب.',
    'أرسل رسالة اختبار من حساب خارجي وردّ عليها.',
    'راقب أول 24 ساعة.',
]);

h2($section, '16.4 تدوير توكن أو سر');

table($section, ['الحالة', 'الخطوات'], [
    ['تدوير App Secret', 'لا يمكن استرجاع السر القديم؛ اضغط Reset لتوليد سر جديد، انسخه فورًا، حدّث AYDRA، أعد تشغيل الطوابير، وراقب 24 ساعة'],
    ['تدوير System User token', 'ولّد توكنًا جديدًا بنفس الصلاحيات، حدّث AYDRA، اختبر، ثم أبطِل القديم'],
    ['انتهاء توكن عميل', 'أعد الربط أو التحديث، اختبر الرسائل، وحدّث تاريخ الانتهاء'],
    ['تغيير Verify Token', 'حدّث AYDRA بالقيمة الجديدة أولًا، ثم Meta، وأعد التحقق من الـ webhook لتقليل فترة التعطل'],
], [26, 74]);

h2($section, '16.5 تعطيل عميل (Offboarding)');

numbered($section, [
    'أوقف القناة داخل AYDRA (لا تحذف البيانات فورًا).',
    'أزل اشتراك التطبيق من WABA/Page عبر Business settings أو API.',
    'أبطِل توكن العميل إن كان مخزنًا لديك.',
    'صدّر بياناته إن طلب، ثم احذفها حسب السياسة.',
    'وثّق سبب التعطيل وتاريخه.',
    'أبلغ Meta إن كان الحذف مطلوبًا لأسباب قانونية.',
]);

h2($section, '16.6 المراقبة الدورية');

table($section, ['الدورية', 'المهام'], [
    ['يوميًا', 'أخطاء الـ webhooks، الرسائل الفاشلة، حالة الطابور، أي تنبيه من Meta'],
    ['أسبوعيًا', 'جودة الأرقام، القوالب المرفوضة، معدل التسليم، توكنات تنتهي خلال 14 يومًا'],
    ['شهريًا', 'مراجعة الأدوار، النسخ الاحتياطية، حدود الاستخدام، تحديثات Meta'],
    ['ربع سنوي', 'تدوير الأسرار، مراجعة الصلاحيات، اختبار استعادة الكوارث، تحديث هذا الدليل'],
], [16, 84]);

// ═══════════════════════════════════════════════════════════════════════════
// 17. الملاحق
// ═══════════════════════════════════════════════════════════════════════════

h1($section, '17', 'الملاحق');

h2($section, 'الملحق أ: الصلاحيات ولماذا');

table($section, ['الصلاحية', 'القناة', 'الغرض', 'Advanced؟'], [
    ['`whatsapp_business_management`', 'WhatsApp', 'أرقام، قوالب، اشتراكات', 'نعم'],
    ['`whatsapp_business_messaging`', 'WhatsApp', 'إرسال واستقبال', 'نعم'],
    ['`business_management`', 'الكل', 'أصول Business', 'نعم غالبًا'],
    ['`pages_messaging`', 'Messenger', 'رسائل الصفحة', 'نعم'],
    ['`pages_show_list`', 'Messenger/IG', 'اختيار الصفحة', 'لا غالبًا'],
    ['`pages_read_engagement`', 'Messenger/IG', 'قراءة المحتوى', 'نعم'],
    ['`pages_manage_metadata`', 'Messenger', 'اشتراكات webhook', 'نعم'],
    ['`pages_manage_posts`', 'النشر', 'النشر على الصفحة', 'نعم'],
    ['`instagram_basic`', 'Instagram', 'بيانات الحساب', 'نعم'],
    ['`instagram_manage_messages`', 'Instagram', 'الرسائل المباشرة', 'نعم'],
    ['`instagram_content_publish`', 'Instagram', 'النشر', 'نعم'],
], [32, 18, 38, 12]);

h2($section, 'الملحق ب: نصوص جاهزة');

h3($section, 'ب-1 رسالة ترحيب للعميل قبل الربط');

code($section, <<<'TEXT'
مرحبًا [اسم العميل]،

لتفعيل واتساب داخل AYDRA نحتاج منك:
1) حساب أعمال على Meta (Business Portfolio) — ننشئه معك إن لم يكن لديك.
2) رقم هاتف مخصص للنشاط وغير مسجل على واتساب العادي.
3) طريقة دفع على حساب Meta (تُدفع رسوم Meta مباشرة، ولا تمر عبر AYDRA).
4) صلاحية Admin على الصفحة إن أردت ربط Messenger/Instagram.

الربط يستغرق 5–10 دقائق عبر نافذة Meta الرسمية. لن نطلب كلمة مرورك في أي وقت.
TEXT);

h3($section, 'ب-2 سياسة خصوصية (مسودة)');

code($section, <<<'TEXT'
نحن في [اسم الشركة] نقدم خدمة إدارة المحادثات عبر واتساب وMessenger وInstagram.
نجمع: اسم المستخدم، رقم الهاتف، محتوى الرسائل، وبيانات الحساب التي تمنحها لنا
عبر Meta. نستخدمها لتقديم الخدمة والدعم فقط، ولا نبيعها.

نشارك البيانات مع مزوّدي الاستضافة والتحليل الضروريين لتشغيل الخدمة، وبموجب
التزامات تعاقدية. نحتفظ بها للمدة اللازمة لتقديم الخدمة أو حسب ما يقتضيه القانون.

يمكنك طلب الوصول أو التصحيح أو الحذف عبر [بريد الخصوصية]. نستجيب خلال 30 يومًا.

لحذف بياناتك: [رابط صفحة الحذف] أو راسلنا على [بريد الخصوصية].
TEXT, 'هذه مسودة وليست استشارة قانونية. راجعها مع مستشارك وأضف بيانات شركتك الفعلية.');

h3($section, 'ب-3 تعليمات حذف البيانات');

code($section, <<<'TEXT'
لطلب حذف بياناتك:
1) أرسل بريدًا إلى [privacy@example.com] بعنوان "حذف بيانات".
2) اذكر رقم الهاتف/البريد المرتبط بالحساب واسم النشاط.
3) سنؤكد الاستلام خلال 72 ساعة، وننفذ الحذف خلال 30 يومًا.

ما نحذفه: المحادثات، جهات الاتصال، الوسائط، وبيانات القناة المرتبطة.
ما قد نحتفظ به: سجلات الفواتير لمدة يفرضها القانون، دون محتوى الرسائل.
TEXT);

h2($section, 'الملحق ج: روابط رسمية');

table($section, ['الموضوع', 'الرابط'], [
    ['التسجيل كمطوّر', 'developers.facebook.com/documentation/development/register'],
    ['إنشاء تطبيق', 'developers.facebook.com/documentation/development/create-an-app'],
    ['إعدادات التطبيق الأساسية', 'developers.facebook.com/documentation/development/create-an-app/app-dashboard/basic-settings'],
    ['بدء WhatsApp Cloud API', 'developers.facebook.com/documentation/business-messaging/whatsapp/get-started'],
    ['Embedded Signup', 'developers.facebook.com/documentation/business-messaging/whatsapp/embedded-signup/overview'],
    ['تنفيذ Embedded Signup', 'developers.facebook.com/documentation/business-messaging/whatsapp/embedded-signup/implementation'],
    ['أخطاء Embedded Signup', 'developers.facebook.com/documentation/business-messaging/whatsapp/embedded-signup/errors'],
    ['إصدارات Embedded Signup', 'developers.facebook.com/documentation/business-messaging/whatsapp/embedded-signup/versions'],
    ['Webhooks', 'developers.facebook.com/documentation/business-messaging/whatsapp/webhooks/overview'],
    ['إنشاء Webhook endpoint', 'developers.facebook.com/documentation/business-messaging/whatsapp/webhooks/create-webhook-endpoint'],
    ['Access Tokens', 'developers.facebook.com/documentation/business-messaging/whatsapp/access-tokens'],
    ['Tech Provider', 'developers.facebook.com/documentation/business-messaging/whatsapp/solution-providers/get-started-for-tech-providers'],
    ['App Review للواتساب', 'developers.facebook.com/documentation/business-messaging/whatsapp/solution-providers/app-review'],
    ['Business Verification', 'developers.facebook.com/documentation/development/release/business-verification'],
    ['حدود الرسائل', 'developers.facebook.com/documentation/business-messaging/whatsapp/messaging-limits'],
    ['صلاحيات واتساب', 'developers.facebook.com/documentation/business-messaging/whatsapp/permissions'],
    ['أكواد أخطاء واتساب', 'developers.facebook.com/documentation/business-messaging/whatsapp/support/error-codes'],
    ['Facebook Login for Business', 'developers.facebook.com/documentation/facebook-login/facebook-login-for-business'],
    ['Messenger: إنشاء تطبيق', 'developers.facebook.com/documentation/business-messaging/messenger-platform/create-an-app'],
    ['Messenger: Webhooks', 'developers.facebook.com/documentation/business-messaging/messenger-platform/webhooks'],
    ['Instagram: إنشاء تطبيق', 'developers.facebook.com/documentation/instagram-platform/create-an-instagram-app'],
], [30, 70]);

h2($section, 'الملحق د: أسئلة شائعة');

table($section, ['السؤال', 'الجواب'], [
    ['هل أحتاج تطبيقًا لكل عميل؟', 'لا. تطبيق واحد يكفي، مع أصول منفصلة لكل عميل.'],
    ['هل يمكن أن يستخدم عميلان نفس الرقم؟', 'لا. كل رقم مرتبط بحساب واحد.'],
    ['هل الفوترة موحدة؟', 'لا في نموذج Tech Provider؛ كل عميل يدفع لـ Meta.'],
    ['هل يمكن الاختبار قبل المراجعة؟', 'نعم، مع أدوار التطبيق فقط (Standard Access).'],
    ['ماذا يحدث إن انتهى توكن العميل؟', 'تتوقف قناته حتى إعادة الربط/التحديث؛ جهّز تنبيهًا مسبقًا.'],
    ['هل يمكن نقل WABA لتطبيق آخر؟', 'نعم عبر إدارة الأصول، لكنه يقطع الاشتراكات الحالية؛ خطط له.'],
    ['هل أحتاج موقعًا إلكترونيًا؟', 'نعم لروابط السياسات والنشر، ولتعزيز قبول التحقق.'],
    ['ما الفرق بين WABA ID وPhone Number ID؟', 'WABA يحمل القوالب والفوترة، وPhone ID يمثل الرقم في الإرسال.'],
    ['هل يمكن ربط رقم موجود على واتساب عادي؟', 'نعم بعد إلغاء تسجيله، أو عبر Coexistence إن كان تطبيق أعمال.'],
    ['هل حدود الرسائل مشتركة بين العملاء؟', 'لا، لكل Business Portfolio حدّه.'],
    ['كيف أضيف عميلًا لا يريد Embedded Signup؟', 'ربط يدوي بـ WABA ID وتوكن دائم.'],
    ['هل حذف التطبيق قابل للتراجع؟', 'لا. احذف بحذر، وأوقف القنوات أولًا.'],
    ['هل يمكن تشغيل أكثر من رقم في WABA واحد؟', 'نعم، حسب حدود الحساب والجودة.'],
    ['هل App Review مطلوبة لكل صلاحية؟', 'نعم للـ Advanced Access لكل صلاحية على حدة.'],
    ['هل التطبيق الواحد يخلط بيانات العملاء؟', 'لا، العزل داخل AYDRA على مستوى workspace ومسؤولية الكود.'],
], [34, 66]);

h2($section, 'الملحق هـ: قائمة التحقق النهائية');

table($section, ['✓', 'البند'], [
    ['☐', 'تطبيق Meta واحد باسم AYDRA في وضع Live'],
    ['☐', 'Business Verification مكتمل'],
    ['☐', 'App Review معتمد للصلاحيات الأساسية'],
    ['☐', 'App ID وApp Secret في AYDRA ويعملان'],
    ['☐', 'Verify Token مطابق في AYDRA وMeta'],
    ['☐', 'Callback URL يعيد 200 ويرفض التوقيع الخاطئ'],
    ['☐', 'System User token دائم ومُدار'],
    ['☐', 'config_id_whatsapp وconfig_id_social مضبوطان'],
    ['☐', 'كل عميل مشترك عبر subscribed_apps'],
    ['☐', 'اختبار ربط خارجي ناجح (بلا أدوار)'],
    ['☐', 'مراقبة وتنبيهات مفعّلة'],
    ['☐', 'خطة تراجع وتدوير موثقة'],
    ['☐', 'نسخة احتياطية من الإعدادات (بدون أسرار)'],
], [8, 92]);

h2($section, 'الملحق و: سجل التغييرات');

table($section, ['الإصدار', 'التاريخ', 'التغيير'], [
    ['1.0', 'نوفمبر 2026', 'الإصدار الأول: تطبيق موحّد، ES v4، Webhooks، App Review، التشغيل'],
], [14, 26, 60]);

// ─── Save ───────────────────────────────────────────────────────────────────

$output = $argv[1] ?? __DIR__.'/../../AYDRA_Meta_App_Guide.docx';
$directory = dirname($output);

if (! is_dir($directory)) {
    mkdir($directory, 0777, true);
}

$writer = IOFactory::createWriter($phpWord, 'Word2007');
$writer->save($output);

echo 'Generated: '.realpath($output).PHP_EOL;
echo 'Size: '.number_format(filesize($output) / 1024, 1).' KB'.PHP_EOL;
