<?php
/**
 * Returns the entire dataset for a given client
 */

# Includes
require_once("inc/error.inc.php");
require_once("inc/database.inc.php");
require_once("inc/security.inc.php");
require_once("inc/csv.pdo.inc.php");

# Performs the query and returns XML or JSON
try {
	$p_customer_key = $_REQUEST['customer_key'];

	$sql = <<<ENDSQL
	select to_char(to_date('01/01/2010','DD/MM/YYYY') + all_d_p.d - 1,'YYYY/MM/DD') as date,all_d_p.p as period,coalesce(t.k1,0.0) as kwh1,coalesce(t.k2,0.0) as kwh2,coalesce(t.k3,0.0) as kwh3,coalesce(t.k4,0.0) as kwh4 from
	(select * from
	(select * from generate_series(
	(select min(day)::integer from interval_reading_mini where customer_key=$p_customer_key),
	(select max(day)::integer from interval_reading_mini where customer_key=$p_customer_key)
	) as d) dates, (select * from generate_series(1,48) as p) periods) all_d_p
	LEFT JOIN
	(select day as d,prd as p,wh1/100 as k1,wh2/100 as k2,wh3/100 as k3,wh4/100 as k4 from interval_reading_mini where customer_key=$p_customer_key) t
	ON all_d_p.d=t.d and all_d_p.p=t.p ORDER BY 1,2
ENDSQL;

	//echo $sql;
	$pgconn = pgConnection();

    /*** fetch into an PDOStatement object ***/
    $recordSet = $pgconn->prepare($sql);
    $recordSet->execute();

	// Exporting as CSV	
	header("Content-Type: text/csv");
	header("Content-Disposition: attachment; filename=smd-".$p_customer_key.".csv");
	echo rs2csv($recordSet);

}
catch (Exception $e) {
	trigger_error("Caught Exception: " . $e->getMessage(), E_USER_ERROR);
}

?>
