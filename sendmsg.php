<?php
require_once 's3/vendor/autoload.php';
use Aws\Sqs\SqsClient;
use Aws\Exception\AwsException;

//A function to send message to SQS queue
function send_to_sqs($params){
    //Connect to SQS
	$client = SqsClient::factory(array(	    
	    'credentials' => array (
	                'key'    => 'AKIAIQO3SCQQK5QY2ZGQ', //use your AWS key here
	                'secret' => 'ziBRZyue4aCJzliMEv1oPva3BYikRMNcL/WCmmrc' //use your AWS secret here
	    ),
	    'region'  => 'us-east-1', //replace it with your region
	    'version' => '2012-11-05'
	));

	try {
		$result = $client->sendMessage($params);
		if($result){
			return 1;	
		}		
	} 
	catch (AwsException $e) {
		// output error message if fails
		print_r($e->getMessage());
		error_log($e->getMessage());
	}
}
?>