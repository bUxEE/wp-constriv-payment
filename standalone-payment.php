<?php
define('CONSTRIV_MD5', 'consTriVmD5');

add_action( 'wp_ajax_constriv_standalone_payment_init', 'constriv_standalone_payment_init' );
add_action( 'wp_ajax_nopriv_constriv_standalone_payment_init', 'constriv_standalone_payment_init' );
function constriv_standalone_payment_init() {
	$fields = ["first_name","last_name","address","country","city","phone","email","amount"];
	parse_str($_POST["data"], $postData);
	
	$allset = true;
	foreach ($fields as $field) {
		if(empty($postData[$field])) {
			$allset = false;
			break;
		} else {
			if($field == "amount") {
				$data[$field] = str_replace(['€',' ','.'], '', $postData[$field]);
				$data[$field] = str_replace(',', '.', $data[$field]);
			} else {
				$data[$field] = $postData[$field];
			}
		}
	}
	if($postData["constriv_standalone_payment"] !== "true") {
		$allset = false;
	}

	if($allset) {
		$BooktimaStandalonePayment = new BooktimaStandalonePayment($data);
	}
}

add_action( 'wp_footer', 'constriv_standalone_payment_js', 99);
function constriv_standalone_payment_js() {
	?>
	<script>
		jQuery(document).ready(function($) {
			$('#constrivStandalonePaymentForm button[type="submit"]').click(function(e) {
				e.preventDefault();
				var form 	= $(this).closest('form');
				var fields 	= form.find('input');
				var valid   = true;
				$(fields).each(function(index,el) {
					if($(el).val().length <= 0) {
						valid = false;
						return false;
					} 	
				});

				if(!valid) {
					alertify.error("All fields must be filled");
					return;
				}

				pageBlockUI();
				$.post(
					BooktimaAjaxUrl,
					{ 
						action: "constriv_standalone_payment_init",
						data: form.serialize()
					}, // Send our PHP function
					function(response){
						response = JSON.parse(response);
						if(response.error.length > 0) {
							removePageBlockUI();
							alertify.error("Gateway error");
						} else {
							window.location.href = response.paymentPage + '?paymentId=' + response.paymentId;
						}
					}
				);
			})
		});
	</script>
	<?php
}

add_action('plugins_loaded', 'constriv_standalone_payment', 1);
function constriv_standalone_payment() {

	if(!class_exists('e24PaymentPipe')) {
		require_once('payment/e24PaymentPipe.inc.php');
	}

	/**
	 * Standalone CONSTRIV payment
	 */
	class BooktimaStandalonePayment
	{

		function __construct($data)
		{
			global $constriv_options;

			$this->payment_id 			= mb_substr(uniqid(), 2, 6)."-".date("Ymd");
 			$this->data 				= $data;
			$this->id 					= 'constriv';
			$this->method_title			= 'Consorzio Triveneto Bassilichi';
			$this->method_description 	= 'Gateway pagamento delle banche Bassilichi, Gruppo MPS, Banca Popolare di Vicenza, ecc.';		
			$this->icon 				= WP_PLUGIN_URL . "/" . plugin_basename( dirname (__FILE__ ) ) . '/core/payment/img/constriv-cards.jpg';
			$this->callback_url 		= get_site_url();
			//$this->callback_url 		= get_site_url()."/wp-content/plugins/constriv/core/payment/callback.php";
			$this->notify_url 		    = add_query_arg('constriv-api', 'Gateway_ConsTriv', $this->callback_url);	
			//$this->notify_url 		    = $this->callback_url;


			$this->title 				= 'Carta di Credito (Consorzio Triveneto)';
			$this->description 			= 'Effettua il pagamento tramite carta di credito, attraverso i server sicuri di Consorzio Triveneto Bassilichi.';		
			$this->debug 				= false;
			$this->test_mode 			= in_array($constriv_options['constriv_test_mode'], ["on", "true", 1]) ? true : false;
			$this->constriv_id 			= $constriv_options['constriv_id'];
			$this->constriv_password 	= urlencode($constriv_options['constriv_password']);
			$this->send_error_email 	= false;

			$endpoint = '';
			if ($this->test_mode) {
				$endpoint = "https://ipg-test4.constriv.com/IPGWeb/servlet/PaymentInitHTTPServlet";
			} else {
				$endpoint = "https://ipg.constriv.com/IPGWeb/servlet/PaymentInitHTTPServlet";
			}

			$url_parts 					= parse_url($endpoint);
			$this->constriv_webaddress 	= $url_parts["host"];
			$this->constriv_port 		= in_array("port", $url_parts) ? $url_parts["port"] : null;
			$path_parts 				= explode("/", $url_parts["path"]);
			$this->constriv_context 	= $path_parts[1];

			$this->init_payment();
			
		}

		function init_payment() {

			$language = 'ITA';
			switch (ICL_LANGUAGE_CODE) {
				case 'en':
					$language = 'USA';
					break;
				case 'fr':
					$language = 'FRA';
					break;
				case 'de':
					$language = 'DEU';
					break;
				case 'es':
					$language = 'ESP';
					break;
				case 'sk':
					$language = 'SLO';
					break;
				case 'sr':
					$language = 'SRB';
					break;							
				case 'pt':
					$language = 'POR';
					break;
				case 'ru':
					$language = 'RUS';
					break;							
			}

			$payment = new e24PaymentPipe;

			$payment->setErrorUrl(urlencode($this->notify_url));
			$payment->setResponseURL(urlencode($this->notify_url));

			$payment->setId($this->constriv_id);
			$payment->setPassword($this->constriv_password);
			$payment->setWebAddress($this->constriv_webaddress);
			$payment->setPort($this->constriv_port);
			$payment->setContext($this->constriv_context);		
			$payment->setLanguage($language);
			$payment->setCurrency("978"); //EUR
			$payment->setTrackId($this->payment_id);
			$payment->setAction("1"); //1 = Purchase
			$payment->setUdf1(md5($this->payment_id.CONSTRIV_MD5.get_option('siteurl')));
			$payment->setUdf2($this->payment_id);
			$payment->setUdf3("EMAILADDR:".$this->data["email"]);
			$payment->setAmt($this->data["amount"]);
			$initPaymentStatus = $payment->performPaymentInitialization();

			if ($initPaymentStatus != 0 || strlen($payment->getErrorMsg()) > 0 || !$payment->paymentPage || !$payment->paymentId) {	
				echo json_encode(["error" => "problem with gateway"]);
				die();							
			} else {
				echo json_encode($payment);
				die();
			}
		}

	}

}


