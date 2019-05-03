<?
// Example of API used to retrieve required information for product lister (lister can be accessed through search, browse or promotion)
// Code has been obfuscated and heavily commented to explain context and functionality

// Function to check that the request contains all the required headers
verifyAppParameters( true );

// Create variables for request parameters, should only have 1 of these in request
// strParam retrieves $_REQUEST[ argument ], intParam checks if this is numeric and casts to integer
$search_term = strParam( '...' );
$lister_id = intParam( '...' );
$promo_id = intParam( '...' );

// If lister ID, search term and promotion ID are all empty, we cannot do anything
// errorHandler function returns error code in JSON to show in app
if( empty( $lister_id ) && empty( $search_term ) && empty( $promo_id ) )
	errorHandler( 9 );

// If multiple request parameters are provided, exit with error
if( ( !empty( $lister_id ) && !empty( $search_term ) ) || ( !empty( $lister_id ) && !empty( $promo_id ) ) || ( !empty( $promo_id ) && !empty( $search_term ) ) )
	errorHandler( 10 );

// Includes for functions used below
include $root_directory . '...';
include $root_directory . '...';
include $root_directory . '...';
include $root_directory . '...';
include $root_directory . '...';

// Location used to determine some information displayed in listing such as express delivery badge
// Function uses combination of location data available such as IP, latitude/longitude etc.
loadLocation( true );

// The next section gathers required information for the request we make to Solr (open source search engine based on Apache Lucene)
// Set of fields we wish to return from SOLR
$field_list_arr = [ '...', '...', '...' ];

// Set of categoric fields you want to use as facets in Solr request, this includes things like brand, colour, department that we can use to filter on
$facet_category_arr = [ '...', '...', '...' ];

// Apply preselected filter from search if applicable
$preselected_filter = strParam( '...' );
if( !strlen( $preselected_filter ) )
	$preselected_filter = false;

// Ensure we filter by brand if it's a brand search
// boolParam uses filter_var(..., FILTER_VALIDATE_BOOLEAN) as parameter could be true as string
$brand_search = boolParam( '...' );

// Determine what arguments to provide to loadDataForLister function
// loadDataForLister( $database_reference, $search_term (string), $lister_id (integer), $promotion_id (integer), $brand_search (boolean), $field_list_arr (array), $facet_category_arr (array), $preselected_filter (string) )
// Function will send a cURL request to Solr and return JSON that we manipulate to form final listing and filter arrays
$raw_solr_arr = [];
if( $brand_search )
	$raw_solr_arr = loadDataForLister( $db, $search_term, false, false, true, $field_list_arr, $facet_category_arr, $preselected_filter );
elseif( $search_term )
	$raw_solr_arr = loadDataForLister( $db, $search_term, false, false, false, $field_list_arr, $facet_category_arr, $preselected_filter );
elseif( $lister_id )
	$raw_solr_arr = loadDataForLister( $db, false, $lister_id, false, false, $field_list_arr, $facet_category_arr, $preselected_filter );
else
	$raw_solr_arr = loadDataForLister( $db, false, false, $promo_id, false, $field_list_arr, $facet_category_arr, $preselected_filter );

// $raw_solr_arr doesn't contain the final array of documents (that is in ['response']['docs']), could be spellcheck suggestions if Solr returns 0 documents
// $search_term_corr and $suggestion_arr used to display information in app to user about search suggestions
$solr_docs_arr = [];
$suggestion_arr = [];
$search_term_corr = '';
if( !empty( $raw_solr_arr['response']['docs'] ) )
	$solr_docs_arr = $raw_solr_arr['response']['docs'];
elseif( $search_term && isset( $raw_solr_arr['spellcheck']['collations'] ) && count( $raw_solr_arr['spellcheck']['collations'] ) > 1 )
{
	// If this is a search that returns 0 documents, we want to send another request to Solr using the first spellcheck suggestion
	$count = 0;
	foreach( $raw_solr_arr['spellcheck']['collations'] as $suggestion )
	{
		// ['collations'] array is in format ['collation', 'table', 'collation', 'dining table' ... ]
		if( $suggestion === 'collation' )
			continue;

		$count++;
		if( $count === 1 )
		{
			$raw_solr_arr = loadDataForLister( $db, $suggestion, false, false, false, $field_list_arr, $facet_category_arr, $preselected_filter );
			if( !empty( $raw_solr_arr['response']['docs'] ) )
			{
				$solr_docs_arr = $raw_solr_arr['response']['docs'];
				$search_term_corr = $suggestion;
			}
			continue;
		}

		$suggestion_arr[] = [
			'name' => $suggestion
		];
	}
}

