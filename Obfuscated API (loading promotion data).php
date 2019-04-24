<?
// Example of API that loads information on promotions using the jQuery DataTables plugin
// SOME COMMENTS, FUNCTION NAMES, VARIABLE NAMES AND FILE NAMES HAVE BEEN OBFUSCATED
// File has been heavily commented to explain functions and what was required for project
// Example requests:
// path_to_api?example_promotion_id=1
// path_to_api?s_example_promo_id=2&example_promo_code=example&example_promo_status=Active&example_promo_sku=123456

$DOCUMENT_ROOT = $_SERVER['DOCUMENT_ROOT'];
require_once $DOCUMENT_ROOT . 'required_file_1.inc';
require_once $DOCUMENT_ROOT . 'required_file_2.inc';

functionToCheckUserHasRequiredPermissions( '...RBAC identifier for access to API...' );

try
{
	// Create new instance of Database class
	// Database class extends mysqli
	$database_instance = new Database();

	// Check for promotion ID in request
	// intParam checks if param is_numeric and then casts to int
	$id = intParam( 'example_promotion_id' );
	if( $id )
	{
		// Match requested ID. LEFT JOIN to ... table to access ... values.
		$stmt = 'SELECT a.example1 example_1, a.example2 example_2, a.example3 example_3, '
			.'a.example4 example_4, IFNULL(a.example5, 1) example_5, '
			.'STR_TO_DATE(a.example6, \'%Y-%m-%d\') example_6, '
			.'STR_TO_DATE(a.example7, \'%Y-%m-%d\') example_7, a.example8 example_8, '
			.'IF(b.example1>0, b.example1, b.example2) example_9, '
			.'IFNULL(b.example3, a.example) example_10 '
			.'FROM ' . DATABASE_DEFINE .'.table1 a '
			.'LEFT JOIN ' . DATABASE_DEFINE .'.table2 b ON b.example1=a.example1 '
			.'WHERE a.example1=' . $id;

		// exec method uses mysqli::query and performs a few sanity checks
		$row = $database_instance->exec( $stmt )->fetch_assoc();

		// If row with requested ID is not found, throw a 400 as we cannot find the promotion.
		// abort_400 is a custom error handling function that will log the error and show a message to user
		if( $row === null )
			abort_400( 'Promotion could not be found' );

		// Query to obtain related SKUs to promotion
		$stmt = 'SELECT b.example1 example_1, b.example2 example_2 '
				.'FROM ' . DATABASE_DEFINE . '.table1 a '
				.'LEFT JOIN ' . DATABASE_DEFINE . '.table2 b ON b.example1=a.example1 '
				.'WHERE a.example1=' . $id;

		// Using fetch_all but don't expect more than 100 records
		$rslt = $database_instance->exec( $stmt )->fetch_all( MYSQL_ASSOC );

		// Building a prettified list of SKUs with their names in parentheses, separated with line breaks
		$row['example_list_of_skus'] = '';
		$count = count( $rslt );
		foreach( $rslt as $rslt_row )
		{
			$row['example_list_of_skus'] .= $rslt_row['example_1'] . ' (' . $rslt_row['example_2'] . ')';
			if( --$count <= 0 )
				break;
			$row['example_list_of_skus'] .= "\n";
		}

		// Return a JSON response
		header( 'Content-Type: application/json' );
		echo json_encode( $row );
		exit();
	}

	// Build up WHERE clause depending on parameters in request
	$where_arr = [];
	
	// strParam checks if parameter is set with the provided key, 2nd param is default value (in this case, false)
	// esc method uses escape_string as well as str_replace to remove malicious tags and characters to prevent SQL injection
	$promo_id = strParam( 's_example_promo_id', false );
	if( $promo_id !== false )
	  $where_arr[] = 'a.example1="' . $database_instance->esc( $promo_id ) .'"';

	$promo_code = strParam( 'example_promo_code', false );
	if( $promo_code !== false )
	  $where_arr[] = 'a.example2="' . $database_instance->esc( $promo_code ) .'"';

	$status = strParam( 'example_promo_status', false );
	if( strlen( $status ) )
	  $where_arr[] = 'a.example3="' . $database_instance->esc( $status ) .'"';

	// If a promo SKU is provided in request, an additional table is required to search for results (see line 41)
	$sku_join_clause = '';
	$sku_group_by_clause = '';
	$sku = intParam( 'example_promo_sku', false );
	if( $sku !== false )
	{
		$sku_join_clause = 'LEFT JOIN ' . DATABASE_DEFINE . '.table3 c ON c.example1=a.example1 ';
		$where_arr[] = 'c.example1=' . $sku;
		$sku_group_by_clause = 'GROUP BY a.example1 ';
	}

	$where_clause = count( $where_arr )	? 'WHERE ' . implode( $where_arr , ' AND ' ) . ' ' : '';

	// Column ordering for DataTables plugin
	$order_col = isset( $_REQUEST['order'][0]['column'] ) && (int) $_REQUEST['order'][0]['column'] > 1 ? (int) $_REQUEST['order'][0]['column'] + 1 : 1;
	$order_dir = isset( $_REQUEST['order'][0]['dir'] ) && $_REQUEST['order'][0]['dir'] === 'asc' ? '' : ' DESC';

	// Limit number of records displayed per page for DataTables plugin
	$start = intParam( 'start', false );
	$limit_clause = '';
	if( $start !== false )
	{
		$length = intParam( 'length', 10 );
		$limit_clause = 'LIMIT ' . $start . ',' . $length;
	}

	// Query to retrieve initial records for DataTables plugin
	$stmt = 'SELECT SQL_CALC_FOUND_ROWS DISTINCT a.example1, a.example2, '
		.'a.example3, b.example4, a.example5, '
		.'STR_TO_DATE(a.example6, \'%Y-%m-%d\') example_6, '
		.'STR_TO_DATE(a.example7, \'%Y-%m-%d\') example_7, '
		.'a.example8, IF(b.example1>0, b.example1, b.example2) example_9 '
		.'FROM ' . DATABASE_DEFINE . '.table1 a '
		.'LEFT JOIN ' . DATABASE_DEFINE .'.table2 b ON b.example1=a.example1 '
		. $sku_join_clause
		. $where_clause
		. $sku_group_by_clause
		.'ORDER BY ' . $order_col . $order_dir . ' '
		. $limit_clause;

	// execToTableJson fetches all records and adds a count of records to the JSON response for the DataTables plugin to work correctly
	echo $database_instance->execToTableJson( $stmt );
}
catch( BusinessException $be )
{
	// BusinessException is an extension of the Exception class to differentiate between business rule exceptions and server exceptions
	abort_400( $be->getMessage() );
}
catch( Exception $e )
{
	abort( 500, $e->getMessage() );
}