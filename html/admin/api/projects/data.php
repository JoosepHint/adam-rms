<?php
if (basename(__FILE__) == basename($_SERVER["SCRIPT_FILENAME"])) require_once __DIR__ . '/../apiHeadSecure.php'; //Only if it wasn't included from somewhere else
use Money\Currency;
use Money\Money;

$array = [];
if (isset($_POST['formData'])) {
    foreach ($_POST['formData'] as $item) {
        $array[$item['name']] = $item['value'];
        $_GET[$item['name']] = $item['value'];
    }
}
if (!$AUTH->instancePermissionCheck(20) or !isset($_GET['id'])) finish(false);

//The project itself
$DBLIB->where("projects.instances_id", $AUTH->data['instance']['instances_id']);
$DBLIB->where("projects.projects_deleted", 0);
$DBLIB->where("projects.projects_id", $_GET['id']);
$DBLIB->join("projectsTypes", "projects.projectsTypes_id=projectsTypes.projectsTypes_id", "LEFT");
$DBLIB->join("clients", "projects.clients_id=clients.clients_id", "LEFT");
$DBLIB->join("users", "projects.projects_manager=users.users_userid", "LEFT");
$DBLIB->join("locations","locations.locations_id=projects.locations_id","LEFT");
$PAGEDATA['project'] = $DBLIB->getone("projects", ["projects.*", "projectsTypes.*", "clients.clients_id", "clients_name","clients_website","clients_email","clients_notes","clients_address","clients_phone","users.users_userid", "users.users_name1", "users.users_name2", "users.users_email","locations.locations_name","locations.locations_address"]);
if (!$PAGEDATA['project']) die("404");


//Finances

