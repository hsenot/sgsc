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
		$p_derate = 0.80;
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

		$sql = <<<ENDSQL
SELECT   system_size_kw,
		 {$p_offset} as day_offset,
         SUM(supply_pv_system_kwh) AS total_supply_pv_system_kwh, 
         Max(supply_pv_system_kwh) AS max_supply_30mn_kwh, 
         Max(demand_kwh)           AS max_demand_30mn, 
         SUM(balance_30mn_kwh)     AS net_balance, 
         SUM(demand_kwh)           AS total_demand, 
         SUM(CASE WHEN balance_30mn_kwh<0 THEN -balance_30mn_kwh ELSE 0 END) AS total_imports, 
         SUM(CASE WHEN balance_30mn_kwh>0 THEN demand_kwh ELSE supply_pv_system_kwh END) AS total_self_consumption, 
         SUM( CASE WHEN balance_30mn_kwh>0 THEN balance_30mn_kwh ELSE 0 END) AS total_exports, 
         Round(Cast(SUM(CASE WHEN balance_30mn_kwh>0 THEN demand_kwh ELSE supply_pv_system_kwh END)/SUM(demand_kwh)*100 AS NUMERIC),2) AS self_to_demand_ratio 
FROM     ( 
          SELECT   *,Round(Cast(supply_pv_system_kwh-demand_kwh AS NUMERIC),4) AS balance_30mn_kwh 
          FROM     ( 
			SELECT 	s.day AS d,
					s.prd AS p,
					c.kwh AS demand_kwh,
					sys_size.x AS system_size_kw,
					Coalesce(Round(sys_size.x*(s.{$p_orientation}_w*{$p_derate}/1000/2),4),0.0) AS supply_pv_system_kwh 
			FROM ( 
				SELECT 	day, 
						prd, 
						wh1/100 AS kwh 
				FROM interval_reading_mini
				WHERE customer_key={$p_customer_key}
				ORDER BY day DESC limit 365*48) c
				LEFT OUTER JOIN solar_sydney_opti s 
				ON c.day%365=(s.day+{$p_offset}+365)%365 AND c.prd=s.prd,
				(SELECT round(generate_series(3,10)/2.0,1) AS x) sys_size
          ) t 
) s 
GROUP BY system_size_kw 
ORDER BY system_size_kw
ENDSQL;

		//echo $sql;
		$pgconn = pgConnection();

		/*** fetch into an PDOStatement object ***/
		$recordSet = $pgconn->prepare($sql);
		$recordSet->execute();

		//header("Content-Type: application/json");
		// Required to cater for IE
		header("Content-Type: text/html");
		// Allow CORS
		header("Access-Control-Allow-Origin: *");

		// Outputting the content
		echo rs2json($recordSet);
	}
	catch (Exception $e) {
		trigger_error("Caught Exception: " . $e->getMessage(), E_USER_ERROR);
	}

?>
