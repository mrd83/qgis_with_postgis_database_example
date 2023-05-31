#!/usr/bin/env php
<?php
/*
 *  lms-qgis_connector.php v.0.1 pre-alpha
 *  for LMS 27.52+
 *
 *  (C) 2022 Michał Dąbrowski, michal@euro-net.pl
 *
 *  regarding the excessive amount of comments:
 *  "Always code as if the guy who ends up maintaining your code
 *  will be a violent psychopath who knows where you live." – Martin Golding
 *
 *
 *
 *  This program is free software; you can redistribute it and/or modify
 *  it under the terms of the GNU General Public License Version 2 as
 *  published by the Free Software Foundation.
 *
 *  This program is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU General Public License for more details.
 *
 *
 *  $Id$
 */

# quick guide:
# 1. add your qgis db connection data to /etc/lms/lms.ini like so:
# [qgisdatabase]
# type =
# host =
# user =
# password =
# database =
# 2. read all the comments :)
# 3. sorry, it's far from finished...
#
### user variables

### end of user variables

# PLEASE DO NOT MODIFY ANYTHING BELOW THIS LINE UNLESS YOU KNOW
# *EXACTLY* WHAT ARE YOU DOING!!!
# ************************************************************************************************

ini_set('error_reporting', E_ALL & ~E_NOTICE & ~E_DEPRECATED);

$parameters = array(
    'config-file:' => 'C:',
    'help' => 'h',
#    'initial_run' => 'i',
#    'update' => 'u',
    'full_logging' => 'f',
);

$long_to_shorts = array();
foreach ($parameters as $long => $short) {
    $long = str_replace(':', '', $long);
    if (isset($short)) {
        $short = str_replace(':', '', $short);
    }
    $long_to_shorts[$long] = $short;
}

$options = getopt(
    implode(
        '',
        array_filter(
            array_values($parameters),
            function ($value) {
                return isset($value);
            }
        )
    ),
    array_keys($parameters)
);

foreach (array_flip(array_filter($long_to_shorts, function ($value) {
    return isset($value);
})) as $short => $long) {
    if (array_key_exists($short, $options)) {
        $options[$long] = $options[$short];
        unset($options[$short]);
    }
}

if (array_key_exists('help', $options)) {
    print <<<EOF
lms-sidusis_update_netranges.php
(C) 2022 Michał Dąbrowski, michal@euro-net.pl

-C, --config-file=/etc/lms/lms.ini      alternate config file (default: /etc/lms/lms.ini).
-h, --help                              print this help and exit.
-f, --full_logging                      Creates logfile in script directory.

EOF;
    exit(0);
}


if (array_key_exists('config-file', $options)) {
    $CONFIG_FILE = $options['config-file'];
} else {
    $CONFIG_FILE = DIRECTORY_SEPARATOR . 'etc' . DIRECTORY_SEPARATOR . 'lms' . DIRECTORY_SEPARATOR . 'lms.ini';
}
echo "Using file ".$CONFIG_FILE." as config." . PHP_EOL;

if (!is_readable($CONFIG_FILE)) {
    die('Unable to read configuration file ['.$CONFIG_FILE.']!');
}

define('CONFIG_FILE', $CONFIG_FILE);

$CONFIG = (array) parse_ini_file($CONFIG_FILE, true);

// Check for configuration vars and set default values
$CONFIG['directories']['sys_dir'] = (!isset($CONFIG['directories']['sys_dir']) ? getcwd() : $CONFIG['directories']['sys_dir']);
$dbtype = $CONFIG['database']['type'];

define('SYS_DIR', $CONFIG['directories']['sys_dir']);

# set qgis connection options:
$qgisdbtype = $CONFIG['qgisdatabase']['type'];
$qgisdbhost = $CONFIG['qgisdatabase']['host'];
$qgisdbuser = $CONFIG['qgisdatabase']['user'];
$qgisdbpassword = $CONFIG['qgisdatabase']['password'];
$qgisdatabase = $CONFIG['qgisdatabase']['database'];

// Load autoloader
$composer_autoload_path = SYS_DIR . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';
if (file_exists($composer_autoload_path)) {
    require_once $composer_autoload_path;
} else {
    die("Composer autoload not found. Run 'composer install' command from LMS directory and try again. More information at https://getcomposer.org/" . PHP_EOL);
}

# check db type
if ($dbtype == 'postgres') {
    echo "Found supported LMS DB type.\n";
} else {
    die("Fatal error: unsupported DB type! Only postgresql DB is supported! Exiting...\n" . PHP_EOL);
}
$full_logging = false;
# check user vars
if (array_key_exists('full_logging', $options)) {
    $full_logging = array_key_exists('full_logging', $options);
}

// Init database
$DB = null;

