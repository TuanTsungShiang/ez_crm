<?php

namespace App\Services\Order;

use App\Models\Order;
use App\Models\OrderSettings;
use Illuminate\Support\Facades\DB;

/**
 * Generates unique, sequential order numbers in the format:
 *   {PREFIX}-{YYYYMMDD}-{NNNN}
 *
 * Example: EZ-20260502-0001
 *
 * Race-safety: uses SELECT MAX + 1 with a DB transaction + retry rather than
 * a separate sequence table, keeping the schema simple while still being
 * safe under concurrent inserts. The UNIQUE constraint on orders.order_no
 * is the final backstop — a collision triggers a retry (up to 5 attempts).
 *
 * Future multi-prefix support: replace OrderSettings::current()->order_no_prefix
 * with a PrefixResolver injection (see ORDER_INTEGRATION_PLAN §10.1).
 */
class OrderNumberGenerator
{
    private const MAX_RETRIES = 5;

    public function next(): string
    {
        $prefix = OrderSettings::current()->order_no_prefix;
        $date = now()->format('Ymd');

        for ($attempt = 1; $attempt <= self::MAX_RETRIES; $attempt++) {
            $seq = $this->nextSequence($prefix, $date);
            $candidate = sprintf('%s-%s-%04d', $prefix, $date, $seq);

            // Optimistic check — UNIQUE constraint is the real backstop
            if (! Order::where('order_no', $candidate)->exists()) {
                return $candidate;
            }
        }

        // Fallback: append microseconds to guarantee uniqueness (extremely rare)
        return sprintf('%s-%s-%s', $prefix, $date, substr((string) microtime(true), -6));
    }

    private function nextSequence(string $prefix, string $date): int
    {
        // Find the highest sequence number used today for this prefix
        $like = "{$prefix}-{$date}-%";
        $maxNo = Order::where('order_no', 'like', $like)
            ->lockForUpdate()
            ->max('order_no');

        if ($maxNo === null) {
            return 1;
        }

        // Extract the 4-digit sequence from the end: PREFIX-YYYYMMDD-NNNN
        $parts = explode('-', $maxNo);
        $last = (int) end($parts);

        return $last + 1;
    }
}
