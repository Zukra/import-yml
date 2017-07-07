<?php
$_SERVER["DOCUMENT_ROOT"] = realpath(dirname(__FILE__) . "/../..");
$DOCUMENT_ROOT = $_SERVER["DOCUMENT_ROOT"];

define("NO_KEEP_STATISTIC", true);
define("NOT_CHECK_PERMISSIONS", true);
define('CHK_EVENT', true);

@set_time_limit(0);
@ignore_user_abort(true);


require($_SERVER["DOCUMENT_ROOT"] . "/bitrix/modules/main/include/prolog_before.php");

if (!CModule::IncludeModule("iblock")) {

    return;
}
if (!CModule::IncludeModule("search")) {

    return;
}

//$file = $_SERVER['DOCUMENT_ROOT'] . '/local/import-yml/yml/marketYandex.yml';  // импортируемый файл
$file = $_SERVER['DOCUMENT_ROOT'] . '/local/import-yml/yml/example.yml';  // импортируемый файл

echo '<pre>';

Bitrix\Main\Diag\Debug::startTimeLabel("run");

$instance = ImportYmlFile::getInstance();

if ($xmlObj = $instance->getXmlToObject($file)) {

    $arCatalogs = $instance->importCatalogs($xmlObj);

    $items = $instance->importItems($xmlObj, $arCatalogs);


//    $instance->workWithImages($xmlObj);   // перемещение скаченных файлов в соответствующие каталоги

//    $instance->getPicUrl($xmlObj);     // save to txt-file image url from yaml-file

//    $instance->showListCatalog($xmlObj);

    echo 'Added catalogs = ' . count($arCatalogs);
    echo '<br>';
    echo 'Added items = ' . ($items ?: 0);
    echo '<br>';
}
Bitrix\Main\Diag\Debug::endTimeLabel("run");

var_dump(Bitrix\Main\Diag\Debug::getTimeLabels()['run']['time']);

echo '</pre>';


class ImportYmlFile {
    private static $_instance;

    const CATALOG_ID = '4';  // id инфоблока каталога (куда импортируем)
//    const SITE_ID = SITE_ID; // магазин

    const PARTNER_PRODUCT_SELECTED_ID = '13'; // id свойства Товар партнера (PARTNER_PRODUCT)

    const PARENT_REPAIR_ID = 'repair';                  // значение доп. поля раздела Для ремонта (UF_YAML_ID)
    const PARENT_FURNITURE_ID = 'furniture';            // значение доп. поля раздела Мебель (UF_YAML_ID)
    const PARENT_DACHA_ID = 'tovary-dlya-doma-i-dachi'; // значение доп. поля раздела Дом и дача (UF_YAML_ID)

    public $relationArray = [      // yml_id раздела (что добавлять) => bx_id раздела (куда добавлять)
        '141' => self::PARENT_DACHA_ID,      // Садовая техника
        '270' => self::PARENT_DACHA_ID,      // Оборудование
        '139' => self::PARENT_DACHA_ID,      // Для отдыха
        // '1677' => self::PARENT_FURNITURE_ID,  // Мебель   ( Для жилых комнат )
        // '344'  => self::PARENT_FURNITURE_ID,  // Интерьер
        // '1607' => self::PARENT_FURNITURE_ID,  // Для ванной
        // '1937' => self::PARENT_FURNITURE_ID,  // Мебель для прихожей
        // '2017' => self::PARENT_FURNITURE_ID,  // Мебель для спальни
        // '2047' => self::PARENT_FURNITURE_ID,  // Мягкая мебель
        // '285'  => self::PARENT_FURNITURE_ID,  // Детская мебель
        // '346'  => self::PARENT_REPAIR_ID,     // Сантехника (И все подкатегории)
        // '1747' => self::PARENT_REPAIR_ID,     // Двери и конструкции для дома (И все подкатегории)
        // '905'  => self::PARENT_REPAIR_ID,     // Отделочные материалы (И все подкатегории)
        // '544'  => self::PARENT_REPAIR_ID,     // Электроинструмент (И все подкатегории)
        // '633'  => self::PARENT_REPAIR_ID,     // Профессиональный инструмент (И все подкатегории)
        // '138'  => self::PARENT_REPAIR_ID,     // Ручной инструмент, оборудование (И все подкатегории)
        // '1807' => self::PARENT_REPAIR_ID,     // Отопление, водоснабжение, вентиляция (И все подкатегории)
        // '1767' => self::PARENT_REPAIR_ID,     // Строительное оборудование (И все подкатегории)
    ];