//Payments and also
function projectFinancials($project) {
    global $DBLIB,$AUTH;
    $projectFinanceHelper = new projectFinance();
    $return = [];

    $DBLIB->where("payments.payments_deleted", 0);
    $DBLIB->orderBy("payments.payments_date", "ASC");
    $DBLIB->where("payments.projects_id", $project['projects_id']);
    $payments = $DBLIB->get("payments");
    $return['payments'] = ["received" => ["ledger" => [], "total" => new Money(null, new Currency($AUTH->data['instance']['instances_config_currency']))], "sales" => ["ledger" => [], "total" => new Money(null, new Currency($AUTH->data['instance']['instances_config_currency']))], "subHire" => ["ledger" => [], "total" => new Money(null, new Currency($AUTH->data['instance']['instances_config_currency']))], "staff" => ["ledger" => [], "total" => new Money(null, new Currency($AUTH->data['instance']['instances_config_currency']))]];
    foreach ($payments as $payment) {
        $key = false;
        switch ($payment['payments_type']) {
            case 1:
                $key = "received";
                break;
            case 2:
                $key = 'sales';
                break;
            case 3:
                $key = 'subHire';
                break;
            case 4:
                $key = 'staff';
                break;
        }
        if ($key) {
            $payment['payments_amount'] = new Money($payment['payments_amount'], new Currency($AUTH->data['instance']['instances_config_currency']));
            $payment['payments_amountTotal'] = $payment['payments_amount']->multiply($payment['payments_quantity']);
            $return['payments'][$key]['total'] = $payment['payments_amountTotal']->add($return['payments'][$key]['total']);
            $return['payments'][$key]['ledger'][] = $payment;
        } else throw new Exception("Unknown payment type found");
    }

    //Assets
    $DBLIB->where("projects_id", $project['projects_id']);
    $DBLIB->where("assetsAssignments.assetsAssignments_deleted", 0);
    $DBLIB->join("assets", "assetsAssignments.assets_id=assets.assets_id", "LEFT");
    $DBLIB->join("assetTypes", "assets.assetTypes_id=assetTypes.assetTypes_id", "LEFT");
    $DBLIB->join("manufacturers", "manufacturers.manufacturers_id=assetTypes.manufacturers_id", "LEFT");
    $DBLIB->join("assetCategories", "assetTypes.assetCategories_id=assetCategories.assetCategories_id", "LEFT");
    $DBLIB->join("assetCategoriesGroups", "assetCategoriesGroups.assetCategoriesGroups_id=assetCategories.assetCategoriesGroups_id", "LEFT");
    $DBLIB->join("assetsAssignmentsStatus", "assetsAssignments.assetsAssignmentsStatus_id=assetsAssignmentsStatus.assetsAssignmentsStatus_id", "LEFT");
    $DBLIB->orderBy("assetCategories.assetCategories_rank", "ASC");
    $DBLIB->orderBy("assetTypes.assetTypes_id", "ASC");
    $DBLIB->orderBy("assets.assets_tag", "ASC");
    $DBLIB->where("assets.assets_deleted", 0);
    $assets = $DBLIB->get("assetsAssignments", null, ["assetCategories.assetCategories_rank", "assetsAssignmentsStatus.assetsAssignmentsStatus_name","assetsAssignments.*", "manufacturers.manufacturers_name", "assetTypes.*", "assets.*", "assetCategories.assetCategories_name", "assetCategories.assetCategories_fontAwesome", "assetCategoriesGroups.assetCategoriesGroups_name", "assets.instances_id"]);

    $return['assetsAssigned'] = [];
    $return['assetsAssignedSUB'] = [];
    $return['mass'] = 0.0; //TODO evaluate whether using floats for mass is a good idea....
    $return['value'] = new Money(null, new Currency($AUTH->data['instance']['instances_config_currency']));
    $return['prices'] = ["subTotal" => new Money(null, new Currency($AUTH->data['instance']['instances_config_currency'])), "discounts" => new Money(null, new Currency($AUTH->data['instance']['instances_config_currency'])), "total" => new Money(null, new Currency($AUTH->data['instance']['instances_config_currency']))];

    $return['priceMaths'] = $projectFinanceHelper->durationMaths($project['projects_dates_deliver_start'],$project['projects_dates_deliver_end']);
    foreach ($assets as $asset) {
        $return['mass'] += ($asset['assets_mass'] == null ? $asset['assetTypes_mass'] : $asset['assets_mass']);
        $asset['value'] = new Money(($asset['assets_value'] != null ? $asset['assets_value'] : $asset['assetTypes_value']), new Currency($AUTH->data['instance']['instances_config_currency']));
        $return['value'] = $return['value']->add($asset['value']);

        if ($asset['assetsAssignments_customPrice'] == null) {
            //The actual pricing calculator
            $asset['price'] = new Money(null, new Currency($AUTH->data['instance']['instances_config_currency']));
            $asset['price'] = $asset['price']->add((new Money(($asset['assets_dayRate'] !== null ? $asset['assets_dayRate'] : $asset['assetTypes_dayRate']), new Currency($AUTH->data['instance']['instances_config_currency'])))->multiply($return['priceMaths']['days']));
            $asset['price'] = $asset['price']->add((new Money(($asset['assets_weekRate'] !== null ? $asset['assets_weekRate'] : $asset['assetTypes_weekRate']), new Currency($AUTH->data['instance']['instances_config_currency'])))->multiply($return['priceMaths']['weeks']));
        } else $asset['price'] = new Money($asset['assetsAssignments_customPrice'],new Currency($AUTH->data['instance']['instances_config_currency']));

        $return['prices']['subTotal'] = $asset['price']->add($return['prices']['subTotal']);

        if ($asset['assetsAssignments_discount'] > 0) $asset['discountPrice'] = $asset['price']->multiply(1 - ($asset['assetsAssignments_discount'] / 100));
        else $asset['discountPrice'] = $asset['price'];

        $return['prices']['discounts'] = $return['prices']['discounts']->add($asset['price']->subtract($asset['discountPrice']));
        $return['prices']['total'] = $return['prices']['total']->add($asset['discountPrice']);

        $asset['flagsblocks'] = assetFlagsAndBlocks($asset['assets_id']);

        $asset['assetTypes_definableFields_ARRAY'] = array_filter(explode(",", $asset['assetTypes_definableFields']));

        $asset['latestScan'] = assetLatestScan($asset['assets_id']);

        if ($asset['instances_id'] != $project['instances_id']) {
            if (!isset($return['assetsAssignedSUB'][$asset['instances_id']]['assets'])) $return['assetsAssignedSUB'][$asset['instances_id']]['assets'] = [];
            if (!isset($return['assetsAssignedSUB'][$asset['instances_id']]['assets'][$asset['assetTypes_id']])) $return['assetsAssignedSUB'][$asset['instances_id']]['assets'][$asset['assetTypes_id']]['assets'] = [];
            $return['assetsAssignedSUB'][$asset['instances_id']]['assets'][$asset['assetTypes_id']]['assets'][] = $asset;
        } else {
            if (!isset($return['assetsAssigned'][$asset['assetTypes_id']])) $return['assetsAssigned'][$asset['assetTypes_id']]['assets'] = [];
            $return['assetsAssigned'][$asset['assetTypes_id']]['assets'][] = $asset;
        }
    }
    foreach ($return['assetsAssigned'] as $key => $type) {
        if (!isset($return['assetsAssigned'][$key]['totals'])) $return['assetsAssigned'][$key]['totals'] = ["status" => null,"discountPrice"=>new Money(null, new Currency($AUTH->data['instance']['instances_config_currency'])),"price"=>new Money(null, new Currency($AUTH->data['instance']['instances_config_currency'])),"mass"=>0.0];
        foreach ($type['assets'] as $asset) {
            if ($return['assetsAssigned'][$key]['totals']['status'] == null) $return['assetsAssigned'][$key]['totals']['status'] = $asset['assetsAssignmentsStatus_name'];
            elseif ($return['assetsAssigned'][$key]['totals']['status'] != $asset['assetsAssignmentsStatus_name']) $return['assetsAssigned'][$key]['totals']['status'] = false; //They aren't all the same
            $return['assetsAssigned'][$key]['totals']['discountPrice'] = $return['assetsAssigned'][$key]['totals']['discountPrice']->add($asset['discountPrice']);
            $return['assetsAssigned'][$key]['totals']['price'] = $return['assetsAssigned'][$key]['totals']['price']->add($asset['price']);
            $return['assetsAssigned'][$key]['totals']['mass'] += ($asset['assets_mass'] == null ? $asset['assetTypes_mass'] : $asset['assets_mass']);
        }
    }
    foreach ($return['assetsAssignedSUB'] as $instanceid => $instance) {
        if (!isset($return['assetsAssignedSUB'][$instanceid]['instance'])) {
            $DBLIB->where("instances_id",$instanceid);
            $return['assetsAssignedSUB'][$instanceid]['instance'] = $DBLIB->getone("instances",["instances_id","instances_name"]);
        }
        foreach ($return['assetsAssignedSUB'][$instanceid]['assets'] as $key => $type) {
            if (!isset($return['assetsAssignedSUB'][$instanceid]['assets'][$key]['totals'])) $return['assetsAssignedSUB'][$instanceid]['assets'][$key]['totals'] = ["status" => null,"discountPrice"=>new Money(null, new Currency($AUTH->data['instance']['instances_config_currency'])),"price"=>new Money(null, new Currency($AUTH->data['instance']['instances_config_currency'])),"mass"=>0.0];
            foreach ($type['assets'] as $asset) {
                if ($return['assetsAssignedSUB'][$instanceid]['assets'][$key]['totals']['status'] == null) $return['assetsAssignedSUB'][$instanceid]['assets'][$key]['totals']['status'] = $asset['assetsAssignmentsStatus_name'];
                elseif ($return['assetsAssignedSUB'][$instanceid]['assets'][$key]['totals']['status'] != $asset['assetsAssignmentsStatus_name']) $return['assetsAssignedSUB'][$instanceid]['assets'][$key]['totals']['status'] = false; //They aren't all the same
                $return['assetsAssignedSUB'][$instanceid]['assets'][$key]['totals']['discountPrice'] = $return['assetsAssignedSUB'][$instanceid]['assets'][$key]['totals']['discountPrice']->add($asset['discountPrice']);
                $return['assetsAssignedSUB'][$instanceid]['assets'][$key]['totals']['price'] = $return['assetsAssignedSUB'][$instanceid]['assets'][$key]['totals']['price']->add($asset['price']);
                $return['assetsAssignedSUB'][$instanceid]['assets'][$key]['totals']['mass'] += ($asset['assets_mass'] == null ? $asset['assetTypes_mass'] : $asset['assets_mass']);
            }
        }
    }

    $return['payments']['subTotal'] = $return['prices']['total']->add($return['payments']['sales']['total'],$return['payments']['subHire']['total'],$return['payments']['staff']['total']);
    $return['payments']['total'] = $return['payments']['subTotal']->subtract($return['payments']['received']['total']);
    return $return;
}
$PAGEDATA['FINANCIALS'] = projectFinancials($PAGEDATA['project']);
$DBLIB->where("projects_id",$PAGEDATA['project']['projects_id']);
$DBLIB->orderBy("projectsFinanceCache_timestamp", "DESC");
$projectFinanceCache = $DBLIB->getone("projectsFinanceCache");
$projectFinancesCacheMismatch = false;
if (!$projectFinanceCache) {
    //Insert a new project finance cache for this project as it doesn't seem to have one hmmm
    $projectFinanceCacheInsert = [
        "projects_id" => $PAGEDATA['project']['projects_id'],
        "projectsFinanceCache_timestamp" => date("Y-m-d H:i:s"),
        "projectsFinanceCache_equipmentSubTotal" =>$PAGEDATA['FINANCIALS']['prices']['subTotal']->getAmount(),
        "projectsFinanceCache_equiptmentDiscounts" =>$PAGEDATA['FINANCIALS']['prices']['discounts']->getAmount(),
        "projectsFinanceCache_equiptmentTotal" =>$PAGEDATA['FINANCIALS']['prices']['total']->getAmount(),
        "projectsFinanceCache_salesTotal" =>$PAGEDATA['FINANCIALS']['payments']['sales']['total']->getAmount(),
        "projectsFinanceCache_staffTotal" =>$PAGEDATA['FINANCIALS']['payments']['staff']['total']->getAmount(),
        "projectsFinanceCache_externalHiresTotal" => $PAGEDATA['FINANCIALS']['payments']['subHire']['total']->getAmount(),
        "projectsFinanceCache_paymentsReceived" =>$PAGEDATA['FINANCIALS']['payments']['received']['total']->getAmount(),
        "projectsFinanceCache_grandTotal" =>$PAGEDATA['FINANCIALS']['payments']['total']->getAmount(),
        "projectsFinanceCache_mass"=>$PAGEDATA['FINANCIALS']['mass'],
        "projectsFinanceCache_value"=>$PAGEDATA['FINANCIALS']['value']->getAmount(),
    ];
    $DBLIB->insert("projectsFinanceCache", $projectFinanceCacheInsert); //Add a cache for the finance of the project
//Just check the cache while we're here - shouldn't ever be thrown!
} elseif ($projectFinanceCache["projectsFinanceCache_equipmentSubTotal"] != $PAGEDATA['FINANCIALS']['prices']['subTotal']->getAmount()) $projectFinancesCacheMismatch = true;
elseif ($projectFinanceCache["projectsFinanceCache_equiptmentDiscounts"] != $PAGEDATA['FINANCIALS']['prices']['discounts']->getAmount()) $projectFinancesCacheMismatch = true;
elseif ($projectFinanceCache["projectsFinanceCache_equiptmentTotal"] != $PAGEDATA['FINANCIALS']['prices']['total']->getAmount()) $projectFinancesCacheMismatch = true;
elseif ($projectFinanceCache["projectsFinanceCache_salesTotal"] != $PAGEDATA['FINANCIALS']['payments']['sales']['total']->getAmount()) $projectFinancesCacheMismatch = true;
elseif ($projectFinanceCache["projectsFinanceCache_staffTotal"] != $PAGEDATA['FINANCIALS']['payments']['staff']['total']->getAmount()) $projectFinancesCacheMismatch = true;
elseif ($projectFinanceCache["projectsFinanceCache_externalHiresTotal"] !=  $PAGEDATA['FINANCIALS']['payments']['subHire']['total']->getAmount()) $projectFinancesCacheMismatch = true;
elseif ($projectFinanceCache["projectsFinanceCache_paymentsReceived"] != $PAGEDATA['FINANCIALS']['payments']['received']['total']->getAmount()) $projectFinancesCacheMismatch = true;
elseif ($projectFinanceCache["projectsFinanceCache_grandTotal"] != $PAGEDATA['FINANCIALS']['payments']['total']->getAmount()) $projectFinancesCacheMismatch = true;
elseif ($projectFinanceCache["projectsFinanceCache_value"] != $PAGEDATA['FINANCIALS']['value']->getAmount()) $projectFinancesCacheMismatch = true;
elseif (round($projectFinanceCache["projectsFinanceCache_mass"]*100000) != round($PAGEDATA['FINANCIALS']['mass']*100000)) $projectFinancesCacheMismatch = true;

