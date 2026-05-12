<?php

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../../config.php');
require_once("$CFG->libdir/clilib.php");
require_once("$CFG->libdir/adminlib.php");

// Now get cli options.
list($options, $unrecognized) = cli_get_params(
    array(
        'id' => '',
        'baseurl' => '',
        'clientid' => '',
        'clientsecret' => '',
        'loginscopes' => '',
        'loginscopesoffline' => '',
        'loginparams' => '',
        'loginparamsoffline' => '',
        'name' => '',
        'showonloginpage' => true,
        'image' => '',
        'list' => false,
        'delete' => false,
        'delete-all' => false,
        'help' => false,
        'json' => false,
        'requireconfirmation' => false
    ),
    array('l' => 'list', 'd' => 'delete', 'h' => 'help')
);

if ($unrecognized) {
    $unrecognized = implode("\n  ", $unrecognized);
    cli_error(get_string('cliunknowoption', 'admin', $unrecognized));
}

if ($options['help']) {
    $help =
        "Manage oauth2 settings.
Current status displayed if not option specified.

Options:
--id                  ID to update
--baseurl             Service Base URL
--clientid            Client ID
--clientsecret        Client Secret
--loginscopes         Scopes included in a login request
--loginscopesoffline  Scopes included in a login request for offline access
--loginparams         Additional parameters included in a login request
--loginparamsoffline  Additional parameters included in a login request for offline access
--name                Name
--image               Logo URL
--showonloginpage     Show on login page
--requireconfirmation Require email confirmation
--list                List provider passed in --id or all
--delete              Delete provider passed in --id
--delete-all          Delete all providers
--json                Encode output list of values using JSON notation.
-h, --help            Print out this help

Examples:
    # php manage.php --list
        Lists all previously configured providers.

    # php manage.php --id=2 --list
        Lists the previously configured provider.

    # php manage.php --id=2 --delete
        Deletes the previously configured provider.

    # php manage.php --baseurl=http://id.example.org --clientid=<client> --clientsecret=<secret> --loginscopes=\"openid profile email sketch-common alloy-api player-api caster-api steamfitter-api vm-api\" --name=<name>
        Creates a new provider.

    # php manage.php --id=1 --baseurl=http://id.example.org --clientid=<client> --clientsecret=<secret> --loginscopes=\"openid profile email sketch-common alloy-api player-api caster-api steamfitter-api vm-api\" --name=<name>
        Updates a previously configured provider.


";

    echo $help;
    die;
}

// create an array of all keys for data
$issuer_settings = array(
    'id',
    'baseurl',
    'clientid',
    'clientsecret',
    'loginscopes',
    'loginscopesoffline',
    'name',
    'image',
    'showonloginpage',
    'requireconfirmation',
    'loginparams',
    'loginparamsoffline'
);

// create an array of required keys for data
$required_settings = array(
    'baseurl',
    'clientid',
    'clientsecret',
    'loginscopes',
    'name',
    'image'
);

$results = array(
    'header' => get_string('manageoauth2', 'tool_foundrysync') . " ($CFG->wwwroot)",
    'success' => true,
    'data' => [],
);


$api = new \tool_foundrysync\oauth2\api();

$issuers = $api->get_all_issuers();

if ($options['list']) {
    $results['data'] = [];
    if ($options['id']) {
        $index = '';
        $i = 0;
        foreach ($issuers as $issuer) {
            if ($issuer->get('id') === $options['id']) {
                $index = $i;
                break;
            }
            $i++;
        }
        if ($index === '') {
            $results['data'] = 'Provider not found.';
            $results['success'] = false;
        }

        $issuer = $api->get_issuer($options['id']);
        $issuer_values = [];
        foreach ($issuer_settings as $key) {
            $issuer_values[$key] = $issuer->get($key);
        }
        $results['data'] = $issuer_values;
    } else {
        $issuers_values = [];
        foreach ($issuers as $issuer) {
            $issuer_values = [];
            foreach ($issuer_settings as $key) {
                $issuer_values[$key] = $issuer->get($key);
            }
            $issuers_values[] = $issuer_values;
        }
        $results['data'] = $issuers_values;
    }
    print_results_and_exit($options, $results, $issuer_settings);
}

