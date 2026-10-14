<?php
// app/helpers/SupportTickets.php
// Help-desk tickets: shop owners and staff raise them from the POS (Help →
// Support tickets); the Support console lists, answers and resolves them.
// One conversation per ticket (support_ticket_messages). unread_by_user /
// unread_by_support drive the "new reply" badges on each side.

class SupportTickets
{
    const CATEGORIES = [
        'general'   => ['General question', 'fa-circle-question'],
        'bug'       => ['Something is not working', 'fa-bug'],
        'sales'     => ['Sales, credit & payments', 'fa-cash-register'],
        'stock'     => ['Stock & inventory', 'fa-boxes-stacked'],
        'account'   => ['Login, staff & access', 'fa-user-lock'],
        'billing'   => ['Packages & activation', 'fa-key'],
        'feature'   => ['Feature request', 'fa-lightbulb'],
    ];
    const PRIORITIES = ['low' => 'Low', 'normal' => 'Normal', 'high' => 'High', 'urgent' => 'Urgent'];
    /** status => [label, colour class] */
    const STATUSES = [
        'open'        => ['Open', 'st-open'],
        'in_progress' => ['In progress', 'st-prog'],
        'waiting'     => ['Waiting for you', 'st-wait'],
        'resolved'    => ['Resolved', 'st-done'],
        'closed'      => ['Closed', 'st-closed'],
    ];

    public static function ref(int $id): string
    {
        return 'TK-' . str_pad((string) $id, 5, '0', STR_PAD_LEFT);
    }

    /** Raise a ticket as the signed-in POS user. Returns the new id. */
    public static function create(string $subject, string $category, string $priority, string $body, ?string $pageUrl): int
    {
        $pdo = Database::pdo();
        $tenantId = (int) TenantContext::tenantId();
        $pdo->beginTransaction();
        try {
            $pdo->prepare(
                'INSERT INTO support_tickets (account_id, tenant_id, user_id, user_name, user_role, subject, category, priority, page_url, unread_by_support)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1)'
            )->execute([
                Features::accountId($tenantId), $tenantId, TenantContext::userId(), $_SESSION['username'] ?? null,
                TenantContext::role(), $subject, isset(self::CATEGORIES[$category]) ? $category : 'general',
                isset(self::PRIORITIES[$priority]) ? $priority : 'normal', $pageUrl ? mb_substr($pageUrl, 0, 255) : null,
            ]);
            $id = (int) $pdo->lastInsertId();
            self::addMessage($id, 'user', $body);
            $pdo->commit();
            return $id;
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /** Add to the conversation and flag the other side as having something new. */
    public static function addMessage(int $ticketId, string $author, string $body): void
    {
        $pdo = Database::pdo();
        $name = $author === 'support' ? 'Support' : ($_SESSION['username'] ?? 'User');
        $pdo->prepare('INSERT INTO support_ticket_messages (ticket_id, author, user_id, author_name, body) VALUES (?, ?, ?, ?, ?)')
            ->execute([$ticketId, $author, $author === 'user' ? TenantContext::userId() : null, $name, $body]);
        if ($author === 'support') {
            $pdo->prepare('UPDATE support_tickets SET unread_by_user = 1, updated_at = NOW() WHERE id = ?')->execute([$ticketId]);
        } else {
            // A user reply re-opens a ticket that was waiting on them or resolved.
            $pdo->prepare(
                "UPDATE support_tickets SET unread_by_support = 1, updated_at = NOW(),
                        status = IF(status IN ('waiting','resolved'), 'open', status) WHERE id = ?"
            )->execute([$ticketId]);
        }
    }

    /** A ticket the current POS user's account owns, or null. */
    public static function forAccount(int $ticketId): ?array
    {
        $stmt = Database::pdo()->prepare('SELECT * FROM support_tickets WHERE id = ? AND account_id = ?');
        $stmt->execute([$ticketId, Features::accountId((int) TenantContext::tenantId())]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public static function messages(int $ticketId): array
    {
        $stmt = Database::pdo()->prepare('SELECT * FROM support_ticket_messages WHERE ticket_id = ? ORDER BY created_at, id');
        $stmt->execute([$ticketId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function statusBadge(string $status): string
    {
        [$label, $cls] = self::STATUSES[$status] ?? [ucfirst($status), 'st-closed'];
        return '<span class="tk-st ' . $cls . '">' . htmlspecialchars($label) . '</span>';
    }

    /** Shared badge colours for both sides. */
    public static function css(): string
    {
        return '<style>.tk-st{display:inline-block;padding:2px 10px;border-radius:999px;font-size:.72rem;font-weight:700;white-space:nowrap;}'
            . '.st-open{background:#dbeafe;color:#1e40af}.st-prog{background:#ede9fe;color:#5b21b6}.st-wait{background:#fef3c7;color:#92400e}'
            . '.st-done{background:#dcfce7;color:#166534}.st-closed{background:#f1f5f9;color:#475569}'
            . '.tk-pri-high,.tk-pri-urgent{color:#dc2626;font-weight:700}</style>';
    }
}
