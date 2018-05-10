<?php
require_once get_template_directory().'/opternative-exam/s3/vendor/autoload.php';
use Aws\S3\S3Client;
use Aws\S3\Exception\S3Exception;
// fileupload(file_name,temp_name,path_to_upload_file,[optional - bucket_name])
function fileupload($name,$tmp,$folder,$bucket="test-sightsupply-to-quiet")
{
	//$ext = pathinfo($name,PATHINFO_EXTENSION);
	
	if(strlen($name) > 0)
	{
		//AWS access info
		if (!defined('awsAccessKey')) define('awsAccessKey', 'my-access-key');
		if (!defined('awsSecretKey')) define('awsSecretKey', 'my-secret-key');
		
		// Set Amazon s3 credentials
		$client = S3Client::factory(
			array(
				'version' => 'latest',//2017-11-24
				'region'  => 'us-east-1',
				'credentials' => array(
					'key' => awsAccessKey,
					'secret'  => awsSecretKey,
				)
			)
		);

		//$image_name_actual = date('YmdGis').'_'.mt_rand(000,999).".".$ext;
		
		try 
		{
			$client->putObject(array(
				'Bucket'=>$bucket,
				'Key' =>  $folder.$name,
				'SourceFile' => $tmp,
				'StorageClass' => 'STANDARD',
				'ContentType' => mime_content_type($tmp),
			));

			$file_message = "1";
			/*$s3file='http://'.$bucket.'.s3.amazonaws.com/'.$folder.$name;
			echo 'S3 File URL:'.$s3file;*/

		} 
		catch (S3Exception $e) 
		{
			// Catch an S3 specific exception.
			echo $e->getMessage();
		}
	}
	else
	{
		$file_message = "Please select file.";
	}
	
	return $file_message;
}
?>
