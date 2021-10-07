<?php

// Deny Direct Access
if ($direct_access !== true) {
    die('Direct Access Not Permitted');
}

date_default_timezone_set('US/East');

// Get the Incoming Data from the Service Trade Webhooks
$webhook = json_decode(file_get_contents('php://input'), true);

// Close Connection and Keep Running
intUtility::connClose();

// Loop Through Incoming WebHooks to Look for Certain Events
foreach ($webhook['data'] as $webhook_event) {

    $event_id = $webhook_event['entity']['id'];

    // On Certain Types of Events Process the Data and Do the Things
    switch ($webhook_event['entity']['type']) {

    /***** Process Job Webhooks *****/
    case 'job':

        // If It's a Job, look to see if it is a newly created or updated job.
        if ($webhook_event['action'] === 'created' || 'updated') {

            // Make Request to Jobs Endpoint to Get Data
            $job_response = $servicetrade_requests->get_request_single('job', $event_id);
            $job_response['request type'] = 'job';
            $packaged_job_data = ServiceTradeUtility::package_servicetrade_data($job_response);

            // Params for Contact Request
            $params = array(
                'locationId' => $job_response['data']['location']['id'],
            );

            // Make the Request to GET Contacts by Location ID
            $contact_response = $servicetrade_requests->get_request_single('contact', '?' . http_build_query($params));
            $contact_response['request type'] = 'contacts';

            // Get OP Contact Field Meta
            $op_meta = $op_api_requests->getContactMeta();

            foreach ($contact_response['data']['contacts'] as $contact) {

                // Exit if No Email Address
                if (filter_var($contact['email'], FILTER_VALIDATE_EMAIL) === false) {
                    continue;
                    //logPrinter::noEmailLog($job_response['data']['location']['id'], LOG_DIR);
                }

                // Package Job Data if the data contains Primary Contact Information
                $packaged_contact_data = ServiceTradeUtility::package_servicetrade_data($contact);

                // Get the Service Lines to Add to the Data
                $service_lines = @$servicetrade_requests->get_request_single('servicerecurrence?locationIds=' . $packaged_job_data['location id']);

                if (is_array($service_lines['data']['serviceRecurrences'])) {
                    foreach ($service_lines['data']['serviceRecurrences'] as $service_line) {
                        $packaged_job_data['service lines'] .= $service_line['serviceLine']['name'] . ', ';
                    }
                }

                $final_packaged_data = array_merge($packaged_job_data, $packaged_contact_data);

                // Package Data to Transfer to Ontraport
                $op_array = ServiceTradeUtility::package_op_contact_data($op_meta, $final_packaged_data);

                // Post Data to Ontraport
                if (!empty($op_array['email'])) {
                    $op_contact_response = $op_api_requests->ContactsPost($op_array);
                }

            }
        }

        break;

    /***** Process Invoice Webhooks *****/
    case 'invoice':
        if ($webhook_event['action'] === 'created' || $webhook_event['action'] === 'updated') {

            // Get Invoice Data from Service Trade
            $event_id = $webhook_event['entity']['id'];
            $invoice_response = $servicetrade_requests->get_request_single('invoice', $event_id);
            $invoice_response['request type'] = 'invoice';

            // Params for Contact Request
            $params = array(
                'locationId' => $invoice_response['data']['location']['id'],
            );

            // Make the Request to GET Contacts by Location ID
            $contact_response = $servicetrade_requests->get_request_single('contact', '?' . http_build_query($params));
            $contact_response['request type'] = 'contacts';

            foreach ($contact_response['data']['contacts'] as $contact) {

                // Skip if there is no email address
                if (filter_var($contact['email'], FILTER_VALIDATE_EMAIL) === false) {
                    continue;
                }

                // Skip if they are not one of the financial contacts
                // if (!stripos($contact['type'], (string) 'quote')
                //     || !stripos($contact['type'], (string) 'owner') !== false
                //     || !stripos($contact['type'], (string) 'financial') !== false) {
                //     continue;
                // }

                // Package the Invoice Data for the OP POST
                $packaged_invoice_data = ServiceTradeUtility::package_servicetrade_data($invoice_response);
                $packaged_contact_data = ServiceTradeUtility::package_servicetrade_data($contact);

                $op_meta = $op_api_requests->getContactMeta();

                // Package Data to Transfer to OP
                $op_contact_array = ServiceTradeUtility::package_op_contact_data($op_meta, $packaged_contact_data);

                // Post Data to Ontraport
                $op_contact_response = json_decode($op_api_requests->ContactsPost($op_contact_array), true);

                $op_invoice_array = ServiceTradeUtility::package_op_contact_data($op_meta, $packaged_invoice_data);
                $op_invoice_array['id'] = $op_contact_response['data']['attrs']['id'];

                $op_invoice_response = $op_api_requests->ContactsPut($op_invoice_array);

            }

        }
        break;

    /***** Process Quote Webhooks *****/
    case 'quote':

        // Process Data from newly created or updated Webhooks
        if ($webhook_event['action'] === 'created' || $webhook_event['action'] === 'updated') {

            $event_id = $webhook_event['entity']['id'];

            // Get Quote Data from Service Trade
            $quote_response = $servicetrade_requests->get_request_single('quote', $event_id);
            $quote_response['request type'] = 'quote';

            $params = array(
                'locationId' => $quote_response['data']['location']['id'],
            );

            // Make the Request to GET Contacts by Location ID
            $contact_response = $servicetrade_requests->get_request_single('contact', '?' . http_build_query($params));
            $contact_response['request type'] = 'contacts';

            $i = 0;

            foreach ($contact_response['data']['contacts'] as $contact) {

                if (filter_var($contact['email'], FILTER_VALIDATE_EMAIL) !== false) {

                    if (stripos($contact['type'], (string) 'quote') !== false
                        || stripos($contact['type'], (string) 'owner') !== false
                        || stripos($contact['type'], (string) 'financial') !== false) {

                        //$contact_search = json_decode($op_api_requests->contactSearch((string) $contact['email']), true);

                        // Package the Quote Data for the OP POST
                        $packaged_quote_data = ServiceTradeUtility::package_servicetrade_data($quote_response);
                        $package_contact_data = ServiceTradeUtility::package_servicetrade_data($contact);

                        // Get Ontraport Quote Field Meta
                        $op_quote_meta = $op_api_requests->getQuoteMeta();
                        $op_contact_meta = $op_api_requests->getContactMeta();

                        // Package Data to Transfer to OP
                        $op_contact_array = ServiceTradeUtility::package_op_contact_data($op_contact_meta, $package_contact_data);

                        // Post Data to Ontraport
                        $op_contact_response = json_decode($op_api_requests->ContactsPost($op_contact_array), true);

                        // Assign the OP ID of the Customer for Quote Attribution
                        // Then Package up the Data Again
                        $packaged_quote_data['customer'] = $op_contact_response['data']['attrs']['id'];
                        $op_quote_array = ServiceTradeUtility::package_op_contact_data($op_quote_meta, $packaged_quote_data);

                        // If New Quote, Create Quote Object in OP with POST
                        // Otherwise Update the Quote with the PUT request using the Contact ID from the Contacts Post Above
                        if (!array_search($contact['email'], $contact_cache)) {

                            if ($webhook_event['action'] === 'created') {
                                $op_quote_response = $op_api_requests->quotePost($op_quote_array);
                                sleep(1);

                            } elseif ($webhook_event['action'] === 'updated') {

                                // Search for Quote in Custom Object
                                $quote_search = json_decode($op_api_requests->quoteSearch((string) $packaged_quote_data['quote id']), true);

                                foreach ($quote_search['data'] as $quote) {
                                    if ($quote["f1660"] !== $op_contact_response['data']['attrs']['id']) {
                                        continue;
                                    } else {
                                        $op_quote_array['id'] = $quote['id'];
                                    }

                                }

                                // Make Quote PUT Request
                                $op_quote_response = $op_api_requests->quotePut($op_quote_array);

                                //var_dump($op_quote_response);
                            }
                        }

                        // Cache the contact to avoid duplicates
                        $contact_cache[$i] = $contact['email'];
                        $i++;
                    }
                }

            }
        }
        break;

    default:
        continue;

    }

}

?>