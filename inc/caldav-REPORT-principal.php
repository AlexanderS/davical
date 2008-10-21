<?php

$responses = array();


/**
 * Build the array of properties to include in the report output
 */
$searches = $xmltree->GetPath('/DAV::principal-property-search/DAV::property-search');
dbg_log_array( "principal", "SEARCH", $searches, true );

$where = "";
foreach( $searches AS $k => $search ) {
  $qry_props = $search->GetPath('/DAV::property-search/DAV::prop/*');  // There may be many
  $match     = $search->GetPath('/DAV::property-search/DAV::match');   // There may only be one
  dbg_log_array( "principal", "MATCH", $match, true );
  $match = qpg($match[0]->GetContent());
  $subwhere = "";
  foreach( $qry_props AS $k1 => $v1 ) {
    if ( $subwhere != "" ) $subwhere .= " OR ";
    switch( $v1->GetTag() ) {
      case 'DAV::displayname':
        $subwhere .= "username = ".$match;
        break;
      default:
        printf("Unhandled tag '%s'\n", $v1->GetTag() );
    }
  }
  if ( $subwhere != "" ) {
    $where .= sprintf( "%s(%s)", ($where == "" ? "" : " AND "), $subwhere );
  }
}
if ( $where != "" ) $where = "WHERE $where";
$sql = "SELECT * FROM usr $where";
$qry = new PgQuery($sql);


$get_props = $xmltree->GetPath('/DAV::principal-property-search/DAV::prop/*');
$properties = array();
foreach( $get_props AS $k1 => $v1 ) {
  $properties[] = $v1->GetTag();
}

if ( $qry->Exec("REPORT",__LINE__,__FILE__) && $qry->rows > 0 ) {
  while( $row = $qry->Fetch() ) {
    $principal = new CalDAVPrincipal($row);
    $responses[] = $principal->RenderAsXML( $properties, &$reply );
  }
}

$multistatus = new XMLElement( "multistatus", $responses, $reply->GetXmlNsArray() );

$request->XMLResponse( 207, $multistatus );