/**
 * 
 */
class BooktimaStandalonePaymentElements
{
	
	function __construct()
	{
		add_shortcode( 'standalone_payment_form', [$this, 'standalone_payment_form'] );
		add_action( 'vc_before_init', [$this, 'standalone_payment_form_vc'] );
		add_action( 'init', [$this, 'constriv_callback'], 1 );
	}

	function constriv_callback() {		
		if($_GET["constriv-api"] == "Gateway_ConsTriv") {
			if (($_POST["result"]=="CAPTURED" || $_POST["result"]=="APPROVED")) {

				$transactionNote = sprintf("Autorizzato CONFERMATA [%s] pagamento ordine:%s [id %s], transazione:%s, garantita:%s, autorizzazione:%s, riferimento:%s, codice risposta:%s, carta:%s, udf1(controllo msg): %s", $_POST["result"], $_POST["trackid"], $_POST["udf2"], $_POST["tranid"], $_POST["liability"], $_POST["auth"], $_POST["ref"], $_POST["responsecode"], $_POST["cardtype"], $_POST["udf1"]);

				if (md5($_POST["udf2"].CONSTRIV_MD5.get_option('siteurl')) === $_POST["udf1"]) {
					$message = "1";
				} else {
					$message = "2";
				}

			} else if (array_key_exists("Error", $_POST)) {

				$transactionNote = sprintf("Procedura di pagamento in ERRORE [%s] id pagamento:%s, messaggio errore:%s", 
						$_POST["Error"], $_POST["paymentid"], $_POST["ErrorText"]);		

				$message = "3";

			} else {

				$transactionNote = sprintf("Autorizzazione NEGATA [%s] pagamento ordine:%s [id %s], transazione:%s, garantita:%s, autorizzazione:%s, riferimento:%s, codice risposta:%s, carta:%s", $_POST["result"], $_POST["trackid"], $_POST["udf2"], $_POST["tranid"], $_POST["liability"], $_POST["auth"], $_POST["ref"], $_POST["responsecode"], $_POST["cardtype"]);	

				$message = "4";

			}
			$url = "REDIRECT=".get_site_url()."/single-payment?transaction_result&transaction_message=".$message;
			echo $url;
			exit;
		}
	}

