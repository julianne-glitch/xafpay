<?php
/**
 * XafPay Logger
 * Writes all important events to Render logs + local file
 */
function log_this($msg, $context = [])
{
    $time = gmdate('Y-m-d H:i:s');
    $prefix = "[XAFPAY] [$time] ";

    if (!empty($context)) {
        $msg .= " | context=" . json_encode($context);
    }

    // Appears in Render Logs
    error_log($prefix . $msg);

    // Optional: Write local file (Render ephemeral but useful for short debugging)
    try {
        file_put_contents(__DIR__ . "/../runtime.log", $prefix . $msg . "\n", FILE_APPEND);
    } catch (Exception $e) {
        /* ignore */
    }
}
