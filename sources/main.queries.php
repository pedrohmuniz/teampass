<?php
/**
 *
 * @file          main.queries.php
 * @author        Nils Laumaillé
 * @version       2.1.13
 * @copyright     (c) 2009-2012 Nils Laumaillé
 * @licensing     GNU AFFERO GPL 3.0
 * @link
 */

$debug_ldap = 0; //Can be used in order to debug LDAP authentication

session_start();
if (!isset($_SESSION['CPM']) || $_SESSION['CPM'] != 1) {
    die('Hacking attempt...');
}

global $k;
include $_SESSION['settings']['cpassman_dir'].'/includes/settings.php';
header("Content-type: text/html; charset=utf-8");
error_reporting(E_ERROR);
require_once $_SESSION['settings']['cpassman_dir'].'/sources/main.functions.php';
require_once $_SESSION['settings']['cpassman_dir'].'/sources/SplClassLoader.php';

// connect to the server
$db = new SplClassLoader('Database\Core', '../includes/libraries');
$db->register();
$db = new Database\Core\DbCore($server, $user, $pass, $database, $pre);
$db->connect();

//Load AES
$aes = new SplClassLoader('Encryption\Crypt', '../includes/libraries');
$aes->register();

// User's language loading
$k['langage'] = @$_SESSION['user_language'];
require_once $_SESSION['settings']['cpassman_dir'].'/includes/language/'.$_SESSION['user_language'].'.php';
// Manage type of action asked
switch ($_POST['type']) {
    case "change_pw":
        // decrypt and retreive data in JSON format
        $data_received = json_decode(Encryption\Crypt\AesCtr::decrypt($_POST['data'], $_SESSION['key'], 256), true);
        // Prepare variables
        $new_pw = encrypt(htmlspecialchars_decode($data_received['new_pw']));
        // User has decided to change is PW
        if (isset($_POST['change_pw_origine']) && $_POST['change_pw_origine'] == "user_change") {
            // Get a string with the old pw array
            $last_pw = explode(';', $_SESSION['last_pw']);
            // if size is bigger then clean the array
            if (sizeof($last_pw) > $_SESSION['settings']['number_of_used_pw'] && $_SESSION['settings']['number_of_used_pw'] > 0) {
                for ($x = 0; $x < $_SESSION['settings']['number_of_used_pw']; $x++) {
                    unset($last_pw[$x]);
                }
                // reinit SESSION
                $_SESSION['last_pw'] = implode(';', $last_pw);
            }
            // specific case where admin setting "number_of_used_pw" is 0
            elseif ($_SESSION['settings']['number_of_used_pw'] == 0) {
                $_SESSION['last_pw'] = "";
                $last_pw = array();
            }
            // check if new pw is different that old ones
            if (in_array($new_pw, $last_pw)) {
                echo '[ { "error" : "already_used" } ]';
                break;
            } else {
                // update old pw with new pw
                if (sizeof($last_pw) == ($_SESSION['settings']['number_of_used_pw'] + 1)) {
                    unset($last_pw[0]);
                } else {
                    array_push($last_pw, $new_pw);
                }
                // create a list of last pw based on the table
                $old_pw = "";
                foreach ($last_pw as $elem) {
                    if (!empty($elem)) {
                        if (empty($old_pw)) {
                            $old_pw = $elem;
                        } else {
                            $old_pw .= ";".$elem;
                        }
                    }
                }
                // update sessions
                $_SESSION['last_pw'] = $old_pw;
                $_SESSION['last_pw_change'] = mktime(0, 0, 0, date('m'), date('d'), date('y'));
                $_SESSION['validite_pw'] = true;
                // update DB
                $db->queryUpdate(
                    "users",
                    array(
                        'pw' => $new_pw,
                        'last_pw_change' => mktime(0, 0, 0, date('m'), date('d'), date('y')),
                        'last_pw' => $old_pw
                       ),
                    "id = ".$_SESSION['user_id']
                );
                // update LOG
                $db->queryInsert(
                    'log_system',
                    array(
                        'type' => 'user_mngt',
                        'date' => mktime(date('H'), date('i'), date('s'), date('m'), date('d'), date('y')),
                        'label' => 'at_user_pwd_changed',
                        'qui' => $_SESSION['user_id'],
                        'field_1' => $_SESSION['user_id']
                       )
                );

                echo '[ { "error" : "none" } ]';
                break;
            }
        }
        // ADMIN has decided to change the USER's PW
        elseif (isset($_POST['change_pw_origine']) && $_POST['change_pw_origine'] == "admin_change") {
            // Check KEY
            if ($data_received['key'] != $_SESSION['key']) {
                echo '[ { "error" : "key_not_conform" } ]';
                break;
            }
            // update DB
            $db->queryUpdate(
                "users",
                array(
                    'pw' => $new_pw,
                    'last_pw_change' => mktime(0, 0, 0, date('m'), date('d'), date('y'))
                   ),
                "id = ".$data_received['user_id']
            );
            // update LOG
            $db->queryInsert(
                'log_system',
                array(
                    'type' => 'user_mngt',
                    'date' => mktime(date('H'), date('i'), date('s'), date('m'), date('d'), date('y')),
                    'label' => 'at_user_pwd_changed',
                    'qui' => $_SESSION['user_id'],
                    'field_1' => $_SESSION['user_id']
                   )
            );

            echo '[ { "error" : "none" } ]';
            break;
        }
        // ADMIN first login
        elseif (isset($_POST['change_pw_origine']) && $_POST['change_pw_origine'] == "first_change") {
            // update DB
            $db->queryUpdate(
                "users",
                array(
                    'pw' => $new_pw,
                    'last_pw_change' => mktime(0, 0, 0, date('m'), date('d'), date('y'))
                   ),
                "id = ".$_SESSION['user_id']
            );
            $_SESSION['last_pw_change'] = mktime(0, 0, 0, date('m'), date('d'), date('y'));
            // update LOG
            $db->queryInsert(
                'log_system',
                array(
                    'type' => 'user_mngt',
                    'date' => mktime(date('H'), date('i'), date('s'), date('m'), date('d'), date('y')),
                    'label' => 'at_user_initial_pwd_changed',
                    'qui' => $_SESSION['user_id'],
                    'field_1' => $_SESSION['user_id']
                   )
            );

            echo '[ { "error" : "none" } ]';
            break;
        } else {
            // DEFAULT case
            echo '[ { "error" : "nothing_to_do" } ]';
        }
        break;
    /**
     * Identify the USer
     */
    case "identify_user":
        require_once $_SESSION['settings']['cpassman_dir'].'/sources/main.functions.php';
        // decrypt and retreive data in JSON format
        $data_received = json_decode((Encryption\Crypt\AesCtr::decrypt($_POST['data'], SALT, 256)), true);
        // Prepare variables
        $password_clear = htmlspecialchars_decode($data_received['pw']);
        $password = encrypt(htmlspecialchars_decode($data_received['pw']));
        $username = htmlspecialchars_decode($data_received['login']);

        //CHeck 2-Factors pw
        if (isset($_SESSION['settings']['2factors_autentication']) && $_SESSION['settings']['2factors_autentication'] == 1) {
            if ($data_received['onetimepw'] != $data_received['original_onetimepw']) {
                echo '[{"value" : "false_onetimepw", "user_admin":"", "initial_url" : ""}]';
                $_SESSION['initial_url'] = "";
                break;
            }
        }

        // GET SALT KEY LENGTH
        if (strlen(SALT) > 32) {
            $_SESSION['error']['salt'] = true;
        }

        $_SESSION['user_language'] = $k['langage'];
        $ldap_connection = false;

        /* LDAP connection */
        if ($debug_ldap == 1) {
            $dbg_ldap = fopen($_SESSION['settings']['path_to_files_folder']."/ldap.debug.txt", "w"); //create temp file
        }

        if (isset($_SESSION['settings']['ldap_mode']) && $_SESSION['settings']['ldap_mode'] == 1 && $username != "admin") {
            if ($debug_ldap == 1) {
                fputs(
                    $dbg_ldap,
                    "Get all ldap params : \n" .
                    'base_dn : '.$_SESSION['settings']['ldap_domain_dn']."\n" .
                    'account_suffix : '.$_SESSION['settings']['ldap_suffix']."\n" .
                    'domain_controllers : '.$_SESSION['settings']['ldap_domain_controler']."\n" .
                    'use_ssl : '.$_SESSION['settings']['ldap_ssl']."\n" .
                    'use_tls : '.$_SESSION['settings']['ldap_tls']."\n*********\n\n"
                );
            }
            
            $adldap = new SplClassLoader('LDAP\AdLDAP', '../includes/libraries');
            $adldap = new LDAP\AdLDAP\AdLDAP(
                array(
                    'base_dn' => $_SESSION['settings']['ldap_domain_dn'],
                    'account_suffix' => $_SESSION['settings']['ldap_suffix'],
                    'domain_controllers' => array($_SESSION['settings']['ldap_domain_controler']),
                    'use_ssl' => $_SESSION['settings']['ldap_ssl'],
                    'use_tls' => $_SESSION['settings']['ldap_tls']
                )
            );
            if ($debug_ldap == 1) {
                fputs($dbg_ldap, "Create new adldap object : ".$adldap->get_last_error()."\n\n\n"); //Debug
            }
            // authenticate the user
            if ($adldap->authenticate($username, $password_clear)) {
                $ldap_connection = true;
            } else {
                $ldap_connection = false;
            }
            if ($debug_ldap == 1) {
                fputs(
                    $dbg_ldap,
                    "After authenticate : ".$adldap->get_last_error()."\n\n\n" .
                    "ldap status : ".$ldap_connection."\n\n\n"
                ); //Debug
            }
        }
        // Check if user exists in cpassman
        $sql = "SELECT * FROM ".$pre."users WHERE login = '".($username)."'";
        $row = $db->query($sql);
        if ($row == 0) {
            $row = $db->fetchRow("SELECT label FROM ".$pre."log_system WHERE ");
            echo '[{"value" : "error", "text":"'.$row[0].'"}]';
            exit;
        }

        $proceed_identification = false;
        if (mysql_num_rows($row) > 0) {
            $proceed_identification = true;
        } elseif (mysql_num_rows($row) == 0 && $ldap_connection == true) {
            // If LDAP enabled, create user in CPM if doesn't exist
            $new_user_id = $db->queryInsert(
                "users",
                array(
                    'login' => $username,
                    'pw' => $password,
                    'email' => "",
                    'admin' => '0',
                    'gestionnaire' => '0',
                    'personal_folder' => $_SESSION['settings']['enable_pf_feature'] == "1" ? '1' : '0',
                    'fonction_id' => '0',
                    'groupes_interdits' => '0',
                    'groupes_visibles' => '0',
                    'last_pw_change' => mktime(date('h'), date('m'), date('s'), date('m'), date('d'), date('y')),
                   )
            );
            // Create personnal folder
            if ($_SESSION['settings']['enable_pf_feature'] == "1") {
                $db->queryInsert(
                    "nested_tree",
                    array(
                        'parent_id' => '0',
                        'title' => $new_user_id,
                        'bloquer_creation' => '0',
                        'bloquer_modification' => '0',
                        'personal_folder' => '1'
                       )
                );
            }
            // Get info for user
            $sql = "SELECT * FROM ".$pre."users WHERE login = '".addslashes($username)."'";
            $row = $db->query($sql);
            $proceed_identification = true;
        }

        if ($proceed_identification === true) {
            // User exists in the DB
            $data = $db->fetchArray($row);
            // Can connect if
            // 1- no LDAP mode + user enabled + pw ok
            // 2- LDAP mode + user enabled + ldap connection ok + user is not admin
            // 3-  LDAP mode + user enabled + pw ok + usre is admin
            // This in order to allow admin by default to connect even if LDAP is activated
            if (
                (isset($_SESSION['settings']['ldap_mode']) && $_SESSION['settings']['ldap_mode'] == 0 && $password == $data['pw'] && $data['disabled'] == 0)
                ||
                (isset($_SESSION['settings']['ldap_mode']) && $_SESSION['settings']['ldap_mode'] == 1 && $ldap_connection == true && $data['disabled'] == 0 && $username != "admin")
                ||
                (isset($_SESSION['settings']['ldap_mode']) && $_SESSION['settings']['ldap_mode'] == 1 && $username == "admin" && $password == $data['pw'] && $data['disabled'] == 0)
            ) {
                $_SESSION['autoriser'] = true;

                //Load PWGEN
                $pwgen = new SplClassLoader('Encryption\PwGen', '../includes/libraries');
                $pwgen->register();
                $pwgen = new Encryption\PwGen\PwGen();

                // Generate a ramdom ID
                $key = "";
                $pwgen->setLength(50);
                $pwgen->setSecure(true);
                $pwgen->setSymbols(false);
                $pwgen->setCapitalize(true);
                $pwgen->setNumerals(true);
                $key = $pwgen->generate();
                // Log into DB the user's connection
                if (isset($_SESSION['settings']['log_connections']) && $_SESSION['settings']['log_connections'] == 1) {
                    logEvents('user_connection', 'connection', $data['id']);
                }
                // Save account in SESSION
                $_SESSION['login'] = stripslashes($username);
                $_SESSION['user_id'] = $data['id'];
                $_SESSION['user_admin'] = $data['admin'];
                $_SESSION['user_gestionnaire'] = $data['gestionnaire'];
                $_SESSION['user_read_only'] = $data['read_only'];
                $_SESSION['last_pw_change'] = $data['last_pw_change'];
                $_SESSION['last_pw'] = $data['last_pw'];
                $_SESSION['can_create_root_folder'] = $data['can_create_root_folder'];
                $_SESSION['key'] = $key;
                $_SESSION['personal_folder'] = $data['personal_folder'];
                $_SESSION['fin_session'] = time() + $data_received['duree_session'] * 60;
                $_SESSION['user_language'] = $data['user_language'];

                syslog(LOG_WARNING, "User logged in - ".$_SESSION['user_id']." - ".date("Y/m/d H:i:s")." {$_SERVER['REMOTE_ADDR']} ({$_SERVER['HTTP_USER_AGENT']})");
                // user type
                if ($_SESSION['user_admin'] == 1) {
                    $_SESSION['user_privilege'] = $txt['god'];
                } elseif ($_SESSION['user_gestionnaire'] == 1) {
                    $_SESSION['user_privilege'] = $txt['gestionnaire'];
                } elseif ($_SESSION['user_read_only'] == 1) {
                    $_SESSION['user_privilege'] = $txt['read_only_account'];
                } else {
                    $_SESSION['user_privilege'] = $txt['user'];
                }

                if (empty($data['last_connexion'])) {
                    $_SESSION['derniere_connexion'] = mktime(date('h'), date('m'), date('s'), date('m'), date('d'), date('y'));
                } else {
                    $_SESSION['derniere_connexion'] = $data['last_connexion'];
                }

                if (!empty($data['latest_items'])) {
                    $_SESSION['latest_items'] = explode(';', $data['latest_items']);
                } else {
                    $_SESSION['latest_items'] = array();
                }
                if (!empty($data['favourites'])) {
                    $_SESSION['favourites'] = explode(';', $data['favourites']);
                } else {
                    $_SESSION['favourites'] = array();
                }

                if (!empty($data['groupes_visibles'])) {
                    $_SESSION['groupes_visibles'] = @implode(';', $data['groupes_visibles']);
                } else {
                    $_SESSION['groupes_visibles'] = array();
                }
                if (!empty($data['groupes_interdits'])) {
                    $_SESSION['groupes_interdits'] = @implode(';', $data['groupes_interdits']);
                } else {
                    $_SESSION['groupes_interdits'] = array();
                }
                // User's roles
                $_SESSION['fonction_id'] = $data['fonction_id'];
                $_SESSION['user_roles'] = explode(";", $data['fonction_id']);
                // build array of roles
                $_SESSION['user_pw_complexity'] = 0;
                $_SESSION['arr_roles'] = array();
                foreach (array_filter(explode(';', $_SESSION['fonction_id'])) as $role) {
                    $res_roles = $db->queryFirst("SELECT title, complexity FROM ".$pre."roles_title WHERE id = ".$role);
                    $_SESSION['arr_roles'][$role] = array(
                        'id' => $role,
                        'title' => $res_roles['title']
                       );
                    // get highest complexity
                    if ($_SESSION['user_pw_complexity'] < $res_roles['complexity']) {
                        $_SESSION['user_pw_complexity'] = $res_roles['complexity'];
                    }
                }
                // build complete array of roles
                $_SESSION['arr_roles_full'] = array();
                $rows = $db->fetchAllArray(
                    "SELECT id, title
                    FROM ".$pre."roles_title A
                    ORDER BY title ASC"
                );
                foreach ($rows as $reccord) {
                    $_SESSION['arr_roles_full'][$reccord['id']] = array(
                        'id' => $reccord['id'],
                        'title' => $reccord['title']
                       );
                }
                // Set some settings
                $_SESSION['user']['find_cookie'] = false;
                $_SESSION['settings']['update_needed'] = "";
                // Update table
                $db->queryUpdate(
                    "users",
                    array(
                        'key_tempo' => $_SESSION['key'],
                        'last_connexion' => mktime(date("h"), date("i"), date("s"), date("m"), date("d"), date("Y")),
                        'timestamp' => mktime(date("h"), date("i"), date("s"), date("m"), date("d"), date("Y")),
                        'disabled' => 0,
                        'no_bad_attempts' => 0
                       ),
                    "id=".$data['id']
                );
                // Get user's rights
                identifyUserRights($data['groupes_visibles'], $_SESSION['groupes_interdits'], $data['admin'], $data['fonction_id'], false);
                // Get some more elements
                $_SESSION['screenHeight'] = $data_received['screenHeight'];
                // Get last seen items
                $_SESSION['latest_items_tab'][] = "";
                foreach ($_SESSION['latest_items'] as $item) {
                    if (!empty($item)) {
                        $data = $db->queryFirst("SELECT id,label,id_tree FROM ".$pre."items WHERE id = ".$item);
                        $_SESSION['latest_items_tab'][$item] = array(
                            'id' => $item,
                            'label' => $data['label'],
                            'url' => 'index.php?page=items&amp;group='.$data['id_tree'].'&amp;id='.$item
                           );
                    }
                }
                // send back the random key
                $return = $data_received['randomstring'];
                // Send email
                if (isset($_SESSION['settings']['enable_send_email_on_user_login']) && $_SESSION['settings']['enable_send_email_on_user_login'] == 1 && $_SESSION['user_admin'] != 1) {
                    // get all Admin users
                    $receivers = "";
                    $rows = $db->fetchAllArray("SELECT email FROM ".$pre."users WHERE admin = 1");
                    foreach ($rows as $reccord) {
                        if (empty($receivers)) {
                            $receivers = $reccord['email'];
                        } else {
                            $receivers = ",".$reccord['email'];
                        }
                    }
                    // Add email to table
                    $db->queryInsert(
                        'emails',
                        array(
                            'timestamp' => mktime(date('h'), date('m'), date('s'), date('m'), date('d'), date('y')),
                            'subject' => $txt['email_subject_on_user_login'],
                            'body' => str_replace(array('#tp_user#', '#tp_date#', '#tp_time#'), array(" ".$_SESSION['login'], date($_SESSION['settings']['date_format'], $_SESSION['derniere_connexion']), date($_SESSION['settings']['time_format'], $_SESSION['derniere_connexion'])), $txt['email_body_on_user_login']),
                            'receivers' => $receivers,
                            'status' => "not sent"
                           )
                    );
                }
            } elseif ($data['disabled'] == 1) {
                // User and password is okay but account is locked
                $return = "user_is_locked";
            } else {
                // User exists in the DB but Password is false
                // check if user is locked
                $user_is_locked = 0;
                $nb_attempts = intval($data['no_bad_attempts'] + 1);
                if ($_SESSION['settings']['nb_bad_authentication'] > 0 && intval($_SESSION['settings']['nb_bad_authentication']) < $nb_attempts) {
                    $user_is_locked = 1;
                    // log it
                    if (isset($_SESSION['settings']['log_connections']) && $_SESSION['settings']['log_connections'] == 1) {
                        logEvents('user_locked', 'connection', $data['id']);
                    }
                }
                $db->queryUpdate(
                    "users",
                    array(
                        'key_tempo' => $_SESSION['key'],
                        'last_connexion' => mktime(date("h"), date("i"), date("s"), date("m"), date("d"), date("Y")),
                        'disabled' => $user_is_locked,
                        'no_bad_attempts' => $nb_attempts
                       ),
                    "id=".$data['id']
                );
                // What return shoulb we do
                if ($user_is_locked == 1) {
                    $return = "user_is_locked";
                } elseif ($_SESSION['settings']['nb_bad_authentication'] == 0) {
                    $return = "false";
                } else {
                    $return = $nb_attempts;
                }
            }
        } else {
            $return = "false";
        }
        echo '[{"value" : "'.$return.'", "user_admin":"', isset($_SESSION['user_admin']) ? $_SESSION['user_admin'] : "", '", "initial_url" : "'.@$_SESSION['initial_url'].'"}]';
        $_SESSION['initial_url'] = "";
        break;
    /**
     * Increase the session time of User
     */
    case "increase_session_time":
        $_SESSION['fin_session'] = $_SESSION['fin_session'] + 3600;
        echo '[{"new_value":"'.$_SESSION['fin_session'].'"}]';
        break;
    /**
     * Hide maintenance message
     */
    case "hide_maintenance":
        $_SESSION['hide_maintenance'] = 1;
        break;
    /**
     * Used in order to send the password to the user by email
     */
    case "send_pw_by_email":
        // found account and pw associated to email
        $data = $db->fetchRow("SELECT COUNT(*) FROM ".$pre."users WHERE email = '".mysql_real_escape_string(stripslashes(($_POST['email'])))."'");
        if ($data[0] != 0) {
            $data = $db->fetchArray("SELECT login,pw FROM ".$pre."users WHERE email = '".mysql_real_escape_string(stripslashes(($_POST['email'])))."'");

            //Load PWGEN
            $pwgen = new SplClassLoader('Encryption\PwGen', '../includes/libraries');
            $pwgen->register();
            $pwgen = new Encryption\PwGen\PwGen();

            // Generate a ramdom ID
            $key = "";
            $pwgen->setLength(50);
            $pwgen->setSecure(true);
            $pwgen->setSymbols(false);
            $pwgen->setCapitalize(true);
            $pwgen->setNumerals(true);
            $key = $pwgen->generate();
            // load library
            $mail = new SplClassLoader('Email\PhpMailer', '../includes/libraries');
            $mail->register();
            $mail = new Email\PhpMailer\PHPMailer();
            // send to user
            $mail->setLanguage("en", "../includes/libraries/email/phpmailer/language/");
            $mail->isSmtp(); // send via SMTP
            $mail->Host = $_SESSION['settings']['email_smtp_server']; // SMTP servers
            $mail->SMTPAuth = $_SESSION['settings']['email_smtp_auth']; // turn on SMTP authentication
            $mail->Username = $_SESSION['settings']['email_auth_username']; // SMTP username
            $mail->Password = $_SESSION['settings']['email_auth_pwd']; // SMTP password
            $mail->From = $_SESSION['settings']['email_from'];
            $mail->FromName = $_SESSION['settings']['email_from_name'];
            $mail->addAddress($_POST['email']); //Destinataire
            $mail->WordWrap = 80; // set word wrap
            $mail->isHtml(true); // send as HTML
            $mail->SMTPDebug = 0;
            $mail->Subject = $txt['forgot_pw_email_subject'];
            $mail->AltBody = $txt['forgot_pw_email_altbody_1']." ".$txt['at_login']." : ".$data['login']." - ".$txt['index_password']." : ".md5($data['pw']);
            $mail->Body = $txt['forgot_pw_email_body_1']." <a href=\"".$_SESSION['settings']['cpassman_url']."/index.php?action=password_recovery&key=".$key."&login=".$_POST['login']."\">".$_SESSION['settings']['cpassman_url']."/index.php?action=password_recovery&key=".$key."&login=".$_POST['login']."</a>.<br><br>".$txt['thku'];
            // Check if email has already a key in DB
            $data = $db->fetchRow("SELECT COUNT(*) FROM ".$pre."misc WHERE intitule = '".$_POST['login']."' AND type = 'password_recovery'");
            if ($data[0] != 0) {
                $db->queryUpdate(
                    "misc",
                    array(
                        'valeur' => $key
                       ),
                    array(
                        'type' => 'password_recovery',
                        'intitule' => $_POST['login']
                       )
                );
            } else {
                // store in DB the password recovery informations
                $db->queryInsert(
                    'misc',
                    array(
                        'type' => 'password_recovery',
                        'intitule' => $_POST['login'],
                        'valeur' => $key
                       )
                );
            }
            // send email
            if (!$mail->send()) {
                echo '[{"error":"error_mail_not_send" , "message":"'.$mail->ErrorInfo.'"}]';
            } else {
                echo '[{"error":"no" , "message":"'.$txt['forgot_my_pw_email_sent'].'"}]';
            }
        } else {
            // no one has this email ... alert
            echo '[{"error":"error_email" , "message":"'.$txt['forgot_my_pw_error_email_not_exist'].'"}]';
        }
        break;
    // Send to user his new pw if key is conform
    case "generate_new_password":
        // check if key is okay
        $data = $db->fetchRow("SELECT valeur FROM ".$pre."misc WHERE intitule = '".$_POST['login']."' AND type = 'password_recovery'");
        if ($_POST['key'] == $data[0]) {
            //Load PWGEN
            $pwgen = new SplClassLoader('Encryption\PwGen', '../includes/libraries');
            $pwgen->register();
            $pwgen = new Encryption\PwGen\PwGen();

            // Generate and change pw
            $new_pw = "";
            $pwgen->setLength(10);
            $pwgen->setSecure(true);
            $pwgen->setSymbols(false);
            $pwgen->setCapitalize(true);
            $pwgen->setNumerals(true);
            $new_pw_not_crypted = $pwgen->generate();
            $new_pw = encrypt(stringUtf8Decode($new_pw_not_crypted));
            // update DB
            $db->queryUpdate(
                "users",
                array(
                    'pw' => $new_pw
                   ),
                "login = '".$_POST['login']."'"
            );
            // Delete recovery in DB
            $db->queryDelete(
                "misc",
                array(
                    'type' => 'password_recovery',
                    'intitule' => $_POST['login'],
                    'valeur' => $key
                   )
            );
            // Get email
            $data_user = $db->queryFirst("SELECT email FROM ".$pre."users WHERE login = '".$_POST['login']."'");

            $_SESSION['validite_pw'] = false;
            // send to user
            $ret = json_decode(
                @sendEmail(
                    $txt['forgot_pw_email_subject_confirm'],
                    $txt['forgot_pw_email_body']." ".$new_pw_not_crypted,
                    $data_user['email'],
                    strip_tags($txt['forgot_pw_email_body'])." ".$new_pw_not_crypted
                )
            );
            // send email
            if (empty($ret['error'])) {
                echo 'done';
            } else {
                echo $ret['message'];
            }
        }
        break;
    /**
     * Get the list of folders
     */
    case "get_folders_list":
        //Load Tree
        $tree = new SplClassLoader('Tree\NestedTree', '../includes/libraries');
        $tree->register();
        $tree = new Tree\NestedTree\NestedTree($pre.'nested_tree', 'id', 'parent_id', 'title');
        $folders = $tree->getDescendants();
        $arrOutput = array();

        /* Build list of all folders */
        $folders_list = "\'0\':\'".$txt['root']."\'";
        foreach ($folders as $f) {
            // Be sure that user can only see folders he/she is allowed to
            if (!in_array($f->id, $_SESSION['forbiden_pfs'])) {
                $display_this_node = false;
                // Check if any allowed folder is part of the descendants of this node
                $node_descendants = $tree->getDescendants($f->id, true, false, true);
                foreach ($node_descendants as $node) {
                    if (in_array($node, $_SESSION['groupes_visibles'])) {
                        $display_this_node = true;
                        break;
                    }
                }

                if ($display_this_node == true) {
                    if ($f->title == $_SESSION['user_id'] && $f->nlevel == 1) {
                        $f->title = $_SESSION['login'];
                    }
                    $arrOutput[$f->id] = $f->title;
                }
            }
        }
        echo json_encode($arrOutput, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP);
        break;
    /**
     * Store the personal saltkey
     */
    case "store_personal_saltkey":
        if ($_POST['sk'] != "**************************") {
            $_SESSION['my_sk'] = str_replace(" ", "+", urldecode($_POST['sk']));
            setcookie("TeamPass_PFSK_".md5($_SESSION['user_id']), $_SESSION['my_sk'], time() + 60 * 60 * 24 * $_SESSION['settings']['personal_saltkey_cookie_duration'], '/');
        }
        break;
    /**
     * Change the personal saltkey
     */
    case "change_personal_saltkey":
        $old_personal_saltkey = $_SESSION['my_sk'];
        $new_personal_saltkey = str_replace(" ", "+", urldecode($_POST['sk']));
        // Change encryption
        $rows = mysql_query(
            "SELECT i.id as id, i.pw as pw
            FROM ".$pre."items as i
            INNER JOIN ".$pre."log_items as l ON (i.id=l.id_item)
            WHERE i.perso = 1
            AND l.id_user=".$_SESSION['user_id']."
            AND l.action = 'at_creation'"
        );
        while ($reccord = mysql_fetchArray($rows)) {
            if (!empty($reccord['pw'])) {
                // get pw
                $pw = trim(mcrypt_decrypt(MCRYPT_RIJNDAEL_256, $old_personal_saltkey, base64_decode($reccord['pw']), MCRYPT_MODE_ECB, mcrypt_create_iv(mcrypt_get_iv_size(MCRYPT_RIJNDAEL_256, MCRYPT_MODE_ECB), MCRYPT_RAND)));
                // encrypt
                $encrypted_pw = trim(base64_encode(mcrypt_encrypt(MCRYPT_RIJNDAEL_256, $new_personal_saltkey, $pw, MCRYPT_MODE_ECB, mcrypt_create_iv(mcrypt_get_iv_size(MCRYPT_RIJNDAEL_256, MCRYPT_MODE_ECB), MCRYPT_RAND))));
                // update pw in ITEMS table
                mysql_query("UPDATE ".$pre."items SET pw = '".$encrypted_pw."' WHERE id='".$reccord['id']."'") or die(mysql_error());
            }
        }
        // change salt
        $_SESSION['my_sk'] = $new_personal_saltkey;
        break;
    /**
     * Reset the personal saltkey
     */
    case "reset_personal_saltkey":
        if (!empty($_SESSION['user_id']) && !empty($_POST['sk'])) {
            // delete all previous items of this user
            $rows = mysql_query(
                "SELECT i.id as id
                FROM ".$pre."items as i
                INNER JOIN ".$pre."log_items as l ON (i.id=l.id_item)
                WHERE i.perso = 1
                AND l.id_user=".$_SESSION['user_id']."
                AND l.action = 'at_creation'"
            );
            while ($reccord = mysql_fetchArray($rows)) {
                // delete in ITEMS table
                mysql_query("DELETE FROM ".$pre."items  WHERE id='".$reccord['id']."'") or die(mysql_error());
                // delete in LOGS table
                mysql_query("DELETE FROM ".$pre."log_items WHERE id_item='".$reccord['id']."'") or die(mysql_error());
            }
            // change salt
            $_SESSION['my_sk'] = str_replace(" ", "+", urldecode($_POST['sk']));
        }
        break;
    /**
     * Change the user's language
     */
    case "change_user_language":
        if (!empty($_SESSION['user_id'])) {
            // update DB
            $db->queryUpdate(
                "users",
                array(
                    'user_language' => $_POST['lang']
                   ),
                "id = ".$_SESSION['user_id']
            );
            $_SESSION['user_language'] = $_POST['lang'];
        }
        break;
    /**
     * Send emails not sent
     */
    case "send_wainting_emails":
        if (isset($_SESSION['settings']['enable_send_email_on_user_login']) && $_SESSION['settings']['enable_send_email_on_user_login'] == 1 && isset($_SESSION['key'])) {
            $row = $db->queryFirst("SELECT valeur FROM ".$pre."misc WHERE type='cron' AND intitule='sending_emails'");
            if ((mktime(date('h'), date('m'), date('s'), date('m'), date('d'), date('y')) - $row['valeur']) >= 300 || $row['valeur'] == 0) {
                //load library
                $mail = new SplClassLoader('Email\PhpMailer', $_SESSION['settings']['cpassman_dir'].'/includes/libraries');
                $mail->register();
                $mail = new Email\PhpMailer\PHPMailer();
    
                $mail->setLanguage("en", "../includes/libraries/email/phpmailer/language");
                $mail->isSmtp(); // send via SMTP
                $mail->Host = $_SESSION['settings']['email_smtp_server']; // SMTP servers
                $mail->SMTPAuth = $_SESSION['settings']['email_smtp_auth']; // turn on SMTP authentication
                $mail->Username = $_SESSION['settings']['email_auth_username']; // SMTP username
                $mail->Password = $_SESSION['settings']['email_auth_pwd']; // SMTP password
                $mail->From = $_SESSION['settings']['email_from'];
                $mail->FromName = $_SESSION['settings']['email_from_name'];
                $mail->WordWrap = 80; // set word wrap
                $mail->isHtml(true); // send as HTML
                $status = "";
                $rows = $db->fetchAllArray("SELECT * FROM ".$pre."emails WHERE status='not sent'");
                foreach ($rows as $reccord) {
                    // send email
                    $ret = json_decode(
                        @sendEmail(
                            $reccord['subject'],
                            $reccord['body'],
                            $reccord['receivers']
                        )
                    );

                    if (!empty($ret['error'])) {
                        $status = "not sent";
                    } else {
                        $status = "sent";
                    }
                    // update item_id in files table
                    $db->queryUpdate(
                        'emails',
                        array(
                            'status' => $status
                           ),
                        "timestamp='".$reccord['timestamp']."'"
                    );
                    if ($status == "not sent") {
                        break;
                    }
                }
            }
            // update cron time
            $db->queryUpdate(
                "misc",
                array(
                    'valeur' => mktime(date('h'), date('m'), date('s'), date('m'), date('d'), date('y'))
                   ),
                array(
                    'intitule' => 'sending_emails',
                    'type' => 'cron'
                   )
            );
        }
        break;
}