    public $updatePic = false;      // true   - обновлять или нет картинки
    public $bUpdateSearch = false;  // Индексировать элемент для поиска

    private $itemsCurrent = null;   // существующие yaml товары
    private $file;

    public $from = '/upload/21vek/';        // откуда брать картинки
    public $to = '/upload/img/galleries/';  // куда складывать картинки

    private function __construct() {
        // get yaml items from BX
        $this->itemsCurrent = [];
        $arSelect = ['ID', 'NAME', 'IBLOCK_SECTION_ID', 'PROPERTY_YAML_ID'];
        $arFilter = [
            "IBLOCK_ID"         => self::CATALOG_ID,
            "ACTIVE"            => "Y",
            '!PROPERTY_YAML_ID' => false
        ];
        $res = CIBlockElement::GetList([], $arFilter, false, false, $arSelect);
        while ($arItem = $res->Fetch()) {
            $this->itemsCurrent[$arItem['PROPERTY_YAML_ID_VALUE']] = $arItem;
        }
        /*
                if ($res = $this->getRelation()) {
                    $this->relationArray = $res;
                }
        */
    }

    public static function getInstance() {
        if (self::$_instance == null) {
            self::$_instance = new self();
        }

        return self::$_instance;
    }

    public function __clone() {
        trigger_error('Clone is not allowed.', E_USER_ERROR);
    }

    public function __wakeup() {
        trigger_error('Unserializing is not allowed.', E_USER_ERROR);
    }

    public function getXmlToObject($file) {
        return simplexml_load_file($file);
    }

    public function importCatalogs($xmlObj) {

        $arParents = array_keys($this->relationArray);

//        $arCategories = [];
        foreach ($xmlObj->shop->categories->category as $category) {
            if (in_array($category['id'], $arParents) && empty($category['parentId'])) {
                $parentId = $this->getBxParentId((string)$category['id']);
                $item = [
                    'id'              => (string)$category['id'],
                    'parentId'        => $parentId,
                    'relatedParentId' => $this->getCatalogBxId($parentId),
                    'name'            => $category['id'] == 1677 ? 'Для жилых комнат' : trim($category[0]),
                ];

//                $arCategories[(string)$category['id']] = $item;

                $this->addSubCatalog($item);   // add sub-catalog

            } elseif (in_array($category['parentId'], $arParents)) {
                if (!in_array($category['id'], $arParents)) {

                    $arParents[] = (int)$category['id'];
                    $item = [
                        'id'              => (string)$category['id'],
                        'parentId'        => (string)$category['parentId'],
                        'relatedParentId' => $this->getCatalogBxId((string)$category['parentId']),
                        'name'            => trim($category[0]),
                    ];

//                    $arCategories[$item['id']] = $item;

                    $this->addCatalog($item);
                }
            }
        }

        return $arParents;
    }

    public function getBxParentId($id) {
        $arCatalog = $this->relationArray;

        return ($arCatalog[$id] ? $arCatalog[$id] : false);
    }

    public function addSubCatalog($catalog) {

        $parentBxId = $this->getCatalogBxId($catalog['parentId']);  // (get parent BX id) get catalog id Мебель
        if ($parentBxId) {
            $arFields = Array(
                "NAME"              => $catalog['name'],
                "ACTIVE"            => 'Y',
                "IBLOCK_SECTION_ID" => $parentBxId,
                "IBLOCK_ID"         => self::CATALOG_ID,
                "UF_YAML_ID"        => $catalog['id'],
                "CODE"              => Cutil::translit($catalog['name'], "ru", array("replace_space" => "-", "replace_other" => "-")),
            );
            $id = $this->getCatalogBxId($catalog['id']); // получение соответствующего BX Id по Id из yaml-файла

            return $this->catalogAddUpdate($id, $arFields);
        } else {
            return false;
        }
    }

