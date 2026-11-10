<?php

use App\Models\Locale;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;

/**
 * Set the UI language for a demo user before capturing documentation screenshots.
 *
 * Usage: php doc/scripts/set-locale.php business@aydra.demo ar
 */
$root = dirname(__DIR__, 2);

require $root.'/vendor/autoload.php';

$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$email = $argv[1] ?? 'business@aydra.demo';
$locale = $argv[2] ?? 'en';

$user = User::where('email', $email)->first();
if (! $user) {
    fwrite(STDERR, "no user {$email}\n");
    exit(1);
}

$user->update(['locale' => $locale]);
echo "set {$email} locale={$locale}\n";

echo 'locales: '.json_encode(
    Locale::query()->get(['code', 'name', 'enabled', 'is_rtl'])->toArray()
)."\n";
