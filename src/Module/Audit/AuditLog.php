<?php
declare(strict_types=1);

namespace Mea\Module\Audit;

use Mea\Core\Crypto;
use Mea\Core\Database;

/**
 * Änderungsprotokoll. Geheimnisse werden vor dem Speichern entfernt:
 * Passwörter, TOTP- und Backup-Codes, Tokens erscheinen nie im Protokoll.
 */
final class AuditLog
{
    private const SECRET_KEYS = '/pass|token|secret|totp|code|_csrf|otp|key/i';

    private ?int $userId = null;
    private ?string $ipHash = null;

    public function __construct(private Database $db, private Crypto $crypto) {}

    public function setActor(?int $userId, ?string $ip): void
    {
        $this->userId = $userId;
        $this->ipHash = $ip !== null ? $this->crypto->hash($ip, 'ip') : null;
    }

    /** @param array<string,mixed> $details */
    public function record(string $action, string $summary, ?string $entityType = null, ?int $entityId = null, array $details = [], ?int $userId = null): void
    {
        $this->db->insert('audit_log', [
            'user_id' => $userId ?? $this->userId,
            'action' => mb_substr($action, 0, 80),
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'summary' => mb_substr($summary, 0, 255),
            'details_json' => $details === [] ? null : json_encode(self::scrub($details), JSON_UNESCAPED_UNICODE),
            'ip_hash' => $this->ipHash,
        ]);
    }

    /**
     * Unterschiede zwischen altem und neuem Datensatz (nur geänderte Felder, gekürzt).
     * @param array<string,mixed> $before @param array<string,mixed> $after @return array<string,array{0:mixed,1:mixed}>
     */
    public static function diff(array $before, array $after): array
    {
        $out = [];
        foreach ($after as $k => $v) {
            if (in_array($k, ['updated_at', 'created_at'], true)) {
                continue;
            }
            $old = $before[$k] ?? null;
            if ((string) json_encode($old) !== (string) json_encode($v) && (string) $old !== (string) $v) {
                $out[$k] = [self::short($old), self::short($v)];
            }
        }
        return $out;
    }

    private static function short(mixed $v): mixed
    {
        return is_string($v) && mb_strlen($v) > 200 ? mb_substr($v, 0, 200) . '…' : $v;
    }

    /** @param array<mixed> $data @return array<mixed> */
    private static function scrub(array $data): array
    {
        foreach ($data as $k => $v) {
            if (is_string($k) && preg_match(self::SECRET_KEYS, $k) && !in_array($k, ['postal_code', 'template_key', 'field_key', 'currency_code'], true)) {
                $data[$k] = '[entfernt]';
            } elseif (is_array($v)) {
                $data[$k] = self::scrub($v);
            }
        }
        return $data;
    }
}