if ($projectFinancesCacheMismatch) {
    //So there's a serious project finance mismatch - you can force a rebuild of the cache by passing a get parameter - otherwise throw an error so support are notified
    if (isset($_GET['forceReloadCache'])) {
        $projectFinanceCacheInsert = [
            "projects_id" => $PAGEDATA['project']['projects_id'],
            "projectsFinanceCache_timestamp" => date("Y-m-d H:i:s"),
            "projectsFinanceCache_equipmentSubTotal" =>$PAGEDATA['FINANCIALS']['prices']['subTotal']->getAmount(),
            "projectsFinanceCache_equiptmentDiscounts" =>$PAGEDATA['FINANCIALS']['prices']['discounts']->getAmount(),
            "projectsFinanceCache_equiptmentTotal" =>$PAGEDATA['FINANCIALS']['prices']['total']->getAmount(),
            "projectsFinanceCache_salesTotal" =>$PAGEDATA['FINANCIALS']['payments']['sales']['total']->getAmount(),
            "projectsFinanceCache_staffTotal" =>$PAGEDATA['FINANCIALS']['payments']['staff']['total']->getAmount(),
            "projectsFinanceCache_externalHiresTotal" => $PAGEDATA['FINANCIALS']['payments']['subHire']['total']->getAmount(),
            "projectsFinanceCache_paymentsReceived" =>$PAGEDATA['FINANCIALS']['payments']['received']['total']->getAmount(),
            "projectsFinanceCache_grandTotal" =>$PAGEDATA['FINANCIALS']['payments']['total']->getAmount(),
            "projectsFinanceCache_mass"=>$PAGEDATA['FINANCIALS']['mass'],
            "projectsFinanceCache_value"=>$PAGEDATA['FINANCIALS']['value']->getAmount(),
        ];
        $newCache = $DBLIB->insert("projectsFinanceCache", $projectFinanceCacheInsert); //Add a cache for the finance of the project
        if($newCache) die("Cache force reloaded - now reload the page without the URL parameter");
    } else throw new Exception('Project finances don\'t match - on cache ' . $projectFinanceCache['projectsFinanceCache_id']);
}



