<?php
/**
* @package davical
* @author Andrew McMillan <andrew@mcmillan.net.nz>
* @copyright Catalyst .Net Ltd, Morphoss Ltd <http://www.morphoss.com/>
* @license   http://gnu.org/copyleft/gpl.html GNU GPL v2
*/

// Ensure the configuration starts out as an empty object.
$c = (object) array();

// Ditto for a few other global things
unset($session); unset($request); unset($dbconn);

// An ultra-simple exception handler to catch errors that occur
// before we get a more functional exception handler in place...
function early_exception_handler($exception) {
  echo "Uncaught early exception: ", $exception->getMessage(), "\n";
}
set_exception_handler('early_exception_handler');

// Default some of the configurable values
$c->sysabbr     = 'davical';
$c->admin_email = 'admin@davical.example.com';
$c->system_name = 'DAViCal CalDAV Server';
$c->domain_name = (isset($_SERVER['SERVER_NAME'])?$_SERVER['SERVER_NAME']:$_SERVER['SERVER_ADDR']);
$c->save_time_zone_defs = true;
$c->collections_always_exist = false;
$c->allow_get_email_visibility = false;
$c->permission_scan_depth = 2;
$c->home_calendar_name = 'home';
$c->enable_row_linking = true;
$c->http_auth_mode = 'Basic';
// $c->default_locale = array('es_MX', 'es_AR', 'es', 'pt');  // An array of locales to try, or just a single locale
// $c->local_tzid = 'Pacific/Auckland';  // Perhaps we should read from /etc/timezone - I wonder how standard that is?
$c->default_locale = 'en';
$c->base_url = preg_replace('#/[^/]+\.php.*$#', '', $_SERVER['SCRIPT_NAME']);
$c->base_directory = preg_replace('#/[^/]*$#', '', $_SERVER['DOCUMENT_ROOT']);
$c->default_privileges = array('read-free-busy', 'schedule-query-freebusy');

$c->stylesheets = array( $c->base_url.'/davical.css' );
$c->images      = $c->base_url . '/images';

// Add a default for newly created users
$c->template_usr = array( 'active' => true,
                          'locale' => 'en_EN',
                          'date_format_type' => 'E',
                          'email_ok' => date('Y-m-d')
                        );

$c->hide_TODO = true;                      // VTODO only visible to collection owner
$c->readonly_webdav_collections = true;    // WebDAV access is readonly

// Ensure that ../inc is in our included paths as early as possible
set_include_path( '../inc'. PATH_SEPARATOR. get_include_path());

// Kind of private configuration values
$c->total_query_time = 0;

$c->dbg = array();

// Utilities
require_once('AWLUtilities.php');

/** We actually discovered this and worked around it earlier, but we can't log it until the utilties are loaded */
if ( !isset($_SERVER['SERVER_NAME']) ) {
  @dbg_error_log( 'WARN', "Your webserver is not setting the SERVER_NAME parameter. You may need to set \$c->domain_name in your configuration.  Using IP address meanhwhile..." );
}

/**
* Calculate the simplest form of reference to this page, excluding the PATH_INFO following the script name.
*/
$c->protocol_server_port = sprintf( '%s://%s%s',
                 (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] == 'on'? 'https' : 'http'),
                 $_SERVER['SERVER_NAME'],
                 (
                   ( (!isset($_SERVER['HTTPS']) || $_SERVER['HTTPS'] != 'on') && $_SERVER['SERVER_PORT'] == 80 )
                           || (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] == 'on' && $_SERVER['SERVER_PORT'] == 443 )
                   ? ''
                   : ':'.$_SERVER['SERVER_PORT']
                 ) );
$c->protocol_server_port_script = $c->protocol_server_port . ($_SERVER['SCRIPT_NAME'] == '/index.php' ? '' : $_SERVER['SCRIPT_NAME']);

init_gettext( 'davical', '../locale' );

/**
* We use @file_exists because things like open_basedir might noisily deny
* access which could break DAViCal completely by causing output to start
* too early.
*/
if ( @file_exists('/etc/davical/'.$_SERVER['SERVER_NAME'].'-conf.php') ) {
  include('/etc/davical/'.$_SERVER['SERVER_NAME'].'-conf.php');
}
else if ( @file_exists('/etc/davical/config.php') ) {
  include('/etc/davical/config.php');
}
else if ( @file_exists('../config/config.php') ) {
  include('../config/config.php');
}
else {
  include('davical_configuration_missing.php');
  exit;
}
if ( isset($c->deny_put_collection) ) {
  @dbg_error_log( 'WARN', 'Deprecated "deny_put_collection" configuration item renamed to "readonly_webdav_collections"' );
  $c->readonly_webdav_collections = $c->deny_put_collection;
}

