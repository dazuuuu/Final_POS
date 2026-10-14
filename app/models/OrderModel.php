<?php
// app/models/OrderModel.php
// Bar tabs: a server opens a tab for a table/customer, adds drinks over one
// or more rounds, and someone with payment permission settles it later. This
// is now the only way staff record a sale (the old direct-sale flow was
// removed), so paid orders are "the sales" for owner reporting — see
// forTenant()/productProfit() below, which shape rows to match SaleModel's
// so the owner's Sales page can merge both sources. Legacy `sales` rows stay
// visible for history. Mirrors SaleModel's transactional style since
// multi-row atomic writes don't fit the plain base-Model CRUD helpers.
namespace Models;

class OrderModel extends Model
{
    protected string $table = 'orders';

    public function __construct(?\PDO $db = null)
    {
        parent::__construct($db);
        $this->ensurePricingSchema();
    }

    /**
     * Open a new order.
     * @param array $in table_name, opened_by, items[{product_id,quantity}],
     *                  channel: 'walkin' (Home — checkout pays immediately, no
     *                  invoice) or 'tab' (Orders — starts unpaid, this IS the
     *                  invoice). Defaults to 'tab' for backward compatibility.
     *                  Optional: discount_amount (negotiated off the subtotal,
     *                  clamped so the total never goes below zero),
     *                  customer_email / customer_phone (for emailing an
     *                  invoice/delivery note on a credit sale later).
     */
    public function open(array $in): array
    {
        $tid = \TenantContext::tenantId();
        if ($tid === null) {
            return ['ok' => false, 'errors' => ['_' => 'No shop in context.']];
        }

        $tableName = trim($in['table_name'] ?? '');
        if ($tableName === '') {
            return ['ok' => false, 'errors' => ['table_name' => 'Enter a table or customer name.']];
        }
        $openedBy = (int) ($in['opened_by'] ?? 0);
        if ($openedBy <= 0) {
            return ['ok' => false, 'errors' => ['_' => 'No staff in context.']];
        }
        $channelIn = $in['channel'] ?? 'tab';
        $channel   = in_array($channelIn, ['walkin', 'tab'], true) ? $channelIn : 'tab';
        $saleTypeIn = $in['sale_type'] ?? 'retail';
        $saleType = in_array($saleTypeIn, ['retail', 'wholesale'], true) ? $saleTypeIn : 'retail';
        $items = array_values(array_filter($in['items'] ?? [], fn($i) => (int) ($i['product_id'] ?? 0) > 0 && (float) ($i['quantity'] ?? 0) > 0));
        if (!$items) {
            return ['ok' => false, 'errors' => ['_' => 'Add at least one item.']];
        }

        $db = $this->db;
        // Built before the transaction: its schema check runs DDL, which would commit it.
        $electronics = ElectronicsModel::enabled($tid) ? new ElectronicsModel($db) : null;
        try {
            $db->beginTransaction();

            $ins = $db->prepare(
                "INSERT INTO orders (tenant_id, table_name, channel, sale_type, opened_by, receipt_number, status, subtotal, total)
                 VALUES (?,?,?,?,?,'PENDING','open',0,0)"
            );
            $ins->execute([$tid, $tableName, $channel, $saleType, $openedBy]);
            $orderId = (int) $db->lastInsertId();
            $prefix  = $channel === 'walkin' ? 'RCP-' : 'ORD-';
            $receipt = $prefix . str_pad((string) $orderId, 6, '0', STR_PAD_LEFT);
            $db->prepare('UPDATE orders SET receipt_number = ? WHERE id = ?')->execute([$receipt, $orderId]);

            $added = $this->insertItems($db, $tid, $orderId, $items, $openedBy, $saleType);
            if (!$added['ok']) {
                $db->rollBack();
                return $added;
            }

            $email = trim((string) ($in['customer_email'] ?? ''));
            $phone = trim((string) ($in['customer_phone'] ?? ''));

            // Electronics: every tracked unit sold needs its serial / IMEI.
            if ($electronics) {
                $serialRes = $electronics->attachToSale($tid, $orderId, $items, (array) ($in['serials'] ?? []), $tableName, $phone !== '' ? $phone : null);
                if (!$serialRes['ok']) {
                    $db->rollBack();
                    return ['ok' => false, 'errors' => ['_' => $serialRes['error']]];
                }
            }

            $discountIn = round((float) ($in['discount_amount'] ?? 0), 2);
            $customerId = (int) ($in['customer_id'] ?? 0) ?: null;
            $company = trim((string) ($in['customer_company'] ?? ''));
            $location = trim((string) ($in['customer_location'] ?? ''));
            $deliveryPerson = trim((string) ($in['delivery_person'] ?? ''));
            $deliveryFee = max(0, round((float) ($in['delivery_fee'] ?? 0), 2));
            if ($discountIn > 0 || $email !== '' || $phone !== '' || $customerId || $company !== '' || $location !== '' || $deliveryPerson !== '' || $deliveryFee > 0) {
                $sub = $db->prepare('SELECT subtotal FROM orders WHERE id = ?');
                $sub->execute([$orderId]);
                $subtotal = (float) $sub->fetchColumn();
                $discount = min(max($discountIn, 0), $subtotal); // never negative, never more than the subtotal
                $newTotal = round($subtotal - $discount + $deliveryFee, 2);
                $db->prepare('UPDATE orders SET discount_amount = ?, total = ?, customer_id = ?, customer_email = ?, customer_phone = ?, customer_company = ?, customer_location = ?, delivery_person = ?, delivery_fee = ? WHERE id = ?')
                    ->execute([$discount, $newTotal, $customerId, $email !== '' ? $email : null, $phone !== '' ? $phone : null, $company ?: null, $location ?: null, $deliveryPerson ?: null, $deliveryFee, $orderId]);
            }

            $db->commit();
            return ['ok' => true, 'order_id' => $orderId, 'receipt_number' => $receipt, 'errors' => []];
        } catch (\Throwable $e) {
            if ($db->inTransaction()) { $db->rollBack(); }
            error_log('OrderModel::open failed: ' . $e->getMessage());
            return ['ok' => false, 'errors' => ['_' => 'Could not open this tab. Please try again.']];
        }
    }