// ['facet_fields'] contains filters applicable to the documents returned from the request
$solr_facet_category_arr = empty( $raw_solr_arr['facet_counts']['facet_fields'] ) ? [] : $raw_solr_arr['facet_counts']['facet_fields'];

// Whether we had to use MySQL as a fallback for when Solr was unavailable
$is_fallback = isset( $raw_solr_arr['fallback'] );

// Using the documents returned from Solr, we add the following:
// filter_arr - this contains key/value pairings that is used client-side to perform filtering
// solr_total_score - measures relevancy of document
// sequence - for listers only, this is the manual sequence of documents set by online trading team
$group_doc_arr = [];
$doc_count = 0;
$max_solr_score	= 0;
foreach( $solr_docs_arr as &$document )
{
	$doc_count++;
	if( !$is_fallback )
	{
		$filter_arr = [];
		foreach( $facet_category_arr as $facet_category )
		{
			// Add dynamic attributes to each SKU - these are attributes that not all SKUs will have
			if( $facet_category === 'dynamic_attribute_f' )
			{
				// Skip any SKUs that don't have dynamic attributes
				if( !isset( $document['dynamic_attribute'] ) )
					continue;

				// Custom attributes stored as "Weight|85" in array
				foreach( $document['dynamic_attribute'] as $custom_attribute )
				{
					$expl_facet = explode( '|', $custom_attribute );
					$filter_arr[] = [
						'name' => $expl_facet[0],
						'value' => $expl_facet[1]
					];
				}
				continue;
			}

			// Build up filter_arr with names and values of attributes
			$value = $document[ substr( $facet_category, 0, -2 ) ];
			$filter_arr[] = [
				'name'	=> $facet_category,
				'value'	=>  $value
			];
		}

		$document['filter_arr']	= $filter_arr;

		// Calculate total search score from Solr score and search multiplier (this is a value we index based on multiple metrics) - normalise scores first (top result set to 100)
		// Get sequence for product lister if not a search
		if( $search_term || $promo_id )
		{
			// After 1st document, scores are scaled based on the 1st document's score
			if( $doc_count !== 1 && $max_solr_score !== 0 )
				$document['solr_total_score'] = ( $document['score'] / $max_solr_score ) * $document['search_multiplier'];
			else
			{
				// Set first document as baseline - score of 100
				$max_solr_score = $document['score'];
				$document['solr_total_score'] = 100;
			}
		}
		else
			foreach( $document['merchandising_category_sequence'] as $merch_cat_seq )
			{
				// One SKU can be in multiple product listers, ['merchandising_category_sequence'] contains an array of key value pairs
				// The key is the product lister ID and the value is the chosen position in the lister (e.g. 444|1)
				$expl_merch_cat_seq = explode( '|', $merch_cat_seq );

				if( (int) $expl_merch_cat_seq[0] !== $lister_id )
					continue;

				$document['sequence'] = (int) $expl_merch_cat_seq[1];
			}			
	}

	// A listing groups SKUs together with the same image so we don't pollute the lister with the same image
	$group_doc_arr[ $document['product_id'] ][ $document['variant_media_id'] ][] = $document;
}
unset( $document );

