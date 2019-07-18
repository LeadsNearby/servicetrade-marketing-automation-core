<?php

// Deny Direct Access
if ($direct_access !== true) {
    die('Direct Access Not Permitted');
}

date_default_timezone_set('US/East');

// Grab the Webhook from Ontraport
$webhook = $_POST;

if (!empty($webhook['appt_id'])) {

    $put_data = array(
        'released' => true,
    );

    $appt_response = $servicetrade_requests->put_request('appointment', $put_data, $webhook['appt_id']);

    logPrinter::debugLog($appt_response, LOG_DIR);

}