try {
    $DB = LMSDB::getInstance();
    echo "Connection to LMS DB successful.\n";
} catch (Exception $ex) {
    trigger_error($ex->getMessage(), E_USER_WARNING);
    // can't work without database
    die("Fatal error: cannot connect to database!" . PHP_EOL);
}

$SYSLOG = SYSLOG::getInstance();

// Initialize Session, Auth and LMS classes

$AUTH = null;
$LMS = new LMS($DB, $AUTH, $SYSLOG);

# Init QGIS database:
$qgis_db_connection = pg_connect("host = '$qgisdbhost' dbname = '$qgisdatabase' user = '$qgisdbuser' password = '$qgisdbpassword'");
$sql = pg_fetch_assoc(pg_query($qgis_db_connection,"SELECT version();"));
if (!empty($sql)) {
    echo "Connection to QGIS DB successful. Found version: {$sql['version']} \n";
} else {
    die("Fatal error: cannot connect to QGIS database!" . PHP_EOL);
}

# create and open log file
if ($full_logging === true) {
    echo "Full log parameter found. Proceeding with full logging...\n\n";
    $LOGFILE = fopen("lms-qgis_connector.log", "w"); # modes: w - overwrite existing, a - append to existing
}

$distinct_addresspoints_array = array();
$main_services_in_addresses_array = array();
$logdata = "";

##### MAIN
# [did you also start learning programming with the C language? MAIN rules! xD]
get_distinct_addresspoints();
parse_addresspoints();
update_qgis();

if ($full_logging === true) {
    fwrite($LOGFILE, $logdata);
    fclose($LOGFILE);
}
##### end of MAIN
function get_distinct_addresspoints() : array {
    global $DB, $distinct_addresspoints_array, $logdata;

    $distinct_addresspoints_query = "WITH mymainquery AS 
    (
         SELECT n.id AS lms_dev_id, n.name AS lms_netdev_name, n.netdevicemodelid, n.producer, n.model,
            concat(n.id, '_', replace(n.name::text, ' '::text, ''::text)) AS uke_report_namepart,
                CASE
                    WHEN n.id = nl.src THEN nl.dst
                    WHEN n.id = nl.dst THEN nl.src
                    ELSE NULL::integer
                END AS destinationdevid,
            n.longitude AS dlugosc,
            n.latitude AS szerokosc,
            concat(ls.ident, ld.ident, lb.ident, lb.type) AS terc,
            lc.ident AS simc,
            lst.ident AS ulic,
            addr.house AS nr_porzadkowy,
            n.address_id AS lms_addr_id,
            concat(lst.name2, ' ', lst.name) AS ulica,
            lc.name AS city_name
           FROM netdevices n
             LEFT JOIN addresses addr ON n.address_id = addr.id
             LEFT JOIN location_streets lst ON lst.id = addr.street_id
             LEFT JOIN location_cities lc ON lc.id = addr.city_id
             LEFT JOIN location_boroughs lb ON lb.id = lc.boroughid
             LEFT JOIN location_districts ld ON ld.id = lb.districtid
             LEFT JOIN location_states ls ON ls.id = ld.stateid
             JOIN netlinks nl ON n.id = nl.src OR n.id = nl.dst
          ORDER BY n.id
    ), mynetlinkscount AS 
    (
            SELECT mymainquery.lms_dev_id, count(mymainquery.lms_dev_id) AS linkscount
            FROM mymainquery
            GROUP BY mymainquery.lms_dev_id
    )
    SELECT DISTINCT ON (main.terc, main.simc, main.ulic, main.nr_porzadkowy) count.linkscount, main.lms_dev_id, main.lms_netdev_name, main.netdevicemodelid, main.producer, main.model, 
    main.uke_report_namepart, main.destinationdevid, main.dlugosc, main.szerokosc, main.terc, main.simc, main.ulic, main.nr_porzadkowy, main.lms_addr_id, main.ulica, main.city_name
    FROM mymainquery main
    JOIN mynetlinkscount count ON main.lms_dev_id = count.lms_dev_id
    ORDER BY main.terc, main.simc, main.ulic, main.nr_porzadkowy, count.linkscount DESC, main.lms_dev_id";

    $distinct_addresspoints_array = $DB->GetAll($distinct_addresspoints_query, array());
    #var_dump($distinct_addresspoints_array);

    echo "Successfully fetched " . count($distinct_addresspoints_array) . " distinct address points. Proceeding...\n\n";
    $logdata .= "Successfully fetched " . count($distinct_addresspoints_array) . " distinct address points. Proceeding...\n\n";
    return array();
}

