@php
    use App\Models\SystemSetting;
    use App\Support\Brand;

    // Resolve branding with graceful fallback (mirrors HandleInertiaRequests::brandingShare()).
    try {
        $appName  = Brand::name();
        $primary  = SystemSetting::get('primary_color') ?: config('saas.branding.primary_color', '#F9A61A');
        $logoPath = SystemSetting::get('app_logo_path');
        $logoUrl  = $logoPath
            ? \Illuminate\Support\Facades\Storage::disk(SystemSetting::get('app_logo_disk', 'public'))->url($logoPath)
            : null;
        $faviconPath = SystemSetting::get('app_favicon_path');
        $faviconUrl  = $faviconPath
            ? \Illuminate\Support\Facades\Storage::disk(SystemSetting::get('app_favicon_disk', 'public'))->url($faviconPath)
            : null;
    } catch (\Throwable) {
        $appName  = Brand::name();
        $primary  = config('saas.branding.primary_color', '#F9A61A');
        $logoUrl  = null;
        $faviconUrl = null;
    }

    // Pick readable ink on the primary colour with the same helper the main app
    // palette uses (a light Golden Yellow needs dark text, not white).
    $onPrimary = \App\Support\BrandPalette::isValidHex($primary)
        ? \App\Support\BrandPalette::onBrand($primary)
        : '#ffffff';
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $code }} · {{ $title }} – {{ $appName }}</title>
    @if($faviconUrl)
        <link rel="icon" href="{{ $faviconUrl }}">
        <link rel="apple-touch-icon" href="{{ $faviconUrl }}">
    @else
        <link rel="icon" type="image/svg+xml" href="/aydra-icon.svg">
        <link rel="alternate icon" href="/favicon.ico" sizes="any">
        <link rel="apple-touch-icon" href="/apple-touch-icon.png">
    @endif
    <style>
        /* BDO Grotesk is self-hosted (public/fonts) so this standalone error page
           matches the app without a third-party font request. */
        @font-face {
            font-family: 'BDO Grotesk';
            font-style: normal;
            font-weight: 400;
            font-display: swap;
            src: url('/fonts/bdo-grotesk/BDOGrotesk-Regular.woff2') format('woff2');
        }
        @font-face {
            font-family: 'BDO Grotesk';
            font-style: normal;
            font-weight: 600;
            font-display: swap;
            src: url('/fonts/bdo-grotesk/BDOGrotesk-DemiBold.woff2') format('woff2');
        }
        @font-face {
            font-family: 'BDO Grotesk';
            font-style: normal;
            font-weight: 700;
            font-display: swap;
            src: url('/fonts/bdo-grotesk/BDOGrotesk-Bold.woff2') format('woff2');
        }
        :root {
            --primary: {{ $primary }};
            --on-primary: {{ $onPrimary }};
            --ink: #241a0f;       /* warm near-black */
            --ink-soft: #6b5b48;  /* muted warm brown */
            --surface: #fffbf4;   /* brand surface */
        }
        *, *::before, *::after { box-sizing: border-box; }
        html, body { height: 100%; margin: 0; font-family: 'BDO Grotesk', ui-sans-serif, system-ui, sans-serif; }
        /* BDO Grotesk is Latin-only; Arabic keeps the system Arabic stack. */
        html[lang="ar"] body { font-family: 'Segoe UI', Tahoma, 'Noto Sans Arabic', Arial, sans-serif; }
        body {
            display: flex; flex-direction: column; align-items: center; justify-content: center;
            color: var(--ink); padding: 2rem; position: relative; overflow: hidden;
            background:
                radial-gradient(60rem 40rem at 50% -10%, color-mix(in srgb, var(--primary) 22%, transparent), transparent 70%),
                linear-gradient(180deg, var(--surface), #ffffff 60%);
        }
        /* Soft decorative glow blobs in the brand color */
        body::before, body::after {
            content: ''; position: fixed; border-radius: 50%; filter: blur(80px);
            background: var(--primary); opacity: .18; z-index: 0;
        }
        body::before { width: 30rem; height: 30rem; top: -10rem; left: -8rem; }
        body::after  { width: 26rem; height: 26rem; bottom: -10rem; right: -8rem; opacity: .12; }

        .brand {
            position: fixed; top: 1.5rem; left: 1.75rem; z-index: 2;
            display: inline-flex; align-items: center; gap: .6rem;
            font-weight: 700; font-size: 1.15rem; color: var(--ink); text-decoration: none; letter-spacing: -.01em;
        }
        .brand img { height: 2rem; max-width: 170px; object-fit: contain; display: block; }
        .brand .dot {
            width: 1.85rem; height: 1.85rem; border-radius: .55rem; display: inline-flex;
            align-items: center; justify-content: center; background: var(--primary); color: var(--on-primary);
            font-weight: 700; font-size: 1rem;
        }

        .card {
            position: relative; z-index: 1; background: rgba(255, 255, 255, .85);
            backdrop-filter: blur(8px); -webkit-backdrop-filter: blur(8px);
            border: 1px solid rgba(36, 26, 15, .10); border-radius: 1.25rem;
            padding: 3.25rem 2.75rem; max-width: 460px; width: 100%; text-align: center;
            box-shadow: 0 24px 60px -24px rgba(36, 26, 15, .25), 0 2px 8px rgba(36, 26, 15, .04);
        }
        .code {
            font-size: clamp(5rem, 18vw, 7.5rem); font-weight: 700; line-height: .9;
            letter-spacing: -.04em; margin-bottom: 1rem; color: var(--ink);
            background: linear-gradient(135deg, var(--ink) 35%, var(--primary));
            -webkit-background-clip: text; background-clip: text; -webkit-text-fill-color: transparent;
        }
        h1 { font-size: 1.5rem; font-weight: 600; margin: 0 0 .65rem; letter-spacing: -.01em; }
        p { color: var(--ink-soft); margin: 0 auto 2.25rem; line-height: 1.65; max-width: 32ch; }

        .actions { display: flex; gap: .75rem; justify-content: center; flex-wrap: wrap; }
        .btn {
            display: inline-flex; align-items: center; gap: .5rem; padding: .7rem 1.6rem;
            border-radius: .7rem; text-decoration: none; font-weight: 600; font-size: .95rem;
            transition: transform .15s ease, box-shadow .15s ease, background .15s ease; cursor: pointer; border: 0;
        }
        .btn-primary {
            background: var(--primary); color: var(--on-primary);
            box-shadow: 0 10px 24px -10px color-mix(in srgb, var(--primary) 75%, transparent);
        }
        .btn-primary:hover { transform: translateY(-1px); box-shadow: 0 14px 28px -10px color-mix(in srgb, var(--primary) 80%, transparent); }
        .btn-ghost { background: transparent; color: var(--ink); border: 1px solid rgba(36, 26, 15, .14); }
        .btn-ghost:hover { background: rgba(36, 26, 15, .04); transform: translateY(-1px); }

        @media (prefers-color-scheme: dark) {
            :root { --ink: #f4eee6; --ink-soft: #b6a48f; --surface: #14100b; }
            body { background:
                radial-gradient(60rem 40rem at 50% -10%, color-mix(in srgb, var(--primary) 18%, transparent), transparent 70%),
                linear-gradient(180deg, #14100b, #0a0705 60%); }
            .brand { color: var(--ink); }
            .card { background: rgba(20, 16, 11, .7); border-color: rgba(255, 255, 255, .08); box-shadow: 0 24px 60px -24px rgba(0, 0, 0, .6); }
            .code { background: linear-gradient(135deg, #eef2f6 35%, var(--primary)); -webkit-background-clip: text; background-clip: text; }
            .btn-ghost { color: var(--ink); border-color: rgba(255, 255, 255, .16); }
            .btn-ghost:hover { background: rgba(255, 255, 255, .06); }
        }
        @media (prefers-reduced-motion: reduce) {
            .btn { transition: none; }
            .btn:hover { transform: none; }
        }
    </style>
</head>
<body>
    <a href="{{ url('/') }}" class="brand">
        @if ($logoUrl)
            <img src="{{ $logoUrl }}" alt="{{ $appName }}">
        @else
            <span class="dot">{{ strtoupper(substr($appName, 0, 1)) }}</span>
            <span>{{ $appName }}</span>
        @endif
    </a>

    <main class="card">
        <div class="code">{{ $code }}</div>
        <h1>{{ $title }}</h1>
        <p>{{ $message }}</p>
        <div class="actions">
            <a href="{{ url('/') }}" class="btn btn-primary">Go Home</a>
            <a href="javascript:history.back()" class="btn btn-ghost">Go Back</a>
        </div>
    </main>
</body>
</html>