$listing_arr = [];
$price_filter_arr = [];
$review_filter_arr = [];
$active_filter_arr = [];
$key = 0;
foreach( $group_doc_arr as $product_id => $product )
{
	foreach( $product as $variant_media_id => $variant_media )
	{
		$listing				= [];
		$sku_arr				= [];
		$min_price				= 0;
		$max_review				= 0;
		$max_search_score		= 0;
		$new_in					= false;
		$in_stock				= false;
		$as_seen_on_tv			= false;
		$can_deliver			= false;
		$instore_only			= true;
		$show_get_it_fast		= false;

		foreach( $variant_media as $document )
		{
			// This will be used later to determine if this listing contains >1 SKU
			$sku_arr[] = $document['sku'];

			// Take minimum price of listing so we can show "From £4.99", only applicable for listing with >1 SKU
			if( $min_price === 0 || $document['price'] < $min_price )
				$min_price = $document['price'];

			// Take max search score from SKUs in listing
			if( ( $search_term || $promo_id ) && ( $document['solr_total_score'] > $max_search_score || $max_search_score === 0 ) )
				$max_search_score	= $document['solr_total_score'];

			// If any SKU new in, mark listing as new in
			if( $new_in === false && $document['new_in'] )
				$new_in = true;

			// If any SKU in stock, mark listing as in stock
			if( $in_stock === false && $document['in_stock'] )
				$in_stock = true;

			// If any SKU as seen on TV, mark listing as seen on TV
			if( $as_seen_on_tv === false && $document['as_seen_on_tv'] )
				$as_seen_on_tv = true;

			// If any SKU can be delivered, mark listing as able to be delivered
			$fulfilment_channel = $document['fulfilment'];
			if( $can_deliver === false && in_array( $fulfilment_channel, [ '...', '...', '...' ] ) )
				$can_deliver = true;

			// If any SKU is not instore only, mark listing as not instore only
			if( $instore_only === true && $fulfilment_channel !== 'C' )
				$instore_only = false;

			// Determine if we can show express delivery badge
			if( $show_get_it_fast === false && isset( $document['lead_time'] ) && in_array( $document['size_class'], [ 'Medium', 'Large' ] ) )
			{
				foreach( $document['lead_time'] as $lead_time )
				{
					// Lead time format is 'location zone'|'lead time' where a zone corresponds to a country or subsection of a country
					$lead_time_arr = explode( '|', $lead_time );

					// Check if entry in lead time array corresponds to customer's zone and lead time is < 10 days
					if( (int) $lead_time_arr[0] === $_SESSION['...'] && (int) $lead_time_arr[1] < 10 )
					{
						$show_get_it_fast = true;
						continue;
					}
				}
			}

			if( !$is_fallback )
			{
				// Add filters to active_filter_arr so we only show filters that will return results
				// Solr returns facets for all documents which means there can be empty filters if we specify rows in Solr request
				if( $document['top_level_category'] !== '' )
				{
					// Remove asostrophes so we can easily use in sharing URLs
					if( !isset( $active_filter_arr['top_level_category_f'] ) )
						$active_filter_arr['top_level_category_f'][] = str_replace( '\'', '', $document['top_level_category'] );
					else
						if( !in_array( $document['top_level_category'], $active_filter_arr['top_level_category_f'] ) )
							$active_filter_arr['top_level_category_f'][] = str_replace( '\'', '', $document['top_level_category'] );
				}

				if( $document['colour_group'] !== '' )
				{
					if( !isset( $active_filter_arr['colour_group_f'] ) )
						$active_filter_arr['colour_group_f'][] = $document['colour_group'];
					else
						if( !in_array( $document['colour_group'], $active_filter_arr['colour_group_f'] ) )
							$active_filter_arr['colour_group_f'][] = $document['colour_group'];
				}

				// Due to bad data, we may not want to show brand as filter (don't want to filter by N/A)
				if( !in_array( $document['brand'], [ 'N/A', 'NA', '' ] ) )
				{
					if( !isset( $active_filter_arr['brand_f'] ) )
						$active_filter_arr['brand_f'][] = str_replace( '\'', '', $document['brand'] );
					else
						if( !in_array( $document['brand'], $active_filter_arr['brand_f'] ) )
							$active_filter_arr['brand_f'][] = str_replace( '\'', '', $document['brand'] );
				}

				if( !empty( $document['dynamic_attribute'] ) )
				{
					foreach( $document['dynamic_attribute'] as $dyn_attr )
					{
						$expl_dyn_attr = explode( '|', $dyn_attr );
						$dyn_attr_name = $expl_dyn_attr[0];
						$dyn_attr_val = $expl_dyn_attr[1];

						if( !isset( $active_filter_arr[ $dyn_attr_name ] ) )
							$active_filter_arr[ $dyn_attr_name ][] = $dyn_attr_val;
						else
							if( !in_array( $dyn_attr_val, $active_filter_arr[ $dyn_attr_name ] ) )
								$active_filter_arr[ $dyn_attr_name ][] = $dyn_attr_val;
					}
				}
			}

			// Build up filter_arr for listing
			$filter_arr = [];
			foreach( $document['filter_arr'] as $filter )
				if( !in_array( $filter['value'], [ '', 'N/A' ] ) )
					$filter_arr[] = [
						'name'	=> $filter['name'],
						'value'	=> str_replace( '\'', '', $filter['value'] )
					];

			// If product_id isn't set, set basic listing data
			if( !isset( $listing['product_id'] ) )
			{
				$listing['product_id']				= $document['product_id'];
				$listing['variant_id']				= $document['variant_id'];
				$listing['sku']						= $document['sku'];
				$listing['review_score']			= $max_review = $document['review_score'];
				$listing['review_count']			= $document['review_count'];
				$listing['review_qualified']		= $document['review_qualified'];
				$listing['price']					= $document['price'];
				$listing['price_was']				= $document['price_was'];
				$listing['price_was_percent']		= $document['price_was_percent'];
				$listing['price_was_save']			= $document['price_was_save'];
				$listing['variant_media_url']		= $document['variant_media_url'];
				$listing['variant_url']				= $document['variant_url'];
				$listing['product_url']				= $document['product_url'];

				// If we have a promotion name from Solr, add to listing
				if( isset( $document['promo_name'] ) )
					$listing['promo_name'] = $document['promo_name'];

				// Ensure sequence is set for listing if lister
				if( $lister_id )
					$listing['sequence'] = $document['sequence'];
			}

			// Use product name for title if multi-sku listing
			$listing['title'] = isset( $listing['title'] ) ? $document['product_name'] : $document['variant_name'];		
		}

		$listing['filter_arr'] = $filter_arr;

		// Save a price range against each listing
		$price_range = priceToRange( $min_price );
		$listing['filter_arr'][] = [
			'name' => 'price_f',
			'value' => $price_range
		];

		// Add new price filter range or upcount existing range
		if( isset( $price_filter_arr[ $price_range ] ) )
			$price_filter_arr[ $price_range ]++;
		else
			$price_filter_arr[ $price_range ] = 1;

		// Save a review range against each listing
		$review_range = reviewToRange( $max_review );
		$listing['filter_arr'][] = [
			'name' => 'review_score_f',
			'value' => $review_range
		];

		// Add new review filter range or upcount existing range
		if( isset( $review_filter_arr[ $review_range ] ) )
			$review_filter_arr[ $review_range ]++;
		else
			$review_filter_arr[ $review_range ] = 1;

		// Optimistic look at listing level
		$listing['new_in']				= $new_in;
		$listing['in_stock']			= $in_stock;
		$listing['as_seen_on_tv']		= $as_seen_on_tv;
		$listing['show_get_it_fast']	= $show_get_it_fast;

		// Ensure Solr score set if search
		if( $search_term || $promo_id )
			$listing['solr_total_score'] = $max_search_score;

		$single_sku = count( $sku_arr ) === 1;

		// Determine what button for the lister we require - could be Add to Basket, Instore Only, Sold Out, More Options
		$listing[ determineListerButton( $single_sku, $can_deliver, $in_stock, $instore_only ) ] = true;

		// Set a from price if multi-SKU listing
		if( !$single_sku )
		{
			$listing['multi_sku'] = true;
			$listing['min_price'] = $min_price;
		}

		// Key required for FlatList component in React Native
		$listing['key'] = (string) $key;
		$listing_arr[] = $listing;
		$key++;
	}
}