function parse_addresspoints() : array {
    global $DB, $distinct_addresspoints_array, $main_services_in_addresses_array, $LOGFILE, $logdata;
    $today = strtotime('today');
    $tmpcounter = 0;
    $addresspoints_counter = 0;
    $services_counter = 0;

    foreach ($distinct_addresspoints_array as $current_address) {
        # let's try to find other devices @ that address and/or at the sam coordinates:
        $temporary_other_devices_array = array();
        $temporary_services_in_addresses_array = array();

        $current_lms_dev_id = $current_address['lms_dev_id'];
        $current_lms_netdev_name = $current_address['lms_netdev_name'];
        $current_netdevicemodelid = $current_address['netdevicemodelid'];
        $current_common_namepart = $current_address['uke_report_namepart'];
        $current_terc = $current_address['terc'];
        $current_simc = $current_address['simc'];
        $current_ulic = $current_address['ulic'];
        $current_nr_porzadkowy = $current_address['nr_porzadkowy'];
        $current_dlugosc = $current_address['dlugosc'];
        $current_szerokosc = $current_address['szerokosc'];
        $current_ua11_technologia_dostepowa = "";

        $other_devices_at_this_address_query = "SELECT 1 AS linkscount, n.id AS lms_dev_id, n.name AS lms_netdev_name, n.netdevicemodelid, n.producer, n.model,
                concat(n.id, '_', replace(n.name::text, ' '::text, ''::text)) AS uke_report_namepart, 1 AS destinationdevid,
                n.longitude AS dlugosc,
                n.latitude AS szerokosc,
                concat(ls.ident, ld.ident, lb.ident, lb.type) AS terc,
                lc.ident AS simc,
                lst.ident AS ulic,
                addr.house AS nr_porzadkowy,
                n.address_id AS lms_addr_id,
                concat(lst.name2, ' ', lst.name) AS ulica,
                lc.name AS city_name
                FROM netdevices n
                LEFT JOIN addresses addr ON n.address_id = addr.id
                LEFT JOIN location_streets lst ON lst.id = addr.street_id
                LEFT JOIN location_cities lc ON lc.id = addr.city_id
                LEFT JOIN location_boroughs lb ON lb.id = lc.boroughid
                LEFT JOIN location_districts ld ON ld.id = lb.districtid
                LEFT JOIN location_states ls ON ls.id = ld.stateid
                WHERE n.name NOT LIKE '%OLT-%' AND n.ports > 0 AND n.id != ? AND ((concat(ls.ident, ld.ident, lb.ident, lb.type) = ? 
                AND lc.ident = ? AND lst.ident = ? AND addr.house = ?) OR (n.longitude = ? AND n.latitude = ?))
                ORDER BY n.id";

        $node_name_query = "SELECT name FROM nodes WHERE name ILIKE 'SW-%' AND netdev = ?";
        $current_nodename = $DB->GetRow($node_name_query, array($current_lms_dev_id));
        $temporary_dev_id_string = $current_lms_dev_id;

        # first let's check if our device is passive (netdevicemodelid = 395, 398 and 400 in my LMS system) or a switch:
        if ($current_netdevicemodelid == 395 OR $current_netdevicemodelid == 398 OR $current_netdevicemodelid == 400 OR str_starts_with($current_lms_netdev_name, 'OLT-')) {
            /* left for later :))
            $tmp = $DB->GetAll($other_devices_at_this_address_query, array($current_lms_dev_id, $current_terc, $current_simc, $current_ulic, $current_nr_porzadkowy, $current_dlugosc, $current_szerokosc));
            if (!empty($tmp)) {
                $temporary_other_devices_array = $tmp;
                echo "\n\nFOUND " . count($tmp) . " OTHER DEVICES at the same address point! Current dev: " .  $current_lms_dev_id . " name: " . $current_nodename . " other devices: \n";
                foreach ($temporary_other_devices_array as $temp) {
                    echo $temp['lms_dev_id'] . " <---ID\n";
                }
                #var_dump($temporary_other_devices_array);
                #$tmpcounter += count($tmp);
            }
            $current_ua11_technologia_dostepowa = "GPON";
            */
            continue;
            #echo "passive or gpon found, current dev: " .  $current_lms_dev_id . " name: " . $current_lms_netdev_name . "\n";
        } elseif (!empty($current_nodename)) {
            # Our address point is based on a switch. Let's get other (if any) switches @ this address:
            $current_ua11_technologia_dostepowa = "1 Gigabit Ethernet";
            $other_devices = $DB->GetAll($other_devices_at_this_address_query, array($current_lms_dev_id, $current_terc, $current_simc, $current_ulic, $current_nr_porzadkowy, $current_dlugosc, $current_szerokosc));
            #var_dump($other_devices);

            if (!empty($other_devices)) {
                $temporary_other_devices_array = $other_devices;
                $temporary_other_devices_array[] = $current_address;
                #var_dump($temporary_other_devices_array);
                #echo "Found " . count($other_devices) . " other devices at the same address point! Current dev: " .  $current_lms_dev_id . " name: " . $current_nodename['name'] . " other devices: \n";
                foreach ($temporary_other_devices_array as $temp) {
                    #echo $temp['lms_dev_id'] . " <---ID\n";
                    $temporary_dev_id_string .= ", " . $temp['lms_dev_id'];
                }
                #$tmpcounter += count($other_devices);
            } else {
                #echo "\n\nNO OTHER DEVICES at the same address point! Current dev: " .  $current_lms_dev_id . " name: " . $current_nodename['name'] . "\n";
                $temporary_other_devices_array[] = $current_address;
            }
        }
        # At this point we should have in our $temporary_other_devices_array all devices present at current address point, including main/base device
        # Let's proceed to count our services.
        # let's fetch all DISTINCT customers at a given address, with their maximum internet speed:

        # example with all tariffs for all customers at a given address:
        /*
        "SELECT vt.id, vt.name, vt.ownerid, vt.netdev, vt.linktechnology, vt.longitude, vt.latitude, vt.address_id,
               vt.downceil, vt.upceil, vt.location_city, vt.location_street, vt.location_house, vt.location_flat
        FROM vnodealltariffs vt
        WHERE vt.netdev IN (269, 670, 671) AND vt.name NOT LIKE 'SW-%'
        ORDER BY vt.ownerid";
        */
        #$temporary_dev_id_string1 = "269, 670, 671";
        # Refined query:
        $distinct_customers_query = "SELECT DISTINCT ON (vt.ownerid) vt.id, vt.name, vt.ownerid, vt.netdev, vt.linktechnology, vt.longitude, vt.latitude, 
                                    vt.address_id, vt.downceil, vt.upceil, vt.location_city, vt.location_street, vt.location_house, vt.location_flat
                                    FROM vnodealltariffs vt
                                    WHERE vt.netdev IN ($temporary_dev_id_string) AND vt.name NOT LIKE 'SW-%'
                                    ORDER BY vt.ownerid, vt.downceil DESC";

        $check_customers_phones_query = "SELECT a.id, a.tariffid, a.customerid, a.datefrom, a.dateto, t.id, t.name, t.type, t.downceil, t.upceil
                                    FROM assignments a
                                    LEFT JOIN tariffs t ON a.tariffid = t.id
                                    WHERE a.datefrom < ? AND (a.dateto = 0 OR a.dateto > ?) AND a.suspended = 0
                                    AND a.tariffid IS NOT NULL AND t.type = 4 AND a.customerid = ?";

        $check_customers_tv_query = "SELECT a.id, a.tariffid, a.customerid, a.datefrom, a.dateto, t.id, t.name, t.type, t.downceil, t.upceil
                                    FROM assignments a
                                    LEFT JOIN tariffs t ON a.tariffid = t.id
                                    WHERE a.datefrom < ? AND (a.dateto = 0 OR a.dateto > ?) AND a.suspended = 0
                                    AND a.tariffid IS NOT NULL AND t.type = 5 AND a.customerid = ?";

        $distinct_customers_at_address = $DB->GetAll($distinct_customers_query, array());
        #var_dump($distinct_customers_at_address);

        # Let's prepare data for QGIS update:
        foreach ($distinct_customers_at_address as $customer) {
            $has_phones = false;
            $has_tv = false;
            $netspeed = getClosest($customer['downceil']/1024, "value");
            $netspeed_key = getClosest($customer['downceil']/1024, "key");
            if ($netspeed !== 0) {
                # check if customer has phones:
                $customers_phones = $DB->GetAll($check_customers_phones_query, array(($today - 86400), ($today + 86400), $customer['ownerid']));
                if (!empty($customers_phones)) {
                    #var_dump($customers_phones);
                    #echo "Has phones! \n";
                    $has_phones = true;
                }
                # same for tv:
                $customers_tv = $DB->GetAll($check_customers_tv_query, array(($today - 86400), ($today + 86400), $customer['ownerid']));
                if (!empty($customers_tv)) {
                    #var_dump($customers_tv);
                    #echo "Has tv! \n";
                    $has_tv = true;
                }
                # Let's construct final array:
                $current_ua15_identyfikacja_uslugi = "";
                $current_ua18_telewizja_cyfrowa = "";
                $current_ua20_usluga_telefoniczna = "";
                $current_ua21_predkosc_uslugi_td = "";
                if ($has_tv AND $has_phones) {
                    $current_ua15_identyfikacja_uslugi = "Internet_" . $netspeed . "Mbs_Telewizja_Telefon_" . $current_common_namepart;
                    $current_ua18_telewizja_cyfrowa = "tak";
                    $current_ua20_usluga_telefoniczna = "tak";
                } elseif ($has_tv AND !$has_phones) {
                    $current_ua15_identyfikacja_uslugi = "Internet_" . $netspeed . "Mbs_Telewizja_" . $current_common_namepart;
                    $current_ua18_telewizja_cyfrowa = "tak";
                    $current_ua20_usluga_telefoniczna = "nie";
                } elseif (!$has_tv AND $has_phones) {
                    $current_ua15_identyfikacja_uslugi = "Internet_" . $netspeed . "Mbs_Telefon_" . $current_common_namepart;
                    $current_ua18_telewizja_cyfrowa = "nie";
                    $current_ua20_usluga_telefoniczna = "tak";
                } else {
                    # net only
                    $current_ua15_identyfikacja_uslugi = "Internet_" . $netspeed . "Mbs_" . $current_common_namepart;
                    $current_ua18_telewizja_cyfrowa = "nie";
                    $current_ua20_usluga_telefoniczna = "nie";
                }
                $current_ua21_predkosc_uslugi_td = $netspeed_key;
                $current_ua22_liczba_uzytkownikow_uslugi_td = 1;

                # Looking for $current_ua15_identyfikacja_uslugi value in my array:
                $search_for_id_in_tmp = array_search($current_ua15_identyfikacja_uslugi, array_column($temporary_services_in_addresses_array, 'ua15_identyfikacja_uslugi'));
                $search_for_id_in_main = array_search($current_ua15_identyfikacja_uslugi, array_column($main_services_in_addresses_array, 'ua15_identyfikacja_uslugi'));

                if ($search_for_id_in_tmp !== false) {
                    echo "Found an existing entry in temporary array: " . $current_ua15_identyfikacja_uslugi . " at key: " . $search_for_id_in_tmp . "\n";
                    $logdata .= "Found an existing entry in temporary array: " . $current_ua15_identyfikacja_uslugi . " at key: " . $search_for_id_in_tmp . "\n";
                    $temporary_services_in_addresses_array[$search_for_id_in_tmp]['ua22_liczba_uzytkownikow_uslugi_td']++;
                    $services_counter++;
                    #var_dump($temporary_services_in_addresses_array);
                } elseif ($search_for_id_in_main !== false) {
                    echo "Found an existing entry in MAIN array: " . $current_ua15_identyfikacja_uslugi . " at key: " . $search_for_id_in_main . "\n";
                    $logdata .= "Found an existing entry in MAIN array: " . $current_ua15_identyfikacja_uslugi . " at key: " . $search_for_id_in_main . "\n";
                    $main_services_in_addresses_array[$search_for_id_in_main]['ua22_liczba_uzytkownikow_uslugi_td']++;
                    $services_counter++;
                } else {
                    echo "No existing entry found: " . $current_ua15_identyfikacja_uslugi . ", adding...\n";
                    $logdata .= "No existing entry found: " . $current_ua15_identyfikacja_uslugi . ", adding...\n";
                    $addresspoints_counter++;
                    $services_counter++;
                    $temporary_services_in_addresses_array[] = array(
                        "common_namepart" => $current_common_namepart,
                        "ua01_id_punktu_adresowego" => "UA_" . $current_common_namepart . "_" . $current_ua15_identyfikacja_uslugi,
                        "ua02_id_pe" => "PE_" . $current_common_namepart,
                        "ua03_id_po" => NULL,
                        "ua04_terc" => $current_terc,
                        "ua05_simc" => $current_simc,
                        "ua06_ulic" => $current_ulic,
                        "ua07_nr_porzadkowy" => $current_nr_porzadkowy,
                        "ua08_szerokosc" => $current_szerokosc,
                        "ua09_dlugosc" => $current_dlugosc,
                        "ua10_medium_dochodzace_do_pa" => "kablowe parowe miedziane",
                        "ua11_technologia_dostepowa" => $current_ua11_technologia_dostepowa,
                        "ua12_instalacja_telekom" => "W budynku sprawozdawca nie posiada instalacji telekomunikacyjnej budynku",
                        "ua13_medium_instalacji_budynku" => NULL,
                        "ua14_technologia_dostepowa" => NULL,
                        "ua15_identyfikacja_uslugi" => $current_ua15_identyfikacja_uslugi,
                        "ua16_dostep_stacjonarny" => "tak",
                        "ua17_dostep_stacjonarny_bezprzewodowy" => "nie",
                        "ua18_telewizja_cyfrowa" => $current_ua18_telewizja_cyfrowa,
                        "ua19_radio" => "nie",
                        "ua20_usluga_telefoniczna" => $current_ua20_usluga_telefoniczna,
                        "ua21_predkosc_uslugi_td" => $current_ua21_predkosc_uslugi_td,
                        "ua22_liczba_uzytkownikow_uslugi_td" => $current_ua22_liczba_uzytkownikow_uslugi_td
                    );
                }
            }
        }
        echo "Address point " . $current_common_namepart . " done. Proceeding...\n";
        $logdata .= "Address point " . $current_common_namepart . " done. Proceeding...\n";
        $main_services_in_addresses_array = array_merge($main_services_in_addresses_array, $temporary_services_in_addresses_array);
        #var_dump($temporary_services_in_addresses_array);
        #break;
    }
    #echo "\n\n\n Number of additional devices: " . $tmpcounter . "\n";
    echo "\n\nData preparation done! Got " . $addresspoints_counter . " address points with " . $services_counter . " services in them. Lets update QGIS...\n\n";
    $logdata .= "\n\nData preparation done! Got " . $addresspoints_counter . " address points with " . $services_counter . " services in them. Lets update QGIS...\n\n";
    #var_dump($main_services_in_addresses_array);
    return array();
}

