<?php
// app/models/CustomerCreditModel.php
// One balance per customer across both ways a shop gives credit:
//   • the credit book (manual_credits — old debts written by hand, money lent)
//   • credit sales with products (orders, status 'open', with a balance)
// Deposits can be any size. A deposit goes to the invoice picked, or — when
// none is picked — to the oldest open ones first until it is used up. Each
// part is recorded on its own invoice (manual_credit_payments / order_payments),
// so receipts, reports and the Payments page stay exactly as they were.
namespace Models;

class CustomerCreditModel
{
    private \PDO $db;

    public function __construct(?\PDO $db = null)
    {
        $this->db = $db ?? \Database::pdo();
        ManualCreditModel::ensureCustomerLink($this->db);
    }

    private function tid(): int
    {
        return (int) \TenantContext::tenantId();
    }

    /** Customers who owe, with their total balance, newest debt first. */
    public function owing(string $q = ''): array
    {
        $tid = $this->tid();
        $sql = "SELECT c.id, c.name, c.phone,
                       COALESCE(m.bal, 0) AS book, COALESCE(o.bal, 0) AS sales, COALESCE(m.n, 0) + COALESCE(o.n, 0) AS invoices,
                       GREATEST(COALESCE(m.last, '1970-01-01'), COALESCE(o.last, '1970-01-01')) AS last_at
                  FROM customers c
             LEFT JOIN (SELECT customer_id, SUM(amount - amount_paid) bal, COUNT(*) n, MAX(created_at) last FROM manual_credits
                         WHERE tenant_id = ? AND status = 'open' GROUP BY customer_id) m ON m.customer_id = c.id
             LEFT JOIN (SELECT customer_id, SUM(total - COALESCE(amount_paid,0)) bal, COUNT(*) n, MAX(created_at) last FROM orders
                         WHERE tenant_id = ? AND status = 'open' AND customer_id IS NOT NULL GROUP BY customer_id) o ON o.customer_id = c.id
                 WHERE c.tenant_id = ? AND (COALESCE(m.bal, 0) + COALESCE(o.bal, 0)) > 0.004";
        $args = [$tid, $tid, $tid];
        if ($q !== '') { $sql .= ' AND (c.name LIKE ? OR c.phone LIKE ?)'; $args[] = "%$q%"; $args[] = "%$q%"; }
        $st = $this->db->prepare($sql . ' ORDER BY last_at DESC LIMIT 500');
        $st->execute($args);
        return array_map(fn($r) => $r + ['balance' => round((float) $r['book'] + (float) $r['sales'], 2)], $st->fetchAll());
    }

    /** Every open invoice of a customer (oldest first): ['key' => 'm:ID'|'o:ID', 'label', 'date', 'total', 'paid', 'balance']. */
    public function openInvoices(int $customerId): array
    {
        $tid = $this->tid();
        $out = [];
        $m = $this->db->prepare("SELECT id, amount, amount_paid, notes, credit_date, created_at FROM manual_credits WHERE tenant_id = ? AND customer_id = ? AND status = 'open'");
        $m->execute([$tid, $customerId]);
        foreach ($m->fetchAll() as $r) {
            $out[] = ['key' => 'm:' . $r['id'], 'kind' => 'Credit book', 'label' => 'CR-' . str_pad((string) $r['id'], 5, '0', STR_PAD_LEFT) . ($r['notes'] ? ' · ' . $r['notes'] : ''),
                      'date' => $r['credit_date'] ?: substr((string) $r['created_at'], 0, 10), 'at' => $r['created_at'],
                      'total' => (float) $r['amount'], 'paid' => (float) $r['amount_paid'], 'balance' => round((float) $r['amount'] - (float) $r['amount_paid'], 2)];
        }
        $o = $this->db->prepare("SELECT id, receipt_number, total, amount_paid, created_at FROM orders WHERE tenant_id = ? AND customer_id = ? AND status = 'open'");
        $o->execute([$tid, $customerId]);
        foreach ($o->fetchAll() as $r) {
            $out[] = ['key' => 'o:' . $r['id'], 'kind' => 'Credit sale', 'label' => (string) $r['receipt_number'],
                      'date' => substr((string) $r['created_at'], 0, 10), 'at' => $r['created_at'],
                      'total' => (float) $r['total'], 'paid' => (float) $r['amount_paid'], 'balance' => round((float) $r['total'] - (float) $r['amount_paid'], 2)];
        }
        usort($out, fn($a, $b) => strcmp((string) $a['at'], (string) $b['at']));
        return array_values(array_filter($out, fn($i) => $i['balance'] > 0.004));
    }

