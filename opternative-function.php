<?php
require_once get_template_directory().'/opternative-exam/fileupload_function.php';
require_once get_template_directory().'/opternative-exam/sendmsg.php';

add_action( 'add_meta_boxes', 'ss_warehouse_meta_box_add' );
function ss_warehouse_meta_box_add()
{
    add_meta_box( 'warehouse_metabox', 'Warehouse Order Request send', 'ss_warehouse_metabox', array('shop_order'), 'normal', 'high' );
}
function ss_warehouse_metabox($post)
{
    $values = get_post_custom($post->ID);
    
    $selected = isset( $values['warehouse_request_status'] ) ? esc_attr( $values['warehouse_request_status'][0] ) : null;
    ?>
    <p>
        <label for="warehouse_status">Status </label>
        <select name="warehouse_status" id="warehouse_status">
            <option value="" <?php selected( $selected, '' );?>>Select Status</option>
            <option value="item_profile" <?php selected( $selected, 'item_profile' ); ?>>Item Profile</option>
            <option value="purchase_order" <?php selected( $selected, 'purchase_order' ); ?>>Purchase Order</option>
            <option value="purchase_order_cancel" <?php selected( $selected, 'purchase_order_cancel' ); ?>>Purchase Order Cancel</option>
            <option value="purchase_order_close" <?php selected( $selected, 'purchase_order_close' ); ?>>Purchase Order Close</option>
            <option value="shipment_order" <?php selected( $selected, 'shipment_order' ); ?>>Shipment Order</option>
            <option value="shipment_order_change" <?php selected( $selected, 'shipment_order_change' ); ?>>Shipment Order Change</option>
            <option value="shipment_order_cancel" <?php selected( $selected, 'shipment_order_cancel' ); ?>>Shipment Order Cancel</option>
            <option value="rma" <?php selected( $selected, 'rma' ); ?>>RMA</option>
        </select>
    </p>
    <?php    
}