if ( !isset($c->page_title) ) $c->page_title = $c->system_name;

if ( count($c->dbg) > 0 ) {
  // Only log this if debugging of some sort is turned on, somewhere
  @dbg_error_log( 'LOG', '==========> method =%s= =%s= =%s= =%s= =%s=',
         $_SERVER['REQUEST_METHOD'], $c->protocol_server_port_script, $_SERVER['PATH_INFO'], $c->base_url, $c->base_directory );
}

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
$c->version_string = '0.9.8'; // The actual version # is replaced into that during the build /release process
if ( isset($c->version_string) && preg_match( '/(\d+)\.(\d+)\.(\d+)(.*)/', $c->version_string, $matches) ) {
  $c->code_major = $matches[1];
  $c->code_minor = $matches[2];
  $c->code_patch = $matches[3];
  $c->code_version = (($c->code_major * 1000) + $c->code_minor).'.'.$c->code_patch;
  dbg_error_log('caldav', 'Version (%d.%d.%d) == %s', $c->code_major, $c->code_minor, $c->code_patch, $c->code_version);
  header( sprintf('Server: %d.%d', $c->code_major, $c->code_minor) );
}

/**
* Force the domain name to what was in the configuration file
*/
$_SERVER['SERVER_NAME'] = $c->domain_name;

require_once('PgQuery.php');

$c->want_dbversion = array(1,2,7);
$c->schema_version = 0;
$qry = new PgQuery( 'SELECT schema_major, schema_minor, schema_patch FROM awl_db_revision ORDER BY schema_id DESC LIMIT 1;' );
if ( $qry->Exec('always') && $row = $qry->Fetch() ) {
  $c->schema_version = doubleval( sprintf( '%d%03d.%03d', $row->schema_major, $row->schema_minor, $row->schema_patch) );
  $c->wanted_version = doubleval( sprintf( '%d%03d.%03d', $c->want_dbversion[0], $c->want_dbversion[1], $c->want_dbversion[2]) );
  $c->schema_major = $row->schema_major;
  $c->schema_minor = $row->schema_minor;
  $c->schema_patch = $row->schema_patch;
  if ( $c->schema_version < $c->wanted_version ) {
    $c->messages[] = sprintf( 'Database schema needs upgrading. Current: %d.%d.%d, Desired: %d.%d.%d',
             $row->schema_major, $row->schema_minor, $row->schema_patch, $c->want_dbversion[0], $c->want_dbversion[1], $c->want_dbversion[2]);
  }
}


$_known_users_name = array();
$_known_users_id   = array();
/**
* Return a user record identified by a username, caching it for any subsequent lookup
* @param string $username The username of the record to retrieve
* @param boolean $use_cache Whether or not to use the cache (default: yes)
*/
function getUserByName( $username, $use_cache = true ) {
  // Provide some basic caching in case this ends up being overused.
  if ( $use_cache && isset( $_known_users_name[$username] ) ) return $_known_users_name[$username];

  global $c, $session;
  if ( isset($session->user_no) )
    $qry = new PgQuery( "SELECT *, to_char(updated at time zone 'GMT','Dy, DD Mon IYYY HH24:MI:SS \"GMT\"') AS modified, principal.*, pprivs(?::int8,principal.principal_id,?::int) AS privileges FROM usr LEFT JOIN principal USING(user_no) WHERE lower(username) = lower(?) ", $session->principal_id, $c->permission_scan_depth, $username );
  else
    $qry = new PgQuery( "SELECT *, to_char(updated at time zone 'GMT','Dy, DD Mon IYYY HH24:MI:SS \"GMT\"') AS modified, principal.*, 0::BIT(24) AS privileges FROM usr LEFT JOIN principal USING(user_no) WHERE lower(username) = lower(?) ", $username );
  if ( $qry->Exec('always',__LINE__,__FILE__) && $qry->rows == 1 ) {
    $_known_users_name[$username] = $qry->Fetch();
    $id = $_known_users_name[$username]->user_no;
    $_known_users_id[$id] = $_known_users_name[$username];
    return $_known_users_name[$username];
  }

  return false;
}