    /** Deposits received from this customer (both kinds), newest first. */
    public function deposits(int $customerId, int $limit = 50): array
    {
        $tid = $this->tid();
        $st = $this->db->prepare(
            "(SELECT p.created_at, p.amount, p.method, CONCAT('CR-', LPAD(c.id, 5, '0')) AS invoice, u.username AS by_name
                FROM manual_credit_payments p JOIN manual_credits c ON c.id = p.credit_id LEFT JOIN users u ON u.id = p.received_by
               WHERE p.tenant_id = ? AND c.customer_id = ?)
             UNION ALL
             (SELECT p.created_at, p.amount, p.method, o.receipt_number AS invoice, u.username AS by_name
                FROM order_payments p JOIN orders o ON o.id = p.order_id LEFT JOIN users u ON u.id = p.received_by
               WHERE p.tenant_id = ? AND o.customer_id = ?)
             ORDER BY created_at DESC LIMIT " . (int) $limit
        );
        $st->execute([$tid, $customerId, $tid, $customerId]);
        return $st->fetchAll();
    }

    /**
     * Take a deposit: $target 'auto' (oldest first) or an invoice key ('m:ID' / 'o:ID').
     * Returns ['ok', 'error', 'applied' => [[label, amount]…], 'left' => amount not needed].
     */
    public function deposit(int $customerId, float $amount, string $method, string $target = 'auto'): array
    {
        $amount = round($amount, 2);
        $method = in_array($method, ['cash', 'mpesa'], true) ? $method : 'cash';
        if ($amount <= 0) return ['ok' => false, 'error' => 'Enter the amount received.'];
        $open = $this->openInvoices($customerId);
        if (!$open) return ['ok' => false, 'error' => 'This customer owes nothing.'];
        if ($target !== 'auto') {
            $open = array_values(array_filter($open, fn($i) => $i['key'] === $target));
            if (!$open) return ['ok' => false, 'error' => 'That invoice is not open any more.'];
            if ($amount > $open[0]['balance'] + 0.004) {
                return ['ok' => false, 'error' => 'That is more than ' . $open[0]['label'] . ' still owes (' . number_format($open[0]['balance'], 2) . '). Choose "Oldest first" to spread it.'];
            }
        }
        $left = $amount;
        $applied = [];
        $MC = new ManualCreditModel($this->db);
        $OR = new OrderModel($this->db);
        foreach ($open as $inv) {
            if ($left <= 0.004) break;
            $part = round(min($left, $inv['balance']), 2);
            [$kind, $id] = explode(':', $inv['key']);
            $res = $kind === 'm'
                ? $MC->recordPayment((int) $id, $part, $method)
                : $OR->recordPayment((int) $id, ['method' => $method, 'amount' => $part, 'amount_tendered' => $part], (int) \TenantContext::userId());
            if (!$res['ok']) {
                return ['ok' => false, 'error' => $res['error'] . ($applied ? ' (' . count($applied) . ' part(s) were already recorded.)' : ''), 'applied' => $applied];
            }
            $applied[] = [$inv['label'], $part];
            $left = round($left - $part, 2);
        }
        return ['ok' => true, 'error' => null, 'applied' => $applied, 'left' => max(0, $left)];
    }
}