// Build up array of attributes to filter
// Pre-apply in stock filter if search - listers will be sorted by stock, then sequence
// loadCategoryFilter is used to organise the filter data from Solr so that's it more usable in the JavaScript client-side code
$in_stock_applied = $search_term ? 'true' : false;
$attr_arr[] = loadCategoryFilter( $solr_facet_category_arr, 'in_stock_f', 'In Stock', $in_stock_applied );
if( $search_term || $promo_id )
	$attr_arr[] = loadCategoryFilter( $solr_facet_category_arr, 'top_level_category_f', 'Department', $preselected_filter );
else
{
	// Find all the child departments of the lister to filter by
	$stmt = 'SELECT a.test, a.test2 '
			.'FROM ... '
			.'WHERE ...="..." '
				.'AND ...=1 '
				.'AND ...=1 '
				.'AND ... IN ("...", "...") '
				.'AND ...=' . (int) $lister_id;
	$rslt = $db->exec( $stmt );

	$child_dept_arr = [];
	// Replacing apostrophes as we did earlier for easy string matching
	while( $row = $rslt->fetch_assoc() )
		$child_dept_arr[] = [
			'name' => str_replace( '\'', '', $row['name'] ),
			'display_name' => $row['name'],
			'lister_id' => $row['id'],
			'applied' => false
		];

	if( count( $child_dept_arr ) !== 0 )
		$attr_arr[] = [
			'name'		=> 'Department',
			'facet'		=> 'Department',
			'type'		=> 'navigation',
			'fixed_seq'	=> false,
			'value_arr'	=> $child_dept_arr
		];

	// Sort lister by out of stock status
	usort( $listing_arr, 'sortListerByStock' );

	// Reallocate sequence for client side sorting
	// usort above sequences listing_arr according to OOS status, then sequence
	$sequence = 0;
	foreach( $listing_arr as &$listing )
	{
		$sequence++;
		$listing['sequence'] = $sequence;
		unset( $listing['in_stock'] );
	}
	unset( $listing );
}

