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
		// TODO: introduce parameterised angle

		$sql = <<<ENDSQL
select 
system_size_kw,
sum(supply_pv_system_kwh) as total_supply_pv_system_kwh,
max(supply_pv_system_kwh) as max_supply_30mn_kwh,
max(demand_kwh) as max_demand_30mn,
sum(balance_30mn_kwh) as net_balance,
sum(demand_kwh) as total_demand,
sum(case when balance_30mn_kwh<0 then -balance_30mn_kwh else 0 end) as total_imports,
sum(case when balance_30mn_kwh>0 then demand_kwh else supply_pv_system_kwh end) as total_self_consumption,
sum(case when balance_30mn_kwh>0 then balance_30mn_kwh else 0 end) as total_exports,
round(cast(sum(case when balance_30mn_kwh>0 then demand_kwh else supply_pv_system_kwh end)/sum(demand_kwh)*100 as numeric),2) as self_to_demand_ratio
from
(
select *,
round(cast(supply_pv_system_kwh-demand_kwh as numeric),4) as balance_30mn_kwh
from
(
select 
c.day as d,
c.prd as p,
c.kwh as demand_kwh,
sys_size.x as system_size_kw,
coalesce(round(sys_size.x*(s.n_w*$p_derate/1000/2),4),0.0) as supply_pv_system_kwh
from 
	(select day,prd,kwh1 as kwh from interval_reading_mini where customer_key=$p_customer_key order by day desc limit 365*48) c
	 left outer join solar_melbourne_opti s	on c.day%365=s.day and c.prd=s.prd,
	(select round(generate_series(3,10)/2.0,1) as x) sys_size
) t
order by 1,2
) s
group by system_size_kw
order by system_size_kw
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
