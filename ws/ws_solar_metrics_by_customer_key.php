<?php
	/**
	 * Returns the entire dataset for a given client
	 */

	# Includes
	require_once("inc/error.inc.php");
	require_once("inc/database.inc.php");
	require_once("inc/security.inc.php");
	require_once("inc/json.pdo.inc.php");

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
			$self_to_demand_ratio = (float)trim($row->self_to_demand_ratio);
			$total_supply_pv_system_kwh = (float)trim($row->total_supply_pv_system_kwh);
			$total_self_consumption = (float)trim($row->total_self_consumption);
			$self_to_supply_ratio = $total_self_consumption / $total_supply_pv_system_kwh * 100;
			$total_exports = (float)trim($row->total_exports);

			// Persisting percentage self-consumption: opportunity for self-dependency from a customer perspective
			$sql2 = <<<ENDSQL
			delete from customer_metric where customer_key=$p_customer_key and metric_key='Self-to-demand ratio ({$system_size}kW/{$p_orientation}/{$p_offset}) (percent)';
ENDSQL;
			$recordSet2 = $pgconn->prepare($sql2);
			$recordSet2->execute();

			$sql3 = <<<ENDSQL
			insert into customer_metric (customer_key,metric_key,metric_value) values ($p_customer_key,'Self-to-demand ratio ({$system_size}kW/{$p_orientation}/{$p_offset}) (percent)',''||$self_to_demand_ratio);
ENDSQL;
			$recordSet3 = $pgconn->prepare($sql3);
			$recordSet3->execute();


			// Persisting percentage self-to-supply: effective onsite use of solar system output
			$sql2 = <<<ENDSQL
			delete from customer_metric where customer_key=$p_customer_key and metric_key='Supply onsite use ratio ({$system_size}kW/{$p_orientation}/{$p_offset}) (percent)';
ENDSQL;
			$recordSet2 = $pgconn->prepare($sql2);
			$recordSet2->execute();

			$sql3 = <<<ENDSQL
			insert into customer_metric (customer_key,metric_key,metric_value) values ($p_customer_key,'Supply onsite use ratio ({$system_size}kW/{$p_orientation}/{$p_offset}) (percent)',''||$self_to_supply_ratio);
ENDSQL;
			$recordSet3 = $pgconn->prepare($sql3);
			$recordSet3->execute();


			// Persist total supply from solar: risk for generators (total displaced generation)
			$sql2 = <<<ENDSQL
			delete from customer_metric where customer_key=$p_customer_key and metric_key='Total supply from PV system ({$system_size}kW/{$p_orientation}/{$p_offset}) (kWh)';
ENDSQL;
			$recordSet2 = $pgconn->prepare($sql2);
			$recordSet2->execute();

			$sql3 = <<<ENDSQL
			insert into customer_metric (customer_key,metric_key,metric_value) values ($p_customer_key,'Total supply from PV system ({$system_size}kW/{$p_orientation}/{$p_offset}) (kWh)',''||$total_supply_pv_system_kwh);
ENDSQL;
			$recordSet3 = $pgconn->prepare($sql3);
			$recordSet3->execute();

			// Persist self-consummed supply: risk for retailers + distributors (total displaced grid imports)
			$sql2 = <<<ENDSQL
			delete from customer_metric where customer_key=$p_customer_key and metric_key='Total self-consumption ({$system_size}kW/{$p_orientation}/{$p_offset}) (kWh)';
ENDSQL;
			$recordSet2 = $pgconn->prepare($sql2);
			$recordSet2->execute();

			$sql3 = <<<ENDSQL
			insert into customer_metric (customer_key,metric_key,metric_value) values ($p_customer_key,'Total self-consumption ({$system_size}kW/{$p_orientation}/{$p_offset}) (kWh)',''||$total_self_consumption);
ENDSQL;
			$recordSet3 = $pgconn->prepare($sql3);
			$recordSet3->execute();

			// Persist exports: free-loading from retailers + distributors (non-dispatched from the NEM, but billed to customers)
			$sql2 = <<<ENDSQL
			delete from customer_metric where customer_key=$p_customer_key and metric_key='Total exports to the grid ({$system_size}kW/{$p_orientation}/{$p_offset}) (kWh)';
ENDSQL;
			$recordSet2 = $pgconn->prepare($sql2);
			$recordSet2->execute();

			$sql3 = <<<ENDSQL
			insert into customer_metric (customer_key,metric_key,metric_value) values ($p_customer_key,'Total exports to the grid ({$system_size}kW/{$p_orientation}/{$p_offset}) (kWh)',''||$total_exports);
ENDSQL;
			$recordSet3 = $pgconn->prepare($sql3);
			$recordSet3->execute();

			// Adding that to the array of metrics to be returned
			$line_arr = array("system_size_kw"=>$system_size,"self_to_demand_ratio"=>round($self_to_demand_ratio,2),"self_to_supply_ratio"=>round($self_to_supply_ratio,2),"total_supply_from_pv_system_kwh"=>round($total_supply_pv_system_kwh),"total_self_consumption_kwh"=>round($total_self_consumption),"total_exports_to_grid_kwh"=>round($total_exports));

			$result[] = $line_arr;
		}
		// Returning financial metrics to user as well
		echo json_encode($result);
	}
	catch (Exception $e) {
		trigger_error("Caught Exception: " . $e->getMessage(), E_USER_ERROR);
	}

?>
