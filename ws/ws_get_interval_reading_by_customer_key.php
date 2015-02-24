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

		$sql = <<<ENDSQL
	select all_d_p.d,all_d_p.p,coalesce(t.k1,0.0) k1,coalesce(t.k2,0.0) k2,coalesce(t.k3,0.0) k3,coalesce(t.k4,0.0) k4 from
	(select * from
	(select * from generate_series(
	(select min(day)::integer from interval_reading_mini where customer_key=$p_customer_key),
	(select max(day)::integer from interval_reading_mini where customer_key=$p_customer_key)
	) as d) dates, (select * from generate_series(1,48) as p) periods) all_d_p
	LEFT JOIN
	(select day as d,prd as p,wh1::float/100 as k1,wh2::float/100 as k2,wh3::float/100 as k3,wh4::float/100 as k4 from interval_reading_mini where customer_key=$p_customer_key) t
	ON all_d_p.d=t.d and all_d_p.p=t.p ORDER BY 1,2
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
		echo rs2json($recordSet);
	}
	catch (Exception $e) {
		trigger_error("Caught Exception: " . $e->getMessage(), E_USER_ERROR);
	}

?>
