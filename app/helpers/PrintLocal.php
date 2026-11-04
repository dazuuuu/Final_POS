<?php
// app/helpers/PrintLocal.php
// Direct Wi-Fi printing (raw port 9100) only works when this server runs on a
// computer inside the shop. Online, shops print to Wi-Fi printers through the
// phone / computer's own printing (the printer is added to the device once).

class PrintLocal
{
    /** Is this server on a local network (localhost or a private address)? */
    public static function server(): bool
    {
        $host = strtolower((string) ($_SERVER['SERVER_NAME'] ?? ''));
        if (in_array($host, ['localhost', '127.0.0.1'], true) || str_ends_with($host, '.local')) return true;
        $ip = (string) ($_SERVER['SERVER_ADDR'] ?? '');
        $isIp = filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4);
        $check = $isIp ? $host : $ip;
        return $isIp && filter_var($check, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)
            && !filter_var($check, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE);
    }
}
