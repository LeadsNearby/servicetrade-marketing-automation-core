<?php

// Deny Direct Access
if ($direct_access !== true) {
    die('Direct Access Not Permitted');
}

date_default_timezone_set('US/East');

// Grab the Webhook from Ontraport
$webhook = $_POST;

if (!empty($webhook['Contact_Id']) && !empty($webhook['Quote_Id'])) {

// Random Sleep to Prevent all of the emails from going out at the exact same time
    sleep(rand(30, 120));

    $mandrill = new MaEmailer();

    // Package up the Data for the POST
    $params = array(
        'contactIds' => $webhook['Contact_Id'],
        'mode' => 'email',
        'template' => 'Quote',
        'send' => true,
        'params' => array(
            'quoteId' => (integer) $webhook['Quote_Id'],
            'subject' => 'Quote Reminder from ASA FIRE',
            'message' => '',
        ),
    );

    // Make the Request to Service Titan to send the Quote Message
    $messages_response = $servicetrade_requests->post_request('message', $params);

    // Log the Response if the Message Send Failed
    if ($message_response['data']['failureCount'] > 0) {
        logPrinter::debugLog($messages_response, LOG_DIR);
        $email_alert = $mandrill->ma_mandrill_send($messages_response, 'Quote Failure');
    }
}

?>