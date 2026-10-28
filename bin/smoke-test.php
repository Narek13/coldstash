<?php
/**
 * Standalone smoke test — no WordPress, no database required.
 *
 * Run:  php bin/smoke-test.php
 *
 * Exercises the two pieces that must never be wrong and happen to be pure PHP:
 * the eligibility rules (which orders may be archived) and the payload
 * checksum (which is what makes "verify before delete" mean anything).
 * Exits non-zero on any mismatch.
 *
 * @package Coldstash
 */

declare(strict_types=1);

if (! function_exists('__')) {
    function __(string $text, string $domain = 'default'): string
    {
        return $text;
    }
}

$GLOBALS['smoke_options'] = [];
if (! function_exists('get_option')) {
    function get_option(string $name, mixed $default = false): mixed
    {
        return $GLOBALS['smoke_options'][$name] ?? $default;
    }
}

spl_autoload_register(static function (string $class): void {
    $prefix = 'Coldstash\\';
    if (! str_starts_with($class, $prefix)) {
        return;
    }
    $path = __DIR__ . '/../src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_readable($path)) {
        require $path;
    }
});

use Coldstash\Archive\Eligibility;
use Coldstash\Archive\ExclusionReason;
use Coldstash\Archive\Payload;
use Coldstash\Settings\Settings;

$failures = [];

function check(string $label, mixed $expected, mixed $actual): void
{
    global $failures;

    $ok = $expected === $actual;
    printf("[%s] %s\n", $ok ? ' OK ' : 'FAIL', $label);

    if (! $ok) {
        $failures[] = sprintf(
            '%s — expected %s, got %s',
            $label,
            var_export($expected, true),
            var_export($actual, true)
        );
    }
}

echo "Coldstash — smoke test\n";
echo str_repeat('-', 56) . "\n";

$now = strtotime('2026-09-16 12:00:00');
$settings = new Settings(ageMonths: 24, statuses: ['wc-completed', 'wc-cancelled']);
$eligibility = new Eligibility($settings);

$old = ['status' => 'wc-completed', 'date_created_gmt' => '2020-01-01 00:00:00', 'parent_order_id' => 0];

check('old completed order is eligible', null, $eligibility->check($old, [], $now));

check(
    'processing order is held back',
    ExclusionReason::NotTerminalStatus,
    $eligibility->check(['status' => 'wc-processing', 'date_created_gmt' => '2020-01-01 00:00:00'], [], $now)
);

check(
    'recent order is held back',
    ExclusionReason::TooRecent,
    $eligibility->check(['status' => 'wc-completed', 'date_created_gmt' => '2026-06-01 00:00:00'], [], $now)
);

check(
    'active downloads hold an order back',
    ExclusionReason::ActiveDownloads,
    $eligibility->check($old, ['has_active_downloads' => true], $now)
);

check(
    'subscription-linked order is held back',
    ExclusionReason::SubscriptionRelated,
    $eligibility->check($old, ['is_subscription_related' => true], $now)
);

check(
    'refund without an eligible parent is held back',
    ExclusionReason::ParentNotEligible,
    $eligibility->check(
        ['status' => 'wc-completed', 'date_created_gmt' => '2020-01-01 00:00:00', 'parent_order_id' => 99],
        ['parent_eligible' => false],
        $now
    )
);

check(
    'already-archived order is held back',
    ExclusionReason::AlreadyArchived,
    $eligibility->check($old, ['already_archived' => true], $now)
);

check(
    'restored order is held back',
    ExclusionReason::Restored,
    $eligibility->check($old, ['restored' => true], $now)
);

$GLOBALS['smoke_options']['coldstash_settings'] = [
    'age_months' => 1,
    'statuses' => ['wc-completed', 'wc-processing'],
    'batch_size' => 99999,
];
$clamped = Settings::fromOptions();

check('age threshold is clamped up to the minimum', Settings::MINIMUM_AGE_MONTHS, $clamped->ageMonths);
check('a non-terminal status is stripped from settings', ['wc-completed'], $clamped->statuses);
check('batch size is capped', 500, $clamped->batchSize);

$GLOBALS['smoke_options'] = [];

check(
    'processing can never be an archivable status',
    false,
    in_array('wc-processing', Settings::ARCHIVABLE_STATUSES, true)
);

$data = [
    'order' => [['id' => 1234, 'status' => 'wc-completed', 'total_amount' => '99.50000000']],
    'items' => [['order_item_id' => 9, 'order_item_name' => 'Espresso — 1kg', 'order_item_type' => 'line_item']],
    'meta' => [['id' => 1, 'order_id' => 1234, 'meta_key' => '_note', 'meta_value' => 'ünïcode ✔ / slashes']],
];

$payload = Payload::fromArray($data);
$roundTripped = Payload::fromJson($payload->json);

check('payload round-trips through JSON', $data, $roundTripped->data);
check('checksum is stable across a round-trip', $payload->checksum(), $roundTripped->checksum());
check('checksum matches itself', true, $payload->matches($payload->checksum()));
check('a tampered checksum is rejected', false, $payload->matches(str_repeat('0', 64)));
check('rows() returns a list', 1, count($payload->rows('items')));
check('row() returns the first row', 1234, $payload->row('order')['id'] ?? null);

$tampered = str_replace('99.50000000', '11.00000000', $payload->json);
check(
    'a tampered payload fails its original checksum',
    false,
    hash('sha256', $tampered) === $payload->checksum()
);

$rejected = false;
try {
    Payload::fromJson('{"schema":999,"data":{}}');
} catch (Throwable $e) {
    $rejected = true;
}
check('a future payload schema is refused', true, $rejected);

echo str_repeat('-', 56) . "\n";

if ($failures !== []) {
    fwrite(STDERR, sprintf("FAIL: %d check(s) failed.\n", count($failures)));
    foreach ($failures as $failure) {
        fwrite(STDERR, "  - {$failure}\n");
    }
    exit(1);
}

echo "PASS ✔\n";
exit(0);
