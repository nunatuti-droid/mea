<?php
declare(strict_types=1);

namespace Mea\Module\Inquiries;

use Mea\Core\Database;

/** Festes Zeitfenster je Bucket; die IP wird nur als gesalzener Hash gespeichert. */
final class RateLimiter
{
    public function __construct(private Database $db, private string $salt) {}

    public function hit(string $bucket, string $subject, int $max, int $windowSeconds): bool
    {
        $hash = hash_hmac('sha256', $subject, $this->salt);
        return $this->db->transaction(function (Database $db) use ($bucket, $hash, $max, $windowSeconds): bool {
            $row = $db->one('SELECT hits, window_start FROM rate_limits WHERE bucket = ? AND subject_hash = ? FOR UPDATE', [$bucket, $hash]);
            $now = time();
            if ($row === null || strtotime($row['window_start'] . ' UTC') + $windowSeconds < $now) {
                $db->run(
                    'REPLACE INTO rate_limits (bucket, subject_hash, hits, window_start) VALUES (?, ?, 1, UTC_TIMESTAMP())',
                    [$bucket, $hash],
                );
                return true;
            }
            if ((int) $row['hits'] >= $max) {
                return false;
            }
            $db->run('UPDATE rate_limits SET hits = hits + 1 WHERE bucket = ? AND subject_hash = ?', [$bucket, $hash]);
            return true;
        });
    }
}