$attr_arr[] = loadCategoryFilter( $solr_facet_category_arr, 'colour_group_f', 'Colour', false );

// Build dynamic price ranges
ksort( $price_filter_arr );

$price_value_arr = [];
foreach( $price_filter_arr as $range_lb => $count )
	$price_value_arr[] = priceRangeDisplay( $range_lb, false );

$attr_arr[] = [
	'name'		=> 'Price',
	'facet'		=> 'price_f',
	'type'		=> 'range',
	'value_arr'	=> $price_value_arr,
	'fixed_seq'	=> true
];

$attr_arr[] = loadCategoryFilter( $solr_facet_category_arr, 'brand_f', 'Brand', false );

// Build review ranges, sort by descending order so top review filters appear first
krsort( $review_filter_arr );

$review_value_arr = [];
foreach( $review_filter_arr as $range_lb => $count )
	$review_value_arr[] = reviewRangeDisplay( $range_lb, false );

$attr_arr[] = [
	'name'		=> 'Review',
	'facet'		=> 'review_score_f',
	'type'		=> 'range',
	'value_arr'	=> $review_value_arr,
	'fixed_seq'	=> true
];

// FILTER VALIDATION
// Only show filters with listings (issue with Solr facets looking at all documents as mentioned earlier)
foreach( $attr_arr as $attr_key => $attr )
{
	// Price and review filters determined after Solr request, promo is a binary filter
	if( in_array( $attr['facet'], [ 'has_promo_f', 'price_f', 'review_score_f', 'in_stock_f', 'Department' ] ) )
		continue;

	// If the attribute is not in active_filter_arr, can unset early
	if( !isset( $active_filter_arr[ $attr['facet'] ] ) )
	{
		unset( $attr_arr[ $attr_key ] );
		continue;
	}

	foreach( $attr['value_arr'] as $key => $value )
	{
		// Validated if we find the attribute value
		if( in_array( $value['name'], $active_filter_arr[ $attr['facet'] ] ) )
			continue;

		// If we can't find value, unset it as a filter
		unset( $attr_arr[ $attr_key ]['value_arr'][ $key ] );
	}

	// Resequence value array after any unsetting
	// Sort custom attribute values in ascending order
	$attr_arr[$attr_key]['value_arr'] = array_values( $attr_arr[$attr_key]['value_arr'] );
	if( !strpos( $attr['facet'], '_f' ) )
		sort( $attr_arr[ $attr_key ]['value_arr'] );
}

// Resequence attribute array after any unsetting
$attr_arr = array_values( $attr_arr );

// PRODUCT LISTER JSON
$response = [
	'listing_arr'		=> $listing_arr,
	'filter_arr'		=> $attr_arr,
	'suggestion_arr'	=> $suggestion_arr,
	'search_term_corr'	=> $search_term_corr
];

header( 'Content-Type: application/json' );
echo json_encode( $response );