    public function addCatalog($category) {
        if ($category['relatedParentId']) {
            $arFields = Array(
                "NAME"              => $category['name'],
                "ACTIVE"            => 'Y',
                "IBLOCK_SECTION_ID" => $category['relatedParentId'],
                "IBLOCK_ID"         => self::CATALOG_ID,
                "UF_YAML_ID"        => $category['id'],
                "CODE"              => Cutil::translit($category['name'], "ru", array("replace_space" => "-", "replace_other" => "-")),
            );

            $catalogBxId = $this->getCatalogBxId($category['id']);

            return $this->catalogAddUpdate($catalogBxId, $arFields);
        } else {
            return false;
        }
    }

    public function catalogAddUpdate($id, $arFields) {
        $bs = new CIBlockSection;
        if (!$id) { // if not exist catalog - add
            $id = $bs->Add($arFields);
        } else {
            if ($bs->Update($id, $arFields)) {
//            echo "Update: " . $id
            } else {
                echo "Error update: " . $bs->LAST_ERROR;
            }
        }

        return ($id ? $id : false);
    }

    public function getCatalogBxId($uf_yaml_id) {

        $arFilter = ["IBLOCK_ID"   => self::CATALOG_ID,
                     'ACTIVE'      => 'Y',
//                     'SITE_ID'     => self::SITE_ID,
                     "=UF_YAML_ID" => $uf_yaml_id,
                     'TYPE'        => 'catalog',
        ];
        $res = CIBlockSection::GetList([], $arFilter, false, ['UF_YAML_ID']);

        $ar_res = $res->Fetch();

        return ($ar_res['UF_YAML_ID'] ? $ar_res['ID'] : false);
    }

    public function modifyUrlPic($url) {
        return str_replace('/preview', '', $url);
    }

    public function importItems($xmlObj, $arCategories) {
//        $items = [];
        $addedItemsCount = 0;
        foreach ($xmlObj->shop->offers->offer as $offer) {
            if (in_array((int)$offer->categoryId, $arCategories)) {
                $item = [
                    'id'                => (string)$offer['id'],
                    'categoryId'        => (string)$offer->categoryId,
                    'relatedParentId'   => $this->getCatalogBxId((string)$offer->categoryId),
                    "available"         => (string)$offer["available"],
                    'price'             => (string)$offer->price,
                    'currencyId'        => (string)$offer->currencyId,
                    'name'              => (string)$offer->typePrefix . ' ' . (string)$offer->vendor . ' ' . (string)$offer->model,
                    'code'              => Cutil::translit((string)$offer->typePrefix . ' ' . (string)$offer->vendor . ' ' . (string)$offer->model, "ru", array("replace_space" => "-", "replace_other" => "-")),
                    'description'       => (string)$offer->description,
                    'country_of_origin' => '<b>Страна производитель: </b>' . (string)$offer->country_of_origin,
                    'picture'           => array_map([$this, 'modifyUrlPic'], (array)$offer->picture),
                ];

                if ($item['relatedParentId']) {
//                    $items[(string)$offer['id']] = $item;
                    $this->addItemToCatalog($item);

                    $addedItemsCount++;
                }
            }
            if ($addedItemsCount > 99) break;
        }

        if ($this->bUpdateSearch && $addedItemsCount > 0) {
            CSearch::ReIndexModule('iblock');
        }

//        return $items;
        return $addedItemsCount;
    }

