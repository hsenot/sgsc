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
		$p_param1 = $_REQUEST['param1'];
		$p_param2 = $_REQUEST['param2'];
		$p_param3 = $_REQUEST['param3'];
		$p_param4 = $_REQUEST['param4'];

		$sql = <<<ENDSQL
select customer_key as id from
trial_customer where customer_key in
(
select distinct m.customer_key from customer_nb_days_not_null m
where nb_day_k2>=$p_param2 and nb_day_k3>=$p_param3 and nb_day_k4>=$p_param4
)
and customer_key||'' like '%$p_param1%' 
order by customer_key
limit 50
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
