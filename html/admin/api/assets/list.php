<?php
require_once __DIR__ . '/../apiHeadSecure.php';

if (isset($_POST['term'])) $PAGEDATA['search'] = $bCMS->sanitizeString($_POST['term']);
else $PAGEDATA['search'] = null;

if (isset($_POST['page'])) $page = $bCMS->sanitizeString($_POST['page']);
else $page = 1;
$DBLIB->pageLimit = (isset($_POST['pageLimit']) ? $_POST['pageLimit'] : 20); //Users per page
if (isset($_POST['category'])) $DBLIB->where("assetTypes.assetCategories_id", $_POST['category']);
if (isset($_POST['manufacturer'])) $DBLIB->where("manufacturers.manufacturers_id", $_POST['manufacturer']);
$DBLIB->orderBy("assetCategories.assetCategories_id", "ASC");
$DBLIB->orderBy("assetTypes.assetTypes_name", "ASC");
$DBLIB->join("manufacturers", "manufacturers.manufacturers_id=assetTypes.manufacturers_id", "LEFT");
$DBLIB->where("((SELECT COUNT(*) FROM assets WHERE assetTypes.assetTypes_id=assets.assetTypes_id AND assets.instances_id = '" . $AUTH->data['instance']['instances_id'] . "' AND assets_deleted = 0" . (!isset($_POST['all']) ? ' AND assets.assets_linkedTo IS NULL' : '') .") > 0)");
$DBLIB->join("assetCategories", "assetCategories.assetCategories_id=assetTypes.assetCategories_id", "LEFT");
$DBLIB->join("assetCategoriesGroups", "assetCategoriesGroups.assetCategoriesGroups_id=assetCategories.assetCategoriesGroups_id", "LEFT");
if (strlen($PAGEDATA['search']) > 0) {
    //Search
    $DBLIB->where("(
		manufacturers_name LIKE '%" . $bCMS->sanitizeString($PAGEDATA['search']) . "%' OR
		assetTypes_description LIKE '%" . $bCMS->sanitizeString($PAGEDATA['search']) . "%' OR
		assetTypes_name LIKE '%" . $bCMS->sanitizeString($PAGEDATA['search']) . "%' 
    )");
}
$assets = $DBLIB->arraybuilder()->paginate('assetTypes', $page, ["assetTypes.*", "manufacturers.*", "assetCategories.*", "assetCategoriesGroups_name"]);
$PAGEDATA['pagination'] = ["page" => $page, "total" => $DBLIB->totalPages];

$PAGEDATA['assets'] = [];
foreach ($assets as $asset) {
    $DBLIB->where("assets.instances_id", $AUTH->data['instance']['instances_id']);
    $DBLIB->where("assets.assetTypes_id", $asset['assetTypes_id']);
    $DBLIB->where("assets_deleted", 0);
    if (!isset($_POST['all'])) $DBLIB->where("(assets.assets_linkedTo IS NULL)");
    $DBLIB->orderBy("assets.assets_tag", "ASC");
    $assetTags = $DBLIB->get("assets", null, ["assets_id", "assets_notes","assets_tag","asset_definableFields_1","asset_definableFields_2","asset_definableFields_3","asset_definableFields_4","asset_definableFields_5","asset_definableFields_6","asset_definableFields_7","asset_definableFields_8","asset_definableFields_9","asset_definableFields_10","assets_dayRate","assets_weekRate","assets_value","assets_mass"]);
    $asset['count'] = count($assetTags);
    $asset['fields'] = explode(",", $asset['assetTypes_definableFields']);
    $asset['thumbnail'] = $bCMS->s3List(2, $asset['assetTypes_id']);
    if ($asset['thumbnail'][0]) $asset['thumbnailSuggested'] = $bCMS->s3URL($asset['thumbnail'][0]['s3files_id'], false, false, '+1 hour', true);
    //Format finances
    $asset['assetTypes_mass_format'] = apiMass($asset['assetTypes_mass']);
    $asset['assetTypes_value_format'] = apiMoney($asset['assetTypes_value']);
    $asset['assetTypes_dayRate_format'] = apiMoney($asset['assetTypes_dayRate']);
    $asset['assetTypes_weekRate_format'] = apiMoney($asset['assetTypes_weekRate']);
    
    $asset['tags'] = [];
    foreach ($assetTags as $tag) {
        $tag['flagsblocks'] = assetFlagsAndBlocks($tag['assets_id']);
        $tag['assets_tag_format'] = $bCMS->aTag($tag['assets_tag']);
        //Format finances
        $tag['assets_mass_format'] = apiMass($tag['assets_mass']);
        $tag['assets_value_format'] = apiMoney($tag['assets_value']);
        $tag['assets_dayRate_format'] = apiMoney($tag['assets_dayRate']);
        $tag['assets_weekRate_format'] = apiMoney($tag['assets_weekRate']);
        
        $asset['tags'][] = $tag;
    }

    $PAGEDATA['assets'][] = $asset;
}
finish(true, null, ["assets" => $PAGEDATA['assets'], "pagination" => $PAGEDATA['pagination']]);
?>