if ($options['delete-all']) {
    $results['data'] = [];
    foreach ($issuers as $issuer) {
        $deletion_id = $issuer->get('id');
        $api->delete_issuer($deletion_id);
        $results['data'][] = 'Deleted provider with given id ' . $deletion_id;
    }
    print_results_and_exit($options, $results, $issuer_settings);
}

if ($options['delete']) {
    if ($options['id']) {
        $index = '';
        $i = 0;
        foreach ($issuers as $issuer) {
            if ($issuer->get('id') === $options['id']) {
                $index = $i;
                break;
            }
            $i++;
        }
        if ($index === '') {
            $results['data'] = 'Provider not found.';
            $results['success'] = false;
        }

        if ($issuer = $api->get_issuer($options['id'])) {
            $api->delete_issuer($options['id']);
            $results['data'] = 'Deleted provider with given id ' . $options['id'];
        }
    } else {
        $results['data'] = 'no provider given with --id';
        $results['success'] = false;
    }
    print_results_and_exit($options, $results, $issuer_settings);
}

// set data
$data = new stdClass();

// for existing provider, get values from it first
if ($options['id']) {
    if (count($issuers) === 0) {
        $results['data'] = 'provider given with --id but no providers found.';
    } else if ($options['id']) {

        $index = '';
        $i = 0;
        foreach ($issuers as $issuer) {
            if ($issuer->get('id') === $options['id']) {
                $index = $i;
                break;
            }
            $i++;
        }
        if ($index === '') {
            $results['data'] = 'Provider not found.';
        } else {
            $issuer = $api->get_issuer($options['id']);
        }
    }
    if (!$issuer) {
        $results['success'] = false;
        print_results_and_exit($options, $results, $issuer_settings);
    }
    foreach ($issuer_settings as $key) {
        $data->$key = $issuer->get($key);
    }
}
// set values that are set on command line
// update with values set in options
$updated = false;
foreach ($issuer_settings as $key) {
    if ($options[$key]) {
        // TODO handle when we want to clear a value
        $value = $options[$key];
        if ($key === 'showonloginpage' || $key === 'requireconfirmation') {
            $value = filter_var($value, FILTER_VALIDATE_BOOLEAN);
        }
        if (!isset($data->$key) || $value != $data->$key) {
                $updated = true;
        }
        $data->$key = $value;
    }
}
// guess at image because db requires a value to be set
if (!$data->image) {
    $api->guess_image($data);
}

// validate that required options exist
foreach ($required_settings as $key) {
    if (!$data->$key) {
        $results['success'] = false;
        $results['data'] = 'required value is empty: ' . $key;
        print_results_and_exit($options, $results, $issuer_settings);
    }
}

// write to db
if ($options['id'] === '' && $options['baseurl']) {
    $newissuer = $api->create_issuer($data);
    $results['data'] = $newissuer;
    print_results_and_exit($options, $results, $issuer_settings, true);
} else if ($options['baseurl'] || $updated) {
    $api->update_issuer($data);
    $issuer = $api->get_issuer($options['id']);
    $results['data'] = $issuer;
    print_results_and_exit($options, $results, $issuer_settings, true);
} else {
    $results['success'] = false;
    $results['data'] = 'No actions taken.';
}
print_results_and_exit($options, $results, $issuer_settings);

function print_results_and_exit($options, $results, $issuer_settings, $has_private_properties=false) {
    if ($options['json']) {
        if ($has_private_properties) {
            $issuer_values = [];
            foreach ($issuer_settings as $key) {
                $issuer_values[$key] = $results['data']->get($key);
            }
            $results['data'] = $issuer_values;
        }
        $results = json_encode($results);
    } else {
        $results = var_dump($results);
    }
    cli_writeln($results);
    exit;
}