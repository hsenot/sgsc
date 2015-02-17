<?php
	/**
	 * Returns the entire dataset for a given client
	 */

	# Includes
	require_once("inc/error.inc.php");
	require_once("inc/database.inc.php");
	require_once("inc/security.inc.php");
	require_once("inc/json.pdo.inc.php");
	require_once('financial/financial_class.php'); 

	# Performs the query and returns XML or JSON
	try {
		$p_customer_key = $_REQUEST['customer_key'];
		$p_offset = 0;
		if (isset($_REQUEST['offset']))
		{
			$p_offset=$_REQUEST['offset'];
		}
		$p_orientation = 'n';
		if (isset($_REQUEST['orientation']))
		{
			$p_orientation=$_REQUEST['orientation'];
		}

		// Parameters in solar financial calculations
		$p_cost_kwh_from_grid = 0.30;
		$p_feed_in_tariff = 0.06;
		$p_capital_cost_per_w = array(1=>1.43,1.5=>1.43,2=>1.65,2.5=>1.49,3=>1.33,3.5=>1.30,4=>1.26,4.5=>1.26,5=>1.26,5.5=>1.24,6=>1.22,6.5=>1.20,7=>1.18,7.5=>1.16,8=>1.14,8.5=>1.12,9=>1.10,9.5=>1.08,10=>1.04);
		$npv_period_years = 25;
		$npv_interest_rate = 0.05;

		// Required to cater for IE
		header("Content-Type: text/html");
		// Allow CORS
		header("Access-Control-Allow-Origin: *");

		// The json file returned from the solar output service
		// Assumption: it's at the /sgsc endpoint on the server
		$recordSet = json_decode(file_get_contents('http://'.$_SERVER['HTTP_HOST'].'/sgsc/ws/ws_solar_output_by_customer_key.php?customer_key='.$p_customer_key.'&offset='.$p_offset.'&orientation='.$p_orientation));

		$pgconn = pgConnection();

		$result = array();
		foreach($recordSet->rows as $row)
		{
			// Variables for financial calculations
			$system_size = (float)trim($row->system_size_kw);
			$capital_cost = $p_capital_cost_per_w[$system_size]*$system_size*1000;
			$benefit_avoided_cost = (float)trim($row->total_self_consumption) * $p_cost_kwh_from_grid;
			$benefit_fit = (float)trim($row->total_exports) * $p_feed_in_tariff;
			$benefits = $benefit_avoided_cost + $benefit_fit;
			
			// Simple payback section
			$payback = $capital_cost/$benefits;

			// Persist that in the database
			$sql2 = <<<ENDSQL
			delete from customer_metric where customer_key=$p_customer_key and metric_key='Payback ({$system_size}kW/{$p_orientation}/{$p_offset}) (years)';
ENDSQL;
			$recordSet2 = $pgconn->prepare($sql2);
			$recordSet2->execute();

			$sql3 = <<<ENDSQL
			insert into customer_metric (customer_key,metric_key,metric_value) values ($p_customer_key,'Payback ({$system_size}kW/{$p_orientation}/{$p_offset}) (years)',''||$payback);
ENDSQL;
			$recordSet3 = $pgconn->prepare($sql3);
			$recordSet3->execute();

			// Cashflow array: capital cost (negative) followed by benefits (positives)
			$cashflow = array();
			$cashflow[] = $capital_cost * (-1.0);
			// Adding the cashflow over the period considered
			for ($x = 0; $x < $npv_period_years; $x++) {
				$cashflow[]=$benefits;
			} 

			// Financial class instantiation
			$f = new Financial;

			// IRR section
			$irr = $f->IRR($cashflow)*100;

			// Persist that in the database
			$sql2 = <<<ENDSQL
			delete from customer_metric where customer_key=$p_customer_key and metric_key='IRR over {$npv_period_years} years ({$system_size}kW/{$p_orientation}/{$p_offset}) (percent)';
ENDSQL;
			$recordSet2 = $pgconn->prepare($sql2);
			$recordSet2->execute();

			$sql3 = <<<ENDSQL
			insert into customer_metric (customer_key,metric_key,metric_value) values ($p_customer_key,'IRR over {$npv_period_years} years ({$system_size}kW/{$p_orientation}/{$p_offset}) (percent)',''||$irr);
ENDSQL;
			$recordSet3 = $pgconn->prepare($sql3);
			$recordSet3->execute();


			// NPV section
			$npv = $f->NPV($npv_interest_rate,$cashflow);

			// Persist that in the database
			$sql2 = <<<ENDSQL
			delete from customer_metric where customer_key=$p_customer_key and metric_key='NPV over {$npv_period_years} years at $npv_interest_rate ({$system_size}kW/{$p_orientation}/{$p_offset}) (AUD)';
ENDSQL;
			$recordSet2 = $pgconn->prepare($sql2);
			$recordSet2->execute();

			$sql3 = <<<ENDSQL
			insert into customer_metric (customer_key,metric_key,metric_value) values ($p_customer_key,'NPV over {$npv_period_years} years at $npv_interest_rate ({$system_size}kW/{$p_orientation}/{$p_offset}) (AUD)',''||$npv);
ENDSQL;
			$recordSet3 = $pgconn->prepare($sql3);
			$recordSet3->execute();


			// Adding that to the array of metrics to be returned
			$line_arr = array("system_size_kw"=>$system_size,"benefit_avoided_cost_aud"=>round($benefit_avoided_cost),"benefit_fit_aud"=>round($benefit_fit),"simple_payback_yr"=>round($payback,1),"irr_over_".$npv_period_years."_years_pct"=>round($irr,2),"npv_over_".$npv_period_years."_years_aud"=>round($npv));

			$result[] = $line_arr;
		}
		// Returning financial metrics to user as well
		echo json_encode($result);
	}
	catch (Exception $e) {
		trigger_error("Caught Exception: " . $e->getMessage(), E_USER_ERROR);
	}

?>
