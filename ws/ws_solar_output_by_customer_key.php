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
		$p_derate = 0.85;
		$p_offsetamplitude = 0;
		// TODO: introduce parameterised angle

		$sql = <<<ENDSQL
SELECT   system_size_kw, 
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
          SELECT   *, 
                   Round(Cast(supply_pv_system_kwh-demand_kwh AS NUMERIC),4) AS balance_30mn_kwh 
          FROM     ( 
                                   SELECT          s.day                                                 AS d,
                                                   s.prd                                                 AS p,
                                                   avg(c.kwh)                                            AS demand_kwh,
                                                   sys_size.x                                            AS system_size_kw,
                                                   avg(Coalesce(Round(sys_size.x*(s.n_w*$p_derate/1000/2),4),0.0)) AS supply_pv_system_kwh 
                                   FROM            ( 
                                                            SELECT   day, 
                                                                     prd, 
                                                                     kwh1 AS kwh 
                                                            FROM     interval_reading_mini
                                                            WHERE    customer_key=$p_customer_key
                                                            ORDER BY day DESC limit 365*48) c
                                   LEFT OUTER JOIN (SELECT (day+y+365)%365 as day,prd,n_w,nw_w,w_w,y as day_offset FROM solar_sydney_opti so,generate_series(-$p_offsetamplitude,$p_offsetamplitude) AS y)s 
                                   ON c.day%365=s.day AND c.prd=s.prd,(SELECT round(generate_series(3,10)/2.0,1) AS x) sys_size 
                                   GROUP BY 1,2,4
          ) t
          ORDER BY 1, 2 
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