    /** Add another round of drinks to an OPEN tab. */
    public function addItems(int $orderId, array $items, int $staffId): array
    {
        $tid = \TenantContext::tenantId();
        if ($tid === null) {
            return ['ok' => false, 'errors' => ['_' => 'No shop in context.']];
        }
        $items = array_values(array_filter($items, fn($i) => (int) ($i['product_id'] ?? 0) > 0 && (float) ($i['quantity'] ?? 0) > 0));
        if (!$items) {
            return ['ok' => false, 'errors' => ['_' => 'Add at least one drink.']];
        }

        $db = $this->db;
        $tracked = ElectronicsModel::enabled($tid) ? (new ElectronicsModel($db))->trackedProducts() : [];
        try {
            $db->beginTransaction();

            $sel = $db->prepare("SELECT id, status, sale_type FROM orders WHERE id = ? AND tenant_id = ? FOR UPDATE");
            $sel->execute([$orderId, $tid]);
            $order = $sel->fetch();
            if (!$order) {
                $db->rollBack();
                return ['ok' => false, 'errors' => ['_' => 'Tab not found.']];
            }
            if ($order['status'] !== 'open') {
                $db->rollBack();
                return ['ok' => false, 'errors' => ['_' => 'This tab is no longer open.']];
            }

            if ($tracked) {
                foreach ($items as $it) {
                    if (isset($tracked[(int) $it['product_id']])) {
                        $db->rollBack();
                        return ['ok' => false, 'errors' => ['_' => $tracked[(int) $it['product_id']]['name'] . ' needs a serial / IMEI — sell it as a new sale from the POS.']];
                    }
                }
            }

            $saleType = in_array($order['sale_type'] ?? 'retail', ['retail', 'wholesale'], true) ? $order['sale_type'] : 'retail';
            $added = $this->insertItems($db, $tid, $orderId, $items, $staffId, $saleType);
            if (!$added['ok']) {
                $db->rollBack();
                return $added;
            }

            $db->commit();
            return ['ok' => true, 'errors' => []];
        } catch (\Throwable $e) {
            if ($db->inTransaction()) { $db->rollBack(); }
            error_log('OrderModel::addItems failed: ' . $e->getMessage());
            return ['ok' => false, 'errors' => ['_' => 'Could not add those drinks. Please try again.']];
        }
    }

    /** Shared item-insert + stock-decrement + total-recalc, used by open() and addItems(). */
    private function insertItems(\PDO $db, int $tid, int $orderId, array $items, int $staffId, string $saleType = 'retail'): array
    {
        $saleType = in_array($saleType, ['retail', 'wholesale'], true) ? $saleType : 'retail';
        $selSql = "SELECT id, name, selling_price, wholesale_price, retail_price, offer_price, offer_starts_at, offer_ends_at, quantity, unit
                     FROM products WHERE id = ? AND tenant_id = ? AND status IN ('active','archived') FOR UPDATE";
        try {
            $sel = $db->prepare($selSql);
        } catch (\PDOException $e) {
            $sel = $db->prepare("SELECT id, name, selling_price, retail_price, quantity, unit FROM products WHERE id = ? AND tenant_id = ? AND status IN ('active','archived') FOR UPDATE");
        }
        $insItem = $db->prepare(
            'INSERT INTO order_items (tenant_id, order_id, product_id, product_name, unit_price, price_type, quantity, line_total, added_by)
             VALUES (?,?,?,?,?,?,?,?,?)'
        );
        $dec = $db->prepare('UPDATE products SET quantity = quantity - ? WHERE id = ? AND tenant_id = ? AND quantity >= ?');

        foreach ($items as $it) {
            $pid = (int) $it['product_id'];
            $qty = (float) $it['quantity'];
            $sel->execute([$pid, $tid]);
            $p = $sel->fetch();
            if (!$p) {
                return ['ok' => false, 'errors' => ['_' => 'One of the drinks is no longer available. Refresh and try again.']];
            }
            if ($qty > (float) $p['quantity']) {
                return ['ok' => false, 'errors' => ['_' => "Not enough stock for {$p['name']} — only " . rtrim(rtrim(number_format((float) $p['quantity'], 2), '0'), '.') . ' left.']];
            }
            if ($saleType === 'wholesale') {
                $unitPrice = (float) ($p['wholesale_price'] ?? 0);
                if ($unitPrice <= 0) { $unitPrice = (float) ($p['retail_price'] ?: $p['selling_price']); }
            } else {
                $offerRow = $p;
                if (!array_key_exists('offer_price', $offerRow)) {
                    $offerRow['offer_price'] = null;
                    $offerRow['offer_starts_at'] = null;
                    $offerRow['offer_ends_at'] = null;
                }
                $unitPrice = ProductModel::effectivePrice($offerRow)['price'];
                if ($unitPrice <= 0) { $unitPrice = (float) ($p['retail_price'] ?: $p['selling_price']); }
            }
            $lineTotal = round($unitPrice * $qty, 2);

            $insItem->execute([$tid, $orderId, $pid, $p['name'], $unitPrice, $saleType, $qty, $lineTotal, $staffId]);
            $dec->execute([$qty, $pid, $tid, $qty]);
            if ($dec->rowCount() !== 1) {
                return ['ok' => false, 'errors' => ['_' => "Stock changed for {$p['name']} while saving. Please try again."]];
            }
        }

        $sum = $db->prepare('SELECT COALESCE(SUM(line_total),0) FROM order_items WHERE order_id = ? AND tenant_id = ?');
        $sum->execute([$orderId, $tid]);
        $subtotal = round((float) $sum->fetchColumn(), 2);
        $meta = $db->prepare('SELECT discount_amount, delivery_fee FROM orders WHERE id = ?');
        $meta->execute([$orderId]);
        $row = $meta->fetch() ?: ['discount_amount' => 0, 'delivery_fee' => 0];
        $discount = min(max((float) ($row['discount_amount'] ?? 0), 0), $subtotal);
        $total = round($subtotal - $discount + (float) ($row['delivery_fee'] ?? 0), 2);
        $db->prepare('UPDATE orders SET subtotal = ?, total = ? WHERE id = ?')->execute([$subtotal, $total, $orderId]);

        return ['ok' => true, 'errors' => []];
    }

    /** Settle a tab in full. $payment: method (cash/mpesa/split), cash_amount, mpesa_amount, amount_tendered. */
    public function markPaid(int $orderId, array $payment, int $staffId): array
    {
        return $this->recordPayment($orderId, $payment, $staffId, true);
    }