add_action( 'save_post', 'ss_warehouse_meta_box_save',10,2 );
function ss_warehouse_meta_box_save($post_id, $post)
{
	
    switch($post->post_type) 
    {
        case 'shop_order':
            // Do stuff for post type 'shop_order'
            if(update_post_meta( $post_id, 'warehouse_request_status',$_POST['warehouse_status'] ))
            {
                $requestedStautus = $_POST['warehouse_status'];
                $orderId = $post_id;
                $order = new WC_Order( $orderId );
                
                $orderdate = $order->order_date;
                //$orderdate = date('c',strtotime($orderdate));
                //$total = $order->total.' '.$order->currency;
                $total = '30.00';
                
                $ClientID = esc_attr( get_option('ClientID') );
                $BusinessUnit = esc_attr( get_option('BusinessUnit') );
                $Warehouse = esc_attr( get_option('Warehouse') );
				$to_bucket = esc_attr( get_option('test_to_bucket') );
				$from_bucket = esc_attr( get_option('test_from_bucket') );
				$Carrier = get_post_meta( $orderId,'_aftership_tracking_provider_name',true);
                $trakingid = get_post_meta( $orderId,'_aftership_tracking_number',true);
				date_default_timezone_set('UTC');
				$t = microtime(true);
				$micro = sprintf("%06d",($t - floor($t)) * 1000000);
				$d = new DateTime( date('Y-m-d H:i:s.'.$micro, $t) );
				$orderdate=$d->format("Y-m-d\TH:i:s.u\Z");
								
				$queueUrl='https://queue.amazonaws.com/101817538215/test_sightsupply_to_quiet/';				
                foreach($order->get_items() as $item){
					$product_details = get_post($item['product_id'])->post_content; // I used wordpress built-in functions to get the product object 
					$product_id = $item['product_id'];
				}
				
                echo $requestedStautus;
                if($requestedStautus == 'item_profile'){
                
                    $UPCno = get_post_meta( $product_id, 'UPCno', true );
                    $barcode = get_post_meta( $product_id, 'barcode', true );
                        
                    $item_profile = '<?xml version="1.0" encoding="utf-8"?>
                    <ItemProfileDocument xmlns="http://schemas.quietlogistics.com/V2/ItemProfile.xsd" >
                        <ItemProfile ClientID="'.$ClientID.'" BusinessUnit="'.$BusinessUnit.'" ItemNo="'.$product_id.'" ItemDesc="'.$product_details.'" StockUOM="EA" WeightUOM="LB" 
                            UPCno="'.$UPCno.'" StockWgt="0" Warehouse="'.$Warehouse.'">
                            <UnitQuantity BarCode="'.$barcode.'" Quantity="1" UnitOfMeasure="EA" Weight="0"/>
                        </ItemProfile>
                    </ItemProfileDocument>';
                        
                    $itemprofilexmlname = 'SIGHTSUPPLY_ItemProfile_'.trim(money_format("%=0(#10.0n",$orderId)).'_'.date('Ymd').'_'.date('His').'.xml';
                    $itemprofileuploadFile = get_template_directory().'/opternative-exam/xmlfiles/'.$itemprofilexmlname;
                    
					$dom = new DOMDocument;
					$dom->preserveWhiteSpace = FALSE;
					$dom->loadXML($item_profile);
					$dom->save($itemprofileuploadFile);
                    //create item profile xml file
                    //file_put_contents( $itemprofileuploadFile, $item_profile );
					
                    //upload item profile xml in aws bucket
					$item_profile_result=fileupload($itemprofilexmlname,$itemprofileuploadFile,'',$to_bucket);
										
                    //$item_profile_result = upload_file($itemprofileuploadFile,$to_bucket);
                    if($item_profile_result == 1)
                    {
						$sqsmsg = [
							'MessageAttributes'=>[
								"ClientId" => [
									'DataType' => "String",
									'StringValue' => $ClientID
								],
								"BusinessUnit" => [
									'DataType' => "String",
									'StringValue' => $BusinessUnit
								],
								"DocumentName" => [
									'DataType' => "String",
									'StringValue' => $itemprofilexmlname
								],
								"DocumentType" => [
									'DataType' => "String",
									'StringValue' => "ItemProfile"
								],
								"Warehouse" => [
									'DataType' => "String",
									'StringValue' => $Warehouse
								]
							], 
							'MessageBody' =>'<EventMessage xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xmlns:xsd="http://www.w3.org/2001/XMLSchema" MessageId="'.GUID().'" MessageDate="'.$orderdate.'" DocumentType="ItemProfile" ClientId="'.$ClientID.'" BusinessUnit="'.$BusinessUnit.'" Warehouse="'.$Warehouse.'" DocumentName="'.$itemprofilexmlname.'" xmlns="http://schemas.quietlogistics.com/V2/EventMessage.xsd"><Extension /></EventMessage>',
							'QueueUrl' => $queueUrl
						];
						echo send_to_sqs($sqsmsg);
						//exit;	
						//remove xml file from local if file uploaded in bucket
                        unlink($itemprofileuploadFile);
                    }
                }
                elseif($requestedStautus == 'purchase_order'){
                    
                    $UPCno = get_post_meta( $product_id, 'UPCno', true );
                    $barcode = get_post_meta( $product_id, 'barcode', true );
                    
                    $purchase_order = '<?xml version="1.0" encoding="utf-8"?>
                        <PurchaseOrderMessage xmlns="http://schemas.quietlogistics.com/V2/PurchaseOrder.xsd" ClientID="'.$ClientID.'" BusinessUnit="'.$BusinessUnit.'">
                            <POHeader Carrier="'.$Carrier.'" ServiceLevel="" PoNumber="'.$orderId.'" OrderDate="'.$orderdate.'" PrimaryTrackingId="'.$trakingid.'" Comments="Purchase Order" Warehouse="'.$Warehouse.'">
                                <Vendor ID="'.$ClientID.'" Company="'.$ClientID.'"/>
                            </POHeader>
                            <PODetails Line="1" ItemNumber="'.$product_id.'" ItemDescription="'.$product_details.'" OrderQuantity="1" UnitCost="'.$total.'"></PODetails>
                        </PurchaseOrderMessage>';
						
                    $PurchaseOrderxmlname = 'SIGHTSUPPLY_PurchaseOrder_'.trim(money_format("%=0(#10.0n",$orderId)).'_'.date('Ymd').'_'.date('His').'.xml';
                    $PurchaseOrderuploadFile = get_template_directory().'/opternative-exam/xmlfiles/'.$PurchaseOrderxmlname;
                    $dom = new DOMDocument;
					$dom->preserveWhiteSpace = FALSE;
					$dom->loadXML($purchase_order);
					$dom->save($PurchaseOrderuploadFile);
                    //create purchase order xml file
                    //file_put_contents( $PurchaseOrderuploadFile, $purchase_order );
                    
                    //uplpad purchase order xml in aws bucket
					
					$purchase_order_result=fileupload($PurchaseOrderxmlname,$PurchaseOrderuploadFile,'',$to_bucket);
					
					//$purchase_order_result = upload_file($PurchaseOrderuploadFile,$to_bucket);
                    if($purchase_order_result == 1)
                    {
						$sqsmsg = [
							'MessageAttributes'=>[
								"ClientId" => [
									'DataType' => "String",
									'StringValue' => $ClientID
								],
								"BusinessUnit" => [
									'DataType' => "String",
									'StringValue' => $BusinessUnit
								],
								"DocumentName" => [
									'DataType' => "String",
									'StringValue' => $PurchaseOrderxmlname
								],
								"DocumentType" => [
									'DataType' => "String",
									'StringValue' => "PurchaseOrder"
								],
								"Warehouse" => [
									'DataType' => "String",
									'StringValue' => $Warehouse
								]
							], 
							'MessageBody' =>'<EventMessage xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xmlns:xsd="http://www.w3.org/2001/XMLSchema" MessageId="'.GUID().'" MessageDate="'.$orderdate.'" DocumentType="PurchaseOrder" ClientId="'.$ClientID.'" BusinessUnit="'.$BusinessUnit.'" Warehouse="'.$Warehouse.'" DocumentName="'.$PurchaseOrderxmlname.'" xmlns="http://schemas.quietlogistics.com/V2/EventMessage.xsd"><Extension /></EventMessage>',
							'QueueUrl' => $queueUrl
						];
						echo send_to_sqs($sqsmsg);
						//exit;
                        //if xml uploaded successfully then remove from bucket
                        unlink($PurchaseOrderuploadFile);
                    }
                }
                elseif($requestedStautus == 'purchase_order_cancel'){
                    //Order Cancelled
                    $ordercancel = '<?xml version="1.0" encoding="utf-8"?>
                        <PurchaseOrderCancel xmlns="http://schemas.quietlogistics.com/V2/PurchaseOrderCancel.xsd" 
                            ClientId="'.$ClientID.'" 
                            BusinessUnit="'.$BusinessUnit.'" 
                            PoNumber="'.$orderId.'"
                            Warehouse="'.$Warehouse.'">
                        </PurchaseOrderCancel>';
                        
                    $ordercancelxmlname = 'SIGHTSUPPLY_PurchaseOrderCancel_'.trim(money_format("%=0(#10.0n",$orderId)).'_'.date('Ymd').'_'.date('His').'.xml';
                    $ordercanceluploadFile = get_template_directory().'/opternative-exam/xmlfiles/'.$ordercancelxmlname;
                    
                    //create cancle order xml file
                    //file_put_contents( $ordercanceluploadFile, $ordercancel );
					$dom = new DOMDocument;
					$dom->preserveWhiteSpace = FALSE;
					$dom->loadXML($ordercancel);
					$dom->save($ordercanceluploadFile);
                    
                    //upload cancle order xml in aws bucket
					$cancel_order_result=fileupload($ordercancelxmlname,$ordercanceluploadFile,'',$to_bucket);
                    //$cancel_order_result = upload_file($ordercanceluploadFile,$to_bucket);
                    if($cancel_order_result == 1)
                    {
						$sqsmsg = [
							'MessageAttributes'=>[
								"ClientId" => [
									'DataType' => "String",
									'StringValue' => $ClientID
								],
								"BusinessUnit" => [
									'DataType' => "String",
									'StringValue' => $BusinessUnit
								],
								"DocumentName" => [
									'DataType' => "String",
									'StringValue' => $ordercancelxmlname
								],
								"DocumentType" => [
									'DataType' => "String",
									'StringValue' => "PurchaseOrderCancel"
								],
								"Warehouse" => [
									'DataType' => "String",
									'StringValue' => $Warehouse
								]
							], 
							'MessageBody' =>'<EventMessage xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xmlns:xsd="http://www.w3.org/2001/XMLSchema" MessageId="'.GUID().'" MessageDate="'.$orderdate.'" DocumentType="PurchaseOrderCancel" ClientId="'.$ClientID.'" BusinessUnit="'.$BusinessUnit.'" Warehouse="'.$Warehouse.'" DocumentName="'.$ordercancelxmlname.'" xmlns="http://schemas.quietlogistics.com/V2/EventMessage.xsd"><Extension /></EventMessage>',
							'QueueUrl' => $queueUrl
						];
						send_to_sqs($sqsmsg);

                        //remove xml file from local if file uploaded in bucket
                        unlink($ordercanceluploadFile);
                    }
                }
                elseif($requestedStautus == 'purchase_order_close'){
                    $orderclose = '<?xml version="1.0" encoding="utf-8"?>
                        <PurchaseOrderClose xmlns="http://schemas.quietlogistics.com/V2/PurchaseOrderClose.xsd"
                        ClientId="'.$ClientID.'" BusinessUnit="'.$BusinessUnit.'" PoNumber="'.$orderId.'" Warehouse="'.$Warehouse.'">
                        </PurchaseOrderClose>';
                        
                    $orderclosexmlname = 'SIGHTSUPPLY_PurchaseOrderClose_'.trim(money_format("%=0(#10.0n",$orderId)).'_'.date('Ymd').'_'.date('His').'.xml';
                    $ordercloseuploadFile = get_template_directory().'/opternative-exam/xmlfiles/'.$orderclosexmlname;
                    
                    //create close order xml file
                    //file_put_contents( $ordercloseuploadFile, $orderclose );
					$dom = new DOMDocument;
					$dom->preserveWhiteSpace = FALSE;
					$dom->loadXML($orderclose);
					$dom->save($ordercloseuploadFile);
                    
                    //upload close order xml in aws bucket
					$close_purchase_result=fileupload($orderclosexmlname,$ordercloseuploadFile,'',$to_bucket);
                    //$close_purchase_result = upload_file($ordercloseuploadFile,$to_bucket);
                    if($close_purchase_result == 1)
                    {
                        $sqsmsg = [
							'MessageAttributes'=>[
								"ClientId" => [
									'DataType' => "String",
									'StringValue' => $ClientID
								],
								"BusinessUnit" => [
									'DataType' => "String",
									'StringValue' => $BusinessUnit
								],
								"DocumentName" => [
									'DataType' => "String",
									'StringValue' => $orderclosexmlname
								],
								"DocumentType" => [
									'DataType' => "String",
									'StringValue' => "PurchaseOrderClose"
								],
								"Warehouse" => [
									'DataType' => "String",
									'StringValue' => $Warehouse
								]
							], 
							'MessageBody' =>'<EventMessage xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xmlns:xsd="http://www.w3.org/2001/XMLSchema" MessageId="'.GUID().'" MessageDate="'.$orderdate.'" DocumentType="PurchaseOrderClose" ClientId="'.$ClientID.'" BusinessUnit="'.$BusinessUnit.'" Warehouse="'.$Warehouse.'" DocumentName="'.$orderclosexmlname.'" xmlns="http://schemas.quietlogistics.com/V2/EventMessage.xsd"><Extension /></EventMessage>',
							'QueueUrl' => $queueUrl
						];
						send_to_sqs($sqsmsg);
                        //remove xml file from local if file uploaded in bucket
                        unlink($ordercloseuploadFile);
                    }
                }
                elseif($requestedStautus == 'shipment_order'){
                    
                    $billing_fitst_name = get_post_meta($post->ID,'_billing_first_name',true);
                    $billing_last_name = get_post_meta($post->ID,'_billing_last_name',true);
                    $billing_company = get_post_meta($post->ID,'_billing_company',true);
                    $billing_address_1 = get_post_meta($post->ID,'_billing_address_1',true);
                    $billing_address_2 = get_post_meta($post->ID,'_billing_address_2',true);
                    $billing_city = get_post_meta($post->ID,'_billing_city',true);
                    $billing_state = get_post_meta($post->ID,'_billing_state',true);
                    $billing_postcode = get_post_meta($post->ID,'_billing_postcode',true);
                    $billing_country = get_post_meta($post->ID,'_billing_country',true);
                    $billing_email = get_post_meta($post->ID,'_billing_email',true);
                    
                    $shipping_fitst_name = get_post_meta($post->ID,'_shipping_first_name',true);
                    $shipping_last_name = get_post_meta($post->ID,'_shipping_last_name',true);
                    $shipping_company = get_post_meta($post->ID,'_shipping_company',true);
                    $shipping_address_1 = get_post_meta($post->ID,'_shipping_address_1',true);
                    $shipping_address_2 = get_post_meta($post->ID,'_shipping_address_2',true);
                    $shipping_city = get_post_meta($post->ID,'_shipping_city',true);
                    $shipping_state = get_post_meta($post->ID,'_shipping_state',true);
                    $shipping_postcode = get_post_meta($post->ID,'_shipping_postcode',true);
                    $shipping_country = get_post_meta($post->ID,'_shipping_country',true);
                    
                    $shipment_order = '<?xml version="1.0" encoding="utf-8"?>
                        <ShipOrderDocument xmlns="http://schemas.quietlogistics.com/V2/ShipmentOrder.xsd" >
                            <ClientID>'.$ClientID.'</ClientID>
                            <BusinessUnit>'.$BusinessUnit.'</BusinessUnit>
                            <OrderHeader OrderNumber="'.$orderId.'" OrderType="SO" OrderDate="'.$orderdate.'" ShipDate="'.date('c').'" Warehouse="'.$Warehouse.'">
                                <Comments>Shipment Order</Comments>
                                <ShipMode Carrier="'.$Carrier.'"/>
                                <ShipTo Company="'.$shipping_company.'" Contact="'.$shipping_fitst_name.' '.$shipping_last_name.'" Address1="'.$shipping_address_1.' '.$shipping_address_2.'" City="'.$shipping_city.'" State="'.$shipping_state.'"
                                    PostalCode="'.$shipping_postcode.'" Country="'.$shipping_country.'" Email="'.$billing_email.'" />
                                <BillTo Company="'.$billing_company.'" Contact="'.$billing_fitst_name.' '.$billing_last_name.'" Address1="'.$billing_address_1.' '.$billing_address_2.'" City="'.$billing_city.'" State="'.$billing_state.'"
                                    PostalCode="'.$billing_postcode.'" Country="'.$billing_country.'" Email="'.$billing_email.'" />
                                <DeclaredValue>'.$total.'</DeclaredValue>
                            </OrderHeader>
                            <OrderDetails ItemNumber="'.$product_id.'" Line="1" QuantityOrdered="1" QuantityToShip="1" UOM="EA"
                                Price="'.$total.'"/>
                        </ShipOrderDocument>';
                        
                    $shipment_orderxmlname = 'SIGHTSUPPLY_ShipmentOrder_'.trim(money_format("%=0(#10.0n",$orderId)).'_'.date('Ymd').'_'.date('His').'.xml';
                    $shipment_orderuploadFile = get_template_directory().'/opternative-exam/xmlfiles/'.$shipment_orderxmlname;
                    
                    //create shipment order xml file
                    //file_put_contents( $shipment_orderuploadFile, $shipment_order );
					$dom = new DOMDocument;
					$dom->preserveWhiteSpace = FALSE;
					$dom->loadXML($shipment_order);
					$dom->save($shipment_orderuploadFile);
                    
                    //upload shipment order xml in aws bucket
					$shipment_order_result=fileupload($shipment_orderxmlname,$shipment_orderuploadFile,'',$to_bucket);
                    //$shipment_order_result = upload_file($shipment_orderuploadFile,$to_bucket);
                    if($shipment_order_result == 1)
                    {
						$sqsmsg = [
							'MessageAttributes'=>[
								"ClientId" => [
									'DataType' => "String",
									'StringValue' => $ClientID
								],
								"BusinessUnit" => [
									'DataType' => "String",

									'StringValue' => $BusinessUnit
								],
								"DocumentName" => [
									'DataType' => "String",
									'StringValue' => $shipment_orderxmlname
								],
								"DocumentType" => [
									'DataType' => "String",
									'StringValue' => "ShipmentOrder"
								],
								"Warehouse" => [
									'DataType' => "String",
									'StringValue' => $Warehouse
								]
							], 
							'MessageBody' =>'<EventMessage xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xmlns:xsd="http://www.w3.org/2001/XMLSchema" MessageId="'.GUID().'" MessageDate="'.$orderdate.'" DocumentType="ShipmentOrder" ClientId="'.$ClientID.'" BusinessUnit="'.$BusinessUnit.'" Warehouse="'.$Warehouse.'" DocumentName="'.$shipment_orderxmlname.'" xmlns="http://schemas.quietlogistics.com/V2/EventMessage.xsd"><Extension /></EventMessage>',
							'QueueUrl' => $queueUrl
						];
						send_to_sqs($sqsmsg);
                        //remove xml file from local if file uploaded in bucket
                        unlink($shipment_orderuploadFile);
                    }
                }
                elseif($requestedStautus == 'shipment_order_change'){
                    
                    $billing_fitst_name = get_post_meta($post->ID,'_billing_first_name',true);
                    $billing_last_name = get_post_meta($post->ID,'_billing_last_name',true);
                    $billing_company = get_post_meta($post->ID,'_billing_company',true);
                    $billing_address_1 = get_post_meta($post->ID,'_billing_address_1',true);
                    $billing_address_2 = get_post_meta($post->ID,'_billing_address_2',true);
                    $billing_city = get_post_meta($post->ID,'_billing_city',true);
                    $billing_state = get_post_meta($post->ID,'_billing_state',true);
                    $billing_postcode = get_post_meta($post->ID,'_billing_postcode',true);
                    $billing_country = get_post_meta($post->ID,'_billing_country',true);
                    $billing_email = get_post_meta($post->ID,'_billing_email',true);
                    $billing_phone = get_post_meta($post->ID,'_billing_phone',true);
                    
                    $shipping_fitst_name = get_post_meta($post->ID,'_shipping_first_name',true);
                    $shipping_last_name = get_post_meta($post->ID,'_shipping_last_name',true);
                    $shipping_company = get_post_meta($post->ID,'_shipping_company',true);
                    $shipping_address_1 = get_post_meta($post->ID,'_shipping_address_1',true);
                    $shipping_address_2 = get_post_meta($post->ID,'_shipping_address_2',true);
                    $shipping_city = get_post_meta($post->ID,'_shipping_city',true);
                    $shipping_state = get_post_meta($post->ID,'_shipping_state',true);
                    $shipping_postcode = get_post_meta($post->ID,'_shipping_postcode',true);
                    $shipping_country = get_post_meta($post->ID,'_shipping_country',true);
                    
                    $shipment_order_change = '<?xml version="1.0" encoding="utf-8"?>
                        <ShipOrderChangeDocument xmlns="http://schemas.quietlogistics.com/V2/ShipmentOrderChange.xsd">
                            <ClientID>'.$ClientID.'</ClientID>
                            <BusinessUnit>'.$BusinessUnit.'</BusinessUnit>
                            <OrderHeader OrderNumber="'.$orderId.'" Warehouse="'.$Warehouse.'">
                                <ShipTo Company="'.$shipping_company.'" Contact="'.$shipping_fitst_name.' '.$shipping_last_name.'" Address1="'.$shipping_address_1.'" Address2="'.$shipping_address_2.'" City="'.$shipping_city.'"
                                    State="'.$shipping_state.'" PostalCode="'.$shipping_postcode.'" Country="'.$shipping_country.'" Email="'.$billing_email.'" Phone="'.$billing_phone.'"/>
                                <BillTo Company="'.$billing_company.'" Contact="'.$billing_fitst_name.' '.$billing_last_name.'" Address1="'.$billing_address_1.'" Address2="'.$billing_address_2.'" City="'.$billing_city.'"
                                    State="'.$billing_state.'" PostalCode="'.$billing_postcode.'" Country="'.$billing_country.'" Email="'.$billing_email.'" Phone="'.$billing_phone.'"/>
                                <ShipMode Carrier="'.$Carrier.'"/>
                                <Comments>Shipment order Change</Comments>
                            </OrderHeader>
                        </ShipOrderChangeDocument>';
                        
                    $shipment_order_changexmlname = 'SIGHTSUPPLY_ShipmentOrderChange_'.trim(money_format("%=0(#10.0n",$orderId)).'_'.date('Ymd').'_'.date('His').'.xml';
                    $shipment_order_changeuploadFile = get_template_directory().'/opternative-exam/xmlfiles/'.$shipment_order_changexmlname;
                    
                    //create shipment order change xml file
                    //file_put_contents( $shipment_order_changeuploadFile, $shipment_order_change );
					$dom = new DOMDocument;
					$dom->preserveWhiteSpace = FALSE;
					$dom->loadXML($shipment_order_change);
					$dom->save($shipment_order_changeuploadFile);
                    
                    //upload shipment order change xml in aws bucket
					$shipment_order_change_result=fileupload($shipment_order_changexmlname,$shipment_order_changeuploadFile,'',$to_bucket);
                    //$shipment_order_change_result = upload_file($shipment_order_changeuploadFile,$to_bucket);
                    if($shipment_order_change_result == 1)
                    {
						$sqsmsg = [
							'MessageAttributes'=>[
								"ClientId" => [
									'DataType' => "String",
									'StringValue' => $ClientID
								],
								"BusinessUnit" => [
									'DataType' => "String",
									'StringValue' => $BusinessUnit
								],
								"DocumentName" => [
									'DataType' => "String",
									'StringValue' => $shipment_order_changexmlname
								],
								"DocumentType" => [
									'DataType' => "String",
									'StringValue' => "ShipmentOrderChange"
								],
								"Warehouse" => [
									'DataType' => "String",
									'StringValue' => $Warehouse
								]
							], 
							'MessageBody' =>'<EventMessage xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xmlns:xsd="http://www.w3.org/2001/XMLSchema" MessageId="'.GUID().'" MessageDate="'.$orderdate.'" DocumentType="ShipmentOrderChange" ClientId="'.$ClientID.'" BusinessUnit="'.$BusinessUnit.'" Warehouse="'.$Warehouse.'" DocumentName="'.$shipment_order_changexmlname.'" xmlns="http://schemas.quietlogistics.com/V2/EventMessage.xsd"><Extension /></EventMessage>',
							'QueueUrl' => $queueUrl
						];
						send_to_sqs($sqsmsg);
                        //remove xml file from local if file uploaded in bucket
                        unlink($shipment_order_changeuploadFile);
                    }
                }
                elseif($requestedStautus == 'shipment_order_cancel'){
                    //Shipment order cancelled
                    $shipmentordercancel = '<?xml version="1.0" encoding="utf-8"?>
                        <ShipmentOrderCancel xmlns="http://schemas.quietlogistics.com/V2/ShipmentOrderCancel.xsd"
                            ClientId="'.$ClientID.'" BusinessUnit="'.$BusinessUnit.'" OrderNumber="'.$orderId.'" Warehouse="'.$Warehouse.'">
                        </ShipmentOrderCancel>';
                    
                    $shipmentordercancelxmlname = 'SIGHTSUPPLY_ShipmentOrderCancel_'.trim(money_format("%=0(#10.0n",$orderId)).'_'.date('Ymd').'_'.date('His').'.xml';
                    $shipmentordercanceluploadFile = get_template_directory().'/opternative-exam/xmlfiles/'.$shipmentordercancelxmlname;
                    
                    //create shipment cancle order xml file
                    //file_put_contents( $shipmentordercanceluploadFile, $shipmentordercancel );
					$dom = new DOMDocument;
					$dom->preserveWhiteSpace = FALSE;
					$dom->loadXML($shipmentordercancel);
					$dom->save($shipmentordercanceluploadFile);
                    
                    //upload shipment cancle order xml in aws bucket
					$shipmentcancel_order_result=fileupload($shipmentordercancelxmlname,$shipmentordercanceluploadFile,'',$to_bucket);
                    //$shipmentcancel_order_result = upload_file($shipmentordercanceluploadFile,$to_bucket);
                    if($shipmentcancel_order_result == 1)
                    {
						$sqsmsg = [
							'MessageAttributes'=>[
								"ClientId" => [
									'DataType' => "String",
									'StringValue' => $ClientID
								],
								"BusinessUnit" => [
									'DataType' => "String",
									'StringValue' => $BusinessUnit
								],
								"DocumentName" => [
									'DataType' => "String",
									'StringValue' => $shipmentordercancelxmlname
								],
								"DocumentType" => [
									'DataType' => "String",
									'StringValue' => "ShipmentOrderCancel"
								],
								"Warehouse" => [
									'DataType' => "String",
									'StringValue' => $Warehouse
								]
							], 
							'MessageBody' =>'<EventMessage xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xmlns:xsd="http://www.w3.org/2001/XMLSchema" MessageId="'.GUID().'" MessageDate="'.$orderdate.'" DocumentType="ShipmentOrderCancel" ClientId="'.$ClientID.'" BusinessUnit="'.$BusinessUnit.'" Warehouse="'.$Warehouse.'" DocumentName="'.$shipmentordercancelxmlname.'" xmlns="http://schemas.quietlogistics.com/V2/EventMessage.xsd"><Extension /></EventMessage>',
							'QueueUrl' => $queueUrl
						];
						send_to_sqs($sqsmsg);
                        //remove xml file from local if file uploaded in bucket
                        unlink($shipmentordercanceluploadFile);
                    }
                }
                elseif($requestedStautus == 'rma'){
                    $rma_request_id = get_post_meta($orderId,'_ywcars_requests',true);
                    
                    global $wpdb;
                    $tablename = $wpdb->prefix.'ywcars_messages';
                    $ReturnReason = $wpdb->get_results("select message from $tablename where request = '".$rma_request_id[0]."' and 
                    author = '".get_post_meta($post->ID,'_customer_user',true)."'",ARRAY_A);
                    
                    $rma = '<?xml version="1.0" encoding="utf-8"?>
                        <RMADocument xmlns="http://schemas.quietlogistics.com/V2/RMADocument.xsd">
                             <RMA ClientID="'.$ClientID.'" BusinessUnit="'.$BusinessUnit.'" RMANumber="'.$rma_request_id[0].'" Warehouse="'.$Warehouse.'" TrackingNumber="'.$trakingid.'">
                             <Line LineNo="1" OrderNumber="'.$orderId.'" ItemNumber="'.$product_id.'" Quantity="1" SaleUOM="EA"
                                ReturnReason="'.esc_attr($ReturnReason[0]['message']).'" CustomerComment="" />
                             </RMA>
                        </RMADocument>';
                        
                    $rmaxmlname = 'SIGHTSUPPLY_RMA_'.trim(money_format("%=0(#10.0n",$orderId)).'_'.date('Ymd').'_'.date('His').'.xml';
                    $rmauploadFile = get_template_directory().'/opternative-exam/xmlfiles/'.$rmaxmlname;
                    
                    //create close order xml file
                    //file_put_contents( $rmauploadFile, $rma );
					$dom = new DOMDocument;
					$dom->preserveWhiteSpace = FALSE;
					$dom->loadXML($rma);
					$dom->save($rmauploadFile);
					
                    //upload close order xml in aws bucket
					$rma_result=fileupload($rmaxmlname,$rmauploadFile,'',$to_bucket);
                    //$rma_result = upload_file($rmauploadFile,$to_bucket);
                    if($rma_result == 1)
                    {
                        $sqsmsg = [
							'MessageAttributes'=>[
								"ClientId" => [
									'DataType' => "String",
									'StringValue' => $ClientID
								],
								"BusinessUnit" => [
									'DataType' => "String",
									'StringValue' => $BusinessUnit
								],
								"DocumentName" => [
									'DataType' => "String",
									'StringValue' => $rmaxmlname
								],
								"DocumentType" => [
									'DataType' => "String",
									'StringValue' => "RMA"
								],
								"Warehouse" => [
									'DataType' => "String",
									'StringValue' => $Warehouse
								]
							], 
							'MessageBody' =>'<EventMessage xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xmlns:xsd="http://www.w3.org/2001/XMLSchema" MessageId="'.GUID().'" MessageDate="'.$orderdate.'" DocumentType="RMA" ClientId="'.$ClientID.'" BusinessUnit="'.$BusinessUnit.'" Warehouse="'.$Warehouse.'" DocumentName="'.$rmaxmlname.'" xmlns="http://schemas.quietlogistics.com/V2/EventMessage.xsd"><Extension /></EventMessage>',
							'QueueUrl' => $queueUrl
						];
						send_to_sqs($sqsmsg);
                        //remove xml file from local if file uploaded in bucket
                        unlink($rmauploadFile);
                    }
                }
            }
            break;
        default:
            return;
    }
}

function GUID()
{
    if (function_exists('com_create_guid') === true)
    {
        return trim(com_create_guid(), '{}');
    }

    return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x', mt_rand(0, 65535), mt_rand(0, 65535), mt_rand(0, 65535), mt_rand(16384, 20479), mt_rand(32768, 49151), mt_rand(0, 65535), mt_rand(0, 65535), mt_rand(0, 65535));
}