    public function addItemToCatalog($item) {
        $arLoadProductArray = Array(
            "IBLOCK_SECTION_ID" => $item['relatedParentId'],
            "IBLOCK_ID"         => self::CATALOG_ID,
            "NAME"              => $item["name"],
            "CODE"              => $item["code"],
            "ACTIVE"            => "Y",
            "SORT"              => "501",
            "DETAIL_TEXT"       => $item["description"],
//            "PREVIEW_PICTURE"   => CFile::MakeFileArray($item['picture'][0]),
//            "PREVIEW_PICTURE"   => $this->getImg($item['picture'][0]),
        );

        $prop = [
            'ABOUT_BRAND'     => $item['country_of_origin'],
            'YAML_ID'         => $item['id'],
            "PARTNER_PRODUCT" => self::PARTNER_PRODUCT_SELECTED_ID
        ];

        $el = new CIBlockElement;

        $PRODUCT_ID = $this->existItem($item);   // get Bx item Id

        if (!$PRODUCT_ID || $this->updatePic) {
            $arPictures = array_map([$this, 'getImg'], $item['picture']);
            $arLoadProductArray["PREVIEW_PICTURE"] = $arPictures[0];
            $prop['PHOTO'] = $arPictures;
        }
        if (!$PRODUCT_ID) {
            if ($PRODUCT_ID = $el->Add($arLoadProductArray, false, $this->bUpdateSearch)) {
//                echo "Added: " . $PRODUCT_ID;
            } else
                echo "Error added : " . $el->LAST_ERROR;
        } else {
            if ($el->Update($PRODUCT_ID, $arLoadProductArray, false, $this->bUpdateSearch)) {
//                echo "Update: " . $PRODUCT_ID;
            } else {
                echo "Error update: " . $el->LAST_ERROR;
            }
        }

        $this->setItemProperties($PRODUCT_ID, $prop);

        $arFields = array("ID" => $PRODUCT_ID, 'QUANTITY' => '1');
        if (CCatalogProduct::Add($arFields)) {
//        echo "Добавили параметры товара к элементу каталога " . $PRODUCT_ID . '<br>';
            $this->setItemPrice($PRODUCT_ID, $item);
        } else {
            echo 'Ошибка добавления параметров<br>';
        }
    }

    public function existItem($item) {
        $arItem = $this->itemsCurrent[$item['id']];

        return ($arItem['IBLOCK_SECTION_ID'] == $item['relatedParentId'] ? $arItem['ID'] : false);
    }

    public function setItemProperties($PRODUCT_ID, $prop) {
        if ($prop['PHOTO']) {
            // delete photos from PROPERTY PHOTO
            CIBlockElement::SetPropertyValuesEx($PRODUCT_ID, self::CATALOG_ID, ['PHOTO' => ["VALUE" => ["del" => "Y"]]]);
        }
        // set properties
        CIBlockElement::SetPropertyValuesEx($PRODUCT_ID, self::CATALOG_ID, $prop);
    }

    public function getPhoto($photo) {
        $res = CFile::MakeFileArray($photo, false, true);

        return ($res > 0 ? $res : false);
    }

    public function setItemPrice($productId, $item) {
        $PRICE_TYPE_ID = 1;

        $arFields = Array(
            "PRODUCT_ID"       => $productId,
            "CATALOG_GROUP_ID" => $PRICE_TYPE_ID,
            "PRICE"            => $item['price'],
            "CURRENCY"         => "BYR", //$item['currencyId'], // in yml-file code BYN
            "QUANTITY_FROM"    => false,
            "QUANTITY_TO"      => false
        );

        $res = CPrice::GetList(
            array(),
            array(
                "PRODUCT_ID"       => $productId,
                "CATALOG_GROUP_ID" => $PRICE_TYPE_ID
            )
        );

        if ($arr = $res->Fetch()) {
            CPrice::Update($arr["ID"], $arFields);
        } else {
            CPrice::Add($arFields);
//        echo "Добавили price " . $productId . '<br>';
        }
    }

    public function getCategoryItemsCount($xmlObj, $catId) {
        $i = 0;
        foreach ($xmlObj->shop->offers->offer as $offer) {
            if ((string)$offer->categoryId == (string)$catId) {
                $i++;
            }
        }

        return $i;
    }

    public function showListCatalog($value) {
        $i = 0;
        $ii = 0;
        foreach ($value->shop->categories->category as $category) {
            $items = $this->getCategoryItemsCount($value, $category['id']);
            $ii += $items;

            if (!$category['parentId']) {
                echo ' items =  ' . $ii . '<br>';
//                echo($category['parentId'] ? '<br>' : '<br>----------------------------------------------------------------<br>');
                echo 'id = ' . $category['id']
//                    . ' parent id = ' . ($category['parentId'] ? $category['parentId'] : 'NULL')
                    . ' name = ' . $category[0]//                    . ' items =  ' . $items
                ;

//                echo($category['parentId'] ? '' : '<br>----------------------------------------------------------------');
            }

            if (!$category['parentId']) {
                $ii = 0;
            }

            $i++;
        }
        echo '<br>catalogs: ' . $i . ' - ' . $ii;

        $i = 0;
        foreach ($value->shop->offers->offer as $offer) {
            $i++;
        }
        echo '<br>items: ' . $i;
    }