    /**
     * Record a payment (full or a part-payment/deposit) against an OPEN tab.
     * $payment: method (cash/mpesa/split), amount (what's being paid now —
     * optional, defaults to what was handed over, capped at the balance),
     * amount_tendered (cash handed over), cash_amount / mpesa_amount (split).
     * Every payment is logged in order_payments; once the running total
     * covers the bill the tab flips to 'paid' (and then counts as a sale).
     */
    public function recordPayment(int $orderId, array $payment, int $staffId, bool $requireFull = false): array
    {
        $tid = \TenantContext::tenantId();
        if ($tid === null) {
            return ['ok' => false, 'error' => 'No shop in context.'];
        }
        $method = in_array($payment['method'] ?? '', ['cash', 'mpesa', 'split'], true) ? $payment['method'] : null;
        if (!$method) {
            return ['ok' => false, 'error' => 'Choose how the customer paid.'];
        }

        $db = $this->db;
        try {
            $db->beginTransaction();
            $sel = $db->prepare('SELECT id, status, total, amount_paid FROM orders WHERE id = ? AND tenant_id = ? FOR UPDATE');
            $sel->execute([$orderId, $tid]);
            $order = $sel->fetch();
            if (!$order) { $db->rollBack(); return ['ok' => false, 'error' => 'Tab not found.']; }
            if ($order['status'] !== 'open') { $db->rollBack(); return ['ok' => false, 'error' => 'This tab is not open.']; }

            $total   = round((float) $order['total'], 2);
            $paid    = round((float) ($order['amount_paid'] ?? 0), 2);
            $balance = round(max(0, $total - $paid), 2);
            $wanted  = round((float) ($payment['amount'] ?? 0), 2);
            $fmt = fn($n) => 'KES ' . number_format($n, 0);

            $cashPart = 0.0;
            $mpesaPart = 0.0;
            $tendered = null;
            $change = null;
            if ($method === 'cash') {
                $tendered = max(0, round((float) ($payment['amount_tendered'] ?? 0), 2));
                if ($tendered <= 0 && $wanted > 0) { $tendered = $wanted; }
                if ($requireFull && $tendered + 0.0001 < $balance) {
                    $db->rollBack();
                    return ['ok' => false, 'error' => 'Cash given is less than the total (' . $fmt($balance) . ').'];
                }
                $amount = min($wanted > 0 ? $wanted : $tendered, $tendered, $balance);
                $cashPart = $amount;
                $change = round(max(0, $tendered - $amount), 2);
            } elseif ($method === 'mpesa') {
                $amount = $requireFull || $wanted <= 0 ? $balance : min($wanted, $balance);
                $mpesaPart = $amount;
            } else { // split
                $c = max(0, round((float) ($payment['cash_amount'] ?? 0), 2));
                $m = max(0, round((float) ($payment['mpesa_amount'] ?? 0), 2));
                if ($requireFull && ($c + $m) + 0.0001 < $balance) {
                    $db->rollBack();
                    return ['ok' => false, 'error' => 'Cash and M-Pesa amounts are less than the total (' . $fmt($balance) . ').'];
                }
                $amount = min($c + $m, $balance);
                $mpesaPart = min($m, $amount);
                $cashPart = round($amount - $mpesaPart, 2);
                $tendered = round($c + $m, 2);
                $change = round(max(0, ($c + $m) - $amount), 2);
            }
            $amount = round($amount, 2);
            if ($amount <= 0 && $balance > 0) {
                $db->rollBack();
                return ['ok' => false, 'error' => 'Enter the amount the customer is paying.'];
            }

            if ($amount > 0) {
                $db->prepare(
                    'INSERT INTO order_payments (tenant_id, order_id, amount, method, cash_amount, mpesa_amount, amount_tendered, change_due, received_by)
                     VALUES (?,?,?,?,?,?,?,?,?)'
                )->execute([$tid, $orderId, $amount, $method, $cashPart, $mpesaPart, $tendered, $change, $staffId]);
            }

            $newPaid = round($paid + $amount, 2);
            $fullyPaid = $newPaid + 0.004 >= $total;
            if ($fullyPaid) {
                // Summarise every instalment on the order row so reports'
                // cash / M-Pesa splits stay right.
                $sum = $db->prepare('SELECT COALESCE(SUM(cash_amount),0) c, COALESCE(SUM(mpesa_amount),0) m, COUNT(DISTINCT method) n, MAX(method) mx FROM order_payments WHERE order_id = ? AND tenant_id = ?');
                $sum->execute([$orderId, $tid]);
                $agg = $sum->fetch();
                $cashTotal = round((float) $agg['c'], 2);
                $mpesaTotal = round((float) $agg['m'], 2);
                $summaryMethod = ((int) $agg['n'] === 1 && $agg['mx'] !== null) ? $agg['mx'] : ($cashTotal > 0 && $mpesaTotal > 0 ? 'split' : ($mpesaTotal > 0 ? 'mpesa' : 'cash'));
                $db->prepare(
                    'UPDATE orders SET status = ?, payment_method = ?, cash_amount = ?, mpesa_amount = ?, amount_tendered = ?, change_due = ?, amount_paid = ?, paid_by = ?, paid_at = NOW() WHERE id = ?'
                )->execute(['paid', $summaryMethod, $cashTotal > 0 ? $cashTotal : null, $mpesaTotal > 0 ? $mpesaTotal : null, $tendered, $change, $newPaid, $staffId, $orderId]);
            } else {
                $db->prepare('UPDATE orders SET amount_paid = ? WHERE id = ?')->execute([$newPaid, $orderId]);
            }

            $db->commit();
            return [
                'ok' => true, 'error' => null, 'amount' => $amount, 'change' => $change,
                'balance' => round(max(0, $total - $newPaid), 2), 'fully_paid' => $fullyPaid,
            ];
        } catch (\Throwable $e) {
            if ($db->inTransaction()) { $db->rollBack(); }
            error_log('OrderModel::recordPayment failed: ' . $e->getMessage());
            return ['ok' => false, 'error' => 'Could not record the payment. Please try again.'];
        }
    }

    /** Every instalment paid against an order, oldest first. */
    public function payments(int $orderId): array
    {
        $stmt = $this->db->prepare(
            'SELECT p.*, u.username AS received_by_name FROM order_payments p
          LEFT JOIN users u ON u.id = p.received_by
              WHERE p.order_id = ? AND p.tenant_id = ? ORDER BY p.created_at ASC, p.id ASC'
        );
        $stmt->execute([$orderId, \TenantContext::tenantId()]);
        return $stmt->fetchAll();
    }

    /** What the customer has paid so far (a paid order counts as fully paid even if it predates instalments). */
    public static function paidSoFar(array $order): float
    {
        if (($order['status'] ?? '') === 'paid') {
            return round(max((float) ($order['amount_paid'] ?? 0), (float) $order['total']), 2);
        }
        return round((float) ($order['amount_paid'] ?? 0), 2);
    }

    public static function balanceDue(array $order): float
    {
        if (($order['status'] ?? '') !== 'open') { return 0.0; }
        return round(max(0, (float) $order['total'] - (float) ($order['amount_paid'] ?? 0)), 2);
    }