	function standalone_payment_form() {
		if(isset($_GET["transaction_result"])) {
			ob_start();
			?>
			<div class="container">
				<div class="row">
					<div class="col-md-12" style="text-align: center; padding: 75px 15px;">
						<h2>
						<?php
						$message = "";
						switch ($_GET["transaction_message"]) {
						 	case '1':
						 		$message = "Complimenti il tuo pagamento è andato a buon fine";
						 		break;
						 	case '2':
						 		$message = "ATTENZIONE!! controllo autenticità fallito, fonte no naffidabile";
						 		break;
						 	case '3':
						 		$message = "Errore con la procedura di pagamento, la preghiamo di riprovare";
						 		break;
						 	case '4':
						 		$message = "Autorizzazione NEGATA";
						 		break;
						 } 
							echo $message; 
						?>
						</h2>
					</div>
				</div>
			</div>
			<?php
			return ob_get_clean();
		
		} else {

			ob_start();

			?>
			<div class="container-fluid">
				<div class="row">
					<form method="post" id="constrivStandalonePaymentForm">
						<div class="col-md-12">
							<h3>
								<?php echo __('Personal Data', 'constriv'); ?>
							</h3>
						</div>
						<div class="col-md-6 col-xs-12">
							<label class="control-label requiredField" for="first_name">
								Name
								<span class="asteriskField">
									*
								</span>
							</label>
							<div class="form-group">
								<input class="form-control" id="first_name" name="first_name" type="text" required />
							</div>
						</div>
						<div class="col-md-6 col-xs-12">
							<label class="control-label requiredField" for="last_name">
								Last name
								<span class="asteriskField">
									*
								</span>
							</label>
							<div class="form-group ">
								<input class="form-control" id="last_name" name="last_name" type="text" required/>
							</div>
						</div>

						<div class="col-md-12">
							<label class="control-label requiredField" for="address">
								Address
								<span class="asteriskField">
									*
								</span>
							</label>
							<div class="form-group ">
								<input class="form-control" id="address" name="address" type="text" required/>
							</div>
						</div>

						<div class="col-md-6 col-xs-12">
							<label class="control-label requiredField" for="city">
								City
								<span class="asteriskField">
									*
								</span>
							</label>
							<div class="form-group ">
								<input class="form-control" id="city" name="city" type="text" required/>
							</div>
						</div>
						<div class="col-md-6 col-xs-12">
							<label class="control-label requiredField" for="country">
								Country
								<span class="asteriskField">
									*
								</span>
							</label>
							<div class="form-group ">
								<input class="form-control" id="country" name="country" type="text" required/>
							</div>
						</div>
						<div class="col-md-6 col-xs-12">
							<label class="control-label requiredField" for="phone">
								Phone
								<span class="asteriskField">
									*
								</span>
							</label>
							<div class="form-group ">
								<input class="form-control" id="phone" name="phone" type="text" required/>
							</div>
						</div>
						<div class="col-md-6 col-xs-12">
							<label class="control-label " for="email">
								Email
							</label>
							<div class="form-group ">
								<input class="form-control" id="email" name="email" type="email" required/>
							</div>
						</div>
						
						<div class="col-md-12">
							<h3>
								<?php echo __('Payment amount', 'constriv'); ?>
							</h3>
						</div>

						<div class="col-md-6 col-xs-12">
							<label class="control-label " for="amount">
								Amount
							</label>
							<div class="form-group " style="max-width: 150px;">
								<input class="form-control" id="constrivStandalonePaymentAmount" name="amount" type="text" required/>
							</div>
						</div>
						
						<input type="hidden" value="true" name="constriv_standalone_payment" id="constriv_standalone_payment">

						<div class="col-md-6 col-xs-12">
							<label class="control-label ">
								Make Payment
							</label>
							<div class="form-group">
								<div>
									<button class="btn btn-primary " name="submit" type="submit">
										Pay now
									</button>
								</div>
							</div>
						</div>
					</form>
				</div>
			</div>

			<?php
			return ob_get_clean();
		} 
	}

	function standalone_payment_form_vc() {
	    vc_map( array(
			"name" 	 	=> __( "Standalone payment", "Wordpress" ),
			"base" 	 	=> "standalone_payment_form",
			"category" 	=> __( "Constriv", "Wordpress"),
			"icon" 	 	=> "vc_general vc_element-icon vc_icon-vc-section",
			"show_settings_on_create" => false
	   ) );
	}
}

$BooktimaStandalonePaymentElements = new BooktimaStandalonePaymentElements();