/**
* Return a user record identified by a user_no, caching it for any subsequent lookup
* @param int $user_no The ID of the record to retrieve
* @param boolean $use_cache Whether or not to use the cache (default: yes)
*/
function getUserByID( $user_no, $use_cache = true ) {
  // Provide some basic caching in case this ends up being overused.
  if ( $use_cache && isset( $_known_users_id[$user_no] ) ) return $_known_users_id[$user_no];

  global $c, $session;
  $qry = new PgQuery( "SELECT *, to_char(updated at time zone 'GMT','Dy, DD Mon IYYY HH24:MI:SS \"GMT\"') AS modified, principal.*, pprivs(?::int8,principal.principal_id,?::int) AS privileges FROM usr LEFT JOIN principal USING(user_no) WHERE user_no = ? ", $session->principal_id, $c->permission_scan_depth, intval($user_no) );
  if ( $qry->Exec('always',__LINE__,__FILE__) && $qry->rows == 1 ) {
    $_known_users_id[$user_no] = $qry->Fetch();
    $name = $_known_users_id[$user_no]->username;
    $_known_users_name[$name] = $_known_users_id[$user_no];
    return $_known_users_id[$user_no];
  }

  return false;
}


/**
 * Return the HTTP status code description for a given code. Hopefully
 * this is an efficient way to code this.
 * @return string The text for a give HTTP status code, in english
 */
function getStatusMessage($status) {
  switch( $status ) {
    case 100:  $ans = 'Continue';                             break;
    case 101:  $ans = 'Switching Protocols';                  break;
    case 200:  $ans = 'OK';                                   break;
    case 201:  $ans = 'Created';                              break;
    case 202:  $ans = 'Accepted';                             break;
    case 203:  $ans = 'Non-Authoritative Information';        break;
    case 204:  $ans = 'No Content';                           break;
    case 205:  $ans = 'Reset Content';                        break;
    case 206:  $ans = 'Partial Content';                      break;
    case 207:  $ans = 'Multi-Status';                         break;
    case 300:  $ans = 'Multiple Choices';                     break;
    case 301:  $ans = 'Moved Permanently';                    break;
    case 302:  $ans = 'Found';                                break;
    case 303:  $ans = 'See Other';                            break;
    case 304:  $ans = 'Not Modified';                         break;
    case 305:  $ans = 'Use Proxy';                            break;
    case 307:  $ans = 'Temporary Redirect';                   break;
    case 400:  $ans = 'Bad Request';                          break;
    case 401:  $ans = 'Unauthorized';                         break;
    case 402:  $ans = 'Payment Required';                     break;
    case 403:  $ans = 'Forbidden';                            break;
    case 404:  $ans = 'Not Found';                            break;
    case 405:  $ans = 'Method Not Allowed';                   break;
    case 406:  $ans = 'Not Acceptable';                       break;
    case 407:  $ans = 'Proxy Authentication Required';        break;
    case 408:  $ans = 'Request Timeout';                      break;
    case 409:  $ans = 'Conflict';                             break;
    case 410:  $ans = 'Gone';                                 break;
    case 411:  $ans = 'Length Required';                      break;
    case 412:  $ans = 'Precondition Failed';                  break;
    case 413:  $ans = 'Request Entity Too Large';             break;
    case 414:  $ans = 'Request-URI Too Long';                 break;
    case 415:  $ans = 'Unsupported Media Type';               break;
    case 416:  $ans = 'Requested Range Not Satisfiable';      break;
    case 417:  $ans = 'Expectation Failed';                   break;
    case 422:  $ans = 'Unprocessable Entity';                 break;
    case 423:  $ans = 'Locked';                               break;
    case 424:  $ans = 'Failed Dependency';                    break;
    case 500:  $ans = 'Internal Server Error';                break;
    case 501:  $ans = 'Not Implemented';                      break;
    case 502:  $ans = 'Bad Gateway';                          break;
    case 503:  $ans = 'Service Unavailable';                  break;
    case 504:  $ans = 'Gateway Timeout';                      break;
    case 505:  $ans = 'HTTP Version Not Supported';           break;
    default:   $ans = 'Unknown HTTP Status Code '.$status;
  }
  return $ans;
}


