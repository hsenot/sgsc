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
		$p_cost_kwh_from_grid = 0.30;
		$p_feed_in_tariff = 0.06;
		$p_capital_cost_per_w = array(1=>1.43,1.5=>1.43,2=>1.65,2.5=>1.49,3=>1.33,3.5=>1.30,4=>1.26,4.5=>1.26,5=>1.26,5.5=>1.24,6=>1.22,6.5=>1.20,7=>1.18,7.5=>1.16,8=>1.14,8.5=>1.12,9=>1.10,9.5=>1.08,10=>1.04);

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

		//echo rs2json($recordSet);

		$result = array();
		while ($row  = $recordSet->fetch(PDO::FETCH_ASSOC))
		{
			foreach ($row as $key => $val)
			{
				if ($key=="total_self_consumption") {
					$benefit_avoided_cost = (float)trim($val) * $p_cost_kwh_from_grid;
					$total_self_consumption = (float)trim($val);
				}
				if ($key=="total_exports") {
					$benefit_fit = (float)trim($val) * $p_feed_in_tariff;
					$total_exports = (float)trim($val);
				}
				if ($key=="system_size_kw") {
                                        $system_size = (float)trim($val);
                                }
				if ($key=="self_to_demand_ratio") {
                                        $self_to_demand_ratio = (float)trim($val);
                                }
				if ($key=="total_demand") {
                                        $total_demand = (float)trim($val);
                                }
				if ($key=="total_supply_pv_system_kwh") {
                                        $total_supply = (float)trim($val);
                                }
			}
			// Calculating payback period
			$payback = ($p_capital_cost_per_w[$system_size]*$system_size*1000)/($benefit_avoided_cost+$benefit_fit);
			// Now doing something with this line
			$line_arr = array("system_size_kw"=>$system_size,"total_demand_kwh"=>$total_demand,"total_supply_by_pv_kwh"=>$total_supply,"total_self_consumption_kwh"=>$total_self_consumption,"benefit_avoided_cost_aud"=>$benefit_avoided_cost,"total_exports_kwh"=>$total_exports,"benefit_fit_aud"=>$benefit_fit,"self_to_demand_ratio"=>$self_to_demand_ratio,"simple_payback_yr"=>$payback);
			// Persist that in the database too
			$sql2 = <<<ENDSQL
			delete from customer_metric where customer_key=$p_customer_key and metric_key='Solar payback for a $system_sizekW system (years)';
ENDSQL;
			//echo $sql2;
    		$recordSet2 = $pgconn->prepare($sql2);
    		$recordSet2->execute();

			$sql3 = <<<ENDSQL
			insert into customer_metric (customer_key,metric_key,metric_value) values ($p_customer_key,'Solar payback for a $system_sizekW system (years)',''||$payback);
ENDSQL;
			//echo $sql3;
    		$recordSet3 = $pgconn->prepare($sql3);
    		$recordSet3->execute();

			$result[] = $line_arr;
		}
		echo json_encode($result);
	}
	catch (Exception $e) {
		trigger_error("Caught Exception: " . $e->getMessage(), E_USER_ERROR);
	}

?>
