<?php
/**
* @package rscds
* @author Andrew McMillan <andrew@catalyst.net.nz>
* @copyright Catalyst .Net Ltd
* @license   http://gnu.org/copyleft/gpl.html GNU GPL v2
*/

// Ensure the configuration starts out as an empty object.
unset($c);

// Default some of the configurable values
$c->sysabbr     = 'rscds';
$c->admin_email = 'andrew@catalyst.net.nz';
$c->system_name = "Really Simple CalDAV Store";
$c->domain_name = $_SERVER['SERVER_NAME'];
$c->save_time_zone_defs = true;
$c->collections_always_exist = true;
$c->home_calendar_name = 'home';
$c->enable_row_linking = true;
// $c->default_locale = array('es_MX', 'es_MX.UTF-8', 'es');
// $c->local_tzid = 'Pacific/Auckland';  // Perhaps we should read from /etc/timezone - I wonder how standard that is?
$c->default_locale = "en_NZ";
$c->base_url = preg_replace("#/[^/]+\.php.*$#", "", $_SERVER['SCRIPT_NAME']);
$c->base_directory = preg_replace("#/[^/]*$#", "", $_SERVER['DOCUMENT_ROOT']);

$c->stylesheets = array( $c->base_url."/rscds.css" );
$c->images      = $c->base_url . "/images";

// Ensure that ../inc is in our included paths as early as possible
set_include_path( '../inc'. PATH_SEPARATOR. get_include_path());

// Kind of private configuration values
$c->total_query_time = 0;

$c->dbg = array();

// Utilities
require_once("AWLUtilities.php");

/**
* Calculate the simplest form of reference to this page, excluding the PATH_INFO following the script name.
*/
$c->protocol_server_port_script = sprintf( "%s://%s%s%s", (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] == 'on'? 'https' : 'http'), $_SERVER['SERVER_NAME'],
                 (
                   ( (!isset($_SERVER['HTTPS']) || $_SERVER['HTTPS'] != 'on') && $_SERVER['SERVER_PORT'] == 80 )
                           || (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] == 'on' && $_SERVER['SERVER_PORT'] == 443 )
                   ? ''
                   : ':'.$_SERVER['SERVER_PORT']
                 ),
                 $_SERVER['SCRIPT_NAME'] );

@dbg_error_log( "LOG", "==========> method =%s= =%s= =%s= =%s= =%s=", $_SERVER['REQUEST_METHOD'], $c->protocol_server_port_script, $_SERVER['PATH_INFO'], $c->base_url, $c->base_directory );

init_gettext( 'rscds', '../locale' );

if ( file_exists("/etc/rscds/".$_SERVER['SERVER_NAME']."-conf.php") ) {
  include_once("/etc/rscds/".$_SERVER['SERVER_NAME']."-conf.php");
}
else if ( file_exists("../config/config.php") ) {
  include_once("../config/config.php");
}
else {
  include_once("rscds_configuration_missing.php");
  exit;
}
if ( !isset($c->page_title) ) $c->page_title = $c->system_name;

/**
* Now that we have loaded the configuration file we can switch to a
* default site locale.  This may be overridden by each user.
*/
awl_set_locale($c->default_locale);

/**
* Work out our version
*
*/
$c->code_version = 0;
$c->version_string = '0.8.0'; // The actual version # is replaced into that during the build /release process
if ( isset($c->version_string) && preg_match( '/(\d+)\.(\d+)\.(\d+)(.*)/', $c->version_string, $matches) ) {
  $c->code_major = $matches[1];
  $c->code_minor = $matches[1];
  $c->code_patch = $matches[1];
  $c->code_version = (($c->code_major * 1000) + $c->code_minor).".".$c->code_patch;
}
dbg_error_log("caldav", "Version %s (%d.%d.%d) == %s", $c->code_pkgver, $c->code_major, $c->code_minor, $c->code_patch, $c->code_version);
header( sprintf("Server: %s/%d.%d", $c->code_pkgver, $c->code_major, $c->code_minor) );

/**
* Force the domain name to what was in the configuration file
*/
$_SERVER['SERVER_NAME'] = $c->domain_name;

include_once("PgQuery.php");

$c->schema_version = 0;
$qry = new PgQuery( "SELECT schema_major, schema_minor, schema_patch FROM awl_db_revision ORDER BY schema_id DESC LIMIT 1;" );
if ( $qry->Exec("always") && $row = $qry->Fetch() ) {
  $c->schema_version = doubleval( sprintf( "%d%03d.%03d", $row->schema_major, $row->schema_minor, $row->schema_patch) );
  $c->schema_major = $row->schema_major;
  $c->schema_minor = $row->schema_minor;
  $c->schema_patch = $row->schema_patch;
}

$_known_users = array();
function getUserByName( $username ) {
  // Provide some basic caching in case this ends up being overused.
  if ( isset( $_known_users[$username] ) ) return $_known_users[$username];

  $qry = new PgQuery( "SELECT * FROM usr WHERE lower(username) = lower(?) ", $username );
  if ( $qry->Exec('always',__LINE__,__FILE__) && $qry->rows == 1 ) {
    $_known_users[$username] = $qry->Fetch();
    return $_known_users[$username];
  }

  return false;
}


$http_status_codes = array(
    '100' =>  "Continue",
    '101' =>  "Switching Protocols",
    '200' =>  "OK",
    '201' =>  "Created",
    '202' =>  "Accepted",
    '203' =>  "Non-Authoritative Information",
    '204' =>  "No Content",
    '205' =>  "Reset Content",
    '206' =>  "Partial Content",
    '207' =>  "Multi-Status",
    '300' =>  "Multiple Choices",
    '301' =>  "Moved Permanently",
    '302' =>  "Found",
    '303' =>  "See Other",
    '304' =>  "Not Modified",
    '305' =>  "Use Proxy",
    '307' =>  "Temporary Redirect",
    '400' =>  "Bad Request",
    '401' =>  "Unauthorized",
    '402' =>  "Payment Required",
    '403' =>  "Forbidden",
    '404' =>  "Not Found",
    '405' =>  "Method Not Allowed",
    '406' =>  "Not Acceptable",
    '407' =>  "Proxy Authentication Required",
    '408' =>  "Request Timeout",
    '409' =>  "Conflict",
    '410' =>  "Gone",
    '411' =>  "Length Required",
    '412' =>  "Precondition Failed",
    '413' =>  "Request Entity Too Large",
    '414' =>  "Request-URI Too Long",
    '415' =>  "Unsupported Media Type",
    '416' =>  "Requested Range Not Satisfiable",
    '417' =>  "Expectation Failed",
    '422' =>  "Unprocessable Entity",
    '423' =>  "Locked",
    '424' =>  "Failed Dependency",
    '500' =>  "Internal Server Error",
    '501' =>  "Not Implemented",
    '502' =>  "Bad Gateway",
    '503' =>  "Service Unavailable",
    '504' =>  "Gateway Timeout",
    '505' =>  "HTTP Version Not Supported");

function getStatusMessage($status) {
  return $http_status_codes[$status];
}

?>