/**
* Construct a URL from the supplied dav_name.  The URL will be urlencoded,
* except for any '/' characters in it.
* @param string $partial_path  The part of the path after the script name
*/
function ConstructURL( $partial_path, $force_script = false ) {
  global $c;

  $partial_path = rawurlencode($partial_path);
  $partial_path = str_replace( '%2F', '/', $partial_path);

  if ( ! isset($c->_url_script_path) ) {
    $c->_url_script_path = (preg_match('#/$#', $c->protocol_server_port_script) ? 'caldav.php' : '');
    $c->_url_script_path = $c->protocol_server_port_script . $c->_url_script_path;
  }

  $url = $c->_url_script_path;
  if ( $force_script ) {
    if ( ! preg_match( '#/caldav\.php$#', $url ) ) $url .= '/caldav.php';
  }
  $url .= $partial_path;
  $url = preg_replace( '#^(https?://.+)//#', '$1/', $url );  // Ensure we don't double any '/'
  $url = preg_replace('#^https?://[^/]+#', '', $url );       // Remove any protocol + hostname portion

  return $url;
}


/**
* Deconstruct a dav_name from the supplied URL.  The dav_name will be urldecoded.
*
* @param string $partial_path  The part of the path after the script name
*/
function DeconstructURL( $url, $force_script = false ) {
  global $c;

  $dav_name = rawurldecode($url);

  /** Allow a path like .../username/calendar.ics to translate into the calendar URL */
  if ( preg_match( '#^(/[^/]+/[^/]+).ics$#', $dav_name, $matches ) ) {
    $dav_name = $matches[1]. '/';
  }

  /** remove any leading protocol/server/port/prefix... */
  if ( !isset($c->deconstruction_base_path) ) $c->deconstruction_base_path = ConstructURL('/');
  if ( preg_match( '%^(.*?)'.str_replace('%', '\\%',$c->deconstruction_base_path).'(.*)$%', $dav_name, $matches ) ) {
    if ( $matches[1] == '' || $matches[1] == $c->protocol_server_port ) {
      $dav_name = $matches[2];
    }
  }

  /** strip doubled slashes */
  if ( strstr($dav_name,'//') ) $dav_name = preg_replace( '#//+#', '/', $dav_name);

  if ( substr($dav_name,0,1) != '/' ) $dav_name = '/'.$dav_name;

  return $dav_name;
}


/**
* Convert a date from ISO format into the sad old HTTP format.
* @param string $isodate The date to convert
*/
function ISODateToHTTPDate( $isodate ) {
  // Use strtotime since strptime is not available on Windows platform.
  return( gmstrftime('%a, %d %b %Y %T GMT', strtotime($isodate)) );
}

/**
* Convert a date into ISO format into the sparkly new ISO format.
* @param string $indate The date to convert
*/
function DateToISODate( $indate ) {
  // Use strtotime since strptime is not available on Windows platform.
  return( date('c', strtotime($indate)) );
}

/**
* Given a privilege string, or an array of privilege strings, return a bit mask
* of the privileges.
* @param mixed $raw_privs The string (or array of strings) of privilege names
* @return integer A bit mask of the privileges.
*/
define("DAVICAL_MAXPRIV", "65535");
function privilege_to_bits( $raw_privs ) {
  $out_priv = 0;

  if ( gettype($raw_privs) == 'string' ) $raw_privs = array( $raw_privs );

  if ( ! is_array($raw_privs) ) $raw_privs = array($raw_privs);

  foreach( $raw_privs AS $priv ) {
    $trim_priv = trim(strtolower(preg_replace( '/^.*:/', '', $priv)));
    switch( $trim_priv ) {
      case 'read'                            : $out_priv |=     1;  break;
      case 'write-properties'                : $out_priv |=     2;  break;
      case 'write-content'                   : $out_priv |=     4;  break;
      case 'unlock'                          : $out_priv |=     8;  break;
      case 'read-acl'                        : $out_priv |=    16;  break;
      case 'read-current-user-privilege-set' : $out_priv |=    32;  break;
      case 'bind'                            : $out_priv |=    64;  break;
      case 'unbind'                          : $out_priv |=   128;  break;
      case 'write-acl'                       : $out_priv |=   256;  break;
      case 'read-free-busy'                  : $out_priv |=   512;  break;
      case 'schedule-deliver-invite'         : $out_priv |=  1024;  break;
      case 'schedule-deliver-reply'          : $out_priv |=  2048;  break;
      case 'schedule-query-freebusy'         : $out_priv |=  4096;  break;
      case 'schedule-send-invite'            : $out_priv |=  8192;  break;
      case 'schedule-send-reply'             : $out_priv |= 16384;  break;
      case 'schedule-send-freebusy'          : $out_priv |= 32768;  break;

      /** Aggregates of Privileges */
      case 'write'                           : $out_priv |=   198;  break; // 2 + 4 + 64 + 128
      case 'schedule-deliver'                : $out_priv |=  7168;  break; // 1024 + 2048 + 4096
      case 'schedule-send'                   : $out_priv |= 57344;  break; // 8192 + 16384 + 32768
      case 'all'                             : $out_priv  = DAVICAL_MAXPRIV;  break;
      default:
        dbg_error_log( 'ERROR', 'Cannot convert privilege of "%s" into bits.', $priv );

    }
  }

  // 'all' will include future privileges
  if ( $out_priv >= DAVICAL_MAXPRIV ) $out_priv = pow(2,25) - 1;
  return $out_priv;
}


