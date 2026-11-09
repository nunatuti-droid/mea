<?php
declare(strict_types=1);

namespace Mea\Module\Consent;

use Mea\Core\Database;
use Mea\Core\Request;
use Mea\Core\Response;
use Mea\Module\Inquiries\RateLimiter;

/** Speichert jede Cookie-Auswahl als Nachweis (Art. 7 Abs. 1 DSGVO), ohne IP-Adresse. */
final class ConsentController
{
    public function __construct(private Database $db, private ConsentConfig $config, private RateLimiter $limiter) {}

    public function record(Request $req): Response
    {
        // Nur von der eigenen Website (Browser senden Origin bzw. Sec-Fetch-Site)
        $site = $req->header('Sec-Fetch-Site');
        if ($site !== '' && $site !== 'same-origin') {
            return Response::json(['ok' => false], 403);
        }
        if (!$this->limiter->hit('consent', $req->ip(), 30, 3600)) {
            return Response::json(['ok' => false], 429);
        }
        $id = $req->input('id');
        $action = $req->input('action');
        if (!preg_match('/^[a-f0-9]{32}$/', $id) || !in_array($action, ['accept_all', 'reject_all', 'custom', 'withdraw'], true)) {
            return Response::json(['ok' => false], 422);
        }
        $allowed = array_column($this->config->categories(), 'key');
        $cats = array_values(array_intersect($allowed, array_map('strval', (array) ($req->post['categories'] ?? []))));
        if (!in_array('necessary', $cats, true)) {
            array_unshift($cats, 'necessary');
        }
        $this->db->insert('consent_log', [
            'consent_id' => $id, 'version' => $this->config->version(), 'categories_json' => json_encode($cats),
            'action' => $action, 'user_agent' => mb_substr($req->userAgent(), 0, 120) ?: null,
        ]);
        return Response::json(['ok' => true]);
    }
}