    /**
     * Return items from a sale: put them back in stock, shrink the order's
     * lines/totals so reports stop counting them, and log the return.
     * $qtyByItem: [order_item_id => quantity being returned].
     * Paid sale → refund_amount is the money handed back (taken off the
     * recorded cash / M-Pesa). Credit sale → the balance just drops; money
     * is only refunded if they'd already paid more than the new total.
     */
    public function returnItems(int $orderId, array $qtyByItem, string $reason, string $refundMethod, int $staffId): array
    {
        $tid = \TenantContext::tenantId();
        if ($tid === null) {
            return ['ok' => false, 'error' => 'No shop in context.'];
        }
        $refundMethod = in_array($refundMethod, ['cash', 'mpesa'], true) ? $refundMethod : 'cash';
        $qtyByItem = array_filter(array_map(fn($q) => round((float) $q, 2), $qtyByItem), fn($q) => $q > 0);
        if (!$qtyByItem) {
            return ['ok' => false, 'error' => 'Enter how many of each item is being returned.'];
        }

        $db = $this->db;
        try {
            $db->beginTransaction();
            $sel = $db->prepare('SELECT * FROM orders WHERE id = ? AND tenant_id = ? FOR UPDATE');
            $sel->execute([$orderId, $tid]);
            $order = $sel->fetch();
            if (!$order) { $db->rollBack(); return ['ok' => false, 'error' => 'Sale not found.']; }
            if ($order['status'] === 'void') { $db->rollBack(); return ['ok' => false, 'error' => 'This sale was voided — nothing to return.']; }

            $itemSel = $db->prepare('SELECT * FROM order_items WHERE id = ? AND order_id = ? AND tenant_id = ? FOR UPDATE');
            $itemUpd = $db->prepare('UPDATE order_items SET quantity = ?, line_total = ? WHERE id = ?');
            $restore = $db->prepare('UPDATE products SET quantity = quantity + ? WHERE id = ? AND tenant_id = ?');
            $lines = [];
            foreach ($qtyByItem as $itemId => $q) {
                $itemSel->execute([(int) $itemId, $orderId, $tid]);
                $it = $itemSel->fetch();
                if (!$it) { $db->rollBack(); return ['ok' => false, 'error' => 'One of those items is not on this sale.']; }
                if ($q > (float) $it['quantity'] + 0.0001) {
                    $db->rollBack();
                    return ['ok' => false, 'error' => "Only " . rtrim(rtrim(number_format((float) $it['quantity'], 2), '0'), '.') . " × {$it['product_name']} left on this sale."];
                }
                $left = round((float) $it['quantity'] - $q, 2);
                $itemUpd->execute([$left, round((float) $it['unit_price'] * $left, 2), $it['id']]);
                if (!empty($it['product_id'])) {
                    $restore->execute([$q, $it['product_id'], $tid]);
                }
                $lines[] = ['item' => $it, 'qty' => $q, 'value' => round((float) $it['unit_price'] * $q, 2)];
            }

            $sum = $db->prepare('SELECT COALESCE(SUM(line_total),0) FROM order_items WHERE order_id = ? AND tenant_id = ?');
            $sum->execute([$orderId, $tid]);
            $subtotal = round((float) $sum->fetchColumn(), 2);
            $discount = min(max((float) $order['discount_amount'], 0), $subtotal);
            $delivery = $subtotal > 0 ? (float) ($order['delivery_fee'] ?? 0) : 0.0;
            $newTotal = round($subtotal - $discount + $delivery, 2);
            $oldPaid = self::paidSoFar($order);
            $refund = round(max(0, $oldPaid - $newTotal), 2);
            $newPaid = round($oldPaid - $refund, 2);

            $status = $order['status'];
            if ($subtotal <= 0) {
                $status = 'void'; // everything came back — it's no longer a sale
            } elseif ($status === 'open' && $newPaid + 0.004 >= $newTotal) {
                $status = 'paid'; // what they'd paid now covers the smaller bill
            }

            $cash = (float) ($order['cash_amount'] ?? 0);
            $mpesa = (float) ($order['mpesa_amount'] ?? 0);
            if ($refund > 0 && $order['status'] === 'paid') {
                if ($refundMethod === 'mpesa') {
                    $fromM = min($mpesa, $refund); $mpesa -= $fromM; $cash = max(0, $cash - ($refund - $fromM));
                } else {
                    $fromC = min($cash, $refund); $cash -= $fromC; $mpesa = max(0, $mpesa - ($refund - $fromC));
                }
            }

            $db->prepare(
                'UPDATE orders SET subtotal = ?, discount_amount = ?, delivery_fee = ?, total = ?, amount_paid = ?, cash_amount = ?, mpesa_amount = ?, status = ?,
                        paid_at = IF(? = \'paid\' AND paid_at IS NULL, NOW(), paid_at), paid_by = COALESCE(paid_by, ?), edited_at = NOW()
                  WHERE id = ? AND tenant_id = ?'
            )->execute([$subtotal, $discount, $delivery, $newTotal, $newPaid, $cash > 0 ? round($cash, 2) : null, $mpesa > 0 ? round($mpesa, 2) : null, $status, $status, $staffId, $orderId, $tid]);

            $returnValue = round(array_sum(array_column($lines, 'value')), 2);
            $db->prepare(
                'INSERT INTO order_returns (tenant_id, order_id, return_value, refund_amount, refund_method, reason, processed_by) VALUES (?,?,?,?,?,?,?)'
            )->execute([$tid, $orderId, $returnValue, $refund, $refund > 0 ? $refundMethod : null, trim($reason) !== '' ? mb_substr(trim($reason), 0, 255) : null, $staffId]);
            $returnId = (int) $db->lastInsertId();
            $insLine = $db->prepare(
                'INSERT INTO order_return_items (tenant_id, return_id, order_item_id, product_id, product_name, unit_price, quantity, line_total) VALUES (?,?,?,?,?,?,?,?)'
            );
            foreach ($lines as $l) {
                $insLine->execute([$tid, $returnId, $l['item']['id'], $l['item']['product_id'], $l['item']['product_name'], $l['item']['unit_price'], $l['qty'], $l['value']]);
            }

            $db->commit();
            return ['ok' => true, 'error' => null, 'return_id' => $returnId, 'refund' => $refund, 'return_value' => $returnValue, 'status' => $status];
        } catch (\Throwable $e) {
            if ($db->inTransaction()) { $db->rollBack(); }
            error_log('OrderModel::returnItems failed: ' . $e->getMessage());
            return ['ok' => false, 'error' => 'Could not record this return. Please try again.'];
        }
    }

    /** Returns logged against one order (with their lines), oldest first. */
    public function returnsFor(int $orderId): array
    {
        $tid = \TenantContext::tenantId();
        $stmt = $this->db->prepare(
            'SELECT r.*, u.username AS processed_by_name FROM order_returns r
          LEFT JOIN users u ON u.id = r.processed_by
              WHERE r.order_id = ? AND r.tenant_id = ? ORDER BY r.created_at ASC, r.id ASC'
        );
        $stmt->execute([$orderId, $tid]);
        $rows = $stmt->fetchAll();
        $lines = $this->db->prepare('SELECT * FROM order_return_items WHERE return_id = ? AND tenant_id = ? ORDER BY id ASC');
        foreach ($rows as &$r) {
            $lines->execute([$r['id'], $tid]);
            $r['items'] = $lines->fetchAll();
        }
        unset($r);
        return $rows;
    }

    /** Most recent returns shop-wide, for the Returns page. */
    public function recentReturns(int $limit = 50): array
    {
        $stmt = $this->db->prepare(
            'SELECT r.*, o.receipt_number, o.table_name, u.username AS processed_by_name,
                    (SELECT GROUP_CONCAT(CONCAT(TRIM(TRAILING \'.\' FROM TRIM(TRAILING \'0\' FROM ri.quantity)), \' × \', ri.product_name) SEPARATOR \', \') FROM order_return_items ri WHERE ri.return_id = r.id) AS summary
               FROM order_returns r
               JOIN orders o ON o.id = r.order_id AND o.tenant_id = r.tenant_id
          LEFT JOIN users u ON u.id = r.processed_by
              WHERE r.tenant_id = ?
           ORDER BY r.created_at DESC, r.id DESC
              LIMIT ' . (int) $limit
        );
        $stmt->execute([\TenantContext::tenantId()]);
        return $stmt->fetchAll();
    }

    /** Edit an open/paid order sale, restoring old stock and issuing a revised receipt number. */
    public function updateOrder(int $orderId, array $in, int $staffId, ?int $onlyOpenedBy = null): array
    {
        $tid = \TenantContext::tenantId();
        if ($tid === null) {
            return ['ok' => false, 'errors' => ['_' => 'No shop in context.']];
        }
        $items = array_values(array_filter($in['items'] ?? [], fn($i) => (int) ($i['product_id'] ?? 0) > 0 && (float) ($i['quantity'] ?? 0) > 0));
        if (!$items) {
            return ['ok' => false, 'errors' => ['_' => 'Add at least one item.']];
        }
        $tableName = trim((string) ($in['table_name'] ?? ''));
        if ($tableName === '') {
            return ['ok' => false, 'errors' => ['table_name' => 'Enter the customer name.']];
        }
        $saleType = in_array($in['sale_type'] ?? '', ['retail', 'wholesale'], true) ? $in['sale_type'] : 'retail';

        $db = $this->db;
        try {
            $db->beginTransaction();
            $sql = "SELECT * FROM orders WHERE id = ? AND tenant_id = ?" . ($onlyOpenedBy !== null ? " AND opened_by = ?" : "") . " FOR UPDATE";
            $params = [$orderId, $tid];
            if ($onlyOpenedBy !== null) { $params[] = $onlyOpenedBy; }
            $sel = $db->prepare($sql);
            $sel->execute($params);
            $order = $sel->fetch();
            if (!$order) { $db->rollBack(); return ['ok' => false, 'errors' => ['_' => 'Sale not found.']]; }
            if (($order['status'] ?? '') === 'void') { $db->rollBack(); return ['ok' => false, 'errors' => ['_' => 'A voided sale cannot be edited.']]; }

            $oldItems = $db->prepare('SELECT product_id, quantity FROM order_items WHERE order_id = ? AND tenant_id = ? FOR UPDATE');
            $oldItems->execute([$orderId, $tid]);
            $restore = $db->prepare('UPDATE products SET quantity = quantity + ? WHERE id = ? AND tenant_id = ?');
            foreach ($oldItems->fetchAll() as $old) {
                if (!empty($old['product_id'])) {
                    $restore->execute([$old['quantity'], $old['product_id'], $tid]);
                }
            }

            $discount = max(0, round((float) ($in['discount_amount'] ?? 0), 2));
            $email = trim((string) ($in['customer_email'] ?? ''));
            $phone = trim((string) ($in['customer_phone'] ?? ''));
            $editCount = (int) ($order['edit_count'] ?? 0) + 1;
            $prefix = ($order['channel'] ?? 'tab') === 'walkin' ? 'RCP-' : 'ORD-';
            $receipt = $prefix . str_pad((string) $orderId, 6, '0', STR_PAD_LEFT) . '-R' . ($editCount + 1);

            $db->prepare('DELETE FROM order_items WHERE order_id = ? AND tenant_id = ?')->execute([$orderId, $tid]);
            $db->prepare(
                'UPDATE orders
                    SET table_name = ?, sale_type = ?, receipt_number = ?, edit_count = ?, edited_at = NOW(),
                        discount_amount = ?, customer_email = ?, customer_phone = ?, subtotal = 0, total = 0
                  WHERE id = ? AND tenant_id = ?'
            )->execute([$tableName, $saleType, $receipt, $editCount, $discount, $email !== '' ? $email : null, $phone !== '' ? $phone : null, $orderId, $tid]);

            $added = $this->insertItems($db, $tid, $orderId, $items, $staffId, $saleType);
            if (!$added['ok']) {
                $db->rollBack();
                return $added;
            }

            $fresh = $db->prepare('SELECT total, status FROM orders WHERE id = ? AND tenant_id = ?');
            $fresh->execute([$orderId, $tid]);
            $updated = $fresh->fetch();
            $total = (float) ($updated['total'] ?? 0);
            if (($updated['status'] ?? '') === 'paid') {
                $method = in_array($in['payment_method'] ?? '', ['cash', 'mpesa', 'split'], true) ? $in['payment_method'] : ($order['payment_method'] ?? 'cash');
                $cash = 0.0;
                $mpesa = 0.0;
                $tendered = null;
                $change = null;
                if ($method === 'cash') {
                    $cash = $total;
                    $tendered = max(0, round((float) ($in['amount_tendered'] ?? 0), 2));
                    if ($tendered + 0.0001 < $cash) {
                        $db->rollBack();
                        return ['ok' => false, 'errors' => ['amount_tendered' => 'Cash given is less than the total.']];
                    }
                    $change = round($tendered - $cash, 2);
                } elseif ($method === 'mpesa') {
                    $mpesa = $total;
                } else {
                    $cash = max(0, round((float) ($in['cash_amount'] ?? 0), 2));
                    $mpesa = max(0, round((float) ($in['mpesa_amount'] ?? 0), 2));
                    $tendered = max(0, round((float) ($in['amount_tendered'] ?? ($cash + $mpesa)), 2));
                    if (($cash + $mpesa) + 0.0001 < $total) {
                        $db->rollBack();
                        return ['ok' => false, 'errors' => ['_' => 'Cash and M-Pesa amounts are less than the total (KES ' . number_format($total, 0) . ').']];
                    }
                    $change = round(max(0, ($cash + $mpesa) - $total), 2);
                }
                $db->prepare('UPDATE orders SET payment_method = ?, cash_amount = ?, mpesa_amount = ?, amount_tendered = ?, change_due = ?, amount_paid = ?, paid_by = ? WHERE id = ? AND tenant_id = ?')
                    ->execute([$method, $cash > 0 ? $cash : null, $mpesa > 0 ? $mpesa : null, $tendered, $change, $total, $staffId, $orderId, $tid]);
            } elseif (($updated['status'] ?? '') === 'open' && (float) ($order['amount_paid'] ?? 0) > 0 && (float) $order['amount_paid'] + 0.004 >= $total) {
                // Edited down below what they'd already deposited — it's settled.
                $db->prepare("UPDATE orders SET status = 'paid', paid_by = ?, paid_at = NOW() WHERE id = ? AND tenant_id = ?")
                    ->execute([$staffId, $orderId, $tid]);
            }

            $db->commit();
            return ['ok' => true, 'order_id' => $orderId, 'receipt_number' => $receipt, 'errors' => []];
        } catch (\Throwable $e) {
            if ($db->inTransaction()) { $db->rollBack(); }
            error_log('OrderModel::updateOrder failed: ' . $e->getMessage());
            return ['ok' => false, 'errors' => ['_' => 'Could not update this sale. Please try again.']];
        }
    }

    /** Cancel an open tab, restoring its stock. */
    public function void(int $orderId, int $staffId): array
    {
        $tid = \TenantContext::tenantId();
        if ($tid === null) {
            return ['ok' => false, 'error' => 'No shop in context.'];
        }
        $db = $this->db;
        try {
            $db->beginTransaction();
            $sel = $db->prepare('SELECT id, status FROM orders WHERE id = ? AND tenant_id = ? FOR UPDATE');
            $sel->execute([$orderId, $tid]);
            $order = $sel->fetch();
            if (!$order) { $db->rollBack(); return ['ok' => false, 'error' => 'Tab not found.']; }
            if ($order['status'] !== 'open') { $db->rollBack(); return ['ok' => false, 'error' => 'Only an open tab can be voided.']; }

            $items = $db->prepare('SELECT product_id, quantity FROM order_items WHERE order_id = ? AND tenant_id = ?');
            $items->execute([$orderId, $tid]);
            $restore = $db->prepare('UPDATE products SET quantity = quantity + ? WHERE id = ? AND tenant_id = ?');
            foreach ($items->fetchAll() as $it) {
                if ($it['product_id']) {
                    $restore->execute([$it['quantity'], $it['product_id'], $tid]);
                }
            }

            $db->prepare("UPDATE orders SET status = 'void', paid_by = ?, paid_at = NOW() WHERE id = ?")->execute([$staffId, $orderId]);
            $db->commit();
            return ['ok' => true, 'error' => null];
        } catch (\Throwable $e) {
            if ($db->inTransaction()) { $db->rollBack(); }
            error_log('OrderModel::void failed: ' . $e->getMessage());
            return ['ok' => false, 'error' => 'Could not void this tab. Please try again.'];
        }
    }

    /** Reverse a duplicated paid walk-in sale/order: restore stock and mark it
     *  void so sales totals, cash/M-Pesa, and profit stop counting it. */
    public function reverseDuplicate(int $orderId, int $staffId, ?int $onlyOpenedBy = null): array
    {
        $tid = \TenantContext::tenantId();
        if ($tid === null) {
            return ['ok' => false, 'error' => 'No shop in context.'];
        }

        $db = $this->db;
        try {
            $db->beginTransaction();

            $sql = "SELECT id, status, channel FROM orders WHERE id = ? AND tenant_id = ?" . ($onlyOpenedBy !== null ? " AND opened_by = ?" : "") . " FOR UPDATE";
            $params = [$orderId, $tid];
            if ($onlyOpenedBy !== null) { $params[] = $onlyOpenedBy; }
            $sel = $db->prepare($sql);
            $sel->execute($params);
            $order = $sel->fetch();
            if (!$order) {
                $db->rollBack();
                return ['ok' => false, 'error' => 'Sale not found.'];
            }
            if (($order['status'] ?? '') === 'void') {
                $db->rollBack();
                return ['ok' => false, 'error' => 'This sale has already been reversed.'];
            }
            if (($order['status'] ?? '') !== 'paid' || ($order['channel'] ?? '') !== 'walkin') {
                $db->rollBack();
                return ['ok' => false, 'error' => 'Only paid walk-in sales can be reversed here.'];
            }

            $items = $db->prepare('SELECT product_id, quantity FROM order_items WHERE order_id = ? AND tenant_id = ? FOR UPDATE');
            $items->execute([$orderId, $tid]);
            $restore = $db->prepare('UPDATE products SET quantity = quantity + ? WHERE id = ? AND tenant_id = ?');
            foreach ($items->fetchAll() as $it) {
                if (!empty($it['product_id'])) {
                    $restore->execute([$it['quantity'], $it['product_id'], $tid]);
                }
            }

            $db->prepare("UPDATE orders SET status = 'void', edited_at = NOW(), paid_by = ? WHERE id = ? AND tenant_id = ?")
                ->execute([$staffId, $orderId, $tid]);

            $db->commit();
            return ['ok' => true, 'error' => null];
        } catch (\Throwable $e) {
            if ($db->inTransaction()) { $db->rollBack(); }
            error_log('OrderModel::reverseDuplicate failed: ' . $e->getMessage());
            return ['ok' => false, 'error' => 'Could not reverse this duplicate sale. Please try again.'];
        }
    }

    /** Find an order by its printed invoice/receipt number (e.g. ORD-000123). */
    public function findByReceipt(string $receiptNumber): ?array
    {
        $tid = \TenantContext::tenantId();
        $stmt = $this->db->prepare(
            "SELECT o.*, u.username AS opened_by_name
               FROM orders o
          LEFT JOIN users u ON u.id = o.opened_by
              WHERE o.tenant_id = ? AND o.receipt_number = ? LIMIT 1"
        );
        $stmt->execute([$tid, strtoupper(trim($receiptNumber))]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /** All open tabs for the tenant, newest first. */
    public function openOrders(): array
    {
        $tid = \TenantContext::tenantId();
        $sql = "SELECT o.*, u.username AS opened_by_name,
                       (SELECT COUNT(*) FROM order_items oi WHERE oi.order_id = o.id) AS item_count
                  FROM orders o
             LEFT JOIN users u ON u.id = o.opened_by
                 WHERE o.tenant_id = ? AND o.status = 'open'
              ORDER BY o.created_at ASC, o.id ASC";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$tid]);
        return $stmt->fetchAll();
    }

    public function items(int $orderId): array
    {
        $tid = \TenantContext::tenantId();
        $stmt = $this->db->prepare('SELECT * FROM order_items WHERE order_id = ? AND tenant_id = ? AND quantity > 0 ORDER BY id ASC');
        $stmt->execute([$orderId, $tid]);
        return $stmt->fetchAll();
    }

    /** Line items for many orders at once, keyed by order_id — one query
     *  instead of one per row, for the sales list "Products" column. */
    public function itemsForMany(array $orderIds): array
    {
        $orderIds = array_values(array_unique(array_map('intval', $orderIds)));
        if (!$orderIds) { return []; }
        $tid = \TenantContext::tenantId();
        $in = implode(',', array_fill(0, count($orderIds), '?'));
        $stmt = $this->db->prepare(
            "SELECT order_id, product_name, quantity, line_total FROM order_items
              WHERE tenant_id = ? AND order_id IN ($in) AND quantity > 0 ORDER BY id ASC"
        );
        $stmt->execute(array_merge([$tid], $orderIds));
        $out = [];
        foreach ($stmt->fetchAll() as $r) {
            $out[(int) $r['order_id']][] = ['name' => $r['product_name'], 'qty' => (float) $r['quantity'], 'total' => (float) $r['line_total']];
        }
        return $out;
    }

    /** This customer's tabs (any status but void), newest first — matched on
     *  table_name, trimmed and case-insensitive since it's free text typed
     *  at checkout, not a real customer record. */
    public function forCustomer(string $name, int $limit = 200): array
    {
        $name = trim($name);
        if ($name === '') { return []; }
        $tid = \TenantContext::tenantId();
        $stmt = $this->db->prepare(
            "SELECT o.*, u.username AS staff_name
               FROM orders o
          LEFT JOIN users u ON u.id = o.opened_by
              WHERE o.tenant_id = ? AND o.status <> 'void' AND LOWER(TRIM(o.table_name)) = LOWER(?)
           ORDER BY o.created_at DESC, o.id DESC
              LIMIT " . (int) $limit
        );
        $stmt->execute([$tid, $name]);
        return $stmt->fetchAll();
    }

    /** Add/update a customer's contact info on an existing tab (e.g. before emailing them). */
    public function updateCustomerContact(int $orderId, ?string $email, ?string $phone): array
    {
        $tid = \TenantContext::tenantId();
        $email = $email !== null ? trim($email) : null;
        $phone = $phone !== null ? trim($phone) : null;
        if ($email !== null && $email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['ok' => false, 'error' => 'Enter a valid email address.'];
        }
        $stmt = $this->db->prepare('UPDATE orders SET customer_email = ?, customer_phone = ? WHERE id = ? AND tenant_id = ?');
        $stmt->execute([$email !== '' ? $email : null, $phone !== '' ? $phone : null, $orderId, $tid]);
        return ['ok' => true, 'error' => null];
    }

    public function markInvoiceSent(int $orderId): void
    {
        $tid = \TenantContext::tenantId();
        $this->db->prepare('UPDATE orders SET invoice_sent_at = NOW() WHERE id = ? AND tenant_id = ?')->execute([$orderId, $tid]);
    }

    public function markDeliveryNoteSent(int $orderId): void
    {
        $tid = \TenantContext::tenantId();
        $this->db->prepare('UPDATE orders SET delivery_note_sent_at = NOW() WHERE id = ? AND tenant_id = ?')->execute([$orderId, $tid]);
    }

    // ===== owner reporting: paid orders, shaped like SaleModel's rows ======

    /**
     * Paid tabs for a period, shaped with the same keys SaleModel::forTenant()
     * rows have (total, payment_method, cash_amount, mpesa_amount,
     * staff_name, sale_type, payment_status, discount_amount,
     * amount_paid, amount_due) so the owner's Sales page can merge the two
     * lists and reuse SaleModel's summarize()/staffBreakdown().
     * Filtered by paid_at (when the money actually came in), not created_at
     * (when the tab was opened) — a late-night tab shouldn't count against
     * the wrong day.
     */
    public function forTenant(int $limit = 1000, string $period = 'all', ?int $staffId = null): array
    {
        $tid = \TenantContext::tenantId();
        $periodSql = match ($period) {
            'today' => "AND DATE(o.paid_at) = CURDATE()",
            'week'  => "AND o.paid_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)",
            'month' => "AND o.paid_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)",
            default => '',
        };
        $staffSql = $staffId !== null ? "AND o.opened_by = :staff_id" : '';
        $stmt = $this->db->prepare(
            "SELECT o.*, u.username AS staff_name,
                    (SELECT COUNT(*) FROM order_items oi WHERE oi.order_id = o.id) AS item_count
               FROM orders o
          LEFT JOIN users u ON u.id = o.opened_by
              WHERE o.tenant_id = :tid AND o.status = 'paid' {$periodSql} {$staffSql}
           ORDER BY o.paid_at DESC, o.id DESC
              LIMIT :lim"
        );
        $stmt->bindValue(':tid', $tid, \PDO::PARAM_INT);
        $stmt->bindValue(':lim', $limit, \PDO::PARAM_INT);
        if ($staffId !== null) { $stmt->bindValue(':staff_id', $staffId, \PDO::PARAM_INT); }
        $stmt->execute();
        $rows = $stmt->fetchAll();

        foreach ($rows as &$r) {
            $r['created_at']       = $r['paid_at'];       // reporting date = when paid
            $r['customer_name']    = $r['table_name'];
            $r['sale_type']        = in_array($r['sale_type'] ?? 'retail', ['retail', 'wholesale'], true) ? $r['sale_type'] : 'retail';
            $r['payment_status']   = 'paid';               // only paid tabs are reported as sales
            $r['discount_amount']  = 0;
            $r['amount_due']       = 0;
            $r['amount_paid']      = $r['total'];
            $r['receipt_url']      = 'staff/orders/receipt.php?id=' . (int) $r['id'];
            $r['source']           = 'order';
        }
        unset($r);

        return $rows;
    }

    /**
     * Paid orders for one tenant on one exact Y-m-d date — CLI-safe (explicit
     * tenant id, no TenantContext), mirrors SaleModel::forTenantId(). Used by
     * the daily sales report (page, PDF, email cron).
     */
    public function forTenantOnDate(int $tenantId, string $date, ?int $staffId = null): array
    {
        $date = preg_replace('/[^0-9-]/', '', $date);
        $staffSql = $staffId !== null ? "AND o.opened_by = ?" : '';
        $stmt = $this->db->prepare(
            "SELECT o.*, u.username AS staff_name,
                    (SELECT COUNT(*) FROM order_items oi WHERE oi.order_id = o.id) AS item_count
               FROM orders o
          LEFT JOIN users u ON u.id = o.opened_by
              WHERE o.tenant_id = ? AND o.status = 'paid' AND DATE(o.paid_at) = ? {$staffSql}
           ORDER BY o.paid_at ASC, o.id ASC"
        );
        $params = [$tenantId, $date];
        if ($staffId !== null) { $params[] = $staffId; }
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        foreach ($rows as &$r) {
            $r['created_at']      = $r['paid_at'];
            $r['customer_name']   = $r['table_name'];
            $r['sale_type']       = in_array($r['sale_type'] ?? 'retail', ['retail', 'wholesale'], true) ? $r['sale_type'] : 'retail';
            $r['payment_status']  = 'paid';
            $r['discount_amount'] = 0;
            $r['amount_due']      = 0;
            $r['amount_paid']     = $r['total'];
            $r['receipt_url']     = 'staff/orders/receipt.php?id=' . (int) $r['id'];
            $r['source']          = 'order';
        }
        unset($r);

        return $rows;
    }

    /** Per-product revenue/qty from paid tabs on one exact date — CLI-safe. */
    public function productBreakdownOnDate(int $tenantId, string $date): array
    {
        $date = preg_replace('/[^0-9-]/', '', $date);
        $stmt = $this->db->prepare(
            "SELECT oi.product_name, SUM(oi.quantity) AS qty, SUM(oi.line_total) AS revenue,
                    MIN(o.paid_at) AS first_at, MAX(o.paid_at) AS last_at
               FROM order_items oi
               JOIN orders o ON o.id = oi.order_id AND o.tenant_id = oi.tenant_id
              WHERE oi.tenant_id = ? AND o.status = 'paid' AND DATE(o.paid_at) = ?
           GROUP BY oi.product_name
           ORDER BY revenue DESC"
        );
        $stmt->execute([$tenantId, $date]);
        return $stmt->fetchAll();
    }

    /** Per-product revenue/cost/profit from paid tabs — mirrors SaleModel::productProfit(). */
    public function productProfit(string $period = 'all', string $costCol = 'buying_price'): array
    {
        $tid = \TenantContext::tenantId();
        if ($tid === null) { return []; }

        $periodSql = match ($period) {
            'today' => "AND DATE(o.paid_at) = CURDATE()",
            'week'  => "AND o.paid_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)",
            'month' => "AND o.paid_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)",
            default => '',
        };

        $sql = "SELECT oi.product_id,
                       MAX(oi.product_name)                           AS product_name,
                       SUM(oi.quantity)                                AS qty,
                       SUM(oi.line_total)                              AS revenue,
                       SUM(oi.quantity * COALESCE(p.`{$costCol}`, 0))  AS cost
                  FROM order_items oi
                  JOIN orders o    ON o.id = oi.order_id AND o.tenant_id = oi.tenant_id
             LEFT JOIN products p  ON p.id = oi.product_id AND p.tenant_id = oi.tenant_id
                 WHERE oi.tenant_id = ? AND o.status = 'paid' {$periodSql}
              GROUP BY oi.product_id";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([$tid]);
        $rows = $stmt->fetchAll();

        foreach ($rows as &$r) {
            $rev  = round((float) $r['revenue'], 2);
            $cost = round((float) $r['cost'], 2);
            $r['product_id'] = (int) $r['product_id'];
            $r['unit']       = 'piece';
            $r['qty']        = (float) $r['qty'];
            $r['revenue']    = $rev;
            $r['cost']       = $cost;
            $r['profit']     = round($rev - $cost, 2);
            $r['margin']     = $rev > 0 ? round(($rev - $cost) / $rev * 100, 1) : 0.0;
        }
        unset($r);

        return $rows;
    }

    /** Per-product revenue/cost/profit from paid tabs on one exact date. */
    public function productProfitOnDate(string $date, string $costCol = 'buying_price'): array
    {
        $tid = \TenantContext::tenantId();
        if ($tid === null) { return []; }

        $date = preg_replace('/[^0-9-]/', '', $date) ?: date('Y-m-d');
        $sql = "SELECT oi.product_id,
                       MAX(oi.product_name)                           AS product_name,
                       SUM(oi.quantity)                                AS qty,
                       SUM(oi.line_total)                              AS revenue,
                       SUM(oi.quantity * COALESCE(p.`{$costCol}`, 0))  AS cost
                  FROM order_items oi
                  JOIN orders o    ON o.id = oi.order_id AND o.tenant_id = oi.tenant_id
             LEFT JOIN products p  ON p.id = oi.product_id AND p.tenant_id = oi.tenant_id
                 WHERE oi.tenant_id = ? AND o.status = 'paid' AND DATE(o.paid_at) = ?
              GROUP BY oi.product_id";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([$tid, $date]);
        $rows = $stmt->fetchAll();
        foreach ($rows as &$r) {
            $rev  = round((float) $r['revenue'], 2);
            $cost = round((float) $r['cost'], 2);
            $r['product_id'] = (int) $r['product_id'];
            $r['unit']       = 'piece';
            $r['qty']        = (float) $r['qty'];
            $r['revenue']    = $rev;
            $r['cost']       = $cost;
            $r['profit']     = round($rev - $cost, 2);
            $r['margin']     = $rev > 0 ? round(($rev - $cost) / $rev * 100, 1) : 0.0;
        }
        unset($r);

        return $rows;
    }

    private function ensurePricingSchema(): void
    {
        $this->ensureColumn('orders', 'sale_type', "ALTER TABLE orders ADD COLUMN sale_type ENUM('retail','wholesale') NOT NULL DEFAULT 'retail' AFTER channel");
        $this->ensureColumn('order_items', 'price_type', "ALTER TABLE order_items ADD COLUMN price_type ENUM('retail','wholesale') NOT NULL DEFAULT 'retail' AFTER unit_price");
        $this->ensureColumn('orders', 'customer_id', "ALTER TABLE orders ADD COLUMN customer_id INT NULL AFTER table_name");
        $this->ensureColumn('orders', 'customer_company', "ALTER TABLE orders ADD COLUMN customer_company VARCHAR(160) NULL AFTER customer_phone");
        $this->ensureColumn('orders', 'customer_location', "ALTER TABLE orders ADD COLUMN customer_location VARCHAR(160) NULL AFTER customer_company");
        $this->ensureColumn('orders', 'delivery_person', "ALTER TABLE orders ADD COLUMN delivery_person VARCHAR(120) NULL AFTER delivery_note_sent_at");
        $this->ensureColumn('orders', 'delivery_fee', "ALTER TABLE orders ADD COLUMN delivery_fee DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER delivery_person");
        $this->ensureColumn('orders', 'edit_count', "ALTER TABLE orders ADD COLUMN edit_count INT NOT NULL DEFAULT 0 AFTER status");
        $this->ensureColumn('orders', 'edited_at', "ALTER TABLE orders ADD COLUMN edited_at DATETIME NULL AFTER edit_count");
        $this->ensureColumn('orders', 'amount_paid', "ALTER TABLE orders ADD COLUMN amount_paid DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER total");
        $this->ensureTable('order_payments', "CREATE TABLE IF NOT EXISTS order_payments (
            id INT AUTO_INCREMENT PRIMARY KEY,
            tenant_id INT NOT NULL,
            order_id INT NOT NULL,
            amount DECIMAL(12,2) NOT NULL,
            method VARCHAR(10) NOT NULL,
            cash_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
            mpesa_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
            amount_tendered DECIMAL(12,2) NULL,
            change_due DECIMAL(12,2) NULL,
            received_by INT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_orderpay_order (tenant_id, order_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $this->ensureTable('order_returns', "CREATE TABLE IF NOT EXISTS order_returns (
            id INT AUTO_INCREMENT PRIMARY KEY,
            tenant_id INT NOT NULL,
            order_id INT NOT NULL,
            return_value DECIMAL(12,2) NOT NULL DEFAULT 0,
            refund_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
            refund_method VARCHAR(10) NULL,
            reason VARCHAR(255) NULL,
            processed_by INT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_orderret_order (tenant_id, order_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $this->ensureTable('order_return_items', "CREATE TABLE IF NOT EXISTS order_return_items (
            id INT AUTO_INCREMENT PRIMARY KEY,
            tenant_id INT NOT NULL,
            return_id INT NOT NULL,
            order_item_id INT NULL,
            product_id INT NULL,
            product_name VARCHAR(160) NOT NULL,
            unit_price DECIMAL(12,2) NOT NULL,
            quantity DECIMAL(12,2) NOT NULL,
            line_total DECIMAL(12,2) NOT NULL,
            KEY idx_orderretitem_return (tenant_id, return_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    private function ensureTable(string $table, string $sql): void
    {
        try {
            $stmt = $this->db->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?');
            $stmt->execute([$table]);
            if ((int) $stmt->fetchColumn() === 0) {
                $this->db->exec($sql);
            }
        } catch (\PDOException $ignored) {
        }
    }

    private function ensureColumn(string $table, string $column, string $sql): void
    {
        try {
            $stmt = $this->db->prepare("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?");
            $stmt->execute([$table, $column]);
            if ((int) $stmt->fetchColumn() > 0) {
                return;
            }
            $this->db->exec($sql);
        } catch (\PDOException $ignored) {
            // Setup/migration pages may construct models before the schema exists.
        }
    }
}