/**
* Given a bit mask of the privileges, will return an array of the
* text values of privileges.
* @param integer $raw_bits A bit mask of the privileges.
* @return mixed The string (or array of strings) of privilege names
*/
function bits_to_privilege( $raw_bits ) {
  $out_priv = array();

  if ( is_string($raw_bits) ) {
    $raw_bits = bindec($raw_bits);
  }

  if ( ($raw_bits & DAVICAL_MAXPRIV) == DAVICAL_MAXPRIV ) $out_priv[] = 'all';

  if ( ($raw_bits &   1) != 0 ) $out_priv[] = 'DAV::read';
  if ( ($raw_bits &   8) != 0 ) $out_priv[] = 'DAV::unlock';
  if ( ($raw_bits &  16) != 0 ) $out_priv[] = 'DAV::read-acl';
  if ( ($raw_bits &  32) != 0 ) $out_priv[] = 'DAV::read-current-user-privilege-set';
  if ( ($raw_bits & 256) != 0 ) $out_priv[] = 'DAV::write-acl';
  if ( ($raw_bits & 512) != 0 ) $out_priv[] = 'urn:ietf:params:xml:ns:caldav:read-free-busy';

  if ( ($raw_bits & 198) != 0 ) {
    if ( ($raw_bits & 198) == 198 ) $out_priv[] = 'DAV::write';
    if ( ($raw_bits &   2) != 0 ) $out_priv[] = 'DAV::write-properties';
    if ( ($raw_bits &   4) != 0 ) $out_priv[] = 'DAV::write-content';
    if ( ($raw_bits &  64) != 0 ) $out_priv[] = 'DAV::bind';
    if ( ($raw_bits & 128) != 0 ) $out_priv[] = 'DAV::unbind';
  }

  if ( ($raw_bits & 7168) != 0 ) {
    if ( ($raw_bits & 7168) == 7168 ) $out_priv[] = 'urn:ietf:params:xml:ns:caldav:schedule-deliver';
    if ( ($raw_bits & 1024) != 0 ) $out_priv[] = 'urn:ietf:params:xml:ns:caldav:schedule-deliver-invite';
    if ( ($raw_bits & 2048) != 0 ) $out_priv[] = 'urn:ietf:params:xml:ns:caldav:schedule-deliver-reply';
    if ( ($raw_bits & 4096) != 0 ) $out_priv[] = 'urn:ietf:params:xml:ns:caldav:schedule-query-freebusy';
  }

  if ( ($raw_bits & 57344) != 0 ) {
    if ( ($raw_bits & 57344) == 57344 ) $out_priv[] = 'urn:ietf:params:xml:ns:caldav:schedule-send';
    if ( ($raw_bits &  8192) != 0 ) $out_priv[] = 'urn:ietf:params:xml:ns:caldav:schedule-send-invite';
    if ( ($raw_bits & 16384) != 0 ) $out_priv[] = 'urn:ietf:params:xml:ns:caldav:schedule-send-reply';
    if ( ($raw_bits & 32768) != 0 ) $out_priv[] = 'urn:ietf:params:xml:ns:caldav:schedule-send-freebusy';
  }

//  dbg_error_log( 'DAVResource', ' Privilege bit "%s" is "%s".', $raw_bits, implode(', ', $out_priv) );

  return $out_priv;
}