usort($PAGEDATA['FINANCIALS']['payments']['subHire']['ledger'], function ($a, $b) {
    // Sort sub-hires in order of supplier so you can do supplier headings
    return $a['payments_supplier'] <=> $b['payments_supplier'];
});
usort($PAGEDATA['FINANCIALS']['payments']['sales']['ledger'], function ($a, $b) {
    // Sort sub-hires in order of supplier so you can do supplier headings
    return $a['payments_supplier'] <=> $b['payments_supplier'];
});
usort($PAGEDATA['FINANCIALS']['payments']['staff']['ledger'], function ($a, $b) {
    // Sort sub-hires in order of supplier so you can do supplier headings
    return $a['payments_supplier'] <=> $b['payments_supplier'];
});


//Locations
$DBLIB->where("locations.instances_id", $AUTH->data['instance']['instances_id']);
$DBLIB->orderBy("locations.locations_name", "ASC");
$DBLIB->where("locations.locations_deleted", 0);
$PAGEDATA['locations'] = $DBLIB->get("locations",null,["locations.locations_name","locations.locations_id"]);


//Notes
$DBLIB->where("projectsNotes_deleted", 0);
$DBLIB->where("projects_id", $PAGEDATA['project']['projects_id']);
$DBLIB->orderBy("projectsNotes_id", "ASC");
$PAGEDATA['project']['notes'] = $DBLIB->get("projectsNotes");

//Crew
$DBLIB->where("projects_id", $PAGEDATA['project']['projects_id']);
$DBLIB->where("crewAssignments.crewAssignments_deleted", 0);
$DBLIB->join("users", "crewAssignments.users_userid=users.users_userid", "LEFT");
$DBLIB->orderBy("crewAssignments.crewAssignments_rank", "ASC");
$DBLIB->orderBy("crewAssignments.crewAssignments_id", "ASC");
$PAGEDATA['project']['crewAssignments'] = $DBLIB->get("crewAssignments", null, ["crewAssignments.*", "users.users_name1", "users.users_name2", "users.users_email"]);

//Files
$PAGEDATA['files'] = $bCMS->s3List(7, $PAGEDATA['project']['projects_id']);

$PAGEDATA['pdfs'] = $bCMS->s3List(12, $PAGEDATA['project']['projects_id']);


$DATA = [
    "project" => $PAGEDATA['project'],
    'FINANCIALS' => $PAGEDATA['FINANCIALS'],
    'locations' => $PAGEDATA['locations']
]; //Data that's safe to return


if (basename(__FILE__) == basename($_SERVER["SCRIPT_FILENAME"])) finish(true, null, $DATA);