function update_qgis() : int {
    global $qgis_db_connection, $main_services_in_addresses_array, $logdata;
    $updated_addresspoints_counter = 0;
    $updated_services_counter = 0;

    echo "Starting QGIS update...\n\n";
    $logdata .= "Starting QGIS update...\n\n";
    foreach ($main_services_in_addresses_array as $single_servicepoint_key => $single_servicepoint) {
        # 1. Let's check if required main PE exists. If technology in current $single_servicepoint is GPON - we have to check for PE's only (for now)
        $pe_candidate = $single_servicepoint['ua02_id_pe'];
        $current_technology = $single_servicepoint['ua11_technologia_dostepowa'];
        $pe_check_query = pg_query($qgis_db_connection,"SELECT * FROM punkty_elastycznosci_base WHERE pe01_id_pe = '$pe_candidate';");
        $pe_check = pg_fetch_assoc($pe_check_query);
        #var_dump($pe_check);
        if (!empty($pe_check)) {
            echo "Main PE found: " . $pe_check['pe01_id_pe'] . "\n";
            $logdata .= "Main PE found: " . $pe_check['pe01_id_pe'] . "\n";
            if (!empty($pe_check['pe03_id_wezla'])) {
                # Looks like main node is present. Lets see if the name is right:
                if ($pe_check['pe03_id_wezla'] == "WW_" . $single_servicepoint['common_namepart']) {
                    echo "Main WW name found in PE table: " . $pe_check['pe03_id_wezla'] . "\n";
                    $logdata .= "Main WW name found in PE table: " . $pe_check['pe03_id_wezla'] . "\n";
                    # Now let's look for main and virtual nodes in the WW table:
                    $main_ww_name = "WW_" . $single_servicepoint['common_namepart'];
                    $main_ww_check_query = pg_query($qgis_db_connection,"SELECT * FROM wezly_base WHERE we01_id_wezla = '$main_ww_name';");
                    $main_ww_check = pg_fetch_assoc($main_ww_check_query);
                    if (!empty($main_ww_check)) {
                        echo "Main WW found in WW table: " . $main_ww_name . "\n";
                        $logdata .= "Main WW found in WW table: " . $main_ww_name . "\n";
                    } else {
                        echo "No main WW. We're probably gonna have to create it here:\n";
                        $logdata .= "No main WW. We're probably gonna have to create it here:\n";
                        /*
                         *
                         *
                         *
                         *
                         */

                    }
                    # Here we'll check what technology do we have. For GPON we'll have to change the technology in PE and see that there's WW at THE MAIN DEVICE's LOCATION
                    # For ethernet we have to create 2nd (virtual) WW:
                    if ($current_technology == "GPON") {
                        # check and update PE's technology, and then look for the main device location to create WW there
                        echo "\n\n\nFound GPON technology! At this moment it's an error!\n\n\n";
                        $logdata .= "\n\n\nFound GPON technology! At this moment it's an error!\n\n\n";
                    } elseif ($current_technology == "1 Gigabit Ethernet") {
                        # Let's look for a virtual WW (since we already checked for MAIN one):
                        $virtual_ww_name_candidate = "WW_" . $single_servicepoint['common_namepart'] . "_virtual_1_Gigabit_Ethernet";
                        $virtual_ww_check_query = pg_query($qgis_db_connection,"SELECT * FROM wezly_base WHERE we01_id_wezla = '$virtual_ww_name_candidate';");
                        $virtual_ww_check = pg_fetch_assoc($virtual_ww_check_query);
                        if (empty($virtual_ww_check)) {
                            echo "No virtual WW found, proceeding with insert: " . $virtual_ww_name_candidate . "\n";
                            $logdata .= "No virtual WW found, proceeding with insert: " . $virtual_ww_name_candidate . "\n";
                            # Prepare data for insert to WW table:
                            $common_namepart = $single_servicepoint['common_namepart'];
                            $medium = $single_servicepoint['ua10_medium_dochodzace_do_pa'];
                            $technologia = $single_servicepoint['ua11_technologia_dostepowa'];
                            $terc = $single_servicepoint['ua04_terc'];
                            $simc = $single_servicepoint['ua05_simc'];
                            $ulic = $single_servicepoint['ua06_ulic'];
                            $nr_domu = $single_servicepoint['ua07_nr_porzadkowy'];
                            $virtual_ww_insert_query = pg_query($qgis_db_connection,"INSERT INTO wezly_base (geom, common_namepart, we01_id_wezla, we02_tytul_do_wezla, we03_id_podmiotu_obcego, 
                            we04_terc, we05_simc, we06_ulic, we07_nr_porzadkowy, we08_szerokosc, we09_dlugosc, we10_medium_transmisyjne, we11_bsa, we12_technologia_dostepowa, we13_uslugi_transmisji_danych, 
                            we14_mozliwosc_zwiekszenia_liczby_interfejsow, we15_finansowanie_publ, we16_numery_projektow_publ, we17_infrastruktura_o_duzym_znaczeniu, we18_typ_interfejsu, we19_udostepnianie_ethernet) 
                            VALUES (st_point({$single_servicepoint['ua09_dlugosc']}, {$single_servicepoint['ua08_szerokosc']}, 4326), '$common_namepart', '$virtual_ww_name_candidate', 'Węzeł własny', 
                                    '', '$terc', '$simc', '$ulic', '$nr_domu', {$single_servicepoint['ua08_szerokosc']}, 
                                    {$single_servicepoint['ua09_dlugosc']}, '$medium', 'nie', '$technologia', 'Ethernet VLAN', 'nie', 'nie', '', 'nie', '02', 'tak')
                                    RETURNING fid");
                            if (!empty($virtual_ww_insert_query)) {
                                echo "Looks like a successful insert, proceeding.\n\n";
                                $logdata .= "Looks like a successful insert, proceeding.\n\n";
                            } else {
                                exit("Error on db insert to WW table! Exiting...\n");
                            }
                        } else {
                            echo "Virtual WW found: " . $virtual_ww_name_candidate . ", array key: " . $single_servicepoint_key . " Proceeding.\n";
                            $logdata .= "Virtual WW found: " . $virtual_ww_name_candidate . ", array key: " . $single_servicepoint_key . " Proceeding.\n";
                        }

                        # Since virtual WW is present we can proceed to insert our data:
                        $ua01_id_punktu_adresowego_candidate = $single_servicepoint['ua01_id_punktu_adresowego'];
                        $existing_ua_check_query = pg_query($qgis_db_connection,"SELECT * FROM uslugi_w_adresach_base WHERE ua01_id_punktu_adresowego = '$ua01_id_punktu_adresowego_candidate';");
                        $existing_ua_check = pg_fetch_assoc($existing_ua_check_query);
                        echo "Checking for existing UA candidate in db: " . $ua01_id_punktu_adresowego_candidate . ", array key: " . $single_servicepoint_key . " \n";
                        $logdata .= "Checking for existing UA candidate in db: " . $ua01_id_punktu_adresowego_candidate . ", array key: " . $single_servicepoint_key . " \n";

                        $common_namepart = $single_servicepoint['common_namepart'];
                        $id_pe = $single_servicepoint['ua02_id_pe'];
                        $ua03_id_po = $single_servicepoint['ua03_id_po'];
                        $terc = $single_servicepoint['ua04_terc'];
                        $simc = $single_servicepoint['ua05_simc'];
                        $ulic = $single_servicepoint['ua06_ulic'];
                        $nr_domu = $single_servicepoint['ua07_nr_porzadkowy'];
                        $medium = $single_servicepoint['ua10_medium_dochodzace_do_pa'];
                        $technologia = $single_servicepoint['ua11_technologia_dostepowa'];
                        $ua_12_instalacja = $single_servicepoint['ua12_instalacja_telekom'];
                        $ua13_medium = $single_servicepoint['ua13_medium_instalacji_budynku'];
                        $ua14_technologia = $single_servicepoint['ua14_technologia_dostepowa'];
                        $id_uslugi = $single_servicepoint['ua15_identyfikacja_uslugi'];
                        $tv = $single_servicepoint['ua18_telewizja_cyfrowa'];
                        $telefon = $single_servicepoint['ua20_usluga_telefoniczna'];
                        $ua21_predkosc_uslugi = $single_servicepoint['ua21_predkosc_uslugi_td'];
                        $ua22_liczba_uzytkownikow_uslugi = $single_servicepoint['ua22_liczba_uzytkownikow_uslugi_td'];

                        if (empty($existing_ua_check)) {
                            # No candidate UA name found in db
                            $updated_addresspoints_counter++;
                            $updated_services_counter += $ua22_liczba_uzytkownikow_uslugi;

                            echo "No UA found in the table: " . $ua01_id_punktu_adresowego_candidate . ", array key: " . $single_servicepoint_key . " Proceeding with db update...\n";
                            $logdata .= "No UA found in the table: " . $ua01_id_punktu_adresowego_candidate . ", array key: " . $single_servicepoint_key . " Proceeding with db update...\n";
                            $ua_insert_query = pg_query($qgis_db_connection,"INSERT INTO uslugi_w_adresach_base (geom, common_namepart, ua01_id_punktu_adresowego, ua02_id_pe, 
                                ua03_id_po, ua04_terc, ua05_simc, ua06_ulic, ua07_nr_porzadkowy, ua08_szerokosc, ua09_dlugosc, ua10_medium_dochodzace_do_pa, ua11_technologia_dostepowa, 
                                ua12_instalacja_telekom, ua13_medium_instalacji_budynku, ua14_technologia_dostepowa, ua15_identyfikacja_uslugi, ua16_dostep_stacjonarny, 
                                ua17_dostep_stacjonarny_bezprzewodowy, ua18_telewizja_cyfrowa, ua19_radio, ua20_usluga_telefoniczna, ua21_predkosc_uslugi_td, ua22_liczba_uzytkownikow_uslugi_td)
                                VALUES (st_point({$single_servicepoint['ua09_dlugosc']}, {$single_servicepoint['ua08_szerokosc']}, 4326), '$common_namepart', '$ua01_id_punktu_adresowego_candidate', 
                                        '$id_pe', '$ua03_id_po', '$terc', '$simc', '$ulic', '$nr_domu', {$single_servicepoint['ua08_szerokosc']}, {$single_servicepoint['ua09_dlugosc']}, '$medium', 
                                        '$technologia', '$ua_12_instalacja', '$ua13_medium', '$ua14_technologia', '$id_uslugi', 'tak', 'nie', '$tv', 'nie', '$telefon', '$ua21_predkosc_uslugi', 
                                        '$ua22_liczba_uzytkownikow_uslugi')
                                RETURNING fid");
                            if (!empty($ua_insert_query)) {
                                echo "Looks like a successful insert, proceeding\n\n";
                                $logdata .= "Looks like a successful insert, proceeding\n\n";
                            } else {
                                exit("Error on db insert to UA table! Exiting...\n");
                            }
                        } else {
                            echo "UA found in the db: " . $ua01_id_punktu_adresowego_candidate . ", array key: " . $single_servicepoint_key . " Proceeding...\n\n";
                            $logdata .= "UA found in the db: " . $ua01_id_punktu_adresowego_candidate . ", array key: " . $single_servicepoint_key . " Proceeding...\n\n";
                        }
                    }
                } else {
                    # There's something present in PE table, but the name is wrong. For now let's just log it:
                    echo "Wrong WW name in PE table. PE: " . $pe_check['pe01_id_pe'] . ", fid: " . $pe_check['fid'] . ", got WW: " . $pe_check['pe03_id_wezla'] . "\n";
                    $logdata .= "\n\n\nWrong WW name in PE table. PE: " . $pe_check['pe01_id_pe'] . ", fid: " . $pe_check['fid'] . ", got WW: " . $pe_check['pe03_id_wezla'] . "\n\n\n";
                }
            }
        } else {
            echo "\n\n\nMatching PE NOT FOUND!!!! " . $pe_candidate . " should exist!!! Looks lik a major problem!\n\n\n";
            $logdata .= "\n\n\nMatching PE NOT FOUND!!!! " . $pe_candidate . " should exist!!! Looks lik a major problem!\n\n\n";
        }
    }
    echo "Done with QGIS db update! Got " . $updated_addresspoints_counter . " new address points and " . $updated_services_counter . " new services in them. Closing...\n\n";
    $logdata .= "Done with QGIS db update! Got " . $updated_addresspoints_counter . " new address points and " . $updated_services_counter . " new services in them. Closing...\n\n";
    return 0;
}

#$netspeed = getClosest(1024, "value");
#$netspeed_key = getClosest(1024, "key");
#echo $netspeed . "   " . $netspeed_key . "\n";
function getClosest($search, $key_or_value) {
    $arr = array("01" => 2, "02" => 10, "03" => 20, "04" => 30, "05" => 40, "06" => 50, "07" => 60, "08" => 70, "09" => "80", "10" => 90, "11" => 100,
        "12" => 200, "13" => 300, "14" => 400, "15" => 500, "16" => 600, "17" => 700, "18" => 800, "19" => 900, "20" => 1000, "21" => 2000, "22" => 3000,
        "23" => 4000, "24" => 5000, "25" => 6000, "26" => 7000, "27" => 8000, "28" => 9000, "29" => 10000);
    $closest = null;
    $closestkey = null;
    foreach ($arr as $key => $item) {
        if ($closest === null || abs($search - $closest) > abs($item - $search)) {
            $closest = $item;
            $closestkey = $key;
        }
    }
    if ($key_or_value == "key") {
        return $closestkey;
    } elseif ($key_or_value == "value") {
        return $closest;
    } else {
        exit("Fatal error: wrong variable passed to getClosest function! Exiting...\n");
    }
}