    public function getYamlImg($xmlObj) {
        $images = [];
//        $tmp = [];
        foreach ($xmlObj->shop->offers->offer as $offer) {
            $arImg = array_map([$this, 'modifyUrlPic'], (array)$offer->picture);
            foreach ($arImg as $image) {
                $data = explode("/", $image);
                $fileName = $data[7];
                $dir = $data[5] . '/' . $data[6];
                $images[$fileName] = $dir;
//                $tmp[] = $fileName;
            }
        }

//        var_dump(array_diff_assoc($tmp, array_unique($tmp)));

        return $images;
    }

    public function createDir($dir) {
        mkdir($dir, 0777, true);
    }

    public function getPicUrl($xmlObj) {
        $this->file = fopen($_SERVER['DOCUMENT_ROOT'] . "/tools/url-pic.txt", "w");
        $pic = [];
        foreach ($xmlObj->shop->offers->offer as $offer) {
            $item = [
                'id'      => (string)$offer['id'],
                'picture' => array_map([$this, 'saveToFileUrlPic'], (array)$offer->picture),
            ];

            $pic[$item['id']] = $item['picture'];
        }

        fclose($this->file);

        return $pic;
    }

    public function saveToFileUrlPic($url) {
        $url = str_replace('/preview', '', $url);
        fwrite($this->file, $url . "\r\n");

        return $url;
    }

    public function getUploadImg($from) {
        $arUploadImg = [];
        if ($handle = opendir($from)) {
            while (false !== ($file = readdir($handle))) {
                if ($file != "." && $file != "..") {
                    $arUploadImg[] = $file;
                }
            }
        }

        return $arUploadImg;
    }

    public function moveImage($fileName, $from, $to) {
        $this->createDir($to);

        return (file_exists($to . '/' . $fileName)
            ?: copy($from . '/' . $fileName, $to . '/' . $fileName));

//        return (file_exists($to . '/' . $fileName)
//            ?: rename($from . '/' . $fileName, $to . '/' . $fileName));
    }

    public function workWithImages($xmlObj) {
        $arYamlImg = $this->getYamlImg($xmlObj);   // get name & directory img-file from yaml-file
        $from = $_SERVER['DOCUMENT_ROOT'] . $this->from;
        $arUploadImg = $this->getUploadImg($from);    // get upload files
        foreach ($arUploadImg as $img) {
            if (!$arYamlImg[$img]) {
                continue;
            }
            $to = $_SERVER['DOCUMENT_ROOT'] . $this->to . $arYamlImg[$img];
            $this->moveImage($img, $from, $to);
        }
    }

    public function getImg($img) {
        $data = explode("/", $img);
        $fileName = $data[7];
        $dir = $data[5] . '/' . $data[6] . '/';

        $file = $_SERVER['DOCUMENT_ROOT'] . $this->to . $dir . $fileName;

        $image = (file_exists($file) && true ? CFile::MakeFileArray($file) : CFile::MakeFileArray($img));

        return $image ?: false;
    }

    public function getRelation() {
        $arItems = [];
        $hlBlockId = 1;

        if (CModule::IncludeModule('highloadblock')) {
            $arHLBlock = Bitrix\Highloadblock\HighloadBlockTable::getById($hlBlockId)->fetch();
            $obEntity = Bitrix\Highloadblock\HighloadBlockTable::compileEntity($arHLBlock);
            $strEntityDataClass = $obEntity->getDataClass();

            echo '<pre>';
//            var_dump($arHLBlock);
//            var_dump($obEntity);
//            var_dump($strEntityDataClass);

            $rsData = $strEntityDataClass::getList([
                'select' => ['UF_YAML_CATALOG_ID', 'UF_YAML_CATALOG_NAME', 'UF_BX_CATALOG_ID', 'UF_CATALOG'],
                'order'  => ['UF_BX_CATALOG_ID' => 'ASC'],
                'filter' => ['UF_ACTIVE' => '1'],
            ]);
            while ($arItem = $rsData->Fetch()) {
                $arItems[$arItem['UF_YAML_CATALOG_ID']] = $arItem['UF_BX_CATALOG_ID'];
//                $arItems[$arItem['UF_YAML_CATALOG_ID']] = $arItem;
            }

//            var_dump($arItems);

            echo '</pre>';
        }

        return $arItems;
    }
}