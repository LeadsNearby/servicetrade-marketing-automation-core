<?php

// Deny Direct Access
if ($direct_access !== true) {
    die('Direct Access Not Permitted');
}

date_default_timezone_set('US/East');

// Get the Incoming Data from the Service Trade Webhooks
$webhook = json_decode(file_get_contents('php://input'), true);

// Close Connection and Keep Running
//intUtility::connClose();

// Loop Through Incoming WebHooks to Look for Certain Events
foreach ($webhook['data'] as $webhook_event) {

    $event_id = $webhook_event['entity']['id'];

    // On Certain Types of Events Process the Data and Do Things
    switch ($webhook_event['entity']['type']) {

    /***** Process Job Webhooks *****/
    case 'job':

        // If It's a Job, look to see if it is a newly created or updated job.
        if ($webhook_event['action'] === 'created' || 'updated') {

            // Make Request to Jobs Endpoint to Get Data
            $job_response = $servicetrade_requests->get_request_single('job', $event_id);
            $job_response['request type'] = 'job';

            // Exit if No Email Address
            if (filter_var($job_response['data']['location']['primaryContact']['email'], FILTER_VALIDATE_EMAIL) === false) {
                exit('No Email Address');
                logPrinter::noEmailLog($job_response['data']['location']['id'], LOG_DIR);
            }

            // Package Job Data if the data contains Primary Contact Information
            if (!empty($job_response['data']['location']['primaryContact'])) {
                $packaged_job_data = ServiceTradeUtility::package_servicetrade_data($job_response);
            } else {
                exit('No Primary Contact');
                logPrinter::noEmailLog($job_response['data']['location']['id'], LOG_DIR);
            }

            // Get the Service Lines to Add to the Data
            $service_lines = @$servicetrade_requests->get_request_single('servicerecurrence?locationIds=' . $packaged_job_data['location id']);

            if (is_array($service_lines['data']['serviceRecurrences'])) {
                foreach ($service_lines['data']['serviceRecurrences'] as $service_line) {
                    $packaged_job_data['service lines'] .= $service_line['serviceLine']['name'] . ', ';
                }
            }

            // Get Ontraport Field Meta
            $op_meta = $op_api_requests->getContactMeta();

            // Package Data to Transfer to Ontraport
            $op_array = ServiceTradeUtility::package_op_contact_data($op_meta, $packaged_job_data);

            // Post Data to Ontraport
            if (!empty($op_array['email'])) {
                $op_contact_response = $op_api_requests->ContactsPost($op_array);
            }

        }

        break;

    case 'appointment':

        break;

    /***** Process Quote Webhooks *****/
    case 'quote':

        // Process Data from newly created or updated Webhooks
        if ($webhook_event['action'] === 'created' || $webhook_event['action'] === 'updated') {

            $event_id = $webhook_event['entity']['id'];

            // Get Quote Data from Service Trade
            $quote_response = $servicetrade_requests->get_request_single('quote', $event_id);
            $quote_response['request type'] = 'quote';

            // Exit if No Email Address Log the Location ID to
            // Log the Location ID to the No Email Log for Troubleshooting Later
            if (filter_var($quote_response['data']['location']['primaryContact']['email'], FILTER_VALIDATE_EMAIL) === false) {
                exit('No Email Address');
                logPrinter::noEmailLog($quote_response['data']['location']['id'], LOG_DIR);
            }

            // Package the Quote Data for the OP Contact POST
            if (!empty($quote_response['data']['location']['primaryContact'])) {
                $packaged_quote_data = ServiceTradeUtility::package_servicetrade_data($quote_response);
            } else {
                exit('No Primary Contact');
                logPrinter::noEmailLog($quote_response['data']['location']['id'], LOG_DIR);
            }

            // Get Ontraport Quote Field Meta
            $op_quote_meta = $op_api_requests->getQuoteMeta();
            $op_contact_meta = $op_api_requests->getContactMeta();

            // Package Data to Transfer to OP
            $op_contact_array = ServiceTradeUtility::package_op_contact_data($op_contact_meta, $packaged_quote_data);

            // Post Data to Ontraport
            $op_contact_response = json_decode($op_api_requests->ContactsPost($op_contact_array), true);

            //Assign the OP ID of the Customer for Quote Attribution
            // Then Package up the Data Again
            $packaged_quote_data['customer'] = $op_contact_response['data']['attrs']['id'];
            $op_quote_array = ServiceTradeUtility::package_op_contact_data($op_quote_meta, $packaged_quote_data);

            // If New Quote, Create Quote Object in OP with POST
            // Otherwise Update the Quote with the PUT request using the Contact ID from the Contacts Post Above */
            if ($webhook_event['action'] === 'created') {
                $op_quote_response = $op_api_requests->quotePost($op_quote_array);
                sleep(1);

            } else {
                // Search for Quote in Custom Object
                $quote_search = json_decode($op_api_requests->quoteSearch((string) $packaged_quote_data['quote id']), true);

                if ($quote_search) {
                    $op_quote_array['id'] = $quote_search['data'][0]['id'];
                }

                // Make Quote PUT Request
                $op_quote_response = $op_api_requests->quotePut($op_quote_array);
            }
        }

        break;

    default:
        continue;
    }

}

?>