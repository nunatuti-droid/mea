<?php
declare(strict_types=1);

namespace Mea\Admin;

use Mea\Core\App;
use Mea\Core\Request;
use Mea\Core\Response;

final class DashboardController
{
    public function __construct(private App $app) {}

    public function index(Request $req, AdminContext $ctx): Response
    {
        $db = $this->app->db;
        $data = [
            'title' => 'Übersicht',
            'upcoming' => $ctx->can('sessions') ? $db->all(
                "SELECT s.id, s.starts_at, s.capacity, s.status, e.public_name, l.short_name AS location,
                    (SELECT COUNT(*) FROM bookings b WHERE b.exam_session_id = s.id AND (b.status = 'confirmed'
                       OR (b.status IN ('reserved','pending_payment') AND b.reserved_until > UTC_TIMESTAMP()))) AS taken
                 FROM exam_sessions s JOIN exam_types e ON e.id = s.exam_type_id JOIN locations l ON l.id = s.location_id
                 WHERE s.starts_at > UTC_TIMESTAMP() AND s.status <> 'cancelled' ORDER BY s.starts_at LIMIT 8",
            ) : [],
            'new_bookings' => $ctx->can('bookings') ? $db->all(
                "SELECT b.id, b.public_ref, b.status, b.payment_status, b.total_cents, b.currency, b.created_at,
                    p.first_name, p.last_name, JSON_UNQUOTE(JSON_EXTRACT(b.snapshot_json, '$.exam.public_name')) AS exam_name
                 FROM bookings b LEFT JOIN booking_participants p ON p.booking_id = b.id
                 WHERE b.created_at > UTC_TIMESTAMP() - INTERVAL 14 DAY AND b.status IN ('confirmed','pending_payment','reserved')
                 ORDER BY b.created_at DESC LIMIT 8",
            ) : [],
            'stats' => [
                'bookings_week' => $ctx->can('bookings') ? (int) $db->value("SELECT COUNT(*) FROM bookings WHERE status = 'confirmed' AND confirmed_at > UTC_TIMESTAMP() - INTERVAL 7 DAY") : null,
                'pending_payments' => $ctx->can('bookings') ? (int) $db->value("SELECT COUNT(*) FROM bookings WHERE status = 'pending_payment' AND reserved_until > UTC_TIMESTAMP()") : null,
                'open_inquiries' => $ctx->can('inquiries') ? (int) $db->value("SELECT COUNT(*) FROM inquiries WHERE status = 'new'") : null,
                'cancelled_sessions' => $ctx->can('sessions') ? (int) $db->value("SELECT COUNT(*) FROM exam_sessions WHERE status = 'cancelled' AND starts_at > UTC_TIMESTAMP()") : null,
            ],
            'cancelled' => $ctx->can('sessions') ? $db->all(
                "SELECT s.id, s.starts_at, e.public_name FROM exam_sessions s JOIN exam_types e ON e.id = s.exam_type_id
                 WHERE s.status = 'cancelled' AND s.starts_at > UTC_TIMESTAMP() ORDER BY s.starts_at LIMIT 5",
            ) : [],
            'audit' => $ctx->can('audit') ? $db->all(
                'SELECT a.created_at, a.summary, u.name FROM audit_log a LEFT JOIN users u ON u.id = a.user_id
                 WHERE a.action NOT LIKE \'auth.%\' ORDER BY a.id DESC LIMIT 8',
            ) : [],
            'warnings' => $ctx->can('system') ? (new SystemCheck($this->app))->warnings() : [],
        ];
        return Response::html($this->app->view->render('admin/dashboard.twig', $data));
    }
}
